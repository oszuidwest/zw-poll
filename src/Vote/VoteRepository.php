<?php
/**
 * Persists and reads vote records.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Vote;

use ZuidWest\Poll\Activation;
use wpdb;

/**
 * Stores anonymous votes in the plugin-owned table.
 */
final class VoteRepository implements VoteCounter
{
    /**
     * Stores the WordPress database adapter.
     *
     * @param wpdb $db WordPress database adapter.
     */
    public function __construct(private readonly wpdb $db) {}

    /**
     * Returns the fully-qualified votes table name.
     */
    public function table(): string
    {
        return $this->db->prefix . Activation::VOTES_TABLE;
    }

    /**
     * Checks the anonymous dedup key for a poll.
     *
     * The cache-safe frontend sends no REST nonce, so votes have no reliable
     * user ID. IP hashes are stored for audit only; shared NATs make them unsafe
     * as dedup keys. Same-token races are handled by the database UNIQUE index.
     *
     * @param int    $poll_id      Poll post ID.
     * @param string $cookie_token Anonymous voter token.
     * @phpstan-impure Reads mutable vote state.
     */
    public function exists(int $poll_id, string $cookie_token): bool
    {
        $table = $this->table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned votes table.
        return (bool) $this->db->get_var(
            $this->db->prepare(
                'SELECT id FROM %i WHERE poll_id = %d AND cookie_token = %s LIMIT 1',
                $table,
                $poll_id,
                $cookie_token
            )
        );
    }

    /**
     * Inserts a vote row.
     *
     * @param int    $poll_id      Poll post ID.
     * @param string $option_id    Option UUID.
     * @param string $ip_hash      Hashed client IP.
     * @param string $cookie_token Anonymous voter token.
     */
    public function insert(
        int $poll_id,
        string $option_id,
        string $ip_hash,
        string $cookie_token
    ): bool {
        $data = [
            'poll_id' => $poll_id,
            'option_id' => $option_id,
            'ip_hash' => $ip_hash,
            'cookie_token' => $cookie_token,
            'created_at' => current_time('mysql', true),
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into plugin-owned votes table.
        return $this->db->insert($this->table(), $data, $formats) === 1;
    }

    /**
     * Deletes all vote rows for a poll.
     *
     * @param int $poll_id Poll post ID.
     */
    public function deleteAllForPoll(int $poll_id): int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete from plugin-owned votes table.
        $result = $this->db->delete($this->table(), ['poll_id' => $poll_id], ['%d']);
        return is_int($result) ? $result : 0;
    }

    /**
     * Counts votes grouped by option.
     *
     * @param int $poll_id Poll post ID.
     * @return array<string, int>
     */
    public function countByOption(int $poll_id): array
    {
        $table = $this->table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate source is plugin-owned votes table.
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT option_id, COUNT(*) AS c FROM %i WHERE poll_id = %d GROUP BY option_id',
                $table,
                $poll_id
            ),
            ARRAY_A
        );
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(string) $row['option_id']] = (int) $row['c'];
        }
        return $out;
    }
}
