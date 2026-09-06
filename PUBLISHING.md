# Publishing Guide

This package is published to [Packagist](https://packagist.org/packages/toggly/feature-management-php) and installed via Composer.

## Monorepo note (current)

Source lives under `packages/*`. The **release version** for the existing
Packagist package is read from
`packages/feature-management-php/composer.json`.

**Important:** Packagist still points at this GitHub repo URL and historically
expected a root `composer.json` that *is* `toggly/feature-management-php`.
After the monorepo layout, the root file is a private workspace. Do **not**
tag a new Packagist release from this layout until OPS-963 chooses either:

1. Promote core package contents (or a publish surface) back to repo root for
   Packagist on this URL, **or**
2. Use dedicated mirrors for all three packages (core + Laravel + WordPress).

Framework packages (`toggly/laravel`, `toggly/wordpress`) are **not** submitted
to Packagist in the monorepo layout slice.

## Automated Release (Recommended)

Releases are handled by the **PHP SDK - Release** GitHub Actions workflow.

1. Go to **Actions > PHP SDK - Release** in the GitHub repository.
2. Click **Run workflow**.
3. Choose the bump type (`patch`, `minor`, or `major`) and whether it is a pre-release.
4. The workflow will:
   - Compute the next version from the core package manifest / latest `v*` tag.
   - Run tests across PHP 8.2–8.4 (package still declares `^7.4`–`^8.4`).
   - Create a signed Git tag (`v{version}`).
   - Create a GitHub Release.
5. Packagist picks up the new tag automatically via webhook (once the Packagist
   surface strategy from OPS-963 is in place).

## Packagist Setup (One-Time)

1. Go to [packagist.org](https://packagist.org) and click **Submit**.
2. Enter the repository URL: `https://github.com/ops-ai/Toggly.FeatureManagement.PHP`
3. Historically Packagist detected `composer.json` at the repo root. After the
   monorepo split, reconcile with OPS-963 (promote core to root or mirror).
4. Enable the GitHub webhook so Packagist auto-updates on new tags.

## Manual Release

```bash
# 1. Tag the release
git tag -a v1.0.0 -m "v1.0.0"
git push origin v1.0.0

# 2. Create a GitHub Release from the tag (optional but recommended)

# 3. Packagist updates automatically via webhook
```

## Verify Installation

```bash
composer require toggly/feature-management-php
composer show toggly/feature-management-php
```

## Required GitHub Secrets

| Secret | Purpose |
|--------|---------|
| `RELEASE_PUSH_TOKEN` | PAT with push access for creating tags |
| `GPG_PRIVATE_KEY` | GPG key for signing tags |
| `GPG_PASSPHRASE` | Passphrase for the GPG key |
| `TOGGLY_SMOKE_APP_KEY_BACKEND` | App key for smoke tests |
| `SONAR_TOKEN` | SonarCloud token |
| `SONAR_SERVER_TOKEN` | SonarQube Server token |
| `SONAR_HOST_URL` | SonarQube Server URL |
| `NVD_API_KEY` | NVD API key for OWASP dependency check |
