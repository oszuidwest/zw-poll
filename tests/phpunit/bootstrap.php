<?php

declare(strict_types=1);

// Constants needed while loading production classes without WordPress.
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);
defined('YEAR_IN_SECONDS') || define('YEAR_IN_SECONDS', 31536000);
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');

// Plugin constants normally set by zw-poll.php.
if (!defined('ZW_POLL_VERSION')) {
    $zw_poll_main_file = dirname(__DIR__, 2) . '/zw-poll.php';
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
defined('ZW_POLL_DIR') || define('ZW_POLL_DIR', dirname(__DIR__, 2) . '/');
defined('ZW_POLL_URL') || define('ZW_POLL_URL', 'https://example.test/');

// Deterministic WP utility stubs used by production classes under test.
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed // phpcs:ignore -- WordPress runtime stub.
    {
        return parse_url($url, $component);
    }
}
if (!function_exists('wp_is_uuid')) {
    function wp_is_uuid(mixed $uuid, ?int $version = null): bool // phpcs:ignore -- WordPress runtime stub.
    {
        if (!is_string($uuid)) {
            return false;
        }
        $regex = 4 === $version
            ? '/^[0-9a-f]{8}\-[0-9a-f]{4}\-4[0-9a-f]{3}\-[89ab][0-9a-f]{3}\-[0-9a-f]{12}$/i'
            : '/^[0-9a-f]{8}\-[0-9a-f]{4}\-[0-9a-f]{4}\-[0-9a-f]{4}\-[0-9a-f]{12}$/i';
        return (bool) preg_match($regex, $uuid);
    }
}
if (!function_exists('absint')) {
    function absint(mixed $maybeint): int // phpcs:ignore -- WordPress runtime stub.
    {
        return abs((int) $maybeint);
    }
}
if (!function_exists('rest_sanitize_boolean')) {
    function rest_sanitize_boolean(mixed $value): bool // phpcs:ignore -- WordPress runtime stub.
    {
        if (is_string($value)) {
            $normalized = strtolower($value);
            return !in_array($normalized, ['false', '0', ''], true);
        }

        return (bool) $value;
    }
}
if (!function_exists('get_shortcode_regex')) {
    /**
     * Faithful port of WordPress core's get_shortcode_regex(); UsageTracker
     * relies on the exact capture-group layout (1: escape open, 3: attrs,
     * 6: escape close).
     *
     * @param array<int, string> $tagnames Shortcode tags to match.
     */
    function get_shortcode_regex(array $tagnames): string // phpcs:ignore -- WordPress runtime stub.
    {
        $tagregexp = implode('|', array_map('preg_quote', $tagnames));

        return '\\[(\\[?)'
            . "($tagregexp)"
            . '(?![\\w-])'
            . '([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)'
            . '(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)'
            . '(\\]?)';
    }
}
if (!function_exists('shortcode_parse_atts')) {
    /**
     * Simplified port of WordPress core's shortcode_parse_atts() covering
     * only the named double-quoted, single-quoted, and unquoted attribute
     * forms the tests exercise (core also parses positional attributes).
     *
     * @return array<string, string>|string
     */
    function shortcode_parse_atts(string $text): array|string // phpcs:ignore -- WordPress runtime stub.
    {
        $atts = [];
        $pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)'
            . '|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)'
            . '|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)/';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (!empty($m[1])) {
                    $atts[strtolower($m[1])] = stripcslashes($m[2]);
                } elseif (!empty($m[3])) {
                    $atts[strtolower($m[3])] = stripcslashes($m[4]);
                } elseif (!empty($m[5])) {
                    $atts[strtolower($m[5])] = stripcslashes($m[6]);
                }
            }
            return $atts;
        }

        return ltrim($text);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false // phpcs:ignore -- WordPress runtime stub.
    {
        return json_encode($data, $options, $depth);
    }
}

