<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Support\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function defaults_match_existing_runtime_behaviour_except_uninstall_cleanup_opt_in(): void
    {
        $this->assertSame([
            'total_min_votes' => Settings::TOTAL_MIN_VOTES_DEFAULT,
            'rate_limit_enabled' => true,
            'rate_limit_max' => Settings::RATE_LIMIT_MAX_DEFAULT,
            'rate_limit_window' => Settings::RATE_LIMIT_WINDOW_DEFAULT,
            'proxy_header' => Settings::PROXY_HEADER_NONE,
            'delete_data_on_uninstall' => false,
        ], Settings::defaults());
    }

    #[Test]
    public function sanitize_clamps_limits_and_coerces_checkboxes(): void
    {
        $settings = Settings::sanitize([
            'total_min_votes' => '1000001',
            'rate_limit_enabled' => '1',
            'rate_limit_max' => '20000',
            'rate_limit_window' => '999999',
            'proxy_header' => Settings::PROXY_HEADER_X_REAL_IP,
            'delete_data_on_uninstall' => 'true',
        ]);

        $this->assertTrue($settings['rate_limit_enabled']);
        $this->assertSame(Settings::TOTAL_MIN_VOTES_MAX, $settings['total_min_votes']);
        $this->assertSame(10000, $settings['rate_limit_max']);
        $this->assertSame(DAY_IN_SECONDS, $settings['rate_limit_window']);
        $this->assertSame(Settings::PROXY_HEADER_X_REAL_IP, $settings['proxy_header']);
        $this->assertTrue($settings['delete_data_on_uninstall']);
    }

    #[Test]
    public function sanitize_clamps_invalid_low_limits_to_minimum_and_unknown_proxy_header_to_none(): void
    {
        $settings = Settings::sanitize([
            'total_min_votes' => '-1',
            'rate_limit_max' => '0',
            'rate_limit_window' => 'not-a-number',
            'proxy_header' => 'HTTP_X_FORWARDED_FOR',
        ]);

        $this->assertFalse($settings['rate_limit_enabled']);
        $this->assertSame(0, $settings['total_min_votes']);
        $this->assertSame(Settings::RATE_LIMIT_MAX_MIN, $settings['rate_limit_max']);
        $this->assertSame(Settings::RATE_LIMIT_WINDOW_MIN, $settings['rate_limit_window']);
        $this->assertSame(Settings::PROXY_HEADER_NONE, $settings['proxy_header']);
        $this->assertFalse($settings['delete_data_on_uninstall']);
    }

    #[Test]
    public function total_vote_threshold_accepts_zero_and_both_boundaries(): void
    {
        $this->assertSame(0, Settings::sanitize(['total_min_votes' => '0'])['total_min_votes']);
        $this->assertSame(1000000, Settings::sanitize(['total_min_votes' => '1000000'])['total_min_votes']);
        $this->assertSame(0, Settings::sanitize(['total_min_votes' => 'invalid'])['total_min_votes']);
    }

    #[Test]
    public function non_array_sanitize_input_falls_back_to_defaults(): void
    {
        $this->assertSame(Settings::defaults(), Settings::sanitize('invalid'));
    }

    #[Test]
    public function get_merges_partial_stored_options_over_defaults(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $option, mixed $default = []): mixed => $option === Settings::OPTION
                ? [
                    'rate_limit_max' => 12,
                    'proxy_header' => Settings::PROXY_HEADER_X_FORWARDED_FOR,
                ]
                : $default
        );

        $settings = Settings::get();

        $this->assertTrue($settings['rate_limit_enabled']);
        $this->assertSame(12, $settings['rate_limit_max']);
        $this->assertSame(Settings::PROXY_HEADER_X_FORWARDED_FOR, $settings['proxy_header']);
        $this->assertFalse($settings['delete_data_on_uninstall']);
    }

    #[Test]
    public function get_uses_defaults_when_stored_option_is_garbage(): void
    {
        Functions\when('get_option')->justReturn('not-an-array');

        $this->assertSame(Settings::defaults(), Settings::get());
    }

    #[Test]
    public function get_reads_programmatic_updates_in_the_same_request(): void
    {
        $stored = ['rate_limit_max' => 5];
        Functions\when('get_option')->alias(
            static function (string $option, mixed $default = []) use (&$stored): mixed {
                return $option === Settings::OPTION ? $stored : $default;
            }
        );

        $this->assertSame(5, Settings::get()['rate_limit_max']);

        $stored = ['rate_limit_max' => 9];
        $this->assertSame(9, Settings::get()['rate_limit_max']);
    }

    #[Test]
    public function sanitize_for_save_reports_coerced_admin_values(): void
    {
        Functions\when('current_user_can')->alias(
            static fn (string $capability): bool => $capability === 'manage_options'
        );
        Functions\expect('add_settings_error')->times(3);

        $settings = Settings::sanitizeForSave([
            'rate_limit_max' => '0',
            'rate_limit_window' => '999999999',
            'proxy_header' => 'HTTP_X_FORWARDED_FOR',
        ]);

        $this->assertSame(Settings::RATE_LIMIT_MAX_MIN, $settings['rate_limit_max']);
        $this->assertSame(Settings::RATE_LIMIT_WINDOW_MAX, $settings['rate_limit_window']);
        $this->assertSame(Settings::PROXY_HEADER_NONE, $settings['proxy_header']);
    }

    #[Test]
    public function sanitize_for_save_preserves_admin_only_values_for_poll_managers(): void
    {
        Functions\when('current_user_can')->alias(
            static fn (string $capability): bool => $capability !== 'manage_options'
        );
        Functions\when('get_option')->alias(
            static fn (string $option, mixed $default = []): mixed => $option === Settings::OPTION
                ? [
                    'total_min_votes' => 345,
                    'rate_limit_enabled' => true,
                    'rate_limit_max' => 7,
                    'rate_limit_window' => 120,
                    'proxy_header' => Settings::PROXY_HEADER_X_REAL_IP,
                    'delete_data_on_uninstall' => true,
                ]
                : $default
        );
        Functions\expect('add_settings_error')->never();

        $settings = Settings::sanitizeForSave([
            'total_min_votes' => '999',
            'rate_limit_enabled' => '0',
            'rate_limit_max' => '9999',
            'rate_limit_window' => '9999',
            'proxy_header' => Settings::PROXY_HEADER_X_FORWARDED_FOR,
            'delete_data_on_uninstall' => '0',
        ]);

        $this->assertTrue($settings['rate_limit_enabled']);
        $this->assertSame(345, $settings['total_min_votes']);
        $this->assertSame(7, $settings['rate_limit_max']);
        $this->assertSame(120, $settings['rate_limit_window']);
        $this->assertSame(Settings::PROXY_HEADER_X_REAL_IP, $settings['proxy_header']);
        $this->assertTrue($settings['delete_data_on_uninstall']);
    }
}
