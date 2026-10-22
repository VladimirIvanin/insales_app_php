<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Set test environment
$_ENV['APP_ENV'] = 'test';
$_ENV['INSALES_APP_ID'] = 'test-app-id';
$_ENV['INSALES_APP_SECRET'] = 'test-app-secret';
$_ENV['ALLOWED_SHOPS'] = 'test.myshop.ru,127.0.0.1';
$_ENV['APP_BASE_URL'] = 'http://127.0.0.1:18765';
$_ENV['APP_ENCRYPTION_KEY'] = '0000000000000000000000000000000000000000000000000000000000000000';

// Clean up any previous test databases
$dbPath = __DIR__ . '/../runtime/test.sqlite3';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

// Set up md5 alias if not available (PHP 8.4+ still has it)
if (!function_exists('md5')) {
    /**
     * Polyfill md5() helper used in Protocol.
     */
    function md5(string $data): string
    {
        return hash('md5', $data);
    }
}