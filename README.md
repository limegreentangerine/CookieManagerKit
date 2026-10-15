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

## What it does (phase 1)

- Consent categories and services, managed under `/dashboard/cookie_manager`.
- Multilingual banner text: plain text, one set per site locale. The default locale comes
  first and is required; other locales fall back to it.
- Script blocking until consent.
- A first-party consent cookie.
- A floating settings button, and `data-cmk-open` to reopen the settings from any element.
- Banner styling with a live preview (Appearance page).

Not built yet (later phases): cookie scanner, consent audit log, regional rules and GPC,
policy re-consent flow beyond the "Ask every visitor again" checkbox, Google Consent Mode,
declaration block, embed placeholders, translation assist, analytics and reports.

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
- An unreadable, invalid or stale cookie counts as no consent.
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
- Endpoints: `GET /cookie-manager/config/{locale}.json` and `POST /cookie-manager/consent`.
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
