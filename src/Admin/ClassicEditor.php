<?php
/**
 * Classic editor (TinyMCE) integration.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Admin;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Shortcode\PollShortcode;

/**
 * Adds poll insert toolbar buttons (TinyMCE for Visual, Quicktags for Text),
 * a picker dialog, and a TinyMCE shortcode preview to classic editor screens.
 *
 * Poll access gates every entry point because the picker uses protected REST endpoints.
 */
final class ClassicEditor
{
    private const SCRIPT_HANDLE = 'zw-poll-classic-editor';
    private const TINYMCE_PLUGIN = 'zwPoll';
    private const TINYMCE_BUTTON = 'zw_poll';

    /**
     * Whether an editor entry point needs the shared picker dialog.
     *
     * @var bool
     */
    private bool $picker_required = false;

    /**
     * Registers classic editor hooks.
     */
    public function register(): void
    {
        add_action('wp_enqueue_editor', [$this, 'enqueueAssets']);
        add_action('admin_footer', [$this, 'renderPickerDialog'], PHP_INT_MAX);
        add_filter('mce_external_plugins', [$this, 'tinyMcePlugins']);
        add_filter('mce_buttons', [$this, 'tinyMceButtons']);
        add_filter('quicktags_settings', [$this, 'quicktagsSettings'], 10, 2);
        add_filter('mce_css', [$this, 'editorStyles']);
    }

    /**
     * Registers the poll insert TinyMCE plugin.
     *
     * @param array<string, string> $plugins TinyMCE external plugins.
     * @return array<string, string>
     */
    public function tinyMcePlugins(array $plugins): array
    {
        if (!current_user_can('edit_zw_polls')) {
            return $plugins;
        }

        $plugins[self::TINYMCE_PLUGIN] = add_query_arg(
            'ver',
            ZW_POLL_VERSION,
            ZW_POLL_URL . 'src/Admin/tinymce-plugin.js'
        );

        return $plugins;
    }

    /**
     * Adds the poll insert button to the TinyMCE toolbar.
     *
     * Teeny editors cannot get this button: core never applies
     * mce_external_plugins for them, so the plugin JS would not load. When
     * Quicktags is enabled, their insertion path is the Text-tab Quicktags
     * button.
     *
     * @param array<int, string> $buttons TinyMCE toolbar buttons.
     * @return array<int, string>
     */
    public function tinyMceButtons(array $buttons): array
    {
        if (!current_user_can('edit_zw_polls')) {
            return $buttons;
        }

        $this->picker_required = true;
        $buttons[] = self::TINYMCE_BUTTON;

        return $buttons;
    }

    /**
     * Marks the picker as required for Text/Quicktags-only editor instances.
     *
     * Core applies this filter while each `wp_editor()` instance is rendered,
     * before `admin_footer`. The later `wp_enqueue_editor` action is too late
     * to decide whether the shared dialog markup must be printed.
     *
     * @param array<string, mixed> $settings  Quicktags settings.
     * @param string               $editor_id WordPress editor instance ID.
     * @return array<string, mixed>
     */
    public function quicktagsSettings(array $settings, string $editor_id): array
    {
        unset($editor_id);

        if (current_user_can('edit_zw_polls')) {
            $this->picker_required = true;
        }

        return $settings;
    }

    /**
     * Enqueues picker and preview assets when a classic editor renders.
     *
     * @param array{tinymce?: bool, quicktags?: bool} $editor Editor scripts being loaded.
     */
    public function enqueueAssets(array $editor = []): void
    {
        $can_edit_polls = current_user_can('edit_zw_polls');
        $has_quicktags = !empty($editor['quicktags']);

        $dependencies = ['mce-view', 'wp-api-fetch', 'wp-escape-html', 'wp-html-entities', 'wp-i18n'];
        if ($has_quicktags) {
            $dependencies[] = 'quicktags';
        }

        wp_enqueue_style('zw-poll-admin');
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            ZW_POLL_URL . 'src/Admin/classic-editor.js',
            $dependencies,
            ZW_POLL_VERSION,
            true
        );
        wp_set_script_translations(
            self::SCRIPT_HANDLE,
            'zw-poll',
            ZW_POLL_DIR . 'languages'
        );
        wp_localize_script(self::SCRIPT_HANDLE, 'zwPollClassic', [
            'shortcodeTag'              => PollShortcode::TAG,
            'editUrl'                   => admin_url('post.php'),
            'insertLabel'               => __('Poll invoegen', 'zw-poll'),
            'pickerUnavailableMessage'  => __('De pollkiezer kan niet worden geopend.', 'zw-poll'),
            'quicktags'                 => $can_edit_polls && $has_quicktags,
            'quicktagsLabel'            => __('poll', 'zw-poll'),
        ]);
    }

    /**
     * Renders the poll picker dialog markup once per screen.
     */
    public function renderPickerDialog(): void
    {
        if (!$this->picker_required) {
            return;
        }
        ?>
<dialog class="zw-poll-picker" aria-labelledby="zw-poll-picker-title">
    <form method="dialog" class="zw-poll-picker__inner">
        <h2 id="zw-poll-picker-title"><?php esc_html_e('Poll invoegen', 'zw-poll'); ?></h2>
        <p>
            <label class="screen-reader-text" for="zw-poll-picker-search"><?php esc_html_e('Polls zoeken', 'zw-poll'); ?></label>
            <input
                type="search"
                id="zw-poll-picker-search"
                class="large-text"
                placeholder="<?php esc_attr_e('Zoek een poll…', 'zw-poll'); ?>"
            >
        </p>
        <ul class="zw-poll-picker__results" aria-live="polite"></ul>
        <p class="zw-poll-picker__footer">
            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . PollPostType::POST_TYPE)); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Nieuwe poll maken', 'zw-poll'); ?>
                <span class="screen-reader-text"><?php esc_html_e('(opent in een nieuw venster)', 'zw-poll'); ?></span>
            </a>
            <button type="submit" class="button"><?php esc_html_e('Annuleren', 'zw-poll'); ?></button>
        </p>
    </form>
</dialog>
        <?php
    }

    /**
     * Adds the poll preview stylesheet to the TinyMCE iframe.
     *
     * @param string $css Comma-separated stylesheet URLs.
     */
    public function editorStyles(string $css): string
    {
        $url = ZW_POLL_URL . 'src/Admin/editor.css';
        return $css === '' ? $url : $css . ',' . $url;
    }
}
