<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Admin\ClassicEditor;
use ZuidWest\Poll\Shortcode\PollShortcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the classic-editor integration: TinyMCE and Quicktags insert button
 * gating, the picker dialog lifecycle, asset data, and the stylesheet filter.
 */
final class ClassicEditorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr_e')->echoArg();
        Functions\when('esc_html_e')->echoArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->alias(
            static fn (string $path): string => 'http://example.test/wp-admin/' . $path
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function registers_the_quicktags_settings_filter(): void
    {
        $actions = [];
        $filters = [];
        $editor = new ClassicEditor();
        Functions\when('add_action')->alias(
            static function (...$args) use (&$actions): void {
                $actions[] = $args;
            }
        );
        Functions\when('add_filter')->alias(
            static function (...$args) use (&$filters): void {
                $filters[] = $args;
            }
        );

        $editor->register();

        $this->assertSame(['wp_enqueue_editor', 'admin_footer'], array_column($actions, 0));
        $this->assertContains(
            ['quicktags_settings', [$editor, 'quicktagsSettings'], 10, 2],
            $filters
        );
        $this->assertSame(['admin_footer', [$editor, 'renderPickerDialog'], PHP_INT_MAX], $actions[1]);
    }

    #[Test]
    public function skips_the_tinymce_button_from_users_without_poll_access(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $editor = new ClassicEditor();

        $this->assertSame([], $editor->tinyMcePlugins([]));
        $this->assertSame(['bold'], $editor->tinyMceButtons(['bold']));
    }

    #[Test]
    public function keeps_the_picker_dialog_hidden_when_poll_access_is_denied(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $editor = new ClassicEditor();

        $this->assertSame([], $editor->tinyMcePlugins([]));
        $this->assertSame(['bold'], $editor->tinyMceButtons(['bold']));
        $this->assertSame(['buttons' => 'strong'], $editor->quicktagsSettings(['buttons' => 'strong'], 'content'));

        ob_start();
        $editor->renderPickerDialog();
        $dialog = (string) ob_get_clean();

        $this->assertSame('', $dialog);
    }

    #[Test]
    public function registers_the_tinymce_button_for_poll_editors(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('add_query_arg')
            ->once()
            ->with(
                'ver',
                ZW_POLL_VERSION,
                ZW_POLL_URL . 'src/Admin/tinymce-plugin.js'
            )
            ->andReturn('https://example.test/src/Admin/tinymce-plugin.js?ver=' . ZW_POLL_VERSION);
        $editor = new ClassicEditor();

        $plugins = $editor->tinyMcePlugins([]);
        $buttons = $editor->tinyMceButtons(['bold']);

        $this->assertSame(
            'https://example.test/src/Admin/tinymce-plugin.js?ver=' . ZW_POLL_VERSION,
            $plugins['zwPoll'] ?? null
        );
        $this->assertSame(['bold', 'zw_poll'], $buttons);
    }

    #[Test]
    public function enqueue_assets_registers_classic_editor_config(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('wp_enqueue_style')
            ->once()
            ->with('zw-poll-admin');
        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'zw-poll-classic-editor',
                ZW_POLL_URL . 'src/Admin/classic-editor.js',
                ['mce-view', 'wp-api-fetch', 'wp-escape-html', 'wp-html-entities', 'wp-i18n', 'quicktags'],
                ZW_POLL_VERSION,
                true
            );
        Functions\expect('wp_set_script_translations')
            ->once()
            ->with(
                'zw-poll-classic-editor',
                'zw-poll',
                ZW_POLL_DIR . 'languages'
            );
        Functions\expect('wp_localize_script')
            ->once()
            ->with('zw-poll-classic-editor', 'zwPollClassic', [
                'shortcodeTag'              => PollShortcode::TAG,
                'editUrl'                   => 'http://example.test/wp-admin/post.php',
                'insertLabel'               => 'Poll invoegen',
                'pickerUnavailableMessage'  => 'De pollkiezer kan niet worden geopend.',
                'quicktags'                 => true,
                'quicktagsLabel'            => 'poll',
            ]);

        (new ClassicEditor())->enqueueAssets(['quicktags' => true]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function enqueue_assets_omits_quicktags_config_without_poll_access(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_enqueue_style')
            ->once()
            ->with('zw-poll-admin');
        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'zw-poll-classic-editor',
                ZW_POLL_URL . 'src/Admin/classic-editor.js',
                ['mce-view', 'wp-api-fetch', 'wp-escape-html', 'wp-html-entities', 'wp-i18n', 'quicktags'],
                ZW_POLL_VERSION,
                true
            );
        Functions\expect('wp_set_script_translations')
            ->once()
            ->with(
                'zw-poll-classic-editor',
                'zw-poll',
                ZW_POLL_DIR . 'languages'
            );
        Functions\expect('wp_localize_script')
            ->once()
            ->with('zw-poll-classic-editor', 'zwPollClassic', [
                'shortcodeTag'              => PollShortcode::TAG,
                'editUrl'                   => 'http://example.test/wp-admin/post.php',
                'insertLabel'               => 'Poll invoegen',
                'pickerUnavailableMessage'  => 'De pollkiezer kan niet worden geopend.',
                'quicktags'                 => false,
                'quicktagsLabel'            => 'poll',
            ]);

        (new ClassicEditor())->enqueueAssets(['quicktags' => true]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function enqueue_assets_omits_quicktags_dependency_when_editor_has_no_quicktags(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('wp_enqueue_style')
            ->once()
            ->with('zw-poll-admin');
        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'zw-poll-classic-editor',
                ZW_POLL_URL . 'src/Admin/classic-editor.js',
                ['mce-view', 'wp-api-fetch', 'wp-escape-html', 'wp-html-entities', 'wp-i18n'],
                ZW_POLL_VERSION,
                true
            );
        Functions\expect('wp_set_script_translations')
            ->once()
            ->with(
                'zw-poll-classic-editor',
                'zw-poll',
                ZW_POLL_DIR . 'languages'
            );
        Functions\expect('wp_localize_script')
            ->once()
            ->with('zw-poll-classic-editor', 'zwPollClassic', [
                'shortcodeTag'              => PollShortcode::TAG,
                'editUrl'                   => 'http://example.test/wp-admin/post.php',
                'insertLabel'               => 'Poll invoegen',
                'pickerUnavailableMessage'  => 'De pollkiezer kan niet worden geopend.',
                'quicktags'                 => false,
                'quicktagsLabel'            => 'poll',
            ]);

        (new ClassicEditor())->enqueueAssets(['quicktags' => false]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function quicktags_settings_marks_the_picker_dialog_before_admin_footer(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $editor = new ClassicEditor();

        $settings = $editor->quicktagsSettings(['buttons' => 'strong'], 'content');

        ob_start();
        $editor->renderPickerDialog();
        $html = (string) ob_get_clean();

        $this->assertSame(['buttons' => 'strong'], $settings);
        $this->assertStringContainsString('<dialog class="zw-poll-picker"', $html);
    }

    #[Test]
    public function skips_the_picker_dialog_when_no_toolbar_button_registered(): void
    {
        ob_start();
        (new ClassicEditor())->renderPickerDialog();

        $this->assertSame('', (string) ob_get_clean());
    }

    #[Test]
    public function renders_the_picker_dialog_after_the_toolbar_button(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $editor = new ClassicEditor();

        $editor->tinyMceButtons([]);

        ob_start();
        $editor->renderPickerDialog();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<dialog class="zw-poll-picker"', $html);
        $this->assertStringContainsString('zw-poll-picker__results', $html);
        $this->assertStringContainsString('post-new.php?post_type=zw_poll', $html);
    }

    #[Test]
    public function appends_the_preview_stylesheet_to_tinymce(): void
    {
        $editor = new ClassicEditor();

        $this->assertSame(
            ZW_POLL_URL . 'src/Admin/editor.css',
            $editor->editorStyles('')
        );
        $this->assertSame(
            'existing.css,' . ZW_POLL_URL . 'src/Admin/editor.css',
            $editor->editorStyles('existing.css')
        );
    }
}
