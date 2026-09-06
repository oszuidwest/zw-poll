<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Post;
use ZuidWest\Poll\Admin\UsageTracker;
use ZuidWest\Poll\PostType\PollPostType;

final class UsageTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function registers_delete_cleanup_before_wordpress_removes_postmeta(): void
    {
        $tracker = new UsageTracker();
        $tracker->register();

        $this->assertNotFalse(has_action('save_post', [$tracker, 'trackOnSave']));
        $this->assertNotFalse(has_action('before_delete_post', [$tracker, 'untrackOnDelete']));
        $this->assertFalse(has_action('delete_post', [$tracker, 'untrackOnDelete']));
    }

    #[Test]
    public function returns_empty_array_for_content_without_shortcodes(): void
    {
        $this->assertSame([], UsageTracker::extractPollIds('plain text'));
    }

    #[Test]
    public function extracts_single_poll_id(): void
    {
        $this->assertSame([12], UsageTracker::extractPollIds('Intro. [zw_poll id="12"] Outro.'));
    }

    #[Test]
    public function extracts_multiple_poll_ids_and_dedupes(): void
    {
        $this->assertSame(
            [5, 9],
            UsageTracker::extractPollIds('[zw_poll id="5"] tekst [zw_poll id="9"] [zw_poll id="5"]')
        );
    }

    #[Test]
    public function ignores_escaped_shortcodes(): void
    {
        $this->assertSame([], UsageTracker::extractPollIds('Documentatie: [[zw_poll id="5"]]'));
    }

    #[Test]
    public function ignores_shortcodes_without_a_valid_id(): void
    {
        $this->assertSame(
            [],
            UsageTracker::extractPollIds('[zw_poll] [zw_poll id="0"] [zw_poll id="abc"]')
        );
    }

    #[Test]
    public function accepts_single_quoted_and_unquoted_shortcode_ids(): void
    {
        $this->assertSame([4, 6], UsageTracker::extractPollIds("[zw_poll id='4'] [zw_poll id=6]"));
    }

    #[Test]
    public function ignores_other_shortcode_tags(): void
    {
        $this->assertSame(
            [],
            UsageTracker::extractPollIds('[gallery id="3"] [zw_poll_extra id="8"] [other_poll id="9"]')
        );
    }

    // Save/delete hooks keep the bidirectional usage index fresh.

    #[Test]
    public function track_on_save_appends_usage_and_stores_references(): void
    {
        $this->stubSave();
        Functions\when('get_post_meta')->justReturn('');
        $writes = $this->captureUpdates();

        (new UsageTracker())->trackOnSave(100, $this->post(100, 'post', 'publish', '[zw_poll id="7"]'));

        $this->assertContains([7, UsageTracker::USAGE_META, [100]], $writes);
        $this->assertContains([100, UsageTracker::REFERENCED_META, [7]], $writes);
    }

    #[Test]
    public function track_on_save_does_not_reappend_on_resave_with_same_references(): void
    {
        $this->stubSave();
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): mixed =>
                $id === 100 && $key === UsageTracker::REFERENCED_META ? [7] : ''
        );
        $writes = $this->captureUpdates();

        (new UsageTracker())->trackOnSave(100, $this->post(100, 'post', 'publish', '[zw_poll id="7"]'));

        $this->assertContains([100, UsageTracker::REFERENCED_META, [7]], $writes);
        foreach ($writes as [, $key]) {
            $this->assertNotSame(UsageTracker::USAGE_META, $key);
        }
    }

    #[Test]
    public function track_on_save_removes_usage_when_the_shortcode_disappears(): void
    {
        $this->stubSave();
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): mixed => match (true) {
                $id === 100 && $key === UsageTracker::REFERENCED_META => [7],
                $id === 7 && $key === UsageTracker::USAGE_META => [100, 200],
                default => '',
            }
        );
        $deleted = new \ArrayObject();
        Functions\when('delete_post_meta')->alias(
            static function (int $id, string $key) use ($deleted): bool {
                $deleted[] = [$id, $key];
                return true;
            }
        );
        $writes = $this->captureUpdates();

        (new UsageTracker())->trackOnSave(100, $this->post(100, 'post', 'publish', 'geen poll meer'));

        $this->assertContains([7, UsageTracker::USAGE_META, [200]], $writes);
        $this->assertContains([100, UsageTracker::REFERENCED_META], $deleted->getArrayCopy());
    }

    #[Test]
    public function track_on_save_untracks_a_trashed_post_from_its_polls(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): mixed => match (true) {
                $id === 100 && $key === UsageTracker::REFERENCED_META => [7],
                $id === 7 && $key === UsageTracker::USAGE_META => [100, 200],
                default => '',
            }
        );
        Functions\when('delete_post_meta')->justReturn(true);
        $writes = $this->captureUpdates();

        (new UsageTracker())->trackOnSave(100, $this->post(100, 'post', 'trash', '[zw_poll id="7"]'));

        $this->assertContains([7, UsageTracker::USAGE_META, [200]], $writes);
    }

    #[Test]
    public function track_on_save_ignores_poll_posts_themselves(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\expect('update_post_meta')->never();
        Functions\expect('delete_post_meta')->never();

        (new UsageTracker())->trackOnSave(
            5,
            $this->post(5, PollPostType::POST_TYPE, 'publish', '[zw_poll id="5"]')
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function track_on_save_ignores_references_to_non_poll_posts(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_post_type')->justReturn('page');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\expect('update_post_meta')->never();

        (new UsageTracker())->trackOnSave(100, $this->post(100, 'post', 'publish', '[zw_poll id="7"]'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function untrack_on_delete_removes_post_from_all_referenced_polls(): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): mixed => match (true) {
                $id === 100 && $key === UsageTracker::REFERENCED_META => [7, 8],
                $id === 7 && $key === UsageTracker::USAGE_META => [100],
                $id === 8 && $key === UsageTracker::USAGE_META => [100, 300],
                default => '',
            }
        );
        Functions\when('delete_post_meta')->justReturn(true);
        $writes = $this->captureUpdates();

        (new UsageTracker())->untrackOnDelete(100, $this->post(100, 'post', 'publish', ''));

        $this->assertContains([7, UsageTracker::USAGE_META, []], $writes);
        $this->assertContains([8, UsageTracker::USAGE_META, [300]], $writes);
    }

    #[Test]
    public function untrack_on_delete_ignores_poll_posts(): void
    {
        Functions\expect('update_post_meta')->never();
        Functions\expect('delete_post_meta')->never();

        (new UsageTracker())->untrackOnDelete(
            5,
            $this->post(5, PollPostType::POST_TYPE, 'publish', '')
        );

        $this->addToAssertionCount(1);
    }

    /** Stub a non-trash save whose referenced IDs all resolve to polls. */
    private function stubSave(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_post_type')->justReturn(PollPostType::POST_TYPE);
        Functions\when('delete_post_meta')->justReturn(true);
    }

    /**
     * Record update_post_meta() calls into shared mutable storage.
     *
     * ArrayObject lets the closure and caller observe the same writes.
     *
     * @return \ArrayObject<int, array{0: int, 1: string, 2: mixed}>
     */
    private function captureUpdates(): \ArrayObject
    {
        $writes = new \ArrayObject();
        Functions\when('update_post_meta')->alias(
            static function (int $id, string $key, mixed $value) use ($writes): bool {
                $writes[] = [$id, $key, $value];
                return true;
            }
        );
        return $writes;
    }

    private function post(int $id, string $type, string $status, string $content): WP_Post
    {
        $post = new WP_Post();
        $post->ID = $id;
        $post->post_type = $type;
        $post->post_status = $status;
        $post->post_content = $content;
        return $post;
    }
}
