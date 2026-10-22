<?php

declare(strict_types=1);

namespace Tests\Simulator;

use InSales\Protocol;

/**
 * Lightweight HTTP simulator of the InSales platform for integration tests.
 *
 * Runs as a standalone PHP built-in server on a random localhost port.
 * Implements only the endpoints used by the application:
 * - GET /admin/account.json
 * - GET /admin/applications/{app_id}/login
 * - Unauthenticated Basic responses
 *
 * Design:
 * - Uses PHP's built-in server router script
 * - Maintains state: installed stores, passwords, rate limits
 * - Synthetic test data, no real platform dependencies
 * - Simulates errors: 401, 403, 404, 422, 429, 5xx, timeout, disconnect
 */
final class PlatformSimulator
{
    private string $host = '127.0.0.1';
    private int $port = 0;
    private ?\Closure $router = null;

    /** @var array<string, array{password: string, id: string, title: string}> */
    private array $stores = [];

    /** @var array<string, string> Maps nonce => app_id for login */
    private array $loginNonces = [];

    /** @var int Request counter for rate limiting */
    private int $requestCount = 0;
    private int $rateLimitMax = 500;
    private int $rateLimitWindow = 300; // 5 minutes
    private int $rateLimitStart = 0;

    private string $appId;
    private string $appSecret;

    /** @var array<int, array{method: string, path: string, status: int, body: mixed, disconnect: bool, delay: int}> */
    private array $scriptedResponses = [];

    public function __construct(string $appId = 'test-app-id', string $appSecret = 'test-app-secret')
    {
        $this->appId = $appId;
        $this->appSecret = $appSecret;
    }

    /**
     * Start the simulator and return its base URL.
     */
    public function start(): string
    {
        $this->port = $this->findFreePort();
        $routerFile = $this->createRouterScript();

        $cmd = sprintf(
            'php -S %s:%d -t /tmp %s 2>/dev/null & echo $!',
            escapeshellarg($this->host),
            $this->port,
            escapeshellarg($routerFile),
        );

        $output = [];
        exec($cmd, $output);
        $pid = (int) ($output[0] ?? 0);

        if ($pid <= 0) {
            throw new \RuntimeException('Failed to start platform simulator');
        }

        // Wait for server to start
        $maxWait = 10;
        for ($i = 0; $i < $maxWait; $i++) {
            $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 1);
            if ($fp) {
                fclose($fp);
                break;
            }
            usleep(100_000);
        }

        register_shutdown_function(function () use ($pid) {
            @exec("kill {$pid} 2>/dev/null");
        });

        return $this->getBaseUrl();
    }

    /**
     * Stop the simulator.
     */
    public function stop(): void
    {
        // The shutdown function handles cleanup
    }

    /**
     * Get the base URL of the simulator.
     */
    public function getBaseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * Register a store as installed.
     */
    public function addStore(string $shop, string $password, string $storeId, string $title = 'Test Store'): void
    {
        $this->stores[$shop] = [
            'password' => $password,
            'id' => $storeId,
            'title' => $title,
        ];
    }

    /**
     * Remove a store (simulates uninstall).
     */
    public function removeStore(string $shop): void
    {
        unset($this->stores[$shop]);
    }

    /**
     * Register a login nonce for redirect.
     */
    public function addLoginNonce(string $nonce, string $appId): void
    {
        $this->loginNonces[$nonce] = $appId;
    }

    /**
     * Clear all installed stores.
     */
    public function reset(): void
    {
        $this->stores = [];
        $this->loginNonces = [];
        $this->scriptedResponses = [];
        $this->requestCount = 0;
    }

    /**
     * Script the next response for a given method+path.
     *
     * @param int $status HTTP status
     * @param mixed $body Response body
     * @param bool $disconnect Whether to disconnect without response
     * @param int $delay Delay in ms before responding
     */
    public function respondNext(string $method, string $path, int $status = 200, mixed $body = null, bool $disconnect = false, int $delay = 0): void
    {
        $this->scriptedResponses[] = [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'body' => $body,
            'disconnect' => $disconnect,
            'delay' => $delay,
        ];
    }

    /**
     * Advance the virtual rate-limit clock by seconds.
     */
    public function advance(int $seconds): void
    {
        $this->rateLimitStart -= $seconds;
    }

    /**
     * Get current port.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Create the router script for PHP built-in server.
     */
    private function createRouterScript(): string
    {
        $simulatorData = serialize([
            'stores' => &$this->stores,
            'loginNonces' => &$this->loginNonces,
            'scriptedResponses' => &$this->scriptedResponses,
            'requestCount' => &$this->requestCount,
            'rateLimitMax' => $this->rateLimitMax,
            'rateLimitStart' => &$this->rateLimitStart,
            'appId' => $this->appId,
            'appSecret' => $this->appSecret,
        ]);

        $code = <<<'PHP'
<?php
// Platform simulator router

// The state is passed via environment as serialized data
// For simplicity, we use a shared file-based approach
$stateFile = sys_get_temp_dir() . '/insales_sim_state_' . getmypid() . '.json';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = $_GET;

$appId = $query['app_id'] ?? 'test-app-id';

// Load state
$state = [];
if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true) ?: [];
}

