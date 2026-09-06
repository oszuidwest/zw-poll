<?php
/**
 * Tracks reset generations for poll vote cookies.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Vote;

use ZuidWest\Poll\PostType\PollPostType;

/**
 * Stores the per-poll vote epoch used to invalidate stale browser cookies.
 */
final class VoteEpoch
{
    /**
     * Returns the current vote epoch for a poll.
     *
     * @param int $poll_id Poll post ID.
     */
    public static function current(int $poll_id): int
    {
        return max(0, (int) get_post_meta($poll_id, PollPostType::META_VOTE_EPOCH, true));
    }

    /**
     * Increments and persists the vote epoch for a poll.
     *
     * @param int $poll_id Poll post ID.
     */
    public static function increment(int $poll_id): void
    {
        $next = self::current($poll_id) + 1;
        update_post_meta($poll_id, PollPostType::META_VOTE_EPOCH, $next);
    }
}
