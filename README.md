# Cookie Manager Kit

A cookie-consent platform for ConcreteCMS 9.5. It shows a consent banner, holds back
tracking and marketing scripts until the visitor agrees, remembers the choice and lets
the visitor change it later.

Status: install and browser verification pending. The install, seeding and routing
behaviour has been verified by unit tests and code review but has not yet been run on a
live site.

## Requirements

- ConcreteCMS 9.5.
- PHP 8.4. The tracking-snippet rewriter uses PHP 8.4's `\Dom\HTMLDocument` HTML5 parser.
- The `class_kit` package.

## What it does (phases 1 to 3)

- Consent categories and services, managed under `/dashboard/cookie_manager`.
- Multilingual banner text: plain text, one set per site locale. The default locale comes
  first and is required; other locales fall back to it.
- Script blocking until consent.
- A first-party consent cookie.
- A floating settings button, and `data-cmk-open` to reopen the settings from any element.
- Banner styling with a live preview (Appearance page).
- Anonymous banner statistics (Statistics page, see below).
- A cookie and script scanner with a review queue (Scanner pages, see below).
- A consent log, re-consent with a recorded reason, Google Consent Mode v2 and an opt-in or opt-out default model (see below).

Not built yet (later phases): regional rules, declaration block, embed placeholders, translation assist, A/B testing, and report export
and sharing.

## Statistics

`Dashboard > Cookie Manager > Statistics` shows how visitors respond to the banner for a date range: banner displays, accept all, custom and reject all (with counts and shares), the response rate, and how many people changed their choice later, by day, language and policy version.

Counting is anonymous. Each event adds one to a daily counter (`cmkStats`); no cookie, IP address, user agent or visitor identifier is stored in the statistics. As with the consent endpoint, the rate limiter briefly (about two minutes) keeps a hash of the visitor's IP address in Concrete's cache so it can limit requests; it is not kept with the statistics. In the browser, a `sessionStorage` key (`cmk_shown_<policy version>`) is written when the banner first shows, so the same tab session counts only once; it is not a cookie and is cleared when the tab closes. Mention these in your own privacy notice if you describe your cookie tooling.

A banner display is counted once per browser tab session per policy version, and the days are UTC. If a browser blocks `sessionStorage` (for example some private modes), every page view that shows the banner is counted, which inflates the display count. Crawlers and headless browsers are not counted.

The event endpoint allows 120 requests a minute per client IP address, as seen by Concrete. Behind a reverse proxy or CDN, configure Concrete's trusted proxies so visitors are not all seen as one address; otherwise counts beyond the limit are silently dropped. The response rate is approximate (first choices divided by displays) and can pass 100%.

After uploading a new version, run **Update** for the package in the dashboard. Until then the statistics table does not exist and events are quietly ignored.

## Scanner

The scanner finds what your site really sets and loads, suggests a consent category for each thing, and lets you turn the ones you approve into Services. Nothing a scan finds reaches the banner until you approve it. It adds three pages under `Dashboard > Cookie Manager`: Scanner, Findings and Scanner Settings.

### What it records

Cookie names, localStorage and sessionStorage key names, and the hosts of scripts, frames, images and other resources. Cookie and storage **values are never read or stored**. Everything recorded comes from the public internet, so names and hosts are cleaned, length-capped and always shown encoded; example pages are shown as plain text, never as links.

### Crawl

**Scanner > Run crawl now**, or the scheduled task. The crawl fetches your published pages as an anonymous visitor and lists the third-party hosts each page loads and the cookies each response sets. It runs no JavaScript.

- **The canonical URL is required** (Dashboard > System & Settings > SEO & Statistics > URLs and Redirection). The crawl requests only that canonical host, never one taken from a request, on the address the host resolved to when the crawl began. It follows no redirects and refuses compressed responses.
- Loopback, private, link-local and CGNAT addresses are refused. For a local development site, set `cookie_manager_kit.scan.allow_private_network` to true in `application/config/cookie_manager_kit.php` and use a dotted hostname (for example `site.test`, because dotless hosts are rejected):

    ```php
    <?php
    return ['scan' => ['allow_private_network' => true]];
    ```

    This allows only loopback and RFC 1918 addresses, never link-local or cloud metadata addresses. Leave it off in production.

