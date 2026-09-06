<?php
/**
 * Renders poll editor meta boxes.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Admin;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Rest\AdminController;
use ZuidWest\Poll\Shortcode\PollShortcode;
use ZuidWest\Poll\Vote\AggregateCache;
use WP_Post;

/**
 * Adds status, results, shortcode, and usage meta boxes to poll edit screens.
 */
final class PollMetaBoxes
{
    /** Registers poll editor hooks. */
    public function register(): void
    {
        add_action('add_meta_boxes_' . PollPostType::POST_TYPE, [$this, 'configureMetaBoxes']);
    }

    /**
     * Configures poll editor meta boxes.
     *
     * @param WP_Post $post Poll post being edited.
     */
    public function configureMetaBoxes(WP_Post $post): void
    {
        add_meta_box(
            'zw-poll-status',
            __('Status', 'zw-poll'),
            [$this, 'renderStatus'],
            PollPostType::POST_TYPE,
            'side',
            'high'
        );
        add_meta_box(
            'zw-poll-results',
            __('Resultaten', 'zw-poll'),
            [$this, 'renderResults'],
            PollPostType::POST_TYPE,
            'normal',
            'high'
        );
        // Unpublished polls have no usable shortcode yet; skip the box entirely.
        if ($post->post_status === 'publish') {
            add_meta_box(
                'zw-poll-shortcode',
                __('Insluiten', 'zw-poll'),
                [$this, 'renderShortcode'],
                PollPostType::POST_TYPE,
                'side',
                'default'
            );
        }
        add_meta_box(
            'zw-poll-usage',
            __('Gebruikt in', 'zw-poll'),
            [$this, 'renderUsage'],
            PollPostType::POST_TYPE,
            'side',
            'default'
        );
        // Poll meta is edited via the plugin meta boxes; keep REST meta support but hide the raw UI.
        remove_meta_box('postcustom', PollPostType::POST_TYPE, 'normal');
    }

    /**
     * Renders the poll status meta box.
     *
     * @param WP_Post $post Poll post.
     */
    public function renderStatus(WP_Post $post): void
    {
        $options = PollPostType::options($post->ID);
        if (count($options) === 0) {
            $this->renderEmptyPollMessage();
            return;
        }

        $is_open = PollPostType::status($post->ID) === 'open';
        $nonce = wp_create_nonce('wp_rest');
        // Reuse the core post-meta REST route; status is a plain flip.
        $url = rest_url(rest_get_route_for_post($post));

        echo '<p class="zw-poll-status-current">';
        StatusBadge::render(
            $is_open,
            $is_open ? __('Open voor stemmen', 'zw-poll') : __('Gesloten', 'zw-poll')
        );
        echo '</p>';

        printf(
            '<button type="button" class="button button-large zw-poll-status-toggle" data-rest-url="%s" data-rest-nonce="%s" data-meta-key="%s" data-target-status="%s">%s</button>',
            esc_url($url),
            esc_attr($nonce),
            esc_attr(PollPostType::META_STATUS),
            esc_attr($is_open ? 'closed' : 'open'),
            esc_html($is_open ? __('Poll sluiten', 'zw-poll') : __('Poll heropenen', 'zw-poll'))
        );

        echo '<p class="description">'
            . esc_html($is_open
                ? __('Sluiten stopt nieuwe stemmen; de resultaten blijven zichtbaar.', 'zw-poll')
                : __('Heropenen maakt stemmen weer mogelijk.', 'zw-poll'))
            . '</p>';
    }

