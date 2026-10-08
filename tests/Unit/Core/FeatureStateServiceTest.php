<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Core\FeatureStateService;

class FeatureStateServiceTest extends TestCase
{
    public function testStateTransitionsNotifyMatchingSubscribersOnceAndIgnoreDuplicateState(): void
    {
        $service = new FeatureStateService();
        $turnedOn = 0;
        $turnedOff = 0;

        $service->whenFeatureTurnsOn('new-checkout', static function () use (&$turnedOn): void {
            $turnedOn++;
        });
        $service->whenFeatureTurnsOff('new-checkout', static function () use (&$turnedOff): void {
            $turnedOff++;
        });

        $service->updateFeatureState('new-checkout', true);
        $service->updateFeatureState('new-checkout', true);
        $service->updateFeatureState('new-checkout', false);

        $this->assertSame(1, $turnedOn);
        $this->assertSame(1, $turnedOff);
    }

    public function testSubscribingAfterKnownStateImmediatelyDeliversTheMatchingCallback(): void
    {
        $service = new FeatureStateService();
        $service->updateFeatureState('dark-mode', true);
        $turnedOn = 0;

        $service->whenFeatureTurnsOn('dark-mode', static function () use (&$turnedOn): void {
            $turnedOn++;
        });
        $service->updateFeatureState('dark-mode', false);
        $turnedOff = 0;

        $service->whenFeatureTurnsOff('dark-mode', static function () use (&$turnedOff): void {
            $turnedOff++;
        });

        $this->assertSame(1, $turnedOn);
        $this->assertSame(1, $turnedOff);
    }

    public function testUnregisteringFeatureCallbackPreventsFutureStateNotifications(): void
    {
        $service = new FeatureStateService();
        $calls = 0;
        $subscription = $service->whenFeatureTurnsOn('invoices', static function () use (&$calls): void {
            $calls++;
        });

        $this->assertTrue($service->unregisterFeatureStateChange('invoices', $subscription));
        $this->assertFalse($service->unregisterFeatureStateChange('invoices', $subscription));

        $service->updateFeatureState('invoices', true);

        $this->assertSame(0, $calls);
    }

    public function testDefinitionChangeSubscribersCanBeRemoved(): void
    {
        $service = new FeatureStateService();
        $calls = 0;
        $subscription = $service->whenDefinitionsChange(static function () use (&$calls): void {
            $calls++;
        });

        $service->notifyDefinitionsChanged();

        $this->assertTrue($service->unregisterDefinitionsChange($subscription));
        $this->assertFalse($service->unregisterDefinitionsChange($subscription));

        $service->notifyDefinitionsChanged();

        $this->assertSame(1, $calls);
    }

    public function testThrowingSubscriberDoesNotPreventOtherSubscribers(): void
    {
        $service = new FeatureStateService();
        $delivered = 0;
        $service->whenFeatureTurnsOn('reports', static function (): void {
            throw new \RuntimeException('subscriber failed');
        });
        $service->whenFeatureTurnsOn('reports', static function () use (&$delivered): void {
            $delivered++;
        });

        $service->updateFeatureState('reports', true);

        $this->assertSame(1, $delivered);
    }

    public function testStringableAndEnumLikeFeatureKeysNormalizeToTheSameSubscription(): void
    {
        $service = new FeatureStateService();
        $fromStringable = 0;
        $fromName = 0;
        $fromValue = 0;
        $stringable = new class {
            public function __toString(): string
            {
                return 'stringable-key';
            }
        };
        $named = new class {
            public string $name = 'named-key';
        };
        $valued = new class {
            public int $value = 12;
        };

        $service->whenFeatureTurnsOn($stringable, static function () use (&$fromStringable): void {
            $fromStringable++;
        });
        $service->whenFeatureTurnsOn($named, static function () use (&$fromName): void {
            $fromName++;
        });
        $service->whenFeatureTurnsOn($valued, static function () use (&$fromValue): void {
            $fromValue++;
        });

        $service->updateFeatureState('stringable-key', true);
        $service->updateFeatureState('named-key', true);
        $service->updateFeatureState('12', true);

        $this->assertSame(1, $fromStringable);
        $this->assertSame(1, $fromName);
        $this->assertSame(1, $fromValue);
    }

    public function testInvalidFeatureKeyIsRejectedBeforeRegistration(): void
    {
        $service = new FeatureStateService();

        $this->expectException(\InvalidArgumentException::class);
        $service->whenFeatureTurnsOn(new \stdClass(), static function (): void {
        });
    }
}
