# Changelog

Notable changes to this project are documented here.

## 0.3.0

Phase 2: the scanner.

### Added

- Scanner dashboard pages: Scanner (`/dashboard/cookie_manager/scanner`), Findings (`/findings`) and Scanner Settings (`/scan_settings`).
- A server-side crawl of the published pages that records third-party scripts, frames, images and other resources, and the cookies the pages set. It fetches only the site's own canonical host, pins the connection to one DNS answer, follows no redirects, refuses compressed bodies, stops at 2 MB or 8 seconds per page and 240 seconds in total, and runs no JavaScript. The page cap defaults to 50 (maximum 500) and pages on the exclude list are skipped.
- The crawler refuses loopback, private, link-local and CGNAT addresses by default. For a local development site, set the config key `cookie_manager_kit.scan.allow_private_network` to `true` (`application/config/cookie_manager_kit.php`: `return ['scan' => ['allow_private_network' => true]];`) and use a dotted hostname such as `site.test`; only loopback and RFC 1918 addresses are then allowed, never link-local or metadata addresses.
- Scan mode: an admin starts a 30 minute session in their own browser (a signed HttpOnly `cmk_scan_token` cookie plus a `cmk_scan=1` marker); a beacon then reports cookie names, storage key names and resource hosts (never values) to `POST /cookie-manager/scan/report`. Page HTML stays identical for every visitor. Scan mode is site-wide: starting a session ends any other running one.
- A review queue: findings are classified against admin overrides, optionally downloaded Open Cookie Database data, and a bundled baseline, in that order. Approving a finding creates or extends a Service; nothing reaches the banner without an admin approving it. Findings can be filtered by status, kind and category, and the Scanner page counts those that are new in the last completed crawl.
- Scheduled tasks under Automation: "Scan the site for cookies and scripts" (`cmk_scan_crawl`) and "Update cookie classification data" (`cmk_refresh_classification`), with an optional plain-text email alert and a warning when no runtime scan has run for a while.
- A crawl only marks findings "gone" when it saw everything. Any excluded, dropped, failed or cut-off page, or a crawl of zero pages, means nothing is marked gone; the Scanner page says which case applies. Crawls that stop unfinished are cleaned up, and a second crawl is refused while one is running.

### Security

- Everything the scanner records comes from the public internet and is cleaned, length-capped and encoded on output. Cookie and storage values are never read or stored.
- The report endpoint answers 401 (one fixed body) for token or run refusals and 400 (the same body) for everything else. Ingest and storage failures are logged with a fixed message and reported to the caller as a failed run or the fixed error.
- The scan token is signed with the secret behind `concrete.security.token.validation`; scan mode refuses to start when that secret is empty.
- The optional classification download fetches one fixed HTTPS URL, is capped at 5,000,000 bytes and validated before use. Up to 25% unusable rows are tolerated in that path (softer than the whole-file-or-nothing rule of the design); wildcard or very short patterns and wildcard "necessary" rows are rejected. Refreshed rows replace only earlier refreshed rows, in one transaction; admin overrides always win and ConcreteCMS's essential cookies are never reclassified.

### Upgrade notes

- Run **Update** for the package in the dashboard. This creates the scanner tables, the Scanner, Findings and Scanner Settings pages and the bundled classification rows, and registers the two scheduled tasks (tasks are only registered on install or upgrade).
- Set the site's canonical URL (Dashboard > System & Settings > SEO & Statistics > URLs and Redirection) before crawling. It is required for every crawl, scheduled or manual; the scanner never takes the host from a request.
- Scheduled crawling and downloading classification data are both off by default. Tick them in Scanner Settings; each task does nothing otherwise. The download fetches one public file and sends nothing about your site.
- Sites with a strict Content-Security-Policy must allow `script-src 'self'` (or the runtime's nonce, which the beacon script reuses) and `connect-src 'self'` for scan mode.

## 0.2.0

Banner statistics.

### Added

- Statistics dashboard page (`/dashboard/cookie_manager/statistics`): how often the banner is shown, and how many visitors accept all, choose some, or reject all, plus how many change their choice later. Date range, per-day table, and breakdowns by language and policy version.
- Public `POST /cookie-manager/event` endpoint and the `cmkStats` table. Only anonymous daily counts are stored: no cookie, IP address, user agent or visitor identifier is kept with the statistics (the rate limiter briefly caches a hash of the IP address, as the consent endpoint does).
- The banner counts one display per browser tab session per policy version; crawlers and headless browsers are not counted.

### Fixed

- Tracking snippets containing `<svg>` or `<math>` are removed, and the inert output is verified with full-document (body and head) parses, closing a foreign-content bypass of the round-trip check.
- Pasted `data-cmk-*` attributes (and a pasted `nonce` on `text/plain`) are stripped from scripts the rewrite leaves untouched, so a pasted script can no longer be activated as `necessary`.
- A hand-pasted `<script type="text/plain" data-cmk-category=...>` (or `Inert::script()` output) inside the SEO tracking-code field now stays permanently inert, because pasted `data-cmk-*` attributes are stripped; use the page template or `Inert::script()` outside the SEO field instead.
- Tracking snippets that straddle each other in the page output (neither contains the other) are removed together rather than leaving one live.
- Overlapping header and footer tracking snippets are rewritten in a single pass, so no part of either stays live.
- The `{session}_LOGIN` cookie is described with the remember-me lifetime; the page-output gate is reset at the start of every render; `composer typecheck` fails on errors and is clean.

### Upgrade notes

- Run **Update** for the package in the dashboard to create the `cmkStats` table and the Statistics page. Choices made before the upgrade are not counted.
- Until **Update** has been run, events are quietly ignored instead of being logged as errors.
- Statistics are anonymous aggregate counts. The only browser-side item is a `sessionStorage` key (`cmk_shown_<policy version>`) that stops the same tab session counting twice; it is cleared when the tab closes.

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
