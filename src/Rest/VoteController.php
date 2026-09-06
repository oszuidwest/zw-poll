<?php
/**
 * Registers public vote REST routes.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Rest;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Support\Settings;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\IpHasher;
use ZuidWest\Poll\Vote\RateLimiter;
use ZuidWest\Poll\Vote\VoteEpoch;
use ZuidWest\Poll\Vote\VoteRepository;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Validates and stores anonymous poll votes.
 */
final class VoteController
{
    public const NAMESPACE = 'zw-poll/v1';
    public const COOKIE_PREFIX = 'zwpoll_voted_';
    private const COOKIE_LIFETIME = YEAR_IN_SECONDS;

    /**
     * Stores collaborators for vote validation and persistence.
     *
     * @param IpHasher       $hasher      Client-IP hashing service.
     * @param RateLimiter    $rateLimiter Soft vote throttle.
     * @param VoteRepository $repository  Vote storage adapter.
     * @param AggregateCache $cache       Aggregate cache service.
     */
    public function __construct(
        private readonly IpHasher $hasher,
        private readonly RateLimiter $rateLimiter,
        private readonly VoteRepository $repository,
        private readonly AggregateCache $cache,
    ) {}

    /**
     * Registers REST hooks.
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Registers public vote routes.
     */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/vote', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'vote'],
            'permission_callback' => '__return_true',
            'args' => [
                'poll_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'minimum' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'option_id' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                // Browser token ties retries and double-submits to one dedup key.
                'token' => [
                    'type' => 'string',
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Returns the public vote endpoint URL.
     */
    public static function voteUrl(): string
    {
        return rest_url(self::NAMESPACE . '/vote/');
    }

    /**
     * Validates and persists a vote request.
     *
     * @param WP_REST_Request $request REST request.
     */
    public function vote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!self::isSameOrigin($request)) {
            return new WP_Error(
                'invalid_origin',
                __('Stemmen vanaf deze pagina is niet toegestaan.', 'zw-poll'),
                ['status' => 403]
            );
        }

        $poll_id = (int) $request->get_param('poll_id');
        $option_id = (string) $request->get_param('option_id');

        $ip_hash = $this->hasher->hash(self::clientIp());

        if (!$this->rateLimiter->allow($ip_hash, $poll_id)) {
            return new WP_Error(
                'rate_limited',
                __('Te veel verzoeken. Probeer het later opnieuw.', 'zw-poll'),
                ['status' => 429]
            );
        }

        $poll = get_post($poll_id);
        if (!($poll instanceof WP_Post)
            || $poll->post_type !== PollPostType::POST_TYPE
            || $poll->post_status !== 'publish'
        ) {
            return new WP_Error(
                'poll_not_found',
                __('Poll bestaat niet.', 'zw-poll'),
                ['status' => 404]
            );
        }

        if (PollPostType::status($poll_id) !== 'open') {
            return new WP_Error(
                'poll_closed',
                __('Deze poll is gesloten.', 'zw-poll'),
                ['status' => 409]
            );
        }

        $options = PollPostType::options($poll_id);
        if (!in_array($option_id, array_column($options, 'id'), true)) {
            return new WP_Error(
                'invalid_option',
                __('Onbekende optie.', 'zw-poll'),
                ['status' => 400]
            );
        }

        $allowed = apply_filters('zw_poll_vote_allowed', true, $poll_id, $option_id, $request);
        if ($allowed instanceof WP_Error) {
            return self::normalizeAllowedError($allowed);
        }
        // Security gate: only exact boolean true allows a vote; truthy values deny.
        if ($allowed !== true) {
            return new WP_Error(
                'vote_forbidden',
                __('Stemmen op deze poll is niet toegestaan.', 'zw-poll'),
                ['status' => 403]
            );
        }

        $vote_epoch = VoteEpoch::current($poll_id);
        $cookie_name = self::COOKIE_PREFIX . $poll_id;
        $cookie_token = self::resolveToken($cookie_name, (string) $request->get_param('token'), $vote_epoch);

        // Votes are anonymous: never deduplicate by user. The application check
        // complements the database constraint with a clear duplicate response.
        if ($this->repository->exists($poll_id, $cookie_token)) {
            return self::alreadyVoted();
        }

        if (!$this->repository->insert($poll_id, $option_id, $ip_hash, $cookie_token)) {
            // The UNIQUE index is the race boundary; same-token collisions are duplicate votes.
            if ($this->repository->exists($poll_id, $cookie_token)) {
                return self::alreadyVoted();
            }
            return new WP_Error(
                'insert_failed',
                __('Stem niet opgeslagen.', 'zw-poll'),
                ['status' => 500]
            );
        }

        $aggregate = AggregateCache::projectAggregate($this->cache->increment($poll_id, $option_id), $options);
        self::setCookie($cookie_name, self::cookieValue($vote_epoch, $cookie_token, $option_id));

        return new WP_REST_Response([
            'ok' => true,
            'aggregate' => (object) $aggregate['counts'],
            'total' => $aggregate['total'],
        ], 200);
    }

