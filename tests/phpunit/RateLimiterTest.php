<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Support\Settings;
use ZuidWest\Poll\Vote\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private const HASH = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $value
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function allows_when_counter_below_max(): void
    {
        Functions\when('get_transient')->justReturn(5);
        Functions\when('set_transient')->justReturn(true);

        $this->assertTrue((new RateLimiter())->allow(self::HASH, 42));
    }

    #[Test]
    public function disabled_limiter_allows_without_touching_transients(): void
    {
        Functions\expect('get_transient')->never();
        Functions\expect('set_transient')->never();

        $this->assertTrue((new RateLimiter(enabled: false))->allow(self::HASH, 42));
    }

    #[Test]
    public function constructor_values_define_the_default_bucket(): void
    {
        Functions\when('get_transient')->justReturn(4);
        $sets = [];
        Functions\when('set_transient')->alias(
            static function (string $key, int $value, int $expiration) use (&$sets): bool {
                $sets[$key] = [$value, $expiration];
                return true;
            }
        );

        $this->assertTrue((new RateLimiter(max_per_window: 5, window_seconds: 120))->allow(self::HASH, 42));
        $this->assertSame([5, 120], $sets[self::globalKey()]);
        $this->assertSame([5, 120], $sets[self::pollKey(42)]);
    }

    #[Test]
    public function constructor_clamps_non_positive_values_to_one(): void
    {
        Functions\when('get_transient')->justReturn(0);
        $sets = [];
        Functions\when('set_transient')->alias(
            static function (string $key, int $value, int $expiration) use (&$sets): bool {
                $sets[$key] = [$value, $expiration];
                return true;
            }
        );

        $this->assertTrue((new RateLimiter(max_per_window: 0, window_seconds: -5))->allow(self::HASH, 42));
        $this->assertSame([1, 1], $sets[self::globalKey()]);
        $this->assertSame([1, 1], $sets[self::pollKey(42)]);
    }

    #[Test]
    public function blocks_when_counter_at_max(): void
    {
        Functions\when('get_transient')->justReturn(Settings::RATE_LIMIT_MAX_DEFAULT);
        Functions\expect('set_transient')->never();

        $this->assertFalse((new RateLimiter())->allow(self::HASH, 42));
    }

    #[Test]
    public function blocks_when_counter_above_max(): void
    {
        Functions\when('get_transient')->justReturn(Settings::RATE_LIMIT_MAX_DEFAULT + 10);
        Functions\expect('set_transient')->never();

        $this->assertFalse((new RateLimiter())->allow(self::HASH, 42));
    }

    #[Test]
    public function increments_counter_and_resets_window(): void
    {
        Functions\when('get_transient')->justReturn(7);
        $sets = [];
        Functions\when('set_transient')->alias(
            static function (string $key, int $value, int $expiration) use (&$sets): bool {
                $sets[$key] = [$value, $expiration];
                return true;
            }
        );

        $this->assertTrue((new RateLimiter())->allow(self::HASH, 42));
        $this->assertSame([8, Settings::RATE_LIMIT_WINDOW_DEFAULT], $sets[self::globalKey()]);
        $this->assertSame([8, Settings::RATE_LIMIT_WINDOW_DEFAULT], $sets[self::pollKey(42)]);
    }

    #[Test]
    public function treats_missing_counter_as_zero(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('set_transient')
            ->twice()
            ->withArgs(static function (string $key, int $value, int $exp): bool {
                return $value === 1 && $exp === Settings::RATE_LIMIT_WINDOW_DEFAULT;
            })
            ->andReturn(true);

        $this->assertTrue((new RateLimiter())->allow(self::HASH, 42));
    }

    #[Test]
    public function rate_limit_can_be_tightened_per_poll_with_filter(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, string $ip_hash, int $poll_id): mixed => $hook === 'zw_poll_rate_limit'
                ? ['max' => 3, 'window' => 120]
                : $value
        );

        $globalKey = self::globalKey();
        $pollKey = self::pollKey(42);
        $sets = [];

        Functions\when('get_transient')->alias(
            static fn (string $key): int => $key === $pollKey ? 2 : 0
        );
        Functions\when('set_transient')->alias(
            static function (string $key, int $value, int $expiration) use (&$sets): bool {
                $sets[$key] = [$value, $expiration];
                return true;
            }
        );

        $this->assertTrue((new RateLimiter())->allow(self::HASH, 42));
        $this->assertSame([1, Settings::RATE_LIMIT_WINDOW_DEFAULT], $sets[$globalKey]);
        $this->assertSame([3, 120], $sets[$pollKey]);
    }

    #[Test]
    public function filtered_poll_limit_blocks_without_incrementing_any_bucket(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, string $ip_hash, int $poll_id): mixed => $hook === 'zw_poll_rate_limit'
                ? ['max' => 3, 'window' => 120]
                : $value
        );

        $pollKey = self::pollKey(42);
        Functions\when('get_transient')->alias(
            static fn (string $key): int => $key === $pollKey ? 3 : 0
        );
        Functions\expect('set_transient')->never();

        $this->assertFalse((new RateLimiter())->allow(self::HASH, 42));
    }

    #[Test]
    public function global_ip_limit_still_blocks_poll_votes(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->with(self::globalKey())
            ->andReturn(Settings::RATE_LIMIT_MAX_DEFAULT);
        Functions\expect('set_transient')->never();

        $this->assertFalse((new RateLimiter())->allow(self::HASH, 42));
    }

    #[Test]
    public function per_poll_buckets_are_isolated_by_poll_id(): void
    {
        $setKeys = [];
        Functions\when('get_transient')->justReturn(0);
        Functions\when('set_transient')->alias(
            static function (string $key, int $value, int $expiration) use (&$setKeys): bool {
                $setKeys[] = $key;
                return true;
            }
        );

        $limiter = new RateLimiter();
        $this->assertTrue($limiter->allow(self::HASH, 42));
        $this->assertTrue($limiter->allow(self::HASH, 43));

        $this->assertContains(self::pollKey(42), $setKeys);
        $this->assertContains(self::pollKey(43), $setKeys);
        $this->assertNotSame(self::pollKey(42), self::pollKey(43));
    }

    #[Test]
    public function non_array_filter_output_uses_defaults_and_reports_it(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, string $ip_hash, int $poll_id): mixed => $hook === 'zw_poll_rate_limit'
                ? 'invalid'
                : $value
        );
        Functions\expect('_doing_it_wrong')->once();

        $pollKey = self::pollKey(42);
        $sets = [];
        Functions\when('get_transient')->alias(
            static fn (string $key): int => $key === $pollKey ? 5 : 0
        );
        Functions\when('set_transient')->alias(
            static function (string $key, int $value, int $expiration) use (&$sets): bool {
                $sets[$key] = [$value, $expiration];
                return true;
            }
        );

        $this->assertTrue((new RateLimiter())->allow(self::HASH, 42));
        $this->assertSame([6, Settings::RATE_LIMIT_WINDOW_DEFAULT], $sets[$pollKey]);
    }

    #[Test]
    public function non_positive_limit_values_use_defaults_and_report_it(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, string $ip_hash, int $poll_id): mixed => $hook === 'zw_poll_rate_limit'
                ? ['max' => 0, 'window' => 0]
                : $value
        );
        Functions\expect('_doing_it_wrong')->twice();

        $pollKey = self::pollKey(42);
        $sets = [];
        Functions\when('get_transient')->alias(
            static fn (string $key): int => $key === $pollKey ? Settings::RATE_LIMIT_MAX_DEFAULT - 1 : 0
        );
        Functions\when('set_transient')->alias(
            static function (string $key, int $value, int $expiration) use (&$sets): bool {
                $sets[$key] = [$value, $expiration];
                return true;
            }
        );

        $this->assertTrue((new RateLimiter())->allow(self::HASH, 42));
        $this->assertSame([Settings::RATE_LIMIT_MAX_DEFAULT, Settings::RATE_LIMIT_WINDOW_DEFAULT], $sets[$pollKey]);
    }

    #[Test]
    public function fractional_limit_values_use_defaults_and_report_it(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, string $ip_hash, int $poll_id): mixed => $hook === 'zw_poll_rate_limit'
                ? ['max' => 2.9, 'window' => '2.9']
                : $value
        );
        Functions\expect('_doing_it_wrong')->twice();

        $pollKey = self::pollKey(42);
        $sets = [];
        Functions\when('get_transient')->alias(
            static fn (string $key): int => $key === $pollKey ? 2 : 0
        );
        Functions\when('set_transient')->alias(
            static function (string $key, int $value, int $expiration) use (&$sets): bool {
                $sets[$key] = [$value, $expiration];
                return true;
            }
        );

        $this->assertTrue((new RateLimiter())->allow(self::HASH, 42));
        $this->assertSame([3, Settings::RATE_LIMIT_WINDOW_DEFAULT], $sets[$pollKey]);
    }

    #[Test]
    public function junk_limit_values_use_defaults_and_report_it(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, string $ip_hash, int $poll_id): mixed => $hook === 'zw_poll_rate_limit'
                ? ['max' => 'junk', 'window' => []]
                : $value
        );
        Functions\expect('_doing_it_wrong')->twice();

        $pollKey = self::pollKey(42);
        $sets = [];
        Functions\when('get_transient')->alias(
            static fn (string $key): int => $key === $pollKey ? 4 : 0
        );
        Functions\when('set_transient')->alias(
            static function (string $key, int $value, int $expiration) use (&$sets): bool {
                $sets[$key] = [$value, $expiration];
                return true;
            }
        );

        $this->assertTrue((new RateLimiter())->allow(self::HASH, 42));
        $this->assertSame([5, Settings::RATE_LIMIT_WINDOW_DEFAULT], $sets[$pollKey]);
    }

    private static function globalKey(): string
    {
        return 'zwpoll_rl_' . substr(self::HASH, 0, 32);
    }

    private static function pollKey(int $pollId): string
    {
        return self::globalKey() . '_p_' . $pollId;
    }
}
