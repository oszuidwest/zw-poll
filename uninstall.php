<?php
/**
 * Remove plugin-owned data on uninstall.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Load the classes that own cleanup constants without booting the plugin.
$zw_poll_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($zw_poll_autoload)) {
    require_once $zw_poll_autoload;
} else {
    // Avoid Activation::DB_VERSION because ZW_POLL_VERSION is unavailable.
    require_once __DIR__ . '/src/Support/Capabilities.php';
    require_once __DIR__ . '/src/Activation.php';
    require_once __DIR__ . '/src/Support/Settings.php';
}

$zw_poll_cleanup = static function (): void {
    if (!\ZuidWest\Poll\Support\Settings::get()['delete_data_on_uninstall']) {
        return;
    }

    global $wpdb;

    $table = $wpdb->prefix . \ZuidWest\Poll\Activation::VOTES_TABLE;
    // %i binds the table identifier.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drop plugin-owned table on uninstall.
    $zw_poll_drop_result = $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
    if ($zw_poll_drop_result === false) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Uninstall failures need server-side diagnostics.
        error_log(sprintf('zw-poll: failed to drop votes table %s during uninstall.', $table));
    }

    delete_option(\ZuidWest\Poll\Activation::DB_VERSION_OPTION);
    delete_option(\ZuidWest\Poll\Activation::IP_SALT_OPTION);
    delete_option(\ZuidWest\Poll\Support\Settings::OPTION);

    \ZuidWest\Poll\Support\Capabilities::revokeFromDefaultRoles();
};

// Keep poll CPT posts; admins delete editorial content explicitly.

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $zw_poll_site_id) {
        switch_to_blog((int) $zw_poll_site_id);
        $zw_poll_cleanup();
        restore_current_blog();
    }
} else {
    $zw_poll_cleanup();
}
