<?php
/**
 * Hashes client IP addresses.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Vote;

use ZuidWest\Poll\Activation;

/**
 * Produces keyed, non-reversible hashes for rate limiting and audit trails.
 */
final class IpHasher
{
    /**
     * Hashes the client IP with the per-site salt.
     *
     * Used only for audit/rate-limit; cookie tokens handle dedup. User-agent is
     * omitted because clients can rotate it to bypass the limiter.
     *
     * @param string $ip Client IP address.
     */
    public function hash(string $ip): string
    {
        return hash_hmac('sha256', $ip, self::salt());
    }

    /**
     * Returns the per-site IP salt, creating it on first use.
     *
     * Lazy seeding covers installs that bypass activation and prevents reversible
     * unsalted hashes across the small IPv4 space.
     */
    private static function salt(): string
    {
        $salt = (string) get_option(Activation::IP_SALT_OPTION, '');
        if ($salt !== '') {
            return $salt;
        }
        $fresh = wp_generate_password(64, true, true);
        // add_option() loses races; re-read the winner.
        return add_option(Activation::IP_SALT_OPTION, $fresh, '', false)
            ? $fresh
            : (string) get_option(Activation::IP_SALT_OPTION, $fresh);
    }
}
