<?php

namespace Toggly\FeatureManagement\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Storage\SnapshotProviders\FileSnapshotProvider;
use Toggly\FeatureManagement\Storage\SnapshotSettings;

final class FileSnapshotProviderCorruptionTest extends TestCase
{
    private string $directory;
    private SnapshotSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/toggly-file-snapshot-corruption-' . bin2hex(random_bytes(8));
        $this->settings = new SnapshotSettings([
            'document_name' => 'custom-features.json',
            'jwk_document_name' => 'custom-jwks.json',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testConstructorCreatesDirectoryAndCorruptFeatureSnapshotFailsClosed(): void
    {
        $provider = new FileSnapshotProvider($this->directory, $this->settings);
        file_put_contents($this->directory . '/custom-features.json', '{"features": [');

        $snapshot = $provider->getFeaturesSnapshot();

        $this->assertDirectoryExists($this->directory);
        $this->assertSame([
            'features' => null,
            'signature' => null,
            'keyId' => null,
            'timestamp' => null,
            'signedDefsJson' => null,
            'etag' => null,
        ], $snapshot);
    }

    public function testCorruptJwkSnapshotDoesNotExposeAKeyOrTimestamp(): void
    {
        $provider = new FileSnapshotProvider($this->directory, $this->settings);
        file_put_contents($this->directory . '/custom-jwks.json', '{"jwks":');

        $snapshot = $provider->getJwkSnapshot();

        $this->assertSame(['jwks' => null, 'timestamp' => null], $snapshot);
    }
}
