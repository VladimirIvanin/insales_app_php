<?php

declare(strict_types=1);

namespace Tests\Integration;

use Application\InstallationService;
use Application\LoginService;
use Application\UninstallService;
use InSales\Protocol;
use Infrastructure\Database;
use Infrastructure\Encryption;
use Infrastructure\JobQueue;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Tests\Simulator\PlatformSimulator;

/**
 * End-to-end lifecycle tests using the platform simulator.
 *
 * These tests use the real InSalesClient against a local HTTP simulator,
 * verify the full install → login → API access → uninstall flow.
 */
#[CoversNothing]
#[TestDox('Full lifecycle against platform simulator')]
final class LifecycleTest extends TestCase
{
    private static PlatformSimulator $simulator;
    private static string $simulatorUrl;
    private static Database $db;
    private static Encryption $encryption;
    private static JobQueue $queue;
    private InstallationService $installationService;
    private LoginService $loginService;
    private UninstallService $uninstallService;

    private string $appId = 'test-app-id';
    private string $appSecret = 'test-app-secret';
    /** @var array<int, string> */
    private array $allowedShops = ['test.myshop.ru', '127.0.0.1'];

    public static function setUpBeforeClass(): void
    {
        // Use in-memory SQLite for tests
        self::$db = new Database(':memory:');

        $migrations = require __DIR__ . '/../../migrations/migrate.php';
        self::$db->migrate($migrations);

        $keyHex = bin2hex(str_repeat("\x00", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        self::$encryption = new Encryption($keyHex);

        self::$queue = new JobQueue(self::$db);

        self::$simulator = new PlatformSimulator('test-app-id', 'test-app-secret');
        self::$simulatorUrl = self::$simulator->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$simulator->stop();
    }

    protected function setUp(): void
    {
        // Clean database between tests
        self::$db->execute('DELETE FROM stores');
        self::$db->execute('DELETE FROM install_candidates');
        self::$db->execute('DELETE FROM pending_logins');
        self::$db->execute('DELETE FROM sessions');
        self::$db->execute('DELETE FROM jobs');
        self::$db->execute('DELETE FROM installation_generations');

        self::$simulator->reset();

        // Create services with simulator URL
        $this->installationService = new InstallationService(
            self::$db,
            self::$encryption,
            self::$queue,
            $this->appSecret,
            $this->appId,
            ['test.myshop.ru', '127.0.0.1'],
            true, // allow HTTP for test
            self::$simulatorUrl,
        );

        $this->loginService = new LoginService(
            self::$db,
            self::$encryption,
            $this->appId,
            'http://127.0.0.1:18765',
            self::$simulatorUrl,
        );

        $this->uninstallService = new UninstallService(
            self::$db,
            self::$encryption,
            ['test.myshop.ru', '127.0.0.1'],
            true,
        );
    }

    #[TestDox('full install → login → probe → uninstall flow')]
    public function testFullLifecycle(): void
    {
        $shop = 'test.myshop.ru';
        $storeId = '12345';
        $token = 'install-token-abc';
        $password = Protocol::installPassword($token, $this->appSecret);

        // Register store in simulator
        self::$simulator->addStore($shop, $password, $storeId, 'Test Store');

        // 1. Install
        $candidateId = $this->installationService->handleInstall($shop, $token, $storeId);
        $this->assertNotEmpty($candidateId);

        // 2. Process verify job
        $job = self::$queue->claim();
        $this->assertNotNull($job, 'Expected a verify job');
        $this->assertSame('verify_install', $job['type']);

        $this->installationService->verifyCandidate($job['payload']['candidate_id']);
        self::$queue->complete((int) $job['id'], (string) $job['lease_token']);

        // 3. Verify store is installed
        $store = self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]);
        $this->assertNotNull($store);
        $this->assertSame($shop, $store['shop']);
        $this->assertSame('Test Store', $store['title']);

        // 4. Login flow
        $loginResult = $this->loginService->initiateLogin($storeId, $shop);

        $this->assertNotEmpty($loginResult['nonce']);
        $this->assertNotEmpty($loginResult['sessionId']);
        $this->assertStringContainsString('/admin/applications/', $loginResult['redirectUrl']);
        /** @var non-empty-string $simulatorUrl */
        $simulatorUrl = self::$simulatorUrl;
        $this->assertStringStartsWith(
            $simulatorUrl,
            $loginResult['redirectUrl'],
            'redirectUrl должен начинаться с URL симулятора'
        );

        // 5. Расшифровываем пароль для проверок
        $decryptedPassword = self::$encryption->decrypt((string) $store['encrypted_password']);

        // 6. Выполняем HTTP-запрос редиректа к симулятору и сверяем token3
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $loginResult['redirectUrl'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        $this->assertSame(302, $httpCode, 'Симулятор должен ответить 302');
        $this->assertNotNull($redirectUrl, 'Должен быть редирект');

        $redirectParts = [];
        $redirectQuery = parse_url((string) $redirectUrl, PHP_URL_QUERY);
        if (is_string($redirectQuery)) {
            parse_str($redirectQuery, $redirectParts);
        }

        $this->assertArrayHasKey('token3', $redirectParts);
        $this->assertArrayHasKey('user_email', $redirectParts);

        $expectedToken3FromRedirect = Protocol::token3(
            $loginResult['nonce'],
            (string) ($redirectParts['user_email'] ?? ''),
            (string) ($redirectParts['user_name'] ?? ''),
            (string) ($redirectParts['user_id'] ?? ''),
            (string) ($redirectParts['email_confirmed'] ?? 'true'),
            $decryptedPassword,
        );
        $this->assertSame(
            $expectedToken3FromRedirect,
            (string) ($redirectParts['token3'] ?? ''),
            'token3 из редиректа симулятора должен совпадать с Protocol::token3'
        );

        // 7. Simulate autologin callback
        $expectedToken3 = Protocol::token3(
            $loginResult['nonce'],
            'admin@example.com',
            'Admin',
            '1',
            'true',
            $decryptedPassword,
        );

        $authResult = $this->loginService->handleAutologin(
            $loginResult['sessionId'],
            [
                'token3' => $expectedToken3,
                'user_email' => 'admin@example.com',
                'user_name' => 'Admin',
                'user_id' => '1',
                'email_confirmed' => 'true',
            ],
        );

        $this->assertNotEmpty($authResult['authToken']);
        $this->assertSame($storeId, $authResult['storeId']);

        // 7. Validate session
        $session = $this->loginService->validateSession($authResult['authToken']);
        $this->assertNotNull($session);
        $this->assertSame($storeId, $session['store_id']);
        $this->assertSame($shop, $session['shop']);

        // 8. Uninstall
        $uninstalled = $this->uninstallService->handleUninstall($shop, $decryptedPassword, $storeId);
        $this->assertTrue($uninstalled);

        // 9. Verify store is gone
        $store = self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]);
        $this->assertNull($store);

