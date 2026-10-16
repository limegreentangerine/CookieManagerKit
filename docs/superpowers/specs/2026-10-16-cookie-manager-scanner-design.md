# CookieManagerKit Phase 2: Scanner (Design)

Date: 2026-10-16
Status: Implemented in 0.3.0 (adjustments recorded in section 13)
Parent plan: `/Users/david/.claude/plans/cryptic-frolicking-pixel.md` (5-phase roadmap)
Builds on: `docs/superpowers/specs/2026-10-15-cookie-manager-core-design.md` (phase 1, shipped as 0.1.0; 0.2.0 added banner statistics)

## 1. Goal
Find the cookies, storage keys, scripts, iframes and images a site actually uses, suggest a consent category for each, and let an admin review and promote them to `Service` records. Re-scan on a schedule and tell admins when something new or changed appears.

The scanner never changes what visitors see or what the banner does on its own. A finding becomes visible to visitors only when an admin promotes it to a Service.

Out of scope for phase 2: a headless-browser scanner, scanning behind logins, the cookie declaration block (phase 4), consent audit log (phase 3), and reports/export (phase 5). Phase 2 must leave clean extension points for them (section 11).

## 2. Decisions agreed
- **Findings flow:** review, then promote to Services. No auto-creation.
- **Crawl scope:** Concrete's published-page list, capped, with an admin exclude list. No link-following.
- **Runtime data:** an admin-started "scan mode" beacon. The scheduled task runs the crawl only; runtime data stays manual and the task flags it when stale.
- **Alerts:** a dashboard badge, plus an optional plain-text email.
- **Classification data:** a bundled baseline, an optional scheduled refresh (off by default), and admin overrides that always win.
- **Extensibility:** scanners sit behind an interface so a headless browser can be added later.

## 3. Success criteria
1. A crawl of a site with a page cap produces findings for the static script, iframe and image hosts and the `Set-Cookie` cookies on those pages, without executing any page JavaScript.
2. An admin in scan mode can browse the site and have runtime cookie names, storage key names and loaded resource hosts recorded; no cookie or storage values are ever read or stored.
3. Every finding is classified against override, then refreshed, then bundled data. Unmatched findings land in the Unclassified queue.
4. Promoting a finding is the only path from a finding to a `Service`.
5. Page HTML is identical for every visitor, including an admin in scan mode, so Concrete full-page cache stays valid.
6. The crawler cannot be used to reach other hosts or internal addresses (section 8.2).
7. All scanned data is treated as hostile and encoded on output (section 8.1).
8. The scheduled task diffs each crawl against the previous one, updates badge counts, sends the optional email, and flags a stale runtime scan.
9. A refreshed classification file that fails validation is discarded and the previous data stays in use.

## 4. Data model
Doctrine entities under `src/Entity`, extending the same `ClassKit` `src/Entity/Core` bases as phase 1 (exact base per entity chosen when planning).

- `ScanRun`: `source` (`crawl` | `beacon`), `startedAt`, `finishedAt`, `status` (`running` | `complete` | `failed`), `pagesScanned`, `newCount`, `changedCount`, `unclassifiedCount`. A beacon run is one admin scan-mode session.
- `ScanFinding`: `kind` (`cookie` | `storage` | `script` | `iframe` | `image` | `resource`), `name`, `host`, `firstSeen`, `lastSeen`, `sources` (crawl, beacon or both), `examplePage`, `status`, `service` (nullable), `suggestedCategory` (nullable), `suggestedVendor` (nullable). Identity is `(kind, name, host)`, stored as a unique `fingerprint` (sha1) because a composite key can exceed index limits. A nullable `restoreStatus` remembers the status a `gone` finding returns to. For `script`, `iframe`, `image` and `resource` findings `name` equals `host`.
  - Status: `unclassified` | `matched` | `approved` | `ignored` | `gone`. "New" is not a stored status: it means `firstSeen` is at or after the last run's start, and is counted in `ScanRun.newCount`.
  - A finding not seen in a complete crawl is marked `gone`, not deleted, so history survives. It returns to its previous status if it reappears.
- `ClassificationEntry`: `pattern` (exact name, or wildcard on name or host), `kind`, `categoryHandle`, `vendor`, `description`, `duration`, `origin` (`bundled` | `refreshed` | `override`).
  - Precedence: override, then refreshed, then bundled.
- `Settings` additions: `scanMaxPages`, `scanExcludes` (list), `scanSchedule` (bool), `scanAlertEmail` (nullable), `scanStaleDays` (default 60), `classificationRefresh` (bool, default false).

