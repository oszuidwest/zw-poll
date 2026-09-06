<?php
/**
 * Registers frontend poll assets.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Frontend;

/**
 * Registers the poll stylesheet and the Interactivity API view module.
 *
 * The view module ships unbundled: WordPress resolves its
 * `@wordpress/interactivity` import through the script-module import map, so
 * no build step is required for the runtime.
 */
final class Assets
{
    public const STYLE_HANDLE = 'zw-poll';
    public const MODULE_ID = 'zw-poll-view';

    /** Registers asset hooks. */
    public function register(): void
    {
        add_action('init', [$this, 'registerAssets']);
    }

    /** Registers the stylesheet and view module; the shortcode enqueues them on demand. */
    public function registerAssets(): void
    {
        wp_register_style(
            self::STYLE_HANDLE,
            ZW_POLL_URL . 'src/Frontend/style.css',
            [],
            ZW_POLL_VERSION
        );
        wp_register_script_module(
            self::MODULE_ID,
            ZW_POLL_URL . 'src/Frontend/view.js',
            [['id' => '@wordpress/interactivity', 'import' => 'static']],
            ZW_POLL_VERSION
        );
    }

    /** Enqueues the frontend assets for a rendered poll. */
    public static function enqueue(): void
    {
        // Shortcodes render after wp_head; WordPress prints late styles in the footer.
        wp_enqueue_style(self::STYLE_HANDLE);
        wp_enqueue_script_module(self::MODULE_ID);
    }
}
