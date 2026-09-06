<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Admin\DeleteGuard;
use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Vote\VoteRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Post;
use wpdb;

/**
 * Guards force-deletes for polls that still carry votes (#14) or are still
 * embedded in content.
 */
final class DeleteGuardTest extends TestCase
{
    private const POLL_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_current_user_id')->justReturn(1);
        // No usage by default; individual tests override.
        Functions\when('get_post_meta')->justReturn([]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function guard(array $tally = []): DeleteGuard
    {
        $db = new class($tally) extends wpdb {
            public function __construct(private array $tally)
            {
                $this->prefix = 'wp_';
            }

            public function get_results(string $query, string $output = ARRAY_A): array
            {
                return $this->tally;
            }
        };

        return new DeleteGuard(new VoteRepository($db));
    }

    private function poll(): WP_Post
    {
        $poll = new WP_Post();
        $poll->ID = self::POLL_ID;
        $poll->post_type = PollPostType::POST_TYPE;

        return $poll;
    }

    private function expectBlockedTransient(string $reason): void
    {
        Functions\expect('set_transient')
            ->once()
            ->withArgs(
                static fn (string $key, mixed $value, int $expiration): bool =>
                    $key === 'zw_poll_delete_blocked_1'
                    && is_array($value)
                    && ($value['post_id'] ?? null) === self::POLL_ID
                    && ($value['reason'] ?? null) === $reason
                    && $expiration === 5 * MINUTE_IN_SECONDS
            )
            ->andReturn(true);
    }

    #[Test]
    public function passes_through_for_non_poll_post(): void
    {
        $page = new WP_Post();
        $page->post_type = 'page';

        $this->assertNull($this->guard()->guard(null, $page, true));
    }

    #[Test]
    public function blocks_cpt_permanent_delete_even_when_force_delete_is_false(): void
    {
        $this->expectBlockedTransient('votes');

        $this->assertFalse($this->guard(tally: [['option_id' => 'optA', 'c' => 3]])
            ->guard(null, $this->poll(), false));
    }

    #[Test]
    public function blocks_force_delete_when_votes_exist(): void
    {
        $this->expectBlockedTransient('votes');

        $result = $this->guard(tally: [['option_id' => 'optA', 'c' => 3]])
            ->guard(null, $this->poll(), true);

        $this->assertFalse($result);
    }

    #[Test]
    public function blocks_force_delete_when_poll_is_still_in_use(): void
    {
        // Poll without votes, but still referenced by post 7 through a shortcode.
        Functions\when('get_post_meta')->justReturn([7]);
        Functions\when('get_post_status')->justReturn('publish');
        $this->expectBlockedTransient('in_use');

        $this->assertFalse($this->guard(tally: [])->guard(null, $this->poll(), true));
    }

    #[Test]
    public function reports_usage_before_votes_when_both_block_deletion(): void
    {
        Functions\when('get_post_meta')->justReturn([7]);
        Functions\when('get_post_status')->justReturn('publish');
        $this->expectBlockedTransient('in_use');

        $this->assertFalse($this->guard(tally: [['option_id' => 'optA', 'c' => 3]])
            ->guard(null, $this->poll(), true));
    }

    #[Test]
    public function ignores_stale_or_trashed_usage_when_blocking_deletion(): void
    {
        Functions\when('get_post_meta')->justReturn([7, 8]);
        Functions\when('get_post_status')->alias(
            static fn (int $post_id): string|false => $post_id === 7 ? false : 'trash'
        );
        Functions\expect('set_transient')->never();

        $this->assertNull($this->guard(tally: [])->guard(null, $this->poll(), true));
    }

    #[Test]
    public function allows_force_delete_when_no_votes_and_not_in_use(): void
    {
        Functions\expect('set_transient')->never();

        $this->assertNull($this->guard(tally: [])->guard(null, $this->poll(), true));
    }

    #[Test]
    public function hides_permanent_delete_row_action_for_guarded_poll(): void
    {
        $actions = ['edit' => 'Edit', 'delete' => 'Delete', 'trash' => 'Trash'];

        $this->assertSame(
            ['edit' => 'Edit', 'trash' => 'Trash'],
            $this->guard(tally: [['option_id' => 'optA', 'c' => 3]])
                ->filterRowActions($actions, $this->poll())
        );
    }

    #[Test]
    public function keeps_permanent_delete_row_action_for_unblocked_poll(): void
    {
        $actions = ['edit' => 'Edit', 'delete' => 'Delete'];

        $this->assertSame(
            $actions,
            $this->guard(tally: [])->filterRowActions($actions, $this->poll())
        );
    }

    #[Test]
    public function notice_explains_usage_block(): void
    {
        $this->stubNoticeEnvironment(['post_id' => self::POLL_ID, 'reason' => 'in_use']);

        $this->expectOutputRegex('/wordt nog gebruikt/');
        $this->guard()->showBlockedNotice();
    }

    #[Test]
    public function notice_explains_votes_block(): void
    {
        $this->stubNoticeEnvironment(['post_id' => self::POLL_ID, 'reason' => 'votes']);

        $this->expectOutputRegex('/bevat stemmen/');
        $this->guard()->showBlockedNotice();
    }

    #[Test]
    public function notice_discards_malformed_transient_without_output(): void
    {
        $this->stubNoticeEnvironment(42, expectDelete: true);

        $this->expectOutputString('');
        $this->guard()->showBlockedNotice();
    }

    #[Test]
    public function notice_skips_cleanup_when_no_transient_exists(): void
    {
        $this->stubNoticeEnvironment(false);

        $this->expectOutputString('');
        $this->guard()->showBlockedNotice();
    }

    private function stubNoticeEnvironment(mixed $transient, bool $expectDelete = false): void
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('get_current_screen')->justReturn((object) ['post_type' => PollPostType::POST_TYPE]);
        Functions\when('get_transient')->justReturn($transient);
        if ($transient === false) {
            Functions\expect('delete_transient')->never();
        } elseif ($expectDelete) {
            Functions\expect('delete_transient')
                ->once()
                ->with('zw_poll_delete_blocked_1')
                ->andReturn(true);
        } else {
            Functions\when('delete_transient')->justReturn(true);
        }
        Functions\when('get_edit_post_link')->justReturn('https://example.test/wp-admin/post.php?post=42&action=edit');
    }
}
