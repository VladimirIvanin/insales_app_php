<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infrastructure\SlimAppFactory;
use Infrastructure\Database;
use Infrastructure\JobQueue;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

/**
 * HTTP-level tests using AppFactory without a real HTTP server.
 *
 * Slim's handle() method processes PSR-7 requests directly,
 * exercising middleware, controllers, and error handlers.
 */
#[CoversNothing]
#[TestDox('HTTP application via AppFactory')]
final class ApplicationTest extends TestCase
{
    private App $app;
    private Database $db;

    protected function setUp(): void
    {
        $this->app = SlimAppFactory::create(__DIR__ . '/../..');
        $this->db = $this->app->getContainer()->get(Database::class);

        // Safety: refuse to clean production-like database paths
        $dbPathRef = new \ReflectionProperty($this->db, 'pdo');
        $dbPathRef->setAccessible(true);
        /** @var \PDO $pdo */
        $pdo = $dbPathRef->getValue($this->db);
        $dbPath = $pdo->query('PRAGMA database_list')->fetch(\PDO::FETCH_ASSOC)['file'] ?? '';
        if (str_contains($dbPath, 'app.sqlite3')) {
            self::fail('Test database points to production path: ' . $dbPath);
        }

        // Clean state
        $this->db->execute('DELETE FROM stores');
        $this->db->execute('DELETE FROM install_candidates');
        $this->db->execute('DELETE FROM pending_logins');
        $this->db->execute('DELETE FROM sessions');
        $this->db->execute('DELETE FROM jobs');
        $this->db->execute('DELETE FROM installation_generations');
    }

    #[TestDox('GET /install with missing params returns 400 (InvalidArgumentException)')]
    public function testInstallMissingParams(): void
    {
        $response = $this->request('GET', '/install');
        $this->assertSame(400, $response->getStatusCode());
    }

    #[TestDox('GET /install with empty shop returns 400 (InvalidArgumentException)')]
    public function testInstallEmptyShop(): void
    {
        $response = $this->request('GET', '/install', ['shop' => '', 'token' => 'tok', 'insales_id' => '1']);
        $this->assertSame(400, $response->getStatusCode());
    }

    #[TestDox('GET /install with non-allowed shop returns 400')]
    public function testInstallNonAllowedShop(): void
    {
        $response = $this->request('GET', '/install', [
            'shop' => 'unknown.myinsales.ru',
            'token' => 'test-token-123',
            'insales_id' => '99999',
        ]);
        $this->assertSame(400, $response->getStatusCode());
    }

    #[TestDox('GET /install with valid params for non-allowed shop returns 400')]
    public function testInstallValid(): void
    {
        $response = $this->request('GET', '/install', [
            'shop' => 'demo.myinsales.ru',
            'token' => 'test-token-123',
            'insales_id' => '99999',
        ]);
        // demo.myinsales.ru is NOT in ALLOWED_SHOPS for test env
        $this->assertSame(400, $response->getStatusCode());
    }

    #[TestDox('GET /login without shop returns 400')]
    public function testLoginNoShop(): void
    {
        $response = $this->request('GET', '/login');
        $this->assertSame(400, $response->getStatusCode());
    }

    #[TestDox('GET / requires session (returns 401 from middleware)')]
    public function testDashboardRequiresSession(): void
    {
        $response = $this->request('GET', '/');
        $this->assertSame(401, $response->getStatusCode());
    }

    #[TestDox('GET /probe requires session (returns 401 from middleware)')]
    public function testProbeRequiresSession(): void
    {
        $response = $this->request('GET', '/probe');
        $this->assertSame(401, $response->getStatusCode());
    }

    #[TestDox('POST /logout without session returns 302 (always redirects)')]
    public function testLogoutWithoutSession(): void
    {
        $response = $this->request('POST', '/logout');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/', (string) $response->getHeaderLine('Location'));
    }

    #[TestDox('unknown route returns 404 via error handler')]
    public function testUnknownRoute(): void
    {
        $response = $this->request('GET', '/nonexistent');
        $this->assertSame(404, $response->getStatusCode());
    }

    #[TestDox('health endpoint returns ok')]
    public function testHealth(): void
    {
        $response = $this->request('GET', '/health');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
    }

