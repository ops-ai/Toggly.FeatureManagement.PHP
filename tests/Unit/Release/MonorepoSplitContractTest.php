<?php

namespace Toggly\FeatureManagement\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

/**
 * Contract checks for Packagist/mirror split readiness (OPS-963).
 */
class MonorepoSplitContractTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testRootComposerIsPublishableCorePackage(): void
    {
        $json = $this->readJson('composer.json');

        $this->assertSame('toggly/feature-management-php', $json['name']);
        $this->assertSame('1.0.0', $json['version']);
        $this->assertSame(['Toggly\\FeatureManagement\\' => 'src/'], $json['autoload']['psr-4']);
        $this->assertArrayNotHasKey('Toggly\\Laravel\\', $json['autoload']['psr-4'] ?? []);
        $this->assertArrayNotHasKey('Toggly\\WordPress\\', $json['autoload']['psr-4'] ?? []);
    }

    public function testLaravelPackageIsPackagistReadyWithoutPathRepos(): void
    {
        $json = $this->readJson('packages/laravel/composer.json');

        $this->assertSame('toggly/laravel', $json['name']);
        $this->assertSame('1.0.0', $json['version']);
        $this->assertSame('^1.0', $json['require']['toggly/feature-management-php']);
        $this->assertArrayNotHasKey('repositories', $json);
        $this->assertSame(['Toggly\\Laravel\\' => 'src/'], $json['autoload']['psr-4']);
    }

    public function testWordpressPackageIsPackagistReadyWithoutPathRepos(): void
    {
        $json = $this->readJson('packages/wordpress/composer.json');

        $this->assertSame('toggly/wordpress', $json['name']);
        $this->assertSame('1.0.0', $json['version']);
        $this->assertSame('^1.0', $json['require']['toggly/feature-management-php']);
        $this->assertArrayNotHasKey('repositories', $json);
        $this->assertSame(['Toggly\\WordPress\\' => 'src/'], $json['autoload']['psr-4']);
    }

    public function testReleaseWorkflowSplitsExpectedMirrorRepos(): void
    {
        $yml = file_get_contents($this->repoRoot() . '/.github/workflows/release.yml');
        $this->assertNotFalse($yml);

        $this->assertStringContainsString('manifest_path: composer.json', $yml);
        $this->assertStringContainsString('danharrin/monorepo-split-github-action@v2.4.0', $yml);
        $this->assertStringContainsString('packages/${{ matrix.package.local_path }}', $yml);
        $this->assertStringContainsString('Toggly.FeatureManagement.PHP.Laravel', $yml);
        $this->assertStringContainsString('Toggly.FeatureManagement.PHP.Wordpress', $yml);
        $this->assertStringContainsString('local_path: laravel', $yml);
        $this->assertStringContainsString('local_path: wordpress', $yml);
        $this->assertStringContainsString('RELEASE_PUSH_TOKEN', $yml);
        $this->assertStringNotContainsString('packages/feature-management-php', $yml);
    }

    public function testCorePackageDirectoryWasRemoved(): void
    {
        $this->assertDirectoryDoesNotExist($this->repoRoot() . '/packages/feature-management-php');
        $this->assertDirectoryExists($this->repoRoot() . '/src');
        $this->assertFileExists($this->repoRoot() . '/src/SdkIdentity.php');
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relativePath): array
    {
        $path = $this->repoRoot() . '/' . $relativePath;
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
