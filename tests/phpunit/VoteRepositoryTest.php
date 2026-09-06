<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Vote\VoteRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use wpdb;

/**
 * Locks the anonymous dedup policy: cookie token only, IP hash for audit.
 */
final class VoteRepositoryTest extends TestCase
{
    private const POLL_ID = 42;
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';
    private const IP_HASH = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('current_time')->justReturn('2026-06-04 12:00:00');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Build a wpdb fake that records prepared SQL and insert payloads. */
    private function db(): wpdb
    {
        return new class extends wpdb {
            public string $lastQuery = '';
            /**
             * Last insert payload.
             *
             * @var array<string, mixed>
             */
            public array $lastInsert = [];

            public function __construct()
            {
                $this->prefix = 'wp_';
            }

            public function prepare(string $query, mixed ...$args): string
            {
                $this->lastQuery = $query;

                return $query;
            }

            public function get_var(string $query): mixed
            {
                return null;
            }

            public function insert(string $table, array $data, array $formats): int|false
            {
                $this->lastInsert = $data;

                return 1;
            }
        };
    }

    #[Test]
    public function dedup_keys_on_cookie_token_only(): void
    {
        $db = $this->db();
        (new VoteRepository($db))->exists(self::POLL_ID, self::TOKEN);

        $this->assertStringContainsString('cookie_token', $db->lastQuery);
        $this->assertStringNotContainsString('ip_hash', $db->lastQuery);
        $this->assertStringNotContainsString('user_id', $db->lastQuery);
    }

    #[Test]
    public function insert_persists_ip_hash_for_audit_without_user_id(): void
    {
        $db = $this->db();
        (new VoteRepository($db))->insert(self::POLL_ID, 'optA', self::IP_HASH, self::TOKEN);

        $this->assertSame(self::IP_HASH, $db->lastInsert['ip_hash']);
        $this->assertSame(self::TOKEN, $db->lastInsert['cookie_token']);
        $this->assertArrayNotHasKey('user_id', $db->lastInsert);
    }
}