    /**
     * Reports a duplicate anonymous vote.
     */
    private static function alreadyVoted(): WP_Error
    {
        return new WP_Error(
            'already_voted',
            __('Je hebt al gestemd op deze poll.', 'zw-poll'),
            ['status' => 409]
        );
    }

    /**
     * Ensures operator-supplied policy errors have an HTTP status.
     *
     * @param WP_Error $error Error returned by zw_poll_vote_allowed.
     */
    private static function normalizeAllowedError(WP_Error $error): WP_Error
    {
        $data = $error->get_error_data();
        if (is_array($data) && isset($data['status'])) {
            return $error;
        }

        _doing_it_wrong(
            'zw_poll_vote_allowed',
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Developer notice uses a translated string.
            __('WP_Error values returned from zw_poll_vote_allowed must include a status in error data; status 403 was used.', 'zw-poll'),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Developer notice uses the plugin version.
            \ZW_POLL_VERSION
        );

        $error->add_data(array_merge(is_array($data) ? $data : [], ['status' => 403]));
        return $error;
    }

    /**
     * Returns the request IP address from the filter or configured proxy header.
     */
    private static function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';
        if (has_filter('zw_poll_client_ip') !== false) {
            return self::filteredClientIp($ip);
        }

        return self::ipFromHeader(Settings::get()['proxy_header'], $ip);
    }

    /**
     * Resolves a developer-provided client IP filter value.
     *
     * @param string $fallback REMOTE_ADDR fallback.
     */
    private static function filteredClientIp(string $fallback): string
    {
        $filtered = apply_filters('zw_poll_client_ip', $fallback);
        $candidate = is_scalar($filtered)
            ? trim(sanitize_text_field(wp_unslash((string) $filtered)))
            : '';

        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }

        _doing_it_wrong(
            'zw_poll_client_ip',
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Developer notice uses a translated string.
            __('The zw_poll_client_ip filter must return a valid IP address; REMOTE_ADDR was used.', 'zw-poll'),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Developer notice uses the plugin version.
            \ZW_POLL_VERSION
        );

        return $fallback;
    }

    /**
     * Resolves a configured proxy header to an IP address with valid syntax.
     *
     * Syntax validation does not make a header trustworthy; configure only
     * headers set or overwritten by a trusted reverse proxy.
     *
     * @param string $header   Configured proxy header key.
     * @param string $fallback REMOTE_ADDR fallback.
     */
    private static function ipFromHeader(string $header, string $fallback): string
    {
        $server_key = Settings::proxyHeaderServerKey($header);
        if ($server_key === '' || !isset($_SERVER[$server_key])) {
            return $fallback;
        }

        $raw = sanitize_text_field(wp_unslash((string) $_SERVER[$server_key]));
        if ($header === Settings::PROXY_HEADER_X_FORWARDED_FOR) {
            $parts = array_map('trim', explode(',', $raw));
            $candidate = (string) end($parts);
        } else {
            $candidate = trim($raw);
        }

        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : $fallback;
    }

    /**
     * Checks whether the vote request came from this site.
     *
     * @param WP_REST_Request $request REST request.
     */
    private static function isSameOrigin(WP_REST_Request $request): bool
    {
        $origin = $request->get_header('origin');
        $referer = $request->get_header('referer');
        $source = is_string($origin) && $origin !== ''
            ? $origin
            : (is_string($referer) ? $referer : '');
        if ($source === '') {
            return false;
        }

        $source_parts = wp_parse_url($source);
        $home_parts = wp_parse_url(home_url('/'));
        if (!is_array($source_parts) || !is_array($home_parts)) {
            return false;
        }

        $source_scheme = strtolower((string) ($source_parts['scheme'] ?? ''));
        $home_scheme = strtolower((string) ($home_parts['scheme'] ?? ''));
        $source_host = strtolower((string) ($source_parts['host'] ?? ''));
        $home_host = strtolower((string) ($home_parts['host'] ?? ''));

        return $source_scheme === $home_scheme
            && $source_host === $home_host
            && self::urlPort($source_parts, $source_scheme) === self::urlPort($home_parts, $home_scheme);
    }

    /**
     * Resolves the explicit URL port or the scheme default.
     *
     * @param array<string, int|string> $parts  Parsed URL parts.
     * @param string                    $scheme URL scheme.
     */
    private static function urlPort(array $parts, string $scheme): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return $scheme === 'https' ? 443 : 80;
    }

    /**
     * Resolves the voter token.
     *
     * Existing cookies win. Otherwise, use the client token so retries and
     * double-submits share one identity; mint a server token as a final fallback.
     *
     * @param string $cookie_name Vote cookie name.
     * @param string $body_token  Sanitized request token.
     * @param int    $vote_epoch  Current poll vote epoch.
     */
    private static function resolveToken(string $cookie_name, string $body_token, int $vote_epoch): string
    {
        if (isset($_COOKIE[$cookie_name]) && is_string($_COOKIE[$cookie_name])) {
            $cookie = self::tokenFromCookieValue(
                sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])),
                $vote_epoch
            );
            if ($cookie !== null) {
                return $cookie;
            }
        }
        // Route args already sanitized the body token.
        $body = self::normalizeToken($body_token);
        if ($body !== null) {
            return $body;
        }
        return wp_generate_password(32, false, false);
    }

    /**
     * Resolves a token from a cookie value for the current vote epoch.
     *
     * @param string $value      Raw cookie value.
     * @param int    $vote_epoch Current poll vote epoch.
     */
    private static function tokenFromCookieValue(string $value, int $vote_epoch): ?string
    {
        // Vote cookies use the strict epoch:token:optionId format.
        $parts = explode(':', $value);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || (int) $parts[0] !== $vote_epoch) {
            return null;
        }

        return self::normalizeToken($parts[1]);
    }

    /**
     * Normalizes a candidate voter token.
     *
     * @param string $value Raw token value.
     * @return string|null A 32-character alphanumeric token, or null when invalid.
     */
    private static function normalizeToken(string $value): ?string
    {
        $token = preg_replace('/[^A-Za-z0-9]/', '', $value);
        return is_string($token) && strlen($token) === 32 ? $token : null;
    }

    /**
     * Encodes a vote cookie value with its poll reset epoch.
     *
     * @param int    $vote_epoch   Current poll vote epoch.
     * @param string $cookie_token Anonymous voter token.
     * @param string $option_id    Voted option marker for cached renders.
     */
    private static function cookieValue(int $vote_epoch, string $cookie_token, string $option_id): string
    {
        // The option marker lets cached renders reopen results.
        return $vote_epoch . ':' . $cookie_token . ':' . $option_id;
    }

    /**
     * Stores the voter token for cached future renders.
     *
     * @param string $name  Cookie name.
     * @param string $value Cookie value.
     */
    private static function setCookie(string $name, string $value): void
    {
        if (headers_sent()) {
            return;
        }
        $options = [
            'expires' => time() + self::COOKIE_LIFETIME,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'samesite' => 'Lax',
            'secure' => is_ssl(),
            // Expose to JS so cached renders can reopen results.
            'httponly' => false,
        ];
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') {
            $options['domain'] = COOKIE_DOMAIN;
        }
        setcookie($name, $value, $options);
    }
}
