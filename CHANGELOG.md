# Changelog

Notable changes to this project are documented here.

## Unreleased

### Fixed

- Tracking snippets containing `<svg>` or `<math>` are removed, and the inert output is verified with full-document (body and head) parses, closing a foreign-content bypass of the round-trip check.
- Pasted `data-cmk-*` attributes (and a pasted `nonce` on `text/plain`) are stripped from scripts the rewrite leaves untouched, so a pasted script can no longer be activated as `necessary`.
- Overlapping header and footer tracking snippets are rewritten in a single pass, so no part of either stays live.
- The `{session}_LOGIN` cookie is described with the remember-me lifetime; the page-output gate is reset at the start of every render; `composer typecheck` fails on errors and is clean.

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
- Optional CSP nonce support through `cookie_manager_kit.csp_nonce`; the loader, the SEO tracking snippets and `Inert::script()` carry the page nonce.
- Server-side failures are logged to the `cookie_manager` channel through ClassKit's Logger.
- If the `analytics` category is deleted, SEO tracking snippets stay in the page but remain inert and never run.
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
