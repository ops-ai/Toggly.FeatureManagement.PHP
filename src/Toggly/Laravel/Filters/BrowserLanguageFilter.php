<?php

namespace Toggly\Laravel\Filters;

use Illuminate\Http\Request;
use Toggly\FeatureManagement\Models\FeatureFilter;

/**
 * Legacy Laravel helper — not used by core FeatureManager evaluation.
 *
 * @deprecated Use core FeatureManager with EvalContext.request.acceptLanguage instead.
 */
class BrowserLanguageFilter
{
    /**
     * Evaluate browser language filter
     */
    public function evaluate(FeatureFilter $filter, ?Request $request = null): bool
    {
        if ($request === null) {
            return false;
        }

        $acceptLanguage = $request->header('Accept-Language', '');
        $allowedLanguages = explode(',', $filter->parameters['languages'] ?? '');

        foreach ($allowedLanguages as $language) {
            $language = trim($language);
            if (stripos($acceptLanguage, $language) !== false) {
                return true;
            }
        }

        return false;
    }
}
