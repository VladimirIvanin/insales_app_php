<?php

/**
 * Test bootstrap.
 *
 * Сценарий с побочными эффектами: окружение и миграции до запуска тестов.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// APP_DB_PATH задаётся до загрузки ContainerFactory и Config.
$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DB_PATH'] = __DIR__ . '/../runtime/test.sqlite3';
$_ENV['INSALES_APP_ID'] = 'test-app-id';
$_ENV['INSALES_APP_SECRET'] = 'test-app-secret';
$_ENV['ALLOWED_SHOPS'] = 'test.myshop.ru,127.0.0.1';
$_ENV['APP_BASE_URL'] = 'http://127.0.0.1:18765';
$_ENV['APP_ENCRYPTION_KEY'] = '0000000000000000000000000000000000000000000000000000000000000000';

$dbPath = '/tmp/insales-test-' . getmyuid() . '.sqlite3';
$_ENV['APP_DB_PATH'] = $dbPath;
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$migrations = require __DIR__ . '/../migrations/migrate.php';
$db = new \Infrastructure\Database($dbPath);
$db->migrate($migrations);
