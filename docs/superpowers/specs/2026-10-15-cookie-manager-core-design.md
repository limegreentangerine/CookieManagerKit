# CookieManagerKit Phase 1: Core Consent Runtime (Design)

Date: 2026-10-15
Status: Draft for user review
Parent plan: `/Users/david/.claude/plans/cryptic-frolicking-pixel.md` (5-phase roadmap)

## 1. Goal
Deliver a working consent management core for ConcreteCMS 9.5 (PHP 8.4): admins define consent categories and services, write multilingual banner content, and style the banner. Visitors choose all, some or no categories, and can change that choice at any time. Third-party scripts do not execute until their category is consented.

Out of scope for phase 1 (later phases): scanner, audit log, regional rules/GPC, policy re-consent flow, Consent Mode v2, declaration block, embed placeholders, translation assist, analytics, reports. Phase 1 must leave clean extension points for them (see section 9).

## 2. Success criteria
1. With no consent, no non-necessary third-party script runs, and none of its cookies is set by the package's own code.
2. Accept all, reject all and custom per-category choices each activate exactly the consented categories, and the choice persists across page loads.
3. A visitor can reopen preferences from a persistent widget or any element carrying `data-cmk-open`, and change their choice; newly rejected categories stop being activated on subsequent loads.
4. Banner text is editable per site locale. A locale without content falls back to the site default locale.
5. Page HTML is identical for every visitor, so Concrete full-page cache stays valid.
6. Admin styling changes (colours, position, layout, button styles, radius, shadow) appear on the frontend without code changes, and values are whitelisted.
7. Both public endpoints and all frontend rendering paths are XSS-protected (section 8.1): no stored, reflected or DOM-based injection from admin content, URL parameters, request bodies or the consent cookie.
8. All backend functionality is XSS-protected (section 8.2): dashboard views, admin AJAX endpoints, previews, and (in later phases) scan findings, consent-log data, exports and reports.
9. Essential ConcreteCMS cookies (session, remember-me, CSRF/login, and `cmk_consent`) are always allowed (section 5.1): a visitor who rejects all can still log in, use forms and keep a session, and the package never blocks, sets or deletes those cookies.

## 3. Architecture
Client-side runtime (approach B from the plan).

- **Server** emits, on every front-end page (not dashboard, edit mode or login): an inline loader in `<head>`, the registered runtime asset, and the config URL. Output does not depend on the visitor.
- **Loader (inline, no dependencies):** reads the `cmk_consent` cookie, and exposes `window.cmk` with `get()`, `on(event, fn)` and `activate(categories)`. It activates inert scripts for consented categories as soon as it runs, before the banner renders.
- **Runtime (`cmk.js`):** fetches the config JSON, renders the banner and preference centre if there is no valid consent (or when reopened), writes consent, and calls `activate`.
- **Config endpoint:** `GET /cookie-manager/config/{locale}.json`. Cacheable, content-hashed, rebuilt when admins save.
- **Consent endpoint:** `POST /cookie-manager/consent`. In phase 1 it validates and acknowledges; phase 3 persists to the audit log. The runtime works without it succeeding.

## 4. Data model
Doctrine entities under `src/Entity`, extending `ClassKit` `src/Entity/Core` bases (`BaseEntity` / `UpdatedEntity`; exact base per entity chosen when planning, following `NotificationManagerKit`).

- `Category`: `handle` (unique slug), `sortOrder`, `isNecessary` (bool; necessary categories are always on and not togglable). Seeded on install: `necessary`, `preferences`, `analytics`, `marketing`.
- `CategoryContent`: `category`, `locale`, `title`, `description`.
- `Service`: `name`, `vendor`, `category`, `domains` (list), `isActive`.
- `ServiceContent`: `service`, `locale`, `description`.
- `BannerContent`: `locale`, `title`, `body`, `acceptAllLabel`, `rejectAllLabel`, `customiseLabel`, `saveLabel`, `policyPageId` (nullable), `policyLinkText`.
- `Settings`: single row. Style tokens (section 6), `position`, `layout`, `consentLifetimeDays` (default 180), `policyVersion` (integer, starts at 1).

Services belong to categories; phase 1 uses categories for activation and services for display and for the later scanner and declaration features.

## 5. Consent model
- **Cookie:** `cmk_consent`, first-party, `Path=/`, `SameSite=Lax`, `Secure` on HTTPS, not HttpOnly (the loader must read it), lifetime `consentLifetimeDays`.
- **Value:** URL-encoded JSON `{"v":<policyVersion>,"c":["necessary","analytics"],"t":<unix seconds>}`.
- **Validity:** consent is valid if the cookie parses and `v` equals the current `policyVersion`. Otherwise the banner is shown again (this also gives phase 3 its re-consent mechanism).
- **Necessary** is always included and cannot be removed.
- **Reject all** records `["necessary"]`, so declining is also a remembered, reversible choice.

