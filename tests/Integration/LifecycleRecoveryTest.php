<?php

declare(strict_types=1);

namespace Tests\Integration;

use Application\InstallationService;
use Application\LoginService;
use Http\Controllers\LoginController;
use Http\Middleware\SessionMiddleware;
use InSales\Protocol;
use Infrastructure\Database;
use Infrastructure\Encryption;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Simulator\PlatformSimulator;

#[CoversNothing]
final class LifecycleRecoveryTest extends TestCase
{
    private Database $db;
    private PlatformSimulator $sim;
    private InstallationService $install;
    private LoginService $login;
    private SessionMiddleware $middleware;
    private LoginController $controller;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->db->migrate(require __DIR__ . '/../../migrations/migrate.php');
        $encryption = new Encryption(str_repeat('ab', 32));
        $this->sim = new PlatformSimulator('demo', 'secret');
        $url = $this->sim->start();
        $this->install = new InstallationService(
            $this->db,
            $encryption,
            'secret',
            'demo',
            ['a.example', 'b.example'],
            true,
            $url
        );
        $this->login = new LoginService($this->db, $encryption, $this->install, 'demo', 'https://app.example', $url);
        $this->middleware = new SessionMiddleware($this->login, 'https://app.example');
        $this->controller = new LoginController($this->login, $this->middleware, 'https://app.example');
    }

    protected function tearDown(): void
    {
        $this->sim->stop();
    }

    public function testForgedIdentitiesDoNotMonopolizeDomainAdmission(): void
    {
        $this->sim->addStore('a.example', Protocol::installPassword('actual', 'secret'), '42');
        for ($n = 0; $n < 5; $n++) {
            $this->install->handleInstall('a.example', 'forged-' . $n, (string) (900 + $n));
        }
        $this->install->handleInstall('a.example', 'actual', '42');
        $active = $this->install->prepareAndActivate('a.example', '42');
        self::assertSame('42', $active['store_id'] ?? null);
        self::assertSame(2, $this->sim->requestCount());
    }

    public function testLaterSpamPreservesAnAcceptedGenuineCandidate(): void
    {
        $this->sim->addStore('a.example', Protocol::installPassword('actual', 'secret'), '42');
        $id = $this->install->handleInstall('a.example', 'actual', '42');
        for ($n = 0; $n < 5; $n++) {
            $this->install->handleInstall('a.example', 'forged-' . $n, (string) (900 + $n));
        }
        self::assertNotNull($this->db->fetch('SELECT id FROM install_candidates WHERE id = :id', ['id' => $id]));
        self::assertSame('42', $this->install->prepareAndActivate('a.example', '42')['store_id'] ?? null);
    }

    public function testRetryAfterPreventsImmediateCallsAndThenRecovers(): void
    {
        $this->sim->addStore('a.example', Protocol::installPassword('actual', 'secret'), '42');
        $id = $this->install->handleInstall('a.example', 'actual', '42');
        $this->sim->respondNext('GET', '/admin/account.json', 429, ['error' => 'wait']);
        $this->assertTemporaryLogin('42', 'a.example');
        $this->assertTemporaryLogin('42', 'a.example');
        self::assertSame(1, $this->sim->requestCount());
        self::assertNotNull($this->db->fetch('SELECT id FROM install_candidates WHERE id = :id', ['id' => $id]));
        $this->db->execute('UPDATE verification_budgets SET next_attempt_at = 0');
        self::assertSame('42', $this->install->prepareAndActivate('a.example', '42')['store_id'] ?? null);
        self::assertSame(2, $this->sim->requestCount());
    }

    public function testMalformedAccountResponseDoesNotDestroyCredentials(): void
    {
        $this->sim->addStore('a.example', Protocol::installPassword('actual', 'secret'), '42');
        $id = $this->install->handleInstall('a.example', 'actual', '42');
        $this->sim->respondNext('GET', '/admin/account.json', 200, ['unexpected' => 'response']);
        $this->assertTemporaryLogin('42', 'a.example');
        self::assertNotNull($this->db->fetch('SELECT id FROM install_candidates WHERE id = :id', ['id' => $id]));
        $this->db->execute('UPDATE verification_budgets SET next_attempt_at = 0');
        self::assertSame('42', $this->install->prepareAndActivate('a.example', '42')['store_id'] ?? null);
    }

    public function testVerifiedDomainRenameAndReassignment(): void
    {
        $this->sim->addStore('a.example', Protocol::installPassword('actual', 'secret'), '42');
        $this->install->handleInstall('a.example', 'actual', '42');
        self::assertNotNull($this->install->prepareAndActivate('a.example', '42'));
        $this->sim->removeStore('a.example');
        $this->sim->addStore('b.example', Protocol::installPassword('renamed', 'secret'), '42');
        $this->install->handleInstall('b.example', 'renamed', '42');
        self::assertNotNull($this->install->prepareAndActivate('b.example', '42'));
        $renamed = $this->db->fetch("SELECT shop FROM stores WHERE store_id = '42'");
        self::assertSame('b.example', $renamed['shop'] ?? null);
        $this->sim->addStore('b.example', Protocol::installPassword('new-owner', 'secret'), '43');
        $this->install->handleInstall('b.example', 'new-owner', '43');
        self::assertSame('43', $this->install->prepareAndActivate('b.example', '43')['store_id'] ?? null);
        self::assertNull($this->db->fetch("SELECT store_id FROM stores WHERE store_id = '42'"));
    }

    public function testTwoBrowserFlowsKeepTheirIndependentChallengeCookies(): void
    {
        /** @var array<string, string> $cookies */
        $cookies = [];
        /** @var list<array{nonce: string, redirectUrl: string, sessionId: string, generation: int}> $flows */
        $flows = [];
        /** @var array<string, string> $passwords */
        $passwords = [];
        foreach (['42' => 'a.example', '43' => 'b.example'] as $id => $shop) {
            $token = 'actual-' . $id;
            $password = Protocol::installPassword($token, 'secret');
            $this->sim->addStore($shop, $password, (string) $id);
            $this->install->handleInstall($shop, $token, (string) $id);
            $flow = $this->login->initiateLogin((string) $id, $shop);
            $cookies['login_' . $flow['nonce']] = $flow['sessionId'];
            $cookies[SessionMiddleware::CHALLENGE_COOKIE] = $flow['sessionId'];
            $flows[] = $flow;
            $passwords[$flow['nonce']] = $password;
        }
        foreach ($flows as $flow) {
            $params = [
                'flow' => $flow['nonce'], 'user_email' => 'qa@example.invalid', 'user_name' => 'QA',
                'user_id' => '1', 'email_confirmed' => 'true',
            ];
            $params['token3'] = Protocol::token3(
                $flow['nonce'],
                $params['user_email'],
                $params['user_name'],
                '1',
                'true',
                $passwords[$flow['nonce']]
            );
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', 'https://app.example/autologin')
                ->withQueryParams($params)->withCookieParams($cookies);
            $response = $this->controller->autologin($request, new Response());
            self::assertSame(302, $response->getStatusCode());
            self::assertStringContainsString('store_id=', $response->getHeaderLine('Location'));
        }
    }

    private function assertTemporaryLogin(string $id, string $shop): void
    {
        try {
            $this->install->prepareAndActivate($shop, $id);
            self::fail('Expected temporary verification failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Temporary API error', $e->getMessage());
        }
    }
}
