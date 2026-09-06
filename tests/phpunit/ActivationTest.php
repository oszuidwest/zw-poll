<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use wpdb;
use ZuidWest\Poll\Activation;
use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Support\Capabilities;

/**
 * Covers installation, schema validation, and multisite provisioning.
 */
final class ActivationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Sites without polls make the total visibility migration a no-op.
        Functions\when('update_meta_cache')->justReturn(false);
        Functions\when('add_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mockDbVersion(string|false $version): void
    {
        Functions\when('get_option')->alias(
            static fn (string $option, mixed $default = false): mixed => $option === Activation::DB_VERSION_OPTION
                ? $version
                : $default
        );
    }

    /**
     * Builds a wpdb fake with queued unique-index probe results.
     *
     * @param list<string|null>       $indexNonUnique Non_unique values; 0 means unique.
     * @param list<list<int|string>>  $pollBatches    Poll IDs returned by successive migration queries.
     * @param string                  $pollQueryError Database error exposed by migration queries.
     * @param \ArrayObject<int, string>|null $pollQueries Captured migration queries.
     */
    private function wpdb(
        array $indexNonUnique = [],
        array $pollBatches = [],
        string $pollQueryError = '',
        ?\ArrayObject $pollQueries = null
    ): wpdb
    {
        return new class($indexNonUnique, $pollBatches, $pollQueryError, $pollQueries) extends wpdb {
            private int $pollCutoff = 0;

            /**
             * @param list<string|null> $indexNonUnique Non_unique values.
             * @param list<list<int|string>> $pollBatches Poll ID batches.
             */
            public function __construct(
                private array $indexNonUnique,
                private array $pollBatches,
                private string $pollQueryError,
                private ?\ArrayObject $pollQueries
            ) {
                $this->prefix = 'wp_';
                foreach ($pollBatches as $batch) {
                    foreach ($batch as $poll_id) {
                        $this->pollCutoff = max($this->pollCutoff, (int) $poll_id);
                    }
                }
            }

            public function prepare(string $query, mixed ...$args): string
            {
                foreach ($args as $arg) {
                    $query = (string) preg_replace('/%[ids]/', (string) $arg, $query, 1);
                }

                return $query;
            }

            public function get_var(string $query): mixed
            {
                if (str_contains($query, 'information_schema.STATISTICS')) {
                    return array_shift($this->indexNonUnique);
                }

                if (str_contains($query, 'COALESCE(MAX(ID), 0)')) {
                    $this->last_error = '';
                    return (string) $this->pollCutoff;
                }

                return null;
            }

            public function get_col(string $query): array
            {
                $this->pollQueries?->append($query);
                $this->last_error = $this->pollQueryError;

                return array_shift($this->pollBatches) ?? [];
            }

            public function get_charset_collate(): string
            {
                return 'DEFAULT CHARACTER SET utf8mb4';
            }
        };
    }

    #[Test]
    public function skips_installation_when_database_version_is_current(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb();
        $this->mockDbVersion(Activation::DB_VERSION);
        Functions\expect('dbDelta')->never();
        Functions\expect('update_option')->never();

        Activation::ensureInstalled();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function installs_the_canonical_votes_table_and_stores_its_version(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb(['0']);
        $this->mockDbVersion(false);
        Functions\expect('dbDelta')
            ->once()
            ->with(\Mockery::on(static function (string $schema): bool {
                return str_contains($schema, 'CREATE TABLE wp_zw_poll_votes')
                    && str_contains($schema, 'UNIQUE KEY poll_cookie_token (poll_id, cookie_token)')
                    && str_contains($schema, 'DEFAULT CHARACTER SET utf8mb4');
            }));
        Functions\expect('update_option')
            ->once()
            ->with(Activation::DB_VERSION_OPTION, Activation::DB_VERSION, true)
            ->andReturn(true);

        Activation::ensureInstalled();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function retries_when_the_required_unique_index_is_missing(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb([null]);
        $this->mockDbVersion(false);
        Functions\when('dbDelta')->justReturn([]);
        Functions\expect('update_option')->never();
        Functions\expect('error_log')
            ->once()
            ->with(\Mockery::on(
                static fn (string $message): bool => str_contains($message, 'unique cookie index is missing')
                    && str_contains($message, 'wp_zw_poll_votes')
            ))
            ->andReturn(true);

        Activation::ensureInstalled();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function logs_when_the_database_version_cannot_be_stored(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb(['0']);
        $this->mockDbVersion(false);
        Functions\when('dbDelta')->justReturn([]);
        Functions\expect('update_option')
            ->once()
            ->with(Activation::DB_VERSION_OPTION, Activation::DB_VERSION, true)
            ->andReturn(false);
        Functions\expect('error_log')
            ->once()
            ->with(\Mockery::on(
                static fn (string $message): bool => str_contains($message, 'failed to store database version')
            ))
            ->andReturn(true);

        Activation::ensureInstalled();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function upgrade_migrates_the_legacy_total_toggle_across_all_statuses_before_storing_the_version(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb(['0'], [[11, 12, 13, 14, 15]]);
        $this->mockDbVersion(false);
        Functions\when('dbDelta')->justReturn([]);
        $legacy = [11 => '1', 12 => '', 14 => '1'];
        $stored = [14 => 'show', 15 => 'corrupt'];
        $deleted = [];
        Functions\when('metadata_exists')->alias(
            static fn (string $type, int $id, string $key): bool => match ($key) {
                PollPostType::META_TOTAL_VISIBILITY => array_key_exists($id, $stored),
                PollPostType::META_HIDE_TOTAL => array_key_exists($id, $legacy),
                default => false,
            }
        );
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): mixed => $key === PollPostType::META_TOTAL_VISIBILITY
                ? ($stored[$id] ?? '')
                : ($legacy[$id] ?? '')
        );
        Functions\when('update_post_meta')->alias(
            static function (int $id, string $key, string $value) use (&$stored): int {
                $stored[$id] = $value;
                return $id;
            }
        );
        Functions\when('delete_post_meta')->alias(
            static function (int $id, string $key) use (&$deleted): bool {
                $deleted[] = [$id, $key];
                return true;
            }
        );
        Functions\expect('update_option')
            ->once()
            ->with(Activation::DB_VERSION_OPTION, Activation::DB_VERSION, true)
            ->andReturn(true);

        Activation::ensureInstalled();

        // Existing rows are left alone; the read path normalizes corrupt values.
        $this->assertSame([
            14 => 'show',
            15 => 'corrupt',
            11 => 'hide',
            12 => 'show',
            13 => 'show',
        ], $stored);
        $this->assertSame([
            [11, PollPostType::META_HIDE_TOTAL],
            [12, PollPostType::META_HIDE_TOTAL],
            [14, PollPostType::META_HIDE_TOTAL],
        ], $deleted);
    }

    #[Test]
    public function failed_total_visibility_write_keeps_legacy_meta_and_retries_later(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb(['0'], [[42]]);
        $this->mockDbVersion(false);
        Functions\when('dbDelta')->justReturn([]);
        Functions\when('metadata_exists')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(false);
        Functions\expect('delete_post_meta')->never();
        Functions\expect('update_option')->never();
        Functions\expect('error_log')
            ->once()
            ->with(\Mockery::on(
                static fn (string $message): bool => str_contains($message, 'total visibility migration')
            ))
            ->andReturn(true);

        Activation::ensureInstalled();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function failed_legacy_meta_deletion_does_not_store_the_database_version(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb(['0'], [[42]]);
        $this->mockDbVersion(false);
        Functions\when('dbDelta')->justReturn([]);
        Functions\when('metadata_exists')->alias(
            static fn (string $type, int $id, string $key): bool => $key === PollPostType::META_HIDE_TOTAL
        );
        Functions\when('get_post_meta')->justReturn('1');
        Functions\when('update_post_meta')->justReturn(42);
        Functions\expect('delete_post_meta')
            ->once()
            ->with(42, PollPostType::META_HIDE_TOTAL)
            ->andReturn(false);
        Functions\expect('update_option')->never();
        Functions\expect('error_log')
            ->once()
            ->with(\Mockery::on(
                static fn (string $message): bool => str_contains($message, 'total visibility migration')
            ))
            ->andReturn(true);

        Activation::ensureInstalled();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function migration_resumes_from_a_persisted_id_cursor_before_storing_the_version(): void
    {
        $queries = new \ArrayObject();
        $GLOBALS['wpdb'] = $this->wpdb(
            ['0', '0'],
            [range(1, 100), [101]],
            pollQueries: $queries
        );
        Functions\when('dbDelta')->justReturn([]);
        Functions\when('metadata_exists')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        $options = [
            Activation::DB_VERSION_OPTION => false,
            Activation::TOTAL_VISIBILITY_CURSOR_OPTION => 0,
        ];
        Functions\when('get_option')->alias(
            static function (string $option, mixed $default = false) use (&$options): mixed {
                return $options[$option] ?? $default;
            }
        );
        Functions\when('update_option')->alias(
            static function (string $option, mixed $value) use (&$options): bool {
                $options[$option] = $value;
                return true;
            }
        );
        Functions\when('add_option')->alias(
            static function (string $option, mixed $value) use (&$options): bool {
                if (array_key_exists($option, $options)) {
                    return false;
                }
                $options[$option] = $value;
                return true;
            }
        );
        Functions\when('delete_option')->alias(
            static function (string $option) use (&$options): bool {
                unset($options[$option]);
                return true;
            }
        );

        Activation::ensureInstalled();

        $this->assertSame(100, $options[Activation::TOTAL_VISIBILITY_CURSOR_OPTION]);
        $this->assertSame(101, $options[Activation::TOTAL_VISIBILITY_CUTOFF_OPTION]);
        $this->assertFalse($options[Activation::DB_VERSION_OPTION]);

        Activation::ensureInstalled();

        $this->assertSame(Activation::DB_VERSION, $options[Activation::DB_VERSION_OPTION]);
        $this->assertArrayNotHasKey(Activation::TOTAL_VISIBILITY_CURSOR_OPTION, $options);
        $this->assertArrayNotHasKey(Activation::TOTAL_VISIBILITY_CUTOFF_OPTION, $options);
        $this->assertStringContainsString('ID > 0 AND ID <= 101 ORDER BY ID ASC LIMIT 100', $queries[0]);
        $this->assertStringContainsString('ID > 100 AND ID <= 101 ORDER BY ID ASC LIMIT 100', $queries[1]);
    }

    #[Test]
    public function migration_query_failure_does_not_store_the_version(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb(['0'], [[]], 'database unavailable');
        $this->mockDbVersion(false);
        Functions\when('dbDelta')->justReturn([]);
        Functions\expect('update_option')->never();
        Functions\expect('error_log')
            ->once()
            ->with(\Mockery::on(
                static fn (string $message): bool => str_contains($message, 'migration query failed')
                    && str_contains($message, 'database unavailable')
            ))
            ->andReturn(true);

        Activation::ensureInstalled();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function registers_site_initialization_hook_on_multisite(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Actions\expectAdded('wp_initialize_site')
            ->once()
            ->with([Activation::class, 'activateInitializedSite'], 10, 1);

        Activation::registerHooks();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function does_not_register_site_initialization_hook_on_single_site(): void
    {
        Functions\when('is_multisite')->justReturn(false);
        Functions\expect('add_action')->never();

        Activation::registerHooks();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function provisions_new_multisite_site_when_plugin_is_network_active(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb(['0']);
        $admin_role = $this->role();
        $editor_role = $this->role();
        $switched_sites = [];
        $restored = 0;
        Functions\when('plugin_basename')->returnArg();
        Functions\expect('is_plugin_active_for_network')
            ->once()
            ->with(ZW_POLL_DIR . 'zw-poll.php')
            ->andReturn(true);
        $this->mockDbVersion(false);
        Functions\expect('dbDelta')->once();
        Functions\expect('update_option')
            ->once()
            ->with(Activation::DB_VERSION_OPTION, Activation::DB_VERSION, true)
            ->andReturn(true);
        Functions\when('switch_to_blog')->alias(static function (int $site_id) use (&$switched_sites): void {
            $switched_sites[] = $site_id;
        });
        Functions\when('restore_current_blog')->alias(static function () use (&$restored): void {
            $restored++;
        });
        Functions\when('get_role')->alias(static fn (string $name): mixed => match ($name) {
            'administrator' => $admin_role,
            'editor' => $editor_role,
            default => null,
        });

        Activation::activateInitializedSite((object) ['blog_id' => 123]);

        $this->assertSame([123], $switched_sites);
        $this->assertSame(1, $restored);
        $this->assertSame(Capabilities::PRIMITIVE_CAPS, $admin_role->caps);
        $this->assertSame(Capabilities::PRIMITIVE_CAPS, $editor_role->caps);
    }

    #[Test]
    public function ignores_new_multisite_site_when_plugin_is_not_network_active(): void
    {
        Functions\when('plugin_basename')->returnArg();
        Functions\expect('is_plugin_active_for_network')
            ->once()
            ->with(ZW_POLL_DIR . 'zw-poll.php')
            ->andReturn(false);
        Functions\expect('switch_to_blog')->never();
        Functions\expect('restore_current_blog')->never();

        Activation::activateInitializedSite((object) ['blog_id' => 123]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function ignores_malformed_new_site_payload(): void
    {
        Functions\when('plugin_basename')->returnArg();
        Functions\when('is_plugin_active_for_network')->justReturn(true);
        Functions\expect('switch_to_blog')->never();
        Functions\expect('restore_current_blog')->never();

        Activation::activateInitializedSite(null);
        Activation::activateInitializedSite((object) []);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function logs_and_restores_when_new_site_provisioning_fails(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb();
        $switched_sites = [];
        $restored = 0;
        Functions\when('plugin_basename')->returnArg();
        Functions\when('is_plugin_active_for_network')->justReturn(true);
        $this->mockDbVersion(Activation::DB_VERSION);
        Functions\when('switch_to_blog')->alias(static function (int $site_id) use (&$switched_sites): void {
            $switched_sites[] = $site_id;
        });
        Functions\when('restore_current_blog')->alias(static function () use (&$restored): void {
            $restored++;
        });
        Functions\when('get_role')->alias(static function (): never {
            throw new \RuntimeException('boom');
        });
        Functions\expect('error_log')
            ->once()
            ->with(\Mockery::on(
                static fn (string $message): bool => str_contains($message, 'new site 123')
                    && str_contains($message, 'boom')
                    && str_contains($message, 'capabilities were not granted')
            ))
            ->andReturn(true);

        Activation::activateInitializedSite((object) ['blog_id' => 123]);

        $this->assertSame([123], $switched_sites);
        $this->assertSame(1, $restored);
    }

    #[Test]
    public function restores_current_blog_when_network_activation_fails(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb();
        $switched_sites = [];
        $restored = 0;
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('get_sites')->justReturn([123]);
        $this->mockDbVersion(Activation::DB_VERSION);
        Functions\when('switch_to_blog')->alias(static function (int $site_id) use (&$switched_sites): void {
            $switched_sites[] = $site_id;
        });
        Functions\when('restore_current_blog')->alias(static function () use (&$restored): void {
            $restored++;
        });
        Functions\when('get_role')->alias(static function (): never {
            throw new \RuntimeException('boom');
        });

        try {
            Activation::activate(true);
            $this->fail('Expected network activation to rethrow provisioning failures.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertSame([123], $switched_sites);
            $this->assertSame(1, $restored);
        }
    }

    private function role(): object
    {
        return new class {
            /**
             * Granted capabilities.
             *
             * @var list<string>
             */
            public array $caps = [];

            public function add_cap(string $cap): void
            {
                $this->caps[] = $cap;
            }
        };
    }
}
