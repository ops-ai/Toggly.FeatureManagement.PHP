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
     * Targeting / Microsoft.Targeting evaluation.
     *
     * Accepts both the nested Audience object (Toggly API /
     * Microsoft.FeatureManagement) and flat Definitions-style keys
     * (Audience.Users:N, Audience.Groups:N, Audience.Exclusion.*:N).
     *
     * Order matches .NET / toggly-eval: exclusions → included users →
     * group rollouts → default rollout. Bucketing uses computePercentile
     * (featureKey + "\n" + userId); the algorithm is unchanged.
     *
     * @param array<string, mixed>|null $context
     */
    private function evaluateTargetingFilter(FeatureFilter $filter, string $featureKey, ?array $context = null): bool
    {
        $enabled = false;
        if ($context !== null) {
            $params = $filter->parameters ?? [];
            $audience = $this->resolveTargetingAudience($params);
            $ignoreCase = $this->asBool($params, 'IgnoreCase');
            if ($ignoreCase === null) {
                // Match Definitions / .NET TogglyTargetingFilter / toggly-eval default.
                $ignoreCase = true;
            }

            $identity = $this->getIdentity($context);
            $userGroups = $this->getGroups($context);
            $excluded = $this->isTargetingExcluded(
                $audience['exclusionUsers'],
                $audience['exclusionGroups'],
                $identity,
                $userGroups,
                $ignoreCase
            );
            if (!$excluded) {
                $includedUser = $identity !== null && $identity !== ''
                    && $this->stringListContains($audience['users'], $identity, $ignoreCase);
                if ($includedUser) {
                    $enabled = true;
                } elseif ($this->matchesAnyGroupRollout(
                    $audience['groups'],
                    $userGroups,
                    $identity,
                    $featureKey,
                    $ignoreCase
                )) {
                    $enabled = true;
                } else {
                    $enabled = $this->matchesDefaultRollout(
                        $audience['defaultRollout'],
                        $identity,
                        $featureKey
                    );
                }
            }
        }

        return $enabled;
    }

    /**
     * @param list<array{name: string, rollout: float|null}> $groups
     * @param string[] $userGroups
     */
    private function matchesAnyGroupRollout(
        array $groups,
        array $userGroups,
        ?string $identity,
        string $featureKey,
        bool $ignoreCase
    ): bool {
        $matched = false;
        foreach ($groups as $group) {
            if ($matched) {
                break;
            }
            $groupName = $group['name'];
            if ($groupName === '' || !$this->stringListContains($userGroups, $groupName, $ignoreCase)) {
                continue;
            }
            $rollout = $group['rollout'];
            // Flat Audience.Groups:N strings and nested groups without an
            // explicit RolloutPercentage are full includes.
            if ($rollout === null || $rollout >= 100) {
                $matched = true;
            } elseif ($rollout > 0 && $identity !== null && $identity !== ''
                && self::computePercentile($identity, $featureKey) < $rollout
            ) {
                $matched = true;
            }
        }

        return $matched;
    }

    private function matchesDefaultRollout(?float $defaultPercentage, ?string $identity, string $featureKey): bool
    {
        $matched = false;
        if ($defaultPercentage !== null && $defaultPercentage > 0) {
            if ($defaultPercentage >= 100) {
                $matched = true;
            } elseif ($identity !== null && $identity !== '') {
                $matched = self::computePercentile($identity, $featureKey) < $defaultPercentage;
            }
        }

        return $matched;
    }

    /**
     * Normalize nested Audience JSON and flat Audience.* keys into one shape.
     *
     * @param array<string, mixed> $params
     * @return array{
     *     users: string[],
     *     groups: list<array{name: string, rollout: float|null}>,
     *     exclusionUsers: string[],
     *     exclusionGroups: string[],
     *     defaultRollout: float|null
     * }
     */
    private function resolveTargetingAudience(array $params): array
    {
        $state = [
            'users' => [],
            'groups' => [],
            'exclusionUsers' => [],
            'exclusionGroups' => [],
            'defaultRollout' => null,
        ];
        $this->mergeNestedAudience($state, $params['Audience'] ?? null);
        $this->appendStringList($state['users'], $params['users'] ?? $params['Users'] ?? null);
        $this->appendGroupEntries($state['groups'], $params['groups'] ?? $params['Groups'] ?? null);
        $this->mergeIndexedAudienceKeys($state, $params);
        if ($state['defaultRollout'] === null) {
            $state['defaultRollout'] = $this->asFloat(
                $params,
                'Audience.DefaultRolloutPercentage',
                'Audience:DefaultRolloutPercentage',
                'DefaultRolloutPercentage',
                'defaultRolloutPercentage',
                'default_percentage',
                'Percentage'
            );
        }

        return $state;
    }

    /**
     * @param array{
     *     users: string[],
     *     groups: list<array{name: string, rollout: float|null}>,
     *     exclusionUsers: string[],
     *     exclusionGroups: string[],
     *     defaultRollout: float|null
     * } $state
     * @param mixed $audience
     */
    private function mergeNestedAudience(array &$state, $audience): void
    {
        if (!is_array($audience)) {
            return;
        }

        $this->appendStringList($state['users'], $audience['Users'] ?? $audience['users'] ?? null);
        $exclusion = $audience['Exclusion'] ?? null;
        if (is_array($exclusion)) {
            $this->appendStringList($state['exclusionUsers'], $exclusion['Users'] ?? $exclusion['users'] ?? null);
            $this->appendStringList($state['exclusionGroups'], $exclusion['Groups'] ?? $exclusion['groups'] ?? null);
        }

        $this->appendGroupEntries($state['groups'], $audience['Groups'] ?? $audience['groups'] ?? null);

        if (array_key_exists('DefaultRolloutPercentage', $audience)
            || array_key_exists('defaultRolloutPercentage', $audience)
        ) {
            $rawDefault = $audience['DefaultRolloutPercentage'] ?? $audience['defaultRolloutPercentage'];
            if (is_numeric($rawDefault)) {
                $state['defaultRollout'] = (float)$rawDefault;
            }
        }
    }

    /**
     * @param array{
     *     users: string[],
     *     groups: list<array{name: string, rollout: float|null}>,
     *     exclusionUsers: string[],
     *     exclusionGroups: string[],
     *     defaultRollout: float|null
     * } $state
     * @param array<string, mixed> $params
     */
    private function mergeIndexedAudienceKeys(array &$state, array $params): void
    {
        $namedGroupRollouts = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $keyStr = (string)$key;
            if ($this->targetingKeyMatches($keyStr, 'Audience.Users', 'Audience:Users')) {
                $state['users'][] = (string)$value;
                continue;
            }
            if ($this->targetingKeyMatches($keyStr, 'Audience.Exclusion.Users', 'Audience:Exclusion:Users')) {
                $state['exclusionUsers'][] = (string)$value;
                continue;
            }
            if ($this->targetingKeyMatches($keyStr, 'Audience.Exclusion.Groups', 'Audience:Exclusion:Groups')) {
                $state['exclusionGroups'][] = (string)$value;
                continue;
            }
            $this->mergeIndexedGroupKey($state['groups'], $namedGroupRollouts, $keyStr, $value);
        }

        foreach ($namedGroupRollouts as $named) {
            $name = trim((string)$named['name']);
            if ($name !== '') {
                $state['groups'][] = ['name' => $name, 'rollout' => $named['rollout']];
            }
        }
    }

    /**
     * @param list<array{name: string, rollout: float|null}> $groups
     * @param array<string, array{name: string, rollout: float|null}> $namedGroupRollouts
     * @param mixed $value
     */
    private function mergeIndexedGroupKey(array &$groups, array &$namedGroupRollouts, string $keyStr, $value): void
    {
        if (preg_match('/^Audience[.:]Groups:(\d+)[.:]Name$/i', $keyStr, $m) === 1) {
            $idx = $m[1];
            if (!isset($namedGroupRollouts[$idx])) {
                $namedGroupRollouts[$idx] = ['name' => '', 'rollout' => null];
            }
            $namedGroupRollouts[$idx]['name'] = (string)$value;

            return;
        }
        if (preg_match('/^Audience[.:]Groups:(\d+)[.:]RolloutPercentage$/i', $keyStr, $m) === 1) {
            $idx = $m[1];
            if (!isset($namedGroupRollouts[$idx])) {
                $namedGroupRollouts[$idx] = ['name' => '', 'rollout' => null];
            }
            if (is_numeric($value)) {
                $namedGroupRollouts[$idx]['rollout'] = (float)$value;
            }

            return;
        }
        if (preg_match('/^Audience[.:]Groups:(\d+)$/i', $keyStr) === 1) {
            $groups[] = ['name' => (string)$value, 'rollout' => null];
        }
    }

    /**
     * @param string[] $exclusionUsers
     * @param string[] $exclusionGroups
     * @param string[] $userGroups
     */
    private function isTargetingExcluded(
        array $exclusionUsers,
        array $exclusionGroups,
        ?string $identity,
        array $userGroups,
        bool $ignoreCase
    ): bool {
        $excluded = $identity !== null && $identity !== ''
            && $this->stringListContains($exclusionUsers, $identity, $ignoreCase);
        if (!$excluded && $exclusionGroups !== [] && $userGroups !== []) {
            foreach ($exclusionGroups as $candidate) {
                if ($this->stringListContains($userGroups, $candidate, $ignoreCase)) {
                    $excluded = true;
                    break;
                }
            }
        }

        return $excluded;
    }

    /**
     * @param string[] $list
     */
    private function stringListContains(array $list, string $needle, bool $ignoreCase): bool
    {
        $found = false;
        if ($needle !== '') {
            foreach ($list as $item) {
                $matches = $ignoreCase
                    ? strcasecmp((string)$item, $needle) === 0
                    : (string)$item === $needle;
                if ($matches) {
                    $found = true;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * True when $key is an indexed value under one of the given prefixes
     * (prefix + ":" + remainder), matching .NET / toggly-eval CollectIndexedValues.
     */
    private function targetingKeyMatches(string $key, string ...$prefixes): bool
    {
        $matched = false;
        foreach ($prefixes as $prefix) {
            if (strpos($key, $prefix . ':') === 0) {
                $matched = true;
                break;
            }
        }

        return $matched;
    }

    /**
     * @param string[] $out
     * @param mixed $raw
     */
    private function appendStringList(array &$out, $raw): void
    {
        if ($raw === null || $raw === '') {
            return;
        }
        $values = is_array($raw) ? $raw : explode(',', (string)$raw);
        foreach ($values as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $text = trim((string)$item);
            if ($text !== '') {
                $out[] = $text;
            }
        }
    }

    /**
     * @param list<array{name: string, rollout: float|null}> $groups
     * @param mixed $raw
     */
    private function appendGroupEntries(array &$groups, $raw): void
    {
        if ($raw === null || $raw === '') {
            return;
        }
        $values = is_array($raw) ? $raw : explode(',', (string)$raw);
        foreach ($values as $item) {
            $parsed = $this->parseGroupRolloutEntry($item);
            if ($parsed !== null) {
                $groups[] = $parsed;
            }
        }
    }

    /**
     * @param mixed $entry
     * @return array{name: string, rollout: float|null}|null
     */
    private function parseGroupRolloutEntry($entry): ?array
    {
        $parsed = null;
        if (is_string($entry) || is_numeric($entry)) {
            $name = trim((string)$entry);
            if ($name !== '') {
                $parsed = ['name' => $name, 'rollout' => null];
            }
        } elseif (is_array($entry)) {
            $nameRaw = $entry['Name'] ?? $entry['name'] ?? null;
            if ($nameRaw !== null && $nameRaw !== '') {
                $name = trim((string)$nameRaw);
                if ($name !== '') {
                    $rollout = null;
                    if (array_key_exists('RolloutPercentage', $entry)
                        || array_key_exists('rolloutPercentage', $entry)
                    ) {
                        $rawPct = $entry['RolloutPercentage'] ?? $entry['rolloutPercentage'];
                        if (is_numeric($rawPct)) {
                            $rollout = (float)$rawPct;
                        }
                    }
                    $parsed = ['name' => $name, 'rollout' => $rollout];
                }
            }
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function asBool(?array $params, string $key): ?bool
    {
        $result = null;
        if ($params !== null && array_key_exists($key, $params) && $params[$key] !== null) {
            $value = $params[$key];
            if (is_bool($value)) {
                $result = $value;
            } elseif (is_int($value) || is_float($value)) {
                if ((float)$value === 1.0) {
                    $result = true;
                } elseif ((float)$value === 0.0) {
                    $result = false;
                }
            } elseif (is_string($value)) {
                if ($value === 'true' || $value === 'True' || $value === '1') {
                    $result = true;
                } elseif ($value === 'false' || $value === 'False' || $value === '0') {
                    $result = false;
                }
            }
        }

        return $result;
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

    /**
     * Run an action inside a usage scope (attempt, duration, errors).
     *
     * @template T
     * @param callable(\Toggly\FeatureManagement\Telemetry\FeatureUsageScope): T $action
     * @return T
     */
    public function use(string $featureKey, callable $action, string $variant = 'enabled')
    {
        return $this->usageStatsProvider->use($featureKey, $action, $variant);
    }
}