## 5.1 Essential ConcreteCMS cookies (always allowed)

Cookies that Concrete needs to function are strictly necessary and are never blocked, delayed, deleted or made dependent on consent. This holds in every phase.

- **Covered cookies (verified against the Concrete 9.5 source):**
  - the session cookie, named by the config key `concrete.session.name` (default `CONCRETE`);
  - the login cookie `{session name}_LOGIN`;
  - the remember-me cookie `ccmAuthUserHash` (`CookieService::COOKIE_NAME`);
  - the dashboard UI cookies `ConcreteSitemapTreeID` (with optional `-<n>` suffix), `ConcreteSitemap-expand` and `dashboardPanelStatus`;
  - the package's own `cmk_consent` cookie.

  Concrete's CSRF tokens are session-based, not separate cookies.
- **Session name is never hard-coded or stored.** Another package may change `concrete.session.name` at runtime, so the protection list is resolved lazily on every use (config endpoint, cookie clearing, scanner): it reads `concrete.session.name` at that moment and, when a PHP session is active, also includes `session_name()`, which is the name actually in use. Both names are protected if they differ. The list is never written to the database or cached across requests, and the config endpoint's ETag includes it, so a name change takes effect on the next request.
- **Not in the consent flow:** the package never sets, rewrites or clears these cookies. Session handling stays entirely with Concrete, so login, dashboard, edit mode, forms and CSRF tokens work before, after and without consent.
- **Locked in the data model:** they belong to the `necessary` category, which is always on and cannot be disabled, deleted, or have its scope narrowed. An admin cannot reclassify them out of `necessary` (the Categories page shows them read-only, labelled "Required by ConcreteCMS").
- **Banner and declaration:** the `necessary` category lists these cookies with their purpose and duration, so the visitor sees what is always set. The "Reject all" choice still records `["necessary"]`.
- **Scanner (phase 2):** any cookie matching the essential list is auto-classified `necessary` with an `essential` flag. Admin overrides and Open Cookie Database matches cannot change it, and it never lands in the Unclassified queue.
- **Cookie clearing:** when a visitor withdraws a category (section 7), only cookies belonging to the withdrawn, non-necessary categories are ever candidates for removal. A hard-coded protection list of the essential cookies is checked first and always wins.
- **Cache and runtime:** the loader never reads or touches session cookies, and the consent endpoint ignores them.

## 6. Admin UI
Dashboard single pages under `/dashboard/cookie_manager`:

1. **Banner content:** per-locale tabs over `$site->getLocales()`. Required: default-locale title and body. Other locales are optional and fall back to the default.
2. **Categories and services:** edit category content per locale, add and remove services, assign services to categories. Necessary categories cannot be disabled or deleted.
3. **Appearance:** background, text, accent and button colours (from a fixed token set plus validated hex values), `position` (bottom-left, bottom-right, bottom-bar, centre-modal), `layout`, border radius, shadow, font inherit/custom, with a live preview.

All writes validate the CSRF token (`submit`). Style inputs are validated against an allowlist or a strict regex (hex `^#[0-9a-fA-F]{6}$`) before storage and again when emitted, so nothing unvalidated reaches an inline style or a class name.

## 7. Script blocking contract
Site authors and the later scanner mark third-party scripts with:

```html
<script type="text/plain" data-cmk-category="analytics" src="..."></script>
<script type="text/plain" data-cmk-category="marketing">/* inline */</script>
```

On activation the loader recreates each matching element as a real `<script>` (copying `src`, `async`, `defer`, `nonce` and inline text) and preserves document order. Each element is activated at most once. The package also provides a PHP helper for authors who add scripts through block or theme code. Site-wide tracking snippets from Concrete's SEO tracking settings are wrapped as inert under the `analytics` category, as `lgt_toolkit` does today.

If a category is later withdrawn, already-run scripts cannot be un-run. When a visitor saves a choice that removes a previously consented category, the runtime stores the new consent and then reloads the page, so the withdrawn category's scripts do not run again. Cookies already set by those scripts are listed to the visitor as removable in their browser; automatic deletion of third-party cookies is added with the scanner in phase 2, when cookie names are known.

## 8. Config JSON
```json
{
  "version": "<content hash>",
  "policyVersion": 1,
  "locale": "en_GB",
  "banner": { "title": "...", "body": "...", "labels": {"acceptAll": "...", "rejectAll": "...", "customise": "...", "save": "..."}, "policyUrl": "/privacy" },
  "categories": [{"handle": "necessary", "necessary": true, "title": "...", "description": "...", "services": [{"name": "...", "vendor": "...", "description": "..."}]}],
  "style": { "position": "bottom-right", "layout": "box", "tokens": {"bg": "#ffffff", "fg": "#111111", "accent": "#0b5fff"}, "radius": 8, "shadow": true },
  "lifetimeDays": 180
}
```
The locale is resolved by the server from the URL path and falls back to the site default. The `version` hash lets the runtime cache the config and refetch when admins change it.

