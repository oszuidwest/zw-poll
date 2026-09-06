<?php
/**
 * Handles plugin activation and database installation.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll;

use ZuidWest\Poll\Support\Capabilities;

/**
 * Installs the votes table and plugin capabilities.
 */
final class Activation
{
    public const DB_VERSION = \ZW_POLL_VERSION;
    public const DB_VERSION_OPTION = 'zw_poll_db_version';
    public const IP_SALT_OPTION = 'zw_poll_ip_salt';
    public const VOTES_TABLE = 'zw_poll_votes';

    /**
     * Registers runtime lifecycle hooks.
     */
    public static function registerHooks(): void
    {
        if (is_multisite()) {
            add_action('wp_initialize_site', [self::class, 'activateInitializedSite']);
        }
    }

    /**
     * Activates the plugin for the current site or network.
     *
     * @param bool $network_wide Whether activation is network-wide.
     */
    public static function activate(bool $network_wide = false): void
    {
        if ($network_wide && is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
                switch_to_blog((int) $site_id);
                try {
                    self::activateSite();
                } finally {
                    restore_current_blog();
                }
            }
            return;
        }
        self::activateSite();
    }

    /**
     * Activates plugin-owned per-site state for a newly initialized multisite site.
     *
     * @param mixed $new_site Site object passed by wp_initialize_site.
     */
    public static function activateInitializedSite(mixed $new_site): void
    {
        if (!self::isNetworkActive()) {
            return;
        }

        $site_id = is_object($new_site) && isset($new_site->blog_id)
            ? (int) $new_site->blog_id
            : 0;
        if ($site_id <= 0) {
            return;
        }

        switch_to_blog($site_id);
        try {
            self::activateSite();
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Optional per-site provisioning must not abort core site creation.
            error_log(sprintf(
                'zw-poll: provisioning failed for new site %d in wp_initialize_site (%s). '
                . 'Votes table installation retries on the next request; poll capabilities were not granted.',
                $site_id,
                $e->getMessage()
            ));
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Activates the plugin for the current site.
     */
    private static function activateSite(): void
    {
        self::ensureInstalled();
        Capabilities::grantToDefaultRoles();
        // IpHasher seeds the salt lazily, including installs that bypass activation.
    }

    /**
     * Checks whether this plugin is network-active.
     */
    private static function isNetworkActive(): bool
    {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename(ZW_POLL_DIR . 'zw-poll.php'));
    }

    /**
     * Creates the votes table with the canonical schema.
     *
     * @param string $table Fully-qualified votes table name.
     */
    public static function createVotesTable(string $table): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta(self::votesTableSchema($table));
    }

    /**
     * Returns the canonical dbDelta() statement for the votes table.
     *
     * The statement follows the dbDelta() rules: lowercase types, two spaces
     * after PRIMARY KEY, one column/index per line, no backticks. Deduplication
     * is based on the anonymous cookie token.
     *
     * @param string $table Fully-qualified votes table name.
     */
    private static function votesTableSchema(string $table): string
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  poll_id bigint(20) unsigned NOT NULL,
  option_id char(36) NOT NULL,
  ip_hash char(64) NOT NULL,
  cookie_token char(32) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY poll_ip_hash (poll_id, ip_hash),
  UNIQUE KEY poll_cookie_token (poll_id, cookie_token),
  KEY created_at (created_at)
) {$charset_collate};";
    }

    /**
     * Ensures the current site's votes table is installed.
     *
     * The version is stored only after the table and its unique cookie index
     * are verified, so a partial failure retries on the next request.
     */
    public static function ensureInstalled(): void
    {
        if ((string) get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::VOTES_TABLE;
        self::createVotesTable($table);

        if (!self::cookieIndexIsUnique($table)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Installation failures need server-side diagnostics.
            error_log(sprintf(
                'zw-poll: database installation %s is incomplete for %s; '
                . 'the required unique cookie index is missing. Installation retries on the next request.',
                self::DB_VERSION,
                $table
            ));
            return;
        }

        // Autoload this scalar: init runs this on every request, and
        // one alloptions read is cheaper than a standalone query without persistent object cache.
        if (!update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Installation failures need server-side diagnostics.
            error_log(sprintf(
                'zw-poll: failed to store database version %s; installation retries on the next request.',
                self::DB_VERSION
            ));
        }
    }

    /**
     * Checks whether the required cookie-token index exists and is unique.
     *
     * @param string $table Fully-qualified votes table name.
     * @phpstan-impure Reads live schema state after DDL changes.
     */
    private static function cookieIndexIsUnique(string $table): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema validation after table installation.
        $non_unique = $wpdb->get_var($wpdb->prepare(
            'SELECT Non_unique FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s LIMIT 1',
            $table,
            'poll_cookie_token'
        ));
        return $non_unique !== null && (int) $non_unique === 0;
    }
}
