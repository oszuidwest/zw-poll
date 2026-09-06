<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZuidWest\Poll\Frontend\Assets;
use ZuidWest\Poll\Shortcode\PollShortcode;

final class PollShortcodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('shortcode_atts')->alias(
            static fn (array $defaults, array $atts): array => array_merge($defaults, array_intersect_key($atts, $defaults))
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build a shortcode whose renderer records the poll IDs it receives.
     *
     * @param \ArrayObject<int, int> $calls  Recorded poll IDs.
     * @param string                 $markup Markup to return.
     */
    private function shortcode(\ArrayObject $calls, string $markup = '<aside class="zw-poll">poll</aside>'): PollShortcode
    {
        return new PollShortcode(static function (int $poll_id) use ($calls, $markup): string {
            $calls[] = $poll_id;
            return $markup;
        });
    }

    #[Test]
    public function registers_the_shortcode_on_init(): void
    {
        $shortcode = new PollShortcode();
        $shortcode->register();

        $this->assertNotFalse(has_action('init', [$shortcode, 'registerShortcode']));
    }

    #[Test]
    public function register_shortcode_adds_the_zw_poll_tag(): void
    {
        $shortcode = new PollShortcode();
        Functions\expect('add_shortcode')
            ->once()
            ->with(PollShortcode::TAG, [$shortcode, 'render']);

        $shortcode->registerShortcode();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function renders_nothing_without_an_id(): void
    {
        $calls = new \ArrayObject();
        Functions\expect('wp_interactivity_process_directives')->never();

        $this->assertSame('', $this->shortcode($calls)->render([]));
        $this->assertCount(0, $calls);
    }

    #[Test]
    public function renders_nothing_for_a_non_numeric_id(): void
    {
        $calls = new \ArrayObject();

        $this->assertSame('', $this->shortcode($calls)->render(['id' => 'abc']));
        $this->assertCount(0, $calls);
    }

    #[Test]
    public function renders_nothing_when_wordpress_passes_an_empty_string_for_atts(): void
    {
        $calls = new \ArrayObject();

        $this->assertSame('', $this->shortcode($calls)->render(''));
        $this->assertCount(0, $calls);
    }

    #[Test]
    public function processes_directives_and_enqueues_assets_for_a_rendered_poll(): void
    {
        $calls = new \ArrayObject();
        Functions\expect('wp_enqueue_style')->once()->with(Assets::STYLE_HANDLE);
        Functions\expect('wp_enqueue_script_module')->once()->with(Assets::MODULE_ID);
        Functions\expect('wp_interactivity_process_directives')
            ->once()
            ->with('<aside class="zw-poll">poll</aside>')
            ->andReturn('<aside class="zw-poll">processed</aside>');

        $this->assertSame(
            '<aside class="zw-poll">processed</aside>',
            $this->shortcode($calls)->render(['id' => '7'])
        );
        $this->assertSame([7], $calls->getArrayCopy());
    }

    #[Test]
    public function skips_assets_and_directives_when_the_poll_renders_nothing(): void
    {
        $calls = new \ArrayObject();
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_script_module')->never();
        Functions\expect('wp_interactivity_process_directives')->never();

        $this->assertSame('', $this->shortcode($calls, '')->render(['id' => '7']));
        $this->assertSame([7], $calls->getArrayCopy());
    }

    #[Test]
    public function builds_the_shortcode_text_for_a_poll(): void
    {
        $this->assertSame('[zw_poll id="7"]', PollShortcode::forPoll(7));
    }
}
