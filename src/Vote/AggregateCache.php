<?php
/**
 * Maintains cached vote aggregates.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Vote;

use ZuidWest\Poll\PostType\PollPostType;

/**
 * Stores and normalizes poll vote aggregates.
 */
final class AggregateCache
{
    private const MAX_INCREMENT_ATTEMPTS = 3;

    /**
     * Stores the vote counter used for rebuilds.
     *
     * @param VoteCounter $repository Vote counter.
     */
    public function __construct(private readonly VoteCounter $repository) {}

    /**
     * Returns a cached aggregate, rebuilding missing or malformed meta.
     *
     * @param int $poll_id Poll post ID.
     * @return array{counts: array<string, int>, total: int, updated_at: string}
     */
    public function get(int $poll_id): array
    {
        $stored = get_post_meta($poll_id, PollPostType::META_AGGREGATE, true);
        return self::decode($stored) ?? $this->rebuild($poll_id);
    }

    /**
     * Normalizes a stored aggregate meta value without rebuilding.
     *
     * Read-only display paths cannot write during GET/list-table renders, so
     * malformed meta falls back to zeroes instead of rebuilding.
     *
     * @param mixed $stored Raw aggregate meta value.
     * @return array{counts: array<string, int>, total: int, updated_at: string}
     */
    public static function fromMeta(mixed $stored): array
    {
        return self::decode($stored) ?? ['counts' => [], 'total' => 0, 'updated_at' => ''];
    }

    /**
     * Decodes a stored aggregate meta value, or null when malformed.
     *
     * Central shape check for aggregate meta.
     *
     * @param mixed $stored Raw aggregate meta value.
     * @return array{counts: array<string, int>, total: int, updated_at: string}|null
     */
    private static function decode(mixed $stored): ?array
    {
        if (!is_array($stored) || !isset($stored['counts'], $stored['total'])) {
            return null;
        }
        return [
            'counts' => array_map('intval', (array) $stored['counts']),
            'total' => (int) $stored['total'],
            'updated_at' => (string) ($stored['updated_at'] ?? ''),
        ];
    }

    /**
     * Projects a full aggregate payload onto the visible options, zero-seeded.
     *
     * Public aggregate shape for SSR and REST: counts are zero-seeded for
     * visible options and removed options are excluded from the total.
     *
     * @param array{counts: array<string, int>, total: int, updated_at?: string} $aggregate Stored aggregate payload.
     * @param array<int|string, mixed>                                           $options   Current option rows.
     * @return array{counts: array<string, int>, total: int, updated_at: string}
     */
    public static function projectAggregate(array $aggregate, array $options): array
    {
        $counts = $aggregate['counts'];
        $out = [];
        foreach ($options as $option) {
            if (!is_array($option) || !isset($option['id'])) {
                continue;
            }
            $id = (string) $option['id'];
            $out[$id] = (int) ($counts[$id] ?? 0);
        }
        return [
            'counts' => $out,
            'total' => array_sum($out),
            'updated_at' => (string) ($aggregate['updated_at'] ?? ''),
        ];
    }

    /**
     * Calculates the display percentage for an option.
     *
     * Shared rounding policy for PHP render/admin output; view.js mirrors it.
     *
     * @param int $count Option vote count.
     * @param int $total Total vote count.
     */
    public static function percentage(int $count, int $total): int
    {
        return $total > 0 ? (int) round($count / $total * 100) : 0;
    }

    /**
     * Decodes and projects a poll's stored aggregate for display.
     *
     * Display paths decode raw meta and project it onto visible options without
     * rebuilding or writing; REST hydrates the same shape.
     *
     * @param int                      $poll_id Poll post ID.
     * @param array<int|string, mixed> $options Current option rows.
     * @return array{counts: array<string, int>, total: int, updated_at: string}
     */
    public static function forDisplay(int $poll_id, array $options): array
    {
        return self::projectAggregate(
            self::fromMeta(get_post_meta($poll_id, PollPostType::META_AGGREGATE, true)),
            $options
        );
    }

    /**
     * Rebuilds and persists the aggregate from the vote store.
     *
     * @param int $poll_id Poll post ID.
     * @return array{counts: array<string, int>, total: int, updated_at: string}
     */
    public function rebuild(int $poll_id): array
    {
        $payload = self::payload($this->repository->countByOption($poll_id));
        update_post_meta($poll_id, PollPostType::META_AGGREGATE, $payload);
        return $payload;
    }

    /**
     * Increments the cached aggregate without scanning the votes table.
     *
     * The votes table remains source of truth. This hot path CAS-updates
     * postmeta, clears stale meta between misses, then falls back to rebuild.
     *
     * @param int    $poll_id   Poll post ID.
     * @param string $option_id Selected option ID.
     * @return array{counts: array<string, int>, total: int, updated_at: string}
     */
    public function increment(int $poll_id, string $option_id): array
    {
        for ($attempt = 0; $attempt < self::MAX_INCREMENT_ATTEMPTS; $attempt++) {
            $stored = get_post_meta($poll_id, PollPostType::META_AGGREGATE, true);
            $payload = self::incrementPayload($stored, $option_id);

            if (update_post_meta($poll_id, PollPostType::META_AGGREGATE, $payload, $stored)) {
                return $payload;
            }

            wp_cache_delete($poll_id, 'post_meta');
        }

        return $this->rebuild($poll_id);
    }

    /**
     * Builds an incremented aggregate payload from stored meta.
     *
     * @param mixed  $stored    Stored aggregate meta value.
     * @param string $option_id Selected option ID.
     * @return array{counts: array<string, int>, total: int, updated_at: string}
     */
    private static function incrementPayload(mixed $stored, string $option_id): array
    {
        $counts = self::fromMeta($stored)['counts'];
        $counts[$option_id] = ($counts[$option_id] ?? 0) + 1;

        return self::payload($counts);
    }

    /**
     * Assembles the persisted aggregate payload from raw counts.
     *
     * @param array<string, int> $counts Raw counts keyed by option ID.
     * @return array{counts: array<string, int>, total: int, updated_at: string}
     */
    private static function payload(array $counts): array
    {
        return [
            'counts' => $counts,
            'total' => array_sum($counts),
            'updated_at' => current_time('mysql', true),
        ];
    }
}
