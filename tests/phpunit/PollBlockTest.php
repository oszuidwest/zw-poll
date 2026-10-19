<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZuidWest\Poll\Block\PollBlock;
use ZuidWest\Poll\Frontend\Assets;

final class PollBlockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('get_block_wrapper_attributes')->justReturn('class="wp-block-zw-poll-poll"');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function registers_a_php_only_interactive_block_with_an_integer_poll_control(): void
    {
        $block = new PollBlock();
        $block->register();
        $this->assertNotFalse(has_action('init', [$block, 'registerBlock']));
        Functions\expect('register_block_type')->once()->with(
            PollBlock::NAME,
            \Mockery::on(static fn (array $settings): bool =>
                $settings['api_version'] === 3
                && $settings['supports']['autoRegister'] === true
                && $settings['supports']['interactivity'] === true
                && $settings['attributes']['pollId']['type'] === 'integer'
                && $settings['attributes']['pollId']['label'] === 'Poll-ID'
                && $settings['style_handles'] === [Assets::STYLE_HANDLE]
                && $settings['render_callback'] === [$block, 'render']
            )
        );
        $block->registerBlock();
    }

    #[Test]
    public function renders_the_selected_poll_with_block_supports_and_shared_assets(): void
    {
        $received = [];
        $block = new PollBlock(static function (int $id) use (&$received): string {
            $received[] = $id;
            return '<aside>shared poll</aside>';
        });
        Functions\expect('wp_enqueue_style')->once()->with(Assets::STYLE_HANDLE);
        Functions\expect('wp_enqueue_script_module')->once()->with(Assets::MODULE_ID);

        $this->assertSame(
            '<div class="wp-block-zw-poll-poll"><aside>shared poll</aside></div>',
            $block->render(['pollId' => 7])
        );
        $this->assertSame([7], $received);
    }

    #[Test]
    public function an_unselected_block_explains_the_next_step_to_editors(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_enqueue_script_module')->justReturn(null);

        $this->assertStringContainsString('Vul een poll-ID in', (new PollBlock())->render([]));
    }

    #[Test]
    public function visitors_do_not_see_an_unselected_block(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_enqueue_style')->never();

        $this->assertSame('', (new PollBlock())->render(['pollId' => -1]));
    }

    #[Test]
    public function an_unavailable_poll_does_not_leave_an_empty_wrapper_or_enqueue_assets(): void
    {
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_script_module')->never();

        $this->assertSame('', (new PollBlock(static fn (): string => ''))->render(['pollId' => 7]));
    }
}
