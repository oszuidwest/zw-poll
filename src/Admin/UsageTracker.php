<?php
/**
 * Tracks where polls are used.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Admin;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Shortcode\PollShortcode;
use WP_Post;

/**
 * Maintains the bidirectional post-to-poll usage index.
 */
final class UsageTracker
{
    public const USAGE_META = PollPostType::META_PREFIX . 'usage';
    public const REFERENCED_META = PollPostType::META_PREFIX . 'in_post';

    /**
     * Registers usage tracking hooks.
     */
    public function register(): void
    {
        add_action('save_post', [$this, 'trackOnSave'], 10, 2);
        add_action('before_delete_post', [$this, 'untrackOnDelete'], 10, 2);
    }

    /**
     * Updates poll usage when a content post is saved.
     *
     * @param int     $post_id Saved post ID.
     * @param WP_Post $post    Saved post object.
     */
    public function trackOnSave(int $post_id, WP_Post $post): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ($post->post_type === PollPostType::POST_TYPE) {
            return;
        }
        if ($post->post_status === 'trash') {
            $this->removePostFromAllPolls($post_id);
            return;
        }

        $current = array_values(array_filter(
            self::extractPollIds($post->post_content),
            static fn (int $poll_id): bool => get_post_type($poll_id) === PollPostType::POST_TYPE
        ));
        $previous = self::idList($post_id, self::REFERENCED_META);

        foreach (array_diff($current, $previous) as $poll_id) {
            self::appendUsage((int) $poll_id, $post_id);
        }
        foreach (array_diff($previous, $current) as $poll_id) {
            self::removeUsage((int) $poll_id, $post_id);
        }

        if (count($current) > 0) {
            update_post_meta($post_id, self::REFERENCED_META, $current);
        } else {
            delete_post_meta($post_id, self::REFERENCED_META);
        }
    }

    /**
     * Removes usage links when a content post is deleted.
     *
     * @param int     $post_id Deleted post ID.
     * @param WP_Post $post    Deleted post object.
     */
    public function untrackOnDelete(int $post_id, WP_Post $post): void
    {
        if ($post->post_type === PollPostType::POST_TYPE) {
            return;
        }
        $this->removePostFromAllPolls($post_id);
    }

    /**
     * Removes one content post from all tracked poll usage lists.
     *
     * @param int $post_id Content post ID.
     */
    private function removePostFromAllPolls(int $post_id): void
    {
        foreach (self::idList($post_id, self::REFERENCED_META) as $poll_id) {
            self::removeUsage($poll_id, $post_id);
        }
        delete_post_meta($post_id, self::REFERENCED_META);
    }

    /**
     * Returns IDs of posts that reference a poll.
     *
     * @param int $poll_id Poll post ID.
     * @return array<int, int>
     */
    public static function getUsage(int $poll_id): array
    {
        return self::idList($poll_id, self::USAGE_META);
    }

    /**
     * Reads a stored meta value as a filtered list of positive post IDs.
     *
     * @param int    $post_id  Post ID owning the meta.
     * @param string $meta_key Meta key holding the ID list.
     * @return array<int, int>
     */
    private static function idList(int $post_id, string $meta_key): array
    {
        return array_values(array_filter(array_map(
            'intval',
            (array) get_post_meta($post_id, $meta_key, true)
        )));
    }

    /**
     * Appends one content post to a poll's usage list.
     *
     * @param int $poll_id Poll post ID.
     * @param int $post_id Content post ID.
     */
    private static function appendUsage(int $poll_id, int $post_id): void
    {
        $usage = self::getUsage($poll_id);
        $usage[] = $post_id;
        update_post_meta($poll_id, self::USAGE_META, array_values(array_unique($usage)));
    }

    /**
     * Removes one content post from a poll's usage list.
     *
     * @param int $poll_id Poll post ID.
     * @param int $post_id Content post ID.
     */
    private static function removeUsage(int $poll_id, int $post_id): void
    {
        $usage = array_values(array_filter(
            self::getUsage($poll_id),
            static fn (int $id): bool => $id !== $post_id
        ));
        update_post_meta($poll_id, self::USAGE_META, $usage);
    }

    /**
     * Extracts poll IDs from saved shortcodes.
     *
     * Public for direct unit coverage; production uses save_post.
     *
     * @param string $content Saved post content.
     * @return array<int, int>
     */
    public static function extractPollIds(string $content): array
    {
        $ids = [];
        self::collectShortcodeIds($content, $ids);
        return array_values(array_unique($ids));
    }

    /**
     * Collects poll IDs referenced by [zw_poll] shortcodes.
     *
     * @param string          $content Saved post content.
     * @param array<int, int> $ids     Collected poll IDs.
     */
    private static function collectShortcodeIds(string $content, array &$ids): void
    {
        if (!str_contains($content, '[' . PollShortcode::TAG)) {
            return;
        }
        $found = preg_match_all('/' . get_shortcode_regex([PollShortcode::TAG]) . '/', $content, $matches, PREG_SET_ORDER);
        if ($found === false) {
            // An unnoticed PCRE failure (e.g. backtrack limit) would silently
            // under-count usage in the admin overview.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Scan failures must be visible in server logs.
            error_log(sprintf('zw-poll: shortcode usage scan failed (%s).', preg_last_error_msg()));
            return;
        }
        foreach ($matches as $match) {
            // Group 1/6 hold the brackets of an escaped [[shortcode]]; skip those.
            if ($match[1] === '[' && $match[6] === ']') {
                continue;
            }
            // Cast: core returns a string for attribute-less shortcodes.
            $atts = (array) shortcode_parse_atts($match[3]);
            $id = (int) ($atts['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
}
