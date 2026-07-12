# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-07-11

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
