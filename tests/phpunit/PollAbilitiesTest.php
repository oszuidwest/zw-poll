<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZuidWest\Poll\Abilities\PollAbilities;
use ZuidWest\Poll\Cron\PollCloseSweep;
use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\PollReset;
use ZuidWest\Poll\Vote\VoteRepository;
use WP_Error;
use WP_Post;
use wpdb;

final class PollAbilitiesTest extends TestCase
{
    private PollAbilities $abilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-10-19 12:00:00');
        $repository = new VoteRepository(new wpdb());
        $cache = new AggregateCache($repository);
        $this->abilities = new PollAbilities($cache, new PollReset($repository, $cache), new PollCloseSweep());
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function poll(int $id = 42): WP_Post
    {
        $poll = new WP_Post();
        $poll->ID = $id;
        $poll->post_type = PollPostType::POST_TYPE;
        $poll->post_title = 'Vraag';
        $poll->post_status = 'draft';
        return $poll;
    }

    #[Test]
    public function registers_discoverable_abilities_with_strict_contracts_and_honest_annotations(): void
    {
        $this->abilities->register();
        $this->assertNotFalse(has_action('wp_abilities_api_init', [$this->abilities, 'registerAbilities']));
        $this->assertNotFalse(has_action('wp_abilities_api_categories_init', [$this->abilities, 'registerCategory']));
        Functions\expect('wp_register_ability_category')->once()->with('zw-poll', \Mockery::type('array'));
        $this->abilities->registerCategory();
        $registered = [];
        Functions\when('wp_register_ability')->alias(static function (string $name, array $args) use (&$registered): void {
            $registered[$name] = $args;
        });
        $this->abilities->registerAbilities();
        $this->assertCount(6, $registered);
        foreach ($registered as $args) {
            $this->assertTrue($args['meta']['public']);
            $this->assertFalse($args['input_schema']['additionalProperties']);
            $this->assertIsCallable($args['execute_callback']);
            $this->assertIsCallable($args['permission_callback']);
        }
        $this->assertSame(['readonly' => false, 'destructive' => true, 'idempotent' => false],
            $registered['zw-poll/reset-votes']['meta']['annotations']);
        $this->assertSame([true], $registered['zw-poll/reset-votes']['input_schema']['properties']['confirm']['enum']);
    }

    #[Test]
    public function rebuild_uses_the_shared_cache_writer_and_reset_propagates_storage_failure(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\expect('update_post_meta')->once()->with(42, PollPostType::META_AGGREGATE, \Mockery::type('array'))->andReturn(true);
        $this->assertSame(0, $this->abilities->rebuild(['id' => 42])['total']);
        $repository = new VoteRepository(new class extends wpdb {
            public function delete(string $table, array $where, array $formats): int|false
            {
                return false;
            }
        });
        $cache = new AggregateCache($repository);
        $abilities = new PollAbilities($cache, new PollReset($repository, $cache), new PollCloseSweep());
        $this->assertInstanceOf(WP_Error::class, $abilities->resetVotes(['id' => 42, 'confirm' => true]));
    }

    #[Test]
    public function object_permissions_require_both_global_and_target_capabilities(): void
    {
        Functions\when('get_post')->justReturn($this->poll());
        Functions\when('current_user_can')->alias(static fn (string $cap, int $id = 0): bool =>
            $cap === 'manage_zw_polls' || ($cap === 'edit_post' && $id === 42));
        $this->assertTrue($this->abilities->canEditPoll(['id' => 42]));
        Functions\when('current_user_can')->alias(static fn (string $cap): bool => $cap === 'manage_zw_polls');
        $this->assertFalse($this->abilities->canEditPoll(['id' => 42]));
        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse($this->abilities->canEditPoll(['id' => 42]));
    }

    #[Test]
    public function missing_or_wrong_type_objects_are_not_accepted_as_polls(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post')->justReturn(null);
        $this->assertInstanceOf(WP_Error::class, $this->abilities->canEditPoll(['id' => 42]));
        $post = $this->poll();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        $this->assertInstanceOf(WP_Error::class, $this->abilities->canEditPoll(['id' => 42]));
    }

