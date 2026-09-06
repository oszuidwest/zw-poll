<?php
/**
 * Applies a soft per-IP vote throttle.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Vote;

use ZuidWest\Poll\Support\Settings;

/**
 * Limits rapid repeat vote attempts by hashed IP address.
 */
final class RateLimiter
{
    private const KEY_PREFIX = 'zwpoll_rl_';

    /**
     * Maximum votes in the configured window.
     *
     * @var int
     */
    private readonly int $max_per_window;

    /**
     * Window size in seconds.
     *
     * @var int
     */
    private readonly int $window_seconds;

    /**
     * Stores effective default rate-limit settings.
     *
     * Clamp direct construction; max 0 would block every vote, and window 0
     * would create never-expiring transients.
     *
     * @param bool $enabled        Whether rate limiting is active.
     * @param int  $max_per_window Maximum votes in the window.
     * @param int  $window_seconds Window size in seconds.
     */
    public function __construct(
        private readonly bool $enabled = true,
        int $max_per_window = Settings::RATE_LIMIT_MAX_DEFAULT,
        int $window_seconds = Settings::RATE_LIMIT_WINDOW_DEFAULT
    ) {
        $this->max_per_window = max(1, $max_per_window);
        $this->window_seconds = max(1, $window_seconds);
    }

    /**
     * Checks whether an IP hash may cast another vote in the current window.
     *
     * @param string $ip_hash Hashed client IP.
     * @param int    $poll_id Poll post ID.
     */
    public function allow(string $ip_hash, int $poll_id): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $buckets = $this->buckets($ip_hash, $poll_id);
        $counts = [];

        // Transient reads and writes are non-atomic; this is a soft throttle.
        foreach ($buckets as $index => $bucket) {
            $count = (int) get_transient($bucket['key']);
            if ($count >= $bucket['max']) {
                return false;
            }
            $counts[$index] = $count;
        }

        foreach ($buckets as $index => $bucket) {
            set_transient($bucket['key'], $counts[$index] + 1, $bucket['window']);
        }

        return true;
    }

    /**
     * Returns the transient buckets that must all pass.
     *
     * @param string $ip_hash Hashed client IP.
     * @param int    $poll_id Poll post ID.
     * @return list<array{key: string, max: int, window: int}>
     */
    private function buckets(string $ip_hash, int $poll_id): array
    {
        $global_limit = $this->defaultLimit();
        $poll_limit = $this->limit($ip_hash, $poll_id);

        return [
            [
                'key' => self::globalKey($ip_hash),
                'max' => $global_limit['max'],
                'window' => $global_limit['window'],
            ],
            [
                'key' => self::pollKey($ip_hash, $poll_id),
                'max' => $poll_limit['max'],
                'window' => $poll_limit['window'],
            ],
        ];
    }

    /**
     * Returns the effective rate limit.
     *
     * @param string $ip_hash Hashed client IP.
     * @param int    $poll_id Poll post ID.
     * @return array{max: int, window: int}
     */
    private function limit(string $ip_hash, int $poll_id): array
    {
        $default = $this->defaultLimit();
        $limit = apply_filters('zw_poll_rate_limit', $default, $ip_hash, $poll_id);

        if (!is_array($limit)) {
            self::invalidLimit(
                __('The zw_poll_rate_limit filter must return an array; defaults were used.', 'zw-poll')
            );
            return $default;
        }

        return [
            'max' => self::positiveInt($limit['max'] ?? $default['max'], 'max', $default['max']),
            'window' => self::positiveInt($limit['window'] ?? $default['window'], 'window', $default['window']),
        ];
    }

    /**
     * Returns the default bucket limit.
     *
     * @return array{max: int, window: int}
     */
    private function defaultLimit(): array
    {
        return [
            'max' => $this->max_per_window,
            'window' => $this->window_seconds,
        ];
    }

    /**
     * Returns a positive integer limit value, or the safe default.
     *
     * @param mixed  $value   Filtered limit value.
     * @param string $field   Limit field name.
     * @param int    $fallback Default value for the field.
     */
    private static function positiveInt(mixed $value, string $field, int $fallback): int
    {
        $int_value = (is_int($value) || is_string($value))
            ? filter_var($value, FILTER_VALIDATE_INT)
            : false;

        if (!is_int($int_value)) {
            self::invalidLimit(sprintf(
                /* translators: %s: rate limit field name, either max or window. */
                __('The zw_poll_rate_limit filter returned a non-integer %s value; the default was used.', 'zw-poll'),
                $field
            ));
            return $fallback;
        }

        if ($int_value <= 0) {
            self::invalidLimit(sprintf(
                /* translators: %s: rate limit field name, either max or window. */
                __('The zw_poll_rate_limit filter returned a non-positive %s value; the default was used.', 'zw-poll'),
                $field
            ));
            return $fallback;
        }

        return $int_value;
    }

    /**
     * Reports invalid operator filter output.
     *
     * @param string $message Developer notice message.
     */
    private static function invalidLimit(string $message): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Developer notice uses controlled strings.
        _doing_it_wrong('zw_poll_rate_limit', $message, \ZW_POLL_VERSION);
    }

    /**
     * Returns the stable global bucket key used by active transients.
     *
     * @param string $ip_hash Hashed client IP.
     */
    private static function globalKey(string $ip_hash): string
    {
        return self::KEY_PREFIX . substr($ip_hash, 0, 32);
    }

    /**
     * Returns a poll-specific bucket key.
     *
     * @param string $ip_hash Hashed client IP.
     * @param int    $poll_id Poll post ID.
     */
    private static function pollKey(string $ip_hash, int $poll_id): string
    {
        return self::globalKey($ip_hash) . '_p_' . $poll_id;
    }

}
