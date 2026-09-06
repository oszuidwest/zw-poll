<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Admin\UsageTracker;
use ZuidWest\Poll\Cron\PollCloseSweep;
use ZuidWest\Poll\PostType\PollPostType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PollCloseSweepTest extends TestCase
{
    private const NOW = 1784808000;

    private PollCloseSweep $sut;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('time')->justReturn(self::NOW);
        $this->sut = new PollCloseSweep();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function register_wires_schedule_sweep_and_status_listeners(): void
    {
        $this->sut->register();

        $this->assertNotFalse(has_filter('cron_schedules', [$this->sut, 'addSchedule']));
        $this->assertNotFalse(has_action('init', [$this->sut, 'schedule']));
        $this->assertNotFalse(has_action(PollCloseSweep::EVENT, [$this->sut, 'runScheduledSweep']));
        $this->assertNotFalse(has_action('added_post_meta', [$this->sut, 'statusMetaAdded']));
        $this->assertNotFalse(has_action('updated_post_meta', [$this->sut, 'statusMetaUpdated']));
    }

    #[Test]
    public function adds_a_hardcoded_five_minute_schedule(): void
    {
        $schedules = $this->sut->addSchedule([]);

        $this->assertSame(PollCloseSweep::INTERVAL, $schedules[PollCloseSweep::SCHEDULE]['interval']);
        $this->assertSame('Elke vijf minuten', $schedules[PollCloseSweep::SCHEDULE]['display']);
    }

    #[Test]
    public function schedules_the_event_only_when_it_is_missing(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(self::NOW, PollCloseSweep::SCHEDULE, PollCloseSweep::EVENT);

        $this->sut->schedule();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function keeps_an_existing_scheduled_event(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(self::NOW + 60);
        Functions\expect('wp_schedule_event')->never();

        $this->sut->schedule();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function sweep_queries_only_expired_open_published_polls_and_counts_changes(): void
    {
        $query = [];
        Functions\when('get_posts')->alias(
            static function (array $args) use (&$query): array {
                $query = $args;

                $first = new \WP_Post();
                $first->ID = 11;
                $second = new \WP_Post();
                $second->ID = 12;

                return [$first, $second];
            }
        );
        Functions\when('update_post_meta')->alias(
            static fn (int $poll_id, string $key, string $value): bool => $poll_id === 11
                && $key === PollPostType::META_STATUS
                && $value === 'closed'
        );

        $closed = $this->sut->sweep();

        $this->assertSame(1, $closed);
        $this->assertSame(PollPostType::POST_TYPE, $query['post_type']);
        $this->assertSame('publish', $query['post_status']);
        $this->assertSame(-1, $query['posts_per_page']);

        $meta_query = $query['meta_query'];
        $this->assertSame('AND', $meta_query['relation']);
        $this->assertSame([
            'key' => PollPostType::META_CLOSES_AT,
            'value' => [1, self::NOW],
            'compare' => 'BETWEEN',
            'type' => 'NUMERIC',
        ], $meta_query[0]);
        $this->assertSame('OR', $meta_query[1]['relation']);
        $this->assertContains([
            'key' => PollPostType::META_STATUS,
            'value' => 'open',
            'compare' => '=',
        ], $meta_query[1]);
        $this->assertContains([
            'key' => PollPostType::META_STATUS,
            'compare' => 'NOT EXISTS',
        ], $meta_query[1]);
    }

    #[Test]
    public function closing_transition_fires_the_public_hook_with_usage_ids(): void
    {
        Functions\when('get_post_type')->justReturn(PollPostType::POST_TYPE);
        Functions\when('get_post_meta')->alias(
            static fn (int $poll_id, string $key): mixed => $key === UsageTracker::USAGE_META
                ? [101, 202]
                : ''
        );
        Actions\expectDone('zw_poll_closed')
            ->once()
            ->with(42, [101, 202]);

        $this->sut->statusMetaUpdated(7, 42, PollPostType::META_STATUS, 'closed');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function adding_closed_status_fires_the_public_hook(): void
    {
        Functions\when('get_post_type')->justReturn(PollPostType::POST_TYPE);
        Functions\when('get_post_meta')->justReturn([]);
        Actions\expectDone('zw_poll_closed')
            ->once()
            ->with(42, []);

        $this->sut->statusMetaAdded(7, 42, PollPostType::META_STATUS, 'closed');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function reopening_clears_an_expired_deadline(): void
    {
        Functions\when('get_post_type')->justReturn(PollPostType::POST_TYPE);
        Functions\when('get_post_meta')->justReturn(self::NOW);
        Functions\expect('delete_post_meta')
            ->once()
            ->with(42, PollPostType::META_CLOSES_AT);

        $this->sut->statusMetaUpdated(7, 42, PollPostType::META_STATUS, 'open');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function reopening_keeps_a_future_deadline(): void
    {
        Functions\when('get_post_type')->justReturn(PollPostType::POST_TYPE);
        Functions\when('get_post_meta')->justReturn(self::NOW + 60);
        Functions\expect('delete_post_meta')->never();

        $this->sut->statusMetaUpdated(7, 42, PollPostType::META_STATUS, 'open');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function adding_explicit_open_status_does_not_count_as_reopening(): void
    {
        Functions\expect('get_post_type')->never();
        Functions\expect('delete_post_meta')->never();
        Actions\expectDone('zw_poll_closed')->never();

        $this->sut->statusMetaAdded(7, 42, PollPostType::META_STATUS, 'open');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function status_listener_ignores_other_post_types_and_meta_keys(): void
    {
        Functions\when('get_post_type')->justReturn('post');
        Functions\expect('delete_post_meta')->never();
        Actions\expectDone('zw_poll_closed')->never();

        $this->sut->statusMetaUpdated(7, 42, PollPostType::META_STATUS, 'closed');
        $this->sut->statusMetaUpdated(8, 42, '_other_meta', 'closed');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function deactivation_clears_the_current_site_event(): void
    {
        Functions\when('is_multisite')->justReturn(false);
        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with(PollCloseSweep::EVENT);

        PollCloseSweep::deactivate();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function network_deactivation_clears_every_site_event(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('get_sites')->justReturn([2, 5]);
        $switched = [];
        Functions\when('switch_to_blog')->alias(
            static function (int $site_id) use (&$switched): void {
                $switched[] = $site_id;
            }
        );
        Functions\expect('restore_current_blog')->twice();
        Functions\expect('wp_clear_scheduled_hook')
            ->twice()
            ->with(PollCloseSweep::EVENT);

        PollCloseSweep::deactivate(true);

        $this->assertSame([2, 5], $switched);
    }
}
