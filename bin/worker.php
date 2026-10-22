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
use Infrastructure\ContainerFactory;
use Infrastructure\JobQueue;
use Application\InstallationService;

$rootDir = dirname(__DIR__);
$config = new Config($rootDir);
$pollInterval = (int) ($config->getString('WORKER_POLL_INTERVAL', '2'));

// Use shared DI container
$container = ContainerFactory::create($rootDir);

$db = $container->get(\Infrastructure\Database::class);
$queue = $container->get(JobQueue::class);
$installationService = $container->get(InstallationService::class);

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