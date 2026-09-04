<?php

namespace Toggly\FeatureManagement\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\SecureFeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Core\FeatureManager;
use Toggly\FeatureManagement\Core\HttpRequestMapper;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\FeatureFilter;

/**
 * Golden filter-parity fixtures (vendored under tests/fixtures/filter-parity/).
 *
 * Canonical source: ops-ai/Toggly.FeatureManagement docs/filter-parity/fixtures.
 * Override discovery with TOGGLY_FILTER_PARITY_FIXTURES when needed.
 */
class FilterParityFixturesTest extends TestCase
{
    /** @var string[] */
    private const REQUIRED_IDS = [
        'browser-family-match',
        'browser-family-miss',
        'browser-language-match',
        'country-from-request',
        'country-from-cf-ipcountry',
        'device-type-match',
        'os-match',
        'user-claims-match',
        'user-claims-miss',
        'targeting-groups-match',
        'percentage-missing-fail-closed',
        'percentage-zero-fail-closed',
        'unknown-filter-fail-closed',
    ];

    public function testLoadsRequiredWave1Cases(): void
    {
        $fixtures = self::loadFixtures();
        if ($fixtures === []) {
            $this->markTestSkipped('filter-parity fixtures not found');
        }

        $ids = array_map(static function (array $pair) {
            return $pair[0];
        }, $fixtures);

        foreach (self::REQUIRED_IDS as $required) {
            $this->assertContains($required, $ids, "missing fixture {$required}");
        }
    }

    /**
     * @dataProvider goldenFixtureProvider
     */
    public function testGoldenFixture(string $fixtureId, array $root): void
    {
        $manager = $this->createFeatureManager();
        $definition = $this->toDefinition($root);
        $context = $this->toContext($root);
        $expected = (bool)$root['expected'];
        $actual = $manager->evaluateDefinition($definition, $context);
        $this->assertSame($expected, $actual, "fixture {$fixtureId} failed");
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function goldenFixtureProvider(): array
    {
        $cases = [];
        foreach (self::loadFixtures() as [$fixtureId, $root]) {
            $cases[$fixtureId] = [$fixtureId, $root];
        }

        // Empty set: no golden cases run (fixtures absent). Required-ID test skips.
        return $cases;
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private static function loadFixtures(): array
    {
        $directory = self::resolveFixturesDir();
        if ($directory === null) {
            return [];
        }

        $fixtures = [];
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files);
        foreach ($files as $path) {
            $data = json_decode((string)file_get_contents($path), true);
            if (!is_array($data) || !isset($data['id'])) {
                continue;
            }
            $fixtures[] = [$data['id'], $data];
        }

        return $fixtures;
    }

    private static function resolveFixturesDir(): ?string
    {
        $env = getenv('TOGGLY_FILTER_PARITY_FIXTURES');
        if (is_string($env) && $env !== '' && is_dir($env)) {
            return realpath($env) ?: $env;
        }

        // Vendored copy shipped with this package (CI-safe).
        $vendored = dirname(__DIR__) . '/fixtures/filter-parity';
        if (is_dir($vendored)) {
            return realpath($vendored) ?: $vendored;
        }

        $cwd = getcwd() ?: __DIR__;
        $candidates = [
            $cwd . '/tests/fixtures/filter-parity',
            $cwd . '/docs/filter-parity/fixtures',
            $cwd . '/../docs/filter-parity/fixtures',
            $cwd . '/../../docs/filter-parity/fixtures',
            $cwd . '/../../../docs/filter-parity/fixtures',
            $cwd . '/../Toggly.FeatureManagement/docs/filter-parity/fixtures',
            $cwd . '/../../Toggly.FeatureManagement/docs/filter-parity/fixtures',
            dirname(__DIR__, 2) . '/../Toggly.FeatureManagement/docs/filter-parity/fixtures',
            dirname(__DIR__, 2) . '/../../Toggly.FeatureManagement/docs/filter-parity/fixtures',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $walk = realpath($cwd) ?: $cwd;
        for ($i = 0; $i < 8; $i++) {
            $candidate = $walk . '/docs/filter-parity/fixtures';
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
            $sibling = $walk . '/Toggly.FeatureManagement/docs/filter-parity/fixtures';
            if (is_dir($sibling)) {
                return realpath($sibling) ?: $sibling;
            }
            $parent = dirname($walk);
            if ($parent === $walk) {
                break;
            }
            $walk = $parent;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $root
     */
    private function toDefinition(array $root): FeatureDefinition
    {
        $filters = [];
        foreach ($root['filters'] ?? [] as $filter) {
            $filters[] = new FeatureFilter([
                'name' => $filter['name'],
                'parameters' => $filter['parameters'] ?? [],
            ]);
        }

        return new FeatureDefinition([
            'featureKey' => $root['featureKey'],
            'filters' => $filters,
            'requirementType' => $root['requirementType'] ?? 'Any',
        ]);
    }

    /**
     * @param array<string, mixed> $root
     * @return array<string, mixed>
     */
    private function toContext(array $root): array
    {
        $context = $root['context'] ?? [];
        if (!is_array($context)) {
            $context = [];
        }
        $headers = $root['httpHeaders'] ?? null;
        if (is_array($headers) && $headers !== []) {
            return HttpRequestMapper::mergeIntoContext($headers, $context);
        }

        return $context;
    }

    private function createFeatureManager(): FeatureManager
    {
        $featureProvider = $this->createStub(FeatureProviderInterface::class);
        $usageStats = $this->createStub(UsageStatsProviderInterface::class);
        $secureProvider = $this->createStub(SecureFeatureProviderInterface::class);

        return new FeatureManager($featureProvider, $usageStats, $secureProvider);
    }
}
