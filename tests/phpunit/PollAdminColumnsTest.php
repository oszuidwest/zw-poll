<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Admin\PollAdminColumns;
use ZuidWest\Poll\PostType\PollPostType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PollAdminColumnsTest extends TestCase
{
    private const POLL_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->returnArg();
        Functions\when('number_format_i18n')->alias(static fn (int $n): string => (string) $n);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function votes_column_projects_total_to_visible_options(): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $post_id, string $key): mixed => match ($key) {
                PollPostType::META_OPTIONS => [['id' => 'a', 'label' => 'A']],
                PollPostType::META_AGGREGATE => ['counts' => ['a' => 2, 'removed' => 99], 'total' => 101],
                default => '',
            }
        );

        ob_start();
        (new PollAdminColumns())->renderColumn('votes', self::POLL_ID);
        $html = (string) ob_get_clean();

        $this->assertSame('2', $html);
    }

    #[Test]
    public function column_list_labels_the_title_as_the_question(): void
    {
        Functions\when('__')->returnArg();

        $columns = (new PollAdminColumns())->columns(['cb' => 'cb', 'title' => 'Titel', 'date' => 'Datum']);

        $this->assertSame(
            ['cb', 'title', 'poll_status', 'votes', 'usage', 'shortcode', 'date'],
            array_keys($columns)
        );
        // The poll title is the reader-facing question.
        $this->assertSame('Vraag', $columns['title']);
    }

    #[Test]
    public function usage_column_links_a_single_article_to_that_article(): void
    {
        Functions\when('esc_url')->returnArg();
        Functions\when('_n')->alias(static fn (string $single, string $plural, int $n): string => $n === 1 ? $single : $plural);
        Functions\when('get_post_meta')->justReturn([7]);
        Functions\expect('get_edit_post_link')
            ->once()
            ->with(7)
            ->andReturn('https://example.test/wp-admin/post.php?post=7&action=edit');

        ob_start();
        (new PollAdminColumns())->renderColumn('usage', self::POLL_ID);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('post=7', $html);
        $this->assertStringContainsString('1 artikel', $html);
    }

    #[Test]
    public function usage_column_links_multiple_articles_to_the_poll_usage_meta_box(): void
    {
        Functions\when('esc_url')->returnArg();
        Functions\when('_n')->alias(static fn (string $single, string $plural, int $n): string => $n === 1 ? $single : $plural);
        Functions\when('get_post_meta')->justReturn([7, 9]);
        Functions\expect('get_edit_post_link')
            ->once()
            ->with(self::POLL_ID)
            ->andReturn('https://example.test/wp-admin/post.php?post=42&action=edit');

        ob_start();
        (new PollAdminColumns())->renderColumn('usage', self::POLL_ID);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('post=42', $html);
        $this->assertStringContainsString('#zw-poll-usage', $html);
        $this->assertStringContainsString('2 artikelen', $html);
    }

    #[Test]
    public function shortcode_column_shows_copyable_shortcode_for_published_polls(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('get_post_status')->justReturn('publish');

        ob_start();
        (new PollAdminColumns())->renderColumn('shortcode', self::POLL_ID);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<code>[zw_poll id="42"]</code>', $html);
        $this->assertStringContainsString('zw-poll-copy-shortcode', $html);
    }

    #[Test]
    public function shortcode_column_stays_empty_for_unpublished_polls(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('get_post_status')->justReturn('draft');

        ob_start();
        (new PollAdminColumns())->renderColumn('shortcode', self::POLL_ID);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('zw-poll-usage--empty', $html);
        $this->assertStringNotContainsString('zw_poll id', $html);
    }
}
