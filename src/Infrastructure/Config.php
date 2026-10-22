<?php

declare(strict_types=1);

namespace Infrastructure;

/**
 * Configuration loader.
 *
 * Reads from environment variables and .env file.
 * Supports type-safe getters.
 */
final readonly class Config
{
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = $rootDir;

        // Load .env file
        $envFile = $rootDir . '/.env';
        if (is_readable($envFile)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
            $dotenv->safeLoad();
        }
    }

    /**
     * Get required string config value.
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            if ($default !== '') {
                return $default;
            }
            throw new \RuntimeException("Required config '{$key}' is not set");
        }

        return (string) $value;
    }

    /**
     * Get allowed shops list from comma-separated env var.
     *
     * @return array<int, string>
     */
    public function getAllowedShops(): array
    {
        $value = $this->getString('ALLOWED_SHOPS', '');

        if ($value === '') {
            return [];
        }

        return array_map(
            fn(string $s) => mb_strtolower(trim($s)),
            explode(',', $value),
        );
    }
}