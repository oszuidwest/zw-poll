<?php
/**
 * Registers the poll shortcode.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Shortcode;

use Closure;
use ZuidWest\Poll\Frontend\Assets;
use ZuidWest\Poll\Frontend\PollRenderer;

/**
 * Renders [zw_poll id="..."]: the single frontend entry point for polls.
 */
final class PollShortcode
{
    public const TAG = 'zw_poll';

    /**
     * Poll markup producer; defaults to PollRenderer::render().
     *
     * @var Closure(int): string
     */
    private readonly Closure $renderer;

    /**
     * Stores an optional renderer override for tests.
     *
     * @param (Closure(int): string)|null $renderer Poll markup producer.
     */
    public function __construct(?Closure $renderer = null)
    {
        $this->renderer = $renderer ?? PollRenderer::render(...);
    }

    /** Registers shortcode hooks. */
    public function register(): void
    {
        add_action('init', [$this, 'registerShortcode']);
    }

    /** Registers the shortcode with WordPress. */
    public function registerShortcode(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /**
     * Renders the shortcode.
     *
     * @param array<string, string>|string $atts Raw shortcode attributes.
     */
    public function render(array|string $atts): string
    {
        $atts = shortcode_atts(['id' => 0], is_array($atts) ? $atts : [], self::TAG);
        $poll_id = absint($atts['id']);
        if ($poll_id === 0) {
            return '';
        }

        $html = ($this->renderer)($poll_id);
        if ($html === '') {
            return '';
        }

        Assets::enqueue();

        return wp_interactivity_process_directives($html);
    }

    /**
     * Returns the shortcode text for a poll ID, for copy/insert UIs.
     *
     * @param int $poll_id Poll post ID.
     */
    public static function forPoll(int $poll_id): string
    {
        return sprintf('[%s id="%d"]', self::TAG, $poll_id);
    }

    /**
     * Echoes the copy button consumed by admin.js's clipboard handler.
     *
     * @param int    $poll_id Poll post ID.
     * @param string $class   Admin button style class.
     */
    public static function renderCopyButton(int $poll_id, string $class = 'button'): void
    {
        printf(
            '<button type="button" class="%s zw-poll-copy-shortcode" data-shortcode="%s">%s</button>',
            esc_attr($class),
            esc_attr(self::forPoll($poll_id)),
            esc_html__('Kopiëren', 'zw-poll')
        );
    }
}
