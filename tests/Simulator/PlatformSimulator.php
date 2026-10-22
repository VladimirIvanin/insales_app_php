<?php

declare(strict_types=1);

namespace Tests\Simulator;

/**
 * Lightweight HTTP simulator of the InSales platform for integration tests.
 *
 * Runs as a standalone PHP built-in server on a random localhost port via proc_open.
 * State (stores, nonces, scripted responses) is persisted in a JSON file
 * with LOCK_EX, shared between parent (PHPUnit) and child (php -S router).
 *
 * Endpoints:
 * - GET /admin/account.json — Basic Auth, returns account
 * - GET /admin/applications/{appId}/login — redirect with token/token2/token3
 * - Scripted responses via respondNext()
 * - 404 on unknown path
 *
 * Design:
 * - proc_open with array (no shell)
 * - Temporary docroot and state file per instance
 * - Graceful stop() with proc_terminate + proc_close
 */
final class PlatformSimulator
{
    private string $host = '127.0.0.1';
    private int $port = 0;
    private string $baseUrl = '';

    private string $stateFile;
    private string $docroot;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private bool $running = false;

    public function __construct(
        private string $appId = 'test-app-id',
        private string $appSecret = 'test-app-secret',
    ) {}

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Start the simulator and return its base URL.
     *
     * @throws \RuntimeException
     */
    public function start(): string
    {
        if ($this->running) {
            return $this->baseUrl;
        }

        $this->port = $this->findFreePort();
        $this->baseUrl = "http://{$this->host}:{$this->port}";

        // Temporary docroot
        $this->docroot = sys_get_temp_dir() . '/insales_sim_docroot_' . bin2hex(random_bytes(8));
        if (!mkdir($this->docroot, 0700, true) && !is_dir($this->docroot)) {
            throw new \RuntimeException("Cannot create docroot: {$this->docroot}");
        }

        // State file
        $this->stateFile = sys_get_temp_dir() . '/insales_sim_state_' . bin2hex(random_bytes(8)) . '.json';
        $this->writeState([]);

        // Router path
        $router = __DIR__ . '/router.php';

        // Command as array — no shell
        $cmd = [\PHP_BINARY, '-S', "{$this->host}:{$this->port}", '-t', $this->docroot, $router];

        $env = $_ENV;
        $env['INSALES_SIM_STATE'] = $this->stateFile;

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $this->process = @proc_open(
            $cmd,
            $descriptors,
            $this->pipes,
            null,
            $env,
            ['bypass_shell' => true],
        );

        if ($this->process === false || !is_resource($this->process)) {
            $this->cleanup();
            throw new \RuntimeException('Failed to start platform simulator');
        }

        // Close stdin immediately
        fclose($this->pipes[0]);
        unset($this->pipes[0]);

        // Wait for server to be ready
        $maxWait = 20;
        for ($i = 0; $i < $maxWait; $i++) {
            $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 1);
            if ($fp) {
                fclose($fp);
                $this->running = true;
                return $this->baseUrl;
            }
            usleep(150_000);
        }

