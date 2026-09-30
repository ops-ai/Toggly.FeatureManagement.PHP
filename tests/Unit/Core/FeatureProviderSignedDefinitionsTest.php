<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Security\EcdsaSignatureVerifier;

final class FeatureProviderSignedDefinitionsTest extends TestCase
{
    public function testAppliesVerifiedSignedDefinitions(): void
    {
        $provider = $this->provider('{"defs":[{"featureKey":"signed-flag"}],"signature":"sig","kid":"kid","timestamp":10}', true);
        $provider->refreshFeatures();
        $this->assertNotNull($provider->getFeatureDefinition('signed-flag'));
    }

    public function testInvalidSignatureFailsClosedWithoutDefinitions(): void
    {
        $provider = $this->provider('{"defs":[{"featureKey":"blocked"}],"signature":"sig","kid":"kid","timestamp":10}', false);
        $provider->refreshFeatures();
        $this->assertNull($provider->getFeatureDefinition('blocked'));
        $this->assertSame('Invalid signature', $provider->getDebugInfo()['last_error']);
    }

    private function provider(string $json, bool $valid): FeatureProvider
    {
        $body=$this->createStub(StreamInterface::class);$body->method('getContents')->willReturn($json);$body->method('rewind');
        $response=$this->createStub(ResponseInterface::class);$response->method('getBody')->willReturn($body);
        $http=$this->createMock(TogglyHttpClient::class);$http->method('get')->willReturn($response);$http->method('getLastETag')->willReturn(null);
        $provider=new FeatureProvider(new TogglySettings(['app_key'=>'app','environment'=>'Production','use_signed_definitions'=>true,'enable_live_updates'=>false]),$http,$this->createStub(FeatureStateServiceInterface::class));
        $verifier=$this->createMock(EcdsaSignatureVerifier::class);$verifier->method('verify')->willReturn($valid);
        (new \ReflectionProperty($provider,'signatureVerifier'))->setValue($provider,$verifier);
        return $provider;
    }
}
