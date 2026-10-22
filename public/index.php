<?php

/**
 * InSales Partner Application — HTTP entry point.
 *
 * Bootstrap Slim 4 with PHP-DI container.
 */

// Check PHP version
if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP 8.4+ required']);
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use Infrastructure\ContainerFactory;
use Infrastructure\Config;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Slim\Routing\RouteCollectorProxy;

// Load environment
$rootDir = dirname(__DIR__);
$config = new Config($rootDir);
$appEnv = $config->getString('APP_ENV', 'development');

// Build DI container via shared factory
$container = ContainerFactory::create($rootDir);

// Create Slim app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Add middleware
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();

// Error handling
$errorMiddleware = $app->addErrorMiddleware(
    $appEnv === 'development' || $appEnv === 'test',
    true,
    true,
);

$errorMiddleware->setDefaultErrorHandler(function (
    Psr\Http\Message\ServerRequestInterface $request,
    \Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails,
) {
    $response = new Response();
    $response->getBody()->write('Internal server error');
    return $response->withStatus(500)
        ->withHeader('Content-Type', 'text/plain; charset=utf-8');
});

// Routes
$app->get('/health', [\Http\Controllers\DashboardController::class, 'health'])->setName('health');

// Install callback (no session required)
$app->get('/install', \Http\Controllers\InstallController::class)->setName('install');

// Login flow
$app->get('/login', [\Http\Controllers\LoginController::class, 'login'])->setName('login');
$app->get('/autologin', [\Http\Controllers\LoginController::class, 'autologin'])->setName('autologin');
$app->post('/logout', [\Http\Controllers\LoginController::class, 'logout'])->setName('logout');

// Uninstall callback (no session required)
$app->get('/uninstall', \Http\Controllers\UninstallController::class)->setName('uninstall');

// Protected routes (session middleware)
$app->group('', function (RouteCollectorProxy $group) {
    $group->get('/', [\Http\Controllers\DashboardController::class, 'index'])->setName('dashboard');
    $group->get('/probe', [\Http\Controllers\DashboardController::class, 'probe'])->setName('probe');
})->add(\Http\Middleware\SessionMiddleware::class);

// Run
$app->run();