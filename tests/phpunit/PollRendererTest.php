<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ZuidWest\Poll\Frontend\PollRenderer;
use ZuidWest\Poll\PostType\PollPostType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class PollRendererTest extends TestCase
{
    private const POLL_ID = 42;

    /**
     * State registered via wp_interactivity_state() during the last render.
     *
     * @var array<string, mixed>
     */
    private array $interactivityState = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('wp_strip_all_tags')->alias(static fn (string $text): string => trim(strip_tags($text)));
        Functions\when('esc_html')->alias(
            static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        Functions\when('esc_attr')->alias(
            static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('number_format_i18n')->alias(static fn (int $n): string => number_format($n, 0, ',', '.'));
        Functions\when('_n')->alias(static fn (string $single, string $plural, int $n): string => $n === 1 ? $single : $plural);
        Functions\when('error_log')->justReturn(true);
        Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo esc_html($text);
        });
        Functions\when('esc_attr_e')->alias(static function (string $text): void {
            echo esc_attr($text);
        });
        Functions\when('rest_url')->alias(static fn (string $path): string => 'https://example.test/wp-json/' . $path);
        Functions\when('wp_interactivity_state')->alias(
            function (string $namespace, array $state = []): void {
                $this->interactivityState = $state;
            }
        );
        Functions\when('wp_interactivity_data_wp_context')->alias(
            static fn (array $context): string => 'data-wp-context="' . htmlspecialchars(
                (string) json_encode($context),
                ENT_QUOTES
            ) . '"'
        );
        Functions\when('wp_unique_id')->justReturn('zw-poll-42-test');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function poll(): WP_Post
    {
        $poll = new WP_Post();
        $poll->ID = self::POLL_ID;
        $poll->post_type = PollPostType::POST_TYPE;
        $poll->post_status = 'publish';

        return $poll;
    }

    /**
     * Render a published poll with controlled content.
     *
     * @param string                                          $status   Poll status.
     * @param string                                          $question Post title (the poll question).
     * @param mixed                                           $options  Stored poll options.
     * @param array{counts: array<string, int>, total: int}|null $aggregate Stored public aggregate.
     * @param bool                                                $hide_total Whether the total is hidden for readers.
     * @param int                                                 $closes_at  Optional closing timestamp.
     */
    private function renderPoll(
        string $status,
        string $question = 'Wat kies jij?',
        mixed $options = [
            ['id' => 'opt-a', 'label' => 'Optie A'],
            ['id' => 'opt-b', 'label' => 'Optie B'],
        ],
        ?array $aggregate = null,
        bool $hide_total = false,
        int $closes_at = 0
    ): string {
        $poll = $this->poll();
        $poll->post_title = $question;
        $aggregate ??= [
            'counts' => ['opt-a' => 2, 'opt-b' => 1],
            'total' => 3,
        ];
        Functions\when('get_post')->justReturn($poll);
        Functions\when('get_post_meta')->alias(
            static function (int $post_id, string $key) use ($status, $options, $aggregate, $hide_total, $closes_at): mixed {
                return match ($key) {
                    PollPostType::META_OPTIONS => $options,
                    PollPostType::META_STATUS => $status,
                    PollPostType::META_AGGREGATE => $aggregate,
                    PollPostType::META_HIDE_TOTAL => $hide_total,
                    PollPostType::META_CLOSES_AT => $closes_at,
                    default => '',
                };
            }
        );

        return PollRenderer::render(self::POLL_ID);
    }

    #[Test]
    public function open_poll_displays_its_deadline_but_closed_poll_does_not(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $key): string => $key === 'date_format' ? 'd-m-Y' : 'H:i'
        );
        Functions\when('wp_date')->justReturn('23-07-2026 14:30');

        $open = $this->renderPoll('open', closes_at: 1784809800);
        $this->assertStringContainsString('Stemmen kan tot 23-07-2026 14:30', $open);
        $this->assertStringContainsString('zw-poll__deadline', $open);

        $closed = $this->renderPoll('closed', closes_at: 1784809800);
        $this->assertStringNotContainsString('Stemmen kan tot', $closed);
    }

    /**
     * Return the root interactivity context rendered on the poll wrapper.
     *
     * @return array<string, mixed>
     */
    private function rootContext(string $html): array
    {
        $this->assertSame(1, preg_match('/data-wp-context="([^"]+)"/', $html, $match));
        $context = json_decode(htmlspecialchars_decode($match[1], ENT_QUOTES), true);
        $this->assertIsArray($context);

        return $context;
    }

    /**
     * Evaluate derived state the way server directive processing does.
     *
     * @param string               $key     Derived state key.
     * @param array<string, mixed> $context Directive context for the element.
     */
    private function evaluateDerivedState(string $key, array $context): mixed
    {
        $closure = $this->interactivityState[$key] ?? null;
        $this->assertIsCallable($closure, "state.{$key} must be a derived-state closure");
        Functions\when('wp_interactivity_get_context')->justReturn($context);

        return $closure();
    }

    /**
     * Render an incomplete poll while controlling editor permissions.
     *
     * @param string $question Poll question.
     * @param mixed  $options  Stored poll options.
     */
    private function renderIncompletePoll(string $question, mixed $options, bool $can_edit): string
    {
        Functions\when('current_user_can')->alias(static fn (string $cap): bool => $cap === 'edit_posts' && $can_edit);

        return $this->renderPoll('open', $question, $options);
    }

    /**
     * Render an unavailable poll while controlling editor permissions.
     */
    private function renderUnavailablePoll(bool $can_edit, ?WP_Post $poll): string
    {
        Functions\when('current_user_can')->alias(static fn (string $cap): bool => $cap === 'edit_posts' && $can_edit);
        Functions\when('get_post')->justReturn($poll);

        return PollRenderer::render(self::POLL_ID);
    }

    /**
     * Render a missing poll while controlling editor permissions.
     */
    private function renderMissingPoll(bool $can_edit): string
    {
        return $this->renderUnavailablePoll($can_edit, null);
    }

    /**
     * Render an unpublished poll while controlling editor permissions.
     */
    private function renderUnpublishedPoll(bool $can_edit): string
    {
        $poll = $this->poll();
        $poll->post_status = 'draft';

        return $this->renderUnavailablePoll($can_edit, $poll);
    }

    #[Test]
    public function closed_poll_renders_final_results_without_voting_controls(): void
    {
        $html = $this->renderPoll('closed');

        $this->assertStringContainsString('zw-poll--closed', $html);
        $this->assertStringContainsString('class="zw-poll__closed zw-poll__header-action"', $html);
        $this->assertStringContainsString('Gesloten', $html);
        $this->assertStringContainsString('class="zw-poll__final"', $html);
        $this->assertStringContainsString('Einduitslag', $html);
        $this->assertStringContainsString('class="zw-poll__total"', $html);
        // Directive processing hides the form via state.showResults; the
        // derived-state tests cover the closure's closed-poll value.
        $this->assertStringContainsString('data-wp-bind--hidden="state.showResults"', $html);
        $this->assertStringNotContainsString('zw-poll__peek', $html);
        $this->assertStringNotContainsString('Eerst de tussenstand bekijken', $html);
        $this->assertStringNotContainsString('Terug naar stemmen', $html);
        $this->assertStringNotContainsString('zw-poll__voted', $html);
        $this->assertStringNotContainsString('Gestemd', $html);
        $this->assertStringNotContainsString('zw-poll__status-badge', $html);
    }

    #[Test]
    public function open_poll_renders_vote_form_without_results_toggle(): void
    {
        $html = $this->renderPoll('open');

        $this->assertStringNotContainsString('zw-poll--closed', $html);
        $this->assertStringNotContainsString('zw-poll__peek', $html);
        $this->assertStringNotContainsString('zw-poll__peek-row', $html);
        $this->assertStringNotContainsString('Eerst de tussenstand bekijken', $html);
        $this->assertStringNotContainsString('Terug naar stemmen', $html);
        $this->assertStringNotContainsString('togglePeek', $html);
        $this->assertStringNotContainsString('canTogglePeek', $html);
        $this->assertStringNotContainsString('zw-poll__voted', $html);
        $this->assertSame('', $this->rootContext($html)['votedOptionId'] ?? null);
        $this->assertStringContainsString('data-wp-class--is-selected="state.isVotedOption"', $html);
        $this->assertStringContainsString('data-wp-bind--hidden="!state.isVotedOption"', $html);
        $this->assertStringContainsString('Gestemd', $html);
        $this->assertStringContainsString('data-wp-bind--hidden="!state.showResults"', $html);
        $this->assertStringNotContainsString('class="zw-poll__closed zw-poll__header-action"', $html);
        $this->assertStringNotContainsString('class="zw-poll__final"', $html);
        $this->assertStringNotContainsString('Einduitslag', $html);
        $this->assertStringNotContainsString('zw-poll__status-badge', $html);
    }

    #[Test]
    public function open_poll_keeps_the_total_count_inside_the_results_region(): void
    {
        $html = $this->renderPoll(
            'open',
            aggregate: [
                'counts' => ['opt-a' => 2711, 'opt-b' => 0],
                'total' => 2711,
            ]
        );

        $context = $this->rootContext($html);

        $this->assertStringNotContainsString('zw-poll__peek-total', $html);
        $this->assertStringContainsString('class="zw-poll__total"', $html);
        $this->assertSame(
            'Totaal aantal stemmen: 2.711',
            $this->evaluateDerivedState('totalText', $context)
        );
        $this->assertSame(
            '100%',
            $this->evaluateDerivedState('barText', array_merge($context, ['optionId' => 'opt-a']))
        );
    }

    #[Test]
    public function open_poll_with_hidden_total_omits_the_total_line(): void
    {
        $html = $this->renderPoll('open', hide_total: true);
        $context = $this->rootContext($html);

        $this->assertStringNotContainsString('zw-poll__total', $html);
        $this->assertStringNotContainsString('Totaal aantal stemmen', $html);
        // Hiding the total is presentation-only; view.js still needs aggregate
        // state to update percentage bars immediately after a vote.
        $this->assertSame(3, $context['total'] ?? null);
        $this->assertStringNotContainsString('zw-poll__results-foot', $html);
        $this->assertStringContainsString('zw-poll__results', $html);
    }

    #[Test]
    public function closed_poll_with_hidden_total_keeps_the_final_label(): void
    {
        $html = $this->renderPoll('closed', hide_total: true);

        $this->assertStringNotContainsString('zw-poll__total', $html);
        $this->assertStringNotContainsString('Totaal aantal stemmen', $html);
        $this->assertStringContainsString('zw-poll__results-foot', $html);
        $this->assertStringContainsString('Einduitslag', $html);
    }

    #[Test]
    public function derived_state_fills_the_directive_bound_markup_for_open_polls(): void
    {
        $html = $this->renderPoll('open');
        $context = $this->rootContext($html);
        $bar_context = array_merge($context, ['optionId' => 'opt-a']);

        // Server directive processing writes these values into the markup;
        // tests/playwright/ssr-no-js.spec.ts asserts the processed result.
        $this->assertFalse($this->evaluateDerivedState('showResults', $context));
        $this->assertTrue($this->evaluateDerivedState('cannotSubmit', $context));
        $this->assertSame('67%', $this->evaluateDerivedState('barText', $bar_context));
        $this->assertSame('width: 67%', $this->evaluateDerivedState('barFillStyle', $bar_context));
        $this->assertSame('Totaal aantal stemmen: 3', $this->evaluateDerivedState('totalText', $context));
    }

    #[Test]
    public function derived_state_hides_the_form_and_shows_results_for_closed_polls(): void
    {
        $html = $this->renderPoll('closed');
        $context = $this->rootContext($html);

        $this->assertTrue($this->evaluateDerivedState('showResults', $context));
        $this->assertTrue($this->evaluateDerivedState('cannotSubmit', $context));
    }

    #[Test]
    public function derived_state_handles_zero_totals_without_division_errors(): void
    {
        // The render only registers the closures; the zero totals under test
        // come from the directive context below.
        $this->renderPoll('open');

        $context = ['counts' => [], 'total' => 0, 'optionId' => 'opt-a'];

        $this->assertSame('0%', $this->evaluateDerivedState('barText', $context));
        $this->assertSame('width: 0%', $this->evaluateDerivedState('barFillStyle', $context));
        $this->assertSame('Totaal aantal stemmen: 0', $this->evaluateDerivedState('totalText', $context));
    }

    #[Test]
    public function poll_root_is_a_labelled_aside(): void
    {
        $html = $this->renderPoll('open');

        $this->assertMatchesRegularExpression('/^<aside\s/', $html);
        $this->assertStringContainsString('aria-labelledby="zw-poll-42-test-question"', $html);
        $this->assertStringContainsString('id="zw-poll-42-test-question"', $html);
        $this->assertStringNotContainsString('<section', $html);
    }

    #[Test]
    public function published_poll_without_question_renders_nothing_publicly(): void
    {
        $html = $this->renderIncompletePoll('', [
            ['id' => 'opt-a', 'label' => 'Optie A'],
            ['id' => 'opt-b', 'label' => 'Optie B'],
        ], false);

        $this->assertSame('', $html);
    }

    #[Test]
    public function published_poll_without_options_renders_nothing_publicly(): void
    {
        $html = $this->renderIncompletePoll('Wat kies jij?', [], false);

        $this->assertSame('', $html);
    }

    #[Test]
    public function published_poll_with_one_option_renders_nothing_publicly(): void
    {
        $html = $this->renderIncompletePoll('Wat kies jij?', [
            ['id' => 'opt-a', 'label' => 'Optie A'],
        ], false);

        $this->assertSame('', $html);
    }

    #[Test]
    public function published_poll_with_non_array_options_renders_nothing_publicly(): void
    {
        $html = $this->renderIncompletePoll('Wat kies jij?', 'not-an-array', false);

        $this->assertSame('', $html);
    }

    #[Test]
    public function published_poll_with_blank_option_row_renders_nothing_publicly(): void
    {
        $html = $this->renderIncompletePoll('Wat kies jij?', [
            ['id' => 'opt-a', 'label' => 'Optie A'],
            ['id' => 'opt-b', 'label' => '   '],
        ], false);

        $this->assertSame('', $html);
    }

    #[Test]
    public function poll_with_two_valid_options_and_junk_rows_still_renders(): void
    {
        $html = $this->renderPoll('open', 'Wat kies jij?', [
            ['id' => 'opt-a', 'label' => 'Optie A'],
            'not-an-option',
            ['id' => 123, 'label' => 'Numeric id'],
            ['id' => 'opt-empty', 'label' => '  '],
            ['id' => 'opt-label-bad', 'label' => false],
            ['id' => 'opt-b', 'label' => 'Optie B'],
        ]);

        $this->assertStringContainsString('Wat kies jij?', $html);
        $this->assertStringContainsString('Optie A', $html);
        $this->assertStringContainsString('Optie B', $html);
        $this->assertStringNotContainsString('zw-poll--incomplete', $html);
        $this->assertStringNotContainsString('Numeric id', $html);
        $this->assertSame(2, substr_count($html, 'class="zw-poll__option-label"'));
    }

    #[Test]
    public function title_markup_is_stripped_before_rendering_the_question(): void
    {
        $html = $this->renderPoll('open', '<em>Wat vind je?</em>');

        $this->assertStringContainsString('Wat vind je?', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringNotContainsString('&lt;em&gt;', $html);
    }

    #[Test]
    public function comparison_signs_in_questions_render_without_double_encoding(): void
    {
        $html = $this->renderPoll('open', 'Is 1 < 2 en 3 > 2?');

        $this->assertStringContainsString('Is 1 &lt; 2 en 3 &gt; 2?', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
        $this->assertStringNotContainsString('&amp;gt;', $html);
    }

    #[Test]
    public function markup_only_question_renders_nothing_publicly(): void
    {
        $html = $this->renderIncompletePoll('<em></em>', [
            ['id' => 'opt-a', 'label' => 'Optie A'],
            ['id' => 'opt-b', 'label' => 'Optie B'],
        ], false);

        $this->assertSame('', $html);
    }

    #[Test]
    public function editors_see_a_placeholder_for_incomplete_polls(): void
    {
        $html = $this->renderIncompletePoll('Wat kies jij?', [
            ['id' => 'opt-a', 'label' => 'Optie A'],
        ], true);

        $this->assertStringContainsString('zw-poll--incomplete', $html);
        $this->assertStringContainsString('Poll onvolledig', $html);
    }

    #[Test]
    public function missing_poll_renders_nothing_publicly(): void
    {
        $html = $this->renderMissingPoll(false);

        $this->assertSame('', $html);
    }

    #[Test]
    public function unpublished_poll_renders_nothing_publicly(): void
    {
        $html = $this->renderUnpublishedPoll(false);

        $this->assertSame('', $html);
    }

    #[Test]
    public function editors_see_a_placeholder_for_missing_polls(): void
    {
        $html = $this->renderMissingPoll(true);

        $this->assertStringContainsString('zw-poll--missing', $html);
        $this->assertStringContainsString('Poll niet beschikbaar', $html);
    }

    #[Test]
    public function editors_see_a_placeholder_for_unpublished_polls(): void
    {
        $html = $this->renderUnpublishedPoll(true);

        $this->assertStringContainsString('zw-poll--missing', $html);
        $this->assertStringContainsString('Poll niet beschikbaar', $html);
    }
}
