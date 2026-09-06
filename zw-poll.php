<?php
/**
 * Plugin Name:       ZuidWest Poll
 * Plugin URI:        https://github.com/oszuidwest/zw-poll
 * Description:       Poll voor WordPress: laat lezers stemmen via een shortcode in artikelen en pagina's.
 * Version:           0.1.1
 * Requires at least: 6.9
 * Requires PHP:      8.3
 * Author:            Streekomroep ZuidWest
 * Author URI:        https://www.zuidwesttv.nl/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zw-poll
 * Domain Path:       /languages
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$zw_poll_plugin_data = get_file_data(__FILE__, ['Version' => 'Version']);
define('ZW_POLL_VERSION', $zw_poll_plugin_data['Version']);
unset($zw_poll_plugin_data);
define('ZW_POLL_DIR', plugin_dir_path(__FILE__));
define('ZW_POLL_URL', plugin_dir_url(__FILE__));

$zw_poll_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($zw_poll_autoload)) {
    require_once $zw_poll_autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'ZuidWest\\Poll\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }
    });
}

register_activation_hook(__FILE__, [\ZuidWest\Poll\Activation::class, 'activate']);
register_deactivation_hook(__FILE__, [\ZuidWest\Poll\Activation::class, 'deactivate']);

add_action('init', static function (): void {
    load_plugin_textdomain('zw-poll', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('plugins_loaded', [\ZuidWest\Poll\Plugin::class, 'boot']);
