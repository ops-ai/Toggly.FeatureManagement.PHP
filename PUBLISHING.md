# Publishing Guide

This monorepo publishes **three** Packagist packages:

| Packagist name | Source in monorepo | GitHub publish surface |
|----------------|--------------------|------------------------|
| `toggly/feature-management-php` | **Repo root** (`composer.json` + `src/`) | `ops-ai/Toggly.FeatureManagement.PHP` (this repo) |
| `toggly/laravel` | `packages/laravel/` | Mirror `ops-ai/Toggly.FeatureManagement.PHP.Laravel` |
| `toggly/wordpress` | `packages/wordpress/` | Mirror `ops-ai/Toggly.FeatureManagement.PHP.Wordpress` |

**Layout rule:** the default-branch root `composer.json` **is** the publishable
core package (Packagist already points at this GitHub URL). Laravel and
WordPress stay under `packages/` and are subtree-split to read-only mirrors on
each release. Root `repositories` → `packages/*` exists only so local CI can
path-require the framework packages; Composer ignores package-defined
repositories for Packagist consumers.

Shared version preference: bump all three manifests (and `SdkIdentity::SDK_VERSION`)
together before dispatching a release (e.g. `1.0.0`).

## Automated Release (Recommended)

Releases are handled by the **PHP SDK - Release** GitHub Actions workflow.

1. Go to **Actions > PHP SDK - Release** in the GitHub repository.
2. Click **Run workflow**.
3. Prefer `release_mode=publish` (uses the version already in root `composer.json`).
4. The workflow will:
   - Resolve the version from root `composer.json` vs Packagist.
   - Run tests across PHP 8.2–8.4 (package still declares `^7.4`–`^8.4`).
   - Create a signed Git tag (`v{version}`) on this monorepo.
   - Create a GitHub Release.
   - **Bootstrap** each mirror with an initial `main` commit if the repo is
     still empty (new mirrors have no default branch; without this,
     `danharrin/monorepo-split` fails with `pathspec 'main' did not match`).
   - Subtree-split `packages/laravel` → `ops-ai/Toggly.FeatureManagement.PHP.Laravel`
     (`main` + tag `v{version}`).
   - Subtree-split `packages/wordpress` → `ops-ai/Toggly.FeatureManagement.PHP.Wordpress`
     (`main` + tag `v{version}`).
5. Packagist picks up new tags via webhook on **each** package’s GitHub URL.

Do **not** invent new secret storage. Reuse `RELEASE_PUSH_TOKEN`.

### Human gate — `RELEASE_PUSH_TOKEN` (required before any tag)

`GITHUB_TOKEN` cannot push to sibling repositories. The release workflow **fails
closed** in the `release` job **before** creating a Packagist-visible core tag
if `RELEASE_PUSH_TOKEN` is missing. There is **no** `github.token` fallback for
tag push or mirror split.

1. Ensure `RELEASE_PUSH_TOKEN` is a PAT (or fine-grained token) with **push**
   access to:
   - `ops-ai/Toggly.FeatureManagement.PHP` (tags on monorepo)
   - `ops-ai/Toggly.FeatureManagement.PHP.Laravel`
   - `ops-ai/Toggly.FeatureManagement.PHP.Wordpress`
2. Classic PAT: `repo` scope on the ops-ai org (or those three repos).
3. Store it as the existing repo secret `RELEASE_PUSH_TOKEN`.

Without that token, the workflow exits before `v*` is pushed — core cannot
publish to Packagist ahead of empty Laravel/WordPress mirrors.

## Packagist Setup

### Core (already registered)

- Package: [toggly/feature-management-php](https://packagist.org/packages/toggly/feature-management-php)
- Repository URL: `https://github.com/ops-ai/Toggly.FeatureManagement.PHP`
- Root `composer.json` name must remain `toggly/feature-management-php`.

### Laravel and WordPress (human submit — do not automate)

1. Open [packagist.org/packages/submit](https://packagist.org/packages/submit).
2. Submit Laravel mirror:
   - URL: `https://github.com/ops-ai/Toggly.FeatureManagement.PHP.Laravel`
   - Expected name: `toggly/laravel`
3. Submit WordPress mirror:
   - URL: `https://github.com/ops-ai/Toggly.FeatureManagement.PHP.Wordpress`
   - Expected name: `toggly/wordpress`
4. Enable the GitHub webhook on each Packagist package so tags auto-update.
5. Submit **after** the first successful mirror split has pushed at least one
   commit/`composer.json` to each empty mirror (typically the first `v1.0.0`
   release dispatch). Empty repos cannot be submitted usefully.

## Manual Release (not recommended)

```bash
git tag -a v1.0.0 -m "v1.0.0"
git push origin v1.0.0
# Prefer the Actions workflow so mirrors are split + tagged too.
```

## Verify Installation

```bash
composer require toggly/feature-management-php:^1.0
composer show toggly/feature-management-php

composer require toggly/laravel:^1.0
composer require toggly/wordpress:^1.0
```

Core must not ship `Toggly\Laravel` / `Toggly\WordPress` classes.

## Required GitHub Secrets

| Secret | Purpose |
|--------|---------|
| `RELEASE_PUSH_TOKEN` | PAT with push access for monorepo tags **and** both mirror repos |
| `GPG_PRIVATE_KEY` | GPG key for signing tags (optional) |
| `GPG_PASSPHRASE` | Passphrase for the GPG key (optional) |
| `TOGGLY_SMOKE_APP_KEY_BACKEND` | App key for smoke tests |
| `SONAR_TOKEN` | SonarCloud token |
| `SONAR_SERVER_TOKEN` | SonarQube Server token |
| `SONAR_HOST_URL` | SonarQube Server URL |
| `NVD_API_KEY` | NVD API key for OWASP dependency check |
