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

    public function testRetainedHostFixturesUseScopedComposerAcknowledgementsAndWaitForMysql(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot() . '/.github/workflows/ci.yml');
        $policyFixture = (string) file_get_contents($this->repoRoot() . '/tests/HostFixtures/configure-laravel-policy.php');

        foreach ([
            'PKSA-m5cs-t1y6-qpcs',
            'PKSA-3r5d-mb8f-1qw9',
            'PKSA-mdq4-51ck-6kdq',
            'PKSA-8qx3-n5y5-vvnd',
            'PKSA-w7xr-vk7n-rstm',
            'PKSA-q46n-4fdk-zjr4',
            'PKSA-qzrn-rnz3-85w1',
        ] as $advisoryId) {
            $this->assertStringContainsString($advisoryId, $policyFixture);
        }

        $this->assertStringContainsString('create-project --no-install --no-scripts', $workflow);
        $this->assertStringContainsString('configure-laravel-policy.php', $workflow);
        $this->assertStringContainsString("'on-audit' => false", $policyFixture);
        $this->assertStringContainsString("'10.3.3' =>", $policyFixture);
        $this->assertStringContainsString("'11.6.1' =>", $policyFixture);
        $this->assertStringNotContainsString('--no-blocking', $workflow);
        $this->assertStringNotContainsString('--no-security-blocking', $workflow);

        $mysqlReady = strpos($workflow, '@mysqli_connect("127.0.0.1", "root", "root", "wordpress")');
        $wordpressInstall = strpos($workflow, 'wp core install --path="$wordpress"');
        $this->assertNotFalse($mysqlReady);
        $this->assertNotFalse($wordpressInstall);
        $this->assertLessThan($wordpressInstall, $mysqlReady);
    }

    public function testCorePackageRuntimeIdentityMatchesTheManifestRelease(): void
    {
        $json = $this->readJson('composer.json');

        $this->assertSame('1.1.2', $json['version']);
        $this->assertSame('toggly-php/1.1.2', SdkIdentity::userAgent());
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
