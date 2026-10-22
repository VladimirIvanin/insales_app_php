<?php

declare(strict_types=1);

namespace InSales;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for InSales API with Basic Auth.
 *
 * Features:
 * - Basic authentication (app_id + store password)
 * - SSRF protection: rejects private/loopback unless explicitly allowed
 * - TLS enforcement for non-loopback hosts
 * - Response size limit (2 MB)
 * - Automatic redirect disabling (credentials must not follow redirects)
 * - Retrieves API-Usage-Limit header
 * - Rate-limit aware (429 / Retry-After)
 * - Retry for safe (GET) requests on transient failures
 * - Logging without secrets
 */
final readonly class ApiClient
{
    private const MAX_RESPONSE_BYTES = 2_000_000;
    private const REQUEST_TIMEOUT = 10.0;
    private const CONNECT_TIMEOUT = 5.0;
    private const RETRY_MAX = 2;
    private const RETRY_DELAY_MS = 500;
    private const OK_STATUSES = [200, 201];

    private ClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(
        private string $appId,
        private string $shop,
        private string $password,
        private bool $allowHttp = false,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        private ?string $platformBaseUrl = null,
    ) {
        $this->httpClient = $httpClient ?? new Client([
            RequestOptions::TIMEOUT => self::REQUEST_TIMEOUT,
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::VERIFY => true,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::STREAM => false,
            RequestOptions::READ_TIMEOUT => self::REQUEST_TIMEOUT,
        ]);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * GET request to InSales API.
     *
     * @return array{data: mixed, usage: string|null}
     * @throws ApiException
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * POST request with JSON body.
     *
     * @param array<string, mixed> $data
     * @return array{data: mixed, usage: string|null}
     * @throws ApiException
     */
    public function post(string $path, array $data): array
    {
        return $this->request('POST', $path, $data);
    }

    /**
     * PUT request with JSON body.
     *
     * @param array<string, mixed> $data
     * @return array{data: mixed, usage: string|null}
     * @throws ApiException
     */
    public function put(string $path, array $data): array
    {
        return $this->request('PUT', $path, $data);
    }

    /**
     * DELETE request.
     *
     * @return array{data: mixed, usage: string|null}
     * @throws ApiException
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Fetch store account info.
     *
     * @return array{id: int, title: string, ...}
     * @throws ApiException
     */
    public function getAccount(): array
    {
        $result = $this->get('/admin/account.json');
        $data = $result['data'];

        if (!\is_array($data) || !isset($data['id'])) {
            throw new ApiException(200, 'Unexpected account response format');
        }

        /** @var array{id: int, title: string} $data */
        return $data;
    }

    /**
     * Verify store identity by comparing account.id.
     */
    public function verifyIdentity(string|int $expectedStoreId): bool
    {
        try {
            $account = $this->getAccount();
            return (string) $account['id'] === (string) $expectedStoreId;
        } catch (ApiException | \Throwable) {
            return false;
        }
    }

    /**
     * Execute HTTP request with SSRF protection, retry, and logging.
     *
     * @param array<string, mixed>|null $body
     * @return array{data: mixed, usage: string|null}
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $this->verifyDestination();

        $url = $this->buildUrl($path);
        $lastException = null;
        $maxAttempts = ($method === 'GET') ? (self::RETRY_MAX + 1) : 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->doRequest($method, $url, $body);
            } catch (ApiException $e) {
                // Only retry safe GET on transient errors (429, 5xx)
                if ($method !== 'GET' || !$e->isTemporary() || $attempt >= $maxAttempts) {
                    throw $e;
                }
                $lastException = $e;
                $this->logger->warning('Transient API error, retrying', [
                    'attempt' => $attempt,
                    'status' => $e->status,
                    'shop' => $this->shop,
                    'path' => $path,
                ]);

                if ($e->retryAfter !== null) {
                    usleep((int) (((float) $e->retryAfter) * 1_000_000));
                } else {
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                }
            } catch (\Throwable $e) {
                // Transport error — retry for GET
                if ($method !== 'GET' || $attempt >= $maxAttempts) {
                    throw new ApiException(0, $e->getMessage(), isTransient: true);
                }
                $lastException = $e;
                $this->logger->warning('Transport error, retrying', [
                    'attempt' => $attempt,
                    'shop' => $this->shop,
                    'path' => $path,
                ]);
                usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
            }
        }

        // Should not reach here, but satisfies type checker
        throw new ApiException(0, 'Max retries exceeded', isTransient: true);
    }

    /**
     * Execute single HTTP request.
     *
     * @param array<string, mixed>|null $body
     * @return array{data: mixed, usage: string|null}
     * @throws ApiException
     */
    private function doRequest(string $method, string $url, ?array $body = null): array
    {
        $headers = [
            'Authorization' => 'Basic ' . $this->getBasicAuth(),
            'Accept' => 'application/json',
        ];

        $requestBody = null;
        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            $requestBody = is_string($encoded) ? $encoded : null;
            $headers['Content-Type'] = 'application/json';
        }

        $this->logger->debug('API request', [
            'method' => $method,
            'shop' => $this->shop,
            'path' => parse_url($url, \PHP_URL_PATH),
        ]);

        try {
            // Используем stream для bounded чтения — предотвращает OOM при больших ответах
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'body' => $requestBody,
                'stream' => true,
            ]);

            // SSRF check: if redirected (should not happen as we disabled redirects)
            $effectiveUri = $response->getHeaderLine('X-Guzzle-Redirect-Status');
            if ($effectiveUri !== '') {
                $this->logger->warning('Unexpected redirect prevented', ['url' => $url]);
            }

            $statusCode = $response->getStatusCode();
            $usage = $response->getHeaderLine('API-Usage-Limit') ?: null;
            $retryAfter = $response->getHeaderLine('Retry-After') ?: null;

            // Bounded streaming: читаем не более MAX_RESPONSE_BYTES
            $bodyStream = $response->getBody();
            $chunks = [];
            $totalLen = 0;
            $chunkSize = 8192; // 8 KB chunks
            while (!$bodyStream->eof()) {
                $chunk = $bodyStream->read($chunkSize);
                $chunkLen = \strlen($chunk);
                if ($totalLen + $chunkLen > self::MAX_RESPONSE_BYTES) {
                    // Превышаем лимит — прерываем чтение, закрываем стрим
                    $bodyStream->close();
                    throw new ApiException($statusCode, 'API response too large');
                }
                $chunks[] = $chunk;
                $totalLen += $chunkLen;
            }
            $bodyStream->close();
            $rawBody = implode('', $chunks);

            $this->logger->debug('API response', [
                'status' => $statusCode,
                'usage' => $usage,
            ]);

            if (!\in_array($statusCode, self::OK_STATUSES, true)) {
                $message = $rawBody !== '' ? $rawBody : "HTTP {$statusCode}";
                throw new ApiException(
                    $statusCode,
                    $message,
                    usage: $usage,
                    retryAfter: $retryAfter,
                    isTransient: $statusCode === 429 || $statusCode >= 500,
                );
            }

            if ($rawBody === '' || ctype_space($rawBody)) {
                return ['data' => null, 'usage' => $usage];
            }

            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);

            return ['data' => $decoded, 'usage' => $usage];
        } catch (ApiException $e) {
            throw $e;
        } catch (ConnectException $e) {
            throw new ApiException(0, 'Connection failed: ' . $e->getMessage(), isTransient: true);
        } catch (RequestException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            throw new ApiException($statusCode, 'Request failed: ' . $e->getMessage(), isTransient: $statusCode >= 500 || $statusCode === 0);
        } catch (\JsonException $e) {
            throw new ApiException(200, 'Invalid JSON response: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new ApiException(0, 'Unexpected error: ' . $e->getMessage(), isTransient: true);
        }
    }

    /**
     * Verify the destination hostname resolves to a public IP.
     *
     * Если задан platformBaseUrl (тест/симулятор), проверка назначения
     * применяется к его хосту: разрешены только loopback localhost/127.0.0.1.
     * Без platformBaseUrl — полный DNS-резолв и блокировка приватных сетей.
     *
     * @throws ApiException
     */
    private function verifyDestination(): void
    {
        $parsed = ShopValidator::parse($this->shop);
        $host = $parsed['host'];

        // Режим симулятора: платформа на loopback
        if ($this->platformBaseUrl !== null) {
            $parts = parse_url($this->platformBaseUrl);
            $simHost = $parts['host'] ?? '';

            if (!ShopValidator::isLoopback($simHost)) {
                throw new ApiException(0, "SSRF: simulator host {$simHost} is not loopback");
            }
            return;
        }

        // Allow loopback only when explicitly enabled (test mode)
        if (ShopValidator::isLoopback($host)) {
            if (!$this->allowHttp) {
                throw new ApiException(0, 'SSRF: loopback address not allowed');
            }
            return;
        }

        // Block known metadata hosts by name
        $blocked = [
            '169.254.169.254', // AWS/GCP/Azure metadata
            'metadata.google.internal',
            '100.100.100.200', // Alibaba
        ];

        if (\in_array(mb_strtolower($host), $blocked, true)) {
            throw new ApiException(0, 'SSRF: blocked hostname');
        }

        // DNS resolve: check for private/loopback IPs
        $ips = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        if ($ips === false || $ips === []) {
            $this->logger->warning('DNS resolution failed for host', ['host' => $host]);
            return;
        }

        foreach ($ips as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }

            if (self::isPrivate($ip)) {
                throw new ApiException(
                    0,
                    "SSRF: host {$host} resolves to private IP {$ip}",
                );
            }
        }
    }

    /**
     * Check if an IP address is private or loopback.
     */
    private static function isPrivate(string $ip): bool
    {
        // IPv4
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
            return true;
        }

        // IPv6 loopback (::1)
        if ($ip === '::1') {
            return true;
        }

        // IPv6 private (fc00::/7)
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin !== false && (\ord($bin[0]) & 0xfe) === 0xfc) {
                return true;
            }
            return false;
        }

        return false;
    }

    private function buildUrl(string $path): string
    {
        if ($this->platformBaseUrl !== null) {
            // Режим симулятора: URL полностью из platformBaseUrl + path
            return rtrim($this->platformBaseUrl, '/') . '/' . ltrim($path, '/');
        }

        $parsed = ShopValidator::parse($this->shop);
        $scheme = ($this->allowHttp && ShopValidator::isLoopback($parsed['host'])) ? 'http' : 'https';
        $host = $parsed['host'];
        $portPart = $parsed['port'] !== null ? ":{$parsed['port']}" : '';

        return "{$scheme}://{$host}{$portPart}{$path}";
    }

    private function getBasicAuth(): string
    {
        return base64_encode("{$this->appId}:{$this->password}");
    }
}
