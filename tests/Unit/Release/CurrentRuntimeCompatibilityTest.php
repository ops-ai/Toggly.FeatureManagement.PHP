<?php

namespace Toggly\FeatureManagement\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\SdkIdentity;

/**
 * Contract checks for the retained and current PHP framework support matrix.
 */
class CurrentRuntimeCompatibilityTest extends TestCase
{
    public function testPackageConstraintsRetainExistingPhpLinesAndIncludePhp85(): void
    {
        foreach (['composer.json', 'packages/laravel/composer.json', 'packages/wordpress/composer.json'] as $manifest) {
            $json = $this->readJson($manifest);

            $this->assertSame(
                '^7.4|^8.0|^8.1|^8.2|^8.3|^8.4|^8.5',
                $json['require']['php'],
                $manifest
            );
        }
    }

    public function testLaravelPackageDocumentsRetainedAndCurrentHostMajors(): void
    {
        $json = $this->readJson('packages/laravel/composer.json');

        $this->assertSame(
            'Laravel support package (^10.0|^11.0|^12.0|^13.0)',
            $json['suggest']['illuminate/support']
        );
        $this->assertSame(
            'Laravel HTTP integration (^10.0|^11.0|^12.0|^13.0)',
            $json['suggest']['illuminate/http']
        );
        $this->assertSame(
            'Laravel cache storage provider (^10.0|^11.0|^12.0|^13.0)',
            $json['suggest']['illuminate/cache']
        );
    }

    public function testCiExercisesPhp85AndCurrentLaravelHostsFromPackedArtifacts(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot() . '/.github/workflows/ci.yml');
        $releaseWorkflow = (string) file_get_contents($this->repoRoot() . '/.github/workflows/release.yml');

        $this->assertStringContainsString("'8.5'", $workflow);
        $this->assertStringContainsString("laravel-version: '12.12.2'", $workflow);
        $this->assertStringContainsString("laravel-version: '13.10.1'", $workflow);
        $this->assertStringContainsString('composer archive --format=tar', $workflow);
        $this->assertStringContainsString('repositories.toggly artifact', $workflow);
        $this->assertStringContainsString("'8.5'", $releaseWorkflow);
    }

    public function testCorePackageRuntimeIdentityMatchesTheManifestRelease(): void
    {
        $json = $this->readJson('composer.json');

        $this->assertSame('1.1.1', $json['version']);
        $this->assertSame('toggly-php/1.1.1', SdkIdentity::userAgent());
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relativePath): array
    {
        $content = file_get_contents($this->repoRoot() . '/' . $relativePath);
        $this->assertNotFalse($content);

        $json = json_decode($content, true);
        $this->assertIsArray($json);

        return $json;
    }
}
