<?php

namespace Toggly\FeatureManagement\Core;

use Toggly\FeatureManagement\Contracts\FeatureAuthorizationServiceInterface;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\SecureFeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\FeatureFilter;

/**
 * Extended feature manager that records feature check stats and optionally checks for security.
 *
 * Evaluation context array keys (EvalContext parity):
 * - identity (string): sticky percentage / targeting user
 * - groups (string[]): targeting audience groups
 * - claims (array<string, string>): UserClaims principal claims
 * - request (array): userAgent, acceptLanguage, country
 * - traits (array): optional legacy/custom attributes
 *
 * Legacy keys userId / user_id are still accepted as identity fallbacks.
 */
class FeatureManager
{
    private FeatureProviderInterface $featureProvider;
    private UsageStatsProviderInterface $usageStatsProvider;
    private SecureFeatureProviderInterface $secureFeatureProvider;
    private ?FeatureAuthorizationServiceInterface $featureAuthorizationService;

    public function __construct(
        FeatureProviderInterface $featureProvider,
        UsageStatsProviderInterface $usageStatsProvider,
        SecureFeatureProviderInterface $secureFeatureProvider,
        ?FeatureAuthorizationServiceInterface $featureAuthorizationService = null
    ) {
        $this->featureProvider = $featureProvider;
        $this->usageStatsProvider = $usageStatsProvider;
        $this->secureFeatureProvider = $secureFeatureProvider;
        $this->featureAuthorizationService = $featureAuthorizationService;
    }

    /**
     * Check if a feature is enabled
     *
     * @param array<string, mixed>|null $context
     */
    public function isEnabled(string $feature, ?array $context = null): bool
    {
        $definition = $this->featureProvider->getFeatureDefinition($feature);
        if ($definition === null) {
            $this->usageStatsProvider->recordCheck($feature, false);
            return false;
        }

        $allowed = $this->evaluateFeature($definition, $context);

        if ($allowed && $this->secureFeatureProvider->isFeatureSecured($feature)) {
            if ($this->featureAuthorizationService !== null) {
                $allowed = $this->featureAuthorizationService->isAllowed($feature);
            }
        }

        if ($context !== null) {
            $this->usageStatsProvider->recordUsageWithContext($feature, $context, $allowed);
        } else {
            $this->usageStatsProvider->recordCheck($feature, $allowed);
        }

        return $allowed;
    }

    /**
     * Evaluate a feature definition against context without recording usage stats.
     * Useful for local/parity evaluation of Definitions-style filters.
     *
     * @param array<string, mixed>|null $context
     */
    public function evaluateDefinition(FeatureDefinition $definition, ?array $context = null): bool
    {
        return $this->evaluateFeature($definition, $context);
    }

    /**
     * Evaluate a feature definition based on its filters
     *
     * @param array<string, mixed>|null $context
     */
    private function evaluateFeature(FeatureDefinition $definition, ?array $context = null): bool
    {
        if (empty($definition->filters)) {
            return false;
        }

        $featureKey = $definition->featureKey;
        $results = [];

        foreach ($definition->filters as $filter) {
            $results[] = $this->evaluateFilter($filter, $featureKey, $context);
        }

        if ($definition->requirementType === 'All') {
            return !in_array(false, $results, true);
        }

        return in_array(true, $results, true);
    }

    /**
     * Evaluate a single filter. Unknown filter names fail closed.
     *
     * @param array<string, mixed>|null $context
     */
    private function evaluateFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        $name = $filter->name;

