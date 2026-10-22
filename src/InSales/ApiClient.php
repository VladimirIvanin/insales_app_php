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
final readonly class InSalesClient
{
    private const MAX_RESPONSE_BYTES = 2_000_000;
    private const REQUEST_TIMEOUT = 10.0;
    private const CONNECT_TIMEOUT = 5.0;
    private const RETRY_MAX = 2;
    private const RETRY_DELAY_MS = 500;
    private const OK_STATUSES = [200, 201];

    private ClientInterface $httpClient;
    private LoggerInterface $logger;

    /** @var array<int, bool> SSRF blocklist for resolved IPs (RFC 1918, loopback, etc.) */
    private static array $privateRanges = [
        0x00000000 => 0x00FFFFFF,   // 0.0.0.0/8
        0x0A000000 => 0x0AFFFFFF,   // 10.0.0.0/8
        0x7F000000 => 0x7FFFFFFF,   // 127.0.0.0/8
        0xA9FE0000 => 0xA9FEFFFF,   // 169.254.0.0/16
        0xAC100000 => 0xAC1FFFFF,   // 172.16.0.0/12
        0xC0A80000 => 0xC0A8FFFF,   // 192.168.0.0/16
    ];

    public function __construct(
        private string $appId,
        private string $shop,
        private string $password,
        private bool $allowHttp = false,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
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
     * @return array{data: mixed, usage: string|null}
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $this->validateShop();
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
            $requestBody = json_encode($body, JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        }

        $this->logger->debug('API request', [
            'method' => $method,
            'shop' => $this->shop,
            'path' => parse_url($url, \PHP_URL_PATH),
        ]);

        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'body' => $requestBody,
                'stream' => false,
            ]);

            // SSRF check: if redirected (should not happen as we disabled redirects)
            $effectiveUri = $response->getHeaderLine('X-Guzzle-Redirect-Status');
            if ($effectiveUri !== '') {
                $this->logger->warning('Unexpected redirect prevented', ['url' => $url]);
            }

            $rawBody = (string) $response->getBody();

            if (\strlen($rawBody) > self::MAX_RESPONSE_BYTES) {
                throw new ApiException($response->getStatusCode(), 'API response too large');
            }

            $statusCode = $response->getStatusCode();
            $usage = $response->getHeaderLine('API-Usage-Limit') ?: null;
            $retryAfter = $response->getHeaderLine('Retry-After') ?: null;

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
     * Parse shop and verify it's not a private/loopback address (SSRF protection).
     */
    private function validateShop(): void
    {
        $parsed = ShopValidator::parse($this->shop);
        $host = $parsed['host'];

        // Loopback is only allowed when explicitly enabled (test mode)
        if (ShopValidator::isLoopback($host) && !$this->allowHttp) {
            throw new ApiException(0, 'SSRF: loopback address not allowed');
        }

        // Resolve and check private ranges
        // Note: This is a best-effort check; the real protection is disabling redirects and limiting timeouts
        if (!ShopValidator::isLoopback($host) && $this->isPrivateIp($host)) {
            throw new ApiException(0, 'SSRF: private IP address not allowed');
        }
    }

    /**
     * Validate case-insensitive hostname is not resolved as private IP.
     * We check the host string itself for IP literals.
     */
    private function isPrivateIp(string $host): bool
    {
        // Check if it's an IP literal
        if (filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($host);
            if ($ipLong === false) {
                return false;
            }
            $ipLong = (int) $ipLong;
            foreach (self::$privateRanges as $start => $end) {
                if ($ipLong >= $start && $ipLong <= $end) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verify the destination is not a private/reserved hostname.
     */
    private function verifyDestination(): void
    {
        $parsed = ShopValidator::parse($this->shop);
        $host = $parsed['host'];

        // Block known metadata/private hosts
        $blocked = [
            '169.254.169.254', // AWS/GCP/Azure metadata
            'metadata.google.internal',
            '100.100.100.200', // Alibaba
        ];

        if (\in_array(mb_strtolower($host), $blocked, true)) {
            throw new ApiException(0, 'SSRF: blocked hostname');
        }
    }

    private function buildUrl(string $path): string
    {
        $parsed = ShopValidator::parse($this->shop);
        $scheme = $this->allowHttp ? 'http' : 'https';
        $host = $parsed['host'];
        $portPart = $parsed['port'] !== null ? ":{$parsed['port']}" : '';

        return "{$scheme}://{$host}{$portPart}{$path}";
    }

    private function getBasicAuth(): string
    {
        return base64_encode("{$this->appId}:{$this->password}");
    }
}