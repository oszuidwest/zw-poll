<?php
/**
 * Defines the vote-counting contract.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Vote;

/**
 * Read side of the votes store used by aggregate rebuilds and tests.
 */
interface VoteCounter
{
    /**
     * Counts votes grouped by option.
     *
     * @param int $poll_id Poll post ID.
     * @return array<string, int> Map of option_id to vote count.
     */
    public function countByOption(int $poll_id): array;
}