        switch ($name) {
            case 'AlwaysOn':
                return true;

            case 'AlwaysOff':
                return false;

            case 'Percentage':
            case 'Microsoft.Percentage':
                return $this->evaluatePercentageFilter($filter, $featureKey, $context);

            case 'TimeWindow':
            case 'Microsoft.TimeWindow':
                return $this->evaluateTimeWindowFilter($filter);

            case 'Targeting':
            case 'Microsoft.Targeting':
                return $this->evaluateTargetingFilter($filter, $featureKey, $context);

            case 'BrowserFamily':
                return $this->evaluateBrowserFamilyFilter($filter, $featureKey, $context);

            case 'BrowserLanguage':
                return $this->evaluateBrowserLanguageFilter($filter, $featureKey, $context);

            case 'Country':
            case 'CountryFamily':
                return $this->evaluateCountryFilter($filter, $featureKey, $context);

            case 'DeviceType':
                return $this->evaluateDeviceTypeFilter($filter, $featureKey, $context);

            case 'OS':
            case 'OperatingSystem':
                return $this->evaluateOperatingSystemFilter($filter, $featureKey, $context);

            case 'UserClaims':
                return $this->evaluateUserClaimsFilter($filter, $featureKey, $context);

            default:
                return false;
        }
    }

    /**
     * Sticky bucket in [0, 100) matching Definitions / toggly-eval SHA-256.
     * Hash input is featureKey + "\n" + userId; LE uint32 / 0xFFFFFFFF * 100.
     */
    public static function computePercentile(string $userId, string $featureKey): float
    {
        $digest = hash('sha256', $featureKey . "\n" . $userId, true);
        $unpacked = unpack('V', substr($digest, 0, 4));
        $value = $unpacked[1];
        // Ensure unsigned 32-bit on platforms where unpack may yield signed values.
        if ($value < 0) {
            $value += 4294967296.0;
        }

        return ($value / 4294967295.0) * 100.0;
    }

    /**
     * Percentage gate for segment filters; missing or ≤0 fails closed.
     */
    private function segmentPercentagePasses(?float $percentage, string $featureKey, ?string $identity): bool
    {
        if ($percentage === null || $percentage <= 0) {
            return false;
        }
        if ($percentage >= 100) {
            return true;
        }
        if ($identity !== null && $identity !== '') {
            return self::computePercentile($identity, $featureKey) < $percentage;
        }

        return (mt_rand() / mt_getrandmax()) * 100.0 < $percentage;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function evaluatePercentageFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        $percentage = $this->asFloat($filter->parameters, 'Value', 'Percentage', 'percentage');
        if ($percentage === null || $percentage <= 0) {
            return false;
        }
        if ($percentage >= 100) {
            return true;
        }

        $identity = $this->getIdentity($context);
        if ($identity === null || $identity === '') {
            return false;
        }

        return self::computePercentile($identity, $featureKey) < $percentage;
    }

    private function evaluateTimeWindowFilter(FeatureFilter $filter): bool
    {
        $now = time();
        $params = $filter->parameters ?? [];

        $startRaw = $params['Start'] ?? $params['start'] ?? null;
        $endRaw = $params['End'] ?? $params['end'] ?? null;

        if ($startRaw !== null && $startRaw !== '') {
            $start = strtotime((string)$startRaw);
            if ($start !== false && $now < $start) {
                return false;
            }
        }

        if ($endRaw !== null && $endRaw !== '') {
            $end = strtotime((string)$endRaw);
            if ($end !== false && $now > $end) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function evaluateTargetingFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        if ($context === null) {
            return false;
        }

        $params = $filter->parameters ?? [];
        $identity = $this->getIdentity($context);

        $users = $this->collectTargetingUsers($params);
        if ($identity !== null && $identity !== '' && in_array($identity, $users, true)) {
            return true;
        }

        $groups = $this->collectTargetingGroups($params);
        $userGroups = $this->getGroups($context);
        if ($groups !== [] && $userGroups !== [] && array_intersect($groups, $userGroups) !== []) {
            return true;
        }

        $defaultPercentage = $this->asFloat(
            $params,
            'Audience.DefaultRolloutPercentage',
            'DefaultRolloutPercentage',
            'defaultRolloutPercentage',
            'default_percentage',
            'Percentage'
        );
        if ($defaultPercentage !== null && $defaultPercentage > 0 && $identity !== null && $identity !== '') {
            return self::computePercentile($identity, $featureKey) < $defaultPercentage;
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function evaluateBrowserFamilyFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        $params = $filter->parameters ?? [];
        $percentage = $this->asFloat($params, 'Percentage');
        if (!$this->segmentPercentagePasses($percentage, $featureKey, $this->getIdentity($context))) {
            return false;
        }

        $values = $this->collectIndexedValues($params, 'BrowserFamily');
        if ($values === []) {
            return false;
        }

        $parsed = UserAgentParser::parse($this->getRequestField($context, 'userAgent'));
        if ($parsed === null || $parsed['browserFamily'] === 'Other') {
            return false;
        }

        return $this->anyContainsIgnoreCase($parsed['browserFamily'], $values);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function evaluateBrowserLanguageFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        $params = $filter->parameters ?? [];
        $percentage = $this->asFloat($params, 'Percentage');
        if (!$this->segmentPercentagePasses($percentage, $featureKey, $this->getIdentity($context))) {
            return false;
        }

        $values = $this->collectIndexedValues($params, 'BrowserLanguage');
        if ($values === []) {
            return false;
        }

        $accept = $this->getRequestField($context, 'acceptLanguage');
        if ($accept === null || $accept === '') {
            return false;
        }

        return $this->anyContainsIgnoreCase($accept, $values);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function evaluateCountryFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        $params = $filter->parameters ?? [];
        $percentage = $this->asFloat($params, 'Percentage');
        if (!$this->segmentPercentagePasses($percentage, $featureKey, $this->getIdentity($context))) {
            return false;
        }

        $values = $this->collectIndexedValues($params, 'Country');
        if ($values === []) {
            return false;
        }

        $country = $this->getRequestField($context, 'country');
        if ($country === null || $country === '') {
            return false;
        }

        foreach ($values as $value) {
            if (strcasecmp($value, $country) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function evaluateDeviceTypeFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        $params = $filter->parameters ?? [];
        $percentage = $this->asFloat($params, 'Percentage');
        if (!$this->segmentPercentagePasses($percentage, $featureKey, $this->getIdentity($context))) {
            return false;
        }

        $values = $this->collectIndexedValues($params, 'DeviceType');
        if ($values === []) {
            return false;
        }

        $parsed = UserAgentParser::parse($this->getRequestField($context, 'userAgent'));
        if ($parsed === null || $parsed['deviceFamily'] === 'Other') {
            return false;
        }

        return $this->anyContainsIgnoreCase($parsed['deviceFamily'], $values);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function evaluateOperatingSystemFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        $params = $filter->parameters ?? [];
        $percentage = $this->asFloat($params, 'Percentage');
        if (!$this->segmentPercentagePasses($percentage, $featureKey, $this->getIdentity($context))) {
            return false;
        }

        $values = $this->collectIndexedValues($params, 'OperatingSystem');
        if ($values === []) {
            return false;
        }

        $parsed = UserAgentParser::parse($this->getRequestField($context, 'userAgent'));
        if ($parsed === null || $parsed['osFamily'] === 'Other') {
            return false;
        }

        return $this->anyContainsIgnoreCase($parsed['osFamily'], $values);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function evaluateUserClaimsFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        $params = $filter->parameters ?? [];
        $percentage = $this->asFloat($params, 'Percentage');
        if (!$this->segmentPercentagePasses($percentage, $featureKey, $this->getIdentity($context))) {
            return false;
        }

        $claimType = $this->asString($params, 'Claim');
        $claimValue = $this->asString($params, 'Value');
        if ($claimType === null || $claimValue === null || $context === null) {
            return false;
        }

        $claims = $context['claims'] ?? null;
        if (!is_array($claims) || !array_key_exists($claimType, $claims)) {
            return false;
        }

        return (string)$claims[$claimType] === $claimValue;
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function asFloat(?array $params, string ...$keys): ?float
    {
        if ($params === null) {
            return null;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $params) || $params[$key] === null) {
                continue;
            }
            $value = $params[$key];
            if (is_bool($value)) {
                continue;
            }
            if (is_numeric($value)) {
                return (float)$value;
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function asString(array $params, string $key): ?string
    {
        if (!array_key_exists($key, $params) || $params[$key] === null) {
            return null;
        }
        $text = (string)$params[$key];

        return $text !== '' ? $text : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function collectIndexedValues(array $params, string ...$prefixes): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (strpos((string)$key, $prefix . ':') === 0) {
                    $text = (string)$value;
                    if ($text !== '') {
                        $out[] = $text;
                    }
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function collectTargetingUsers(array $params): array
    {
        $users = [];
        $usersStr = $params['users'] ?? $params['Users'] ?? null;
        if ($usersStr !== null && $usersStr !== '') {
            foreach (explode(',', (string)$usersStr) as $u) {
                $u = trim($u);
                if ($u !== '') {
                    $users[] = $u;
                }
            }
        }
        foreach ($params as $key => $value) {
            if (strpos((string)$key, 'Audience.Users:') === 0 && $value !== null && $value !== '') {
                $users[] = (string)$value;
            }
        }

        return $users;
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function collectTargetingGroups(array $params): array
    {
        $groups = [];
        $groupsStr = $params['groups'] ?? $params['Groups'] ?? null;
        if ($groupsStr !== null && $groupsStr !== '') {
            foreach (explode(',', (string)$groupsStr) as $g) {
                $g = trim($g);
                if ($g !== '') {
                    $groups[] = $g;
                }
            }
        }
        foreach ($params as $key => $value) {
            if (strpos((string)$key, 'Audience.Groups:') === 0 && $value !== null && $value !== '') {
                $groups[] = (string)$value;
            }
        }

        return $groups;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function getIdentity(?array $context): ?string
    {
        if ($context === null) {
            return null;
        }
        foreach (['identity', 'userId', 'user_id'] as $key) {
            if (isset($context[$key]) && $context[$key] !== null && $context[$key] !== '') {
                return (string)$context[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return string[]
     */
    private function getGroups(array $context): array
    {
        $userGroups = $context['groups'] ?? $context['group'] ?? [];
        if (is_string($userGroups)) {
            $userGroups = explode(',', $userGroups);
        }
        if (!is_array($userGroups)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $userGroups), static function ($g) {
            return $g !== '';
        }));
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function getRequestField(?array $context, string $camelKey): ?string
    {
        if ($context === null || !isset($context['request']) || !is_array($context['request'])) {
            return null;
        }
        $request = $context['request'];
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $camelKey) ?? $camelKey);
        if (isset($request[$camelKey]) && $request[$camelKey] !== null && $request[$camelKey] !== '') {
            return (string)$request[$camelKey];
        }
        if (isset($request[$snake]) && $request[$snake] !== null && $request[$snake] !== '') {
            return (string)$request[$snake];
        }

        return null;
    }

    /**
     * @param string[] $needles
     */
    private function anyContainsIgnoreCase(string $haystack, array $needles): bool
    {
        $haystackLower = strtolower($haystack);
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystackLower, strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assigned variant for a feature when variant mode is enabled (server-evaluated).
     *
     * @return array{name: string, configurationValue: mixed}|null
     */
    public function getVariant(string $featureKey): ?array
    {
        return $this->featureProvider->getVariant($featureKey);
    }

    /**
     * Configuration value for the feature's assigned variant, or null.
     *
     * @return mixed|null
     */
    public function getVariantValue(string $featureKey)
    {
        return $this->featureProvider->getVariantValue($featureKey);
    }
}
