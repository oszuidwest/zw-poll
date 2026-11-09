<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\VoteCounter;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AggregateCacheTest extends TestCase
{
    private const POLL_ID = 42;
    private const NOW = '2026-06-04 12:00:00';

    private VoteCounter $repository;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('current_time')->justReturn(self::NOW);
        Functions\when('wp_cache_delete')->justReturn(true);
        $this->repository = Mockery::mock(VoteCounter::class);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function rebuild_pulls_truth_from_repository(): void
    {
        Functions\when('update_post_meta')->justReturn(true);
        $this->repository->shouldReceive('countByOption')
            ->once()
            ->with(self::POLL_ID)
            ->andReturn(['a' => 10, 'b' => 7, 'c' => 3]);

        $result = (new AggregateCache($this->repository))->rebuild(self::POLL_ID);

        $this->assertSame(['a' => 10, 'b' => 7, 'c' => 3], $result['counts']);
        $this->assertSame(20, $result['total']);
    }

    #[Test]
    public function rebuild_keeps_the_stored_aggregate_when_the_count_fails(): void
    {
        Functions\expect('update_post_meta')->never();
        $this->repository->shouldReceive('countByOption')->once()->andReturn(null);

        $this->assertNull((new AggregateCache($this->repository))->rebuild(self::POLL_ID));
    }

    #[Test]
    public function increment_updates_cold_cache_without_scanning_votes_table(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        $this->repository->shouldNotReceive('countByOption');

        $result = (new AggregateCache($this->repository))->increment(self::POLL_ID, 'optA');

        $this->assertSame(['optA' => 1], $result['counts']);
        $this->assertSame(1, $result['total']);
    }

    #[Test]
    public function increment_preserves_raw_counts_and_persists_with_previous_value(): void
    {
        $stored = [
            'counts' => ['optA' => 2, 'optB' => 1, 'removed' => 99],
            'total' => 102,
            'updated_at' => '2026-01-01 00:00:00',
        ];
        $updated = [];
        Functions\when('get_post_meta')->justReturn($stored);
        Functions\when('update_post_meta')->alias(
            static function (int $post_id, string $key, array $value, mixed $previous) use (&$updated): bool {
                $updated = [$post_id, $key, $value, $previous];

                return true;
            }
        );
        $this->repository->shouldNotReceive('countByOption');

        $result = (new AggregateCache($this->repository))->increment(self::POLL_ID, 'optA');

        $this->assertSame(['optA' => 3, 'optB' => 1, 'removed' => 99], $result['counts']);
        $this->assertSame(103, $result['total']);
        $this->assertSame(self::POLL_ID, $updated[0]);
        $this->assertSame($stored, $updated[3]);
    }

    #[Test]
    public function increment_retries_after_busting_stale_meta_cache(): void
    {
        $old = [
            'counts' => ['optA' => 2],
            'total' => 2,
        ];
        $fresh = [
            'counts' => ['optA' => 3],
            'total' => 3,
        ];
        $reads = 0;
        $updates = [];
        $cache_deletes = [];
        Functions\when('get_post_meta')->alias(
            static function () use (&$reads, $old, $fresh): array {
                $reads++;

                return $reads === 1 ? $old : $fresh;
            }
        );
        Functions\when('update_post_meta')->alias(
            static function (int $post_id, string $key, array $value, mixed $previous) use (&$updates): bool {
                $updates[] = [$post_id, $key, $value, $previous];

                return count($updates) === 2;
            }
        );
        Functions\when('wp_cache_delete')->alias(
            static function (int $post_id, string $group) use (&$cache_deletes): bool {
                $cache_deletes[] = [$post_id, $group];

                return true;
            }
        );
        $this->repository->shouldNotReceive('countByOption');

        $result = (new AggregateCache($this->repository))->increment(self::POLL_ID, 'optA');

        $this->assertSame(['optA' => 4], $result['counts']);
        $this->assertSame(4, $result['total']);
        $this->assertCount(2, $updates);
        $this->assertSame($old, $updates[0][3]);
        $this->assertSame($fresh, $updates[1][3]);
        $this->assertSame([[self::POLL_ID, 'post_meta']], $cache_deletes);
    }

    #[Test]
    public function increment_logs_and_leaves_the_aggregate_after_retry_failures(): void
    {
        $stored = [
            'counts' => ['optA' => 2],
            'total' => 2,
        ];
        $previous_values = [];
        Functions\when('get_post_meta')->justReturn($stored);
        Functions\when('update_post_meta')->alias(
            static function (int $post_id, string $key, array $value, mixed $previous = '') use (&$previous_values): bool {
                $previous_values[] = $previous;

                return false;
            }
        );
        $logs = [];
        Functions\when('error_log')->alias(static function (string $message) use (&$logs): bool {
            $logs[] = $message;
            return true;
        });
        $this->repository->shouldReceive('countByOption')
            ->once()
            ->with(self::POLL_ID)
            ->andReturn(['optA' => 10]);

        $result = (new AggregateCache($this->repository))->increment(self::POLL_ID, 'optA');

        $this->assertSame(['optA' => 10], $result['counts']);
        $this->assertSame(array_fill(0, 10, $stored), $previous_values);
        $this->assertSame(['zw-poll: aggregate increment failed for poll 42; run wp zw-poll rebuild 42.'], $logs);
    }

    #[Test]
    public function increment_returns_the_attempted_payload_when_the_fallback_count_fails(): void
    {
        Functions\when('get_post_meta')->justReturn(['counts' => ['optA' => 2], 'total' => 2]);
        Functions\when('update_post_meta')->justReturn(false);
        $logs = [];
        Functions\when('error_log')->alias(static function (string $message) use (&$logs): bool {
            $logs[] = $message;
            return true;
        });
        $this->repository->shouldReceive('countByOption')->once()->andReturn(null);

        $result = (new AggregateCache($this->repository))->increment(self::POLL_ID, 'optA');

        $this->assertSame(['optA' => 3], $result['counts']);
        $this->assertSame('zw-poll: vote count failed for poll 42; the vote response shows estimated counts.', $logs[1] ?? null);
    }

    // Public aggregate shape shared by SSR and REST.

    #[Test]
    public function project_excludes_removed_options_from_counts_and_total(): void
    {
        $result = AggregateCache::projectAggregate(
            ['counts' => ['optA' => 3, 'removed' => 5], 'total' => 8],
            [['id' => 'optA', 'label' => 'A']]
        );

        $this->assertSame(['optA' => 3], $result['counts']);
        // Deleted-option votes must not inflate the public total.
        $this->assertSame(3, $result['total']);
    }

    #[Test]
    public function project_zero_seeds_visible_options_without_counts(): void
    {
        $result = AggregateCache::projectAggregate(
            ['counts' => ['optA' => 2], 'total' => 2],
            [['id' => 'optA', 'label' => 'A'], ['id' => 'optB', 'label' => 'B']]
        );

        $this->assertSame(['optA' => 2, 'optB' => 0], $result['counts']);
        $this->assertSame(2, $result['total']);
    }

    #[Test]
    public function project_skips_malformed_option_rows(): void
    {
        $result = AggregateCache::projectAggregate(
            ['counts' => ['optA' => 4], 'total' => 4],
            [['id' => 'optA', 'label' => 'A'], 'not-an-array', ['label' => 'no id']]
        );

        $this->assertSame(['optA' => 4], $result['counts']);
        $this->assertSame(4, $result['total']);
    }

    #[Test]
    public function project_returns_zero_for_empty_options(): void
    {
        $result = AggregateCache::projectAggregate(['counts' => ['optA' => 9], 'total' => 9], []);

        $this->assertSame([], $result['counts']);
        $this->assertSame(0, $result['total']);
    }

    #[Test]
    public function project_aggregate_preserves_timestamp_and_projects_counts(): void
    {
        $result = AggregateCache::projectAggregate(
            [
                'counts' => ['optA' => 3, 'removed' => 5],
                'total' => 8,
                'updated_at' => '2026-01-01 00:00:00',
            ],
            [['id' => 'optA', 'label' => 'A'], ['id' => 'optB', 'label' => 'B']]
        );

        $this->assertSame(['optA' => 3, 'optB' => 0], $result['counts']);
        $this->assertSame(3, $result['total']);
        $this->assertSame('2026-01-01 00:00:00', $result['updated_at']);
    }

    // Read-only display decode; never rebuild or write.

    #[Test]
    public function from_meta_returns_zeros_for_malformed_input_without_rebuilding(): void
    {
        Functions\expect('update_post_meta')->never();
        $this->repository->shouldNotReceive('countByOption');

        $zero = ['counts' => [], 'total' => 0, 'updated_at' => ''];
        $this->assertSame($zero, AggregateCache::fromMeta(''));
        $this->assertSame($zero, AggregateCache::fromMeta(['only_total' => 5]));
    }

    #[Test]
    public function from_meta_coerces_stored_values_to_ints(): void
    {
        $result = AggregateCache::fromMeta([
            'counts' => ['a' => '3', 'b' => '4'],
            'total' => '7',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->assertSame(['a' => 3, 'b' => 4], $result['counts']);
        $this->assertSame(7, $result['total']);
        $this->assertSame('2026-01-01 00:00:00', $result['updated_at']);
    }
}
