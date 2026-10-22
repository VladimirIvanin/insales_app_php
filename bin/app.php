#!/usr/bin/env php
<?php

/**
 * InSales Partner Application — CLI entry point.
 *
 * Commands:
 *   php bin/app.php db upgrade        Apply pending migrations
 *   php bin/app.php db status         Show migration version (read-only)
 *   php bin/app.php doctor            Check configuration, DB, schema and connections (no network)
 *   php bin/app.php doctor --check-api  Explicit API checks for active stores
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, "PHP 8.4+ required\n");
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use Infrastructure\Config;
use Infrastructure\Database;
use Infrastructure\Encryption;

// ──────────────────────────────────────────────
//  Parse arguments
// ──────────────────────────────────────────────
$args = array_slice($argv, 1);
$command = $args[0] ?? 'help';

function printUsage(): void
{
    echo "Usage:\n";
    echo "  php bin/app.php db upgrade        Apply pending migrations\n";
    echo "  php bin/app.php db status         Show current migration version\n";
    echo "  php bin/app.php doctor            Check config, DB and schema\n";
    echo "  php bin/app.php doctor --check-api  Check API connectivity for active stores\n";
    echo "  php bin/app.php help              Show this help\n";
}

// ──────────────────────────────────────────────
//  Shared helpers
// ──────────────────────────────────────────────

function createDatabase(string $dbPath): Database
{
    return new Database($dbPath);
}

function loadMigrations(string $rootDir): array
{
    return require $rootDir . '/migrations/migrate.php';
}

function getCurrentVersion(Database $db): int
{
    try {
        $row = $db->fetch('SELECT MAX(version) as v FROM _schema_version');
        return (int) ($row['v'] ?? 0);
    } catch (\PDOException) {
        return 0;
    }
}

// ──────────────────────────────────────────────
//  Route command
// ──────────────────────────────────────────────

$rootDir = dirname(__DIR__);
$config = new Config($rootDir);

match ($command) {
    'db' => handleDbCommand($args[1] ?? 'help', $rootDir, $config),
    'doctor' => handleDoctor($args, $rootDir, $config),
    'help', '--help', '-h' => printUsage(),
    default => printUsage(),
};

// ──────────────────────────────────────────────
//  db upgrade / db status
// ──────────────────────────────────────────────

function handleDbCommand(string $sub, string $rootDir, Config $config): void
{
    $dbPath = resolveDbPath($config, $rootDir);
    $migrations = loadMigrations($rootDir);
    $total = count($migrations);

    match ($sub) {
        'upgrade' => doUpgrade($dbPath, $migrations, $total),
        'status' => doStatus($dbPath, $migrations, $total),
        default => printUsage(),
    };
}

function doUpgrade(string $dbPath, array $migrations, int $total): void
{
    echo "Database: {$dbPath}\n";

    $db = createDatabase($dbPath);

    $currentBefore = getCurrentVersion($db);
    echo sprintf("Current schema version: %d / %d\n", $currentBefore, $total);

    if ($currentBefore >= $total) {
        echo "Schema is up to date.\n";
        return;
    }

    $db->migrate($migrations);

    $currentAfter = getCurrentVersion($db);
    echo sprintf("Schema version after upgrade: %d / %d\n", $currentAfter, $total);

    if ($currentAfter <= $currentBefore) {
        echo "No migrations were applied.\n";
    } else {
        echo sprintf("Applied migrations %d..%d\n", $currentBefore + 1, $currentAfter);
    }
}

function doStatus(string $dbPath, array $migrations, int $total): void
{
    if (!file_exists($dbPath)) {
        echo "Database file does not exist: {$dbPath}\n";
        echo "Schema version: 0 / {$total}\n";
        exit(0);
    }

    try {
        $db = createDatabase($dbPath);
        $current = getCurrentVersion($db);
        echo sprintf("Schema version: %d / %d\n", $current, $total);
    } catch (\PDOException $e) {
        echo "Error reading database: {$e->getMessage()}\n";
        exit(1);
    }
}

// ──────────────────────────────────────────────
//  doctor
// ──────────────────────────────────────────────

function handleDoctor(array $args, string $rootDir, Config $config): void
{
    $checkApi = in_array('--check-api', $args, true);
    $hasErrors = false;

    // 1. Check config
    echo "1. Checking configuration...\n";

    try {
        $appId = $config->getString('INSALES_APP_ID');
        $appSecret = $config->getString('INSALES_APP_SECRET');
        $baseUrl = $config->getString('APP_BASE_URL');
        $allowedShops = $config->getAllowedShops();

        echo "   INSALES_APP_ID:      " . ($appId !== '' ? 'set' : 'EMPTY') . "\n";
        echo "   INSALES_APP_SECRET:  " . ($appSecret !== '' ? 'set' : 'EMPTY') . "\n";
        echo "   APP_BASE_URL:        " . ($baseUrl ?: 'EMPTY') . "\n";
        echo "   ALLOWED_SHOPS:       " . ($allowedShops !== [] ? implode(', ', $allowedShops) : 'not set') . "\n";
    } catch (\RuntimeException $e) {
        echo "   ERROR: {$e->getMessage()}\n";
        $hasErrors = true;
    }

    // 2. Check encryption key
    echo "\n2. Checking encryption key...\n";

    try {
        $keyHex = $config->getString('APP_ENCRYPTION_KEY', '');
        if ($keyHex === '') {
            echo "   WARNING: APP_ENCRYPTION_KEY is not set\n";
        } elseif (strlen($keyHex) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2) {
            echo "   ERROR: APP_ENCRYPTION_KEY has wrong length\n";
            $hasErrors = true;
        } else {
            $key = sodium_hex2bin($keyHex);
            if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                echo "   ERROR: APP_ENCRYPTION_KEY decodes to wrong key length\n";
                $hasErrors = true;
            } else {
                // Verify encryption roundtrip
                $encryption = new Encryption($keyHex);
                $test = 'doctor-test-' . bin2hex(random_bytes(4));
                $encrypted = $encryption->encrypt($test);
                $decrypted = $encryption->decrypt($encrypted);
                if ($decrypted !== $test) {
                    echo "   ERROR: encryption roundtrip failed\n";
                    $hasErrors = true;
                } else {
                    echo "   Encryption key: valid\n";
                }
            }
        }
    } catch (\RuntimeException $e) {
        echo "   ERROR: {$e->getMessage()}\n";
        $hasErrors = true;
    }

    // 3. Check database
    echo "\n3. Checking database...\n";

    $dbPath = resolveDbPath($config, $rootDir);
    $dbDir = dirname($dbPath);

    echo "   Path: {$dbPath}\n";

    if (!is_dir($dbDir)) {
        echo "   Directory does not exist (will be created on first use)\n";
    } elseif (!is_writable($dbDir)) {
        echo "   ERROR: database directory is not writable\n";
        $hasErrors = true;
    } else {
        echo "   Directory: exists, writable\n";
    }

    if (file_exists($dbPath)) {
        if (!is_readable($dbPath) || !is_writable($dbPath)) {
            echo "   ERROR: database file is not readable/writable\n";
            $hasErrors = true;
        } else {
            try {
                $db = createDatabase($dbPath);
                $migrations = loadMigrations($rootDir);
                $current = getCurrentVersion($db);
                $total = count($migrations);

                echo "   Schema version: {$current} / {$total}\n";

                if ($current < $total) {
                    echo "   WARNING: migrations are not up to date. Run: php bin/app.php db upgrade\n";
                }

                // Check store count
                $storeCount = (int) ($db->fetch('SELECT COUNT(*) as c FROM stores')['c'] ?? 0);
                echo "   Active stores: {$storeCount}\n";
            } catch (\PDOException $e) {
                echo "   ERROR: {$e->getMessage()}\n";
                $hasErrors = true;
            }
        }
    } else {
        echo "   File does not exist (will be created on first upgrade)\n";
    }

    // 4. Check PHP extensions
    echo "\n4. Checking PHP extensions...\n";

    $required = ['pdo', 'pdo_sqlite', 'sodium', 'mbstring', 'curl'];
    foreach ($required as $ext) {
        echo "   {$ext}: " . (extension_loaded($ext) ? '✓' : '✗ MISSING') . "\n";
    }

    // 5. Optional API check
    if ($checkApi) {
        echo "\n5. Checking API connectivity for active stores...\n";

        if (!file_exists($dbPath)) {
            echo "   No database — skipping API check\n";
        } else {
            try {
                $db = createDatabase($dbPath);
                $stores = $db->fetchAll('SELECT * FROM stores');

                if ($stores === []) {
                    echo "   No active stores\n";
                } else {
                    $keyHex = $config->getString('APP_ENCRYPTION_KEY', '');
                    $encryption = new Encryption($keyHex);
                    $appId = $config->getString('INSALES_APP_ID');
                    $allowHttp = $config->getString('APP_ENV', 'development') === 'test';

                    foreach ($stores as $store) {
                        $shop = (string) $store['shop'];
                        $encPass = (string) $store['encrypted_password'];
                        $storeId = (string) $store['store_id'];
                        $title = (string) ($store['title'] ?? '?');

                        try {
                            $password = $encryption->decrypt($encPass);
                            $client = new \InSales\ApiClient(
                                appId: $appId,
                                shop: $shop,
                                password: $password,
                                allowHttp: $allowHttp,
                                logger: new \Psr\Log\NullLogger(),
                            );

                            $timeout = 5;
                            $start = microtime(true);
                            $account = $client->getAccount();
                            $elapsed = round(microtime(true) - $start, 2);

                            $returnedId = (string) ($account['id'] ?? '');
                            $match = $returnedId === $storeId ? '✓' : '✗ ID mismatch';

                            echo "   [{$title}] {$shop}: {$match} ({$elapsed}s)\n";
                        } catch (\Throwable $e) {
                            echo "   [{$title}] {$shop}: ✗ {$e->getMessage()}\n";
                        }
                    }
                }
            } catch (\PDOException $e) {
                echo "   ERROR reading database: {$e->getMessage()}\n";
                $hasErrors = true;
            }
        }
    }

    exit($hasErrors ? 1 : 0);
}

// ──────────────────────────────────────────────
//  Resolve DB path (consistent with Config + default)
// ──────────────────────────────────────────────

function resolveDbPath(Config $config, string $rootDir): string
{
    $dbPath = $config->getString('APP_DB_PATH', '');
    if ($dbPath === '') {
        return $rootDir . '/runtime/app.sqlite3';
    }
    // If relative, resolve against rootDir
    if (str_starts_with($dbPath, '/')) {
        return $dbPath;
    }
    return $rootDir . '/' . $dbPath;
}