# Changelog

## Unreleased

### Added

- Plugin header now declares `Requires at least: 5.0`, `Tested up to: 7.1`, and
  `Requires PHP: 7.4`.
- PHP 7.4 polyfills for `str_starts_with` / `str_contains` / `str_ends_with` in
  the plugin bootstrap so definition refresh works with published core builds that
  still call PHP 8.0 string helpers on the live-update path.

## 1.0.3

### Fixed

- Report WordPress HTTP API failures through the PSR-18 client exception contract and make closed in-memory streams unavailable for further reads or writes.
