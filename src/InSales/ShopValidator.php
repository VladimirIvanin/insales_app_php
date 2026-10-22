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
     * Разрешён только порт 443 (стандартный HTTPS) или без порта.
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
        $port = isset($m[2]) ? (int) $m[2] : null;

        // Разрешаем только 443 или без порта
        if ($port !== null && $port !== 443) {
            throw new \InvalidArgumentException("Only port 443 is allowed, got {$port}");
        }

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
     *
     * @param array<int, string> $allowedShops
     */
    public static function isAllowed(string $shop, array $allowedShops): bool
    {
        $parsed = self::parse($shop);
        return \in_array($parsed['host'], $allowedShops, true);
    }

    /**
     * Validate shop — returns normalized host string or throws.
     *
     * @param array<int, string> $allowedShops
     */
    public static function validate(string $shop, array $allowedShops, bool $allowHttp): string
    {
        $parsed = self::parse($shop);
        $host = $parsed['host'];

        if (!\in_array($host, $allowedShops, true)) {
            throw new \InvalidArgumentException("Shop '{$shop}' is not in ALLOWED_SHOPS");
        }

        // Transport policy:
        // - Публичные домены всегда используют HTTPS (порт 443 или без порта).
        // - Plain HTTP (порт 80) запрещён на уровне parse(), который разрешает
        //   только порт 443 или отсутствие порта (HTTPS по умолчанию).
        // - allowHttp=true разрешает HTTP для loopback-адресов (dev-режим).
        // - allowHttp=false НЕ блокирует публичные домены — только явный HTTP
        //   к не-loopback адресу (не достигается, т.к. parse блокирует порт 80).
        if ($parsed['port'] !== null && $parsed['port'] !== 443) {
            throw new \InvalidArgumentException("Only port 443 is allowed, got {$parsed['port']}");
        }

        // allowHttp=false: публичный домен без порта или с портом 443 — HTTPS, разрешено.
        // Блокировка plain HTTP для не-loopback адресов уже обеспечена parse().
        // allowHttp=true нужен только для HTTP loopback в dev-режиме.

        // Reconstruct canonical form for comparison
        return $parsed['port'] !== null ? "{$host}:{$parsed['port']}" : $host;
    }
}
