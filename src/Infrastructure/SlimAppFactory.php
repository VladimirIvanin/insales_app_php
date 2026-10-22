<?php

declare(strict_types=1);

namespace Infrastructure;

use Http\Controllers\DashboardController;
use Http\Controllers\InstallController;
use Http\Controllers\LoginController;
use Http\Controllers\UninstallController;
use Http\Middleware\SessionMiddleware;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Slim\Routing\RouteCollectorProxy;

/**
 * Фабрика Slim-приложения.
 *
 * Создаёт настроенное приложение Slim 4 с маршрутами и middleware,
 * используя общий DI-контейнер из ContainerFactory.
 * Приложение НЕ запускается (не вызывается $app->run()).
 *
 * Используется:
 * - public/index.php — точка входа HTTP
 * - tests — для проверки маршрутов и middleware без реального сервера
 */
final readonly class SlimAppFactory
{
    /**
     * Создать Slim-приложение из корня проекта.
     *
     * @param string $rootDir Абсолютный путь корня проекта
     * @return App<\Psr\Container\ContainerInterface>
     */
    public static function create(string $rootDir): App
    {
        $config = new Config($rootDir);
        $appEnv = $config->getString('APP_ENV', 'development');

        $container = ContainerFactory::create($rootDir);
        AppFactory::setContainer($container);
        /** @var App<\Psr\Container\ContainerInterface> $app */
        $app = AppFactory::create();

        // Middleware
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();

        // Error handling
        $errorMiddleware = $app->addErrorMiddleware(
            $appEnv === 'development' || $appEnv === 'test',
            true,
            true,
        );

        /** @phpstan-ignore-next-line */
        $errorMiddleware->setDefaultErrorHandler(function (
            \Psr\Http\Message\ServerRequestInterface $request,
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
        $app->get('/health', [DashboardController::class, 'health'])->setName('health');
        $app->get('/install', InstallController::class)->setName('install');
        $app->get('/login', [LoginController::class, 'login'])->setName('login');
        $app->get('/autologin', [LoginController::class, 'autologin'])->setName('autologin');
        $app->post('/logout', [LoginController::class, 'logout'])->setName('logout');
        $app->get('/uninstall', UninstallController::class)->setName('uninstall');

        $app->group('', function (RouteCollectorProxy $group) {
            $group->get('/', [DashboardController::class, 'index'])->setName('dashboard');
            $group->get('/probe', [DashboardController::class, 'probe'])->setName('probe');
        })->add(SessionMiddleware::class);

        return $app;
    }
}
