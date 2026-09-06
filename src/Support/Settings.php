<?php
/**
 * Stores and sanitizes plugin settings.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Support;

/**
 * Central schema and typed accessors for per-site plugin settings.
 *
 * @phpstan-type SettingsArray array{
 *     total_min_votes: int,
 *     rate_limit_enabled: bool,
 *     rate_limit_max: int,
 *     rate_limit_window: int,
 *     proxy_header: string,
 *     delete_data_on_uninstall: bool
 * }
 */
final class Settings
{
    public const OPTION = 'zw_poll_settings';

    public const PROXY_HEADER_NONE = 'none';
    public const PROXY_HEADER_CF_CONNECTING_IP = 'cf-connecting-ip';
    public const PROXY_HEADER_X_FORWARDED_FOR = 'x-forwarded-for';
    public const PROXY_HEADER_X_REAL_IP = 'x-real-ip';

    public const RATE_LIMIT_MAX_DEFAULT = 30;
    public const RATE_LIMIT_WINDOW_DEFAULT = MINUTE_IN_SECONDS;

    public const RATE_LIMIT_MAX_MIN = 1;
    public const RATE_LIMIT_MAX_MAX = 10000;
    public const RATE_LIMIT_WINDOW_MIN = 1;
    public const RATE_LIMIT_WINDOW_MAX = DAY_IN_SECONDS;
    public const TOTAL_MIN_VOTES_DEFAULT = 100;
    public const TOTAL_MIN_VOTES_MIN = 0;
    public const TOTAL_MIN_VOTES_MAX = 1000000;

    private const PROXY_HEADER_SERVER_KEYS = [
        self::PROXY_HEADER_CF_CONNECTING_IP => 'HTTP_CF_CONNECTING_IP',
        self::PROXY_HEADER_X_FORWARDED_FOR => 'HTTP_X_FORWARDED_FOR',
        self::PROXY_HEADER_X_REAL_IP => 'HTTP_X_REAL_IP',
    ];

    private const ADMIN_ONLY_KEYS = [
        'total_min_votes',
        'rate_limit_enabled',
        'rate_limit_max',
        'rate_limit_window',
        'proxy_header',
        'delete_data_on_uninstall',
    ];

    /**
     * Returns the plugin's default settings.
     *
     * @return SettingsArray
     */
    public static function defaults(): array
    {
        return [
            'total_min_votes' => self::TOTAL_MIN_VOTES_DEFAULT,
            'rate_limit_enabled' => true,
            'rate_limit_max' => self::RATE_LIMIT_MAX_DEFAULT,
            'rate_limit_window' => self::RATE_LIMIT_WINDOW_DEFAULT,
            'proxy_header' => self::PROXY_HEADER_NONE,
            'delete_data_on_uninstall' => false,
        ];
    }

    /**
     * Returns the settings for the current site.
     *
     * WordPress caches options per request; no local memoization needed.
     *
     * @return SettingsArray
     */
    public static function get(): array
    {
        return self::normalizeStoredOption(get_option(self::OPTION, []));
    }

    /**
     * Sanitizes a settings array before storage.
     *
     * @param mixed $raw Raw settings value from the Settings API.
     * @return SettingsArray
     */
    public static function sanitize(mixed $raw): array
    {
        return self::sanitizeInternal($raw, false);
    }

    /**
     * Sanitizes settings before storage through the Settings API.
     *
     * Settings are registered for manage_options; keeping admin-only values for
     * non-admin callers is defense-in-depth for direct or third-party saves.
     *
     * @param mixed $raw Raw settings value from the Settings API.
     * @return SettingsArray
     */
    public static function sanitizeForSave(mixed $raw): array
    {
        $settings = self::sanitizeInternal($raw, true);
        if (current_user_can('manage_options')) {
            return $settings;
        }

        $existing = self::normalizeStoredOption(get_option(self::OPTION, []));
        foreach (self::ADMIN_ONLY_KEYS as $key) {
            $settings[$key] = $existing[$key];
        }

        return $settings;
    }

