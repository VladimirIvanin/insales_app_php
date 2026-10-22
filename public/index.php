<?php

declare(strict_types=1);

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

use Http\Controllers\DashboardController;
use Http\Controllers\InstallController;
use Http\Controllers\LoginController;
use Http\Controllers\UninstallController;
use Http\Middleware\SessionMiddleware;
use Infrastructure\Config;
use Psr\Container\ContainerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Slim\Routing\RouteCollectorProxy;

// Load environment
$rootDir = dirname(__DIR__);
$config = new Config($rootDir);

$appId = $config->getString('INSALES_APP_ID');
$appSecret = $config->getString('INSALES_APP_SECRET');
$baseUrl = $config->getString('APP_BASE_URL');
$allowedShops = $config->getAllowedShops();
$encryptionKey = $config->getString('APP_ENCRYPTION_KEY');
$dbPath = $config->getString('APP_DB_PATH', $rootDir . '/runtime/app.sqlite3');
$appEnv = $config->getString('APP_ENV', 'development');
$allowHttp = $appEnv === 'test' || $appEnv === 'development';

// Build DI container
$container = new DI\Container();

// Infrastructure
$container->set(Infrastructure\Database::class, function () use ($dbPath) {
    $db = new Infrastructure\Database($dbPath);

    // Apply migrations
    $migrations = require $rootDir . '/migrations/migrate.php';
    $db->migrate($migrations);

    return $db;
});

$container->set(Infrastructure\Encryption::class, function () use ($encryptionKey) {
    return new Infrastructure\Encryption($encryptionKey);
});

$container->set(Infrastructure\JobQueue::class, function (ContainerInterface $c) {
    return new Infrastructure\JobQueue(
        $c->get(Infrastructure\Database::class),
    );
});

// Application services
$container->set(Application\InstallationService::class, function (ContainerInterface $c) use ($appSecret, $appId, $allowedShops, $allowHttp) {
    return new Application\InstallationService(
        $c->get(Infrastructure\Database::class),
        $c->get(Infrastructure\Encryption::class),
        $c->get(Infrastructure\JobQueue::class),
        $appSecret,
        $appId,
        $allowedShops,
        $allowHttp,
    );
});

$container->set(Application\LoginService::class, function (ContainerInterface $c) use ($appId, $baseUrl) {
    return new Application\LoginService(
        $c->get(Infrastructure\Database::class),
        $appId,
        $baseUrl,
    );
});

$container->set(Application\UninstallService::class, function (ContainerInterface $c) use ($appSecret, $allowedShops, $allowHttp) {
    return new Application\UninstallService(
        $c->get(Infrastructure\Database::class),
        $c->get(Infrastructure\Encryption::class),
        $appSecret,
        $allowedShops,
        $allowHttp,
    );
});

// HTTP layer
$container->set(SessionMiddleware::class, function (ContainerInterface $c) use ($baseUrl) {
    return new SessionMiddleware(
        $c->get(Application\LoginService::class),
        $baseUrl,
    );
});

$container->set(InstallController::class, function (ContainerInterface $c) {
    return new InstallController(
        $c->get(Application\InstallationService::class),
    );
});

$container->set(LoginController::class, function (ContainerInterface $c) use ($baseUrl) {
    return new LoginController(
        $c->get(Application\LoginService::class),
        $c->get(SessionMiddleware::class),
        $baseUrl,
    );
});

$container->set(UninstallController::class, function (ContainerInterface $c) {
    return new UninstallController(
        $c->get(Application\UninstallService::class),
    );
});

$container->set(DashboardController::class, function (ContainerInterface $c) use ($appId, $allowHttp) {
    return new DashboardController(
        $appId,
        $allowHttp,
    );
});

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

// Custom error handler
$errorMiddleware->setDefaultErrorHandler(function (
    Psr\Http\Message\ServerRequestInterface $request,
    \Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails,
) use ($app) {
    $response = new Response();
    $response->getBody()->write('Internal server error');
    return $response->withStatus(500)
        ->withHeader('Content-Type', 'text/plain; charset=utf-8');
});

// Routes
$app->get('/health', [DashboardController::class, 'health'])->setName('health');

// Install callback (no session required)
$app->get('/install', InstallController::class)->setName('install');

// Login flow
$app->get('/login', [LoginController::class, 'login'])->setName('login');
$app->get('/autologin', [LoginController::class, 'autologin'])->setName('autologin');
$app->post('/logout', [LoginController::class, 'logout'])->setName('logout');

// Uninstall callback (no session required)
$app->get('/uninstall', UninstallController::class)->setName('uninstall');

// Protected routes (session middleware)
$app->group('', function (RouteCollectorProxy $group) {
    $group->get('/', [DashboardController::class, 'index'])->setName('dashboard');
    $group->get('/probe', [DashboardController::class, 'probe'])->setName('probe');
})->add(SessionMiddleware::class);

// Run
$app->run();