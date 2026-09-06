<?php
/**
 * Bootstraps plugin services.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll;

use ZuidWest\Poll\Admin\ClassicEditor;
use ZuidWest\Poll\Admin\DeleteGuard;
use ZuidWest\Poll\Admin\PollAdminColumns;
use ZuidWest\Poll\Admin\PollEditForm;
use ZuidWest\Poll\Admin\PollMetaBoxes;
use ZuidWest\Poll\Admin\SettingsPage;
use ZuidWest\Poll\Admin\UsageTracker;
use ZuidWest\Poll\Cli\Commands;
use ZuidWest\Poll\Frontend\Assets;
use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Rest\AdminController;
use ZuidWest\Poll\Rest\VoteController;
use ZuidWest\Poll\Shortcode\PollShortcode;
use ZuidWest\Poll\Support\Settings;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\IpHasher;
use ZuidWest\Poll\Vote\PollReset;
use ZuidWest\Poll\Vote\RateLimiter;
use ZuidWest\Poll\Vote\VoteRepository;

/**
 * Registers the plugin's runtime services.
 */
final class Plugin
{
    /**
     * Wires WordPress hooks and service collaborators.
     */
    public static function boot(): void
    {
        // File-only deployments skip activation; init keeps runtime paths on the current schema.
        Activation::registerHooks();
        add_action('init', [Activation::class, 'ensureInstalled']);

        (new PollPostType())->register();
        (new Assets())->register();
        (new PollShortcode())->register();

        global $wpdb;
        $repository = new VoteRepository($wpdb);
        $cache = new AggregateCache($repository);
        $resetter = new PollReset($repository, $cache);
        $settings = Settings::get();

        (new VoteController(
            new IpHasher(),
            new RateLimiter(
                $settings['rate_limit_enabled'],
                $settings['rate_limit_max'],
                $settings['rate_limit_window'],
            ),
            $repository,
            $cache,
        ))->register();
        (new AdminController($resetter))->register();

        // Admin UI hooks stay out of frontend and REST requests.
        if (is_admin()) {
            add_action('admin_enqueue_scripts', static function (string $hook): void {
                wp_register_style(
                    'zw-poll-admin',
                    ZW_POLL_URL . 'src/Admin/admin.css',
                    [],
                    ZW_POLL_VERSION
                );
                wp_register_script(
                    'zw-poll-admin',
                    ZW_POLL_URL . 'src/Admin/admin.js',
                    ['clipboard', 'wp-i18n'],
                    ZW_POLL_VERSION,
                    true
                );
                wp_set_script_translations(
                    'zw-poll-admin',
                    'zw-poll',
                    ZW_POLL_DIR . 'languages'
                );

                // The poll list table and poll edit screen share these assets:
                // meta-box buttons, the options repeater, and shortcode copy.
                $screen = get_current_screen();
                if ($screen
                    && $screen->post_type === PollPostType::POST_TYPE
                    && in_array($hook, ['edit.php', 'post.php', 'post-new.php'], true)
                ) {
                    wp_enqueue_style('zw-poll-admin');
                    wp_enqueue_script('zw-poll-admin');
                    if (in_array($hook, ['post.php', 'post-new.php'], true)) {
                        wp_enqueue_media();
                    }
                }
            }, 1);
            (new PollAdminColumns())->register();
            (new PollMetaBoxes())->register();
            (new PollEditForm())->register();
            (new ClassicEditor())->register();
            (new SettingsPage())->register();
        }

        // Usage and delete guards must also cover REST writes, where is_admin() is false.
        (new UsageTracker())->register();
        (new DeleteGuard($repository))->register();

        Commands::register($cache, $resetter);
    }
}
