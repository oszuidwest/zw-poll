<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Cli\Commands;
use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\PollReset;
use ZuidWest\Poll\Vote\VoteRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_CLI;
use WP_Post;
use wpdb;

final class CommandsTest extends TestCase
{
    private const POLL_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        WP_CLI::reset();
        Functions\when('current_time')->justReturn('2026-06-04 12:00:00');
        Functions\when('__')->returnArg();
        Functions\when('get_post_meta')->justReturn(0);
        Functions\when('update_post_meta')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Build CLI commands over a fake tally. */
    private function commands(array $tally = [], int $deleted = 0): Commands
    {
        $db = new class($tally, $deleted) extends wpdb {
            public function __construct(private array $tally, private int $deleted)
            {
                $this->prefix = 'wp_';
            }

            public function get_results(string $query, string $output = ARRAY_A): array
            {
                return $this->tally;
            }

            public function delete(string $table, array $where, array $formats): int|false
            {
                return $this->deleted;
            }
        };

        $repository = new VoteRepository($db);
        $cache = new AggregateCache($repository);

        return new Commands($cache, new PollReset($repository, $cache));
    }

    private function poll(): WP_Post
    {
        $poll = new WP_Post();
        $poll->ID = self::POLL_ID;
        $poll->post_type = PollPostType::POST_TYPE;
        $poll->post_title = 'Poll week 23';

        return $poll;
    }

    #[Test]
    public function rebuild_recomputes_aggregate_and_reports_totals(): void
    {
        Functions\when('get_post')->justReturn($this->poll());

        $this->commands([
            ['option_id' => 'a', 'c' => 3],
            ['option_id' => 'b', 'c' => 1],
        ])->rebuild([(string) self::POLL_ID]);

        $this->assertSame(
            [['level' => 'success', 'message' => 'Aggregaat herbouwd voor poll 42: 4 stemmen over 2 opties.']],
            WP_CLI::$log
        );
    }

    #[Test]
    public function reset_deletes_votes_and_reports_count(): void
    {
        Functions\when('get_post')->justReturn($this->poll());
        $writes = [];
        Functions\when('update_post_meta')->alias(
            static function (int $id, string $key, mixed $value) use (&$writes): bool {
                $writes[] = [$id, $key, $value];
                return true;
            }
        );

        $this->commands(tally: [], deleted: 7)->reset([(string) self::POLL_ID], ['yes' => true]);

        $epoch_writes = array_values(array_filter(
            $writes,
            static fn (array $write): bool => $write[0] === self::POLL_ID
                && $write[1] === PollPostType::META_VOTE_EPOCH
        ));
        $this->assertSame([[self::POLL_ID, PollPostType::META_VOTE_EPOCH, 1]], $epoch_writes);
        $this->assertSame(
            [['level' => 'success', 'message' => '7 stemmen verwijderd voor poll 42.']],
            WP_CLI::$log
        );
    }

    #[Test]
    public function unknown_poll_id_aborts_with_error(): void
    {
        Functions\when('get_post')->justReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Geen poll gevonden met ID 999.');

        $this->commands()->reset(['999'], ['yes' => true]);
    }

    #[Test]
    public function wrong_post_type_aborts_with_error(): void
    {
        $page = new WP_Post();
        $page->ID = self::POLL_ID;
        $page->post_type = 'page';
        Functions\when('get_post')->justReturn($page);

        $this->expectException(RuntimeException::class);

        $this->commands()->rebuild([(string) self::POLL_ID]);
    }

    #[Test]
    public function list_renders_one_row_per_poll(): void
    {
        Functions\when('get_posts')->justReturn([$this->poll()]);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): mixed => match ($key) {
                PollPostType::META_OPTIONS => [['id' => 'a', 'label' => 'A']],
                PollPostType::META_AGGREGATE => ['counts' => ['a' => 2], 'total' => 2, 'updated_at' => '2026-06-04 10:00:00'],
                PollPostType::META_STATUS => 'open',
                default => '',
            }
        );

        $captured = [];
        Functions\when('WP_CLI\Utils\format_items')->alias(
            static function (string $format, array $rows, array $cols) use (&$captured): void {
                $captured = $rows;
            }
        );

        $this->commands()->list_polls([], ['format' => 'table']);

        $this->assertCount(1, $captured);
        $this->assertSame(self::POLL_ID, $captured[0]['id']);
        $this->assertSame('open', $captured[0]['status']);
        $this->assertSame(2, $captured[0]['votes']);
    }

    #[Test]
    public function list_projects_vote_totals_to_visible_options(): void
    {
        Functions\when('get_posts')->justReturn([$this->poll()]);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): mixed => match ($key) {
                PollPostType::META_OPTIONS => [['id' => 'a', 'label' => 'A']],
                PollPostType::META_AGGREGATE => [
                    'counts' => ['a' => 2, 'removed' => 99],
                    'total' => 101,
                    'updated_at' => '2026-06-04 10:00:00',
                ],
                PollPostType::META_STATUS => 'open',
                default => '',
            }
        );

        $captured = [];
        Functions\when('WP_CLI\Utils\format_items')->alias(
            static function (string $format, array $rows, array $cols) use (&$captured): void {
                $captured = $rows;
            }
        );

        $this->commands()->list_polls([], ['format' => 'table']);

        $this->assertSame(2, $captured[0]['votes']);
    }
}
