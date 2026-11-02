<?php

namespace Toggly\FeatureManagement\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\SdkIdentity;

/**
 * Contract checks for the current PHP framework support matrix.
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

    public function testLaravelPackageDocumentsCurrentHostMajorsOnly(): void
    {
        $json = $this->readJson('packages/laravel/composer.json');

        $this->assertSame(
            'Laravel support package (^12.0|^13.0)',
            $json['suggest']['illuminate/support']
        );
        $this->assertSame(
            'Laravel HTTP integration (^12.0|^13.0)',
            $json['suggest']['illuminate/http']
        );
        $this->assertSame(
            'Laravel cache storage provider (^12.0|^13.0)',
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
        $this->assertStringNotContainsString("laravel-version: '10.3.3'", $workflow);
        $this->assertStringNotContainsString("laravel-version: '11.6.1'", $workflow);
        $this->assertStringContainsString('composer archive --format=tar', $workflow);
        $this->assertStringContainsString('repositories.toggly artifact', $workflow);
        $this->assertStringContainsString("'8.5'", $releaseWorkflow);
    }

    public function testLaravelHostJobsDoNotIgnoreSecurityAdvisoriesAndWordPressWaitsForMysql(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot() . '/.github/workflows/ci.yml');
        $policyFixture = (string) file_get_contents($this->repoRoot() . '/tests/HostFixtures/configure-laravel-policy.php');

        $this->assertStringContainsString('create-project --no-install --no-scripts', $workflow);
        $this->assertStringContainsString('configure-laravel-policy.php', $workflow);
        $this->assertStringContainsString('PKSA-b41t-wbzk-9q51', $policyFixture);
        $this->assertStringNotContainsString('ignore-id', $policyFixture);
        $this->assertStringNotContainsString("'10.3.3' =>", $policyFixture);
        $this->assertStringNotContainsString("'11.6.1' =>", $policyFixture);
        $this->assertStringNotContainsString('--no-blocking', $workflow);
        $this->assertStringNotContainsString('--no-security-blocking', $workflow);
        $this->assertStringNotContainsString('advisories.block', $workflow);

        $mysqlReady = strpos($workflow, '@mysqli_connect("127.0.0.1", "root", "root", "wordpress")');
        $wordpressInstall = strpos($workflow, 'wp core install --path="$wordpress"');
        $this->assertNotFalse($mysqlReady);
        $this->assertNotFalse($wordpressInstall);
        $this->assertLessThan($wordpressInstall, $mysqlReady);

        $wordpressJob = strstr($workflow, '  wordpress-host:');
        $this->assertNotFalse($wordpressJob);
        $this->assertStringContainsString("MYSQL_ROOT_HOST: '%'", $wordpressJob);
        $this->assertStringNotContainsString('MYSQL_ALLOW_EMPTY_PASSWORD', $wordpressJob);
        $this->assertStringNotContainsString('MYSQL_USER:', $wordpressJob);

        $wordpressService = strstr($wordpressJob, '      mysql:');
        $this->assertNotFalse($wordpressService);
        $wordpressService = strstr($wordpressService, "\n\n    steps:", true);
        $this->assertNotFalse($wordpressService);
        $this->assertStringContainsString("ports:\n          - 3306:3306", $wordpressService);
    }

    public function testConfigureLaravelPolicyIsANoOpForSupportedHosts(): void
    {
        foreach (['12.12.2', '13.10.1'] as $laravelVersion) {
            $hostDirectory = sys_get_temp_dir() . '/toggly-laravel-policy-' . bin2hex(random_bytes(8));
            mkdir($hostDirectory);
            $manifestPath = $hostDirectory . '/composer.json';
            file_put_contents($manifestPath, "{}\n");

            try {
                $command = sprintf(
                    '%s %s %s %s',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg($this->repoRoot() . '/tests/HostFixtures/configure-laravel-policy.php'),
                    escapeshellarg($hostDirectory),
                    escapeshellarg($laravelVersion)
                );
                exec($command, $output, $exitCode);

                $this->assertSame(0, $exitCode, implode("\n", $output));

                $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame([], $manifest);
                $this->assertArrayNotHasKey('config', $manifest);
            } finally {
                unlink($manifestPath);
                rmdir($hostDirectory);
            }
        }
    }

    public function testCorePackageRuntimeIdentityMatchesTheManifestRelease(): void
    {
        $json = $this->readJson('composer.json');

        $this->assertSame('1.3.1', $json['version']);
        $this->assertSame('toggly-php/1.3.1', SdkIdentity::userAgent());
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