// Rate limiting
$now = time();
if (!isset($state['rate_limit_start'])) {
    $state['rate_limit_start'] = $now;
}
if (!isset($state['request_count'])) {
    $state['request_count'] = 0;
}

// Reset window if expired
if ($now - $state['rate_limit_start'] > 300) {
    $state['rate_limit_start'] = $now;
    $state['request_count'] = 0;
}

$state['request_count']++;
$usage = sprintf('%d/%d', $state['request_count'], $state['rate_limit_max'] ?? 500);

// Check scripted responses
if (!empty($state['scripted_responses'])) {
    $scripted = $state['scripted_responses'][0];
    if ($scripted['method'] === $method && $scripted['path'] === $uri) {
        array_shift($state['scripted_responses']);
        file_put_contents($stateFile, json_encode($state));

        if (!empty($scripted['delay'])) {
            usleep($scripted['delay'] * 1000);
        }
        if (!empty($scripted['disconnect'])) {
            http_response_code(500);
            exit;
        }

        http_response_code($scripted['status']);
        header('API-Usage-Limit: ' . $usage);
        if ($scripted['status'] === 429) {
            header('Retry-After: 10');
        }
        header('Content-Type: application/json');
        if ($scripted['body'] !== null) {
            echo json_encode($scripted['body']);
        }
        exit;
    }
}

// Handle routes
header('API-Usage-Limit: ' . $usage);

if ($uri === '/admin/account.json' && $method === 'GET') {
    // Basic Auth check
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth = $headers['Authorization'] ?? '';
    }
    if ($auth === '') {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }

    if (!preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Authorization required']);
        file_put_contents($stateFile, json_encode($state));
        exit;
    }

    $decoded = base64_decode($m[1]);
    if ($decoded === false || !str_contains($decoded, ':')) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid authorization']);
        file_put_contents($stateFile, json_encode($state));
        exit;
    }

    [$username, $password] = explode(':', $decoded, 2);

    // Find store by password
    $store = null;
    foreach (($state['stores'] ?? []) as $shop => $info) {
        if ($info['password'] === $password) {
            $store = $info;
            $store['shop'] = $shop;
            break;
        }
    }

    if ($store === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid password']);
        file_put_contents($stateFile, json_encode($state));
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'id' => (int) $store['id'],
        'title' => $store['title'],
        'shop' => $store['shop'],
    ]);
    file_put_contents($stateFile, json_encode($state));
    exit;
}

// Login redirect: GET /admin/applications/{app_id}/login?token=NONCE&login=URL
if (preg_match('#^/admin/applications/([^/]+)/login$#', $uri, $m) && $method === 'GET') {
    $appId = $m[1];
    $nonce = $query['token'] ?? '';
    $loginUrl = $query['login'] ?? '';

    if ($nonce === '' || $loginUrl === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing token or login']);
        file_put_contents($stateFile, json_encode($state));
        exit;
    }

    // Store nonce for verification
    $state['login_nonces'][$nonce] = ['app_id' => $appId, 'timestamp' => time()];
    file_put_contents($stateFile, json_encode($state));

    // Simulate redirect back to application with user info
    $password = '';
    foreach (($state['stores'] ?? []) as $shop => $info) {
        // We need the password to sign token3
        $password = $info['password'];
    }

    if ($password === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Store not found']);
        file_put_contents($stateFile, json_encode($state));
        exit;
    }

    $userEmail = $query['user_email'] ?? 'admin@example.com';
    $userName = $query['user_name'] ?? 'Test Admin';
    $userId = $query['user_id'] ?? '1';
    $emailConfirmed = $query['email_confirmed'] ?? 'true';

    $token3 = md5($nonce . $userEmail . $userName . $userId . $emailConfirmed . $password);

    $separator = (parse_url($loginUrl, PHP_URL_QUERY) ? '&' : '?');
    $redirectUrl = $loginUrl . $separator . http_build_query([
        'token' => md5($nonce . $password),
        'token2' => md5($nonce . $userEmail . $userName . $userId . $password),
        'token3' => $token3,
        'user_email' => $userEmail,
        'user_name' => $userName,
        'user_id' => $userId,
        'email_confirmed' => $emailConfirmed,
    ]);

    http_response_code(302);
    header('Location: ' . $redirectUrl);
    file_put_contents($stateFile, json_encode($state));
    exit;
}

// 404 for unknown routes
http_response_code(404);
echo json_encode(['error' => 'Not found']);
file_put_contents($stateFile, json_encode($state));
PHP;

        $file = tempnam(sys_get_temp_dir(), 'insales_sim_') . '.php';
        file_put_contents($file, $code);

        return $file;
    }

    /**
     * Find a free TCP port.
     */
    private function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($sock === false) {
            throw new \RuntimeException('Cannot create socket');
        }
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        return $port;
    }
}