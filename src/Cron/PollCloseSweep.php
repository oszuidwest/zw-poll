<?php
/**
 * Closes published polls whose configured deadline has passed.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Cron;

use ZuidWest\Poll\Admin\UsageTracker;
use ZuidWest\Poll\PostType\PollPostType;

/**
 * Owns the recurring close sweep and the effects of poll status changes.
 */
final class PollCloseSweep
{
    public const EVENT = 'zw_poll_close_expired';
    private const SCHEDULE = 'zw_poll_five_minutes';

    /**
     * Registers cron and status-change hooks.
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action('init', [$this, 'schedule']);
        add_action(self::EVENT, [$this, 'runScheduledSweep']);
        // A first status write on a poll without a status row fires added_post_meta, not updated_post_meta.
        add_action('added_post_meta', [$this, 'onStatusMeta'], 10, 4);
        add_action('updated_post_meta', [$this, 'onStatusMeta'], 10, 4);
    }

    /**
     * Adds the hardcoded five-minute recurrence.
     *
     * @param array<string, array{interval: int, display: string}> $schedules Registered schedules.
     * @return array<string, array{interval: int, display: string}>
     */
    public function addSchedule(array $schedules): array
    {
        $schedules[self::SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Elke vijf minuten', 'zw-poll'),
        ];

        return $schedules;
    }

    /**
     * Ensures the per-site recurring event exists.
     */
    public function schedule(): void
    {
        if (wp_next_scheduled(self::EVENT) === false) {
            wp_schedule_event(time(), self::SCHEDULE, self::EVENT);
        }
    }

    /**
     * Runs the sweep as an action callback; phpstan-wordpress requires action callbacks to return void.
     */
    public function runScheduledSweep(): void
    {
        $this->sweep();
    }

    /**
     * Closes all published, open polls whose deadline has passed.
     *
     * @return int Number of polls changed by this invocation.
     */
    public function sweep(): int
    {
        $polls = get_posts([
            'post_type' => PollPostType::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Poll deadlines and status use core post meta by design.
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => PollPostType::META_CLOSES_AT,
                    'value' => [1, time()],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => PollPostType::META_STATUS,
                        'value' => 'open',
                        'compare' => '=',
                    ],
                    [
                        'key' => PollPostType::META_STATUS,
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ],
        ]);

        $closed = 0;
        foreach ($polls as $poll) {
            if (update_post_meta($poll->ID, PollPostType::META_STATUS, 'closed') !== false) {
                ++$closed;
            }
        }

        return $closed;
    }

    /**
     * Applies status-change effects shared by REST, admin, CLI, bulk, and cron writes.
     *
     * Closing announces the poll to cache purgers and other consumers. Opening
     * drops an expired deadline so the next sweep does not close the poll again.
     *
     * @param int    $meta_id  Meta row ID.
     * @param int    $poll_id  Post ID.
     * @param string $meta_key Meta key.
     * @param mixed  $value    New value.
     */
    public function onStatusMeta(int $meta_id, int $poll_id, string $meta_key, mixed $value): void
    {
        if ($meta_key !== PollPostType::META_STATUS || get_post_type($poll_id) !== PollPostType::POST_TYPE) {
            return;
        }

        if ($value === 'closed') {
            do_action('zw_poll_closed', $poll_id, UsageTracker::getUsage($poll_id));
            return;
        }

        $closes_at = PollPostType::closesAt($poll_id);
        if ($value === 'open' && $closes_at > 0 && $closes_at <= time()) {
            delete_post_meta($poll_id, PollPostType::META_CLOSES_AT);
        }
    }
}
