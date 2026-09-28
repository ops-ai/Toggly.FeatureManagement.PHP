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

        $this->assertStringContainsString('needs: [test]', $sonar);
        $this->assertStringContainsString('scan_mode: ${{ steps.sonar-eligibility.outputs.scan }}', $sonar);
        $this->assertStringNotContainsString('if: always()', $sonar);
        $this->assertStringContainsString('Require coverage and test reports', $sonar);
        $this->assertStringContainsString('test -s coverage/coverage.xml', $sonar);
        $this->assertStringContainsString('test -s coverage/test-results.xml', $sonar);
        $this->assertStringContainsString('Require Sonar credentials for trusted analysis', $sonar);

        foreach (['SONAR_TOKEN', 'SONAR_SERVER_TOKEN', 'SONAR_HOST_URL'] as $credential) {
            $this->assertStringContainsString($credential, $sonar);
        }
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
        $this->assertStringNotContainsString('✅ Analysis workflow completed', $summary);
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
