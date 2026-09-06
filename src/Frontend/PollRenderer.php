<?php
/**
 * Renders the poll markup on the server.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Frontend;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Rest\VoteController;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\VoteEpoch;
use WP_Post;

/**
 * Server-side render for the poll shortcode.
 *
 * Returns unprocessed Interactivity API markup; PollShortcode runs the server
 * directive pass. Class-method rendering keeps template locals visible to
 * static analysers and Plugin Check.
 */
final class PollRenderer
{
    /**
     * Renders a poll instance.
     *
     * @param int $poll_id Poll post ID.
     */
    public static function render(int $poll_id): string
    {
        if ($poll_id <= 0) {
            return '';
        }

        $poll = get_post($poll_id);
        if (!($poll instanceof WP_Post)
            || $poll->post_type !== PollPostType::POST_TYPE
            || $poll->post_status !== 'publish'
        ) {
            // Only editors see the placeholder; the public sees nothing.
            return self::editorPlaceholder(
                'zw-poll zw-poll--missing',
                __('Poll niet beschikbaar (alleen zichtbaar voor redacteuren).', 'zw-poll')
            );
        }

        // The title is the question. Core title handling and the direct-SQL
        // backfill may retain markup; strip tags here and escape on output.
        $question = trim(wp_strip_all_tags($poll->post_title));
        $options = PollPostType::options($poll_id);
        if (!PollPostType::isComplete($question, $options)) {
            return self::editorPlaceholder(
                'zw-poll zw-poll--incomplete',
                sprintf(
                    /* translators: %d: minimum number of answers. */
                    __('Poll onvolledig: geef de poll een vraag als titel en voeg minstens %d antwoorden toe.', 'zw-poll'),
                    PollPostType::MIN_OPTIONS
                )
            );
        }
        // PollPostType::options() guarantees the keys consumed below.
        $has_images = PollPostType::hasCompleteImages($options);

        $aggregate = AggregateCache::forDisplay($poll_id, $options);
        $counts = $aggregate['counts'];
        $total = $aggregate['total'];
        $is_closed = (PollPostType::status($poll_id) !== 'open');
        // Presentation-only: totals/counts stay in context so view.js can
        // update percentage bars after a vote; page caches may hold this flag
        // until the rendered page refreshes.
        $show_total = !PollPostType::hidesTotal($poll_id);

        /* translators: %s: total number of votes. */
        $total_label = __('Totaal aantal stemmen: %s', 'zw-poll');

        // WordPress 6.9 cannot load text domains for script modules; pass strings through state.
        wp_interactivity_state('zw-poll', [
            'restUrl' => esc_url_raw(VoteController::voteUrl()),
            'cookiePrefix' => VoteController::COOKIE_PREFIX,
            'i18n' => [
                'errors' => [
                    'rate_limited' => __('Even rustig aan — probeer over een minuutje opnieuw.', 'zw-poll'),
                    'poll_not_found' => __('Deze poll bestaat niet meer.', 'zw-poll'),
                    'poll_closed' => __('Deze poll is gesloten.', 'zw-poll'),
                    'invalid_option' => __('Kies eerst een optie.', 'zw-poll'),
                    'already_voted' => __('Je hebt al gestemd op deze poll.', 'zw-poll'),
                    'invalid_origin' => __('Stemmen vanaf deze pagina is niet toegestaan.', 'zw-poll'),
                    'vote_forbidden' => __('Stemmen op deze poll is niet toegestaan.', 'zw-poll'),
                    'insert_failed' => __('Stem niet opgeslagen. Probeer het later opnieuw.', 'zw-poll'),
                    'default' => __('Er ging iets mis. Probeer het later opnieuw.', 'zw-poll'),
                ],
                'total' => $total_label,
            ],
            // Mirror client getters for initial directive processing. These
            // cache-safe closures are not serialized; isVotedOption is client-only.
            'showResults' => static function (): bool {
                $ctx = wp_interactivity_get_context();
                return !empty($ctx['voted']) || !empty($ctx['closed']);
            },
            'cannotSubmit' => static function (): bool {
                $ctx = wp_interactivity_get_context();
                return !empty($ctx['busy']) || empty($ctx['selected']) || !empty($ctx['closed']);
            },
            'barFillStyle' => static fn (): string => 'width: ' . self::contextPercentage() . '%',
            'barText' => static fn (): string => self::contextPercentage() . '%',
            'totalText' => static function () use ($total_label): string {
                $ctx = wp_interactivity_get_context();
                return sprintf($total_label, number_format_i18n((int) ($ctx['total'] ?? 0)));
            },
        ]);

        $context = [
            'pollId' => $poll_id,
            // Cached page HTML can carry a stale reset epoch until the page
            // cache refreshes; REST voting still reads the current epoch.
            'voteEpoch' => VoteEpoch::current($poll_id),
            'voted' => false,
            'busy' => false,
            'closed' => $is_closed,
            'selected' => '',
            'votedOptionId' => '',
            'token' => '',
            'errorMessage' => '',
            'counts' => (object) $counts,
            'total' => $total,
        ];

        $instance_id = wp_unique_id('zw-poll-' . $poll_id . '-');
        $question_id = $instance_id . '-question';
        $results_id = $instance_id . '-results';
        $radio_name = $instance_id . '-option';
        $wrapper_class = 'zw-poll'
            . ($has_images ? ' zw-poll--images ' . self::imageLayoutClass(count($options)) : '')
            . ($is_closed ? ' zw-poll--closed' : '');

        ob_start();
        ?>
<aside
    class="<?php echo esc_attr($wrapper_class); ?>"
    data-wp-interactive="zw-poll"
    <?php echo wp_interactivity_data_wp_context($context); ?>
    data-wp-init="callbacks.init"
    aria-labelledby="<?php echo esc_attr($question_id); ?>"
>
    <div class="zw-poll__header">
        <h3 id="<?php echo esc_attr($question_id); ?>" class="zw-poll__question">
            <svg
                class="zw-poll__question-icon"
                aria-hidden="true"
                focusable="false"
                width="24"
                height="24"
                viewBox="0 0 24 24"
            >
                <circle class="zw-poll__question-icon-circle" cx="12" cy="12" r="10"></circle>
                <path
                    class="zw-poll__question-icon-mark"
                    d="M8.9 9.15C9.35 7.7 10.55 6.85 12.25 6.85C14.1 6.85 15.35 7.95 15.35 9.5C15.35 10.75 14.62 11.5 13.45 12.2C12.45 12.8 12.1 13.25 12.1 14.25"
                ></path>
                <circle class="zw-poll__question-icon-dot" cx="12.1" cy="17.15" r="1.2"></circle>
            </svg>
            <span class="zw-poll__question-text"><?php echo esc_html($question); ?></span>
        </h3>
    </div>

    <?php if ($is_closed) : ?>
    <div class="zw-poll__meta">
        <span class="zw-poll__closed zw-poll__header-action">
            <svg
                class="zw-poll__closed-icon"
                aria-hidden="true"
                focusable="false"
                width="16"
                height="16"
                viewBox="0 0 24 24"
            >
                <rect x="5" y="10.5" width="14" height="9.5" rx="2.2" fill="none" stroke="currentColor" stroke-width="2"></rect>
                <path d="M8 10.5V8a4 4 0 0 1 8 0v2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path>
            </svg>
            <?php esc_html_e('Gesloten', 'zw-poll'); ?>
        </span>
    </div>
    <?php endif; ?>

    <div class="zw-poll__form" data-wp-bind--hidden="state.showResults">
        <fieldset class="zw-poll__options">
            <legend class="screen-reader-text">
                <?php esc_html_e('Kies een optie', 'zw-poll'); ?>
            </legend>
            <?php foreach ($options as $opt) : ?>
                <label class="zw-poll__option">
                    <?php if ($has_images) : ?>
                        <span class="zw-poll__option-media">
                            <?php self::renderOptionImage($opt['imageId'], 'zw-poll__option-image'); ?>
                        </span>
                    <?php endif; ?>
                    <input
                        type="radio"
                        name="<?php echo esc_attr($radio_name); ?>"
                        value="<?php echo esc_attr($opt['id']); ?>"
                        data-wp-on--change="actions.select"
                    >
                    <span class="zw-poll__option-label">
                        <?php echo esc_html($opt['label']); ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="zw-poll__actions">
            <button
                type="button"
                class="zw-poll__submit"
                data-wp-on--click="actions.submit"
                data-wp-bind--disabled="state.cannotSubmit"
                data-wp-bind--aria-busy="context.busy"
            >
                <?php esc_html_e('Stem', 'zw-poll'); ?>
            </button>
        </div>
    </div>

    <div
        id="<?php echo esc_attr($results_id); ?>"
        class="zw-poll__results"
        role="region"
        aria-label="<?php esc_attr_e('Resultaten', 'zw-poll'); ?>"
        aria-live="polite"
        tabindex="-1"
        data-wp-bind--hidden="!state.showResults"
    >
        <?php foreach ($options as $opt) :
            $opt_id = $opt['id'];
            ?>
            <div
                class="zw-poll__bar"
                <?php echo wp_interactivity_data_wp_context(['optionId' => $opt_id]); ?>
                data-wp-class--is-selected="state.isVotedOption"
            >
                <?php if ($has_images) : ?>
                    <span class="zw-poll__bar-media">
                        <?php self::renderOptionImage($opt['imageId'], 'zw-poll__bar-image'); ?>
                    </span>
                <?php endif; ?>
                <span class="zw-poll__bar-label">
                    <span class="zw-poll__bar-label-text">
                        <?php echo esc_html($opt['label']); ?>
                    </span>
                    <?php if (!$is_closed) : ?>
                        <span
                            class="zw-poll__bar-vote"
                            data-wp-bind--hidden="!state.isVotedOption"
                        >
                            <svg
                                class="zw-poll__bar-vote-icon"
                                aria-hidden="true"
                                focusable="false"
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    d="M20 6L9 17l-5-5"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                ></path>
                            </svg>
                            <span class="screen-reader-text">
                                <?php esc_html_e('Gestemd', 'zw-poll'); ?>
                            </span>
                        </span>
                    <?php endif; ?>
                </span>
                <span class="zw-poll__bar-track" aria-hidden="true">
                    <span
                        class="zw-poll__bar-fill"
                        data-wp-bind--style="state.barFillStyle"
                    ></span>
                </span>
                <span class="zw-poll__bar-value" data-wp-text="state.barText"></span>
            </div>
        <?php endforeach; ?>

        <?php if ($is_closed || $show_total) : ?>
        <div class="zw-poll__results-foot">
            <?php if ($is_closed) : ?>
                <span class="zw-poll__final"><?php esc_html_e('Einduitslag', 'zw-poll'); ?></span>
            <?php endif; ?>
            <?php if ($show_total) : ?>
                <p class="zw-poll__total" data-wp-text="state.totalText"></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <p
        class="zw-poll__error"
        role="alert"
        data-wp-text="context.errorMessage"
        data-wp-bind--hidden="!context.errorMessage"
    ></p>
</aside>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Renders core-generated responsive image markup for an option.
     *
     * @param int    $image_id Attachment ID.
     * @param string $class    Image element class.
     */
    private static function renderOptionImage(int $image_id, string $class): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core generates the complete image markup.
        echo wp_get_attachment_image(
            $image_id,
            'medium_large',
            false,
            [
                'class' => $class,
                'alt' => '',
                'loading' => 'lazy',
            ]
        );
    }