Bundled baseline: a trimmed Open Cookie Database CSV plus a script-domain list, shipped under the package and loaded into `ClassificationEntry` on install and upgrade (origin `bundled`). Upgrade replaces `bundled` rows only; `refreshed` and `override` rows are untouched.

## 5. Scanner pipeline
```
Scanner::scan(ScanContext): ScanResult
        |
        v
FindingIngestor  ->  Classifier  ->  ScanFinding / ScanRun
```
- `Scanner` interface returns normalised findings. Phase 2 ships `CrawlScanner` and `BeaconScanner`.
- `FindingIngestor` is the only code that writes findings. It de-duplicates on `(kind, name, host)`, updates `firstSeen`/`lastSeen`, calls the `Classifier`, and after a complete crawl marks unseen crawl-sourced findings `gone`.
- `Classifier` returns a suggested category and vendor, or none (finding becomes `unclassified`).

### 5.1 Crawl
- URL list: Concrete's published pages, filtered by the exclude list, capped at `scanMaxPages`. Optionally one sample per page type.
- Requests only the site's own canonical host (section 8.2).
- Parses each page with the WHATWG parser already used by `TrackingCode`, not regex. Collects `script src`, `iframe src`, `img src`, `link href`, hosts referenced by inline scripts, and `Set-Cookie` response headers.
- Fetches as an anonymous visitor. No JS execution, no login.
- Runs as one pass with a 240 s time budget and a 2,000,000 byte, 8 s per-page limit. Pages not reached when the budget ends count as failed, so an incomplete crawl never marks anything gone.

### 5.2 Beacon (scan mode)
- "Start scan mode" in the dashboard issues an HMAC-signed token with a 30 minute expiry, set as a cookie in the admin's browser only.
- Page HTML never changes. The runtime (already on every page) adds the beacon script only when the JavaScript-readable marker cookie `cmk_scan` is present, which only an admin in scan mode has. The beacon route answers 204 unless the request also carries the signed HttpOnly token, so cached pages stay identical and ordinary visitors make no extra request.
- The beacon reports to `POST /cookie-manager/scan/report`: cookie names from `document.cookie`, `localStorage` and `sessionStorage` key names, and resource hosts from the Performance API. Values are never read.
- The endpoint checks the token, content type, size, origin and rate limit. It stores names and hosts only.
- The beacon stops at token expiry. The admin can end scan mode from the dashboard.

### 5.3 Scheduled task
Registered through `tasks.xml` using the `NotificationManagerKit` pattern.
- Runs the crawl, diffs against the previous complete crawl, updates badge counts, sends the optional email.
- Flags a stale runtime scan when no beacon run exists within `scanStaleDays`.
- A separate task refreshes classification data (section 7); disabled unless `classificationRefresh` is on.

## 6. Dashboard (three pages: `/dashboard/cookie_manager/scanner`, `/findings`, `/scan_settings`)
- **Overview:** last crawl and last scan-mode run, stale-runtime warning, "Run crawl now", "Start/stop scan mode", new and unclassified badges, run history.
- **Findings:** filter by status, kind and category. Per-row actions: approve (create or attach a Service), reassign category, ignore, save as override. Bulk approve is limited to `matched` findings.
- **Settings:** page cap, exclude list, schedule, alert email, stale threshold, refresh on/off.
- "Run crawl now" runs the crawl inside the request (with a 240 s budget); scheduled runs use the Concrete task. Large sites should schedule it.
- Approve is the only path from finding to `Service`. It also records the cookie against the Service for the phase 4 declaration block.

## 7. Classification refresh
- Task fetches a snapshot from a fixed HTTPS URL (constant, not admin-supplied).
- Validated against a schema and a size cap before anything is stored. The whole file passes or the whole file is discarded.
- On success, `refreshed` rows are replaced in a single transaction. On failure the previous rows stay and the failure is logged through the package Logger.
- Off by default; the bundled baseline works without it.

## 8. Security
Extends phase 1 section 8.

### 8.1 Output and input
- Cookie names, hosts, URLs and page titles are attacker-influenceable. All of it is encoded for its context in dashboard views, forms, flash messages, the alert email (HTML and plain-text parts built separately) and any later export.
- The existing static gate that scans views for unescaped output covers the new pages.
- Hosts are validated against a strict syntax; only `http`/`https` URLs are accepted. Names are length-capped and charset-checked before storing.
- Every admin action checks permission and CSRF token. IDs are cast and checked against the database.

