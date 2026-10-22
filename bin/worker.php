#!/usr/bin/env php
<?php

/**
 * Background worker for async job processing.
 *
 * Processes jobs from the JobQueue, currently:
 * - verify_install: Verify install candidate via InSales API
 *
 * Run: php bin/worker.php
 *
 * Graceful shutdown on SIGTERM/SIGINT.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, "PHP 8.4+ required\n");
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

pcntl_async_signals(true);

use Infrastructure\Config;
use Infrastructure\Database;
use Infrastructure\Encryption;
use Infrastructure\JobQueue;
use Application\InstallationService;

$rootDir = dirname(__DIR__);
$config = new Config($rootDir);

$appId = $config->getString('INSALES_APP_ID');
$appSecret = $config->getString('INSALES_APP_SECRET');
$allowedShops = $config->getAllowedShops();
$encryptionKey = $config->getString('APP_ENCRYPTION_KEY', '');
$dbPath = $config->getString('APP_DB_PATH', $rootDir . '/runtime/app.sqlite3');
$appEnv = $config->getString('APP_ENV', 'development');
$allowHttp = $appEnv === 'test' || $appEnv === 'development';
$pollInterval = (int) ($config->getString('WORKER_POLL_INTERVAL', '2'));

if ($encryptionKey === '') {
    $encryptionKey = bin2hex(sodium_crypto_secretbox_keygen());
}

$db = new Database($dbPath);
$migrations = require $rootDir . '/migrations/migrate.php';
$db->migrate($migrations);

$encryption = new Encryption($encryptionKey);
$queue = new JobQueue($db);

$installationService = new InstallationService(
    $db,
    $encryption,
    $queue,
    $appSecret,
    $appId,
    $allowedShops,
    $allowHttp,
);

// Graceful shutdown
$shutdown = false;
pcntl_signal(SIGTERM, function () use (&$shutdown) { $shutdown = true; });
pcntl_signal(SIGINT, function () use (&$shutdown) { $shutdown = true; });

fwrite(STDOUT, "Worker started (poll interval: {$pollInterval}s)\n");

while (!$shutdown) {
    $job = $queue->claim();

    if ($job === null) {
        sleep($pollInterval);
        continue;
    }

    fwrite(STDOUT, "Processing job #{$job['id']}: {$job['type']}\n");

    try {
        match ($job['type']) {
            'verify_install' => $installationService->verifyCandidate(
                $job['payload']['candidate_id'] ?? '',
            ),
            default => throw new \RuntimeException("Unknown job type: {$job['type']}"),
        };

        $queue->complete((int) $job['id']);
        fwrite(STDOUT, "Job #{$job['id']} completed\n");
    } catch (\Throwable $e) {
        $retried = $queue->fail((int) $job['id'], $e->getMessage());
        if ($retried) {
            fwrite(STDERR, "Job #{$job['id']} failed, will retry: {$e->getMessage()}\n");
        } else {
            fwrite(STDERR, "Job #{$job['id']} failed permanently: {$e->getMessage()}\n");
        }
    }
}

fwrite(STDOUT, "Worker stopped gracefully\n");