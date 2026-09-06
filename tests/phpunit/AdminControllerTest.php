<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Rest\AdminController;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\PollReset;
use ZuidWest\Poll\Vote\VoteRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use wpdb;

/**
 * Covers reset authorization and delete-then-rebuild behavior (#14).
 */
final class AdminControllerTest extends TestCase
{
    private const POLL_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-06-04 12:00:00');
        Functions\when('get_post_meta')->justReturn(0);
        Functions\when('update_post_meta')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function controller(int $deleted = 0, array $tally = []): AdminController
    {
        $db = new class($deleted, $tally) extends wpdb {
            public function __construct(private int $deleted, private array $tally)
            {
                $this->prefix = 'wp_';
            }

            public function delete(string $table, array $where, array $formats): int|false
            {
                return $this->deleted;
            }

            public function get_results(string $query, string $output = ARRAY_A): array
            {
                return $this->tally;
            }
        };

        $repository = new VoteRepository($db);

        return new AdminController(new PollReset($repository, new AggregateCache($repository)));
    }

    private function request(): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $req->set_param('id', self::POLL_ID);

        return $req;
    }

    private function poll(): WP_Post
    {
        $poll = new WP_Post();
        $poll->ID = self::POLL_ID;
        $poll->post_type = PollPostType::POST_TYPE;

        return $poll;
    }

    private function page(): WP_Post
    {
        $page = new WP_Post();
        $page->ID = self::POLL_ID;
        $page->post_type = 'page';

        return $page;
    }

    #[Test]
    public function can_manage_requires_manage_capability_and_object_edit_permission(): void
    {
        Functions\when('get_post')->justReturn($this->poll());
        Functions\when('current_user_can')->alias(
            static fn (string $capability, int $post_id = 0): bool => match ($capability) {
                'manage_zw_polls' => true,
                'edit_post' => $post_id === self::POLL_ID,
                default => false,
            }
        );

        $this->assertTrue($this->controller()->canManage($this->request()));
    }

    #[Test]
    public function can_manage_denies_without_capability(): void
    {
        Functions\expect('current_user_can')->once()->with('manage_zw_polls')->andReturn(false);
        $this->assertFalse($this->controller()->canManage($this->request()));
    }

    #[Test]
    public function can_manage_denies_when_user_cannot_edit_the_target_poll(): void
    {
        Functions\when('get_post')->justReturn($this->poll());
        Functions\when('current_user_can')->alias(
            static fn (string $capability): bool => $capability === 'manage_zw_polls'
        );

        $this->assertFalse($this->controller()->canManage($this->request()));
    }

    #[Test]
    public function can_manage_allows_authorized_manager_to_reach_missing_poll_404(): void
    {
        Functions\when('get_post')->justReturn(null);
        Functions\when('current_user_can')->alias(
            static fn (string $capability): bool => $capability === 'manage_zw_polls'
        );

        $this->assertTrue($this->controller()->canManage($this->request()));
    }

    #[Test]
    public function can_manage_allows_authorized_manager_to_reach_wrong_post_type_404(): void
    {
        Functions\when('get_post')->justReturn($this->page());
        Functions\when('current_user_can')->alias(
            static fn (string $capability): bool => $capability === 'manage_zw_polls'
        );

        $this->assertTrue($this->controller()->canManage($this->request()));
    }

    #[Test]
    public function reset_deletes_votes_and_rebuilds_zeroed_aggregate(): void
    {
        Functions\when('get_post')->justReturn($this->poll());
        $writes = [];
        Functions\when('update_post_meta')->alias(
            static function (int $id, string $key, mixed $value) use (&$writes): bool {
                $writes[] = [$id, $key, $value];
                return true;
            }
        );

        $result = $this->controller(deleted: 7, tally: [])->reset($this->request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $data = $result->get_data();
        $epoch_writes = array_values(array_filter(
            $writes,
            static fn (array $write): bool => $write[0] === self::POLL_ID
                && $write[1] === PollPostType::META_VOTE_EPOCH
        ));
        $this->assertSame([[self::POLL_ID, PollPostType::META_VOTE_EPOCH, 1]], $epoch_writes);
        $this->assertTrue($data['ok']);
        $this->assertSame(7, $data['deleted']);
        $this->assertArrayNotHasKey('voteEpoch', $data);
        $this->assertSame(0, $data['total']);
    }

    #[Test]
    public function reset_rejects_missing_poll(): void
    {
        Functions\when('get_post')->justReturn(null);

        $result = $this->controller()->reset($this->request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('poll_not_found', $result->get_error_code());
        $this->assertSame(404, $result->get_error_data()['status']);
    }

    #[Test]
    public function reset_rejects_wrong_post_type(): void
    {
        Functions\when('get_post')->justReturn($this->page());

        $result = $this->controller()->reset($this->request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('poll_not_found', $result->get_error_code());
    }
}
