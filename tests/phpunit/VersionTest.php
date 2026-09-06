<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZuidWest\Poll\Activation;

final class VersionTest extends TestCase
{
    private function rootPath(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }

    private function pluginHeaderVersion(): string
    {
        $plugin = file_get_contents($this->rootPath('zw-poll.php'));
        $this->assertIsString($plugin);
        $this->assertMatchesRegularExpression('/^[ \t*]*Version:[ \t]*(.+)$/mi', $plugin);
        preg_match('/^[ \t*]*Version:[ \t]*(.+)$/mi', $plugin, $match);
        return trim($match[1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode(
            (string) file_get_contents($this->rootPath($path)),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }

    #[Test]
    public function plugin_header_is_the_runtime_and_schema_version_source(): void
    {
        $version = $this->pluginHeaderVersion();

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.\d+)?$/', $version);
        $this->assertSame($version, ZW_POLL_VERSION);
        $this->assertSame($version, Activation::DB_VERSION);
    }

    #[Test]
    public function npm_manifests_do_not_define_a_project_version(): void
    {
        $package = $this->jsonFile('package.json');
        $lock = $this->jsonFile('package-lock.json');

        $this->assertArrayNotHasKey('version', $package);
        $this->assertArrayNotHasKey('version', $lock);
        $this->assertArrayNotHasKey('version', $lock['packages']['']);
    }

    #[Test]
    public function pot_metadata_follows_the_header_version(): void
    {
        $version = $this->pluginHeaderVersion();
        $pot = (string) file_get_contents($this->rootPath('languages/zw-poll.pot'));

        $this->assertStringContainsString("Project-Id-Version: ZuidWest Poll {$version}\\n", $pot);
    }
}
