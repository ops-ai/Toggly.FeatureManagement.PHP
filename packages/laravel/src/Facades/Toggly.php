<?php

namespace Toggly\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Toggly\FeatureManagement\Core\FeatureManager;

/**
 * Toggly Facade
 *
 * @method static bool isEnabled(string $feature, ?array $context = null)
 * @method static array{name: string, configurationValue: mixed}|null getVariant(string $featureKey)
 * @method static mixed getVariantValue(string $featureKey)
 */
class Toggly extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return FeatureManager::class;
    }
}
