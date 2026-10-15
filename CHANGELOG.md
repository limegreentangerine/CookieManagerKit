# Changelog

Notable changes to this project are documented here.

## 0.1.0

Phase 1: consent banner and script blocking.

### Added

- Consent categories and services with a dashboard UI (`/dashboard/cookie_manager`).
- Multilingual plain-text banner content per site locale, default locale first and required.
- Script blocking until consent via `<script type="text/plain" data-cmk-category>` and `Inert::script()`.
- Automatic inerting of the SEO tracking codes under the `analytics` category.
- `cmk_consent` first-party cookie with policy versioning and "Ask every visitor again".
- Floating settings button, `data-cmk-open` hook and `window.cmk` API with `consent:changed`.
- Appearance page with banner styling and live preview.
- Config and consent endpoints (`/cookie-manager/config/{locale}.json`, `/cookie-manager/consent`).
- Optional CSP nonce support through `cookie_manager_kit.csp_nonce`.
- Essential ConcreteCMS cookies are never blocked.

## 0.0.0

### Added

- Separate GitHub Actions workflows for code standards and system tests.

### Changed

- Code standards checks run on pushes and pull requests; system tests run on
  pull requests.
- Updated Composer dependencies and development tooling for the package
  template.

## 2026-09-25

### Added

- PHPStan static analysis configuration and a Composer command for type checks.
- Cloudflare Access authentication for installing dependencies from the private
  Composer registry.

### Changed

- Updated the template to use the published `limegreentangerine/class_kit`
  Composer package.
- Refined the GitHub Actions workflows and development dependencies.

## 2026-09-15

### Added

- Initial Concrete CMS Composer package template, including Composer
  autoloading, PHPUnit test setup, and a GitHub Actions test workflow.
