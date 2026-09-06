<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Admin\PollMetaBoxes;
use ZuidWest\Poll\PostType\PollPostType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Covers the Status meta box: the badge and the toggle button that flips
 * the poll status through the core post-meta REST route.
 */
final class PollMetaBoxesTest extends TestCase
{
    private const POLL_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('_n')->alias(static fn (string $single, string $plural, int $n): string => $n === 1 ? $single : $plural);
        Functions\when('number_format_i18n')->alias(static fn (int $n): string => (string) $n);
        Functions\when('wp_create_nonce')->justReturn('nonce123');
        Functions\when('rest_get_route_for_post')->justReturn('/wp/v2/zw-polls/' . self::POLL_ID);
        Functions\when('rest_url')->alias(static fn (string $path): string => 'http://example.test/wp-json' . $path);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function poll(string $status = 'publish'): WP_Post
    {
        $poll = new WP_Post();
        $poll->ID = self::POLL_ID;
        $poll->post_type = PollPostType::POST_TYPE;
        $poll->post_status = $status;

        return $poll;
    }

    /**
     * Render the status box with controlled meta.
     *
     * @param string                                       $status  Poll status.
     * @param array<int, array{id: string, label: string}> $options Poll options.
     */
    private function renderStatus(string $status, array $options = [['id' => 'option-1', 'label' => 'Ja']]): string
    {
        Functions\when('get_post_meta')->alias(
            static function (int $post_id, string $key) use ($status, $options): mixed {
                return match ($key) {
                    PollPostType::META_STATUS => $status,
                    PollPostType::META_OPTIONS => $options,
                    default => null,
                };
            }
        );

        ob_start();
        (new PollMetaBoxes())->renderStatus($this->poll());

        return (string) ob_get_clean();
    }

    #[Test]
    public function registers_a_status_meta_box(): void
    {
        $registered = [];
        Functions\when('add_meta_box')->alias(
            static function (string $id) use (&$registered): void {
                $registered[] = $id;
            }
        );
        Functions\when('remove_meta_box')->justReturn(null);

        (new PollMetaBoxes())->configureMetaBoxes($this->poll());

        $this->assertContains('zw-poll-status', $registered);
    }

    #[Test]
    public function shortcode_box_registers_for_published_polls_only(): void
    {
        $registered = [];
        Functions\when('add_meta_box')->alias(
            static function (string $id) use (&$registered): void {
                $registered[] = $id;
            }
        );
        Functions\when('remove_meta_box')->justReturn(null);

        $boxes = new PollMetaBoxes();
        $boxes->configureMetaBoxes($this->poll());
        $this->assertContains('zw-poll-shortcode', $registered);

        $registered = [];
        $boxes->configureMetaBoxes($this->poll('draft'));
        $this->assertNotContains('zw-poll-shortcode', $registered);
    }

    #[Test]
    public function shortcode_box_offers_the_copyable_shortcode(): void
    {
        Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
        });

        ob_start();
        (new PollMetaBoxes())->renderShortcode($this->poll());
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('value="[zw_poll id="' . self::POLL_ID . '"]"', $html);
        $this->assertStringContainsString('zw-poll-copy-shortcode', $html);
    }

    #[Test]
    public function removes_the_raw_custom_fields_meta_box_from_poll_editor(): void
    {
        $removed = [];
        Functions\when('add_meta_box')->justReturn(null);
        Functions\when('remove_meta_box')->alias(
            static function (string $id, string $screen, string $context) use (&$removed): void {
                $removed[] = [$id, $screen, $context];
            }
        );

        (new PollMetaBoxes())->configureMetaBoxes($this->poll());

        $this->assertSame([['postcustom', PollPostType::POST_TYPE, 'normal']], $removed);
    }

    #[Test]
    public function open_poll_offers_a_close_button_targeting_closed(): void
    {
        $html = $this->renderStatus('open');

        $this->assertStringContainsString('zw-poll-status-badge--open', $html);
        $this->assertStringContainsString('Poll sluiten', $html);
        $this->assertStringContainsString('data-target-status="closed"', $html);
    }

    #[Test]
    public function closed_poll_offers_a_reopen_button_targeting_open(): void
    {
        $html = $this->renderStatus('closed');

        $this->assertStringContainsString('zw-poll-status-badge--closed', $html);
        $this->assertStringContainsString('Poll heropenen', $html);
        $this->assertStringContainsString('data-target-status="open"', $html);
    }

    #[Test]
    public function button_posts_to_the_core_meta_route_with_the_status_key(): void
    {
        $html = $this->renderStatus('open');

        $this->assertStringContainsString(
            'data-rest-url="http://example.test/wp-json/wp/v2/zw-polls/' . self::POLL_ID . '"',
            $html
        );
        $this->assertStringContainsString('data-meta-key="' . PollPostType::META_STATUS . '"', $html);
        $this->assertStringContainsString('data-rest-nonce="nonce123"', $html);
    }

    #[Test]
    public function empty_status_meta_defaults_to_open(): void
    {
        $html = $this->renderStatus('');

        $this->assertStringContainsString('zw-poll-status-badge--open', $html);
        $this->assertStringContainsString('Poll sluiten', $html);
    }

    #[Test]
    public function status_box_is_hidden_until_the_poll_has_options(): void
    {
        $html = $this->renderStatus('open', []);

        $this->assertStringContainsString('zw-poll-admin-empty', $html);
        $this->assertStringNotContainsString('zw-poll-status-toggle', $html);
        $this->assertStringNotContainsString('Open voor stemmen', $html);
    }

    #[Test]
    public function results_box_projects_totals_to_visible_options(): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $post_id, string $key): mixed => match ($key) {
                PollPostType::META_OPTIONS => [
                    ['id' => 'option-1', 'label' => 'Ja'],
                    ['id' => 'option-2', 'label' => 'Nee'],
                ],
                PollPostType::META_AGGREGATE => [
                    'counts' => ['option-1' => 2, 'removed' => 99],
                    'total' => 101,
                ],
                default => '',
            }
        );

        ob_start();
        (new PollMetaBoxes())->renderResults($this->poll());
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Totaal aantal stemmen: 2', $html);
        $this->assertStringNotContainsString('Totaal aantal stemmen: 101', $html);
        $this->assertStringContainsString('100% (2)', $html);
    }
}
