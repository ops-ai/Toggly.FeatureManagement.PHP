# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - 2026-09-04

### Added
- Core filter-parity evaluation for segment filters (`BrowserFamily`,
  `BrowserLanguage`, `Country` / `CountryFamily`, `DeviceType`, `OS` /
  `OperatingSystem`) and `UserClaims` in `FeatureManager`.
- `HttpRequestMapper` mapping `user-agent`, `accept-language`, and country
  headers (`cf-ipcountry` → `x-vercel-ip-country` →
  `cloudfront-viewer-country`) into EvalContext `request`.
- Microsoft.* aliases for `Percentage`, `TimeWindow`, and `Targeting`.
- Golden fixture PHPUnit coverage against shared
  `docs/filter-parity/fixtures` (via sibling path or
  `TOGGLY_FILTER_PARITY_FIXTURES`).

### Changed
- Sticky percentage hashing now uses Definitions-aligned SHA-256
  (`featureKey + "\n" + userId`, little-endian uint32 / `0xFFFFFFFF` × 100).
  Cohort membership for partial rollouts may differ from the previous crc32
  buckets — expect sticky cohort remapping when upgrading from 0.2.x.
- Percentage and segment gates fail closed when percentage is missing or ≤ 0.
- Unknown filter names fail closed.
- Laravel classes under `src/Toggly/Laravel/Filters/` are marked legacy;
  evaluation goes through core `FeatureManager`, not those helpers.

### Fixed
- Removed invalid duplicate `recordUsageWithContext` signature from
  `UsageStatsProviderInterface` (PHP cannot declare the same method twice;
  only the three-argument form used by `FeatureManager` remains).

## [0.2.0] - 2026-07-11

### Changed
- Public Packagist metadata: author `Toggly <support@toggly.io>`, docs homepage, and support URLs.

### Added
- Snapshot providers persist exact signed `defs` JSON (`signedDefsJson`) and ETag
  so verification uses raw server bytes (no `json_encode` re-serialize on load).
- `clear()` on `FeatureSnapshotProviderInterface` for all providers (cache,
  database, file, MongoDB, Laravel cache).
- `TogglySettings::$onError` callback and LKG-friendly error reporting on refresh
  / snapshot failures (OPS-277 parity).
- WebSocket `signing-key-updated` handling: clear JWKS cache and force refresh.
- `FeatureProvider::clearPersistedSnapshots()` helper.

### Fixed
- Cold-start `Invalid signature` when loading snapshots that re-serialized feature models.

## [1.0.0] - 2024-01-XX

### Added
- Initial release of Toggly Feature Management PHP library
- Core feature management functionality matching .NET library
- Signed definitions support with ECDSA signature verification
- WebSocket support for real-time updates (with polling fallback)
- Usage statistics collection and reporting
- Metrics service for measurements, observations, and counters
- Snapshot providers: Cache (PSR-16), Database (PDO), and File-based
- Laravel integration with ServiceProvider, Facade, and Middleware
- WordPress plugin with admin interface and hooks
- PSR-4, PSR-11, PSR-16, PSR-18, and PSR-17 compliance
- Feature state change notifications
- Secure feature authorization support
- Context providers for user tracking
- Browser, device, OS, country, and user claims filters for Laravel
