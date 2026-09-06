<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Activation;
use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Rest\VoteController;
use ZuidWest\Poll\Support\Settings;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\IpHasher;
use ZuidWest\Poll\Vote\RateLimiter;
use ZuidWest\Poll\Vote\VoteRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use wpdb;

/**
 * Locks the public vote contract across controller, repository, and cache.
 *
 * Covers same-origin/no-nonce auth (#11), cold-cache first votes (#10),
 * validation (#14), and duplicate insert races (#1).
 */
final class VoteControllerTest extends TestCase
{
    private const POLL_ID = 42;
    private const ORIGIN = 'https://example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-06-04 12:00:00');
        $this->stubOptions();
        Functions\when('get_transient')->justReturn(0); // Keep limiter under cap.
        Functions\when('set_transient')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('has_filter')->justReturn(false);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $value
        );
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('home_url')->justReturn(self::ORIGIN . '/');
        Functions\when('is_ssl')->justReturn(false);
        Functions\when('headers_sent')->justReturn(true); // Skip real cookie writes.
        Functions\when('wp_generate_password')->justReturn(str_repeat('a', 32));
        Functions\when('get_post')->justReturn($this->validPoll());
        $this->stubMeta('open', $this->options());
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset(
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_CF_CONNECTING_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_REAL_IP']
        );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub plugin options used by Settings and IpHasher.
     *
     * @param array<string, mixed> $settings Stored settings option value.
     */
    private function stubOptions(array $settings = []): void
    {
        Functions\when('get_option')->alias(
            static function (string $option, mixed $default = '') use ($settings): mixed {
                return match ($option) {
                    Activation::IP_SALT_OPTION => 'test-salt',
                    Settings::OPTION => $settings,
                    default => $default,
                };
            }
        );
    }

    /** Build a controller over fake vote-store outcomes. */
    private function controller(
        bool $alreadyVoted = false,
        int|false $insertResult = 1,
        array $tally = [['option_id' => 'optA', 'c' => 1]],
        bool $duplicateRace = false
    ): VoteController {
        $db = new class($alreadyVoted, $insertResult, $tally, $duplicateRace) extends wpdb {
            private bool $inserted = false;

            public function __construct(
                private bool $alreadyVoted,
                private int|false $insertResult,
                private array $tally,
                private bool $duplicateRace
            ) {
                $this->prefix = 'wp_';
            }

            public function get_var(string $query): mixed
            {
                // Flip after insert to model a UNIQUE collision.
                if ($this->alreadyVoted || ($this->duplicateRace && $this->inserted)) {
                    return 1;
                }
                return null;
            }

            public function insert(string $table, array $data, array $formats): int|false
            {
                $this->inserted = true;
                return $this->insertResult;
            }

            public function get_results(string $query, string $output = ARRAY_A): array
            {
                return $this->tally;
            }
        };

        $repository = new VoteRepository($db);

        return new VoteController(
            new IpHasher(),
            new RateLimiter(),
            $repository,
            new AggregateCache($repository),
        );
    }

    #[Test]
    public function proxy_header_setting_uses_valid_cloudflare_ip_when_no_filter_exists(): void
    {
        $this->stubOptions(['proxy_header' => Settings::PROXY_HEADER_CF_CONNECTING_IP]);
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
        $db = $this->capturingDb();

        $result = $this->controllerWith($db)->vote($this->request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame($this->expectedIpHash('203.0.113.9'), $db->insertedIpHash);
    }

    #[Test]
    public function x_forwarded_for_uses_the_rightmost_address(): void
    {
        $this->stubOptions(['proxy_header' => Settings::PROXY_HEADER_X_FORWARDED_FOR]);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.11, 198.51.100.200';
        $db = $this->capturingDb();

        $result = $this->controllerWith($db)->vote($this->request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame($this->expectedIpHash('198.51.100.200'), $db->insertedIpHash);
    }

    #[Test]
    public function invalid_proxy_header_value_falls_back_to_remote_addr(): void
    {
        $this->stubOptions(['proxy_header' => Settings::PROXY_HEADER_X_REAL_IP]);
        $_SERVER['HTTP_X_REAL_IP'] = 'not-an-ip';
        $db = $this->capturingDb();

        $result = $this->controllerWith($db)->vote($this->request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame($this->expectedIpHash('198.51.100.10'), $db->insertedIpHash);
    }

    #[Test]
    public function client_ip_filter_wins_over_proxy_setting_even_at_priority_zero(): void
    {
        $this->stubOptions(['proxy_header' => Settings::PROXY_HEADER_CF_CONNECTING_IP]);
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
        Functions\when('has_filter')->alias(
            static fn (string $hook): int|false => $hook === 'zw_poll_client_ip' ? 0 : false
        );
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $hook === 'zw_poll_client_ip'
                ? '192.0.2.77'
                : $value
        );
        $db = $this->capturingDb();

        $result = $this->controllerWith($db)->vote($this->request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame($this->expectedIpHash('192.0.2.77'), $db->insertedIpHash);
    }

    #[Test]
    public function invalid_client_ip_filter_value_falls_back_to_remote_addr(): void
    {
        $this->stubOptions(['proxy_header' => Settings::PROXY_HEADER_CF_CONNECTING_IP]);
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
        Functions\when('has_filter')->alias(
            static fn (string $hook): int|false => $hook === 'zw_poll_client_ip' ? 0 : false
        );
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $hook === 'zw_poll_client_ip'
                ? 'not-an-ip'
                : $value
        );
        Functions\expect('_doing_it_wrong')->once();
        $db = $this->capturingDb();

        $result = $this->controllerWith($db)->vote($this->request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame($this->expectedIpHash('198.51.100.10'), $db->insertedIpHash);
    }

    private function validPoll(): WP_Post
    {
        $poll = new WP_Post();
        $poll->ID = self::POLL_ID;
        $poll->post_type = PollPostType::POST_TYPE;
        $poll->post_status = 'publish';

        return $poll;
    }

    /**
     * Return valid poll options for request validation.
     *
     * @return array<int, array{id: string, label: string}>
     */
    private function options(): array
    {
        return [
            ['id' => 'optA', 'label' => 'A'],
            ['id' => 'optB', 'label' => 'B'],
        ];
    }

    private function stubMeta(string $status, array $options, mixed $aggregate = '', int $voteEpoch = 0): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): mixed => match ($key) {
                PollPostType::META_STATUS => $status,
                PollPostType::META_OPTIONS => $options,
                PollPostType::META_AGGREGATE => $aggregate,
                PollPostType::META_VOTE_EPOCH => $voteEpoch,
                default => '',
            }
        );
    }

    private function request(
        ?string $origin = self::ORIGIN,
        ?string $referer = null,
        string $optionId = 'optA'
    ): WP_REST_Request {
        $req = new WP_REST_Request();
        if ($origin !== null) {
            $req->set_header('origin', $origin);
        }
        if ($referer !== null) {
            $req->set_header('referer', $referer);
        }
        $req->set_param('poll_id', self::POLL_ID);
        $req->set_param('option_id', $optionId);

        return $req;
    }

    /** Cold-cache first vote (#10). */

    #[Test]
    public function first_vote_on_fresh_poll_counts_as_exactly_one(): void
    {
        // No aggregate meta; one row appears after insert.
        $this->stubMeta('open', $this->options(), aggregate: '');

        $result = $this->controller(tally: [['option_id' => 'optA', 'c' => 1]])
            ->vote($this->request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $data = $result->get_data();
        $this->assertSame(1, $data['total']);
        $this->assertSame(['optA' => 1, 'optB' => 0], (array) $data['aggregate']);
    }

    #[Test]
    public function vote_increments_cached_aggregate_after_insert(): void
    {
        $this->stubMeta('open', $this->options(), aggregate: [
            'counts' => ['optA' => 2, 'optB' => 2],
            'total' => 4,
        ]);

        $result = $this->controller()->vote($this->request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $data = $result->get_data();
        $this->assertSame(5, $data['total']);
        $this->assertSame(['optA' => 3, 'optB' => 2], (array) $data['aggregate']);
    }

    #[Test]
    public function vote_sets_cookie_value_with_epoch_token_and_option_id(): void
    {
        $token = str_repeat('b', 32);
        $before = time();
        Functions\when('headers_sent')->justReturn(false);
        Functions\expect('setcookie')
            ->once()
            ->with(
                VoteController::COOKIE_PREFIX . self::POLL_ID,
                '0:' . $token . ':optA',
                \Mockery::on(static function (array $options) use ($before): bool {
                    return isset($options['expires'])
                        && is_int($options['expires'])
                        && $options['expires'] >= $before + YEAR_IN_SECONDS
                        && $options['expires'] <= time() + YEAR_IN_SECONDS
                        && ($options['path'] ?? null) === '/'
                        && ($options['samesite'] ?? null) === 'Lax'
                        && ($options['secure'] ?? null) === false
                        && ($options['httponly'] ?? null) === false
                        && !isset($options['domain']);
                })
            )
            ->andReturn(true);
        $req = $this->request();
        $req->set_param('token', $token);

        $result = $this->controllerWith($this->capturingDb())->vote($req);

        $this->assertInstanceOf(WP_REST_Response::class, $result);
    }

    #[Test]
    public function vote_response_projects_rebuild_fallback_to_visible_options(): void
    {
        $this->stubMeta('open', $this->options(), aggregate: [
            'counts' => ['optA' => 2, 'removed' => 99],
            'total' => 101,
        ]);
        Functions\when('update_post_meta')->justReturn(false);

        $result = $this->controller(tally: [
            ['option_id' => 'optA', 'c' => 3],
            ['option_id' => 'removed', 'c' => 99],
        ])->vote($this->request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $data = $result->get_data();
        $this->assertSame(3, $data['total']);
        $this->assertSame(['optA' => 3, 'optB' => 0], (array) $data['aggregate']);
    }

    /** Cache-safe same-origin auth without nonce (#11). */

    #[Test]
    public function vote_succeeds_same_origin_without_nonce(): void
    {
        $result = $this->controller()->vote($this->request(origin: self::ORIGIN));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(200, $result->get_status());
        $this->assertTrue($result->get_data()['ok']);
    }

    #[Test]
    public function vote_accepts_same_origin_via_referer_fallback(): void
    {
        $result = $this->controller()->vote(
            $this->request(origin: null, referer: self::ORIGIN . '/artikel/123')
        );

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(200, $result->get_status());
    }

    #[Test]
    public function vote_rejects_cross_origin(): void
    {
        $result = $this->controller()->vote($this->request(origin: 'https://evil.test'));

        $this->assertError($result, 'invalid_origin', 403);
    }

    #[Test]
    public function vote_rejects_missing_origin_and_referer(): void
    {
        $result = $this->controller()->vote($this->request(origin: null, referer: null));

        $this->assertError($result, 'invalid_origin', 403);
    }

    /** Full validation chain (#14). */

    #[Test]
    public function vote_rejected_when_rate_limited(): void
    {
        Functions\when('get_transient')->justReturn(Settings::RATE_LIMIT_MAX_DEFAULT);

        $result = $this->controller()->vote($this->request());

        $this->assertError($result, 'rate_limited', 429);
    }

    #[Test]
    public function vote_rejected_when_poll_missing(): void
    {
        Functions\when('get_post')->justReturn(null);

        $result = $this->controller()->vote($this->request());

        $this->assertError($result, 'poll_not_found', 404);
    }

    #[Test]
    public function vote_rejected_for_wrong_post_type(): void
    {
        $page = new WP_Post();
        $page->ID = self::POLL_ID;
        $page->post_type = 'page';
        $page->post_status = 'publish';
        Functions\when('get_post')->justReturn($page);

        $result = $this->controller()->vote($this->request());

        $this->assertError($result, 'poll_not_found', 404);
    }

    #[Test]
    public function vote_rejected_when_poll_not_published(): void
    {
        $draft = $this->validPoll();
        $draft->post_status = 'draft';
        Functions\when('get_post')->justReturn($draft);

        $result = $this->controller()->vote($this->request());

        $this->assertError($result, 'poll_not_found', 404);
    }

    #[Test]
    public function vote_rejected_when_poll_closed(): void
    {
        $this->stubMeta('closed', $this->options());

        $result = $this->controller()->vote($this->request());

        $this->assertError($result, 'poll_closed', 409);
    }

    #[Test]
    public function vote_rejected_for_unknown_option(): void
    {
        $result = $this->controller()->vote($this->request(optionId: 'does-not-exist'));

        $this->assertError($result, 'invalid_option', 400);
    }

    #[Test]
    public function vote_rejected_when_hardening_filter_denies_it(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $hook === 'zw_poll_vote_allowed' ? false : $value
        );

        $result = $this->controller()->vote($this->request());

        $this->assertError($result, 'vote_forbidden', 403);
    }

    #[Test]
    public function vote_rejected_when_hardening_filter_returns_truthy_non_true_value(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $hook === 'zw_poll_vote_allowed' ? 1 : $value
        );

        $result = $this->controller()->vote($this->request());

        $this->assertError($result, 'vote_forbidden', 403);
    }

    #[Test]
    public function vote_passes_through_hardening_filter_wp_error(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $hook === 'zw_poll_vote_allowed'
                ? new WP_Error('waf_challenge', 'Complete the challenge.', ['status' => 401])
                : $value
        );

        $result = $this->controller()->vote($this->request());

        $this->assertError($result, 'waf_challenge', 401);
        $this->assertSame('Complete the challenge.', $result->get_error_message());
    }

    #[Test]
    public function hardening_filter_wp_error_without_status_defaults_to_forbidden(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $hook === 'zw_poll_vote_allowed'
                ? new WP_Error('captcha_failed', 'Please complete the challenge.')
                : $value
        );
        Functions\expect('_doing_it_wrong')->once();

        $result = $this->controller()->vote($this->request());

        $this->assertError($result, 'captcha_failed', 403);
        $this->assertSame('Please complete the challenge.', $result->get_error_message());
    }

    #[Test]
    public function vote_rejected_when_already_voted(): void
    {
        $result = $this->controller(alreadyVoted: true)->vote($this->request());

        $this->assertError($result, 'already_voted', 409);
    }

    #[Test]
    public function duplicate_is_rejected_before_insert(): void
    {
        // The application check rejects duplicates before insert.
        $db = new class extends wpdb {
            public bool $insertCalled = false;

            public function __construct()
            {
                $this->prefix = 'wp_';
            }

            public function get_var(string $query): mixed
            {
                return 1; // A vote row already exists for this token.
            }

            public function insert(string $table, array $data, array $formats): int|false
            {
                $this->insertCalled = true;
                return 1; // A non-unique table would accept the duplicate.
            }
        };

        $result = $this->controllerWith($db)->vote($this->request());

        $this->assertError($result, 'already_voted', 409);
        $this->assertFalse($db->insertCalled);
    }

    #[Test]
    public function vote_returns_error_when_insert_fails(): void
    {
        $result = $this->controller(insertResult: false)->vote($this->request());

        $this->assertError($result, 'insert_failed', 500);
    }

    #[Test]
    public function concurrent_insert_collision_is_treated_as_already_voted(): void
    {
        // The UNIQUE index turns a same-token insert race into a duplicate vote.
        $result = $this->controller(insertResult: false, duplicateRace: true)
            ->vote($this->request());

        $this->assertError($result, 'already_voted', 409);
    }

    /**
     * Voter-token dedup identity (#1).
     *
     * Cookie token is the only dedup key; these cover non-random token paths.
     */

    #[Test]
    public function vote_persists_a_valid_client_token_as_the_dedup_key(): void
    {
        $token = str_repeat('b', 32);
        $db = $this->capturingDb();
        $req = $this->request();
        $req->set_param('token', $token);

        $this->controllerWith($db)->vote($req);

        $this->assertSame($token, $db->insertedToken);
    }

    #[Test]
    public function vote_falls_back_to_a_generated_token_when_the_body_token_is_malformed(): void
    {
        $db = $this->capturingDb();
        $req = $this->request();
        $req->set_param('token', 'too-short'); // Malformed token.

        $this->controllerWith($db)->vote($req);

        // Password generation is stubbed to 32 'a's in setUp.
        $this->assertSame(str_repeat('a', 32), $db->insertedToken);
    }

    #[Test]
    public function vote_prefers_an_existing_cookie_over_a_new_body_token(): void
    {
        $cookieToken = str_repeat('c', 32);
        $_COOKIE[VoteController::COOKIE_PREFIX . self::POLL_ID] = '0:' . $cookieToken . ':optA';
        $db = $this->capturingDb();
        $req = $this->request();
        $req->set_param('token', str_repeat('b', 32));

        $this->controllerWith($db)->vote($req);

        $this->assertSame($cookieToken, $db->insertedToken);
    }

    #[Test]
    public function vote_ignores_unprefixed_cookie_tokens(): void
    {
        $_COOKIE[VoteController::COOKIE_PREFIX . self::POLL_ID] = str_repeat('c', 32);
        $bodyToken = str_repeat('b', 32);
        $db = $this->capturingDb();
        $req = $this->request();
        $req->set_param('token', $bodyToken);

        $this->controllerWith($db)->vote($req);

        $this->assertSame($bodyToken, $db->insertedToken);
    }

    #[Test]
    public function vote_ignores_pre_0_13_cookie_tokens_without_option_id(): void
    {
        $_COOKIE[VoteController::COOKIE_PREFIX . self::POLL_ID] = '0:' . str_repeat('c', 32);
        $bodyToken = str_repeat('b', 32);
        $db = $this->capturingDb();
        $req = $this->request();
        $req->set_param('token', $bodyToken);

        $this->controllerWith($db)->vote($req);

        $this->assertSame($bodyToken, $db->insertedToken);
    }

    #[Test]
    public function vote_accepts_an_epoch_matched_cookie_token(): void
    {
        $cookieToken = str_repeat('c', 32);
        $_COOKIE[VoteController::COOKIE_PREFIX . self::POLL_ID] = '2:' . $cookieToken . ':optA';
        $this->stubMeta('open', $this->options(), voteEpoch: 2);
        $db = $this->capturingDb();
        $req = $this->request();
        $req->set_param('token', str_repeat('b', 32));

        $this->controllerWith($db)->vote($req);

        $this->assertSame($cookieToken, $db->insertedToken);
    }

    #[Test]
    public function vote_ignores_cookie_tokens_from_previous_reset_epochs(): void
    {
        $_COOKIE[VoteController::COOKIE_PREFIX . self::POLL_ID] = '0:' . str_repeat('c', 32) . ':optA';
        $this->stubMeta('open', $this->options(), voteEpoch: 1);
        $bodyToken = str_repeat('b', 32);
        $db = $this->capturingDb();
        $req = $this->request();
        $req->set_param('token', $bodyToken);

        $this->controllerWith($db)->vote($req);

        $this->assertSame($bodyToken, $db->insertedToken);
    }

    #[Test]
    public function vote_reports_already_voted_when_the_cookie_token_matches_a_row(): void
    {
        $cookieToken = str_repeat('c', 32);
        $_COOKIE[VoteController::COOKIE_PREFIX . self::POLL_ID] = '0:' . $cookieToken . ':optA';

        // Match only queries carrying the cookie token; avoid a token-blind fake.
        $db = new class($cookieToken) extends wpdb {
            public function __construct(private string $needle)
            {
                $this->prefix = 'wp_';
            }

            public function prepare(string $query, mixed ...$args): string
            {
                foreach ($args as $arg) {
                    $query = preg_replace('/%[idsf]/', (string) $arg, $query, 1) ?? $query;
                }
                return $query;
            }

            public function get_var(string $query): mixed
            {
                return str_contains($query, $this->needle) ? 1 : null;
            }
        };

        $result = $this->controllerWith($db)->vote($this->request());

        $this->assertError($result, 'already_voted', 409);
    }

    /** Build a controller over a caller-supplied vote store. */
    private function controllerWith(wpdb $db): VoteController
    {
        $repository = new VoteRepository($db);

        return new VoteController(
            new IpHasher(),
            new RateLimiter(),
            $repository,
            new AggregateCache($repository),
        );
    }

    /** Not-yet-voted store that records inserted identity data. */
    private function capturingDb(): wpdb
    {
        return new class extends wpdb {
            public ?string $insertedToken = null;
            public ?string $insertedIpHash = null;

            public function __construct()
            {
                $this->prefix = 'wp_';
            }

            public function get_var(string $query): mixed
            {
                return null; // No existing vote for this token.
            }

            public function insert(string $table, array $data, array $formats): int|false
            {
                $this->insertedToken = (string) $data['cookie_token'];
                $this->insertedIpHash = (string) $data['ip_hash'];
                return 1;
            }

            public function get_results(string $query, string $output = ARRAY_A): array
            {
                return [['option_id' => 'optA', 'c' => 1]];
            }
        };
    }

    private function assertError(mixed $result, string $code, int $status): void
    {
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame($code, $result->get_error_code());
        $this->assertSame($status, $result->get_error_data()['status']);
    }

    private function expectedIpHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, 'test-salt');
    }
}