    /**
     * Sanitizes a settings array.
     *
     * @param mixed $raw           Raw settings value.
     * @param bool  $report_errors Whether coercions should be shown in wp-admin.
     * @return SettingsArray
     */
    private static function sanitizeInternal(mixed $raw, bool $report_errors): array
    {
        if (!is_array($raw)) {
            if ($report_errors) {
                add_settings_error(
                    self::OPTION,
                    'zw_poll_settings_invalid',
                    __('Instellingen konden niet worden verwerkt; standaardwaarden zijn opgeslagen.', 'zw-poll')
                );
            }
            return self::defaults();
        }

        $defaults = self::defaults();

        return [
            'total_min_votes' => self::boundedInt(
                $raw['total_min_votes'] ?? $defaults['total_min_votes'],
                self::TOTAL_MIN_VOTES_MIN,
                self::TOTAL_MIN_VOTES_MAX,
                'total_min_votes',
                'Minimumaantal stemmen voor zichtbaar totaal',
                $report_errors
            ),
            'rate_limit_enabled' => self::sanitizeBoolean($raw['rate_limit_enabled'] ?? false),
            'rate_limit_max' => self::boundedInt(
                $raw['rate_limit_max'] ?? $defaults['rate_limit_max'],
                self::RATE_LIMIT_MAX_MIN,
                self::RATE_LIMIT_MAX_MAX,
                'rate_limit_max',
                'Maximumaantal stemmen per periode',
                $report_errors
            ),
            'rate_limit_window' => self::boundedInt(
                $raw['rate_limit_window'] ?? $defaults['rate_limit_window'],
                self::RATE_LIMIT_WINDOW_MIN,
                self::RATE_LIMIT_WINDOW_MAX,
                'rate_limit_window',
                'Lengte van de periode (seconden)',
                $report_errors
            ),
            'proxy_header' => self::sanitizeProxyHeader(
                $raw['proxy_header'] ?? $defaults['proxy_header'],
                $report_errors
            ),
            'delete_data_on_uninstall' => self::sanitizeBoolean($raw['delete_data_on_uninstall'] ?? false),
        ];
    }

    /**
     * Returns whitelisted proxy header values.
     *
     * @return list<string>
     */
    public static function proxyHeaders(): array
    {
        return [self::PROXY_HEADER_NONE, ...array_keys(self::PROXY_HEADER_SERVER_KEYS)];
    }

    /**
     * Returns the $_SERVER key for a proxy header setting, or '' for none.
     *
     * @param string $header Proxy header setting value.
     */
    public static function proxyHeaderServerKey(string $header): string
    {
        return self::PROXY_HEADER_SERVER_KEYS[$header] ?? '';
    }

    /**
     * Sanitizes and clamps an integer option.
     *
     * @param mixed  $value  Raw integer-like value.
     * @param int    $min    Minimum accepted value.
     * @param int    $max    Maximum accepted value.
     * @param string $field  Settings field key.
     * @param string $label  Human-readable field label.
     * @param bool   $report Whether coercions should be shown in wp-admin.
     */
    private static function boundedInt(
        mixed $value,
        int $min,
        int $max,
        string $field,
        string $label,
        bool $report
    ): int {
        $int_value = filter_var($value, FILTER_VALIDATE_INT);
        if ($int_value === false) {
            self::reportCoercedSetting($field, $label, $value, $min, $report);
            return $min;
        }

        if ($int_value < $min) {
            self::reportCoercedSetting($field, $label, $value, $min, $report);
            return $min;
        }

        if ($int_value > $max) {
            self::reportCoercedSetting($field, $label, $value, $max, $report);
            return $max;
        }

        return $int_value;
    }

    /**
     * Sanitizes a proxy-header option against the known choices.
     *
     * @param mixed $value  Raw header option.
     * @param bool  $report Whether coercions should be shown in wp-admin.
     */
    private static function sanitizeProxyHeader(mixed $value, bool $report): string
    {
        $header = is_string($value) ? strtolower($value) : '';
        if (in_array($header, self::proxyHeaders(), true)) {
            return $header;
        }

        self::reportCoercedSetting(
            'proxy_header',
            'Proxy-header',
            $value,
            self::PROXY_HEADER_NONE,
            $report
        );

        return self::PROXY_HEADER_NONE;
    }

    /**
     * Sanitizes a boolean option from Settings API input.
     *
     * @param mixed $value Raw boolean-like value.
     */
    private static function sanitizeBoolean(mixed $value): bool
    {
        return (is_bool($value) || is_int($value) || is_string($value)) && rest_sanitize_boolean($value);
    }

    /**
     * Returns a stored option normalized over the full settings schema.
     *
     * @param mixed $raw Raw stored option value.
     * @return SettingsArray
     */
    private static function normalizeStoredOption(mixed $raw): array
    {
        if (!is_array($raw)) {
            return self::defaults();
        }

        return self::sanitize(array_replace(self::defaults(), $raw));
    }

    /**
     * Reports an admin-visible settings coercion.
     *
     * @param string     $field   Settings field key.
     * @param string     $label   Human-readable field label.
     * @param mixed      $value   Submitted value.
     * @param int|string $stored  Stored replacement value.
     * @param bool       $report  Whether coercions should be shown in wp-admin.
     */
    private static function reportCoercedSetting(
        string $field,
        string $label,
        mixed $value,
        int|string $stored,
        bool $report
    ): void {
        if (!$report) {
            return;
        }

        $submitted = is_scalar($value) ? (string) $value : get_debug_type($value);
        add_settings_error(
            self::OPTION,
            'zw_poll_' . $field . '_coerced',
            sprintf(
                /* translators: 1: settings field label, 2: submitted value, 3: stored value. */
                __('Ongeldige waarde voor %1$s (%2$s); opgeslagen als %3$s.', 'zw-poll'),
                $label,
                $submitted,
                (string) $stored
            )
        );
    }
}