## 8.1 XSS protection (endpoints and rendering)

**Principle:** every value is validated on input, encoded for its output context, and never interpreted as HTML or code by the runtime.

**Config endpoint (`GET /cookie-manager/config/{locale}.json`)**
- `{locale}` is matched against the site's configured locales (allowlist). Anything else falls back to the default locale and is never echoed back.
- Response headers: `Content-Type: application/json; charset=utf-8`, `X-Content-Type-Options: nosniff`, and `Content-Disposition: inline; filename="config.json"` so it can never be rendered as a document. Non-GET methods return 405.
- Built with `json_encode(..., JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`, so `<`, `>`, `&` and quotes are escaped even if the JSON is ever inlined into HTML.
- `policyUrl` is resolved server-side from `policyPageId` through Concrete's page URL resolver, never from free text. If an admin-supplied URL is ever allowed, only relative paths and `http`/`https` URLs pass; `javascript:`, `data:` and `vbscript:` are rejected.

**Consent endpoint (`POST /cookie-manager/consent`)**
- Accepts only `Content-Type: application/json`, a body of at most 2 KB, and a strict schema: `v` is an integer, `c` is an array of at most 20 strings, and each string must match `^[a-z0-9_-]{1,32}$` and exist as a `Category` handle. Unknown keys and unknown handles are rejected with 400, not sanitised and kept.
- The response is a fixed `{"ok":true}` or `{"error":"invalid"}` body with `nosniff` and `Cache-Control: no-store`. No request value is reflected in the response.
- Same-origin check on `Origin`/`Referer` and rate limiting per hashed IP. A CSRF token is not used, since consent is anonymous and cacheable pages cannot carry per-visitor tokens; the validation above means a forged request can only record a valid choice.

