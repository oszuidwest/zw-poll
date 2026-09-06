<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Activation;
use ZuidWest\Poll\Vote\IpHasher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IpHasherTest extends TestCase
{
    private const SALT = 'test-salt-very-long-secret-do-not-use-in-production';
    private const IP = '192.0.2.1';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_option')->justReturn(self::SALT);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function returns_64_character_hex_hash(): void
    {
        $hash = (new IpHasher())->hash(self::IP);
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function is_keyed_hmac_of_the_ip_with_the_configured_salt(): void
    {
        $hash = (new IpHasher())->hash(self::IP);
        $this->assertSame(hash_hmac('sha256', self::IP, self::SALT), $hash);
    }

    #[Test]
    public function same_ip_produces_same_hash(): void
    {
        $hasher = new IpHasher();
        $this->assertSame($hasher->hash(self::IP), $hasher->hash(self::IP));
    }

    #[Test]
    public function different_ip_produces_different_hash(): void
    {
        $hasher = new IpHasher();
        $this->assertNotSame($hasher->hash(self::IP), $hasher->hash('192.0.2.2'));
    }

    #[Test]
    public function seeds_a_salt_when_missing_instead_of_hashing_unsalted(): void
    {
        // Empty option models an install that bypassed activation.
        Functions\when('get_option')->justReturn('');
        Functions\expect('wp_generate_password')
            ->once()
            ->andReturn('freshly-generated-salt');
        Functions\expect('add_option')
            ->once()
            ->with(Activation::IP_SALT_OPTION, 'freshly-generated-salt', '', false)
            ->andReturn(true);

        $hash = (new IpHasher())->hash(self::IP);

        // Fresh salt is required; raw SHA-256 would be reversible for IPv4.
        $this->assertSame(hash_hmac('sha256', self::IP, 'freshly-generated-salt'), $hash);
        $this->assertNotSame(hash('sha256', self::IP), $hash);
    }
}
