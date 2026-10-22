<?php

declare(strict_types=1);

namespace Tests\Unit;

use InSales\ShopValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShopValidator::class)]
#[TestDox('Shop domain validation')]
final class ShopValidatorTest extends TestCase
{
    #[TestDox('valid shop domains')]
    #[DataProvider('validShopProvider')]
    public function testValidShop(string $shop, string $expectedHost, ?int $expectedPort): void
    {
        $parsed = ShopValidator::parse($shop);

        $this->assertSame($expectedHost, $parsed['host']);
        $this->assertSame($expectedPort, $parsed['port']);
    }

    /**
     * @return array<string, array{string, string, int|null}>
     */
    public static function validShopProvider(): array
    {
        return [
            'standard domain' => ['demo.myinsales.ru', 'demo.myinsales.ru', null],
            'with port' => ['shop.example.com:8080', 'shop.example.com', 8080],
            'loopback' => ['127.0.0.1', '127.0.0.1', null],
            'loopback with port' => ['127.0.0.1:8765', '127.0.0.1', 8765],
            'localhost' => ['localhost', 'localhost', null],
            'numeric subdomain' => ['shop123.test.ru', 'shop123.test.ru', null],
            'uppercase normalized' => ['SHOP.EXAMPLE.RU', 'shop.example.ru', null],
            'with leading digit' => ['1shop.myinsales.ru', '1shop.myinsales.ru', null],
            'short domain' => ['s.ru', 's.ru', null],
        ];
    }

    #[TestDox('invalid shop domains')]
    #[DataProvider('invalidShopProvider')]
    public function testInvalidShop(string $shop): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShopValidator::parse($shop);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidShopProvider(): array
    {
        return [
            'empty string' => [''],
            'just protocol' => ['https://shop.ru'],
            'path included' => ['shop.ru/path'],
            'with @ symbol' => ['shop@example.ru'],
            'with spaces' => ['shop example.ru'],
            'too large port' => ['shop.ru:99999'],
            'non-numeric port' => ['shop.ru:abc'],
            'starts with dash' => ['-shop.ru'],
        ];
    }

    #[TestDox('loopback detection')]
    #[DataProvider('loopbackProvider')]
    public function testIsLoopback(string $host, bool $expected): void
    {
        $this->assertSame($expected, ShopValidator::isLoopback($host));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function loopbackProvider(): array
    {
        return [
            '127.0.0.1' => ['127.0.0.1', true],
            'localhost' => ['localhost', true],
            '::1' => ['::1', true],
            'external domain' => ['demo.myinsales.ru', false],
            'external ip' => ['192.168.1.1', false],
            '8.8.8.8' => ['8.8.8.8', false],
        ];
    }

    #[TestDox('allowed shops check')]
    public function testIsAllowed(): void
    {
        $allowed = ['demo.myinsales.ru', 'test.myshop.ru'];

        $this->assertTrue(ShopValidator::isAllowed('demo.myinsales.ru', $allowed));
        $this->assertTrue(ShopValidator::isAllowed('test.myshop.ru', $allowed));
        $this->assertFalse(ShopValidator::isAllowed('evil.com', $allowed));
    }

    #[TestDox('validate rejects non-allowed shop')]
    public function testValidateRejectsNonAllowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not in ALLOWED_SHOPS');

        ShopValidator::validate('evil.com', ['demo.myinsales.ru'], false);
    }

    #[TestDox('validate rejects HTTP for non-loopback')]
    public function testValidateRejectsHttpForNonLoopback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Plain HTTP is only allowed');

        ShopValidator::validate('demo.myinsales.ru', ['demo.myinsales.ru'], true);
    }

    #[TestDox('validate allows HTTP for loopback')]
    public function testValidateAllowsHttpForLoopback(): void
    {
        $result = ShopValidator::validate('127.0.0.1', ['127.0.0.1'], true);
        $this->assertSame('127.0.0.1', $result);
    }
}