**Admin content (stored XSS)**
- Banner and category text is stored as plain text, not HTML. Line breaks are the only formatting, preserved by the runtime as `<br>` it creates itself.
- Dashboard views encode every value with `h()` (Concrete's HTML escaper) in HTML context, and with `json_encode` flags above in script context.
- Style tokens are validated on save and again when emitted (hex regex, enumerated positions, numeric radius with bounds).

**Runtime (DOM-based XSS)**
- The runtime builds the banner with `document.createElement`, `textContent` and `setAttribute` only. It never uses `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write` or `eval`.
- Links set `href` only after checking the scheme is `http:`, `https:` or relative, with `rel="noopener noreferrer"`.
- The `cmk_consent` cookie is parsed defensively. It is `JSON.parse`d inside try/catch, then checked against the config's category handles; anything unexpected is treated as no consent. Cookie values are never written into the DOM.
- Script activation copies `src` only from elements the page author marked inert; it does not take URLs from the config, cookie or endpoints.

**CSP compatibility**
- The inline loader accepts a nonce from Concrete's response where a CSP is in use, and all other code ships as registered external assets, so a strict `script-src` works without `unsafe-inline`.

## 8.2 XSS protection for backend (dashboard) functionality

This is a standing requirement for every dashboard page, admin AJAX endpoint, export and preview in all phases, not only phase 1.

**Output encoding**
- Every dynamic value in a dashboard view is encoded for its context: `h()` for HTML text and attributes, `json_encode` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` for values passed to JavaScript, and `rawurlencode` / `urlencode` in URLs. Raw echo of a variable is not allowed in a view.
- Values reach admin JavaScript through `data-*` attributes (encoded) or a JSON island, not by string concatenation into inline script.
- Admin JavaScript updates the DOM with `textContent` / `setAttribute`, never `innerHTML` with data. The same build-time check as the frontend runtime (section 8.1) applies to `js/` dashboard files.
- Flash messages, validation errors and form re-population (including values echoed back after a failed save) are encoded. Error messages never include raw input.
- Live preview in Appearance renders inside a sandboxed `iframe` (`sandbox` without `allow-scripts`) or with the same DOM-construction code as the frontend runtime, so previewing hostile text cannot execute.

**Untrusted data that ends up in the admin**
- Anything originating outside the admin's own typing is treated as hostile, even though it is displayed only to admins. This includes banner and category text in other locales, scanner findings (cookie names, values, script URLs, page titles, response headers from crawled pages), beacon payloads, consent-log fields (user agent, region, locale) and imported cookie-database data. They are validated on ingestion (length limits, character allowlists where possible, URL scheme checks) and encoded again on display.
- Search and filter inputs on list pages are encoded wherever they are redisplayed (search boxes, pagination links, "no results for X" messages).

**Admin endpoints**
- Every admin AJAX or form action checks permission (`/dashboard/cookie_manager` page permissions) and a valid CSRF token (`$this->token->validate(...)`), accepts only the expected method, and returns JSON with `Content-Type: application/json` and `nosniff`.
- IDs are cast and checked against the database. Free-text identifiers are matched against allowlists.
- Redirect targets are fixed routes or validated against same-origin; user-supplied URLs are never used as redirect locations.

**Exports and reports (phases 3 to 5)**
- CSV exports neutralise spreadsheet formula injection: any cell starting with `=`, `+`, `-`, `@`, tab or carriage return is prefixed with a single quote. Fields are quoted and escaped, and the download uses `Content-Disposition: attachment` with `nosniff`.
- PDF and email report templates encode all data. Report share pages and expiring links use the same encoded views, strict headers and `noindex`; tokens are random, stored hashed, and compared in constant time.
- Scheduled email reports encode data in HTML mail parts and build plain-text parts separately.

**Input policy**
- Validation happens server-side on every save (types, lengths, allowlists, URL schemes). Client-side validation is convenience only.
- Rich text is not accepted anywhere in phase 1 (see 8.1). If a later phase adds rich text, it must pass through Concrete's HTML sanitiser with an explicit tag and attribute allowlist, and gets its own XSS tests.

**Enforcement**
- A PHPCS or PHPStan rule, or a test that scans `single_pages/`, `elements/` and `controllers/` for `echo $` / `<?= $` without an encoder, fails CI on unescaped output.
- Code review checklist item: "every new view value is encoded for its context."

## 9. Extension points reserved for later phases
- `ConsentRecorder` interface (phase 1 no-op, phase 3 persists).
- Config builder accepts additional sections (regional rules, Consent Mode defaults).
- `Service` and `Category` carry the fields the scanner and declaration block need.
- The `cmk` event bus (`consent:changed`, `banner:shown`) feeds Consent Mode and analytics later.
- Scheduled tasks use the existing `tasks.xml` pattern from `NotificationManagerKit`.

## 10. Decisions deferred to the implementation plan
- Exact `ClassKit` entity base class per entity (follow `NotificationManagerKit` precedent).

## 11. Testing and verification
- PHPUnit: entity validation, config builder (locale fallback, hash stability), style allowlist, consent endpoint input validation.
- PHPUnit XSS cases: a config built from content containing `<script>`, `"><img onerror=…>` and `javascript:` URLs yields escaped JSON; a hostile `{locale}` is never reflected; the consent endpoint rejects wrong content type, oversize bodies, unknown keys, unknown or malformed handles (including `<script>`), and never echoes input; response headers include `nosniff` and the JSON content type.
- PHPUnit backend XSS cases: dashboard controllers reject saves without a valid CSRF token or permission; hostile text submitted to every admin form (`<script>`, attribute-breaking quotes, `javascript:` URLs) is either rejected or rendered encoded in the re-displayed form, list view, flash message and preview; hostile search terms are encoded when redisplayed; CSV export neutralises `=`, `+`, `-`, `@` leading cells.
- Static check in CI for unescaped output in views and for banned DOM sinks in dashboard JS.
- Essential-cookie tests: the protection list is built from Concrete's session config (a custom session name is respected, and changing `concrete.session.name` between two calls changes the next result, so nothing is cached; a differing `session_name()` is protected as well); the clearing routine never returns a protected cookie even if a category or service claims it; the `necessary` category cannot be disabled, deleted or have essential cookies removed through any admin action; after "Reject all", logging in and submitting a form with a CSRF token still works (browser check), and the session cookie is unchanged by the consent flow.
- Vitest: loader parsing and validity rules, activation order and once-only behaviour, each consent path (accept all, reject all, custom, change later, version mismatch).
- Vitest XSS cases: banner built from hostile strings produces text nodes, not elements; `javascript:` and `data:` links are not rendered as links; a malformed or hostile `cmk_consent` cookie is treated as no consent; a lint rule or test fails the build if `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write` or `eval` appear in the runtime source.
- Browser, on a local Concrete 9.5 site: banner renders; before consent nothing marked inert executes; each choice activates only its categories; persistence across reloads; widget and `data-cmk-open` reopen; no console errors; response HTML identical across visitors with and without consent.
- Static: PHPStan, php-cs-fixer, Prettier via the existing `composer` scripts.

## 12. Housekeeping included in phase 1
- Fix `composer.json` PSR-4 autoload (`ComposerPackage\` to `CookieManagerKit\`).
- Add the front-end build tooling (the scaffold only has Prettier).
- Install routine seeds default categories and registers dashboard pages.
