<?php

namespace Toggly\Laravel\Filters;

use Illuminate\Http\Request;
use Toggly\FeatureManagement\Models\FeatureFilter;

/**
 * Legacy Laravel helper — not used by core FeatureManager evaluation.
 *
 * Segment filters (BrowserFamily, etc.) are evaluated in
 * {@see \Toggly\FeatureManagement\Core\FeatureManager} for filter-parity.
 *
 * @deprecated Use core FeatureManager with EvalContext.request.userAgent instead.
 */
class BrowserFamilyFilter
{
    /**
     * Evaluate browser family filter
     */
    public function evaluate(FeatureFilter $filter, ?Request $request = null): bool
    {
        if ($request === null) {
            return false;
        }

        $userAgent = $request->userAgent() ?? '';
        $allowedFamilies = explode(',', $filter->parameters['browserFamilies'] ?? '');

        foreach ($allowedFamilies as $family) {
            $family = trim($family);
            if (stripos($userAgent, $family) !== false) {
                return true;
            }
        }

        return false;
    }
}
