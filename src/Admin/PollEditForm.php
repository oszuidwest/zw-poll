<?php
/**
 * Classic edit form for poll content.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Admin;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Support\Settings;
use WP_Post;

/**
 * Lets editors manage poll options from the classic edit screen, so polls remain
 * fully usable on classic-editor sites. The question itself is the post title,
 * which core saves.
 */
final class PollEditForm
{
    private const NONCE_ACTION = 'zw_poll_edit_form';
    private const NONCE_FIELD = 'zw_poll_edit_form_nonce';

    /**
     * Registers edit form hooks.
     */
    public function register(): void
    {
        // Registering at 9 (before PollMetaBoxes at 10) renders this box
        // first among boxes with the same meta-box priority.
        add_action('add_meta_boxes_' . PollPostType::POST_TYPE, [$this, 'addMetaBoxes'], 9);
        add_action('save_post_' . PollPostType::POST_TYPE, [$this, 'save']);
        add_filter('enter_title_here', [$this, 'titlePlaceholder'], 10, 2);
        add_action('admin_notices', [$this, 'renderIncompleteNotice']);
    }

    /**
     * Adds the poll edit meta boxes.
     */
    public function addMetaBoxes(): void
    {
        add_meta_box(
            'zw-poll-options',
            __('Antwoorden', 'zw-poll'),
            [$this, 'renderOptions'],
            PollPostType::POST_TYPE,
            'normal',
            'high'
        );
        add_meta_box(
            'zw-poll-display',
            __('Weergave', 'zw-poll'),
            [$this, 'renderDisplay'],
            PollPostType::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Presents the title field as the poll question.
     *
     * @param string  $placeholder Default title placeholder.
     * @param WP_Post $post        Post being edited.
     */
    public function titlePlaceholder(string $placeholder, WP_Post $post): string
    {
        return $post->post_type === PollPostType::POST_TYPE
            ? __('Vraag aan de lezer', 'zw-poll')
            : $placeholder;
    }

    /**
     * Warns when a published poll is not renderable for readers.
     *
     * Uses the same displayability predicate as the frontend gate
     * (PollPostType::isComplete), so the warning and the render path
     * can never drift apart.
     */
    public function renderIncompleteNotice(): void
    {
        // Only the post editor screen has the post type as its id; the list
        // table screen is 'edit-zw_poll'.
        if (get_current_screen()?->id !== PollPostType::POST_TYPE) {
            return;
        }

        $post = get_post();
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            return;
        }

        if (PollPostType::isComplete($post->post_title, PollPostType::options($post->ID))) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %d: minimum number of answers. */
                __(
                    'Deze poll is onvolledig en wordt niet aan lezers getoond. Geef de poll een vraag als titel en voeg minstens %d antwoorden toe.',
                    'zw-poll'
                ),
                PollPostType::MIN_OPTIONS
            ))
        );
    }

    /**
     * Renders the answer options meta box.
     *
     * @param WP_Post $post Poll post.
     */
    public function renderOptions(WP_Post $post): void
    {
        $options = PollPostType::options($post->ID);
        while (count($options) < PollPostType::MIN_OPTIONS) {
            $options[] = ['id' => '', 'label' => ''];
        }

        // Every box this class renders emits the same nonce, so save() keeps
        // working when another plugin removes one of the boxes.
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
<fieldset
    class="zw-poll-edit-options"
    data-min="<?php echo esc_attr((string) PollPostType::MIN_OPTIONS); ?>"
    data-max="<?php echo esc_attr((string) PollPostType::MAX_OPTIONS); ?>"
>
    <legend class="screen-reader-text"><?php esc_html_e('Antwoorden', 'zw-poll'); ?></legend>
    <ol class="zw-poll-edit-options__list">
        <?php foreach ($options as $index => $opt) : ?>
            <?php $this->renderOptionRow((string) $index, $opt['id'], $opt['label'], (int) $index + 1); ?>
        <?php endforeach; ?>
    </ol>
    <button type="button" class="button zw-poll-edit-options__add">
        <?php esc_html_e('Antwoord toevoegen', 'zw-poll'); ?>
    </button>
    <p class="description">
        <?php
        echo esc_html(sprintf(
            /* translators: 1: minimum number of answers, 2: maximum number of answers, 3: maximum answer length. */
            __('Min. %1$d antwoorden, max. %2$d antwoorden, max. %3$d tekens per antwoord.', 'zw-poll'),
            PollPostType::MIN_OPTIONS,
            PollPostType::MAX_OPTIONS,
            PollPostType::MAX_OPTION_LEN
        ));
        ?>
    </p>
</fieldset>

<template class="zw-poll-edit-options__template">
    <?php
    // The row number is refreshed by admin.js as soon as a row is added.
    $this->renderOptionRow('__INDEX__', '', '', 1);
    ?>
</template>
        <?php
    }

    /**
     * Renders the display settings meta box.
     *
     * @param WP_Post $post Poll post.
     */
    public function renderDisplay(WP_Post $post): void
    {
        $visibility = PollPostType::totalVisibility($post->ID);
        $total_min_votes = Settings::get()['total_min_votes'];
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
<fieldset class="zw-poll-edit-display" aria-describedby="zw-poll-total-visibility-description">
    <legend><?php esc_html_e('Totaal aantal stemmen', 'zw-poll'); ?></legend>
    <div class="zw-poll-edit-display__options">
        <?php foreach (self::totalVisibilityLabels() as $value => $label) : ?>
            <label>
                <input
                    type="radio"
                    name="zw_poll_total_visibility"
                    value="<?php echo esc_attr($value); ?>"
                    <?php checked($visibility, $value); ?>
                >
                <?php echo esc_html($label); ?>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
<p id="zw-poll-total-visibility-description" class="description zw-poll-edit-display__description"><?php echo esc_html(self::totalVisibilityDescription($total_min_votes)); ?></p>
        <?php
    }

    /**
     * Persists the classic form fields.
     *
     * REST saves never carry this nonce; they
     * return early here and keep their own meta payload authoritative. The
     * question is not handled here: it is the post title, which core saves.
     *
     * @param int $post_id Poll post ID.
     */
    public function save(int $post_id): void
    {
        $nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_key(wp_unslash($_POST[self::NONCE_FIELD])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // A rendered options box always submits this key (rows are padded to
        // the minimum and every row carries hidden inputs), so an absent key
        // means another plugin removed the box: keep the stored options
        // instead of wiping them.
        if (isset($_POST['zw_poll_options'])) {
            // The registered meta sanitizer (sanitizeOptions) runs inside
            // update_post_meta and stays authoritative for both editor paths.
            $raw_options = is_array($_POST['zw_poll_options'])
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rows sanitized field-by-field in readOptionRows().
                ? wp_unslash($_POST['zw_poll_options'])
                : [];
            update_post_meta($post_id, PollPostType::META_OPTIONS, self::readOptionRows($raw_options));
        }

        // Missing or invalid values preserve storage; this also protects saves
        // when another plugin removes the display box.
        if (isset($_POST['zw_poll_total_visibility'])) {
            $visibility = sanitize_key(wp_unslash($_POST['zw_poll_total_visibility']));
            if (in_array($visibility, PollPostType::totalVisibilityValues(), true)) {
                update_post_meta($post_id, PollPostType::META_TOTAL_VISIBILITY, $visibility);
            }
        }
    }

    /**
     * Returns labels for the total visibility radio group.
     *
     * @return array<string, string>
     */
    private static function totalVisibilityLabels(): array
    {
        return [
            PollPostType::TOTAL_VISIBILITY_DEFAULT => __('Site-instelling volgen', 'zw-poll'),
            PollPostType::TOTAL_VISIBILITY_HIDE => __('Altijd verbergen', 'zw-poll'),
            PollPostType::TOTAL_VISIBILITY_SHOW => __('Altijd tonen', 'zw-poll'),
        ];
    }

    /**
     * Describes the effective site-wide threshold.
     *
     * @param int $total_min_votes Configured minimum vote count.
     */
    private static function totalVisibilityDescription(int $total_min_votes): string
    {
        if ($total_min_votes === 0) {
            return __('Standaard wordt het aantal stemmen altijd getoond, omdat de drempel op 0 staat.', 'zw-poll');
        }

        return sprintf(
            /* translators: %s: configured minimum vote count. */
            _n(
                'Standaard wordt het aantal stemmen getoond zodra er %s stem is.',
                'Standaard wordt het aantal stemmen getoond zodra er %s of meer stemmen zijn.',
                $total_min_votes,
                'zw-poll'
            ),
            number_format_i18n($total_min_votes)
        );
    }

    /**
     * Reduces submitted option rows to sanitized id/label pairs.
     *
     * Keeping the stored UUID of an existing option is required: votes are
     * keyed by option ID, so regenerating IDs would orphan cast votes.
     *
     * @param array<int|string, mixed> $rows Submitted option rows.
     * @return array<int, array{id: string, label: string}>
     */
    private static function readOptionRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => isset($row['id']) ? sanitize_text_field((string) $row['id']) : '',
                'label' => isset($row['label']) ? sanitize_text_field((string) $row['label']) : '',
            ];
        }
        return $out;
    }

    /**
     * Renders one option row.
     *
     * @param string $index    Field index or the template placeholder.
     * @param string $id       Existing option UUID, or empty for new rows.
     * @param string $label    Option label.
     * @param int    $position One-based row number for the accessible name.
     */
    private function renderOptionRow(string $index, string $id, string $label, int $position): void
    {
        /* translators: %d: answer number. */
        $option_name = sprintf(__('Antwoord %d', 'zw-poll'), $position);
        ?>
<li class="zw-poll-edit-option">
    <input type="hidden" name="zw_poll_options[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($id); ?>">
    <input
        type="text"
        name="zw_poll_options[<?php echo esc_attr($index); ?>][label]"
        class="regular-text zw-poll-edit-option__label"
        maxlength="<?php echo esc_attr((string) PollPostType::MAX_OPTION_LEN); ?>"
        value="<?php echo esc_attr($label); ?>"
        aria-label="<?php echo esc_attr($option_name); ?>"
        placeholder="<?php echo esc_attr($option_name); ?>"
    >
    <button type="button" class="button-link zw-poll-edit-option__move-up" aria-label="<?php esc_attr_e('Omhoog', 'zw-poll'); ?>">&uarr;</button>
    <button type="button" class="button-link zw-poll-edit-option__move-down" aria-label="<?php esc_attr_e('Omlaag', 'zw-poll'); ?>">&darr;</button>
    <button type="button" class="button-link zw-poll-edit-option__remove" aria-label="<?php esc_attr_e('Antwoord verwijderen', 'zw-poll'); ?>">&times;</button>
</li>
        <?php
    }
}
