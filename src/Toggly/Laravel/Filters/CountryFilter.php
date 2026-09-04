<?php

namespace Toggly\Laravel\Filters;

use Illuminate\Http\Request;
use Toggly\FeatureManagement\Models\FeatureFilter;

/**
 * Legacy Laravel helper — not used by core FeatureManager evaluation.
 *
 * @deprecated Use core FeatureManager + HttpRequestMapper / request.country instead.
 */
class CountryFilter
{
    /**
     * Evaluate country filter
     */
    public function evaluate(FeatureFilter $filter, ?Request $request = null): bool
    {
        if ($request === null) {
            return false;
        }

        // In a real implementation, you'd use a geolocation service
        // For now, this is a placeholder
        $ip = $request->ip();
        $allowedCountries = explode(',', $filter->parameters['countries'] ?? '');

        // TODO: Implement actual IP geolocation lookup
        // For now, return false as placeholder
        return false;
    }
}