- Each page is capped at 2 MB and 8 seconds, and the whole crawl has a 240 second soft budget. The page cap is set in Scanner Settings (default 50, maximum 500), and the paths you list there are skipped. **Run crawl now** runs inside the request, so on a large site use the scheduled task.
- The crawl is an anonymous visitor, so members-only pages (which answer 403 or redirect) make it incomplete.
- A finding that stops appearing is only marked **Gone** after a crawl that saw everything. If any page was excluded, dropped or off-host, failed, or cut off by the page cap, or if zero pages were fetched, nothing is marked gone, and the Scanner page says so. As a consequence, a site with an exclude list never gets gone-marking.
- Only one crawl runs at a time; a crawl that stopped unfinished is cleaned up automatically.

### Scan mode

A crawl cannot see cookies and storage that scripts create in the browser. Scan mode can.

1. **Scanner > Start scan mode.**
2. In the same browser, open your site, choose **Accept all** on the banner, and browse a few pages.
3. For the next 30 minutes this browser reports cookie names, storage key names and the hosts each page loaded. **Stop scan mode** ends it early.

Only the browser that started scan mode is affected. It holds a signed token in an HttpOnly cookie (`cmk_scan_token`, SameSite=Strict, Secure on https) and a JavaScript-readable marker (`cmk_scan=1`). When the runtime sees the marker it loads the beacon from `/cookie-manager/scan/beacon.js`, which answers 204 unless the token is valid. The beacon posts names and hosts (never values) and the page path to `/cookie-manager/scan/report`. Page HTML is identical for every visitor, so full-page caching is unaffected and ordinary visitors make no extra request.

Notes:

- Scan mode is site-wide: there is one running session, and starting one ends another administrator's.
- A site with a strict Content-Security-Policy needs `script-src 'self'` (or the runtime's nonce, which the beacon script reuses) and `connect-src 'self'`.
- Navigations that do not load a new page (single-page apps) are not re-reported.
- Scan mode needs the site security token secret (`concrete.security.token.validation`); it refuses to start when that is empty.

### Review queue

**Findings** lists everything found and can be filtered by status, kind and category. Each finding is **Matched** (a category was suggested) or **Unclassified**; the Scanner page also counts findings that are new in the last completed crawl ("new" is computed, not a stored status). For a finding you can:

- **Approve**: create a Service or add it to an existing one. This is the only path from a finding to a Service, and an already approved finding cannot be approved again.
- **Change suggested category**, or **Always classify like this** (saves an override rule that beats the built-in data; saving it again replaces the earlier one).
- **Ignore** it (and **Review again** later).
- **Approve all matched findings** to create one Service per vendor and category in one go. Only matched findings are included.

### Classification data

Suggestions come from three sources, strongest first: your own overrides, the optional downloaded data, and a hand-curated baseline that ships with the package (`data/classification-baseline.csv`, see `data/NOTICE.txt`). ConcreteCMS's own essential cookies are always suggested as **Necessary** and are never reclassified.

The optional download is the [Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database) (Apache-2.0), fetched from one fixed HTTPS URL. It is off by default, sends nothing about your site, and is capped at 5,000,000 bytes and validated (header, row rules) before use. Unusable rows are dropped, up to 25% of the file; beyond that, or if the file fails any other check, the whole file is discarded and the previous data stays. Wildcard or very short patterns, and wildcard rows that would classify as `necessary`, are rejected. A successful refresh replaces only earlier refreshed rows, in one transaction.

### Scheduling and alerts

Under `Dashboard > System & Settings > Automation > Tasks` there are two tasks: **Scan the site for cookies and scripts** (`cmk_scan_crawl`) and **Update cookie classification data** (`cmk_refresh_classification`). The crawl task does nothing unless "Run the scheduled crawl" is ticked in Scanner Settings and a canonical URL exists; the update task does nothing unless downloading is ticked. The tasks are registered when the package is installed or updated, so run **Update** after upgrading.

Alerts are the counts on the Scanner page plus an optional **plain-text** email (counts and names only) to the address you set in Scanner Settings. There is no menu badge. The Scanner page also warns when no scan-mode run has happened for the number of days you set (the scheduled crawl cannot run scan mode).

### Scanner limitations

- No headless browser: cookies that scripts add later are only found by scan mode.
- No scanning behind logins; members-only pages make a crawl incomplete.
- IPv6-only site hosts are not scanned.
- Scan mode does not re-report single-page-app navigations.

## Consent log and privacy

`Dashboard > Cookie Manager > Consent Log` records every choice a visitor makes, so you can show when and how consent was given. Each entry stores a random consent ID, the policy version, the categories chosen, what triggered the choice (for example the banner, the settings panel or a re-consent), the locale, a coarse browser and device bucket such as `Chrome/desktop`, and the time. The consent ID is kept in the visitor's `cmk_consent` cookie (as `i`), so a later change by the same browser adds an entry to the same record. No IP address, region or raw user agent is stored in the consent log (the rate limiter still briefly keeps a hash of the IP address in Concrete's cache, as described under Statistics).

