<?php
/**
 * Customizes poll admin list tables.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Admin;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Shortcode\PollShortcode;
use ZuidWest\Poll\Vote\AggregateCache;

/**
 * Adds poll status, vote count, usage, and bulk actions to wp-admin.
 */
final class PollAdminColumns
{
    /**
     * Registers list-table hooks.
     */
    public function register(): void
    {
        $cpt = PollPostType::POST_TYPE;
        add_filter("manage_{$cpt}_posts_columns", [$this, 'columns']);
        add_action("manage_{$cpt}_posts_custom_column", [$this, 'renderColumn'], 10, 2);
        add_filter("bulk_actions-edit-{$cpt}", [$this, 'bulkActions']);
        add_filter("handle_bulk_actions-edit-{$cpt}", [$this, 'handleBulkActions'], 10, 3);
        add_action('admin_notices', [$this, 'bulkNotices']);
    }

    /**
     * Replaces default list-table columns with poll-specific columns.
     *
     * @param array<string, string> $cols Existing columns.
     * @return array<string, string>
     */
    public function columns(array $cols): array
    {
        $new = [];
        $new['cb'] = $cols['cb'] ?? '';
        // The poll title is the reader-facing question; label the column accordingly.
        $new['title'] = __('Vraag', 'zw-poll');
        $new['poll_status'] = __('Status', 'zw-poll');
        $new['votes'] = __('Stemmen', 'zw-poll');
        $new['usage'] = __('Gebruikt in', 'zw-poll');
        $new['shortcode'] = __('Shortcode', 'zw-poll');
        $new['date'] = $cols['date'] ?? __('Datum', 'zw-poll');
        return $new;
    }

    /**
     * Renders custom list-table column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Poll post ID.
     */
    public function renderColumn(string $column, int $post_id): void
    {
        switch ($column) {
            case 'poll_status':
                $is_open = PollPostType::status($post_id) === 'open';
                StatusBadge::render(
                    $is_open,
                    $is_open ? __('Open', 'zw-poll') : __('Gesloten', 'zw-poll')
                );
                break;

            case 'votes':
                $total = AggregateCache::forDisplay(
                    $post_id,
                    PollPostType::options($post_id)
                )['total'];
                echo esc_html(number_format_i18n($total));
                break;

            case 'usage':
                $usage = UsageTracker::getUsage($post_id);
                $count = count($usage);
                if ($count === 0) {
                    echo '<span class="zw-poll-usage--empty">' . esc_html__('Niet in gebruik', 'zw-poll') . '</span>';
                    break;
                }
                // One article links straight to it; more link to the poll edit
                // screen, where the usage meta box lists them all.
                $url = $count === 1
                    ? get_edit_post_link((int) reset($usage))
                    : get_edit_post_link($post_id);
                if ($count > 1 && $url) {
                    $url .= '#zw-poll-usage';
                }
                $label = sprintf(
                    /* translators: %d: number of posts. */
                    _n('%d artikel', '%d artikelen', $count, 'zw-poll'),
                    $count
                );
                if ($url) {
                    printf('<a href="%s">%s</a>', esc_url($url), esc_html($label));
                } else {
                    echo esc_html($label);
                }
                break;

            case 'shortcode':
                if (get_post_status($post_id) !== 'publish') {
                    echo '<span class="zw-poll-usage--empty">' . esc_html__('—', 'zw-poll') . '</span>';
                    break;
                }
                printf('<code>%s</code> ', esc_html(PollShortcode::forPoll($post_id)));
                PollShortcode::renderCopyButton($post_id, 'button-link');
                break;
        }
    }

    /**
     * Adds poll status bulk actions.
     *
     * @param array<string, string> $actions Existing actions.
     * @return array<string, string>
     */
    public function bulkActions(array $actions): array
    {
        $actions['zw_poll_close'] = __('Sluiten', 'zw-poll');
        $actions['zw_poll_open'] = __('Heropenen', 'zw-poll');
        return $actions;
    }

    /**
     * Handles open/close bulk actions for editable polls.
     *
     * @param string                 $redirect Redirect URL.
     * @param string                 $action   Bulk action name.
     * @param array<int, int|string> $post_ids Poll post IDs.
     */
    public function handleBulkActions(string $redirect, string $action, array $post_ids): string
    {
        if (!in_array($action, ['zw_poll_close', 'zw_poll_open'], true)) {
            return $redirect;
        }
        $new_status = $action === 'zw_poll_close' ? 'closed' : 'open';
        $count = 0;
        foreach ($post_ids as $id) {
            $id = (int) $id;
            if (get_post_type($id) !== PollPostType::POST_TYPE) {
                continue;
            }
            if (!current_user_can('edit_post', $id)) {
                continue;
            }
            update_post_meta($id, PollPostType::META_STATUS, $new_status);
            $count++;
        }
        return add_query_arg('zw_poll_bulk_' . $action, $count, $redirect);
    }

    /**
     * Renders bulk-action result notices.
     */
    public function bulkNotices(): void
    {
        $templates = [
            /* translators: %d: number of polls. */
            'zw_poll_close' => _n_noop('%d poll gesloten.', '%d polls gesloten.', 'zw-poll'),
            /* translators: %d: number of polls. */
            'zw_poll_open' => _n_noop('%d poll heropend.', '%d polls heropend.', 'zw-poll'),
        ];
        foreach ($templates as $action => $template) {
            $key = 'zw_poll_bulk_' . $action;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bulk notice count.
            $count = isset($_GET[$key]) ? (int) $_GET[$key] : 0;
            if ($count <= 0) {
                continue;
            }
            $message = translate_nooped_plural($template, $count, 'zw-poll');
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf($message, $count))
            );
        }
    }
}