        // Timeout — capture stderr
        $stderr = stream_get_contents($this->pipes[2]);
        $this->stop();
        throw new \RuntimeException(
            'Simulator failed to start: ' . ($stderr !== false ? trim($stderr) : 'unknown error')
        );
    }

    /**
     * Stop the simulator and clean up resources.
     */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            // Close pipes
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->pipes = [];

            proc_terminate($this->process, \SIGTERM);

            // Give it time to shut down
            for ($i = 0; $i < 5; $i++) {
                $status = @proc_get_status($this->process);
                if ($status === false || !$status['running']) {
                    break;
                }
                usleep(100_000);
            }

            proc_close($this->process);
            $this->process = null;
        }

        $this->cleanup();
        $this->running = false;
    }

    /**
     * Get the base URL of the simulator.
     */
    public function getBaseUrl(): string
    {
        if (!$this->running) {
            throw new \RuntimeException('Simulator not started');
        }
        return $this->baseUrl;
    }

    /**
     * Register a store as installed.
     */
    public function addStore(string $shop, string $password, string $storeId, string $title = 'Test Store'): void
    {
        $state = $this->readState();
        $state['stores'][$shop] = [
            'password' => $password,
            'id' => $storeId,
            'title' => $title,
        ];
        $this->writeState($state);
    }

    /**
     * Remove a store (simulates uninstall).
     */
    public function removeStore(string $shop): void
    {
        $state = $this->readState();
        unset($state['stores'][$shop]);
        $this->writeState($state);
    }

    /**
     * Register a login nonce for redirect.
     */
    public function addLoginNonce(string $nonce, string $appId): void
    {
        $state = $this->readState();
        $state['login_nonces'][$nonce] = ['app_id' => $appId, 'timestamp' => time()];
        $this->writeState($state);
    }

    /**
     * Clear all state.
     */
    public function reset(): void
    {
        $state = [
            'stores' => [],
            'login_nonces' => [],
            'scripted_responses' => [],
            'request_count' => 0,
            'rate_limit_start' => time(),
            'rate_limit_max' => 500,
        ];
        $this->writeState($state);
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
        $state = $this->readState();
        $state['scripted_responses'][] = [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'body' => $body,
            'disconnect' => $disconnect,
            'delay' => $delay,
        ];
        $this->writeState($state);
    }

    /**
     * Advance the virtual rate-limit clock by seconds.
     */
    public function advance(int $seconds): void
    {
        $state = $this->readState();
        $state['rate_limit_start'] = ($state['rate_limit_start'] ?? time()) - $seconds;
        $this->writeState($state);
    }

    /**
     * Get current port.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * Read state from JSON file with shared lock.
     */
    private function readState(): array
    {
        if ($this->stateFile === '' || !file_exists($this->stateFile)) {
            return $this->defaultState();
        }

        $fp = @fopen($this->stateFile, 'rb');
        if ($fp === false) {
            return $this->defaultState();
        }
        flock($fp, \LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, \LOCK_UN);
        fclose($fp);

        $state = $content !== false ? json_decode($content, true) : null;
        return is_array($state) ? $state : $this->defaultState();
    }

    /**
     * Write state to JSON file with exclusive lock.
     */
    private function writeState(array $state): void
    {
        if ($this->stateFile === '') {
            return;
        }

        $fp = @fopen($this->stateFile, 'cb');
        if ($fp === false) {
            return;
        }
        flock($fp, \LOCK_EX);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        fflush($fp);
        flock($fp, \LOCK_UN);
        fclose($fp);
    }

    private function defaultState(): array
    {
        return [
            'stores' => [],
            'login_nonces' => [],
            'scripted_responses' => [],
            'request_count' => 0,
            'rate_limit_start' => time(),
            'rate_limit_max' => 500,
            'app_id' => $this->appId,
            'app_secret' => $this->appSecret,
        ];
    }

    /**
     * Clean up temp files.
     */
    private function cleanup(): void
    {
        if ($this->stateFile !== '' && file_exists($this->stateFile)) {
            @unlink($this->stateFile);
        }
        if ($this->docroot !== '' && is_dir($this->docroot)) {
            @rmdir($this->docroot);
        }
    }

    /**
     * Find a free TCP port by binding to port 0.
     */
    private function findFreePort(): int
    {
        $maxAttempts = 3;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $sock = @socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
            if ($sock === false) {
                throw new \RuntimeException('Cannot create socket: ' . socket_strerror(socket_last_error()));
            }

            if (@socket_bind($sock, '127.0.0.1', 0) === false) {
                socket_close($sock);
                continue;
            }

            if (@socket_getsockname($sock, $addr, $port) === false) {
                socket_close($sock);
                continue;
            }

            socket_close($sock);

            if ($port > 0) {
                return $port;
            }
        }

        throw new \RuntimeException('Cannot find a free TCP port after ' . $maxAttempts . ' attempts');
    }
}