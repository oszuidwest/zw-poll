<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Admin\PollEditForm;
use ZuidWest\Poll\PostType\PollPostType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the classic-editor save path (nonce and capability guards, the option
 * meta payload), the question-as-title placeholder, and the incomplete-poll
 * admin notice.
 */
final class PollEditFormTest extends TestCase
{
    private const POLL_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_key')->alias(
            static fn (string $key): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? ''
        );
        Functions\when('wp_unslash')->alias(
            static fn (mixed $value): mixed => is_string($value) ? stripslashes($value) : $value
        );
        Functions\when('sanitize_text_field')->alias(
            static fn (string $value): string => trim($value)
        );
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fill $_POST with a valid classic form submission.
     *
     * @param array<string, mixed> $overrides Fields to override or add.
     */
    private function submitForm(array $overrides = []): void
    {
        $_POST = array_merge([
            'zw_poll_edit_form_nonce' => 'nonce123',
            'zw_poll_options' => [
                ['id' => 'uuid-1', 'label' => 'Ja'],
                ['id' => '', 'label' => 'Nee'],
            ],
            'zw_poll_total_visibility' => PollPostType::TOTAL_VISIBILITY_SHOW,
        ], $overrides);
    }

    #[Test]
    public function save_ignores_requests_without_the_form_nonce(): void
    {
        $_POST = ['zw_poll_options' => [['id' => '', 'label' => 'Ja']]];
        Functions\expect('update_post_meta')->never();
        Functions\expect('wp_verify_nonce')->never();

        (new PollEditForm())->save(self::POLL_ID);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function save_ignores_requests_with_an_invalid_nonce(): void
    {
        $this->submitForm();
        Functions\when('wp_verify_nonce')->justReturn(false);
        Functions\expect('update_post_meta')->never();

        (new PollEditForm())->save(self::POLL_ID);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function save_ignores_users_without_edit_capability(): void
    {
        $this->submitForm();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('update_post_meta')->never();

        (new PollEditForm())->save(self::POLL_ID);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function save_ignores_revisions_and_autosaves(): void
    {
        $this->submitForm();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_is_post_revision')->justReturn(123);
        Functions\expect('update_post_meta')->never();

        (new PollEditForm())->save(self::POLL_ID);

        $this->addToAssertionCount(1);
    }

    /**
     * Capture update_post_meta() writes into $writes, keyed by meta key.
     *
     * @param array<string, array{0: int, 1: mixed}> $writes Captured writes.
     */
    private function captureMetaWrites(array &$writes): void
    {
        Functions\when('update_post_meta')->alias(
            static function (int $post_id, string $key, mixed $value) use (&$writes): bool {
                $writes[$key] = [$post_id, $value];
                return true;
            }
        );
    }

    #[Test]
    public function save_writes_options(): void
    {
        $this->submitForm();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $writes = [];
        $this->captureMetaWrites($writes);

        (new PollEditForm())->save(self::POLL_ID);

        // Existing option UUIDs must survive the round-trip: votes are keyed
        // by option ID, so a regenerated ID would orphan cast votes.
        $this->assertSame(
            [self::POLL_ID, [
                ['id' => 'uuid-1', 'label' => 'Ja'],
                ['id' => '', 'label' => 'Nee'],
            ]],
            $writes[PollPostType::META_OPTIONS]
        );
        $this->assertSame([self::POLL_ID, PollPostType::TOTAL_VISIBILITY_SHOW], $writes[PollPostType::META_TOTAL_VISIBILITY]);
        // The question is the post title and is saved by core, not here.
        $this->assertSame(
            [PollPostType::META_OPTIONS, PollPostType::META_TOTAL_VISIBILITY],
            array_keys($writes)
        );
    }

    #[Test]
    public function save_hides_the_total_when_the_checkbox_is_unchecked(): void
    {
        $this->submitForm(['zw_poll_total_visibility' => PollPostType::TOTAL_VISIBILITY_HIDE]);
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $writes = [];
        $this->captureMetaWrites($writes);

        (new PollEditForm())->save(self::POLL_ID);

        $this->assertSame([self::POLL_ID, PollPostType::TOTAL_VISIBILITY_HIDE], $writes[PollPostType::META_TOTAL_VISIBILITY]);
    }

    #[Test]
    public function save_keeps_display_meta_when_the_display_box_is_absent(): void
    {
        $this->submitForm();
        // Display box removed by another plugin: even the hidden "0" is gone.
        unset($_POST['zw_poll_total_visibility']);
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $writes = [];
        $this->captureMetaWrites($writes);

        (new PollEditForm())->save(self::POLL_ID);

        $this->assertArrayHasKey(PollPostType::META_OPTIONS, $writes);
        $this->assertArrayNotHasKey(PollPostType::META_TOTAL_VISIBILITY, $writes);
    }

    #[Test]
    public function save_drops_malformed_option_rows(): void
    {
        $this->submitForm([
            'zw_poll_options' => [
                'not-a-row',
                ['id' => 'uuid-1', 'label' => ' Ja '],
                ['label' => 'Nee'],
            ],
        ]);
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $writes = [];
        $this->captureMetaWrites($writes);

        (new PollEditForm())->save(self::POLL_ID);

        $this->assertSame([
            ['id' => 'uuid-1', 'label' => 'Ja'],
            ['id' => '', 'label' => 'Nee'],
        ], $writes[PollPostType::META_OPTIONS][1]);
    }

    #[Test]
    public function save_keeps_options_when_the_options_box_is_absent(): void
    {
        $this->submitForm();
        // Options box removed by another plugin while the display box (which
        // also carries the nonce) still submits: stored options must survive.
        unset($_POST['zw_poll_options']);
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $writes = [];
        $this->captureMetaWrites($writes);

        (new PollEditForm())->save(self::POLL_ID);

        $this->assertArrayNotHasKey(PollPostType::META_OPTIONS, $writes);
        $this->assertArrayHasKey(PollPostType::META_TOTAL_VISIBILITY, $writes);
    }

    #[Test]
    public function save_treats_a_malformed_options_field_as_empty(): void
    {
        $this->submitForm(['zw_poll_options' => 'not-an-array']);
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $writes = [];
        $this->captureMetaWrites($writes);

        (new PollEditForm())->save(self::POLL_ID);

        $this->assertSame([], $writes[PollPostType::META_OPTIONS][1]);
    }

    /**
     * Render the display meta box with a controlled stored hide flag.
     */
    private function renderDisplayBox(bool $hidden): string
    {
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('__')->returnArg();
        Functions\when('esc_html_e')->echoArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('_n')->alias(static fn (string $single, string $plural, int $count): string => $count === 1 ? $single : $plural);
        Functions\when('number_format_i18n')->alias(static fn (int $number): string => (string) $number);
        Functions\when('checked')->alias(static function (mixed $checked, mixed $current): void {
            echo $checked === $current ? 'checked="checked"' : '';
        });
        Functions\when('get_post_meta')->justReturn(
            $hidden ? PollPostType::TOTAL_VISIBILITY_HIDE : PollPostType::TOTAL_VISIBILITY_SHOW
        );
        Functions\when('get_option')->alias(
            static fn (string $option, mixed $default = []): mixed => $default
        );

        ob_start();
        (new PollEditForm())->renderDisplay($this->pollPost('publish'));

        return (string) ob_get_clean();
    }

    #[Test]
    public function display_box_checkbox_is_checked_while_the_total_is_shown(): void
    {
        $html = $this->renderDisplayBox(false);

        $this->assertSame(3, substr_count($html, 'name="zw_poll_total_visibility"'));
        $this->assertStringContainsString('value="show"', $html);
        $this->assertStringContainsString('checked="checked"', $html);
    }

    #[Test]
    public function display_box_checkbox_is_unchecked_when_the_total_is_hidden(): void
    {
        $html = $this->renderDisplayBox(true);

        $this->assertStringContainsString('value="hide"', $html);
        $this->assertStringContainsString('checked="checked"', $html);
    }

    #[Test]
    public function save_ignores_invalid_total_visibility_values(): void
    {
        $this->submitForm(['zw_poll_total_visibility' => 'invalid']);
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        $writes = [];
        $this->captureMetaWrites($writes);

        (new PollEditForm())->save(self::POLL_ID);

        $this->assertArrayNotHasKey(PollPostType::META_TOTAL_VISIBILITY, $writes);
    }

    #[Test]
    public function registers_meta_box_save_placeholder_and_notice_hooks(): void
    {
        $form = new PollEditForm();
        $form->register();

        $this->assertNotFalse(has_action(
            'add_meta_boxes_' . PollPostType::POST_TYPE,
            [$form, 'addMetaBoxes']
        ));
        $this->assertNotFalse(has_action(
            'save_post_' . PollPostType::POST_TYPE,
            [$form, 'save']
        ));
        $this->assertNotFalse(has_filter('enter_title_here', [$form, 'titlePlaceholder']));
        $this->assertNotFalse(has_action('admin_notices', [$form, 'renderIncompleteNotice']));
    }

    #[Test]
    public function add_meta_boxes_registers_options_and_display_boxes(): void
    {
        $registered = [];
        Functions\when('__')->returnArg();
        Functions\when('add_meta_box')->alias(
            static function (string $id, string $title, callable $callback, string $screen, string $context, string $priority) use (&$registered): void {
                $registered[$id] = [$screen, $context, $priority];
            }
        );

        (new PollEditForm())->addMetaBoxes();

        $this->assertSame([PollPostType::POST_TYPE, 'normal', 'high'], $registered['zw-poll-options']);
        $this->assertSame([PollPostType::POST_TYPE, 'side', 'default'], $registered['zw-poll-display']);
    }

    #[Test]
    public function title_placeholder_becomes_the_question_prompt_for_polls_only(): void
    {
        Functions\when('__')->returnArg();
        $form = new PollEditForm();

        $poll = new \WP_Post();
        $poll->post_type = PollPostType::POST_TYPE;
        $this->assertSame('Vraag aan de lezer', $form->titlePlaceholder('Titel toevoegen', $poll));

        $post = new \WP_Post();
        $post->post_type = 'post';
        $this->assertSame('Titel toevoegen', $form->titlePlaceholder('Titel toevoegen', $post));
    }

    /**
     * Render the incomplete-poll notice with controlled screen and post state.
     *
     * @param object|null                                   $screen  Current admin screen.
     * @param \WP_Post|null                                 $post    Post being edited.
     * @param string                                        $question Post title (the reader-facing question).
     * @param array<int, array{id: string, label: string}> $options Stored option rows.
     */
    private function renderNotice(?object $screen, ?\WP_Post $post, string $question = '', array $options = []): string
    {
        if ($post !== null) {
            $post->post_title = $question;
        }
        Functions\when('get_current_screen')->justReturn($screen);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(
            static fn (int $post_id, string $key): mixed => $key === PollPostType::META_OPTIONS ? $options : ''
        );
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();

        ob_start();
        (new PollEditForm())->renderIncompleteNotice();

        return (string) ob_get_clean();
    }

    /**
     * Build a poll post in the given status.
     */
    private function pollPost(string $status): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = self::POLL_ID;
        $post->post_type = PollPostType::POST_TYPE;
        $post->post_status = $status;

        return $post;
    }

    /**
     * Build a poll edit screen object.
     *
     * The post editor screen's id is the post type itself; the list table
     * screen id would be 'edit-zw_poll'.
     */
    private function pollScreen(): object
    {
        return (object) ['id' => PollPostType::POST_TYPE];
    }

    #[Test]
    public function incomplete_published_poll_shows_an_admin_notice(): void
    {
        $html = $this->renderNotice($this->pollScreen(), $this->pollPost('publish'), '', []);

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('Geef de poll een vraag als titel', $html);
    }

    #[Test]
    public function complete_published_poll_shows_no_notice(): void
    {
        $html = $this->renderNotice(
            $this->pollScreen(),
            $this->pollPost('publish'),
            'Wat vind je?',
            [['id' => 'a', 'label' => 'Ja'], ['id' => 'b', 'label' => 'Nee']]
        );

        $this->assertSame('', $html);
    }

    #[Test]
    public function draft_poll_shows_no_notice(): void
    {
        $html = $this->renderNotice($this->pollScreen(), $this->pollPost('draft'));

        $this->assertSame('', $html);
    }

    #[Test]
    public function other_admin_screens_show_no_notice(): void
    {
        $this->assertSame('', $this->renderNotice((object) ['id' => 'post'], null));
        // The poll list table must not warn either; only the editor does.
        $this->assertSame('', $this->renderNotice(
            (object) ['id' => 'edit-' . PollPostType::POST_TYPE],
            null
        ));
        $this->assertSame('', $this->renderNotice(null, null));
    }
}