    #[TestDox('install with allowed shop test.myshop.ru returns 200')]
    public function testInstallAllowedShop(): void
    {
        $response = $this->request('GET', '/install', [
            'shop' => 'test.myshop.ru',
            'token' => 'test-token-456',
            'insales_id' => '12345',
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Installation received', $body);
    }

    // ──────────────────────────────────────────────
    //  Generation & transactional integrity tests
    // ──────────────────────────────────────────────

    #[TestDox('repeated install creates generation records')]
    public function testRepeatedInstallCreatesGenerations(): void
    {
        $response1 = $this->request('GET', '/install', [
            'shop' => 'test.myshop.ru',
            'token' => 'first-token',
            'insales_id' => '98765',
        ]);
        $this->assertSame(200, $response1->getStatusCode());

        $response2 = $this->request('GET', '/install', [
            'shop' => 'test.myshop.ru',
            'token' => 'second-token',
            'insales_id' => '98765',
        ]);
        $this->assertSame(200, $response2->getStatusCode());

        // Должно быть 2 зарезервированных поколения
        $gens = $this->db->fetchAll(
            'SELECT * FROM installation_generations WHERE store_id = :sid ORDER BY generation',
            ['sid' => '98765'],
        );
        $this->assertCount(2, $gens);
        $this->assertSame('reserved', $gens[0]['status']);
        $this->assertSame('reserved', $gens[1]['status']);
        $this->assertSame(1, (int) $gens[0]['generation']);
        $this->assertSame(2, (int) $gens[1]['generation']);

        // Должно быть 2 кандидата
        $candidates = $this->db->fetchAll(
            'SELECT * FROM install_candidates WHERE store_id = :sid ORDER BY candidate_version',
            ['sid' => '98765'],
        );
        $this->assertCount(2, $candidates);
    }

    #[TestDox('install with existing candidate exceeds limit')]
    public function testInstallLimitExceeded(): void
    {
        // Создаём 5 кандидатов (лимит)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->request('GET', '/install', [
                'shop' => 'test.myshop.ru',
                'token' => "token-{$i}",
                'insales_id' => '55555',
            ]);
            $this->assertSame(200, $response->getStatusCode());
        }

        // 6-й должен вернуть 500 (превышение лимита)
        $response = $this->request('GET', '/install', [
            'shop' => 'test.myshop.ru',
            'token' => 'token-too-many',
            'insales_id' => '55555',
        ]);
        $this->assertSame(500, $response->getStatusCode());
    }

    #[TestDox('install creates verify_install job with store_id')]
    public function testInstallCreatesJobWithStoreId(): void
    {
        $response = $this->request('GET', '/install', [
            'shop' => 'test.myshop.ru',
            'token' => 'job-test-token',
            'insales_id' => '11111',
        ]);
        $this->assertSame(200, $response->getStatusCode());

        // Проверяем, что задача создана с store_id
        $jobs = $this->db->fetchAll(
            "SELECT * FROM jobs WHERE store_id = :sid AND type = 'verify_install' AND status = 'pending'",
            ['sid' => '11111'],
        );
        $this->assertCount(1, $jobs);
        $this->assertSame('11111', $jobs[0]['store_id']);

        // Проверяем payload
        $payload = json_decode((string) $jobs[0]['payload'], true);
        $this->assertArrayHasKey('store_id', $payload);
        $this->assertSame('11111', $payload['store_id']);
        $this->assertArrayHasKey('generation', $payload);
        $this->assertSame(1, $payload['generation']);
    }

    #[TestDox('cancelByStoreId removes pending and running jobs')]
    public function testCancelJobsByStoreId(): void
    {
        // Создаём несколько задач для разных магазинов
        $this->request('GET', '/install', [
            'shop' => 'test.myshop.ru',
            'token' => 'cancel-token-1',
            'insales_id' => '22222',
        ]);

        $this->request('GET', '/install', [
            'shop' => 'test.myshop.ru',
            'token' => 'cancel-token-2',
            'insales_id' => '33333',
        ]);

        $queue = $this->app->getContainer()->get(JobQueue::class);
        $queue->cancelByStoreId('22222');

        // Jobs for store 22222 should be removed
        $jobs1 = $this->db->fetchAll(
            "SELECT * FROM jobs WHERE store_id = :sid",
            ['sid' => '22222'],
        );
        $this->assertCount(0, $jobs1);

        // Jobs for store 33333 should still exist
        $jobs2 = $this->db->fetchAll(
            "SELECT * FROM jobs WHERE store_id = :sid",
            ['sid' => '33333'],
        );
        $this->assertCount(1, $jobs2);
    }

    /**
     * Create a PSR-7 server request and process it through the Slim app.
     *
     * @param array<string, string> $queryParams
     */
    private function request(string $method, string $path, array $queryParams = []): ResponseInterface
    {
        /** @var ServerRequestInterface $request */
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest(
            $method,
            new \Slim\Psr7\Uri('http', '127.0.0.1', 18765, $path),
        );

        if ($queryParams !== []) {
            $request = $request->withQueryParams($queryParams);
        }

        return $this->app->handle($request);
    }
}
