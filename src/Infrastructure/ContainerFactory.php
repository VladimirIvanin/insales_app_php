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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
        $container->set(Database::class, function () use ($dbPath): Database {
            return new Database($dbPath);
        });

        $container->set(Encryption::class, function () use ($encryptionKey): Encryption {
            return new Encryption($encryptionKey);
        });

        // Application services
        $container->set(InstallationService::class, function (ContainerInterface $c) use ($appSecret, $appId, $allowedShops, $allowHttp, $baseUrl): InstallationService {
            $db = $c->get(Database::class);
            assert($db instanceof Database);
            $encryption = $c->get(Encryption::class);
            assert($encryption instanceof Encryption);
            return new InstallationService(
                $db,
                $encryption,
                $appSecret,
                $appId,
                $allowedShops,
                $allowHttp,
                platformBaseUrl: $baseUrl,
            );
        });

        $container->set(LoginService::class, function (ContainerInterface $c) use ($appId, $baseUrl): LoginService {
            $db = $c->get(Database::class);
            assert($db instanceof Database);
            $encryption = $c->get(Encryption::class);
            assert($encryption instanceof Encryption);
            $installService = $c->get(InstallationService::class);
            assert($installService instanceof InstallationService);
            return new LoginService(
                $db,
                $encryption,
                $installService,
                $appId,
                $baseUrl,
            );
        });

        $container->set(UninstallService::class, function (ContainerInterface $c) use ($allowedShops, $allowHttp): UninstallService {
            $db = $c->get(Database::class);
            assert($db instanceof Database);
            $encryption = $c->get(Encryption::class);
            assert($encryption instanceof Encryption);
            return new UninstallService(
                $db,
                $encryption,
                $allowedShops,
                $allowHttp,
            );
        });

        // HTTP layer (только для index.php — worker использует сервисы напрямую)
        $container->set(SessionMiddleware::class, function (ContainerInterface $c) use ($baseUrl): SessionMiddleware {
            $loginService = $c->get(LoginService::class);
            assert($loginService instanceof LoginService);
            return new SessionMiddleware(
                $loginService,
                $baseUrl,
            );
        });

        $container->set(InstallController::class, function (ContainerInterface $c): InstallController {
            $installService = $c->get(InstallationService::class);
            assert($installService instanceof InstallationService);
            return new InstallController(
                $installService,
            );
        });

        $container->set(LoginController::class, function (ContainerInterface $c) use ($baseUrl): LoginController {
            $loginService = $c->get(LoginService::class);
            assert($loginService instanceof LoginService);
            $sessionMiddleware = $c->get(SessionMiddleware::class);
            assert($sessionMiddleware instanceof SessionMiddleware);
            return new LoginController(
                $loginService,
                $sessionMiddleware,
                $baseUrl,
            );
        });

        $container->set(UninstallController::class, function (ContainerInterface $c): UninstallController {
            $uninstallService = $c->get(UninstallService::class);
            assert($uninstallService instanceof UninstallService);
            return new UninstallController(
                $uninstallService,
            );
        });

        $container->set(LoggerInterface::class, function (): LoggerInterface {
            return new \Psr\Log\NullLogger();
        });

        $container->set(DashboardController::class, function (ContainerInterface $c) use ($appId, $allowHttp): DashboardController {
            $db = $c->get(Database::class);
            assert($db instanceof Database);
            $encryption = $c->get(Encryption::class);
            assert($encryption instanceof Encryption);
            $logger = $c->get(LoggerInterface::class);
            assert($logger instanceof LoggerInterface);
            return new DashboardController(
                $appId,
                $allowHttp,
                $db,
                $encryption,
                $logger,
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
