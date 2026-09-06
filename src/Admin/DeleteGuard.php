<?php
/**
 * Guards destructive poll deletion.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Admin;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Vote\VoteRepository;
use WP_Post;

/**
 * Blocks permanent deletion of polls that still contain votes or are still
 * embedded in content.
 */
final class DeleteGuard
{
    private const TRANSIENT_PREFIX = 'zw_poll_delete_blocked_';

    /**
     * Stores the vote repository used to detect existing votes.
     *
     * @param VoteRepository $repository Vote storage adapter.
     */
    public function __construct(private readonly VoteRepository $repository) {}

    /**
     * Registers delete guard hooks.
     */
    public function register(): void
    {
        add_filter('pre_delete_post', [$this, 'guard'], 10, 3);
        add_filter('post_row_actions', [$this, 'filterRowActions'], 10, 2);
        add_action('admin_notices', [$this, 'showBlockedNotice']);
    }

    /**
     * Blocks permanent deletion when a poll is still in use or has votes.
     *
     * @param mixed $delete       Existing delete decision.
     * @param mixed $post         Post object passed by WordPress.
     * @param bool  $force_delete Whether the delete is permanent.
     */
    public function guard($delete, mixed $post, bool $force_delete): mixed
    {
        if (!($post instanceof WP_Post) || $post->post_type !== PollPostType::POST_TYPE) {
            return $delete;
        }
        // For this CPT, pre_delete_post means permanent deletion; trashing
        // goes through wp_trash_post() and never reaches this filter.
        $reason = $this->blockReason($post->ID);
        if ($reason === null) {
            return $delete;
        }
        set_transient(
            self::TRANSIENT_PREFIX . get_current_user_id(),
            ['post_id' => $post->ID, 'reason' => $reason],
            5 * MINUTE_IN_SECONDS
        );
        return false;
    }

    /**
     * Hides the permanent delete row action when clicking it would be blocked.
     *
     * @param array<string, string> $actions Row actions.
     * @param WP_Post               $post    List-table post.
     * @return array<string, string>
     */
    public function filterRowActions(array $actions, WP_Post $post): array
    {
        if ($post->post_type !== PollPostType::POST_TYPE || !isset($actions['delete'])) {
            return $actions;
        }
        if ($this->blockReason($post->ID) === null) {
            return $actions;
        }

        unset($actions['delete']);
        return $actions;
    }

    /**
     * Determines why a permanent delete must be blocked, or null to allow it.
     *
     * Usage is checked before votes because "remove it from the article" is
     * the actionable message when both apply.
     *
     * @param int $poll_id Poll post ID.
     * @return 'in_use'|'votes'|null
     */
    private function blockReason(int $poll_id): ?string
    {
        $usage = array_filter(
            UsageTracker::getUsage($poll_id),
            static function (int $post_id): bool {
                $status = get_post_status($post_id);
                return $status !== false && $status !== 'trash';
            }
        );

        if (count($usage) > 0) {
            return 'in_use';
        }
        if (count($this->repository->countByOption($poll_id)) > 0) {
            return 'votes';
        }
        return null;
    }

    /**
     * Shows the deferred admin notice after a blocked delete.
     */
    public function showBlockedNotice(): void
    {
        // Blocked deletes redirect to poll screens; skip lookups elsewhere.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->post_type !== PollPostType::POST_TYPE) {
            return;
        }

        $key = self::TRANSIENT_PREFIX . get_current_user_id();
        $blocked = get_transient($key);
        if ($blocked === false) {
            return;
        }
        delete_transient($key);
        if (!is_array($blocked)) {
            return;
        }
        $blocked_id = (int) ($blocked['post_id'] ?? 0);
        if ($blocked_id <= 0) {
            return;
        }
        $edit_url = get_edit_post_link($blocked_id);
        $message = ($blocked['reason'] ?? '') === 'in_use'
            ? __(
                'Deze poll wordt nog gebruikt in een of meer artikelen en kan niet permanent verwijderd worden. Verwijder eerst de shortcode uit die artikelen.',
                'zw-poll'
            )
            : __(
                'Deze poll bevat stemmen en kan niet permanent verwijderd worden. Verwijder eerst de stemmen via de poll-editor, of laat de poll in de prullenbak staan.',
                'zw-poll'
            );
        $link = $edit_url
            ? sprintf(
                ' <a href="%s">%s</a>',
                esc_url($edit_url),
                esc_html__('Poll bewerken', 'zw-poll')
            )
            : '';
        printf(
            '<div class="notice notice-error"><p>%s%s</p></div>',
            esc_html($message),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Link escaped while building $link.
            $link
        );
    }
}