    #[Test]
    public function list_filters_out_objects_the_manager_cannot_edit(): void
    {
        Functions\when('get_posts')->justReturn([$this->poll(42), $this->poll(43)]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('current_user_can')->alias(static fn (string $cap, int $id = 0): bool =>
            $cap === 'edit_others_zw_polls' || $id === 42);
        $rows = $this->abilities->listPolls([]);
        $this->assertSame([42], array_column($rows, 'id'));
        $this->assertSame('draft', $rows[0]['post_status']);
    }

    #[Test]
    public function managers_without_edit_others_page_through_their_own_polls(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('current_user_can')->alias(static fn (string $cap, int $id = 0): bool =>
            $cap === 'manage_zw_polls' || ($cap === 'edit_post' && $id <= 43));
        // Two newer polls by another author must not consume the first page.
        $records = [
            ['author' => 99, 'poll' => $this->poll(45)],
            ['author' => 99, 'poll' => $this->poll(44)],
            ['author' => 7, 'poll' => $this->poll(43)],
            ['author' => 7, 'poll' => $this->poll(42)],
            ['author' => 7, 'poll' => $this->poll(41)],
        ];
        Functions\when('get_posts')->alias(function (array $query) use ($records): array {
            $this->assertSame(7, $query['author']);
            $this->assertSame(2, $query['posts_per_page']);
            $this->assertSame('Vraag', $query['s']);
            $visible = array_values(array_filter($records,
                static fn (array $record): bool => $record['author'] === $query['author']));
            return array_column(array_slice($visible, ($query['paged'] - 1) * 2, 2), 'poll');
        });

        $this->assertSame([43, 42], array_column($this->abilities->listPolls([
            'page' => 1, 'per_page' => 2, 'search' => 'Vraag',
        ]), 'id'));
        $this->assertSame([41], array_column($this->abilities->listPolls([
            'page' => 2, 'per_page' => 2, 'search' => 'Vraag',
        ]), 'id'));
    }

    #[Test]
    public function managers_with_edit_others_keep_an_unrestricted_author_query(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('get_current_user_id')->never();
        Functions\expect('get_posts')->once()->with(\Mockery::on(static fn (array $query): bool =>
            !array_key_exists('author', $query)
            && $query['paged'] === 3
            && $query['posts_per_page'] === 10
            && $query['s'] === 'Vraag'
        ))->andReturn([]);
        $this->assertSame([], $this->abilities->listPolls(['page' => 3, 'per_page' => 10, 'search' => 'Vraag']));
    }

    #[Test]
    public function readonly_results_with_missing_cache_do_not_write_meta(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\expect('update_post_meta')->never();
        $results = $this->abilities->getResults(['id' => 42]);
        $this->assertSame(0, $results['total']);
        $this->assertEquals((object) [], $results['counts']);
    }

    #[Test]
    public function direct_php_execution_accepts_numeric_strings_allowed_by_core_integer_validation(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        $this->assertSame(42, $this->abilities->getResults(['id' => '42'])['id']);
        $this->assertSame(42, $this->abilities->rebuild(['id' => '42'])['id']);
        $this->assertSame(42, $this->abilities->setStatus(['id' => '42', 'status' => 'open'])['id']);
        $this->assertSame(42, $this->abilities->resetVotes(['id' => '42', 'confirm' => true])['results']['id']);
    }

    #[Test]
    public function reset_uses_shared_epoch_and_aggregate_sequence(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\expect('update_post_meta')->once()->with(42, '_zw_poll_vote_epoch', 1)->andReturn(true);
        Functions\expect('update_post_meta')->once()->with(42, PollPostType::META_AGGREGATE, \Mockery::type('array'))->andReturn(true);
        $result = $this->abilities->resetVotes(['id' => 42, 'confirm' => true]);
        $this->assertIsArray($result);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame(0, $result['results']['total']);
    }

    #[Test]
    public function expiration_never_closes_an_object_the_manager_cannot_edit(): void
    {
        Functions\when('get_posts')->justReturn([42, 43]);
        Functions\when('current_user_can')->alias(static fn (string $cap, int $id): bool => $id === 42);
        Functions\expect('update_post_meta')->once()->with(42, PollPostType::META_STATUS, 'closed')->andReturn(true);
        $this->assertSame(['closed' => 1], $this->abilities->closeExpired());
    }

    #[Test]
    public function status_retries_do_not_fire_status_hooks_twice_and_write_failures_surface(): void
    {
        Functions\when('get_post_meta')->justReturn('open');
        Functions\expect('update_post_meta')->once()->with(42, PollPostType::META_STATUS, 'closed')->andReturn(false);
        $this->assertSame(['id' => 42, 'status' => 'open'], $this->abilities->setStatus(['id' => 42, 'status' => 'open']));
        $this->assertInstanceOf(WP_Error::class, $this->abilities->setStatus(['id' => 42, 'status' => 'closed']));
    }
}
