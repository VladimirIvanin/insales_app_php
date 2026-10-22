<?php

declare(strict_types=1);

namespace Infrastructure;

use Application\InstallationService;
use Application\LoginService;
use Application\UninstallService;
use DI\Container;
use Http\Controllers\DashboardController;
use Http\Controllers\InstallController;
use Http\Controllers\LoginController;
use Http\Controllers\UninstallController;
use Http\Middleware\SessionMiddleware;
use Infrastructure\Config;
use Infrastructure\Database;
use Infrastructure\Encryption;
use Psr\Container\ContainerInterface;

/**
 * Единая фабрика DI-контейнера для HTTP (index.php) и CLI (bin/app.php).
 *
 * Пустой APP_ENCRYPTION_KEY завершает процесс в production.
 */
final readonly class ContainerFactory
{
    /**
     * @param string $rootDir Абсолютный путь к корню проекта
     */
    public static function create(string $rootDir): Container
    {
        $config = new Config($rootDir);

        $appId = $config->getString('INSALES_APP_ID');
        $appSecret = $config->getString('INSALES_APP_SECRET');
        $baseUrl = $config->getString('APP_BASE_URL');
        $allowedShops = $config->getAllowedShops();
        $encryptionKey = self::resolveEncryptionKey($config);
        $dbPath = $config->getString('APP_DB_PATH', $rootDir . '/runtime/app.sqlite3');
        $appEnv = $config->getString('APP_ENV', 'development');
        $allowHttp = $appEnv === 'test';

        $container = new Container();

        // Infrastructure — Database without auto-migration.
        // Run migrations explicitly: php bin/app.php db upgrade
        $container->set(Database::class, function () use ($dbPath) {
            return new Database($dbPath);
        });

        $container->set(Encryption::class, function () use ($encryptionKey) {
            return new Encryption($encryptionKey);
        });

        // Application services
        $container->set(InstallationService::class, function (ContainerInterface $c) use ($appSecret, $appId, $allowedShops, $allowHttp, $baseUrl) {
            return new InstallationService(
                $c->get(Database::class),
                $c->get(Encryption::class),
                $appSecret,
                $appId,
                $allowedShops,
                $allowHttp,
                platformBaseUrl: $baseUrl,
            );
        });

        $container->set(LoginService::class, function (ContainerInterface $c) use ($appId, $baseUrl) {
            return new LoginService(
                $c->get(Database::class),
                $c->get(Encryption::class),
                $c->get(InstallationService::class),
                $appId,
                $baseUrl,
            );
        });

        $container->set(UninstallService::class, function (ContainerInterface $c) use ($allowedShops, $allowHttp) {
            return new UninstallService(
                $c->get(Database::class),
                $c->get(Encryption::class),
                $allowedShops,
                $allowHttp,
            );
        });

        // HTTP layer (только для index.php — worker использует сервисы напрямую)
        $container->set(SessionMiddleware::class, function (ContainerInterface $c) use ($baseUrl) {
            return new SessionMiddleware(
                $c->get(LoginService::class),
                $baseUrl,
            );
        });

        $container->set(InstallController::class, function (ContainerInterface $c) {
            return new InstallController(
                $c->get(InstallationService::class),
            );
        });

        $container->set(LoginController::class, function (ContainerInterface $c) use ($baseUrl) {
            return new LoginController(
                $c->get(LoginService::class),
                $c->get(SessionMiddleware::class),
                $baseUrl,
            );
        });

        $container->set(UninstallController::class, function (ContainerInterface $c) {
            return new UninstallController(
                $c->get(UninstallService::class),
            );
        });

        $container->set(\Psr\Log\LoggerInterface::class, function () {
            return new \Psr\Log\NullLogger();
        });

        $container->set(DashboardController::class, function (ContainerInterface $c) use ($appId, $allowHttp) {
            return new DashboardController(
                $appId,
                $allowHttp,
                $c->get(Database::class),
                $c->get(Encryption::class),
                $c->get(\Psr\Log\LoggerInterface::class),
            );
        });

        return $container;
    }

    /**
     * Разрешает ключ шифрования. Пустой ключ завершает процесс.
     */
    private static function resolveEncryptionKey(Config $config): string
    {
        $key = $config->getString('APP_ENCRYPTION_KEY', '');

        if ($key !== '') {
            return $key;
        }

        $env = $config->getString('APP_ENV', 'development');
        if ($env === 'production') {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY is required in production. '
                . 'Generate: php -r \'echo bin2hex(sodium_crypto_secretbox_keygen());\''
            );
        }

        // Dev/test — детерминированный ключ (не случайный) для воспроизводимости
        // между HTTP и worker процессами
        trigger_error(
            'APP_ENCRYPTION_KEY is empty in ' . $env . ' environment. '
            . 'Set a persistent key in .env to avoid data loss between restarts. '
            . 'Using a deterministic dev key.',
            E_USER_WARNING,
        );

        return bin2hex(
            str_repeat("\x00", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        );
    }
}
