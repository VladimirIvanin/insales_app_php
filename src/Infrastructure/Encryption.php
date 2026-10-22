<?php

declare(strict_types=1);

namespace Infrastructure;

/**
 * Encrypt/decrypt store passwords using libsodium (XChaCha20-Poly1305).
 *
 * Key must be 32 random bytes, provided as hex-encoded string via environment.
 * Sodium uses a nonce + key for authenticated encryption.
 * Each encryption generates a fresh random nonce prepended to the ciphertext.
 * Output is hex-encoded for storage: nonce_hex . ciphertext_hex.
 */
final readonly class Encryption
{
    private const NONCE_BYTES = \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private string $key;

    /**
     * @param string $keyHex 64-char hex string (32 bytes)
     */
    public function __construct(string $keyHex)
    {
        $key = @hex2bin($keyHex);
        if ($key === false || \strlen($key) !== \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException(
                'Encryption key must be ' . \SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes hex-encoded'
            );
        }
        $this->key = $key;
    }

    /**
     * Encrypt plaintext.
     *
     * @return string Hex-encoded nonce+ciphertext
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return bin2hex($nonce) . bin2hex($ciphertext);
    }

    /**
     * Decrypt hex-encoded nonce+ciphertext.
     *
     * @return string Decrypted plaintext
     * @throws \RuntimeException
     */
    public function decrypt(string $encoded): string
    {
        $nonceHexLen = self::NONCE_BYTES * 2;

        if (\strlen($encoded) < $nonceHexLen + 1) {
            throw new \RuntimeException('Invalid encrypted data');
        }

        $nonce = hex2bin(substr($encoded, 0, $nonceHexLen));
        $ciphertext = hex2bin(substr($encoded, $nonceHexLen));

        if ($nonce === false || $ciphertext === false) {
            throw new \RuntimeException('Invalid hex-encoded encrypted data');
        }

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: invalid key or tampered data');
        }

        return $plaintext;
    }
}