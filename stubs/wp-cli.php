<?php
/**
 * PHPStan declarations for the WP-CLI surface used by this plugin.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace {
    /** PHPStan declaration for WP-CLI's command runner. */
    final class WP_CLI
    {
        /**
         * @param callable|object|string $callable Command implementation.
         * @param array<string, mixed>   $args     Registration arguments.
         */
        public static function add_command(string $name, callable|object|string $callable, array $args = []): bool
        {
            return true;
        }

        /** Writes a success message. */
        public static function success(string $message): void {}

        /** Writes an informational message. */
        public static function log(string $message): void {}

        /**
         * @param array<string, string> $assoc_args Associative command arguments.
         */
        public static function confirm(string $question, array $assoc_args = []): void {}

        /** Stops command execution with an error. */
        public static function error(string $message, bool $exit = true): void {}
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<int, array<string, int|string>> $items  Output rows.
     * @param array<int, string>                    $fields Fields to display.
     */
    function format_items(string $format, array $items, array $fields): void {}
}