### 8.2 Crawler (SSRF)
- Same canonical host only; the target is validated before every fetch.
- Private, loopback and link-local addresses are refused, resolving the host and checking the address that will actually be connected to.
- Redirects off-host are not followed.
- Timeouts, a response-size cap and a per-run page cap.

### 8.3 Beacon endpoint
- Token is HMAC-signed with an expiry and compared in constant time.
- Rejects wrong content type, oversize bodies, unknown keys and malformed values. Never echoes input. Response carries `nosniff` and the JSON content type.

## 9. Testing
- **PHPUnit:** ingestor (dedupe, gone-marking, status transitions, return from gone), classifier precedence, crawl URL selection and caps, SSRF refusals (private addresses, off-host redirects), token signing and expiry, report-endpoint validation, refresh validation (bad file leaves previous rows), upgrade replaces only bundled rows.
- **PHPUnit XSS:** hostile cookie names, hosts and URLs through every view, redisplayed form, flash message and the email.
- **Vitest:** beacon sends names only and never values; stops at expiry; uses no banned DOM sinks (`innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`).
- **Browser, local Concrete 9.5 site:** scan mode round trip, findings appear, promotion creates a Service, page HTML is identical with and without the token cookie, no console errors.
- **Static:** PHPStan, php-cs-fixer, Prettier via the existing `composer` scripts.

## 10. Housekeeping included in phase 2
- Add the `scanner` single page to `installOrUpgrade` and an upgrade step that creates the new tables and loads the bundled baseline.
- Add a 0.3.0 changelog entry with upgrade notes (run **Update** to create tables and the Scanner page).

## 11. Extension points reserved for later phases
- `Scanner` interface: a headless-browser scanner.
- `ScanFinding.service` and the recorded cookie data feed the phase 4 declaration block.
- `ScanRun` summaries feed the phase 5 compliance health check and reports.
- Alert delivery is isolated so another channel can be added.

## 12. Decisions deferred to the implementation plan
- Exact `ClassKit` entity base class per entity.
- Crawl batch size and the per-request timeout values.
- The refresh snapshot URL and the trimmed baseline's licence/attribution notice.

## 13. Adjustments made during implementation
Written back from the implementation plan and the build. Where they differ from the sections above, this section is authoritative.

1. `new` is not a stored status (section 4).
2. `ScanFinding` carries a unique `fingerprint` and a nullable `restoreStatus` (section 4).
3. For `script`, `iframe`, `image` and `resource` findings `name` equals `host`: one finding per `(kind, host)`, not per URL.
4. The dashboard is three single pages, not one (section 6).
5. "Run crawl now" runs inside the request; scheduled runs use the Concrete task (section 6).
6. The beacon is not referenced by page HTML; the runtime adds it when the `cmk_scan` marker cookie exists, and the route answers 204 without the signed token. It uses the Performance API (`getEntriesByType('resource')`) rather than a `PerformanceObserver` (section 5.2).
7. The crawl is one pass with a time budget, not batches (section 5.1).
8. Clarifies section 8.2: `UrlGuard` refuses loopback, private (RFC 1918), CGNAT, link-local and other non-public addresses by default. For local development sites only, the config key `cookie_manager_kit.scan.allow_private_network` allows loopback and RFC 1918 addresses (never link-local or metadata addresses), and the site needs a dotted hostname because dotless hosts are rejected. The canonical URL is required; the crawl never takes its host from a request.
9. The alert email is plain text only. Section 8.1 mentioned HTML and plain-text parts; only the plain-text part was built.
10. The classification refresh path tolerates up to 25% unusable rows (and requires a minimum row count), softer than section 7's "whole file or nothing"; any other failure still discards the whole file. It also rejects wildcard or very short patterns and wildcard `necessary` rows so a refresh cannot make broad matches. The size cap is 5,000,000 bytes.
11. `ScanRun.sawEverything` records whether a crawl saw everything (no excluded, dropped or off-host, failed or truncated pages, and at least one page); only then are unseen findings marked `gone`, so a site with an exclude list never gets gone-marking. A sweeper marks crawl runs that stopped unfinished as failed, and a second concurrent crawl is refused.
12. `POST /cookie-manager/scan/report` answers 401 for token or run refusals and 400 for every other refusal, always with one fixed body. `GET /cookie-manager/scan/beacon.js` answers 204 without a valid token.
13. The Findings filter includes category, and the Scanner overview shows a "New in the last completed crawl" count. There is no Dashboard menu badge element; the "badge" in sections 2, 3 and 5.3 is the on-page counts.