    /**
     * Renders the poll results meta box.
     *
     * @param WP_Post $post Poll post.
     */
    public function renderResults(WP_Post $post): void
    {
        $options = PollPostType::options($post->ID);
        if (count($options) === 0) {
            $this->renderEmptyPollMessage();
            return;
        }

        $agg = AggregateCache::forDisplay($post->ID, $options);
        $counts = $agg['counts'];
        $total = $agg['total'];

        echo '<div class="zw-poll-admin-results">';
        foreach ($options as $opt) {
            $opt_id = $opt['id'];
            $label = $opt['label'];
            $count = $counts[$opt_id] ?? 0;
            $pct = AggregateCache::percentage($count, $total);
            $aria = sprintf(
                /* translators: 1: option label, 2: percentage, 3: vote count. */
                _n('%1$s: %2$d procent (%3$s stem)', '%1$s: %2$d procent (%3$s stemmen)', $count, 'zw-poll'),
                $label,
                $pct,
                number_format_i18n($count)
            );
            printf(
                '<div class="zw-poll-admin-bar">
                    <div class="zw-poll-admin-bar__label">%s</div>
                    <div class="zw-poll-admin-bar__track" role="progressbar" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100" aria-label="%s"><span style="width:%d%%"></span></div>
                    <div class="zw-poll-admin-bar__value">%s%% (%s)</div>
                </div>',
                esc_html($label),
                absint($pct),
                esc_attr($aria),
                absint($pct),
                esc_html((string) $pct),
                esc_html(number_format_i18n($count))
            );
        }
        printf(
            '<p class="zw-poll-admin-total">%s</p>',
            esc_html(
                sprintf(
                    /* translators: %s: total number of votes. */
                    __('Totaal aantal stemmen: %s', 'zw-poll'),
                    number_format_i18n($total)
                )
            )
        );

        if ($total > 0) {
            $nonce = wp_create_nonce('wp_rest');
            $url = AdminController::resetUrl($post->ID);
            echo '<hr style="margin: 16px 0;" />';
            printf(
                '<button type="button" class="button button-secondary zw-poll-admin-reset" data-rest-url="%s" data-rest-nonce="%s">%s</button>',
                esc_url($url),
                esc_attr($nonce),
                esc_html__('Alle stemmen verwijderen…', 'zw-poll')
            );
            echo '<p class="description">'
                . esc_html__('Verwijdert alle stemmen voor deze poll. Niet terug te draaien.', 'zw-poll')
                . '</p>';
        }
        echo '</div>';
    }

    /**
     * Renders the shortcode meta box for embedding the poll in classic content.
     *
     * @param WP_Post $post Poll post.
     */
    public function renderShortcode(WP_Post $post): void
    {
        ?>
<p class="zw-poll-shortcode">
    <label class="screen-reader-text" for="zw-poll-edit-shortcode-value"><?php esc_html_e('Shortcode', 'zw-poll'); ?></label>
    <input
        type="text"
        id="zw-poll-edit-shortcode-value"
        class="code"
        readonly
        value="<?php echo esc_attr(PollShortcode::forPoll($post->ID)); ?>"
    >
    <?php PollShortcode::renderCopyButton($post->ID); ?>
</p>
<p class="description"><?php esc_html_e('Plak deze shortcode in een artikel of pagina om de poll te tonen.', 'zw-poll'); ?></p>
        <?php
    }

    /**
     * Renders the poll usage meta box.
     *
     * @param WP_Post $post Poll post.
     */
    public function renderUsage(WP_Post $post): void
    {
        $usage = UsageTracker::getUsage($post->ID);
        if (count($usage) === 0) {
            echo '<p class="zw-poll-admin-empty">' . esc_html__('Niet in gebruik', 'zw-poll') . '</p>';
            return;
        }
        // One query for all referenced posts instead of one per list item.
        _prime_post_caches($usage, false, false);
        echo '<ul style="margin: 0;">';
        foreach ($usage as $post_id) {
            $title = get_the_title($post_id);
            $url = get_edit_post_link($post_id);
            /* translators: %d: post ID. */
            $display = $title !== '' ? $title : sprintf(__('Artikel #%d', 'zw-poll'), $post_id);
            if ($url) {
                printf('<li><a href="%s">%s</a></li>', esc_url($url), esc_html($display));
            } else {
                printf('<li>%s</li>', esc_html($display));
            }
        }
        echo '</ul>';
    }

    /** Renders the empty-poll setup message. */
    private function renderEmptyPollMessage(): void
    {
        echo '<p class="zw-poll-admin-empty">' . esc_html(sprintf(
            /* translators: %d: minimum number of answers. */
            __('Geef de poll een vraag als titel en voeg minstens %d antwoorden toe.', 'zw-poll'),
            PollPostType::MIN_OPTIONS
        )) . '</p>';
    }
}
