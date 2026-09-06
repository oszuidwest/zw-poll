<?php
/**
 * Classic edit form for poll content.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Admin;

use ZuidWest\Poll\PostType\PollPostType;
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

        $options = PollPostType::options($post->ID);
        if (!PollPostType::isComplete($post->post_title, $options)) {
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
            return;
        }

        if (PollPostType::hasAnyImages($options) && !PollPostType::hasCompleteImages($options)) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('Afbeeldingen worden pas getoond als alle antwoorden een geldige afbeelding hebben.', 'zw-poll')
            );
        }
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
            $options[] = ['id' => '', 'label' => '', 'imageId' => 0];
        }

        $image_ids = array_filter(array_column($options, 'imageId'));
        if ($image_ids !== []) {
            _prime_post_caches(array_values($image_ids), false, true);
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
            <?php $this->renderOptionRow((string) $index, $opt['id'], $opt['label'], $opt['imageId'], (int) $index + 1); ?>
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
    $this->renderOptionRow('__INDEX__', '', '', 0, 1);
    ?>
</template>
        <?php
    }

    /**
     * Renders the display settings meta box.
     *
     * The hidden "0" makes the browser always submit the field while this box
     * renders; a checked checkbox overrides it with "1". A missing field thus
     * means the box was removed, and save() keeps the stored setting.
     *
     * @param WP_Post $post Poll post.
     */
    public function renderDisplay(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
<input type="hidden" name="zw_poll_show_total" value="0">
<label class="zw-poll-edit-display">
    <input type="checkbox" name="zw_poll_show_total" value="1" <?php checked(!PollPostType::hidesTotal($post->ID)); ?>>
    <?php esc_html_e('Totaal aantal stemmen tonen', 'zw-poll'); ?>
</label>
<p class="description"><?php esc_html_e('Uitgeschakeld: lezers zien alleen percentages, geen totaal onder de resultaten.', 'zw-poll'); ?></p>
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

        // Absent entirely when another plugin removed the display box; keep
        // the stored setting instead of treating the checkbox as unchecked.
        if (isset($_POST['zw_poll_show_total'])) {
            update_post_meta($post_id, PollPostType::META_HIDE_TOTAL, sanitize_key(wp_unslash($_POST['zw_poll_show_total'])) !== '1');
        }
    }

    /**
     * Reduces submitted option rows to sanitized id/label pairs.
     *
     * Keeping the stored UUID of an existing option is required: votes are
     * keyed by option ID, so regenerating IDs would orphan cast votes.
     *
     * @param array<int|string, mixed> $rows Submitted option rows.
     * @return array<int, array{id: string, label: string, imageId: int}>
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
                'imageId' => PollPostType::normalizeImageId($row['imageId'] ?? 0),
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
     * @param int    $image_id Attachment ID, or zero without an image.
     * @param int    $position One-based row number for the accessible name.
     */
    private function renderOptionRow(string $index, string $id, string $label, int $image_id, int $position): void
    {
        /* translators: %d: answer number. */
        $option_name = sprintf(__('Antwoord %d', 'zw-poll'), $position);
        $choose_label = $image_id > 0
            ? __('Afbeelding vervangen', 'zw-poll')
            : __('Afbeelding kiezen', 'zw-poll');
        ?>
<li class="zw-poll-edit-option">
    <input type="hidden" name="zw_poll_options[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($id); ?>">
    <input
        type="hidden"
        class="zw-poll-edit-option__image-id"
        name="zw_poll_options[<?php echo esc_attr($index); ?>][imageId]"
        value="<?php echo esc_attr((string) $image_id); ?>"
    >
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
    <div class="zw-poll-edit-option__image">
        <span class="zw-poll-edit-option__preview">
            <?php
            if ($image_id > 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core generates the complete image markup.
                echo wp_get_attachment_image(
                    $image_id,
                    'thumbnail',
                    false,
                    [
                        'class' => 'zw-poll-edit-option__thumbnail',
                        'alt' => '',
                    ]
                );
            }
            ?>
        </span>
        <button type="button" class="button zw-poll-edit-option__choose-image">
            <?php echo esc_html($choose_label); ?>
        </button>
        <button
            type="button"
            class="button-link-delete zw-poll-edit-option__remove-image"
            <?php if ($image_id === 0) : ?>hidden<?php endif; ?>
        >
            <?php esc_html_e('Afbeelding verwijderen', 'zw-poll'); ?>
        </button>
    </div>
</li>
        <?php
    }
}