Filter the log by date, trigger, policy version, category or consent ID, and download a CSV (it includes the re-consent reason). Mention the log in your own privacy notice.

The scheduled task **Purge old consent records** (`cmk_purge_consent_log`) deletes a browser's history 24 months after its last choice by default. Change the number of months (1 to 120) on the Banner page. The task is registered when the package is installed or updated, so run **Update** after upgrading.

"Ask every visitor again" now needs a reason. It is kept in a history on the Banner page and in the log export. Approving a scanner finding that adds a service to an optional category offers a one-click "Ask visitors again"; it is never automatic.

### Google Consent Mode v2

Off by default (Banner page). When on, every signal starts denied except `security_storage`, which is always granted, and the rest update from the visitor's choice: necessary sets `security_storage` (always granted); analytics sets `analytics_storage`; marketing sets `ad_storage`, `ad_user_data` and `ad_personalization`; preferences sets `functionality_storage` and `personalization_storage`.

### Default model and Global Privacy Control

The Banner page sets what happens before a visitor chooses. **Opt-in** (the default) keeps optional categories off until they agree. **Opt-out** lets optional categories run until they object, and is not suitable for visitors in the EU or UK. A browser that sends Global Privacy Control always starts with Marketing off, and the banner is still shown.

With opt-out, a visitor whose stored choice is from an older policy version, whose cookie is unreadable, or who blocks cookies is treated as having made no choice, so optional categories run until they choose. Opt-in keeps optional categories off in every one of those cases.

## Blocking a script

Mark a script as inert and give it a category handle. This pattern is for page templates and blocks, NOT for the SEO tracking-code field: those snippets are rewritten automatically, and pasted `data-cmk-*` attributes there are stripped.

```html
<script type="text/plain" data-cmk-category="analytics" src="https://example.com/a.js"></script>
```

- Use `data-cmk-type="module"` on the element for module scripts.
- From PHP: `CookieManagerKit\Support\Inert::script($category, $src, $inline, $type, $nonce)`.
- The site's SEO tracking codes (header and footer) are made inert under category
  `analytics` automatically, and their `<noscript>` parts are removed. If the `analytics`
  category is deleted, the snippets stay in the page but remain inert and never run (the
  loader only activates known categories). Data blocks that browsers never execute
  (`application/ld+json`, `importmap`, `speculationrules`, other non-JavaScript types) are left as pasted.
  If rendering the banner fails, scripts stay blocked; tracking snippets that cannot be made inert are removed from the page.

