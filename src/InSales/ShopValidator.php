<?php

declare(strict_types=1);

namespace InSales;

/**
 * Validate and normalize InSales shop domain.
 */
final readonly class ShopValidator
{
    /**
     * Allowed private/loopback hostnames for dev mode.
     */
    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    /**
     * Validate shop domain format.
     *
     * @return array{host: string, port: int|null}
     */
    public static function parse(string $shop): array
    {
        $shop = mb_strtolower(trim($shop));

        if (!preg_match('/^([a-z0-9][a-z0-9.\-]*)(?::(\d{1,5}))?$/', $shop, $m)) {
            throw new \InvalidArgumentException("Invalid shop domain format: {$shop}");
        }

        $host = $m[1];
        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;

        return ['host' => $host, 'port' => $port];
    }

    /**
     * Check if host is a loopback address.
     */
    public static function isLoopback(string $host): bool
    {
        return \in_array($host, self::LOOPBACK_HOSTS, true);
    }

    /**
     * Check shop is in allowed list.
     */
    public static function isAllowed(string $shop, array $allowedShops): bool
    {
        $parsed = self::parse($shop);
        return \in_array($parsed['host'], $allowedShops, true);
    }

    /**
     * Validate shop — returns normalized host string or throws.
     */
    public static function validate(string $shop, array $allowedShops, bool $allowHttp): string
    {
        $parsed = self::parse($shop);
        $host = $parsed['host'];

        if (!\in_array($host, $allowedShops, true)) {
            throw new \InvalidArgumentException("Shop '{$shop}' is not in ALLOWED_SHOPS");
        }

        if ($allowHttp && !self::isLoopback($host)) {
            throw new \InvalidArgumentException("Plain HTTP is only allowed for loopback test simulator");
        }

        // Reconstruct canonical form for comparison
        return $parsed['port'] !== null ? "{$host}:{$parsed['port']}" : $host;
    }
}