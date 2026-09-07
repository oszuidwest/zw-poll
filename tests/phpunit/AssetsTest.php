<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZuidWest\Poll\Frontend\Assets;

final class AssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function registers_the_frontend_assets_and_module_translations(): void
    {
        Functions\expect('wp_register_style')
            ->once()
            ->with(
                Assets::STYLE_HANDLE,
                ZW_POLL_URL . 'src/Frontend/style.css',
                [],
                ZW_POLL_VERSION
            );
        Functions\expect('wp_register_script_module')
            ->once()
            ->with(
                Assets::MODULE_ID,
                ZW_POLL_URL . 'src/Frontend/view.js',
                [['id' => '@wordpress/interactivity', 'import' => 'static']],
                ZW_POLL_VERSION,
                [
                    'in_footer' => true,
                    'fetchpriority' => 'low',
                ]
            );
        Functions\expect('wp_set_script_module_translations')
            ->once()
            ->with(Assets::MODULE_ID, 'zw-poll', ZW_POLL_DIR . 'languages');

        (new Assets())->registerAssets();

        $this->addToAssertionCount(1);
    }
}
