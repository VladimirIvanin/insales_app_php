<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infrastructure\Database;
use Infrastructure\Encryption;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[TestDox('doctor --check-api exit status')]
final class DoctorCommandTest extends TestCase
{
    #[TestDox('API or identity failure makes doctor exit 1')]
    public function testApiFailureIsAnError(): void
    {
        $root = dirname(__DIR__, 2);
        $dbPath = sys_get_temp_dir() . '/insales-doctor-' . bin2hex(random_bytes(4)) . '.sqlite3';
        $key = str_repeat('ab', 32);
        $encryption = new Encryption($key);
        $db = new Database($dbPath);
        /** @var array<int, string> $migrations */
        $migrations = require $root . '/migrations/migrate.php';
        $db->migrate($migrations);
        $db->execute(
            'INSERT INTO stores (store_id, shop, encrypted_password, title, generation, installed_at)
             VALUES (:id, :shop, :password, :title, 1, :installed)',
            [
                'id' => '42',
                'shop' => '127.0.0.1',
                'password' => $encryption->encrypt('store-password'),
                'title' => 'Broken',
                'installed' => time(),
            ],
        );
        unset($db);

        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['APP_DB_PATH'] = $dbPath;
        $env['APP_ENCRYPTION_KEY'] = $key;
        $env['APP_ENV'] = 'development';
        $env['INSALES_APP_ID'] = 'doctor-app';
        $env['INSALES_APP_SECRET'] = 'doctor-secret';
        $env['APP_BASE_URL'] = 'http://127.0.0.1:8765';
        $env['ALLOWED_SHOPS'] = '127.0.0.1';

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, $root . '/bin/app.php', 'doctor', '--check-api'],
            $descriptor,
            $pipes,
            $root,
            $env,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(1, $exitCode);
        $this->assertIsString($stdout);
        $this->assertStringContainsString('127.0.0.1', $stdout);
        $this->assertStringContainsString('✗', $stdout);

        @unlink($dbPath);
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');
    }
}
