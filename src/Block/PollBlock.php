<?php
/**
 * Registers the PHP-only poll block.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Block;

use Closure;
use ZuidWest\Poll\Frontend\Assets;
use ZuidWest\Poll\Frontend\PollRenderer;

/** Exposes the shared poll renderer in the block editor without bundled JavaScript. */
final class PollBlock
{
    public const NAME = 'zw-poll/poll';

    /**
     * Poll markup producer shared with the shortcode.
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

    /** Registers block hooks. */
    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
    }

    /** Registers the dynamic block and its core-generated inspector control. */
    public function registerBlock(): void
    {
        register_block_type(self::NAME, [
            'api_version' => 3,
            'title' => __('Poll', 'zw-poll'),
            'description' => __('Toon een bestaande poll. Vul de poll-ID uit het overzicht Polls in.', 'zw-poll'),
            'category' => 'widgets',
            'icon' => 'chart-bar',
            'attributes' => [
                'pollId' => [
                    'type' => 'integer',
                    'default' => 0,
                    'label' => __('Poll-ID', 'zw-poll'),
                ],
            ],
            'supports' => [
                'autoRegister' => true,
                'html' => false,
                'interactivity' => true,
            ],
            'style_handles' => [Assets::STYLE_HANDLE],
            'render_callback' => [$this, 'render'],
        ]);
    }

    /**
     * Renders the same poll as the shortcode; core processes block directives.
     *
     * @param array<string, mixed> $attributes Saved block attributes.
     */
    public function render(array $attributes): string
    {
        $poll_id = (int) ($attributes['pollId'] ?? 0);
        if ($poll_id <= 0) {
            if (!current_user_can('edit_posts')) {
                return '';
            }
            $html = '<p>' . esc_html__('Vul een poll-ID in bij de blokinstellingen om een poll te tonen.', 'zw-poll') . '</p>';
        } else {
            $html = ($this->renderer)($poll_id);
        }
        if ($html === '') {
            return '';
        }

        Assets::enqueue();

        return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
    }
}
