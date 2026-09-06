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
     * @param list<string|null> $indexNonUnique Non_unique values; 0 means unique.
     */
    private function wpdb(array $indexNonUnique = []): wpdb
    {
        return new class($indexNonUnique) extends wpdb {
            /** @param list<string|null> $indexNonUnique Non_unique values. */
            public function __construct(private array $indexNonUnique)
            {
                $this->prefix = 'wp_';
            }

            public function get_var(string $query): mixed
            {
                return array_shift($this->indexNonUnique);
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
