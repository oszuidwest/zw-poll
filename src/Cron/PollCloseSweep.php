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
 * Owns the recurring close sweep and poll-status transition effects.
 */
final class PollCloseSweep
{
    public const EVENT = 'zw_poll_close_expired';
    public const SCHEDULE = 'zw_poll_five_minutes';
    public const INTERVAL = 300;

    /**
     * Registers cron and status-transition hooks.
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action('init', [$this, 'schedule']);
        add_action(self::EVENT, [$this, 'runScheduledSweep']);
        add_action('added_post_meta', [$this, 'statusMetaAdded'], 10, 4);
        add_action('updated_post_meta', [$this, 'statusMetaUpdated'], 10, 4);
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
            'interval' => self::INTERVAL,
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
     * Runs the sweep as a void WordPress action callback.
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
            'no_found_rows' => true,
            'suppress_filters' => false,
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
     * Handles a newly created status meta row.
     *
     * @param int    $meta_id   Meta row ID.
     * @param int    $object_id Post ID.
     * @param string $meta_key  Meta key.
     * @param mixed  $value     New value.
     */
    public function statusMetaAdded(int $meta_id, int $object_id, string $meta_key, mixed $value): void
    {
        if ($value === 'closed') {
            $this->handleStatusTransition($object_id, $meta_key, 'closed');
        }
    }

    /**
     * Handles a changed status meta row.
     *
     * @param int    $meta_id   Meta row ID.
     * @param int    $object_id Post ID.
     * @param string $meta_key  Meta key.
     * @param mixed  $value     New value.
     */
    public function statusMetaUpdated(int $meta_id, int $object_id, string $meta_key, mixed $value): void
    {
        if ($value === 'open' || $value === 'closed') {
            $this->handleStatusTransition($object_id, $meta_key, $value);
        }
    }

    /**
     * Clears close events for one site or every site during network deactivation.
     *
     * @param bool $network_wide Whether the plugin is network-deactivated.
     */
    public static function deactivate(bool $network_wide = false): void
    {
        if ($network_wide && is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
                switch_to_blog((int) $site_id);
                try {
                    wp_clear_scheduled_hook(self::EVENT);
                } finally {
                    restore_current_blog();
                }
            }
            return;
        }

        wp_clear_scheduled_hook(self::EVENT);
    }

    /**
     * Applies effects shared by REST, admin, CLI, bulk, and cron writes.
     *
     * @param int    $poll_id Poll post ID.
     * @param string $meta_key Changed meta key.
     * @param string $status New poll status.
     */
    private function handleStatusTransition(int $poll_id, string $meta_key, string $status): void
    {
        if ($meta_key !== PollPostType::META_STATUS || get_post_type($poll_id) !== PollPostType::POST_TYPE) {
            return;
        }

        if ($status === 'closed') {
            do_action('zw_poll_closed', $poll_id, UsageTracker::getUsage($poll_id));
            return;
        }

        $closes_at = PollPostType::closesAt($poll_id);
        if ($closes_at > 0 && $closes_at <= time()) {
            delete_post_meta($poll_id, PollPostType::META_CLOSES_AT);
        }
    }
}