    /**
     * Chooses a two- or three-column image-card layout.
     *
     * @param int $option_count Number of poll options.
     */
    private static function imageLayoutClass(int $option_count): string
    {
        return in_array($option_count, [2, 4], true)
            ? 'zw-poll--image-layout-two-column'
            : 'zw-poll--image-layout-three-column';
    }

    /**
     * Displays the option percentage from the current directive context.
     *
     * Runs inside server directive processing, where the per-bar context
     * carries optionId and the root context carries counts/total. Mirrors
     * view.js's percentage(); AggregateCache::percentage() keeps the
     * rounding policy shared.
     */
    private static function contextPercentage(): int
    {
        $ctx = wp_interactivity_get_context();
        $counts = (array) ($ctx['counts'] ?? []);
        $count = (int) ($counts[(string) ($ctx['optionId'] ?? '')] ?? 0);

        return AggregateCache::percentage($count, (int) ($ctx['total'] ?? 0));
    }

    /**
     * Renders a diagnostic placeholder for editors only.
     *
     * @param string $class   Wrapper class list.
     * @param string $message Placeholder message.
     */
    private static function editorPlaceholder(string $class, string $message): string
    {
        if (!current_user_can('edit_posts')) {
            return '';
        }

        ob_start();
        ?>
<div class="<?php echo esc_attr($class); ?>">
    <?php echo esc_html($message); ?>
</div>
<?php
        return (string) ob_get_clean();
    }
}
