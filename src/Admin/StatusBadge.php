<?php
/**
 * Renders the shared poll status badge.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Admin;

/**
 * Single source for status badge markup shared by list tables and meta boxes.
 */
final class StatusBadge
{
    /**
     * Prints the status badge.
     *
     * @param bool   $is_open Whether the poll is open for voting.
     * @param string $label   Translated badge label.
     */
    public static function render(bool $is_open, string $label): void
    {
        printf(
            '<span class="zw-poll-status-badge zw-poll-status-badge--%s">%s</span>',
            esc_attr($is_open ? 'open' : 'closed'),
            esc_html($label)
        );
    }
}