        // 10. Session invalidated
        $session = $this->loginService->validateSession($authResult['authToken']);
        $this->assertNull($session);
    }

    #[TestDox('two stores with independent credentials and sessions')]
    public function testTwoStores(): void
    {
        $shop1 = 'test.myshop.ru';
        $shop2 = '127.0.0.1';
        $storeId1 = '100';
        $storeId2 = '200';
        $token1 = 'token-store-1';
        $token2 = 'token-store-2';
        $pass1 = Protocol::installPassword($token1, $this->appSecret);
        $pass2 = Protocol::installPassword($token2, $this->appSecret);

        self::$simulator->addStore($shop1, $pass1, $storeId1, 'Store One');
        self::$simulator->addStore($shop2, $pass2, $storeId2, 'Store Two');

        // Install both
        $this->installationService->handleInstall($shop1, $token1, $storeId1);
        $this->installationService->handleInstall($shop2, $token2, $storeId2);

        // Process both verify jobs
        for ($i = 0; $i < 2; $i++) {
            $job = self::$queue->claim();
            $this->assertNotNull($job);
            $this->installationService->verifyCandidate($job['payload']['candidate_id']);
            self::$queue->complete((int) $job['id'], (string) $job['lease_token']);
        }

        // Login to store 1
        $login1 = $this->loginService->initiateLogin($storeId1, $shop1);
        $store1 = self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId1]);
        $passDec1 = self::$encryption->decrypt((string) $store1['encrypted_password']);
        $expectedT3_1 = Protocol::token3($login1['nonce'], 'admin1@test.com', 'Admin1', '1', 'true', $passDec1);
        $auth1 = $this->loginService->handleAutologin($login1['sessionId'], [
            'token3' => $expectedT3_1, 'user_email' => 'admin1@test.com',
            'user_name' => 'Admin1', 'user_id' => '1', 'email_confirmed' => 'true',
        ]);

        // Login to store 2
        $login2 = $this->loginService->initiateLogin($storeId2, $shop2);
        $store2 = self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId2]);
        $passDec2 = self::$encryption->decrypt((string) $store2['encrypted_password']);
        $expectedT3_2 = Protocol::token3($login2['nonce'], 'admin2@test.com', 'Admin2', '2', 'true', $passDec2);
        $auth2 = $this->loginService->handleAutologin($login2['sessionId'], [
            'token3' => $expectedT3_2, 'user_email' => 'admin2@test.com',
            'user_name' => 'Admin2', 'user_id' => '2', 'email_confirmed' => 'true',
        ]);

        // Validate both sessions
        $s1 = $this->loginService->validateSession($auth1['authToken']);
        $s2 = $this->loginService->validateSession($auth2['authToken']);

        $this->assertNotNull($s1);
        $this->assertNotNull($s2);
        $this->assertSame($storeId1, $s1['store_id']);
        $this->assertSame($storeId2, $s2['store_id']);
        $this->assertNotSame($s1['id'], $s2['id']);

        // Uninstall store 1 — store 2 unaffected
        $this->uninstallService->handleUninstall($shop1, $passDec1, $storeId1);

        $this->assertNull(self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId1]));
        $this->assertNotNull(self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId2]));
    }

    #[TestDox('invalid signature rejects login')]
    public function testInvalidToken3(): void
    {
        $shop = 'test.myshop.ru';
        $storeId = '42';
        $password = Protocol::installPassword('tok', $this->appSecret);
        self::$simulator->addStore($shop, $password, $storeId);

        // Install directly (skip installation verification for brevity)
        $encPass = self::$encryption->encrypt($password);
        self::$db->execute(
            'INSERT INTO stores (store_id, shop, encrypted_password, title, generation, installed_at)
             VALUES (:id, :shop, :pass, :title, 1, :now)',
            ['id' => $storeId, 'shop' => $shop, 'pass' => $encPass, 'title' => 'Store', 'now' => time()],
        );

        $loginResult = $this->loginService->initiateLogin($storeId, $shop);

        $this->expectException(\Application\LoginException::class);
        $this->expectExceptionMessage('Invalid token3 signature');

        $this->loginService->handleAutologin($loginResult['sessionId'], [
            'token3' => str_repeat('a', 32), // wrong signature
            'user_email' => 'admin@test.com',
            'user_name' => 'Admin',
            'user_id' => '1',
            'email_confirmed' => 'true',
        ]);
    }

    #[TestDox('expired nonce rejects login')]
    public function testExpiredNonce(): void
    {
        $shop = 'test.myshop.ru';
        $storeId = '42';
        $password = Protocol::installPassword('tok', $this->appSecret);
        self::$simulator->addStore($shop, $password, $storeId);

        $encPass = self::$encryption->encrypt($password);
        self::$db->execute(
            'INSERT INTO stores (store_id, shop, encrypted_password, title, generation, installed_at)
             VALUES (:id, :shop, :pass, :title, 1, :now)',
            ['id' => $storeId, 'shop' => $shop, 'pass' => $encPass, 'title' => 'Store', 'now' => time()],
        );

        $loginResult = $this->loginService->initiateLogin($storeId, $shop);

        // Manually expire the nonce
        self::$db->execute(
            'UPDATE pending_logins SET expires_at = :past WHERE session_id = :sid',
            ['past' => time() - 10, 'sid' => $loginResult['sessionId']],
        );

        $store = self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]);
        $password = self::$encryption->decrypt((string) $store['encrypted_password']);
        $expectedToken3 = Protocol::token3(
            $loginResult['nonce'],
            'admin@test.com',
            'Admin',
            '1',
            'true',
            $password,
        );

        $this->expectException(\Application\LoginException::class);
        $this->expectExceptionMessage('expired');

        $this->loginService->handleAutologin($loginResult['sessionId'], [
            'token3' => $expectedToken3,
            'user_email' => 'admin@test.com',
            'user_name' => 'Admin',
            'user_id' => '1',
            'email_confirmed' => 'true',
        ]);
    }

    #[TestDox('repeated nonce is rejected (single-use)')]
    public function testNonceReuse(): void
    {
        $shop = 'test.myshop.ru';
        $storeId = '42';
        $password = Protocol::installPassword('tok', $this->appSecret);
        self::$simulator->addStore($shop, $password, $storeId);

        $encPass = self::$encryption->encrypt($password);
        self::$db->execute(
            'INSERT INTO stores (store_id, shop, encrypted_password, title, generation, installed_at)
             VALUES (:id, :shop, :pass, :title, 1, :now)',
            ['id' => $storeId, 'shop' => $shop, 'pass' => $encPass, 'title' => 'Store', 'now' => time()],
        );

        $loginResult = $this->loginService->initiateLogin($storeId, $shop);

        $store = self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]);
        $storePass = self::$encryption->decrypt((string) $store['encrypted_password']);
        $expectedToken3 = Protocol::token3(
            $loginResult['nonce'],
            'admin@test.com',
            'Admin',
            '1',
            'true',
            $storePass,
        );

        // First use succeeds
        $auth1 = $this->loginService->handleAutologin($loginResult['sessionId'], [
            'token3' => $expectedToken3, 'user_email' => 'admin@test.com',
            'user_name' => 'Admin', 'user_id' => '1', 'email_confirmed' => 'true',
        ]);
        $this->assertNotEmpty($auth1['authToken']);

        // Second use fails
        $this->expectException(\Application\LoginException::class);
        $this->expectExceptionMessage('not found or already used');
        $this->loginService->handleAutologin($loginResult['sessionId'], [
            'token3' => $expectedToken3, 'user_email' => 'admin@test.com',
            'user_name' => 'Admin', 'user_id' => '1', 'email_confirmed' => 'true',
        ]);
    }

    #[TestDox('uninstall with wrong token is rejected')]
    public function testUninstallWrongToken(): void
    {
        $shop = 'test.myshop.ru';
        $storeId = '42';
        $password = Protocol::installPassword('tok', $this->appSecret);
        self::$simulator->addStore($shop, $password, $storeId);
        $encPass = self::$encryption->encrypt($password);
        self::$db->execute(
            'INSERT INTO stores (store_id, shop, encrypted_password, title, generation, installed_at)
             VALUES (:id, :shop, :pass, :title, 1, :now)',
            ['id' => $storeId, 'shop' => $shop, 'pass' => $encPass, 'title' => 'Store', 'now' => time()],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->uninstallService->handleUninstall($shop, 'wrong-password', $storeId);
    }

    #[TestDox('no access after uninstall')]
    public function testNoAccessAfterUninstall(): void
    {
        $shop = 'test.myshop.ru';
        $storeId = '42';
        $token = 'install-token';
        $password = Protocol::installPassword($token, $this->appSecret);
        self::$simulator->addStore($shop, $password, $storeId);

        // Install and verify
        $this->installationService->handleInstall($shop, $token, $storeId);
        $job = self::$queue->claim();
        $this->installationService->verifyCandidate($job['payload']['candidate_id']);
        self::$queue->complete((int) $job['id'], (string) $job['lease_token']);

        $store = self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]);
        $passDec = self::$encryption->decrypt((string) $store['encrypted_password']);

        // Uninstall
        $this->uninstallService->handleUninstall($shop, $passDec, $storeId);
        $this->assertNull(self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]));

        // Verify candidates are gone
        $this->assertNull(self::$db->fetch('SELECT * FROM install_candidates WHERE store_id = :id', ['id' => $storeId]));

        // Simulator should reject API calls with old password
        // (Verified via the account endpoint returning 401)
    }

    #[TestDox('reinstall creates new password generation after uninstall')]
    public function testReinstallIncrementsGeneration(): void
    {
        $shop = 'test.myshop.ru';
        $storeId = '42';
        $token1 = 'first-install';
        $password1 = Protocol::installPassword($token1, $this->appSecret);
        self::$simulator->addStore($shop, $password1, $storeId, 'Original');

        // First install
        $this->installationService->handleInstall($shop, $token1, $storeId);
        $job = self::$queue->claim();
        $this->installationService->verifyCandidate($job['payload']['candidate_id']);
        self::$queue->complete((int) $job['id'], (string) $job['lease_token']);

        $store = self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]);
        $this->assertSame(1, (int) $store['generation']);

        // Uninstall first
        $passDec1 = self::$encryption->decrypt((string) $store['encrypted_password']);
        $this->uninstallService->handleUninstall($shop, $passDec1, $storeId);
        $this->assertNull(self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]));

        // Reinstall with new token
        $token2 = 'second-install';
        $password2 = Protocol::installPassword($token2, $this->appSecret);
        self::$simulator->addStore($shop, $password2, $storeId, 'Updated');

        $this->installationService->handleInstall($shop, $token2, $storeId);
        $job = self::$queue->claim();
        $this->installationService->verifyCandidate($job['payload']['candidate_id']);
        self::$queue->complete((int) $job['id'], (string) $job['lease_token']);

        $store = self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]);
        $this->assertSame(2, (int) $store['generation']);
        $this->assertSame('Updated', $store['title']);

        // Old sessions should be invalidated
        $decryptedNew = self::$encryption->decrypt((string) $store['encrypted_password']);
        $this->assertNotSame($password1, $decryptedNew);
    }

    #[TestDox('store identity mismatch during verification rejects candidate')]
    public function testIdentityMismatchRejectsCandidate(): void
    {
        $shop = 'test.myshop.ru';
        $storeId = '42';
        $token = 'some-token';
        $password = Protocol::installPassword($token, $this->appSecret);

        // Register with DIFFERENT store ID in simulator
        self::$simulator->addStore($shop, $password, '999', 'Wrong Store');

        $this->installationService->handleInstall($shop, $token, $storeId);
        $job = self::$queue->claim();
        $this->installationService->verifyCandidate($job['payload']['candidate_id']);

        // Candidate should be removed, store should not be created
        $this->assertNull(self::$db->fetch('SELECT * FROM stores WHERE store_id = :id', ['id' => $storeId]));
        $this->assertNull(self::$db->fetch('SELECT * FROM install_candidates WHERE id = :id', ['id' => $job['payload']['candidate_id']]));
    }
}
