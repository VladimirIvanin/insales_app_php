<?php

declare(strict_types=1);

namespace Tests\Unit;

use InSales\Protocol;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Protocol::class)]
#[TestDox('Protocol formulas (MD5-based)')]
final class ProtocolTest extends TestCase
{
    /** @var array<string, mixed> $vectors */
    private array $vectors = [];

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/protocol-vectors.json');
        if (!is_string($raw)) {
            self::fail('Cannot read protocol vectors');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::fail('Protocol vectors are not an object');
        }
        /** @var array<string, mixed> $decoded */
        $this->vectors = $decoded;
    }

    #[TestDox('installPassword matches cross-language test vectors')]
    public function testInstallPasswordVector(): void
    {
        $password = Protocol::installPassword(
            $this->vectorString('install', 'token'),
            $this->vectorString('install', 'secret'),
        );

        $this->assertSame($this->vectorString('install', 'expected_password'), $password);
    }

    #[TestDox('token3 matches cross-language test vectors with Cyrillic name')]
    public function testToken3Vector(): void
    {
        $token3 = Protocol::token3(
            $this->vectorString('autologin', 'nonce'),
            $this->vectorString('autologin', 'email'),
            $this->vectorString('autologin', 'name'),
            $this->vectorString('autologin', 'user_id'),
            $this->vectorString('autologin', 'email_confirmed'),
            $this->vectorString('install', 'expected_password'),
        );

        $this->assertSame($this->vectorString('autologin', 'expected_token3'), $token3);
    }

    #[TestDox('token2 matches cross-language test vectors')]
    public function testToken2Vector(): void
    {
        $token2 = Protocol::token2(
            $this->vectorString('autologin', 'nonce'),
            $this->vectorString('autologin', 'email'),
            $this->vectorString('autologin', 'name'),
            $this->vectorString('autologin', 'user_id'),
            $this->vectorString('install', 'expected_password'),
        );

        $this->assertSame($this->vectorString('autologin', 'expected_token2'), $token2);
    }

    #[TestDox('token matches cross-language test vectors')]
    public function testTokenVector(): void
    {
        $token = Protocol::token(
            $this->vectorString('autologin', 'nonce'),
            $this->vectorString('install', 'expected_password'),
        );

        $this->assertSame($this->vectorString('autologin', 'expected_token'), $token);
    }

    private function vectorString(string $group, string $key): string
    {
        $section = $this->vectors[$group] ?? null;
        if (!is_array($section)) {
            self::fail('Missing vector group ' . $group);
        }
        $value = $section[$key] ?? null;
        if (!is_string($value)) {
            self::fail('Vector ' . $group . '.' . $key . ' is not a string');
        }
        return $value;
    }

    #[TestDox('installPassword produces lowercase 32-char hex')]
    public function testInstallPasswordFormat(): void
    {
        $password = Protocol::installPassword('any-token', 'any-secret');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $password);
    }

    #[TestDox('token3 handles Cyrillic and empty user_name')]
    #[DataProvider('token3DataProvider')]
    public function testToken3HandlesEdgeCases(
        string $nonce,
        string $email,
        string $name,
        string $userId,
        string $emailConfirmed,
        string $password,
    ): void {
        $token3 = Protocol::token3($nonce, $email, $name, $userId, $emailConfirmed, $password);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token3);
    }

    /**
     * @return array<string, array{string, string, string, string, string, string}>
     */
    public static function token3DataProvider(): array
    {
        return [
            'empty username' => ['nonce123', 'user@example.com', '', '42', 'false', 'pass123'],
            'circassian username' => ['nonce456', 'buyer@shop.ru', 'Иван Иванов', '99', 'true', 'pass456'],
            'email confirmed true as string' => ['nonce789', 'test@test.com', 'Test', '1', 'true', 'pass789'],
            'long values' => [
                str_repeat('a', 64),
                'long@email.com',
                str_repeat('b', 50),
                '999999',
                'false',
                str_repeat('c', 32),
            ],
        ];
    }

    #[TestDox('different passwords produce different hashes')]
    public function testDifferentPasswords(): void
    {
        $p1 = Protocol::installPassword('token1', 'secret');
        $p2 = Protocol::installPassword('token2', 'secret');

        $this->assertNotSame($p1, $p2);
    }

    #[TestDox('token3 differs from token2')]
    public function testToken3DiffersFromToken2(): void
    {
        $nonce = 'test-nonce';
        $email = 'user@example.com';
        $name = 'User';
        $userId = '42';
        $password = 'test-password';

        $token2 = Protocol::token2($nonce, $email, $name, $userId, $password);
        $token3 = Protocol::token3($nonce, $email, $name, $userId, 'false', $password);

        $this->assertNotSame($token2, $token3);
    }

    #[TestDox('email_confirmed=true vs false produce different token3')]
    public function testEmailConfirmedAffectsToken3(): void
    {
        $nonce = 'test-nonce';
        $email = 'user@example.com';
        $name = 'User';
        $userId = '42';
        $password = 'test-password';

        $withTrue = Protocol::token3($nonce, $email, $name, $userId, 'true', $password);
        $withFalse = Protocol::token3($nonce, $email, $name, $userId, 'false', $password);

        $this->assertNotSame($withTrue, $withFalse);
    }
}
