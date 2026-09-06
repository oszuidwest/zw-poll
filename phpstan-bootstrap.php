<?php
/**
 * Define runtime constants for PHPStan without loading plugin bootstrap hooks.
 */

declare(strict_types=1);

if (!defined('ZW_POLL_VERSION')) {
    $zw_poll_main_file = __DIR__ . '/zw-poll.php';
    $zw_poll_header = file_get_contents($zw_poll_main_file);
    if (
        $zw_poll_header === false
        || !preg_match('/^[ \t*]*Version:[ \t]*(.+)$/mi', $zw_poll_header, $zw_poll_match)
    ) {
        throw new RuntimeException('Unable to read the ZuidWest Poll version from the plugin header.');
    }
    define('ZW_POLL_VERSION', trim($zw_poll_match[1]));
    unset($zw_poll_main_file, $zw_poll_header, $zw_poll_match);
}
defined('ZW_POLL_DIR') || define('ZW_POLL_DIR', __DIR__ . '/');
defined('ZW_POLL_URL') || define('ZW_POLL_URL', 'https://example.test/');
