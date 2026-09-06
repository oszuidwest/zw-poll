<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Admin\SettingsPage;
use ZuidWest\Poll\Support\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('selected')->alias(
            static fn (mixed $selected, mixed $current, bool $echo = true): string => $selected === $current
                ? ' selected="selected"'
                : ''
        );
        Functions\when('disabled')->alias(
            static fn (mixed $disabled, mixed $current = true, bool $echo = true): string => $disabled === $current
                ? ' disabled="disabled"'
                : ''
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function option_page_capability_reuses_poll_management_capability(): void
    {
        $this->assertSame('manage_options', (new SettingsPage())->optionPageCapability());
    }

    #[Test]
    public function register_settings_hides_all_sections_from_non_admins(): void
    {
        $sections = [];
        $fields = [];
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('register_setting')->justReturn(true);
        Functions\when('add_settings_section')->alias(
            static function (string $id, mixed ...$args) use (&$sections): void {
                $sections[] = $id;
            }
        );
        Functions\when('add_settings_field')->alias(
            static function (string $id, mixed ...$args) use (&$fields): void {
                $fields[] = $id;
            }
        );

        (new SettingsPage())->registerSettings();

        $this->assertSame([], $sections);
        $this->assertSame([], $fields);
    }

    #[Test]
    public function register_settings_adds_label_for_to_unwrapped_controls_for_admins(): void
    {
        $fields = [];
        Functions\when('current_user_can')->alias(
            static fn (string $capability): bool => $capability === 'manage_options'
        );
        Functions\when('register_setting')->justReturn(true);
        Functions\when('add_settings_section')->justReturn(true);
        Functions\when('add_settings_field')->alias(
            static function (
                string $id,
                string $title,
                callable $callback,
                string $page,
                string $section,
                array $args = []
            ) use (&$fields): void {
                $fields[$id] = $args;
            }
        );

        (new SettingsPage())->registerSettings();

        $this->assertSame(
            ['label_for' => 'zw_poll_rate_limit_max'],
            $fields['zw_poll_rate_limit_max']
        );
        $this->assertSame(
            ['label_for' => 'zw_poll_total_min_votes'],
            $fields['zw_poll_total_min_votes']
        );
        $this->assertSame(
            ['label_for' => 'zw_poll_rate_limit_window'],
            $fields['zw_poll_rate_limit_window']
        );
        $this->assertSame(
            ['label_for' => 'zw_poll_proxy_header'],
            $fields['zw_poll_proxy_header']
        );
    }

    #[Test]
    public function number_fields_render_stable_ids_and_server_bounds(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $option, mixed $default = []): mixed => $option === Settings::OPTION
                ? [
                    'total_min_votes' => 789,
                    'rate_limit_max' => 123,
                    'rate_limit_window' => 456,
                ]
                : $default
        );

        ob_start();
        (new SettingsPage())->renderTotalMinVotesField();
        (new SettingsPage())->renderRateLimitMaxField();
        (new SettingsPage())->renderRateLimitWindowField();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('id="zw_poll_total_min_votes"', $html);
        $this->assertStringContainsString('min="0" max="1000000"', $html);
        $this->assertStringContainsString('value="789"', $html);
        $this->assertStringContainsString('id="zw_poll_rate_limit_max"', $html);
        $this->assertStringContainsString('min="1" max="10000"', $html);
        $this->assertStringContainsString('value="123"', $html);
        $this->assertStringContainsString('id="zw_poll_rate_limit_window"', $html);
        $this->assertStringContainsString('min="1" max="86400"', $html);
        $this->assertStringContainsString('value="456"', $html);
    }

    #[Test]
    public function disabled_proxy_field_preserves_the_stored_value(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $option, mixed $default = []): mixed => $option === Settings::OPTION
                ? ['proxy_header' => Settings::PROXY_HEADER_X_REAL_IP]
                : $default
        );
        Functions\when('has_filter')->alias(
            static fn (string $hook): int|false => $hook === 'zw_poll_client_ip' ? 0 : false
        );

        ob_start();
        (new SettingsPage())->renderProxyHeaderField();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(
            'type="hidden" name="zw_poll_settings[proxy_header]" value="x-real-ip"',
            $html
        );
        $this->assertStringContainsString(
            '<select id="zw_poll_proxy_header" name="zw_poll_settings[proxy_header]" disabled="disabled">',
            $html
        );
        $this->assertStringContainsString(
            'Deze instelling wordt in code bepaald door het zw_poll_client_ip-filter.',
            $html
        );
    }
}
