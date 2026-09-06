<?php
/**
 * Resets all votes for a poll.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Vote;

/**
 * Shared reset sequence for REST and WP-CLI.
 *
 * Vote rows, vote epoch, and aggregate must move together.
 */
final class PollReset
{
    /**
     * Stores collaborators for vote resets.
     *
     * @param VoteRepository $repository Vote storage adapter.
     * @param AggregateCache $cache      Aggregate cache service.
     */
    public function __construct(
        private readonly VoteRepository $repository,
        private readonly AggregateCache $cache,
    ) {}

    /**
     * Deletes votes, invalidates stale cookies, and rebuilds the aggregate.
     *
     * @param int $poll_id Poll post ID.
     * @return array{deleted: int, aggregate: array{counts: array<string, int>, total: int, updated_at: string}}
     */
    public function reset(int $poll_id): array
    {
        $deleted = $this->repository->deleteAllForPoll($poll_id);
        VoteEpoch::increment($poll_id);
        $aggregate = $this->cache->rebuild($poll_id);

        return ['deleted' => $deleted, 'aggregate' => $aggregate];
    }
}