**Limitation: only `<script>` elements are held back.** Other markup in a pasted tracking
snippet still loads or runs before consent: `<img>`, `<iframe>`, `<link>`, `<object>`,
inline `on...=` handlers, `<iframe srcdoc>` and `javascript:` URLs. Remove that markup or
load it through a marked script.

## Consent behaviour

- The `cmk_consent` cookie is first-party JSON that includes the policy version. It is
  remembered for 30 to 395 days (configurable).
- "Ask every visitor again" bumps the policy version, so everyone is asked again.
- Withdrawing a category reloads the page, because scripts that already ran cannot be
  un-run.
- An unreadable, invalid or stale cookie counts as no consent (with the opt-out default model, "no consent" means optional categories run until the visitor chooses).
- External scripts keep their relative order. Inline scripts run immediately, so an inline
  script that follows an external one is not guaranteed to wait for it to finish loading.

## Reopening the settings and the JavaScript API

Add `data-cmk-open` to any element (for example `<a href="#" data-cmk-open>Cookie
settings</a>`) to open the preference centre.

- `window.cmk.get()` returns the current consent.
- `window.cmk.has('analytics')` tells you whether a category is consented.
- `window.cmk.on('consent:changed', fn)` calls `fn` with `{categories, previous}`.

## Content Security Policy

Bind `cookie_manager_kit.csp_nonce` in the container, as a string or a Closure returning
the request nonce, so the inline loader carries it.

When a nonce is bound, the loader also requires every inert script to carry the same
nonce as the loader itself; otherwise nothing activates. The SEO tracking snippets and
`Inert::script()` output (pass the nonce as the 5th argument) then carry the page nonce.
Scripts you write by hand must carry the same `nonce` attribute or they will not activate.

With full-page caching on, every visitor of a cached page is served the same nonce, which
weakens nonce protection. Either use one nonce value per cached page or exclude the page
from the cache. Without a CSP header this mode adds no protection.

## Essential ConcreteCMS cookies

These are never blocked and are listed read-only on the Categories page. Visitors see each
cookie's name, purpose and duration under the always-allowed category:

- the session cookie (name read from `concrete.session.name` at request time, default
  `CONCRETE`) and `{session}_LOGIN`
- `ccmAuthUserHash`
- `ConcreteSitemapTreeID`, `ConcreteSitemap-expand`, `dashboardPanelStatus`
- the package's own `cmk_consent`

## Security notes

- Banner and category text is plain text only; no rich text in this phase.
- Endpoints: `GET /cookie-manager/config/{locale}.json` and `POST /cookie-manager/consent`
  (plus the scanner's `POST /cookie-manager/scan/report` and `GET /cookie-manager/scan/beacon.js`, which need a valid scan token).
  The consent endpoint is same-origin, JSON only, strictly schema-checked and
  rate-limited. The rate limit uses the client IP, so configure Concrete's trusted proxies
  when running behind a proxy or CDN.
- Failures are logged server-side with sanitised messages, to the `cookie_manager` channel
  through ClassKit's Logger (Dashboard > Reports > Logs).
- The loader activates any inert `type="text/plain"` script of a consented (or
  `necessary`) category. A site must not allow untrusted HTML containing such scripts.

## Known limitations

- Install and browser verification on a live site is pending (see above).
- Upgrades re-create default categories that were deleted.
- Scripts that already ran cannot be un-run; the page reloads on withdrawal.
- The Appearance font option is "theme font" or "system font".
- A tracking snippet that contains `<svg>` or `<math>` is removed entirely (it cannot be verified safely), and `data-cmk-*` attributes pasted into a tracking snippet's scripts are stripped.

## Developing

| Command              | Purpose                    |
| -------------------- | -------------------------- |
| `composer test`      | PHPUnit tests.             |
| `npx vitest run`     | JavaScript tests.          |
| `composer typecheck` | PHPStan.                   |
| `composer format`    | PHP-CS-Fixer and Prettier. |

Two static security tests, `tests/ViewEscapingTest.php` and
`tests/ControllerSecurityTest.php`, apply to every dashboard view and controller; new
ones must pass them.
