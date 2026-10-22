<?php

declare(strict_types=1);

namespace Tests\Unit;

use Infrastructure\Encryption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Encryption::class)]
#[TestDox('Encryption (XChaCha20-Poly1305 via sodium)')]
final class EncryptionTest extends TestCase
{
    private Encryption $encryption;

    protected function setUp(): void
    {
        $keyHex = bin2hex(
            str_repeat("\x00", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        );
        $this->encryption = new Encryption($keyHex);
    }

    #[TestDox('round-trip encrypt/decrypt')]
    public function testRoundTrip(): void
    {
        $original = 'store-password-123';
        $encrypted = $this->encryption->encrypt($original);

        $this->assertNotSame($original, $encrypted);
        $this->assertStringStartsNotWith($original, $encrypted);

        $decrypted = $this->encryption->decrypt($encrypted);
        $this->assertSame($original, $decrypted);
    }

    #[TestDox('different encryptions produce different ciphertexts')]
    public function testNonDeterministic(): void
    {
        $password = 'test-password';
        $e1 = $this->encryption->encrypt($password);
        $e2 = $this->encryption->encrypt($password);

        // Due to random nonce, outputs should differ
        $this->assertNotSame($e1, $e2);

        // Both should decrypt to the same value
        $this->assertSame($password, $this->encryption->decrypt($e1));
        $this->assertSame($password, $this->encryption->decrypt($e2));
    }

    #[TestDox('throws on invalid key length')]
    public function testInvalidKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Encryption('aabb'); // too short
    }

    #[TestDox('throws on invalid hex key')]
    public function testInvalidHexKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Encryption('zzzz' . str_repeat('00', 30));
    }

    #[TestDox('throws on tampered ciphertext')]
    public function testTamperedCiphertext(): void
    {
        $encrypted = $this->encryption->encrypt('secret');
        // Corrupt the nonce
        $corrupted = 'ff' . substr($encrypted, 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');
        $this->encryption->decrypt($corrupted);
    }

    #[TestDox('throws on empty encrypted data')]
    public function testEmptyEncrypted(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt('');
    }

    #[TestDox('handles Cyrillic passwords')]
    public function testCyrillicPassword(): void
    {
        $password = 'пароль_магазина_123!@#';
        $encrypted = $this->encryption->encrypt($password);
        $decrypted = $this->encryption->decrypt($encrypted);

        $this->assertSame($password, $decrypted);
    }
}