// Minimal wpdb stub for repository tests without WordPress loaded.
if (!class_exists('wpdb', false)) {
    // @phpstan-ignore-next-line
    class wpdb // phpcs:ignore -- WordPress runtime stub.
    {
        public string $prefix = 'wp_';
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';

        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }

        public function get_var(string $query): mixed
        {
            return null;
        }

        /**
         * Return no rows by default.
         *
         * @param string $query SQL query.
         * @return list<string>
         */
        public function get_col(string $query): array
        {
            return [];
        }

        /**
         * Return no rows by default.
         *
         * @param string $query  SQL query.
         * @param string $output Requested output type.
         * @return array<int, array<string, mixed>>
         */
        public function get_results(string $query, string $output = ARRAY_A): array
        {
            return [];
        }

        /**
         * Pretend inserts succeed by default.
         *
         * @param string               $table   Table name.
         * @param array<string, mixed> $data    Insert data.
         * @param array<int, string>   $formats Value formats.
         */
        public function insert(string $table, array $data, array $formats): int|false
        {
            return 1;
        }

        /**
         * Pretend deletes affect no rows by default.
         *
         * @param string               $table   Table name.
         * @param array<string, mixed> $where   Where clause data.
         * @param array<int, string>   $formats Value formats.
         */
        public function delete(string $table, array $where, array $formats): int|false
        {
            return 0;
        }

        public function get_charset_collate(): string
        {
            return '';
        }
    }
}

// Minimal REST stubs for controller tests without WordPress loaded.
if (!class_exists('WP_REST_Server', false)) {
    class WP_REST_Server // phpcs:ignore -- WordPress runtime stub.
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
    }
}

if (!class_exists('WP_REST_Request', false)) {
    class WP_REST_Request // phpcs:ignore -- WordPress runtime stub.
    {
        /**
         * Request params.
         *
         * @var array<string, mixed>
         */
        private array $params = [];
        /**
         * Request headers.
         *
         * @var array<string, string>
         */
        private array $headers = [];

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Response', false)) {
    class WP_REST_Response // phpcs:ignore -- WordPress runtime stub.
    {
        public function __construct(private mixed $data = null, private int $status = 200) {}

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (!class_exists('WP_Error', false)) {
    class WP_Error // phpcs:ignore -- WordPress runtime stub.
    {
        /**
         * Error data.
         *
         * @var array<string, mixed>
         */
        private array $data;

        public function __construct(
            private string $code = '',
            private string $message = '',
            mixed $data = []
        ) {
            $this->data = is_array($data) ? $data : [];
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }

        /**
         * Add or replace data for this error.
         *
         * @param mixed       $data Error data.
         * @param string|null $code Ignored in the minimal test stub.
         */
        public function add_data(mixed $data, ?string $code = null): void
        {
            $this->data = is_array($data) ? $data : [];
        }
    }
}

// Minimal WP_Post stub for instanceof checks.
if (!class_exists('WP_Post', false)) {
    class WP_Post // phpcs:ignore -- WordPress runtime stub.
    {
        public int $ID = 0;
        public string $post_type = '';
        public string $post_title = '';
        public string $post_status = '';
        public string $post_content = '';
    }
}

// Minimal WP_CLI stub: record output and throw on error(), like the runner.
if (!class_exists('WP_CLI', false)) {
    class WP_CLI // phpcs:ignore -- WordPress runtime stub.
    {
        /**
         * Recorded CLI output.
         *
         * @var array<int, array{level: string, message: string}>
         */
        public static array $log = [];

        public static function reset(): void
        {
            self::$log = [];
        }

        public static function add_command(string $name, mixed $callable, array $args = []): void {}

        public static function success(string $message): void
        {
            self::$log[] = ['level' => 'success', 'message' => $message];
        }

        public static function log(string $message): void
        {
            self::$log[] = ['level' => 'log', 'message' => $message];
        }

        public static function confirm(string $question, array $assoc_args = []): void {}

        public static function error(string $message): void
        {
            self::$log[] = ['level' => 'error', 'message' => $message];
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test WP_CLI stub preserves the raw error message.
            throw new \RuntimeException($message);
        }
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
