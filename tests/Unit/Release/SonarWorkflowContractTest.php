<?php

namespace Toggly\FeatureManagement\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

/**
 * Guard the PHP SDK's Sonar workflow against false-green credential and scan failures.
 */
class SonarWorkflowContractTest extends TestCase
{
    public function testTrustedAnalysisRequiresCoverageAndAllSonarCredentials(): void
    {
        $sonar = $this->sonarJob();

        $this->assertStringContainsString('needs: [commits-since-last-run, test]', $sonar);
        $this->assertStringContainsString("needs.test.result == 'success'", $sonar);
        $this->assertStringContainsString('scan_mode: ${{ steps.sonar-eligibility.outputs.scan }}', $sonar);
        $this->assertStringNotContainsString('if: always()', $sonar);
        $this->assertStringContainsString('Require coverage and test reports', $sonar);
        $this->assertStringContainsString('test -s coverage/coverage.xml', $sonar);
        $this->assertStringContainsString('test -s coverage/test-results.xml', $sonar);
        $this->assertStringContainsString('Require Sonar credentials for trusted analysis', $sonar);

        foreach (['SONAR_TOKEN', 'SONAR_SERVER_TOKEN', 'SONAR_HOST_URL'] as $credential) {
            $this->assertStringContainsString($credential, $sonar);
        }

        $this->assertMatchesRegularExpression(
            '/if \\[ -n "\\$missing_credentials" \\]; then\\s+'
            . 'echo "::error::Required Sonar credentials are unavailable: \\$missing_credentials\\." >&2\\s+'
            . 'exit 1\\s+fi/',
            $sonar
        );
    }

    public function testForkedPullRequestsSkipCredentialedScansWithoutUsingPullRequestTarget(): void
    {
        $workflow = $this->workflow();
        $sonar = $this->sonarJob();

        $this->assertStringContainsString('github.event.pull_request.head.repo.fork', $sonar);
        $this->assertStringContainsString("steps.sonar-eligibility.outputs.scan == 'true'", $sonar);
        $this->assertStringContainsString('Sonar scans are skipped for forked pull requests', $sonar);
        $this->assertSame(0, preg_match('/^\s*pull_request_target\s*:/m', $workflow));
    }

    public function testScannerAndQualityGateFailuresAreNotAllowedToPass(): void
    {
        $sonar = $this->sonarJob();

        $this->assertStringContainsString('SonarCloud Scan and Quality Gate', $sonar);
        $this->assertStringContainsString('SonarQube Server Scan and Quality Gate', $sonar);
        $this->assertSame(2, substr_count($sonar, '-Dsonar.qualitygate.wait=true'));
        $this->assertSame(2, substr_count($sonar, '-Dsonar.qualitygate.timeout=300'));
        $this->assertStringNotContainsString('continue-on-error: true', $sonar);
        $this->assertStringNotContainsString('dependency-check-report-php', $sonar);
    }

    public function testSummaryFailsInsteadOfReportingACompletedAnalysisAfterSonarFailure(): void
    {
        $summary = strstr($this->workflow(), '  summary:');
        $this->assertNotFalse($summary);

        $this->assertStringContainsString('SONAR_RESULT: ${{ needs.sonar.result }}', $summary);
        $this->assertStringContainsString('Sonar analysis did not complete successfully', $summary);
        $this->assertStringContainsString('Sonar scans were skipped for a forked pull request', $summary);
        $this->assertStringContainsString('Skipped analysis. main has no commits since the last run.', $summary);
        $this->assertStringContainsString('Could not determine whether main has commits since the last run', $summary);
        $this->assertStringNotContainsString('✅ Analysis workflow completed', $summary);
    }

    public function testNightlyScheduleRunsOnlyWhenMainHasNewCommits(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString("cron: '0 7 * * *'", $workflow);
        $this->assertStringContainsString('commits-since-last-run:', $workflow);
        $this->assertStringContainsString('should_run=false', $workflow);
        $this->assertSame(
            6,
            substr_count(
                $workflow,
                "github.event_name != 'schedule' || needs.commits-since-last-run.outputs.should_run == 'true'"
            )
        );
    }

    public function testSonarScansSendRootComposerVersion(): void
    {
        $sonar = $this->sonarJob();

        $this->assertSame(
            2,
            substr_count($sonar, '-Dsonar.projectVersion=${{ steps.sdk-version.outputs.version }}')
        );
        $this->assertStringContainsString(
            'Root composer.json version is required for Sonar analysis.',
            $sonar
        );
    }

    private function workflow(): string
    {
        $workflow = file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/ci.yml');
        $this->assertNotFalse($workflow);

        return $workflow;
    }

    private function sonarJob(): string
    {
        $sonar = strstr($this->workflow(), '  sonar:');
        $this->assertNotFalse($sonar);
        $sonar = strstr($sonar, "\n  dependency-check:", true);
        $this->assertNotFalse($sonar);

        return $sonar;
    }
}
