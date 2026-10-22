<?php

/**
 * InSales Partner Application — HTTP entry point.
 *
 * Bootstrap Slim 4 with shared AppFactory.
 */

// Check PHP version
if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP 8.4+ required']);
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use Infrastructure\SlimAppFactory;

$rootDir = dirname(__DIR__);

// Create and run Slim app via shared factory
$app = SlimAppFactory::create($rootDir);
$app->run();