# CookieManagerKit Phase 1: Core Consent Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the phase 1 consent core for the `cookie_manager_kit` ConcreteCMS package: admin-managed categories, services and multilingual banner content; a cache-safe client runtime that blocks marked scripts until consent; consent stored in a first-party cookie and changeable at any time; styleable banner; XSS-protected endpoints and dashboard.

**Architecture:** Every front-end page carries identical output: an inline loader (`js/cmk-loader.js`) plus the registered runtime asset (`js/cmk-runtime.js`). The runtime fetches a per-locale, content-hashed JSON config (`GET /cookie-manager/config/{locale}.json`), renders the banner with DOM construction only (no HTML injection), and writes consent through the loader. Consent is acknowledged by `POST /cookie-manager/consent`. Pure PHP logic (`Support\*`) is separated from Concrete glue so it is unit-testable; Doctrine entities follow `ClassKit` bases and the `NotificationManagerKit` conventions.

**Tech Stack:** PHP 8.4, ConcreteCMS 9.5, Doctrine ORM (docblock annotations), PHPUnit 11, PHPStan, php-cs-fixer, vanilla ES5-style browser JS, Vitest 3 + jsdom 26, Prettier.

**Spec:** `docs/superpowers/specs/2026-10-15-cookie-manager-core-design.md` (approved 2026-10-15). Sections referenced below as "spec §N".

## Global Constraints

- ConcreteCMS `9.5.0`, PHP `8.4`; package handle `cookie_manager_kit`; autoload registry `'src' => '\CookieManagerKit'`; depends on package `class_kit`.
- Consent cookie `cmk_consent`: first-party, `Path=/`, `SameSite=Lax`, `Secure` on HTTPS, **not** HttpOnly, lifetime `consentLifetimeDays` (default 180). Value is URL-encoded JSON `{"v":<policyVersion>,"c":["necessary",...],"t":<unix seconds>}`.
- Consent is valid only if the cookie parses, `v` equals the current policy version, and every handle in `c` is a known category handle. Anything unexpected is treated as no consent. `necessary` is always included and cannot be removed. "Reject all" records `["necessary"]`.
- Inert script contract: `<script type="text/plain" data-cmk-category="<handle>" ...>`. Each element activates at most once, in document order.
- Endpoints: `GET /cookie-manager/config/{locale}.json` (cacheable, ETag = content hash) and `POST /cookie-manager/consent`. Both return JSON with `X-Content-Type-Options: nosniff`; the consent endpoint also sends `Cache-Control: no-store` and never reflects request data.
- Banner and category text is **plain text**, never HTML. The runtime and dashboard JS never use `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write` or `eval`. Every dashboard view value is encoded for its context (`Output::e`, `Output::json`).
- Essential ConcreteCMS cookies are never blocked, set or deleted by the package. The session cookie name is read from `concrete.session.name` (default `CONCRETE`) **at use time, never stored or cached**, together with `session_name()` when a session is active. Verified essential list: `{session}`, `{session}_LOGIN`, `ccmAuthUserHash`, `ConcreteSitemapTreeID` (+ optional `-<n>`), `ConcreteSitemap-expand`, `dashboardPanelStatus`, `cmk_consent`.
- Dashboard pages live under `/dashboard/cookie_manager`; every save/delete validates the CSRF token with `$this->token->validate('submit')`.
- Content is keyed by full locale ID (for example `en_GB`, from `$locale->getLocale()`); missing locales fall back to the site default locale, then to built-in English text.
- `src/` may only contain files that declare a class, interface, trait or enum (Concrete scans it when building entity metadata). Templates go in `elements/`.
- Match surrounding code style: tabs in JS/JSON, 4-space PSR-12 PHP, docblock `@ORM` annotations, `declare(strict_types=1)` in tests.
- Working discipline: scope package-manager commands to this package directory only; do not rename or refactor anything outside this plan; verify UI in a real browser before calling any UI task done.
- End every commit message with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

These are inputs and conditions the spec implies but no obvious task test would otherwise catch. Each has a pinned test in the task named in brackets.

1. **Cookies blocked or unwritable** (private mode, cookie policy blocks writes): choosing "Accept all" must still activate scripts for the current page view and must not throw; the banner simply returns on the next page. [Task 10]
2. **Stale or foreign consent cookie**: a cookie from an older policy version, with an unknown category handle (a category was deleted or renamed), with wrong types, or with non-JSON content must be treated as "no consent" and re-prompt, never as partial consent. [Task 10]
3. **Inert script edge cases**: an inert script with a missing, unknown or hostile category never activates; `type="module"` scripts get their type restored; scripts with `src` keep document order. [Tasks 9, 10]
4. **Consent endpoint unreachable** (offline, 4xx/5xx, `fetch` missing): the visitor's choice must still be saved and applied, and the UI must not hang or throw. [Task 11]
5. **Locale edge cases**: a visitor locale with no content falls back to the site default, then to built-in English text; a hostile `{locale}` (for example `<script>`, `../`, 5 KB of text) is never echoed and resolves to the default. [Tasks 5, 8]

---

## File Structure

```
composer.json                      modify: PSR-4 autoload fix
package.json, vitest.config.js     modify/create: JS test tooling
phpunit.xml.dist                   modify: suite name
controller.php                     modify: install, routes, events, tables
elements/dashboard/uninstall.php   create
elements/dashboard/banner_preview.php  create
css/cmk.css                        create: banner, preferences, widget
js/cmk-loader.js                   create: inline loader (consent cookie + script activation)
js/cmk-runtime.js                  create: banner/preferences UI
js/cmk-dashboard.js                create: Appearance live preview
src/Support/Output.php             create: HTML/URL/JSON encoding
src/Support/Text.php               create: plain-text sanitising + format checks
src/Support/EssentialCookies.php   create: protected cookie list (lazy)
src/Support/StyleRules.php         create: style allowlists
src/Support/ConfigBuilder.php      create: pure snapshot -> config JSON array
src/Support/LocaleResolver.php     create: requested locale -> a real site locale or the default
src/Support/Catalog.php            create: DB snapshot (cached) + locale resolution
src/Support/ConsentRequest.php     create: strict POST body validation
src/Support/RequestGuard.php       create: same-origin check
src/Support/RateLimit.php          create: per-key fixed window
src/Support/ConfigRequest.php      create: locale from request path
src/Support/TrackingCode.php       create: make SEO tracking snippets inert
src/Support/Inert.php              create: PHP helper for authors
src/Support/LoaderScript.php       create: inline loader markup
src/Support/SiteLocales.php        create: dashboard locale list
src/Support/Installer.php          create: default data seeding
src/Entity/{Settings,Category,CategoryContent,Service,ServiceContent,BannerContent}.php  create
src/Consent/{ConsentChoice,ConsentRecorder,NullConsentRecorder}.php  create
src/Ajax/{Config,Consent}.php      create
src/Events/ConsentManager.php      create
controllers/single_page/dashboard/cookie_manager.php (+ cookie_manager/{banner,categories,services,appearance}.php)  create
single_pages/dashboard/cookie_manager.php (+ cookie_manager/*.php)  create
tests/SourceLayoutTest.php, tests/ViewEscapingTest.php, tests/ControllerSecurityTest.php
tests/Support/*Test.php, tests/Entity/*Test.php
tests/js/{helpers,loader,runtime,dashboard,sinks}.test.js
```

---

### Task 1: Foundation (autoload, tooling, escaping and text helpers)

**Files:**
- Modify: `composer.json`, `package.json`, `phpunit.xml.dist`
- Create: `vitest.config.js`, `src/Support/Output.php`, `src/Support/Text.php`
- Test: `tests/SourceLayoutTest.php`, `tests/Support/OutputTest.php`, `tests/Support/TextTest.php`

**Interfaces:**
- Produces:
  - `CookieManagerKit\Support\Output::e(mixed): string` (HTML-encode), `::safeUrl(?string): string` (raw http/https/relative URL or `''`), `::url(?string): string` (HTML-encoded safe URL or `#`), `::json(mixed): string` (script-safe JSON, throws `\JsonException`).
  - `CookieManagerKit\Support\Text::plain(mixed, int $max = 255): string`, `::multiline(mixed, int $max = 2000): string` (anything that is not a string, such as an array from a hostile `title[]=x`, becomes `''` instead of a `TypeError`), `::isLocale(mixed): bool`, `::isHandle(mixed): bool`.

- [ ] **Step 1: Confirm the pending scaffold edits with the user**

Run: `git status --short && git diff --stat`
Expected: `.gitignore`, `composer.json`, `controller.php`, `package.json` modified and `package-lock.json` untracked. These are the user's own uncommitted scaffold-rename edits. Ask the user whether to commit them first as `chore: rename scaffold to cookie manager kit`. Do not include them in any later commit unless they say yes. If yes:

```bash
git add .gitignore composer.json controller.php package.json package-lock.json
git commit -m "chore: rename scaffold to cookie manager kit

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 2: Fix the autoload namespace**

In `composer.json` replace the `autoload` and `autoload-dev` blocks:

```json
	"autoload": {
		"psr-4": {
			"CookieManagerKit\\": "src/"
		}
	},
	"autoload-dev": {
		"psr-4": {
			"CookieManagerKit\\Tests\\": "tests/"
		}
	},
```

Rename the PHPUnit suite in `phpunit.xml.dist`: `<testsuite name="composer_name">` to `<testsuite name="cookie_manager_kit">`.

Run: `composer dump-autoload`
Expected: "Generated optimized autoload files" (this runs in the package directory only).

Then confirm the dependencies the entity tasks need are installed in this package's `vendor/`:

Run: `ls vendor/limegreentangerine/class_kit/src/Entity/Core vendor/concrete5/core/bootstrap/helpers.php`
Expected: `BaseEntity.php GuidEntity.php UpdatedEntity.php UpdatedGuidEntity.php` and the helpers file. If `class_kit` is missing, run `composer install` in this package directory only (it needs the private repository credentials already configured for this project); do not run it anywhere else.

- [ ] **Step 3: Add JS test tooling**

Run: `npm install --save-dev vitest@^3.2.4 jsdom@^26.1.0`

In `package.json` set the script to `"test": "vitest run"` (replacing the placeholder).

Create `vitest.config.js`:

```js
import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		environment: 'jsdom',
		include: ['tests/js/**/*.test.js']
	}
});
```

- [ ] **Step 4: Write the failing source-layout test**

Create `tests/SourceLayoutTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Concrete scans every PHP file under src/ when it builds entity metadata, so a file there that isn't a class
 * (a template, a script) is executed during a schema comparison or package upgrade, and breaks both.
 */
final class SourceLayoutTest extends TestCase
{
    public function testEveryPhpFileUnderSrcDeclaresAClassLikeType(): void
    {
        $offenders = [];
        $src = dirname(__DIR__) . '/src';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            $declares = false;

            foreach ($tokens as $i => $token) {
                if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    // `Foo::class` is not a declaration.
                    $previous = $i > 0 ? $tokens[$i - 1] : null;
                    if (!(is_array($previous) && $previous[0] === T_DOUBLE_COLON)) {
                        $declares = true;
                        break;
                    }
                }
            }

            if (!$declares) {
                $offenders[] = substr($file->getPathname(), strlen($src) + 1);
            }
        }

        $this->assertSame([], $offenders, 'move these out of src/ (eg. into elements/)');
    }
}
```

- [ ] **Step 5: Write failing tests for `Output` and `Text`**

Create `tests/Support/OutputTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\Output;
use PHPUnit\Framework\Attributes\DataProvider;

final class OutputTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function payloadProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'attribute breakout' => ['"><script>alert(1)</script>'],
            'event handler' => ['<img src=x onerror=alert(1)>'],
            'single quote breakout' => ["' onmouseover='alert(1)"],
            'svg onload' => ['<svg onload=alert(1)>'],
        ];
    }

    #[DataProvider('payloadProvider')]
    public function testEscapesEveryPayload(string $payload): void
    {
        $escaped = Output::e($payload);

        $this->assertStringNotContainsString('<', $escaped);
        $this->assertStringNotContainsString('"', $escaped);
        $this->assertStringNotContainsString("'", $escaped);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function urlProvider(): array
    {
        return [
            'https' => ['https://example.com/a?b=1&c=2', 'https://example.com/a?b=1&c=2'],
            'http' => ['http://example.com', 'http://example.com'],
            'relative path' => ['/privacy', '/privacy'],
            'relative no slash' => ['privacy', 'privacy'],
            'javascript' => ['javascript:alert(1)', ''],
            'mixed case javascript' => ['JaVaScRiPt:alert(1)', ''],
            'tab inside scheme' => ["java\tscript:alert(1)", ''],
            'newline inside scheme' => ["java\nscript:alert(1)", ''],
            'data' => ['data:text/html,<script>alert(1)</script>', ''],
            'vbscript' => ['vbscript:msgbox(1)', ''],
            'mailto not allowed' => ['mailto:a@example.com', ''],
            'protocol relative' => ['//evil.example/x', ''],
            'backslash protocol relative' => ['/\\evil.example/x', ''],
            'double backslash' => ['\\\\evil.example', ''],
            'empty' => ['', ''],
            'whitespace' => ['   ', ''],
        ];
    }

    #[DataProvider('urlProvider')]
    public function testSafeUrlAllowsOnlyHttpAndRelative(string $input, string $expected): void
    {
        $this->assertSame($expected, Output::safeUrl($input));
    }

    public function testUrlIsEncodedOrHash(): void
    {
        $this->assertSame('https://example.com/a?b=1&amp;c=2', Output::url('https://example.com/a?b=1&c=2'));
        $this->assertSame('#', Output::url('javascript:alert(1)'));
        $this->assertSame('#', Output::url(null));
    }

    public function testJsonCannotCloseAScriptOrAttribute(): void
    {
        $json = Output::json(['a' => '</script><b onclick="x">&\'']);

        foreach (['<', '>', '&', "'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertSame(['a' => '</script><b onclick="x">&\''], json_decode($json, true));
    }

    public function testJsonThrowsOnInvalidUtf8(): void
    {
        $this->expectException(\JsonException::class);
        Output::json(["bad\xB1" => 'x']);
    }
}
```

Create `tests/Support/TextTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use CookieManagerKit\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testPlainStripsMarkupControlCharactersAndTruncates(): void
    {
        $this->assertSame('alert(1)Hi', Text::plain('<script>alert(1)</script>Hi'));
        $this->assertSame('a b', Text::plain("a\n\tb"));
        $this->assertSame(5, mb_strlen(Text::plain('abcdefghij', 5)));
        $this->assertSame('', Text::plain(null));
        $this->assertSame('', Text::plain("\xB1\xB2"));
    }

    public function testMultilineKeepsLineBreaksButNotMarkup(): void
    {
        $this->assertSame("one\ntwo", Text::multiline("one\r\ntwo"));
        $this->assertSame("one\n\ntwo", Text::multiline("one\n\n\n\n\ntwo"));
        $this->assertSame('xHi', Text::multiline('<img src=x onerror=alert(1)>xHi'));
        $this->assertSame(3, mb_strlen(Text::multiline('abcdef', 3)));
    }

    public function testNonStringInputIsTreatedAsEmptyNotAsAnError(): void
    {
        foreach ([['x'], ['a' => ['b']], 5, 1.5, true, new \stdClass()] as $value) {
            $this->assertSame('', Text::plain($value));
            $this->assertSame('', Text::multiline($value));
        }
    }

    public function testIsLocale(): void
    {
        foreach (['en', 'en_GB', 'cy_GB', 'sr_Latn_RS', 'zh_CN'] as $ok) {
            $this->assertTrue(Text::isLocale($ok), $ok);
        }
        foreach (['', 'e', 'en-GB', "en_GB\n", '<script>', '../x', str_repeat('a', 40), 5, null] as $bad) {
            $this->assertFalse(Text::isLocale($bad), var_export($bad, true));
        }
    }

    public function testIsHandle(): void
    {
        foreach (['necessary', 'analytics', 'my-cat_2'] as $ok) {
            $this->assertTrue(Text::isHandle($ok), $ok);
        }
        foreach (['', 'Upper', "analytics\n", '<script>', 'a b', str_repeat('a', 33), 5, null] as $bad) {
            $this->assertFalse(Text::isHandle($bad), var_export($bad, true));
        }
    }
}
```

- [ ] **Step 6: Run to verify the new tests fail**

Run: `composer test`
Expected: `SourceLayoutTest` passes (no src files yet); `OutputTest`/`TextTest` fail with "Class ... not found".

- [ ] **Step 7: Implement `Output` and `Text`**

Create `src/Support/Output.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Encoding for everything the package writes into HTML, attributes, URLs and script blocks.
 * Entity getters are never echoed raw.
 */
final class Output
{
    /**
     * For text content and quoted attribute values.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A raw (not HTML-encoded) URL that is safe to hand to an href: http(s) or relative. Anything else, including
     * javascript:, data: and protocol-relative URLs, becomes ''. Use for JSON/config values.
     */
    public static function safeUrl(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        // Control characters and whitespace inside the scheme are how "java\tscript:" bypasses get through.
        $normalised = preg_replace('/[\x00-\x20]+/', '', $url);

        if ($normalised === null || $normalised === '') {
            return '';
        }

        // "//host" and "/\host" are network-path references that browsers treat as another site.
        if (preg_match('#^[/\\\\]{2}#', $normalised) === 1) {
            return '';
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalised, $match) === 1) {
            return in_array(strtolower($match[1]), ['http', 'https'], true) ? $url : '';
        }

        return $url;
    }

    /**
     * A URL for an href attribute in a view: encoded, or '#' when unsafe.
     */
    public static function url(?string $url): string
    {
        $safe = self::safeUrl($url);

        return $safe === '' ? '#' : self::e($safe);
    }

    /**
     * JSON that is safe inside an HTML attribute or a <script> block. Throws on invalid input rather than
     * silently emitting a broken document.
     */
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
```

Create `src/Support/Text.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Plain-text normalising for admin input and format checks for identifiers. Banner text is never HTML.
 * Patterns end in \z, not $, because $ also matches before a trailing newline.
 */
final class Text
{
    public static function plain(mixed $value, int $max = 255): string
    {
        $value = trim(strip_tags(is_string($value) ? $value : ''));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    public static function multiline(mixed $value, int $max = 2000): string
    {
        $value = strip_tags(is_string($value) ? $value : '');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? '';
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    public static function isLocale(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2}\z/', $value) === 1;
    }

    public static function isHandle(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9_-]{1,32}\z/', $value) === 1;
    }
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `composer test`
Expected: all tests PASS.

- [ ] **Step 9: Commit**

```bash
git add composer.json package.json package-lock.json phpunit.xml.dist vitest.config.js src/Support/Output.php src/Support/Text.php tests/
git commit -m "feat: add autoload fix, test tooling, Output and Text helpers

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Essential cookie protection list

**Files:**
- Create: `src/Support/EssentialCookies.php`
- Test: `tests/Support/EssentialCookiesTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `CookieManagerKit\Support\EssentialCookies` with
  `const CONSENT_COOKIE = 'cmk_consent'`, `const DEFAULT_SESSION_NAME = 'CONCRETE'`,
  `static sessionNames(mixed $configured, mixed $active = null): list<string>` (pure),
  `static sessionNamesNow(?callable $read = null): list<string>` (reads `concrete.session.name` and `session_name()` at call time),
  `static names(?callable $read = null): list<string>` (all exact protected names),
  `static isEssential(string $cookie, ?callable $read = null): bool`.
  `$read` is `fn(string $configKey): mixed`; production default reads `app('config')->get($key)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Support/EssentialCookiesTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\EssentialCookies;

final class EssentialCookiesTest extends TestCase
{
    private function reader(?string &$sessionName): callable
    {
        return static function (string $key) use (&$sessionName): mixed {
            return $key === 'concrete.session.name' ? $sessionName : null;
        };
    }

    public function testDefaultSessionNameIsUsedWhenConfigIsMissingOrInvalid(): void
    {
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames(null));
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames(''));
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames(['x']));
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames('has space'));
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames(str_repeat('a', 65)));
    }

    public function testConfiguredAndActiveNamesAreBothProtectedWhenTheyDiffer(): void
    {
        $this->assertSame(['SITE'], EssentialCookies::sessionNames('SITE', 'SITE'));
        $this->assertSame(['SITE', 'OTHER'], EssentialCookies::sessionNames('SITE', 'OTHER'));
        $this->assertSame(['OTHER'], EssentialCookies::sessionNames('bad name', 'OTHER'));
    }

    public function testNamesIncludeEverythingConcreteNeeds(): void
    {
        $name = 'CONCRETE';
        $names = EssentialCookies::names($this->reader($name));

        foreach (['CONCRETE', 'CONCRETE_LOGIN', 'ccmAuthUserHash', 'ConcreteSitemapTreeID', 'ConcreteSitemap-expand', 'dashboardPanelStatus', 'cmk_consent'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function testAChangeOfSessionNameBetweenCallsIsPickedUpNothingIsCached(): void
    {
        $name = 'CONCRETE';
        $read = $this->reader($name);

        $this->assertTrue(EssentialCookies::isEssential('CONCRETE', $read));
        $this->assertFalse(EssentialCookies::isEssential('MYSITE', $read));

        $name = 'MYSITE'; // another package changed concrete.session.name at runtime

        $this->assertTrue(EssentialCookies::isEssential('MYSITE', $read));
        $this->assertTrue(EssentialCookies::isEssential('MYSITE_LOGIN', $read));
        $this->assertFalse(EssentialCookies::isEssential('CONCRETE', $read));
    }

    public function testSitemapTreeCookieWithNumericSuffixIsEssential(): void
    {
        $name = 'CONCRETE';
        $read = $this->reader($name);

        $this->assertTrue(EssentialCookies::isEssential('ConcreteSitemapTreeID-2', $read));
        $this->assertFalse(EssentialCookies::isEssential('ConcreteSitemapTreeID-x', $read));
        $this->assertFalse(EssentialCookies::isEssential("ConcreteSitemapTreeID-2\n", $read));
    }

    public function testThirdPartyCookiesAreNotEssential(): void
    {
        $name = 'CONCRETE';
        $read = $this->reader($name);

        foreach (['_ga', '_fbp', 'IDE', 'concrete', 'CONCRETE2'] as $cookie) {
            $this->assertFalse(EssentialCookies::isEssential($cookie, $read), $cookie);
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpunit tests/Support/EssentialCookiesTest.php`
Expected: FAIL, class `EssentialCookies` not found.

- [ ] **Step 3: Implement**

Create `src/Support/EssentialCookies.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Cookies ConcreteCMS needs to function. They are strictly necessary: the package never blocks, sets or deletes
 * them, and the cookie-clearing and scanner code consult this list first. The session name is read each time
 * because another package may change `concrete.session.name` at runtime; nothing here is stored or cached.
 */
final class EssentialCookies
{
    public const CONSENT_COOKIE = 'cmk_consent';
    public const DEFAULT_SESSION_NAME = 'CONCRETE';

    private const FIXED = ['ccmAuthUserHash', 'ConcreteSitemap-expand', 'dashboardPanelStatus', self::CONSENT_COOKIE];

    /**
     * @return list<string>
     */
    public static function sessionNames(mixed $configured, mixed $active = null): array
    {
        $names = [];

        foreach ([$configured, $active] as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $candidate) === 1) {
                $names[] = $candidate;
            }
        }

        return $names === [] ? [self::DEFAULT_SESSION_NAME] : array_values(array_unique($names));
    }

    /**
     * @param (callable(string): mixed)|null $read Config reader; defaults to Concrete's config repository.
     *
     * @return list<string>
     */
    public static function sessionNamesNow(?callable $read = null): array
    {
        $read ??= static fn(string $key): mixed => app('config')->get($key);
        $active = session_status() === PHP_SESSION_ACTIVE ? session_name() : null;

        return self::sessionNames($read('concrete.session.name'), $active);
    }

    /**
     * @param (callable(string): mixed)|null $read
     *
     * @return list<string>
     */
    public static function names(?callable $read = null): array
    {
        $names = [];

        foreach (self::sessionNamesNow($read) as $session) {
            $names[] = $session;
            $names[] = $session . '_LOGIN';
        }

        return array_values(array_unique([...$names, 'ConcreteSitemapTreeID', ...self::FIXED]));
    }

    /**
     * @param (callable(string): mixed)|null $read
     */
    public static function isEssential(string $cookie, ?callable $read = null): bool
    {
        return in_array($cookie, self::names($read), true)
            || preg_match('/^ConcreteSitemapTreeID(?:-\d+)?\z/', $cookie) === 1;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpunit tests/Support/EssentialCookiesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/EssentialCookies.php tests/Support/EssentialCookiesTest.php
git commit -m "feat: add lazily resolved essential ConcreteCMS cookie list

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Style rules and Settings entity

**Files:**
- Create: `src/Support/StyleRules.php`, `src/Entity/Settings.php`
- Test: `tests/Support/StyleRulesTest.php`, `tests/Entity/SettingsTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `StyleRules`: `const POSITIONS = ['bottom-left','bottom-right','bottom-bar','center-modal']`, `const LAYOUTS = ['stacked','inline']`, `const FONTS = ['inherit','system']`, `const DEFAULT_TOKENS` (`bg #ffffff`, `fg #111111`, `accent #0b5fff`, `buttonBg #0b5fff`, `buttonFg #ffffff`), `const MIN_LIFETIME_DAYS = 30`, `const MAX_LIFETIME_DAYS = 395`, `const DEFAULT_LIFETIME_DAYS = 180`; `static isHex(mixed): bool`, `static hex(mixed, string $default): string` (lower-cased valid hex or default), `static choice(mixed, array $allowed, string $default): string`, `static radius(mixed): int` (0..32), `static lifetime(mixed): int` (clamped 30..395, default 180), `static isValidLifetime(mixed): bool`.
  - `CookieManagerKit\Entity\Settings` (one row, extends `ClassKit\Entity\Core\UpdatedGuidEntity`): `static get(): self`; `getPosition()/setPosition($v)`, `getLayout()/setLayout($v)`, `getFont()/setFont($v)`, `getRadius()/setRadius($v)`, `hasShadow()/setShadow($v)`, `getToken(string $name): string`, `setToken(string $name, $v): self`, `getTokens(): array<string,string>`, `getLifetimeDays()/setLifetimeDays($v)`, `static isValidLifetime($v): bool`, `getPolicyVersion(): int`, `bumpPolicyVersion(): self`, `isWidgetEnabled()/setWidgetEnabled($v)`. All setters return `self`; invalid values fall back to defaults.

- [ ] **Step 1: Write the failing tests**

Create `tests/Support/StyleRulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\StyleRules;
use PHPUnit\Framework\Attributes\DataProvider;

final class StyleRulesTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function hexProvider(): array
    {
        return [
            'valid lower' => ['#0b5fff', true],
            'valid upper' => ['#0B5FFF', true],
            'short form' => ['#fff', false],
            'no hash' => ['0b5fff', false],
            'css injection' => ['red;}</style><script>alert(1)</script>', false],
            'expression' => ['#fff; background:url(javascript:alert(1))', false],
            'trailing newline' => ["#0b5fff\n", false],
            'array' => [['#0b5fff'], false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('hexProvider')]
    public function testIsHex(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, StyleRules::isHex($value));
    }

    public function testHexFallsBackToDefaultAndLowerCases(): void
    {
        $this->assertSame('#0b5fff', StyleRules::hex('#0B5FFF', '#000000'));
        $this->assertSame('#000000', StyleRules::hex('url(x)', '#000000'));
    }

    public function testChoiceOnlyReturnsAllowedValues(): void
    {
        $this->assertSame('inline', StyleRules::choice('inline', StyleRules::LAYOUTS, 'stacked'));
        $this->assertSame('stacked', StyleRules::choice('"><script>', StyleRules::LAYOUTS, 'stacked'));
        $this->assertSame('stacked', StyleRules::choice(['inline'], StyleRules::LAYOUTS, 'stacked'));
    }

    public function testRadiusIsClamped(): void
    {
        $this->assertSame(0, StyleRules::radius(-5));
        $this->assertSame(32, StyleRules::radius(999));
        $this->assertSame(8, StyleRules::radius('8'));
        $this->assertSame(8, StyleRules::radius('nonsense'));
    }

    public function testLifetime(): void
    {
        $this->assertSame(180, StyleRules::lifetime(null));
        $this->assertSame(30, StyleRules::lifetime(1));
        $this->assertSame(395, StyleRules::lifetime(9999));
        $this->assertSame(90, StyleRules::lifetime('90'));
        $this->assertTrue(StyleRules::isValidLifetime('90'));
        $this->assertFalse(StyleRules::isValidLifetime('9'));
        $this->assertFalse(StyleRules::isValidLifetime('abc'));
        $this->assertFalse(StyleRules::isValidLifetime('90.5'));
    }
}
```

Create `tests/Entity/SettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Settings;

final class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame('bottom-right', $settings->getPosition());
        $this->assertSame('stacked', $settings->getLayout());
        $this->assertSame('inherit', $settings->getFont());
        $this->assertSame(8, $settings->getRadius());
        $this->assertTrue($settings->hasShadow());
        $this->assertTrue($settings->isWidgetEnabled());
        $this->assertSame(180, $settings->getLifetimeDays());
        $this->assertSame(1, $settings->getPolicyVersion());
        $this->assertSame('#ffffff', $settings->getToken('bg'));
        $this->assertSame(['bg', 'fg', 'accent', 'buttonBg', 'buttonFg'], array_keys($settings->getTokens()));
    }

    public function testHostileValuesFallBackToDefaults(): void
    {
        $settings = (new Settings())
            ->setPosition('top"><script>')
            ->setLayout('x')
            ->setFont('Comic Sans; }')
            ->setToken('bg', 'red;}</style>')
            ->setRadius('999');

        $this->assertSame('bottom-right', $settings->getPosition());
        $this->assertSame('stacked', $settings->getLayout());
        $this->assertSame('inherit', $settings->getFont());
        $this->assertSame('#ffffff', $settings->getToken('bg'));
        $this->assertSame(32, $settings->getRadius());
    }

    public function testValidValuesAreStored(): void
    {
        $settings = (new Settings())
            ->setPosition('center-modal')
            ->setLayout('inline')
            ->setFont('system')
            ->setToken('accent', '#AA00FF')
            ->setShadow(false)
            ->setWidgetEnabled(false)
            ->setLifetimeDays('365');

        $this->assertSame('center-modal', $settings->getPosition());
        $this->assertSame('inline', $settings->getLayout());
        $this->assertSame('system', $settings->getFont());
        $this->assertSame('#aa00ff', $settings->getToken('accent'));
        $this->assertFalse($settings->hasShadow());
        $this->assertFalse($settings->isWidgetEnabled());
        $this->assertSame(365, $settings->getLifetimeDays());
    }

    public function testUnknownTokenNamesAreIgnored(): void
    {
        $settings = (new Settings())->setToken('evil', '#000000');

        $this->assertArrayNotHasKey('evil', $settings->getTokens());
        $this->assertSame('', $settings->getToken('evil'));
    }

    public function testBumpPolicyVersion(): void
    {
        $settings = new Settings();
        $settings->bumpPolicyVersion()->bumpPolicyVersion();

        $this->assertSame(3, $settings->getPolicyVersion());
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `composer test`
Expected: FAIL, classes `StyleRules` and `Settings` not found.

- [ ] **Step 3: Implement `StyleRules`**

Create `src/Support/StyleRules.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Allowlists for everything an admin can choose about the banner's look. Used when saving and again when the
 * config is built, so nothing unvalidated reaches an inline style or a data attribute.
 */
final class StyleRules
{
    public const POSITIONS = ['bottom-left', 'bottom-right', 'bottom-bar', 'center-modal'];
    public const LAYOUTS = ['stacked', 'inline'];
    public const FONTS = ['inherit', 'system'];

    public const DEFAULT_TOKENS = [
        'bg' => '#ffffff',
        'fg' => '#111111',
        'accent' => '#0b5fff',
        'buttonBg' => '#0b5fff',
        'buttonFg' => '#ffffff',
    ];

    public const MIN_LIFETIME_DAYS = 30;
    public const MAX_LIFETIME_DAYS = 395;
    public const DEFAULT_LIFETIME_DAYS = 180;

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}\z/', $value) === 1;
    }

    public static function hex(mixed $value, string $default): string
    {
        return self::isHex($value) ? strtolower($value) : $default;
    }

    /**
     * @param list<string> $allowed
     */
    public static function choice(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    public static function radius(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(32, (int) $value)) : 8;
    }

    public static function lifetime(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_LIFETIME_DAYS;
        }

        return max(self::MIN_LIFETIME_DAYS, min(self::MAX_LIFETIME_DAYS, (int) $value));
    }

    /**
     * Whether a submitted lifetime is a whole number of days within the allowed range (the setter still clamps).
     */
    public static function isValidLifetime(mixed $value): bool
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+\z/', trim($value)) === 1)) {
            return (int) $value >= self::MIN_LIFETIME_DAYS && (int) $value <= self::MAX_LIFETIME_DAYS;
        }

        return false;
    }
}
```

- [ ] **Step 4: Implement `Settings`**

Create `src/Entity/Settings.php`:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\StyleRules;
use Doctrine\ORM\EntityManagerInterface;
use ClassKit\Entity\Core\UpdatedGuidEntity;

/**
 * Package settings. There is only ever one row; nothing is stored in the package file config.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkSettings",
 *      options={"comment": "Cookie Manager settings"}
 * )
 */
class Settings extends UpdatedGuidEntity
{
    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "bottom-right", "comment": "Banner position"})
     */
    protected $position = 'bottom-right';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "stacked", "comment": "Button layout"})
     */
    protected $layout = 'stacked';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "inherit", "comment": "Font: inherit from the theme or system"})
     */
    protected $font = 'inherit';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 8, "comment": "Corner radius in px"})
     */
    protected $radius = 8;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": true, "comment": "Drop shadow"})
     */
    protected $shadow = true;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": true, "comment": "Show the floating cookie settings button"})
     */
    protected $widgetEnabled = true;

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#ffffff", "comment": "Background colour"})
     */
    protected $tokenBg = '#ffffff';

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#111111", "comment": "Text colour"})
     */
    protected $tokenFg = '#111111';

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#0b5fff", "comment": "Accent colour"})
     */
    protected $tokenAccent = '#0b5fff';

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#0b5fff", "comment": "Button background"})
     */
    protected $tokenButtonBg = '#0b5fff';

    /**
     * @ORM\Column(type="string", length=7, nullable=false, options={"default": "#ffffff", "comment": "Button text"})
     */
    protected $tokenButtonFg = '#ffffff';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 180, "comment": "Days a visitor's choice is remembered"})
     */
    protected $lifetimeDays = 180;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 1, "comment": "Bumping this asks every visitor again"})
     */
    protected $policyVersion = 1;

    /**
     * The saved settings, or an unsaved instance with the defaults if none have been saved yet. Never writes.
     */
    public static function get(): self
    {
        $settings = app(EntityManagerInterface::class)->getRepository(self::class)->findOneBy([]);

        return $settings instanceof self ? $settings : new self();
    }

    public function getPosition(): string
    {
        return StyleRules::choice($this->position, StyleRules::POSITIONS, 'bottom-right');
    }

    public function setPosition($value): self
    {
        $this->position = StyleRules::choice($value, StyleRules::POSITIONS, 'bottom-right');

        return $this;
    }

    public function getLayout(): string
    {
        return StyleRules::choice($this->layout, StyleRules::LAYOUTS, 'stacked');
    }

    public function setLayout($value): self
    {
        $this->layout = StyleRules::choice($value, StyleRules::LAYOUTS, 'stacked');

        return $this;
    }

    public function getFont(): string
    {
        return StyleRules::choice($this->font, StyleRules::FONTS, 'inherit');
    }

    public function setFont($value): self
    {
        $this->font = StyleRules::choice($value, StyleRules::FONTS, 'inherit');

        return $this;
    }

    public function getRadius(): int
    {
        return StyleRules::radius($this->radius);
    }

    public function setRadius($value): self
    {
        $this->radius = StyleRules::radius($value);

        return $this;
    }

    public function hasShadow(): bool
    {
        return (bool) $this->shadow;
    }

    public function setShadow($value): self
    {
        $this->shadow = (bool) $value;

        return $this;
    }

    public function isWidgetEnabled(): bool
    {
        return (bool) $this->widgetEnabled;
    }

    public function setWidgetEnabled($value): self
    {
        $this->widgetEnabled = (bool) $value;

        return $this;
    }

    private const TOKEN_PROPERTIES = [
        'bg' => 'tokenBg',
        'fg' => 'tokenFg',
        'accent' => 'tokenAccent',
        'buttonBg' => 'tokenButtonBg',
        'buttonFg' => 'tokenButtonFg',
    ];

    public function getToken(string $name): string
    {
        $property = self::TOKEN_PROPERTIES[$name] ?? null;

        return $property === null ? '' : StyleRules::hex($this->{$property}, StyleRules::DEFAULT_TOKENS[$name]);
    }

    public function setToken(string $name, $value): self
    {
        $property = self::TOKEN_PROPERTIES[$name] ?? null;

        if ($property !== null) {
            $this->{$property} = StyleRules::hex($value, StyleRules::DEFAULT_TOKENS[$name]);
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getTokens(): array
    {
        $tokens = [];

        foreach (array_keys(self::TOKEN_PROPERTIES) as $name) {
            $tokens[$name] = $this->getToken($name);
        }

        return $tokens;
    }

    public function getLifetimeDays(): int
    {
        return StyleRules::lifetime($this->lifetimeDays);
    }

    public function setLifetimeDays($value): self
    {
        $this->lifetimeDays = StyleRules::lifetime($value);

        return $this;
    }

    public static function isValidLifetime(mixed $value): bool
    {
        return StyleRules::isValidLifetime($value);
    }

    public function getPolicyVersion(): int
    {
        return max(1, (int) $this->policyVersion);
    }

    /**
     * Visitors whose stored choice carries an older version are asked again.
     */
    public function bumpPolicyVersion(): self
    {
        $this->policyVersion = $this->getPolicyVersion() + 1;

        return $this;
    }
}
```

- [ ] **Step 5: Run to verify they pass**

Run: `composer test`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Support/StyleRules.php src/Entity/Settings.php tests/Support/StyleRulesTest.php tests/Entity/SettingsTest.php
git commit -m "feat: add style allowlists and Settings entity

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Category, Service and BannerContent entities

**Files:**
- Create: `src/Entity/Category.php`, `src/Entity/CategoryContent.php`, `src/Entity/Service.php`, `src/Entity/ServiceContent.php`, `src/Entity/BannerContent.php`
- Test: `tests/Entity/CategoryTest.php`, `tests/Entity/ServiceTest.php`, `tests/Entity/BannerContentTest.php`

**Interfaces:**
- Consumes: `Text::plain`, `Text::multiline`, `Text::isHandle`, `Text::isLocale` (Task 1).
- Produces (all extend `ClassKit\Entity\Core\UpdatedEntity`; setters return `self` unless noted):
  - `Category`: `const NECESSARY = 'necessary'`; `getHandle(): string`, `setHandle(string): self` (throws `\InvalidArgumentException` if not `Text::isHandle`, `\LogicException` if already set), `getSortOrder(): int`, `setSortOrder($v)`, `isNecessary(): bool`, `setNecessary(bool)`, `getContent(string $locale): ?CategoryContent`, `setContent(string $locale, string $title, string $description): CategoryContent`, `getContents(): Collection`, `getServices(): Collection`, `canBeDeleted(): bool`.
  - `CategoryContent`: `getLocale()`, `setLocale(string)` (throws `\InvalidArgumentException`), `getTitle()/setTitle`, `getDescription()/setDescription`, `getCategory(): ?Category`, `setCategory(Category)`.
  - `Service`: `getName()/setName`, `getVendor()/setVendor`, `getCategory(): ?Category`, `setCategory(Category)`, `getDomains(): list<string>`, `getDomainsText(): string`, `setDomainsText(string)`, `isActive()/setActive($v)`, `getContent(string $locale): ?ServiceContent`, `setContent(string $locale, string $description): ServiceContent`, `getContents(): Collection`.
  - `ServiceContent`: `getLocale()/setLocale`, `getDescription()/setDescription`, `getService()/setService(Service)`.
  - `BannerContent`: `getLocale()/setLocale`, `getTitle()/setTitle`, `getBody()/setBody`, `getAcceptAllLabel()/setAcceptAllLabel`, `getRejectAllLabel()/setRejectAllLabel`, `getCustomiseLabel()/setCustomiseLabel`, `getSaveLabel()/setSaveLabel`, `getWidgetLabel()/setWidgetLabel`, `getPolicyLinkText()/setPolicyLinkText`, `getPolicyPageId(): ?int`, `setPolicyPageId($v)`, `isEmpty(): bool`.
  - Tables: `cmkCategories`, `cmkCategoryContents`, `cmkServices`, `cmkServiceContents`, `cmkBannerContents`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Entity/CategoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\{Category, Service};

final class CategoryTest extends TestCase
{
    public function testHandleMustBeAValidSlug(): void
    {
        $category = (new Category())->setHandle('analytics');
        $this->assertSame('analytics', $category->getHandle());

        foreach (['', 'Upper', '<script>', "a\n", str_repeat('a', 33)] as $bad) {
            try {
                (new Category())->setHandle($bad);
                $this->fail('Expected an exception for ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHandleCannotBeChangedOnceSet(): void
    {
        $this->expectException(\LogicException::class);
        (new Category())->setHandle('analytics')->setHandle('marketing');
    }

    public function testContentIsPerLocaleAndPlainText(): void
    {
        $category = (new Category())->setHandle('analytics');
        $category->setContent('en_GB', '<b>Analytics</b>', "Helps us <script>x</script>improve\n\n\n\nthe site");
        $category->setContent('cy_GB', 'Dadansoddeg', '');

        $this->assertSame('Analytics', $category->getContent('en_GB')?->getTitle());
        $this->assertStringNotContainsString('<', (string) $category->getContent('en_GB')?->getDescription());
        $this->assertSame('Dadansoddeg', $category->getContent('cy_GB')?->getTitle());
        $this->assertNull($category->getContent('fr_FR'));
        $this->assertCount(2, $category->getContents());
    }

    public function testSettingContentTwiceUpdatesTheSameRow(): void
    {
        $category = (new Category())->setHandle('analytics');
        $category->setContent('en_GB', 'One', '');
        $category->setContent('en_GB', 'Two', '');

        $this->assertCount(1, $category->getContents());
        $this->assertSame('Two', $category->getContent('en_GB')?->getTitle());
    }

    public function testInvalidLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Category())->setHandle('analytics')->setContent('<script>', 'x', 'y');
    }

    public function testNecessaryCategoryCanNeverBeDeleted(): void
    {
        $necessary = (new Category())->setHandle('necessary')->setNecessary(true);
        $this->assertTrue($necessary->isNecessary());
        $this->assertFalse($necessary->canBeDeleted());
    }

    public function testCustomCategoryCanBeDeletedOnlyWhenEmpty(): void
    {
        $category = (new Category())->setHandle('custom');
        $this->assertTrue($category->canBeDeleted());

        $service = (new Service())->setName('Tool')->setCategory($category);
        $category->getServices()->add($service);

        $this->assertFalse($category->canBeDeleted());
    }
}
```

Create `tests/Entity/ServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Service;

final class ServiceTest extends TestCase
{
    public function testNameAndVendorArePlainText(): void
    {
        $service = (new Service())->setName('<i>Google Analytics</i>')->setVendor("Google\nLLC");

        $this->assertSame('Google Analytics', $service->getName());
        $this->assertSame('Google LLC', $service->getVendor());
    }

    public function testDomainsAreValidatedLowerCasedAndDeduplicated(): void
    {
        $service = (new Service())->setDomainsText(
            "Example.COM\n*.google-analytics.com\nhttps://bad.example.com\njavascript:alert(1)\n<script>\nexample.com\nnot a host\n-bad.com\n",
        );

        $this->assertSame(['example.com', '*.google-analytics.com'], $service->getDomains());
        $this->assertSame("example.com\n*.google-analytics.com", $service->getDomainsText());
    }

    public function testActiveDefaultsToTrue(): void
    {
        $this->assertTrue((new Service())->isActive());
        $this->assertFalse((new Service())->setActive(false)->isActive());
    }

    public function testDescriptionIsPerLocale(): void
    {
        $service = new Service();
        $service->setContent('en_GB', 'Measures <b>visits</b>');
        $service->setContent('en_GB', 'Measures visits');
        $service->setContent('cy_GB', 'Mesur ymweliadau');

        $this->assertCount(2, $service->getContents());
        $this->assertSame('Measures visits', $service->getContent('en_GB')?->getDescription());
        $this->assertNull($service->getContent('de_DE'));
    }
}
```

Create `tests/Entity/BannerContentTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\BannerContent;

final class BannerContentTest extends TestCase
{
    public function testAllTextIsPlain(): void
    {
        $content = (new BannerContent())
            ->setLocale('en_GB')
            ->setTitle('<script>alert(1)</script>Privacy')
            ->setBody("Line one\n<img src=x onerror=alert(1)>Line two")
            ->setAcceptAllLabel('<b>Accept</b>');

        $this->assertSame('alert(1)Privacy', $content->getTitle());
        $this->assertSame("Line one\nLine two", $content->getBody());
        $this->assertSame('Accept', $content->getAcceptAllLabel());
    }

    public function testLabelsAreCapped(): void
    {
        $this->assertSame(64, mb_strlen((new BannerContent())->setSaveLabel(str_repeat('a', 500))->getSaveLabel()));
        $this->assertSame(255, mb_strlen((new BannerContent())->setTitle(str_repeat('a', 500))->getTitle()));
    }

    public function testPolicyPageIdMustBePositive(): void
    {
        $this->assertSame(12, (new BannerContent())->setPolicyPageId('12')->getPolicyPageId());
        $this->assertNull((new BannerContent())->setPolicyPageId('0')->getPolicyPageId());
        $this->assertNull((new BannerContent())->setPolicyPageId('abc')->getPolicyPageId());
        $this->assertNull((new BannerContent())->setPolicyPageId('-3')->getPolicyPageId());
        $this->assertNull((new BannerContent())->setPolicyPageId(null)->getPolicyPageId());
    }

    public function testInvalidLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BannerContent())->setLocale('en-GB');
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue((new BannerContent())->setLocale('cy_GB')->isEmpty());
        $this->assertFalse((new BannerContent())->setLocale('cy_GB')->setTitle('Hi')->isEmpty());
        $this->assertFalse((new BannerContent())->setLocale('cy_GB')->setPolicyPageId(4)->isEmpty());
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `composer test`
Expected: FAIL, entity classes not found.

- [ ] **Step 3: Implement `CategoryContent` and `Category`**

Create `src/Entity/CategoryContent.php`:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * One locale's title and description for a category.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkCategoryContents",
 *      indexes={@ORM\Index(name="idx_cmk_cc_locale", columns={"locale"})},
 *      options={"comment": "Per-locale content for consent categories"}
 * )
 */
class CategoryContent extends UpdatedEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="Category", inversedBy="contents")
     * @ORM\JoinColumn(name="categoryID", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    protected $category;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Locale ID, eg. en_GB"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Title (plain text)"})
     */
    protected $title = '';

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Description (plain text)"})
     */
    protected $description = '';

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getLocale(): string
    {
        return (string) $this->locale;
    }

    public function setLocale(string $locale): self
    {
        if (!Text::isLocale($locale)) {
            throw new \InvalidArgumentException('Invalid locale.');
        }

        $this->locale = $locale;

        return $this;
    }

    public function getTitle(): string
    {
        return (string) $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = Text::plain($title);

        return $this;
    }

    public function getDescription(): string
    {
        return (string) $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = Text::multiline($description);

        return $this;
    }
}
```

Create `src/Entity/Category.php`:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;
use Doctrine\Common\Collections\{ArrayCollection, Collection};

/**
 * A consent category (necessary, preferences, analytics, marketing, or a custom one). The necessary category is
 * always on and can never be removed.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkCategories",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_category_handle", columns={"handle"})},
 *      options={"comment": "Consent categories"}
 * )
 */
class Category extends UpdatedEntity
{
    public const NECESSARY = 'necessary';

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Unique slug, immutable once set"})
     */
    protected $handle = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Display order"})
     */
    protected $sortOrder = 0;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false, "comment": "Always on, cannot be refused or deleted"})
     */
    protected $necessary = false;

    /**
     * @ORM\OneToMany(targetEntity="CategoryContent", mappedBy="category", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected $contents;

    /**
     * @ORM\OneToMany(targetEntity="Service", mappedBy="category")
     */
    protected $services;

    public function __construct()
    {
        parent::__construct();

        $this->contents = new ArrayCollection();
        $this->services = new ArrayCollection();
    }

    public function getHandle(): string
    {
        return (string) $this->handle;
    }

    public function setHandle(string $handle): self
    {
        if ($this->handle !== '') {
            throw new \LogicException('A category handle cannot be changed.');
        }

        if (!Text::isHandle($handle)) {
            throw new \InvalidArgumentException('Invalid category handle.');
        }

        $this->handle = $handle;

        return $this;
    }

    public function getSortOrder(): int
    {
        return (int) $this->sortOrder;
    }

    public function setSortOrder($value): self
    {
        $this->sortOrder = max(0, min(9999, (int) $value));

        return $this;
    }

    public function isNecessary(): bool
    {
        return (bool) $this->necessary;
    }

    public function setNecessary(bool $necessary): self
    {
        $this->necessary = $necessary;

        return $this;
    }

    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function getContent(string $locale): ?CategoryContent
    {
        foreach ($this->contents as $content) {
            if ($content->getLocale() === $locale) {
                return $content;
            }
        }

        return null;
    }

    public function setContent(string $locale, string $title, string $description): CategoryContent
    {
        $content = $this->getContent($locale);

        if ($content === null) {
            $content = (new CategoryContent())->setLocale($locale)->setCategory($this);
            $this->contents->add($content);
        }

        $content->setTitle($title)->setDescription($description);

        return $content;
    }

    public function getServices(): Collection
    {
        return $this->services;
    }

    public function canBeDeleted(): bool
    {
        return !$this->isNecessary() && $this->services->count() === 0;
    }
}
```

- [ ] **Step 4: Implement `ServiceContent` and `Service`**

Create `src/Entity/ServiceContent.php`:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * One locale's description of a service.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkServiceContents",
 *      indexes={@ORM\Index(name="idx_cmk_sc_locale", columns={"locale"})},
 *      options={"comment": "Per-locale content for services"}
 * )
 */
class ServiceContent extends UpdatedEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="Service", inversedBy="contents")
     * @ORM\JoinColumn(name="serviceID", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    protected $service;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Locale ID, eg. en_GB"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Description (plain text)"})
     */
    protected $description = '';

    public function getService(): ?Service
    {
        return $this->service;
    }

    public function setService(Service $service): self
    {
        $this->service = $service;

        return $this;
    }

    public function getLocale(): string
    {
        return (string) $this->locale;
    }

    public function setLocale(string $locale): self
    {
        if (!Text::isLocale($locale)) {
            throw new \InvalidArgumentException('Invalid locale.');
        }

        $this->locale = $locale;

        return $this;
    }

    public function getDescription(): string
    {
        return (string) $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = Text::multiline($description, 1000);

        return $this;
    }
}
```

Create `src/Entity/Service.php`:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;
use Doctrine\Common\Collections\{ArrayCollection, Collection};

/**
 * A third-party service (vendor tool) that belongs to one consent category. Phase 1 uses it for display; later
 * phases use its domains for scanning and embed blocking.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkServices",
 *      options={"comment": "Third-party services listed under consent categories"}
 * )
 */
class Service extends UpdatedEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="Category", inversedBy="services")
     * @ORM\JoinColumn(name="categoryID", referencedColumnName="id", onDelete="RESTRICT", nullable=false)
     */
    protected $category;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Service name"})
     */
    protected $name = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Vendor / provider"})
     */
    protected $vendor = '';

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Domains, one per line"})
     */
    protected $domains = '';

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": true, "comment": "Listed to visitors"})
     */
    protected $active = true;

    /**
     * @ORM\OneToMany(targetEntity="ServiceContent", mappedBy="service", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected $contents;

    public function __construct()
    {
        parent::__construct();

        $this->contents = new ArrayCollection();
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = Text::plain($name);

        return $this;
    }

    public function getVendor(): string
    {
        return (string) $this->vendor;
    }

    public function setVendor(?string $vendor): self
    {
        $this->vendor = Text::plain($vendor);

        return $this;
    }

    /**
     * Keeps only plain hostnames (optionally with a leading "*."), lower-cased, one per line.
     */
    public function setDomainsText(?string $text): self
    {
        $this->domains = implode("\n", self::parseDomains((string) $text));

        return $this;
    }

    public function getDomainsText(): string
    {
        return (string) $this->domains;
    }

    /**
     * @return list<string>
     */
    public function getDomains(): array
    {
        return $this->domains === '' ? [] : explode("\n", (string) $this->domains);
    }

    /**
     * @return list<string>
     */
    public static function parseDomains(string $text): array
    {
        $domains = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = strtolower(trim($line));

            if (preg_match('/^(?:\*\.)?(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $line) === 1) {
                $domains[] = $line;
            }
        }

        return array_values(array_unique($domains));
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    public function setActive($value): self
    {
        $this->active = (bool) $value;

        return $this;
    }

    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function getContent(string $locale): ?ServiceContent
    {
        foreach ($this->contents as $content) {
            if ($content->getLocale() === $locale) {
                return $content;
            }
        }

        return null;
    }

    public function setContent(string $locale, string $description): ServiceContent
    {
        $content = $this->getContent($locale);

        if ($content === null) {
            $content = (new ServiceContent())->setLocale($locale)->setService($this);
            $this->contents->add($content);
        }

        $content->setDescription($description);

        return $content;
    }
}
```

- [ ] **Step 5: Implement `BannerContent`**

Create `src/Entity/BannerContent.php`:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * One locale's banner text. All text is plain; empty labels fall back to built-in English at build time.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkBannerContents",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_banner_locale", columns={"locale"})},
 *      options={"comment": "Per-locale banner content"}
 * )
 */
class BannerContent extends UpdatedEntity
{
    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Locale ID, eg. en_GB"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Heading"})
     */
    protected $title = '';

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Body text (plain text, line breaks kept)"})
     */
    protected $body = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $acceptAllLabel = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $rejectAllLabel = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $customiseLabel = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $saveLabel = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": ""})
     */
    protected $widgetLabel = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": ""})
     */
    protected $policyLinkText = '';

    /**
     * @ORM\Column(type="integer", nullable=true, options={"comment": "Cookie policy page cID"})
     */
    protected $policyPageId = null;

    public function getLocale(): string
    {
        return (string) $this->locale;
    }

    public function setLocale(string $locale): self
    {
        if (!Text::isLocale($locale)) {
            throw new \InvalidArgumentException('Invalid locale.');
        }

        $this->locale = $locale;

        return $this;
    }

    public function getTitle(): string
    {
        return (string) $this->title;
    }

    public function setTitle(?string $value): self
    {
        $this->title = Text::plain($value);

        return $this;
    }

    public function getBody(): string
    {
        return (string) $this->body;
    }

    public function setBody(?string $value): self
    {
        $this->body = Text::multiline($value);

        return $this;
    }

    public function getAcceptAllLabel(): string
    {
        return (string) $this->acceptAllLabel;
    }

    public function setAcceptAllLabel(?string $value): self
    {
        $this->acceptAllLabel = Text::plain($value, 64);

        return $this;
    }

    public function getRejectAllLabel(): string
    {
        return (string) $this->rejectAllLabel;
    }

    public function setRejectAllLabel(?string $value): self
    {
        $this->rejectAllLabel = Text::plain($value, 64);

        return $this;
    }

    public function getCustomiseLabel(): string
    {
        return (string) $this->customiseLabel;
    }

    public function setCustomiseLabel(?string $value): self
    {
        $this->customiseLabel = Text::plain($value, 64);

        return $this;
    }

    public function getSaveLabel(): string
    {
        return (string) $this->saveLabel;
    }

    public function setSaveLabel(?string $value): self
    {
        $this->saveLabel = Text::plain($value, 64);

        return $this;
    }

    public function getWidgetLabel(): string
    {
        return (string) $this->widgetLabel;
    }

    public function setWidgetLabel(?string $value): self
    {
        $this->widgetLabel = Text::plain($value, 64);

        return $this;
    }

    public function getPolicyLinkText(): string
    {
        return (string) $this->policyLinkText;
    }

    public function setPolicyLinkText(?string $value): self
    {
        $this->policyLinkText = Text::plain($value);

        return $this;
    }

    public function getPolicyPageId(): ?int
    {
        return $this->policyPageId === null ? null : (int) $this->policyPageId;
    }

    public function setPolicyPageId($value): self
    {
        $this->policyPageId = (is_int($value) || (is_string($value) && preg_match('/^\d+\z/', $value) === 1)) && (int) $value > 0
            ? (int) $value
            : null;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->title === '' && $this->body === '' && $this->acceptAllLabel === '' && $this->rejectAllLabel === ''
            && $this->customiseLabel === '' && $this->saveLabel === '' && $this->widgetLabel === ''
            && $this->policyLinkText === '' && $this->policyPageId === null;
    }
}
```

- [ ] **Step 6: Run to verify they pass**

Run: `composer test`
Expected: all PASS, including `SourceLayoutTest` (every new file declares a class).

- [ ] **Step 7: Commit**

```bash
git add src/Entity tests/Entity
git commit -m "feat: add category, service and banner content entities

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```


---

### Task 5: Config builder and locale resolver (pure)

**Files:**
- Create: `src/Support/ConfigBuilder.php`, `src/Support/LocaleResolver.php`
- Test: `tests/Support/ConfigBuilderTest.php`, `tests/Support/LocaleResolverTest.php`

**Interfaces:**
- Consumes: `Output::json`, `Output::safeUrl` (Task 1), `Text::isHandle`, `Text::isLocale` (Task 1), `StyleRules::*` (Task 3).
- Produces:
  - `LocaleResolver::resolve(string $requested, array $siteLocales, string $default): string` returns the matching entry of `$siteLocales` (case-insensitive) or `$default`; never returns the requested string itself.
  - `ConfigBuilder::build(array $data, string $locale, string $defaultLocale, array $essentialCookies): array`. `$data` is the **snapshot shape**:
    ```php
    [
      'settings'   => ['policyVersion' => int, 'lifetimeDays' => int, 'widget' => bool, 'position' => string,
                       'layout' => string, 'font' => string, 'radius' => int, 'shadow' => bool,
                       'tokens' => ['bg' => '#rrggbb', 'fg' => ..., 'accent' => ..., 'buttonBg' => ..., 'buttonFg' => ...]],
      'banner'     => [locale => ['title','body','acceptAll','rejectAll','customise','save','widget','policyLinkText','policyUrl']],
      'categories' => [['handle','necessary','sort','content' => [locale => ['title','description']],
                        'services' => [['name','vendor','content' => [locale => description]]]]],
      'defaults'   => ['title','body','acceptAll','rejectAll','customise','save','widget','policyLinkText'],
    ]
    ```
    Returns the **config shape** (exactly what the endpoint serves):
    ```php
    ['version' => 12-char hash, 'policyVersion' => int, 'locale' => string,
     'banner' => ['title','body','labels' => ['acceptAll','rejectAll','customise','save','widget'], 'policyUrl','policyLinkText'],
     'categories' => [['handle','necessary','title','description','services' => [['name','vendor','description']],'cookies' => list<string>]],
     'style' => ['position','layout','font','tokens' => [...],'radius','shadow'],
     'lifetimeDays' => int, 'widget' => bool]
    ```

- [ ] **Step 1: Write the failing tests**

Create `tests/Support/LocaleResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\LocaleResolver;

final class LocaleResolverTest extends TestCase
{
    private const SITE = ['en_GB', 'cy_GB'];

    public function testMatchesASiteLocaleCaseInsensitivelyAndReturnsTheSiteSpelling(): void
    {
        $this->assertSame('cy_GB', LocaleResolver::resolve('cy_GB', self::SITE, 'en_GB'));
        $this->assertSame('cy_GB', LocaleResolver::resolve('CY_gb', self::SITE, 'en_GB'));
    }

    public function testUnknownLocalesResolveToTheDefaultAndAreNeverEchoed(): void
    {
        foreach (['fr_FR', '', '<script>alert(1)</script>', '../../etc/passwd', "en_GB\n", str_repeat('a', 5000), 'en-GB'] as $hostile) {
            // None of these is a site locale, so the default comes back and the hostile value is not echoed.
            $this->assertSame('en_GB', LocaleResolver::resolve($hostile, self::SITE, 'en_GB'));
        }
    }
}
```

Create `tests/Support/ConfigBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\ConfigBuilder;

final class ConfigBuilderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        return [
            'settings' => [
                'policyVersion' => 3,
                'lifetimeDays' => 180,
                'widget' => true,
                'position' => 'bottom-left',
                'layout' => 'inline',
                'font' => 'system',
                'radius' => 12,
                'shadow' => false,
                'tokens' => ['bg' => '#101010', 'fg' => '#f0f0f0', 'accent' => '#ff0000', 'buttonBg' => '#00ff00', 'buttonFg' => '#000000'],
            ],
            'banner' => [
                'en_GB' => [
                    'title' => 'Your privacy', 'body' => "We use cookies.\nChoose below.", 'acceptAll' => 'Accept all', 'rejectAll' => 'Reject all',
                    'customise' => 'Customise', 'save' => 'Save', 'widget' => 'Cookie settings', 'policyLinkText' => 'Cookie policy',
                    'policyUrl' => 'https://example.com/cookies',
                ],
                'cy_GB' => [
                    'title' => 'Eich preifatrwydd', 'body' => '', 'acceptAll' => 'Derbyn pob un', 'rejectAll' => '',
                    'customise' => '', 'save' => '', 'widget' => '', 'policyLinkText' => '', 'policyUrl' => '',
                ],
            ],
            'categories' => [
                [
                    'handle' => 'marketing', 'necessary' => false, 'sort' => 30,
                    'content' => ['en_GB' => ['title' => 'Marketing', 'description' => 'Ads']],
                    'services' => [['name' => 'Meta Pixel', 'vendor' => 'Meta', 'content' => ['en_GB' => 'Tracks conversions']]],
                ],
                [
                    'handle' => 'necessary', 'necessary' => true, 'sort' => 0,
                    'content' => ['en_GB' => ['title' => 'Necessary', 'description' => 'Required'], 'cy_GB' => ['title' => 'Angenrheidiol', 'description' => '']],
                    'services' => [],
                ],
            ],
            'defaults' => [
                'title' => 'We value your privacy', 'body' => 'We use cookies.', 'acceptAll' => 'Accept all', 'rejectAll' => 'Reject all',
                'customise' => 'Customise', 'save' => 'Save my choices', 'widget' => 'Cookie settings', 'policyLinkText' => 'Cookie policy',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function build(array $data, string $locale = 'en_GB'): array
    {
        return ConfigBuilder::build($data, $locale, 'en_GB', ['CONCRETE', 'cmk_consent']);
    }

    public function testBuildsTheDocumentedShape(): void
    {
        $config = self::build(self::data());

        $this->assertSame(['version', 'policyVersion', 'locale', 'banner', 'categories', 'style', 'lifetimeDays', 'widget'], array_keys($config));
        $this->assertSame(3, $config['policyVersion']);
        $this->assertSame('en_GB', $config['locale']);
        $this->assertSame('Your privacy', $config['banner']['title']);
        $this->assertSame('https://example.com/cookies', $config['banner']['policyUrl']);
        $this->assertSame('Accept all', $config['banner']['labels']['acceptAll']);
        $this->assertSame('bottom-left', $config['style']['position']);
        $this->assertSame(12, $config['style']['radius']);
        $this->assertFalse($config['style']['shadow']);
        $this->assertSame(180, $config['lifetimeDays']);
        $this->assertSame(12, strlen($config['version']));
    }

    public function testCategoriesAreSortedAndNecessaryListsTheEssentialCookies(): void
    {
        $config = self::build(self::data());

        $this->assertSame(['necessary', 'marketing'], array_column($config['categories'], 'handle'));
        $this->assertSame(['CONCRETE', 'cmk_consent'], $config['categories'][0]['cookies']);
        $this->assertSame([], $config['categories'][1]['cookies']);
        $this->assertSame('Tracks conversions', $config['categories'][1]['services'][0]['description']);
    }

    public function testEmptyFieldsFallBackPerFieldToTheDefaultLocaleThenToBuiltInText(): void
    {
        $config = self::build(self::data(), 'cy_GB');

        $this->assertSame('cy_GB', $config['locale']);
        $this->assertSame('Eich preifatrwydd', $config['banner']['title']);            // own value
        $this->assertSame("We use cookies.\nChoose below.", $config['banner']['body']); // default locale's value
        $this->assertSame('Derbyn pob un', $config['banner']['labels']['acceptAll']);   // own value
        $this->assertSame('Reject all', $config['banner']['labels']['rejectAll']);      // default locale's value
        $this->assertSame('https://example.com/cookies', $config['banner']['policyUrl']);
        $this->assertSame('Angenrheidiol', $config['categories'][0]['title']);
        $this->assertSame('Required', $config['categories'][0]['description']);        // empty Welsh description -> English
    }

    public function testNothingConfiguredUsesTheBuiltInEnglishText(): void
    {
        $data = self::data();
        $data['banner'] = [];
        $data['categories'][1]['content'] = [];

        $config = self::build($data);

        $this->assertSame('We value your privacy', $config['banner']['title']);
        $this->assertSame('Save my choices', $config['banner']['labels']['save']);
        $this->assertSame('necessary', $config['categories'][0]['title']);              // handle as the last resort
    }

    public function testAnUnknownLocaleRendersTheDefaultLocalesContent(): void
    {
        $config = self::build(self::data(), 'fr_FR');

        $this->assertSame('Your privacy', $config['banner']['title']);
    }

    public function testStyleValuesAreRevalidatedWhenBuilt(): void
    {
        $data = self::data();
        $data['settings']['position'] = '"><script>';
        $data['settings']['layout'] = 'x';
        $data['settings']['font'] = 'url(x)';
        $data['settings']['radius'] = 99999;
        $data['settings']['tokens']['bg'] = 'red;}</style><script>alert(1)</script>';
        $data['settings']['tokens']['fg'] = ['#ffffff'];

        $style = self::build($data)['style'];

        $this->assertSame('bottom-right', $style['position']);
        $this->assertSame('stacked', $style['layout']);
        $this->assertSame('inherit', $style['font']);
        $this->assertSame(32, $style['radius']);
        $this->assertSame('#ffffff', $style['tokens']['bg']);
        $this->assertSame('#111111', $style['tokens']['fg']);
    }

    public function testUnsafePolicyUrlsAreRemoved(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.example', "java\tscript:x"] as $url) {
            $data = self::data();
            $data['banner']['en_GB']['policyUrl'] = $url;
            $data['banner']['cy_GB']['policyUrl'] = '';

            $this->assertSame('', self::build($data)['banner']['policyUrl'], $url);
        }
    }

    public function testInvalidCategoryHandlesAreSkipped(): void
    {
        $data = self::data();
        $data['categories'][] = ['handle' => '<script>', 'necessary' => false, 'sort' => 1, 'content' => [], 'services' => []];
        $data['categories'][] = ['handle' => "analytics\n", 'necessary' => false, 'sort' => 2, 'content' => [], 'services' => []];

        $this->assertSame(['necessary', 'marketing'], array_column(self::build($data)['categories'], 'handle'));
    }

    public function testHostileContentCannotBreakOutOfTheSerialisedJson(): void
    {
        $data = self::data();
        $data['banner']['en_GB']['title'] = '</script><script>alert(1)</script>';
        $data['banner']['en_GB']['body'] = '"><img src=x onerror=alert(1)>';
        $data['categories'][1]['services'][0]['name'] = "'; alert(1); //";

        $json = \CookieManagerKit\Support\Output::json(self::build($data));

        foreach (['<', '>', '&', "'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertSame('</script><script>alert(1)</script>', json_decode($json, true)['banner']['title']);
    }

    public function testVersionIsStableAndChangesWithContentAndWithTheEssentialCookieNames(): void
    {
        $a = self::build(self::data())['version'];
        $b = self::build(self::data())['version'];
        $this->assertSame($a, $b);

        $changed = self::data();
        $changed['banner']['en_GB']['title'] = 'Different';
        $this->assertNotSame($a, self::build($changed)['version']);

        $renamed = ConfigBuilder::build(self::data(), 'en_GB', 'en_GB', ['MYSITE', 'cmk_consent']);
        $this->assertNotSame($a, $renamed['version']);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpunit tests/Support/ConfigBuilderTest.php tests/Support/LocaleResolverTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement `LocaleResolver`**

Create `src/Support/LocaleResolver.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Turns a locale that arrived from the outside (URL path) into one the site actually has. The result is always
 * one of the site's own spellings or the default, so a hostile value is never echoed back or used as a cache key.
 */
final class LocaleResolver
{
    /**
     * @param list<string> $siteLocales
     */
    public static function resolve(string $requested, array $siteLocales, string $default): string
    {
        if (Text::isLocale($requested)) {
            foreach ($siteLocales as $locale) {
                if (strcasecmp($locale, $requested) === 0) {
                    return $locale;
                }
            }
        }

        return $default;
    }
}
```

- [ ] **Step 4: Implement `ConfigBuilder`**

Create `src/Support/ConfigBuilder.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Pure: turns the plain-data snapshot into the config document the runtime renders. No Concrete, no database.
 * Fallback is per field: the visitor's locale, then the site default locale, then built-in English text.
 */
final class ConfigBuilder
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $essentialCookies
     *
     * @return array<string, mixed>
     */
    public static function build(array $data, string $locale, string $defaultLocale, array $essentialCookies): array
    {
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $defaults = is_array($data['defaults'] ?? null) ? $data['defaults'] : [];
        $banner = is_array($data['banner'] ?? null) ? $data['banner'] : [];

        $text = static fn(string $key): string => self::field($banner, $locale, $defaultLocale, $key, (string) ($defaults[$key] ?? ''));

        $categories = [];

        foreach (self::sorted(is_array($data['categories'] ?? null) ? $data['categories'] : []) as $category) {
            $handle = $category['handle'] ?? null;

            if (!Text::isHandle($handle)) {
                continue;
            }

            $content = is_array($category['content'] ?? null) ? $category['content'] : [];
            $necessary = (bool) ($category['necessary'] ?? false);
            $services = [];

            foreach (is_array($category['services'] ?? null) ? $category['services'] : [] as $service) {
                $descriptions = is_array($service['content'] ?? null) ? $service['content'] : [];

                $services[] = [
                    'name' => (string) ($service['name'] ?? ''),
                    'vendor' => (string) ($service['vendor'] ?? ''),
                    'description' => self::scalar($descriptions, $locale, $defaultLocale),
                ];
            }

            $categories[] = [
                'handle' => $handle,
                'necessary' => $necessary,
                'title' => self::field($content, $locale, $defaultLocale, 'title', $handle),
                'description' => self::field($content, $locale, $defaultLocale, 'description', ''),
                'services' => $services,
                'cookies' => $necessary ? array_values($essentialCookies) : [],
            ];
        }

        $tokens = [];
        $savedTokens = is_array($settings['tokens'] ?? null) ? $settings['tokens'] : [];

        foreach (StyleRules::DEFAULT_TOKENS as $name => $fallback) {
            $tokens[$name] = StyleRules::hex($savedTokens[$name] ?? null, $fallback);
        }

        $config = [
            'policyVersion' => max(1, (int) ($settings['policyVersion'] ?? 1)),
            'locale' => $locale,
            'banner' => [
                'title' => $text('title'),
                'body' => $text('body'),
                'labels' => [
                    'acceptAll' => $text('acceptAll'),
                    'rejectAll' => $text('rejectAll'),
                    'customise' => $text('customise'),
                    'save' => $text('save'),
                    'widget' => $text('widget'),
                ],
                'policyUrl' => Output::safeUrl(self::field($banner, $locale, $defaultLocale, 'policyUrl', '')),
                'policyLinkText' => $text('policyLinkText'),
            ],
            'categories' => $categories,
            'style' => [
                'position' => StyleRules::choice($settings['position'] ?? null, StyleRules::POSITIONS, 'bottom-right'),
                'layout' => StyleRules::choice($settings['layout'] ?? null, StyleRules::LAYOUTS, 'stacked'),
                'font' => StyleRules::choice($settings['font'] ?? null, StyleRules::FONTS, 'inherit'),
                'tokens' => $tokens,
                'radius' => StyleRules::radius($settings['radius'] ?? 8),
                'shadow' => (bool) ($settings['shadow'] ?? true),
            ],
            'lifetimeDays' => StyleRules::lifetime($settings['lifetimeDays'] ?? null),
            'widget' => (bool) ($settings['widget'] ?? true),
        ];

        return ['version' => substr(sha1(Output::json($config)), 0, 12)] + $config;
    }

    /**
     * One field from a per-locale map: own locale, then the default locale, then the fallback. Empty means "not set".
     *
     * @param array<string, mixed> $byLocale
     */
    private static function field(array $byLocale, string $locale, string $defaultLocale, string $key, string $fallback): string
    {
        foreach ([$locale, $defaultLocale] as $candidate) {
            $row = $byLocale[$candidate] ?? null;
            $value = is_array($row) ? ($row[$key] ?? '') : '';

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $byLocale locale => string
     */
    private static function scalar(array $byLocale, string $locale, string $defaultLocale): string
    {
        foreach ([$locale, $defaultLocale] as $candidate) {
            $value = $byLocale[$candidate] ?? '';

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<int, mixed> $categories
     *
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $categories): array
    {
        $rows = array_values(array_filter($categories, 'is_array'));

        usort($rows, static function (array $a, array $b): int {
            return [(int) ($a['sort'] ?? 0), (string) ($a['handle'] ?? '')] <=> [(int) ($b['sort'] ?? 0), (string) ($b['handle'] ?? '')];
        });

        return $rows;
    }
}
```

- [ ] **Step 5: Run to verify they pass**

Run: `composer test`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Support/ConfigBuilder.php src/Support/LocaleResolver.php tests/Support/ConfigBuilderTest.php tests/Support/LocaleResolverTest.php
git commit -m "feat: add pure config builder with per-field locale fallback

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Catalog (database snapshot, cache, locale and policy lookups)

**Files:**
- Create: `src/Support/Catalog.php`

**Interfaces:**
- Consumes: entities (Tasks 3 and 4), `ConfigBuilder::build`, `LocaleResolver::resolve` (Task 5), `EssentialCookies::names` (Task 2), `Output::json`, `Output::safeUrl`.
- Produces `CookieManagerKit\Support\Catalog` (all static):
  `snapshot(): array` (snapshot shape from Task 5 plus a `'hash'` key), `hash(): string`, `handles(): list<string>`, `policyVersion(): int`, `lifetimeDays(): int`, `defaultLocale(): string`, `siteLocales(): list<string>`, `resolveLocale(string $requested): string`, `config(string $requested): array` (config shape, built at call time so essential cookie names are never cached), `flush(): void` (clears the snapshot cache and the full-page cache; call after **every** admin save), `const DEFAULTS` (built-in English text).

This class reads the database and Concrete's cache, so it has no unit test; its pure parts are covered in Task 5, and it is exercised end to end in Task 18. Static analysis is the gate here.

- [ ] **Step 1: Implement `Catalog`**

Create `src/Support/Catalog.php`:

```php
<?php

namespace CookieManagerKit\Support;

use Concrete\Core\Page\Page;
use Concrete\Core\Cache\Page\PageCache;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{BannerContent, Category, Settings};

/**
 * Reads the settings, categories and banner text once, turns them into plain data and keeps that in Concrete's
 * "expensive" cache. Every admin save calls flush(), which also clears the full-page cache because pages embed the
 * snapshot hash. The essential cookie names are NOT part of the snapshot: config() resolves them on every call.
 */
final class Catalog
{
    private const KEY = 'cookie_manager_kit/snapshot/v1';
    private const LIFETIME = 43200;

    /** Built-in English text, used when an admin has not provided a value. */
    public const DEFAULTS = [
        'title' => 'We value your privacy',
        'body' => 'We use cookies to make this site work and, with your permission, to understand how it is used and to show relevant content. You can change your choice at any time.',
        'acceptAll' => 'Accept all',
        'rejectAll' => 'Reject all',
        'customise' => 'Customise',
        'save' => 'Save my choices',
        'widget' => 'Cookie settings',
        'policyLinkText' => 'Cookie policy',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $cache = app('cache/expensive');
        $item = $cache->getItem(self::KEY);

        if (!$item->isMiss() && is_array($item->get())) {
            return self::$memo = $item->get();
        }

        $data = self::build();
        $item->set($data);
        $item->expiresAfter(self::LIFETIME);
        $cache->save($item);

        return self::$memo = $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function build(): array
    {
        $em = app(EntityManagerInterface::class);
        $settings = Settings::get();

        $banner = [];
        foreach ($em->getRepository(BannerContent::class)->findAll() as $row) {
            $banner[$row->getLocale()] = [
                'title' => $row->getTitle(),
                'body' => $row->getBody(),
                'acceptAll' => $row->getAcceptAllLabel(),
                'rejectAll' => $row->getRejectAllLabel(),
                'customise' => $row->getCustomiseLabel(),
                'save' => $row->getSaveLabel(),
                'widget' => $row->getWidgetLabel(),
                'policyLinkText' => $row->getPolicyLinkText(),
                'policyUrl' => self::pageUrl($row->getPolicyPageId()),
            ];
        }

        $categories = [];
        foreach ($em->getRepository(Category::class)->findBy([], ['sortOrder' => 'ASC', 'handle' => 'ASC']) as $category) {
            $content = [];
            foreach ($category->getContents() as $row) {
                $content[$row->getLocale()] = ['title' => $row->getTitle(), 'description' => $row->getDescription()];
            }

            $services = [];
            foreach ($category->getServices() as $service) {
                if (!$service->isActive()) {
                    continue;
                }

                $descriptions = [];
                foreach ($service->getContents() as $row) {
                    $descriptions[$row->getLocale()] = $row->getDescription();
                }

                $services[] = ['name' => $service->getName(), 'vendor' => $service->getVendor(), 'content' => $descriptions];
            }

            $categories[] = [
                'handle' => $category->getHandle(),
                'necessary' => $category->isNecessary(),
                'sort' => $category->getSortOrder(),
                'content' => $content,
                'services' => $services,
            ];
        }

        $data = [
            'settings' => [
                'policyVersion' => $settings->getPolicyVersion(),
                'lifetimeDays' => $settings->getLifetimeDays(),
                'widget' => $settings->isWidgetEnabled(),
                'position' => $settings->getPosition(),
                'layout' => $settings->getLayout(),
                'font' => $settings->getFont(),
                'radius' => $settings->getRadius(),
                'shadow' => $settings->hasShadow(),
                'tokens' => $settings->getTokens(),
            ],
            'banner' => $banner,
            'categories' => $categories,
            'defaults' => self::DEFAULTS,
        ];

        $data['hash'] = substr(sha1(Output::json($data)), 0, 12);

        return $data;
    }

    private static function pageUrl(?int $cid): string
    {
        if (!$cid) {
            return '';
        }

        $page = Page::getByID($cid);

        return $page && !$page->isError() ? Output::safeUrl((string) $page->getCollectionLink()) : '';
    }

    public static function hash(): string
    {
        return (string) self::snapshot()['hash'];
    }

    /**
     * @return list<string>
     */
    public static function handles(): array
    {
        return array_values(array_map(static fn(array $c): string => $c['handle'], self::snapshot()['categories']));
    }

    public static function policyVersion(): int
    {
        return (int) self::snapshot()['settings']['policyVersion'];
    }

    public static function lifetimeDays(): int
    {
        return (int) self::snapshot()['settings']['lifetimeDays'];
    }

    public static function defaultLocale(): string
    {
        $locale = app('site')->getSite()->getDefaultLocale();

        return $locale ? $locale->getLocale() : 'en_US';
    }

    /**
     * @return list<string>
     */
    public static function siteLocales(): array
    {
        $locales = [];

        foreach (app('site')->getSite()->getLocales() as $locale) {
            $locales[] = $locale->getLocale();
        }

        return $locales;
    }

    public static function resolveLocale(string $requested): string
    {
        return LocaleResolver::resolve($requested, self::siteLocales(), self::defaultLocale());
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(string $requestedLocale): array
    {
        return ConfigBuilder::build(
            self::snapshot(),
            self::resolveLocale($requestedLocale),
            self::defaultLocale(),
            EssentialCookies::names(),
        );
    }

    /**
     * Call after every admin save.
     */
    public static function flush(): void
    {
        self::$memo = null;
        app('cache/expensive')->getItem(self::KEY)->clear();

        $pageCache = PageCache::getLibrary();

        if ($pageCache) {
            $pageCache->flush();
        }
    }
}
```

- [ ] **Step 2: Run static analysis and the suite**

Run: `composer typecheck && composer test`
Expected: PHPStan reports nothing new that points at `Catalog.php` beyond Concrete facade typing noise already present in sibling packages (investigate anything about undefined methods on entities); tests PASS, including `SourceLayoutTest`.

- [ ] **Step 3: Commit**

```bash
git add src/Support/Catalog.php
git commit -m "feat: add cached catalog snapshot with request-time essential cookies

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Consent endpoint (`POST /cookie-manager/consent`)

**Files:**
- Create: `src/Support/ConsentRequest.php`, `src/Support/RequestGuard.php`, `src/Support/RateLimit.php`, `src/Consent/ConsentChoice.php`, `src/Consent/ConsentRecorder.php`, `src/Consent/NullConsentRecorder.php`, `src/Ajax/Consent.php`
- Test: `tests/Support/ConsentRequestTest.php`, `tests/Support/RequestGuardTest.php`, `tests/Support/RateLimitTest.php`

**Interfaces:**
- Consumes: `Text::isHandle` (Task 1), `Output::json` (Task 1), `Catalog::handles()`, `Catalog::policyVersion()` (Task 6).
- Produces:
  - `ConsentRequest::parse(?string $contentType, string $body, array $knownHandles): ?array{v:int, c:list<string>}`; `MAX_BYTES = 2048`, `MAX_CATEGORIES = 20`. Always includes `necessary` in `c`. Returns `null` for any deviation.
  - `RequestGuard::isSameOrigin(?string $origin, ?string $referer, string $host): bool`.
  - `RateLimit::step(?array $state, int $now, int $limit, int $window): array{bool, array}` (pure) and `RateLimit::allow(string $key, int $limit, int $windowSeconds = 60): bool` (cache-backed).
  - `ConsentChoice` (`public readonly int $policyVersion`, `public readonly array $categories`), interface `ConsentRecorder::record(ConsentChoice): void`, `NullConsentRecorder`.
  - `CookieManagerKit\Ajax\Consent::record(): Response`. Status/body contract: `200 {"ok":true}`; `400|403|405|429|500 {"error":"invalid"}`; always `Content-Type: application/json; charset=utf-8`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Support/ConsentRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\ConsentRequest;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsentRequestTest extends TestCase
{
    private const KNOWN = ['necessary', 'preferences', 'analytics', 'marketing'];

    public function testAcceptsAValidBodyAndAlwaysIncludesNecessary(): void
    {
        $parsed = ConsentRequest::parse('application/json', '{"v":2,"c":["analytics"]}', self::KNOWN);

        $this->assertSame(['v' => 2, 'c' => ['necessary', 'analytics']], $parsed);
    }

    public function testDeduplicatesAndKeepsNecessaryOnce(): void
    {
        $parsed = ConsentRequest::parse('application/json', '{"v":1,"c":["necessary","analytics","analytics"]}', self::KNOWN);

        $this->assertSame(['necessary', 'analytics'], $parsed['c']);
    }

    public function testContentTypeMayCarryACharset(): void
    {
        $this->assertNotNull(ConsentRequest::parse('application/json; charset=utf-8', '{"v":1,"c":[]}', self::KNOWN));
        $this->assertNotNull(ConsentRequest::parse('Application/JSON', '{"v":1,"c":[]}', self::KNOWN));
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'no content type' => [null, '{"v":1,"c":[]}'],
            'form content type' => ['application/x-www-form-urlencoded', '{"v":1,"c":[]}'],
            'text plain (simple CORS request)' => ['text/plain', '{"v":1,"c":[]}'],
            'json lookalike' => ['application/jsonp', '{"v":1,"c":[]}'],
            'empty body' => ['application/json', ''],
            'not json' => ['application/json', 'v=1&c=analytics'],
            'json scalar' => ['application/json', '5'],
            'json list' => ['application/json', '[1,["analytics"]]'],
            'oversize' => ['application/json', '{"v":1,"c":[],"pad":"' . "\x41" . '"}' . str_repeat(' ', 3000)],
            'unknown key' => ['application/json', '{"v":1,"c":[],"extra":true}'],
            'missing v' => ['application/json', '{"c":[]}'],
            'missing c' => ['application/json', '{"v":1}'],
            'v as string' => ['application/json', '{"v":"1","c":[]}'],
            'v zero' => ['application/json', '{"v":0,"c":[]}'],
            'v negative' => ['application/json', '{"v":-1,"c":[]}'],
            'v float' => ['application/json', '{"v":1.5,"c":[]}'],
            'v huge' => ['application/json', '{"v":99999999,"c":[]}'],
            'c as object' => ['application/json', '{"v":1,"c":{"a":"analytics"}}'],
            'c as string' => ['application/json', '{"v":1,"c":"analytics"}'],
            'unknown handle' => ['application/json', '{"v":1,"c":["analytics","bogus"]}'],
            'script handle' => ['application/json', '{"v":1,"c":["<script>alert(1)</script>"]}'],
            'handle with newline' => ['application/json', '{"v":1,"c":["analytics\n"]}'],
            'non-string handle' => ['application/json', '{"v":1,"c":[1]}'],
            'nested handle' => ['application/json', '{"v":1,"c":[["analytics"]]}'],
            'too many' => ['application/json', '{"v":1,"c":[' . implode(',', array_fill(0, 21, '"analytics"')) . ']}'],
            'too deep' => ['application/json', '{"v":1,"c":[[[[[]]]]]}'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function testRejectsEverythingUnexpected(?string $contentType, string $body): void
    {
        $this->assertNull(ConsentRequest::parse($contentType, $body, self::KNOWN));
    }
}
```

Create `tests/Support/RequestGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\RequestGuard;

final class RequestGuardTest extends TestCase
{
    public function testSameOriginIsAccepted(): void
    {
        $this->assertTrue(RequestGuard::isSameOrigin('https://example.com', null, 'example.com'));
        $this->assertTrue(RequestGuard::isSameOrigin('http://localhost:8080', null, 'localhost:8080'));
        $this->assertTrue(RequestGuard::isSameOrigin('https://EXAMPLE.com', null, 'example.com'));
    }

    public function testRefererIsUsedWhenThereIsNoOrigin(): void
    {
        $this->assertTrue(RequestGuard::isSameOrigin(null, 'https://example.com/some/page?x=1', 'example.com'));
        $this->assertFalse(RequestGuard::isSameOrigin(null, 'https://evil.example/page', 'example.com'));
    }

    public function testOriginWinsOverReferer(): void
    {
        $this->assertFalse(RequestGuard::isSameOrigin('https://evil.example', 'https://example.com/', 'example.com'));
    }

    public function testRejectsForeignMissingAndMalformedOrigins(): void
    {
        foreach (['https://evil.example', 'https://example.com.evil.example', 'null', 'garbage', '', 'https://example.com:444'] as $origin) {
            $this->assertFalse(RequestGuard::isSameOrigin($origin, null, 'example.com'), $origin);
        }

        $this->assertFalse(RequestGuard::isSameOrigin(null, null, 'example.com'));
    }
}
```

Create `tests/Support/RateLimitTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\RateLimit;

final class RateLimitTest extends TestCase
{
    public function testAllowsUpToTheLimitThenBlocksWithinTheWindow(): void
    {
        $state = null;

        for ($i = 0; $i < 3; $i++) {
            [$allowed, $state] = RateLimit::step($state, 1000, 3, 60);
            $this->assertTrue($allowed);
        }

        [$allowed, $state] = RateLimit::step($state, 1010, 3, 60);
        $this->assertFalse($allowed);
        $this->assertSame(3, $state['used']);
    }

    public function testStartsANewWindowAfterItExpires(): void
    {
        $state = ['start' => 1000, 'used' => 3];

        [$allowed, $state] = RateLimit::step($state, 1060, 3, 60);

        $this->assertTrue($allowed);
        $this->assertSame(['start' => 1060, 'used' => 1], $state);
    }

    public function testASteadyTrickleDoesNotExtendTheWindow(): void
    {
        $state = null;
        [, $state] = RateLimit::step($state, 1000, 100, 60);

        foreach ([1020, 1040, 1059] as $now) {
            [, $state] = RateLimit::step($state, $now, 100, 60);
        }

        $this->assertSame(1000, $state['start']);
    }

    public function testGarbageStateIsReplaced(): void
    {
        [$allowed, $state] = RateLimit::step(['start' => 'x', 'used' => 'y'], 500, 3, 60);

        $this->assertTrue($allowed);
        $this->assertSame(['start' => 500, 'used' => 1], $state);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpunit tests/Support/ConsentRequestTest.php tests/Support/RequestGuardTest.php tests/Support/RateLimitTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement the pure classes**

Create `src/Support/ConsentRequest.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Reads and vets the body a visitor's browser posts when they make a choice. Pure: no Concrete, no database.
 * Everything arrives from the public internet, so nothing is trusted and nothing is "cleaned and kept": a body
 * that deviates in any way is rejected whole.
 */
final class ConsentRequest
{
    public const MAX_BYTES = 2048;
    public const MAX_CATEGORIES = 20;
    private const MAX_POLICY_VERSION = 1000000;

    /**
     * @param list<string> $knownHandles
     *
     * @return array{v: int, c: list<string>}|null
     */
    public static function parse(?string $contentType, string $body, array $knownHandles): ?array
    {
        if ($contentType === null || preg_match('#^application/json(?:\s*;.*)?\z#i', trim($contentType)) !== 1) {
            return null;
        }

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || array_is_list($data)) {
            return null;
        }

        $keys = array_keys($data);
        sort($keys);

        if ($keys !== ['c', 'v']) {
            return null;
        }

        $version = $data['v'];

        if (!is_int($version) || $version < 1 || $version > self::MAX_POLICY_VERSION) {
            return null;
        }

        $categories = $data['c'];

        if (!is_array($categories) || !array_is_list($categories) || count($categories) > self::MAX_CATEGORIES) {
            return null;
        }

        $accepted = ['necessary'];

        foreach ($categories as $handle) {
            if (!Text::isHandle($handle) || !in_array($handle, $knownHandles, true)) {
                return null;
            }

            if (!in_array($handle, $accepted, true)) {
                $accepted[] = $handle;
            }
        }

        return ['v' => $version, 'c' => $accepted];
    }
}
```

Create `src/Support/RequestGuard.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Same-origin check for the public POST endpoint. A CSRF token cannot be used (pages are cached and anonymous),
 * so the browser-supplied Origin (or Referer when there is no Origin) must match the host being served.
 */
final class RequestGuard
{
    public static function isSameOrigin(?string $origin, ?string $referer, string $host): bool
    {
        foreach ([$origin, $referer] as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $parts = parse_url($value);

            if (!is_array($parts) || !isset($parts['host'])) {
                return false;
            }

            $candidate = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

            return strcasecmp($candidate, $host) === 0;
        }

        return false;
    }
}
```

Create `src/Support/RateLimit.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Fixed-window counter in Concrete's expensive cache. The window start lives in the value, not in the cache
 * expiry: an expiry that moves on every save would let a steady trickle from one address accumulate forever.
 */
final class RateLimit
{
    /**
     * @param array<string, mixed>|null $state
     *
     * @return array{bool, array{start: int, used: int}}
     */
    public static function step(?array $state, int $now, int $limit, int $window): array
    {
        $start = is_int($state['start'] ?? null) ? $state['start'] : null;
        $used = is_int($state['used'] ?? null) ? $state['used'] : null;

        if ($start === null || $used === null || $now - $start >= $window) {
            $start = $now;
            $used = 0;
        }

        if ($used + 1 > $limit) {
            return [false, ['start' => $start, 'used' => $used]];
        }

        return [true, ['start' => $start, 'used' => $used + 1]];
    }

    public static function allow(string $key, int $limit, int $windowSeconds = 60): bool
    {
        $cache = app('cache/expensive');
        $item = $cache->getItem('cookie_manager_kit/rate/' . $key);
        $state = $item->isMiss() ? null : $item->get();

        [$allowed, $next] = self::step(is_array($state) ? $state : null, time(), $limit, $windowSeconds);

        $item->set($next);
        $item->expiresAfter($windowSeconds * 2);
        $cache->save($item);

        return $allowed;
    }
}
```

Create `src/Consent/ConsentChoice.php`:

```php
<?php

namespace CookieManagerKit\Consent;

/**
 * A validated consent decision, handed to a ConsentRecorder.
 */
final class ConsentChoice
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public readonly int $policyVersion,
        public readonly array $categories,
    ) {}
}
```

Create `src/Consent/ConsentRecorder.php`:

```php
<?php

namespace CookieManagerKit\Consent;

/**
 * Extension point: phase 1 binds a no-op; the audit log (phase 3) binds an implementation that stores the choice.
 */
interface ConsentRecorder
{
    public function record(ConsentChoice $choice): void;
}
```

Create `src/Consent/NullConsentRecorder.php`:

```php
<?php

namespace CookieManagerKit\Consent;

final class NullConsentRecorder implements ConsentRecorder
{
    public function record(ConsentChoice $choice): void {}
}
```

- [ ] **Step 4: Implement the Ajax endpoint**

Create `src/Ajax/Consent.php`:

```php
<?php

namespace CookieManagerKit\Ajax;

use Concrete\Core\Http\Request;
use CookieManagerKit\Support\{Catalog, ConsentRequest, Output, RateLimit, RequestGuard};
use Symfony\Component\HttpFoundation\Response;
use CookieManagerKit\Consent\{ConsentChoice, ConsentRecorder};

/**
 * Receives the choice a visitor made. Public and cacheable pages cannot carry a CSRF token, so it is defensive
 * instead: same-origin only, JSON only, a strict schema with an allowlist of category handles, a size cap and a
 * per-address rate limit. It answers with a fixed body and never reflects anything from the request.
 */
class Consent
{
    private const PER_MINUTE = 30;

    private function status(Request $request): int
    {
        if (!$request->isMethod('POST')) {
            return 405;
        }

        if (!RequestGuard::isSameOrigin($request->headers->get('Origin'), $request->headers->get('Referer'), $request->getHttpHost())) {
            return 403;
        }

        if (!RateLimit::allow('consent/' . sha1((string) $request->getClientIp()), self::PER_MINUTE)) {
            return 429;
        }

        $parsed = ConsentRequest::parse($request->headers->get('Content-Type'), (string) $request->getContent(), Catalog::handles());

        if ($parsed === null || $parsed['v'] !== Catalog::policyVersion()) {
            return 400;
        }

        app(ConsentRecorder::class)->record(new ConsentChoice($parsed['v'], $parsed['c']));

        return 200;
    }

    public function record(): Response
    {
        try {
            $status = $this->status(app(Request::class));
        } catch (\Throwable) {
            $status = 500;
        }

        return new Response(
            Output::json($status === 200 ? ['ok' => true] : ['error' => 'invalid']),
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
```

- [ ] **Step 5: Run to verify everything passes**

Run: `composer test`
Expected: all PASS (`SourceLayoutTest` also passes: every new file declares a class or interface).

- [ ] **Step 6: Commit**

```bash
git add src/Support/ConsentRequest.php src/Support/RequestGuard.php src/Support/RateLimit.php src/Consent src/Ajax/Consent.php tests/Support
git commit -m "feat: add strict consent endpoint with origin check and rate limit

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Config endpoint (`GET /cookie-manager/config/{locale}.json`)

**Files:**
- Create: `src/Support/ConfigRequest.php`, `src/Ajax/Config.php`
- Test: `tests/Support/ConfigRequestTest.php`

**Interfaces:**
- Consumes: `Catalog::config()` (Task 6), `Output::json` (Task 1).
- Produces:
  - `ConfigRequest::localeFromPath(string $path): ?string` (extracts the raw segment only; resolving it to a real locale is `Catalog::resolveLocale`'s job) and `ConfigRequest::matchesEtag(?string $header, string $etag): bool`.
  - `CookieManagerKit\Ajax\Config::serve(): Response`. Headers: `Content-Type: application/json; charset=utf-8`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline; filename="config.json"`, `ETag: "<version>"`, `Cache-Control: public, max-age=300, must-revalidate`; `304` on matching `If-None-Match`; `405` for non GET/HEAD; `500 {"error":"unavailable"}` with `no-store` on failure.

- [ ] **Step 1: Write the failing test**

Create `tests/Support/ConfigRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\ConfigRequest;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConfigRequestTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function pathProvider(): array
    {
        return [
            'plain' => ['/cookie-manager/config/en_GB.json', 'en_GB'],
            'with index.php' => ['/index.php/cookie-manager/config/cy_GB.json', 'cy_GB'],
            'subdirectory install' => ['/site/cookie-manager/config/en.json', 'en'],
            'script payload' => ['/cookie-manager/config/<script>.json', null],
            'traversal' => ['/cookie-manager/config/../../etc/passwd.json', null],
            'encoded traversal' => ['/cookie-manager/config/..%2F..%2Fx.json', null],
            'too long' => ['/cookie-manager/config/' . str_repeat('a', 33) . '.json', null],
            'wrong extension' => ['/cookie-manager/config/en_GB.php', null],
            'no extension' => ['/cookie-manager/config/en_GB', null],
            'trailing newline' => ["/cookie-manager/config/en_GB.json\n", null],
            'empty segment' => ['/cookie-manager/config/.json', null],
            'other route' => ['/something/else', null],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testLocaleFromPath(string $path, ?string $expected): void
    {
        $this->assertSame($expected, ConfigRequest::localeFromPath($path));
    }

    public function testMatchesEtag(): void
    {
        $this->assertTrue(ConfigRequest::matchesEtag('"abc"', '"abc"'));
        $this->assertTrue(ConfigRequest::matchesEtag('W/"abc"', '"abc"'));
        $this->assertTrue(ConfigRequest::matchesEtag('"zzz", "abc"', '"abc"'));
        $this->assertFalse(ConfigRequest::matchesEtag('"abd"', '"abc"'));
        $this->assertFalse(ConfigRequest::matchesEtag(null, '"abc"'));
        $this->assertFalse(ConfigRequest::matchesEtag('', '"abc"'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpunit tests/Support/ConfigRequestTest.php`
Expected: FAIL, class `ConfigRequest` not found.

- [ ] **Step 3: Implement**

Create `src/Support/ConfigRequest.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Reads what the config endpoint needs from the request. The locale segment is only extracted here; it is never
 * trusted, never echoed, and is turned into a real site locale by LocaleResolver.
 */
final class ConfigRequest
{
    public static function localeFromPath(string $path): ?string
    {
        return preg_match('#/cookie-manager/config/([A-Za-z0-9_\-]{1,32})\.json\z#', $path, $match) === 1 ? $match[1] : null;
    }

    public static function matchesEtag(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
```

Create `src/Ajax/Config.php`:

```php
<?php

namespace CookieManagerKit\Ajax;

use Concrete\Core\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use CookieManagerKit\Support\{Catalog, ConfigRequest, Output};

/**
 * Serves the per-locale config the banner renders from. Public, read-only and identical for every visitor, so it
 * is cacheable. The requested locale is only ever matched against the site's own locales.
 */
class Config
{
    public function serve(): Response
    {
        $request = app(Request::class);

        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return self::response('{"error":"invalid"}', 405, ['Cache-Control' => 'no-store']);
        }

        try {
            $config = Catalog::config(ConfigRequest::localeFromPath($request->getPathInfo()) ?? '');
            $etag = '"' . $config['version'] . '"';
            $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300, must-revalidate'];

            if (ConfigRequest::matchesEtag($request->headers->get('If-None-Match'), $etag)) {
                return self::response('', 304, $headers);
            }

            return self::response(Output::json($config), 200, $headers);
        } catch (\Throwable) {
            return self::response('{"error":"unavailable"}', 500, ['Cache-Control' => 'no-store']);
        }
    }

    /**
     * @param array<string, string> $extra
     */
    private static function response(string $body, int $status, array $extra = []): Response
    {
        return new Response($body, $status, $extra + [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="config.json"',
        ]);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `composer test`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/ConfigRequest.php src/Ajax/Config.php tests/Support/ConfigRequestTest.php
git commit -m "feat: add cacheable per-locale config endpoint

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Inert script helpers (tracking code and author helper)

**Files:**
- Create: `src/Support/TrackingCode.php`, `src/Support/Inert.php`
- Test: `tests/Support/TrackingCodeTest.php`, `tests/Support/InertTest.php`

**Interfaces:**
- Consumes: `Text::isHandle`, `Output::e`, `Output::safeUrl` (Task 1).
- Produces:
  - `TrackingCode::makeInert(string $code, string $category): string`: removes `<noscript>…</noscript>` blocks (visitors without JavaScript cannot consent), and rewrites each `<script …>` open tag to `type="text/plain" data-cmk-category="<category>"`, keeping an original non-default `type` in `data-cmk-type`. Returns `''` for an invalid category.
  - `Inert::script(string $category, string $src = '', string $inline = '', string $type = ''): string`: builds one inert script tag for template/block authors; returns `''` for an invalid category, unsafe `src`, or nothing to run.

- [ ] **Step 1: Write the failing tests**

Create `tests/Support/TrackingCodeTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\TrackingCode;

final class TrackingCodeTest extends TestCase
{
    public function testExternalAndInlineScriptsBecomeInert(): void
    {
        $code = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
            . '<script>window.dataLayer=window.dataLayer||[];</script>';

        $inert = TrackingCode::makeInert($code, 'analytics');

        $this->assertSame(2, substr_count($inert, 'type="text/plain" data-cmk-category="analytics"'));
        $this->assertStringContainsString('src="https://www.googletagmanager.com/gtag/js?id=G-1"', $inert);
        $this->assertStringContainsString('window.dataLayer=window.dataLayer||[];', $inert);
        $this->assertStringNotContainsString('G-1"></script>', $inert); // the original tag, without the inert attributes
    }

    public function testAnExistingTypeIsReplacedAndRemembered(): void
    {
        $inert = TrackingCode::makeInert('<script type="module" src="/x.js"></script>', 'analytics');

        $this->assertSame(1, substr_count($inert, ' type=')); // data-cmk-type is preceded by "-", not a space
        $this->assertStringContainsString('type="text/plain"', $inert);
        $this->assertStringContainsString('data-cmk-type="module"', $inert);
    }

    public function testPlainJavascriptTypesAreNotRemembered(): void
    {
        $inert = TrackingCode::makeInert('<script type="text/javascript">x()</script>', 'analytics');

        $this->assertStringNotContainsString('data-cmk-type', $inert);
    }

    public function testHostileTypeValuesAreNotCarriedOver(): void
    {
        $inert = TrackingCode::makeInert('<script type="x&quot; onerror=&quot;alert(1)">x()</script>', 'analytics');

        $this->assertStringNotContainsString('onerror', $inert);
        $this->assertStringNotContainsString('data-cmk-type', $inert);
    }

    public function testExistingCmkAttributesCannotOverrideTheCategory(): void
    {
        $inert = TrackingCode::makeInert('<script data-cmk-category="necessary" src="/x.js"></script>', 'marketing');

        $this->assertSame(1, substr_count($inert, 'data-cmk-category='));
        $this->assertStringContainsString('data-cmk-category="marketing"', $inert);
        $this->assertStringNotContainsString('necessary', $inert);
    }

    public function testNoscriptBlocksAreRemoved(): void
    {
        $code = '<script>a()</script><noscript><img src="https://px.example/p.gif" height="1" width="1"></noscript>';

        $inert = TrackingCode::makeInert($code, 'marketing');

        $this->assertStringNotContainsString('noscript', $inert);
        $this->assertStringNotContainsString('px.example', $inert);
    }

    public function testMultilineNoscriptAndUppercaseTagsAreHandled(): void
    {
        $code = "<SCRIPT>a()</SCRIPT>\n<NOSCRIPT>\n<img src=\"https://px.example/p.gif\">\n</NOSCRIPT>";

        $inert = TrackingCode::makeInert($code, 'marketing');

        $this->assertStringNotContainsStringIgnoringCase('noscript', $inert);
        $this->assertStringContainsString('data-cmk-category="marketing"', $inert);
    }

    public function testAnInvalidCategoryDropsTheCodeEntirely(): void
    {
        foreach (['', '<script>', 'Analytics', "analytics\n", 'a b'] as $category) {
            $this->assertSame('', TrackingCode::makeInert('<script>a()</script>', $category), $category);
        }
    }

    public function testCodeWithoutScriptsPassesThrough(): void
    {
        $this->assertSame('<meta name="x" content="y">', TrackingCode::makeInert('<meta name="x" content="y">', 'analytics'));
        $this->assertSame('', TrackingCode::makeInert('', 'analytics'));
    }
}
```

Create `tests/Support/InertTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use CookieManagerKit\Support\Inert;
use PHPUnit\Framework\TestCase;

final class InertTest extends TestCase
{
    public function testBuildsAnInertExternalScript(): void
    {
        $this->assertSame(
            '<script type="text/plain" data-cmk-category="marketing" src="https://px.example/a.js?x=1&amp;y=2"></script>',
            Inert::script('marketing', 'https://px.example/a.js?x=1&y=2'),
        );
    }

    public function testBuildsAnInertInlineScript(): void
    {
        $this->assertSame(
            '<script type="text/plain" data-cmk-category="analytics">track();</script>',
            Inert::script('analytics', '', 'track();'),
        );
    }

    public function testInlineCodeCannotCloseTheScriptElement(): void
    {
        $html = Inert::script('analytics', '', 'var s="</script><img src=x onerror=alert(1)>";');

        $this->assertSame(1, substr_count(strtolower($html), '</script'));
    }

    public function testAModuleTypeIsRemembered(): void
    {
        $this->assertStringContainsString('data-cmk-type="module"', Inert::script('analytics', '/a.js', '', 'module'));
        $this->assertStringNotContainsString('data-cmk-type', Inert::script('analytics', '/a.js', '', 'x" onerror="y'));
    }

    public function testRefusesBadInput(): void
    {
        $this->assertSame('', Inert::script('Bad Category', '/a.js'));
        $this->assertSame('', Inert::script('analytics', 'javascript:alert(1)'));
        $this->assertSame('', Inert::script('analytics', '//evil.example/a.js'));
        $this->assertSame('', Inert::script('analytics', '', ''));
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpunit tests/Support/TrackingCodeTest.php tests/Support/InertTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

Create `src/Support/TrackingCode.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Makes a pasted tracking snippet (Concrete's SEO tracking code) inert until its category is consented.
 * <noscript> blocks are removed: a visitor without JavaScript cannot consent, so their pixel must not load.
 */
final class TrackingCode
{
    private const ATTRIBUTE_VALUE = '(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)';

    public static function makeInert(string $code, string $category): string
    {
        if (!Text::isHandle($category)) {
            return '';
        }

        $code = preg_replace('#<noscript\b[^>]*>.*?</noscript\s*>#is', '', $code) ?? '';

        return preg_replace_callback(
            '#<script\b([^>]*)>#i',
            static function (array $match) use ($category): string {
                $attributes = $match[1];
                $type = '';

                $attributes = preg_replace_callback(
                    '/\s+type\s*=\s*(' . self::ATTRIBUTE_VALUE . ')/i',
                    static function (array $found) use (&$type): string {
                        $type = trim($found[1], "\"'");

                        return '';
                    },
                    $attributes,
                ) ?? '';

                $attributes = preg_replace('/\s+data-cmk-[a-z-]+(?:\s*=\s*' . self::ATTRIBUTE_VALUE . ')?/i', '', $attributes) ?? '';

                $remembered = '';

                if (preg_match('#^[a-z0-9/+.\-]{1,64}\z#i', $type) === 1 && !in_array(strtolower($type), ['text/javascript', 'application/javascript'], true)) {
                    $remembered = ' data-cmk-type="' . $type . '"';
                }

                return '<script' . $attributes . ' type="text/plain" data-cmk-category="' . $category . '"' . $remembered . '>';
            },
            $code,
        ) ?? '';
    }
}
```

Create `src/Support/Inert.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * For template and block authors: one inert script tag that the runtime activates once its category is consented.
 */
final class Inert
{
    public static function script(string $category, string $src = '', string $inline = '', string $type = ''): string
    {
        if (!Text::isHandle($category)) {
            return '';
        }

        $src = $src === '' ? '' : Output::safeUrl($src);

        if ($src === '' && $inline === '') {
            return '';
        }

        $attributes = ' type="text/plain" data-cmk-category="' . $category . '"';

        if ($src !== '') {
            $attributes .= ' src="' . Output::e($src) . '"';
        }

        if (preg_match('#^[a-z0-9/+.\-]{1,64}\z#i', $type) === 1 && !in_array(strtolower($type), ['text/javascript', 'application/javascript'], true)) {
            $attributes .= ' data-cmk-type="' . $type . '"';
        }

        // "</script" inside inline code would end the element early.
        $body = (string) preg_replace('#</(script)#i', '<\\/$1', $inline);

        return '<script' . $attributes . '>' . $body . '</script>';
    }
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `composer test`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/TrackingCode.php src/Support/Inert.php tests/Support/TrackingCodeTest.php tests/Support/InertTest.php
git commit -m "feat: add helpers that make tracking snippets and author scripts inert

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Browser loader (consent cookie and script activation)

**Files:**
- Create: `js/cmk-loader.js`
- Test: `tests/js/helpers.js`, `tests/js/loader.test.js`

**Interfaces:**
- Consumes: `window.cmkBoot = { v: number, cats: string[], days: number }` (set by `LoaderScript::inline`, Task 12).
- Produces `window.cmk`:
  `version: number`, `known: string[]`, `get(): {v, c, t} | null` (a copy), `has(handle): boolean`, `save(categories: string[]): string[]` (writes the cookie, activates scripts, emits `consent:changed`, returns the saved list with `necessary` first), `activate(): void` (rescan and activate), `on(event: string, fn): void`. Event `consent:changed` payload: `{ categories: string[], previous: string[] | null }`.

- [ ] **Step 1: Create the test helper**

Create `tests/js/helpers.js`:

```js
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** The package scripts are plain browser scripts, so run them in the jsdom window rather than import them. */
export function loadScript(name) {
	const source = readFileSync(resolve(__dirname, '../../js', name), 'utf8');
	(0, eval)(source);
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/js/loader.test.js`:

```js
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { loadScript } from './helpers.js';

const BOOT = { v: 2, cats: ['necessary', 'preferences', 'analytics', 'marketing'], days: 180 };

function start(boot = BOOT) {
	delete window.cmk;
	window.cmkBoot = boot;
	loadScript('cmk-loader.js');
}

function setConsent(value) {
	const raw = typeof value === 'string' ? value : encodeURIComponent(JSON.stringify(value));
	document.cookie = 'cmk_consent=' + raw + '; Path=/';
}

function valid(categories, overrides = {}) {
	return { v: 2, c: categories, t: 1700000000, ...overrides };
}

/** Scripts that are not inert: what the browser would actually run. */
function live() {
	return [...document.querySelectorAll('script:not([type="text/plain"])')];
}

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	// Remove any cookie override first: a test may have made writes throw.
	if (Object.getOwnPropertyDescriptor(document, 'cookie')) delete document.cookie;
	if (Object.getOwnPropertyDescriptor(document, 'readyState')) delete document.readyState;
	document.cookie = 'cmk_consent=; Path=/; Max-Age=0';
	delete window.cmk;
	delete window.cmkBoot;
});

describe('without consent', () => {
	it('reports no choice and only the necessary category', () => {
		start();

		expect(window.cmk.get()).toBeNull();
		expect(window.cmk.has('necessary')).toBe(true);
		expect(window.cmk.has('analytics')).toBe(false);
	});

	it('leaves non-necessary scripts inert', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">window.__ran = 1;</script>';
		start();

		expect(live()).toHaveLength(0);
		expect(document.querySelector('script[data-cmk-done]')).toBeNull();
	});

	it('still runs scripts marked necessary', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="necessary">window.__n = 1;</script>';
		start();

		expect(live()).toHaveLength(1);
		expect(live()[0].text).toBe('window.__n = 1;');
	});

	it('never activates a script with a missing, unknown or hostile category', () => {
		document.body.innerHTML = [
			'<script type="text/plain">a()</script>',
			'<script type="text/plain" data-cmk-category="">b()</script>',
			'<script type="text/plain" data-cmk-category="bogus">c()</script>',
			'<script type="text/plain" data-cmk-category="&lt;script&gt;">d()</script>',
		].join('');
		setConsent(valid(['necessary', 'analytics', 'marketing', 'preferences']));
		start();

		expect(live()).toHaveLength(0);
	});
});

describe('with a valid consent cookie', () => {
	it('activates only the consented categories', () => {
		document.body.innerHTML =
			'<script type="text/plain" data-cmk-category="analytics">a()</script>' +
			'<script type="text/plain" data-cmk-category="marketing">m()</script>';
		setConsent(valid(['necessary', 'analytics']));
		start();

		expect(live().map((s) => s.text)).toEqual(['a()']);
		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
		expect(window.cmk.has('analytics')).toBe(true);
		expect(window.cmk.has('marketing')).toBe(false);
	});

	it('keeps document order and copies attributes, async off for external scripts', () => {
		document.body.innerHTML =
			'<script type="text/plain" data-cmk-category="analytics" src="/one.js" data-x="1"></script>' +
			'<script type="text/plain" data-cmk-category="analytics">two()</script>' +
			'<script type="text/plain" data-cmk-category="analytics" src="/three.js"></script>';
		setConsent(valid(['necessary', 'analytics']));
		start();

		const scripts = live();
		expect(scripts.map((s) => s.getAttribute('src') || s.text)).toEqual(['/one.js', 'two()', '/three.js']);
		expect(scripts[0].getAttribute('data-x')).toBe('1');
		expect(scripts[0].async).toBe(false);
		expect(scripts[0].hasAttribute('data-cmk-category')).toBe(false);
	});

	it('restores a remembered script type such as module', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics" data-cmk-type="module">import("/x.js")</script>';
		setConsent(valid(['necessary', 'analytics']));
		start();

		expect(live()[0].type).toBe('module');
	});

	it('activates each script at most once', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		setConsent(valid(['necessary', 'analytics']));
		start();
		window.cmk.activate();
		window.cmk.activate();

		expect(live()).toHaveLength(1);
	});

	it('picks up inert scripts added later when activate() is called again', () => {
		setConsent(valid(['necessary', 'analytics']));
		start();
		document.body.insertAdjacentHTML('beforeend', '<script type="text/plain" data-cmk-category="analytics">late()</script>');
		window.cmk.activate();

		expect(live().map((s) => s.text)).toEqual(['late()']);
	});
});

describe('a stale, foreign or hostile cookie is no consent at all', () => {
	const cases = {
		'older policy version': valid(['necessary', 'analytics'], { v: 1 }),
		'newer policy version': valid(['necessary', 'analytics'], { v: 3 }),
		'unknown handle (category deleted or renamed)': valid(['necessary', 'analytics', 'bogus']),
		'handle is an object': valid(['necessary', { a: 1 }]),
		'handle is a script tag': valid(['necessary', '<script>alert(1)</script>']),
		'c is a string': valid('analytics'),
		'c missing necessary': valid(['analytics']),
		'time missing': { v: 2, c: ['necessary'] },
		'time is a string': valid(['necessary'], { t: 'now' }),
		'version is a string': valid(['necessary'], { v: '2' }),
		'json array': [2, ['necessary']],
		'json null': null,
		'json number': 5,
		'prototype pollution attempt': '{"__proto__":{"v":2,"c":["necessary"],"t":1}}',
	};

	Object.entries(cases).forEach(([name, value]) => {
		it(name, () => {
			document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
			setConsent(typeof value === 'string' ? encodeURIComponent(value) : value);
			start();

			expect(window.cmk.get()).toBeNull();
			expect(live()).toHaveLength(0);
		});
	});

	['%E0%A4%A', encodeURIComponent('not json'), '', encodeURIComponent('{"v":2,'), 'x'.repeat(5000)].forEach((raw) => {
		it('survives garbage: ' + raw.slice(0, 20), () => {
			setConsent(raw);
			expect(() => start()).not.toThrow();
			expect(window.cmk.get()).toBeNull();
		});
	});
});

describe('save()', () => {
	it('stores necessary plus the chosen known categories, activates them and reports the change', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		start();
		const seen = [];
		window.cmk.on('consent:changed', (event) => seen.push(event));

		const saved = window.cmk.save(['analytics']);

		expect(saved).toEqual(['necessary', 'analytics']);
		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
		expect(live().map((s) => s.text)).toEqual(['a()']);
		expect(seen).toEqual([{ categories: ['necessary', 'analytics'], previous: null }]);
	});

	it('drops unknown, hostile and duplicate handles and always keeps necessary', () => {
		start();

		expect(window.cmk.save(['bogus', '<script>', 'analytics', 'analytics', 5, null])).toEqual(['necessary', 'analytics']);
		expect(window.cmk.save([])).toEqual(['necessary']);
		expect(window.cmk.save('analytics')).toEqual(['necessary']);
		expect(window.cmk.save(undefined)).toEqual(['necessary']);
	});

	it('round-trips through the cookie so the next page load sees the same choice', () => {
		start();
		window.cmk.save(['marketing']);

		start(); // a new page load reads the cookie again

		expect(window.cmk.get().c).toEqual(['necessary', 'marketing']);
		expect(window.cmk.get().v).toBe(2);
	});

	it('reports the previous choice so callers can see what was withdrawn', () => {
		setConsent(valid(['necessary', 'analytics']));
		start();
		const seen = [];
		window.cmk.on('consent:changed', (event) => seen.push(event));

		window.cmk.save([]);

		expect(seen[0]).toEqual({ categories: ['necessary'], previous: ['necessary', 'analytics'] });
	});

	it('writes a first-party, script-readable cookie with the documented attributes', () => {
		const writes = [];
		Object.defineProperty(document, 'cookie', { configurable: true, get: () => '', set: (value) => writes.push(value) });
		start();

		window.cmk.save(['analytics']);

		const written = writes.at(-1);
		expect(written).toMatch(/^cmk_consent=/);
		expect(written).toContain('Path=/');
		expect(written).toContain('Max-Age=15552000'); // 180 days
		expect(written).toContain('SameSite=Lax');
		expect(written).not.toContain('HttpOnly');
		expect(JSON.parse(decodeURIComponent(written.split(';')[0].split('=')[1]))).toMatchObject({ v: 2, c: ['necessary', 'analytics'] });
	});

	it('still applies the choice for this page view when cookies cannot be written', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		Object.defineProperty(document, 'cookie', {
			configurable: true,
			get: () => '',
			set: () => {
				throw new Error('cookies are blocked');
			},
		});
		start();

		expect(() => window.cmk.save(['analytics'])).not.toThrow();
		expect(live().map((s) => s.text)).toEqual(['a()']);
		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
	});

	it('copes with writes that are silently ignored (cookies disabled)', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		Object.defineProperty(document, 'cookie', { configurable: true, get: () => '', set: () => {} });
		start();

		window.cmk.save(['analytics']);

		expect(live()).toHaveLength(1);
	});

	it('isolates a throwing listener from the others', () => {
		start();
		const calls = [];
		window.cmk.on('consent:changed', () => {
			throw new Error('boom');
		});
		window.cmk.on('consent:changed', () => calls.push('second'));

		expect(() => window.cmk.save([])).not.toThrow();
		expect(calls).toEqual(['second']);
	});
});

describe('boot data', () => {
	it('defaults the lifetime and ignores invalid category handles in boot', () => {
		const writes = [];
		Object.defineProperty(document, 'cookie', { configurable: true, get: () => '', set: (value) => writes.push(value) });
		start({ v: 2, cats: ['necessary', 'analytics', '<script>', 5, 'analytics'] });

		expect(window.cmk.known).toEqual(['necessary', 'analytics']);
		window.cmk.save(['analytics']);
		expect(writes.at(-1)).toContain('Max-Age=15552000');
	});

	it('does not run twice if the loader is inlined twice', () => {
		start();
		const first = window.cmk;
		loadScript('cmk-loader.js');

		expect(window.cmk).toBe(first);
	});

	it('defers activation until the DOM is parsed when the document is still loading', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		setConsent(valid(['necessary', 'analytics']));
		let readyState = 'loading';
		Object.defineProperty(document, 'readyState', { configurable: true, get: () => readyState });

		start();
		expect(live()).toHaveLength(0);

		readyState = 'interactive';
		document.dispatchEvent(new Event('DOMContentLoaded'));
		expect(live()).toHaveLength(1);
	});
});
```

- [ ] **Step 3: Run to verify they fail**

Run: `npx vitest run tests/js/loader.test.js`
Expected: FAIL, `ENOENT ... js/cmk-loader.js`.

- [ ] **Step 4: Implement the loader**

Create `js/cmk-loader.js`:

```js
/*
 * Cookie Manager loader. Inlined into <head> on every page, so it has no dependencies and stays small.
 * It reads the consent cookie, activates inert scripts for the categories the visitor consented to, and is the
 * only code that reads or writes the cookie.
 *
 * Reads window.cmkBoot: { v: policy version, cats: [known category handles], days: cookie lifetime }.
 */
(function (w, d) {
	'use strict';

	if (w.cmk) {
		return;
	}

	var boot = w.cmkBoot || {};
	var COOKIE = 'cmk_consent';
	var NECESSARY = 'necessary';
	var HANDLE = /^[a-z0-9_-]{1,32}$/;
	var version = typeof boot.v === 'number' ? boot.v : 0;
	var days = typeof boot.days === 'number' && boot.days > 0 ? boot.days : 180;
	var known = [];
	var handlers = {};
	var current = null;

	(Array.isArray(boot.cats) ? boot.cats : []).forEach(function (handle) {
		if (typeof handle === 'string' && HANDLE.test(handle) && known.indexOf(handle) === -1) {
			known.push(handle);
		}
	});

	function readRaw() {
		var parts = d.cookie ? d.cookie.split('; ') : [];

		for (var i = 0; i < parts.length; i++) {
			var eq = parts[i].indexOf('=');

			if (eq > 0 && parts[i].slice(0, eq) === COOKIE) {
				return parts[i].slice(eq + 1);
			}
		}

		return null;
	}

	/* Anything unexpected is "no consent": the visitor is asked again rather than trusted with a guess. */
	function parse(raw) {
		if (!raw) {
			return null;
		}

		try {
			var data = JSON.parse(decodeURIComponent(raw));

			if (!data || typeof data !== 'object' || Array.isArray(data)) {
				return null;
			}

			if (data.v !== version || !Array.isArray(data.c) || typeof data.t !== 'number') {
				return null;
			}

			var categories = [];

			for (var i = 0; i < data.c.length; i++) {
				if (typeof data.c[i] !== 'string' || known.indexOf(data.c[i]) === -1) {
					return null;
				}

				if (categories.indexOf(data.c[i]) === -1) {
					categories.push(data.c[i]);
				}
			}

			if (categories.indexOf(NECESSARY) === -1) {
				return null;
			}

			return { v: data.v, c: categories, t: data.t };
		} catch (e) {
			return null;
		}
	}

	function emit(name, payload) {
		(handlers[name] || []).slice().forEach(function (fn) {
			try {
				fn(payload);
			} catch (e) {
				/* one listener must not stop the others */
			}
		});
	}

	function activate() {
		var granted = current ? current.c : [NECESSARY];
		var nodes = d.querySelectorAll('script[type="text/plain"][data-cmk-category]');

		for (var i = 0; i < nodes.length; i++) {
			var inert = nodes[i];

			if (inert.hasAttribute('data-cmk-done') || granted.indexOf(inert.getAttribute('data-cmk-category')) === -1) {
				continue;
			}

			inert.setAttribute('data-cmk-done', '1');

			var live = d.createElement('script');

			for (var a = 0; a < inert.attributes.length; a++) {
				var name = inert.attributes[a].name;

				if (name !== 'type' && name.indexOf('data-cmk-') !== 0) {
					live.setAttribute(name, inert.attributes[a].value);
				}
			}

			if (inert.nonce) {
				live.nonce = inert.nonce;
			}

			if (inert.getAttribute('data-cmk-type')) {
				live.type = inert.getAttribute('data-cmk-type');
			}

			if (inert.getAttribute('src')) {
				live.async = false; /* keep document order for external scripts */
			}

			live.text = inert.text;
			inert.parentNode.insertBefore(live, inert.nextSibling);
		}
	}

	function save(categories) {
		var list = [NECESSARY];

		(Array.isArray(categories) ? categories : []).forEach(function (handle) {
			if (typeof handle === 'string' && known.indexOf(handle) !== -1 && list.indexOf(handle) === -1) {
				list.push(handle);
			}
		});

		var previous = current;

		current = { v: version, c: list, t: Math.floor(Date.now() / 1000) };

		try {
			d.cookie =
				COOKIE +
				'=' +
				encodeURIComponent(JSON.stringify(current)) +
				'; Path=/; Max-Age=' +
				Math.round(days * 86400) +
				'; SameSite=Lax' +
				(w.location.protocol === 'https:' ? '; Secure' : '');
		} catch (e) {
			/* cookies blocked: the choice still applies to this page view */
		}

		activate();
		emit('consent:changed', { categories: list.slice(), previous: previous ? previous.c.slice() : null });

		return list.slice();
	}

	function ready(fn) {
		if (d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	current = parse(readRaw());

	w.cmk = {
		version: version,
		known: known.slice(),
		get: function () {
			return current ? { v: current.v, c: current.c.slice(), t: current.t } : null;
		},
		has: function (handle) {
			return current ? current.c.indexOf(handle) !== -1 : handle === NECESSARY;
		},
		save: save,
		activate: activate,
		on: function (name, fn) {
			if (typeof fn === 'function') {
				(handlers[name] = handlers[name] || []).push(fn);
			}
		},
	};

	ready(activate);
})(window, document);
```

- [ ] **Step 5: Run to verify they pass**

Run: `npx vitest run tests/js/loader.test.js`
Expected: PASS. If a jsdom detail differs (for example `script.text` or `document.readyState` spying), fix the test's setup, not the loader's contract.

- [ ] **Step 6: Commit**

```bash
git add js/cmk-loader.js tests/js/helpers.js tests/js/loader.test.js
git commit -m "feat: add consent loader that owns the cookie and activates inert scripts

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Banner and preference runtime (DOM-only, XSS-safe)

**Files:**
- Create: `js/cmk-runtime.js`
- Test: `tests/js/runtime.test.js`, `tests/js/sinks.test.js`

**Interfaces:**
- Consumes: `window.cmk` (Task 10), `window.cmkBoot` (`post`, `cfg`, `v`, `manual`), the config shape (Task 5).
- Produces `window.cmkRuntime`: `start(config): void`, `open(): void` (show preferences), `stop(): void` (tear down listeners and DOM; used by tests), `reload: () => void` (overridable; called after a withdrawal). With `cmkBoot.manual` falsy and `cmkBoot.cfg` set, it fetches `cfg` on DOM ready and calls `start` itself.
- DOM contract for CSS and the dashboard preview: root `div.cmk[role=dialog]` with `data-position`, `data-layout`, `data-font`, `data-shadow`; children `h2#cmk-title`, `p#cmk-body`, `a.cmk__link`, `div.cmk__actions > button.cmk__btn`, `div.cmk__prefs[hidden]` > `div.cmk__cat`; floating `button.cmk-widget[data-cmk-open]`. CSS custom properties: `--cmk-bg --cmk-fg --cmk-accent --cmk-button-bg --cmk-button-fg --cmk-radius`.

- [ ] **Step 1: Write the failing tests**

Create `tests/js/runtime.test.js`:

```js
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadScript } from './helpers.js';

const BOOT = { v: 2, cats: ['necessary', 'preferences', 'analytics', 'marketing'], days: 180, post: '/cookie-manager/consent', manual: true };

function config(overrides = {}) {
	return {
		version: 'abc123abc123',
		policyVersion: 2,
		locale: 'en_GB',
		banner: {
			title: 'Your privacy',
			body: 'We use cookies.\nChoose below.',
			labels: { acceptAll: 'Accept all', rejectAll: 'Reject all', customise: 'Customise', save: 'Save', widget: 'Cookie settings' },
			policyUrl: 'https://example.com/cookies',
			policyLinkText: 'Cookie policy',
		},
		categories: [
			{ handle: 'necessary', necessary: true, title: 'Necessary', description: 'Required', services: [], cookies: ['CONCRETE', 'cmk_consent'] },
			{ handle: 'preferences', necessary: false, title: 'Preferences', description: 'Remember choices', services: [], cookies: [] },
			{ handle: 'analytics', necessary: false, title: 'Analytics', description: 'Measure visits', services: [{ name: 'GA', vendor: 'Google', description: 'Counts visits' }], cookies: [] },
			{ handle: 'marketing', necessary: false, title: 'Marketing', description: 'Ads', services: [], cookies: [] },
		],
		style: { position: 'bottom-right', layout: 'stacked', font: 'inherit', tokens: { bg: '#ffffff', fg: '#111111', accent: '#0b5fff', buttonBg: '#0b5fff', buttonFg: '#ffffff' }, radius: 8, shadow: true },
		lifetimeDays: 180,
		widget: true,
		...overrides,
	};
}

function consent(categories) {
	document.cookie = 'cmk_consent=' + encodeURIComponent(JSON.stringify({ v: 2, c: categories, t: 1700000000 })) + '; Path=/';
}

function mount(cfg = config(), boot = BOOT) {
	delete window.cmk;
	delete window.cmkRuntime;
	window.cmkBoot = boot;
	loadScript('cmk-loader.js');
	loadScript('cmk-runtime.js');
	window.cmkRuntime.reload = vi.fn();
	window.cmkRuntime.start(cfg);
}

const root = () => document.querySelector('.cmk');
const prefs = () => document.querySelector('.cmk__prefs');
const button = (text) => [...document.querySelectorAll('button')].find((b) => b.textContent === text);
const saved = () => window.cmk.get().c;

beforeEach(() => {
	document.body.innerHTML = '';
	window.fetch = vi.fn(() => Promise.resolve({ ok: true }));
});

afterEach(() => {
	if (window.cmkRuntime) window.cmkRuntime.stop();
	document.cookie = 'cmk_consent=; Path=/; Max-Age=0';
	delete window.cmk;
	delete window.cmkRuntime;
	delete window.cmkBoot;
	vi.restoreAllMocks();
});

describe('showing the banner', () => {
	it('shows an accessible banner with the content when there is no valid choice', () => {
		mount();

		expect(root()).not.toBeNull();
		expect(root().getAttribute('role')).toBe('dialog');
		expect(root().getAttribute('aria-labelledby')).toBe('cmk-title');
		expect(document.getElementById('cmk-title').textContent).toBe('Your privacy');
		expect(document.getElementById('cmk-body').textContent).toBe('We use cookies.Choose below.');
		expect(document.getElementById('cmk-body').querySelectorAll('br')).toHaveLength(1);
		expect(button('Accept all')).toBeTruthy();
		expect(button('Reject all')).toBeTruthy();
		expect(button('Customise')).toBeTruthy();
		expect(prefs().hidden).toBe(true);
	});

	it('does not show the banner once a valid choice exists, but keeps the widget', () => {
		consent(['necessary', 'analytics']);
		mount();

		expect(root()).toBeNull();
		expect(document.querySelector('.cmk-widget')).not.toBeNull();
		expect(document.querySelector('.cmk-widget').textContent).toBe('Cookie settings');
	});

	it('omits the widget when the admin turned it off', () => {
		mount(config({ widget: false }));

		expect(document.querySelector('.cmk-widget')).toBeNull();
	});

	it('applies position, layout, font and shadow from an allowlist only', () => {
		mount(config({ style: { ...config().style, position: 'bottom-left', layout: 'inline', font: 'system', shadow: false } }));

		expect(root().dataset.position).toBe('bottom-left');
		expect(root().dataset.layout).toBe('inline');
		expect(root().dataset.font).toBe('system');
		expect(root().dataset.shadow).toBe('off');
	});

	it('falls back to defaults for hostile style values and ignores invalid colours', () => {
		mount(config({ style: { position: '"><img src=x onerror=alert(1)>', layout: 'x', font: 'url(x)', radius: 9999, shadow: true, tokens: { bg: 'red;}</style><script>alert(1)</script>', fg: '#222222', accent: 5, buttonBg: null, buttonFg: '#abcdef' } } }));

		expect(root().dataset.position).toBe('bottom-right');
		expect(root().dataset.layout).toBe('stacked');
		expect(root().dataset.font).toBe('inherit');
		expect(root().style.getPropertyValue('--cmk-bg')).toBe('');
		expect(root().style.getPropertyValue('--cmk-fg')).toBe('#222222');
		expect(root().style.getPropertyValue('--cmk-button-fg')).toBe('#abcdef');
		expect(root().style.getPropertyValue('--cmk-radius')).toBe('');
		expect(document.querySelector('img')).toBeNull();
	});

	it('ignores a missing or invalid config and does nothing without the loader', () => {
		mount(null);
		expect(root()).toBeNull();

		mount('nope');
		expect(root()).toBeNull();

		delete window.cmk;
		delete window.cmkRuntime;
		loadScript('cmk-runtime.js');
		expect(() => window.cmkRuntime.start(config())).not.toThrow();
		expect(root()).toBeNull();
	});
});

describe('hostile content is rendered as text, never as markup', () => {
	const evil = '<img src=x onerror=alert(1)><script>window.__pwned=1</script>"\'`';

	it('banner, category and service text', () => {
		const cfg = config();
		cfg.banner.title = evil;
		cfg.banner.body = evil + '\n' + evil;
		cfg.banner.labels = { acceptAll: evil, rejectAll: evil, customise: evil, save: evil, widget: evil };
		cfg.banner.policyLinkText = evil;
		cfg.categories[2].title = evil;
		cfg.categories[2].description = evil;
		cfg.categories[2].services = [{ name: evil, vendor: evil, description: evil }];
		cfg.categories[0].cookies = [evil];
		mount(cfg);

		expect(document.querySelector('img')).toBeNull();
		expect(document.querySelectorAll('.cmk script, .cmk-widget script')).toHaveLength(0);
		expect(document.getElementById('cmk-title').textContent).toBe(evil);
		expect(window.__pwned).toBeUndefined();
		expect(document.querySelectorAll('[onerror]')).toHaveLength(0);
	});

	it('only links http(s) and relative policy URLs, with safe rel', () => {
		const link = (url) => {
			const cfg = config();
			cfg.banner.policyUrl = url;
			mount(cfg);
			const anchor = document.querySelector('a.cmk__link');
			window.cmkRuntime.stop();
			return anchor;
		};

		expect(link('https://example.com/p').getAttribute('href')).toBe('https://example.com/p');
		expect(link('/privacy').getAttribute('href')).toBe('/privacy');
		expect(link('https://example.com/p').getAttribute('rel')).toBe('noopener noreferrer');

		['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", 'data:text/html,x', 'vbscript:x', '//evil.example', '/\\evil.example', '', null, 5].forEach((bad) => {
			expect(link(bad), String(bad)).toBeNull();
		});
	});

	it('skips categories whose handle is not a valid slug', () => {
		const cfg = config();
		cfg.categories.push({ handle: '<script>', necessary: false, title: 'Bad', description: '', services: [], cookies: [] });
		cfg.categories.push({ handle: 5, necessary: false, title: 'Bad', description: '', services: [], cookies: [] });
		mount(cfg);

		expect(document.querySelectorAll('.cmk__cat')).toHaveLength(4);
	});
});

describe('making a choice', () => {
	it('accept all stores every category, tells the server and closes the banner', () => {
		mount();
		button('Accept all').click();

		expect(saved()).toEqual(['necessary', 'preferences', 'analytics', 'marketing']);
		expect(root()).toBeNull();
		expect(window.fetch).toHaveBeenCalledTimes(1);

		const [url, options] = window.fetch.mock.calls[0];
		expect(url).toBe('/cookie-manager/consent');
		expect(options.method).toBe('POST');
		expect(options.credentials).toBe('same-origin');
		expect(options.headers['Content-Type']).toBe('application/json');
		expect(JSON.parse(options.body)).toEqual({ v: 2, c: ['necessary', 'preferences', 'analytics', 'marketing'] });
		expect(window.cmkRuntime.reload).not.toHaveBeenCalled();
	});

	it('reject all stores only necessary', () => {
		mount();
		button('Reject all').click();

		expect(saved()).toEqual(['necessary']);
		expect(root()).toBeNull();
	});

	it('customise lists every category, necessary is ticked and locked, the rest start unticked', () => {
		mount();
		button('Customise').click();

		expect(prefs().hidden).toBe(false);
		const box = (handle) => document.querySelector('[data-cmk-cat="' + handle + '"]');
		expect(box('necessary').checked).toBe(true);
		expect(box('necessary').disabled).toBe(true);
		['preferences', 'analytics', 'marketing'].forEach((handle) => {
			expect(box(handle).checked).toBe(false);
			expect(box(handle).disabled).toBe(false);
		});
	});

	it('saves exactly the ticked categories', () => {
		mount();
		button('Customise').click();
		document.querySelector('[data-cmk-cat="analytics"]').checked = true;
		button('Save').click();

		expect(saved()).toEqual(['necessary', 'analytics']);
		expect(JSON.parse(window.fetch.mock.calls[0][1].body).c).toEqual(['necessary', 'analytics']);
	});

	it('activates inert scripts straight away through the loader', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		mount();
		button('Accept all').click();

		expect([...document.querySelectorAll('script:not([type="text/plain"])')]).toHaveLength(1);
	});
});

describe('changing the choice later', () => {
	it('reopens from any [data-cmk-open] element with the current choice ticked', () => {
		consent(['necessary', 'analytics']);
		mount();
		document.body.insertAdjacentHTML('beforeend', '<a href="#" id="footer-link" data-cmk-open><span id="inner">Cookie settings</span></a>');

		document.getElementById('inner').click(); // a click on a child still opens it

		expect(root()).not.toBeNull();
		expect(prefs().hidden).toBe(false);
		expect(document.querySelector('[data-cmk-cat="analytics"]').checked).toBe(true);
		expect(document.querySelector('[data-cmk-cat="marketing"]').checked).toBe(false);
	});

	it('reopens from the floating widget', () => {
		consent(['necessary']);
		mount();
		document.querySelector('.cmk-widget').click();

		expect(root()).not.toBeNull();
	});

	it('reloads the page when a previously consented category is withdrawn', () => {
		consent(['necessary', 'analytics']);
		mount();
		document.querySelector('.cmk-widget').click();
		document.querySelector('[data-cmk-cat="analytics"]').checked = false;
		button('Save').click();

		expect(saved()).toEqual(['necessary']);
		expect(window.cmkRuntime.reload).toHaveBeenCalledTimes(1);
	});

	it('does not reload when categories are only added or unchanged', () => {
		consent(['necessary', 'analytics']);
		mount();
		document.querySelector('.cmk-widget').click();
		document.querySelector('[data-cmk-cat="marketing"]').checked = true;
		button('Save').click();

		expect(saved()).toEqual(['necessary', 'analytics', 'marketing']);
		expect(window.cmkRuntime.reload).not.toHaveBeenCalled();
	});

	it('Escape closes the preferences only when a choice already exists', () => {
		consent(['necessary']);
		mount();
		document.querySelector('.cmk-widget').click();
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(root()).toBeNull();

		document.cookie = 'cmk_consent=; Path=/; Max-Age=0';
		window.cmkRuntime.stop();
		mount();
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(root()).not.toBeNull();
	});

	it('stop() removes every listener and node it added', () => {
		mount();
		window.cmkRuntime.stop();

		expect(root()).toBeNull();
		expect(document.querySelector('.cmk-widget')).toBeNull();
		document.body.insertAdjacentHTML('beforeend', '<a data-cmk-open id="x">x</a>');
		document.getElementById('x').click();
		expect(root()).toBeNull();
	});
});

describe('when the consent endpoint cannot be reached', () => {
	it('keeps the choice when fetch rejects', async () => {
		window.fetch = vi.fn(() => Promise.reject(new Error('offline')));
		mount();
		button('Accept all').click();
		await Promise.resolve();

		expect(saved()).toContain('analytics');
		expect(root()).toBeNull();
	});

	it('keeps the choice when the server answers with an error', async () => {
		window.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));
		mount();
		button('Reject all').click();
		await Promise.resolve();

		expect(saved()).toEqual(['necessary']);
	});

	it('keeps the choice when fetch throws synchronously', () => {
		window.fetch = vi.fn(() => {
			throw new Error('blocked by an extension');
		});
		mount();

		expect(() => button('Accept all').click()).not.toThrow();
		expect(saved()).toContain('analytics');
	});

	it('keeps the choice when fetch does not exist', () => {
		delete window.fetch;
		mount();

		expect(() => button('Accept all').click()).not.toThrow();
		expect(saved()).toContain('analytics');
	});

	it('does not post at all when no endpoint is configured', () => {
		mount(config(), { ...BOOT, post: undefined });
		button('Accept all').click();

		expect(window.fetch).not.toHaveBeenCalled();
		expect(saved()).toContain('analytics');
	});
});

describe('automatic start', () => {
	it('fetches the config itself when not in manual mode, without credentials', async () => {
		const cfg = config();
		window.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(cfg) }));
		delete window.cmk;
		delete window.cmkRuntime;
		window.cmkBoot = { ...BOOT, manual: false, cfg: '/cookie-manager/config/en_GB.json?v=abc' };
		loadScript('cmk-loader.js');
		loadScript('cmk-runtime.js');
		await new Promise((resolve) => setTimeout(resolve, 0));

		expect(window.fetch).toHaveBeenCalledWith('/cookie-manager/config/en_GB.json?v=abc', { credentials: 'omit' });
		expect(root()).not.toBeNull();
	});

	it('stays silent when the config cannot be loaded (scripts stay blocked)', async () => {
		window.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));
		delete window.cmk;
		delete window.cmkRuntime;
		window.cmkBoot = { ...BOOT, manual: false, cfg: '/cookie-manager/config/en_GB.json' };
		loadScript('cmk-loader.js');

		expect(() => loadScript('cmk-runtime.js')).not.toThrow();
		await new Promise((resolve) => setTimeout(resolve, 0));
		expect(root()).toBeNull();
	});
});
```

Create `tests/js/sinks.test.js`:

```js
import { describe, expect, it } from 'vitest';
import { resolve } from 'node:path';
import { readdirSync, readFileSync } from 'node:fs';

const dir = resolve(__dirname, '../../js');

/** Anything that turns a string into markup or code. Comments are stripped first so documentation can mention them. */
const BANNED = [
	/\binnerHTML\b/,
	/\bouterHTML\b/,
	/insertAdjacentHTML/,
	/document\s*\.\s*write/,
	/\beval\s*\(/,
	/new\s+Function\b/,
	/\bsetTimeout\s*\(\s*['"`]/,
	/\bsetInterval\s*\(\s*['"`]/,
	/\.srcdoc\b/,
	/createContextualFragment/,
	/\.insertAdjacentElement\s*\(\s*[^,]+,\s*['"`]</,
];

const strip = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

describe('browser code never turns strings into markup or code', () => {
	const files = readdirSync(dir).filter((name) => name.endsWith('.js'));

	it('finds the package scripts', () => {
		expect(files).toContain('cmk-loader.js');
		expect(files).toContain('cmk-runtime.js');
	});

	files.forEach((file) => {
		it(file, () => {
			const source = strip(readFileSync(resolve(dir, file), 'utf8'));

			BANNED.forEach((pattern) => {
				expect(source, String(pattern)).not.toMatch(pattern);
			});
		});
	});

	it('the loader can be inlined: it never contains a closing script tag', () => {
		expect(readFileSync(resolve(dir, 'cmk-loader.js'), 'utf8').toLowerCase()).not.toContain('</script');
	});
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `npx vitest run tests/js/runtime.test.js tests/js/sinks.test.js`
Expected: FAIL (`ENOENT ... js/cmk-runtime.js`).

- [ ] **Step 3: Implement the runtime**

Create `js/cmk-runtime.js`:

```js
/*
 * Cookie Manager runtime: builds the banner and the preference centre from the config JSON.
 * Everything is built with createElement, textContent and setAttribute. Nothing from the config or from the
 * visitor is ever parsed as markup, and links exist only for http(s) or relative URLs.
 */
(function (w, d) {
	'use strict';

	var boot = w.cmkBoot || {};
	var HEX = /^#[0-9a-fA-F]{6}$/;
	var HANDLE = /^[a-z0-9_-]{1,32}$/;
	var POSITIONS = ['bottom-left', 'bottom-right', 'bottom-bar', 'center-modal'];
	var LAYOUTS = ['stacked', 'inline'];
	var FONTS = ['inherit', 'system'];
	var TOKEN_VARS = { bg: '--cmk-bg', fg: '--cmk-fg', accent: '--cmk-accent', buttonBg: '--cmk-button-bg', buttonFg: '--cmk-button-fg' };

	var cmk = null;
	var state = { config: null, root: null, widget: null, started: false };
	var runtime = {
		reload: function () {
			w.location.reload();
		},
	};

	function pick(value, allowed, fallback) {
		return typeof value === 'string' && allowed.indexOf(value) !== -1 ? value : fallback;
	}

	function safeHref(url) {
		if (typeof url !== 'string') {
			return '';
		}

		var trimmed = url.trim();
		var normalised = trimmed.replace(/[\u0000- ]+/g, '');
		var scheme = /^([a-z][a-z0-9+.-]*):/i.exec(normalised);

		if (normalised === '' || /^[\/\\]{2}/.test(normalised)) {
			return '';
		}

		return scheme && !/^https?$/i.test(scheme[1]) ? '' : trimmed;
	}

	function el(tag, attributes, children) {
		var node = d.createElement(tag);

		Object.keys(attributes || {}).forEach(function (name) {
			var value = attributes[name];

			if (value === null || value === undefined || value === false) {
				return;
			}

			if (name === 'text') {
				node.textContent = String(value);
			} else {
				node.setAttribute(name, value === true ? '' : String(value));
			}
		});

		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(typeof child === 'string' ? d.createTextNode(child) : child);
			}
		});

		return node;
	}

	/* Text with line breaks: text nodes and <br> elements we create ourselves. */
	function lines(text) {
		var out = [];

		String(text || '')
			.split('\n')
			.forEach(function (line, index) {
				if (index > 0) {
					out.push(d.createElement('br'));
				}

				out.push(d.createTextNode(line));
			});

		return out;
	}

	function applyStyle(node, style) {
		var tokens = style.tokens || {};

		Object.keys(TOKEN_VARS).forEach(function (name) {
			if (typeof tokens[name] === 'string' && HEX.test(tokens[name])) {
				node.style.setProperty(TOKEN_VARS[name], tokens[name]);
			}
		});

		var radius = parseInt(style.radius, 10);

		if (radius >= 0 && radius <= 32) {
			node.style.setProperty('--cmk-radius', radius + 'px');
		}
	}

	function button(className, text, onClick) {
		var node = el('button', { type: 'button', class: className, text: text });
		node.addEventListener('click', onClick);

		return node;
	}

	function validCategories(config) {
		return (Array.isArray(config.categories) ? config.categories : []).filter(function (category) {
			return category && typeof category.handle === 'string' && HANDLE.test(category.handle);
		});
	}

	function servicesList(category) {
		var items = [];

		(Array.isArray(category.services) ? category.services : []).forEach(function (service) {
			if (service && typeof service.name === 'string') {
				items.push(
					el('li', {}, [
						el('strong', { text: service.name }),
						service.vendor ? ' (' + service.vendor + ')' : null,
						service.description ? ': ' + service.description : null,
					]),
				);
			}
		});

		return items.length ? el('ul', { class: 'cmk__services' }, items) : null;
	}

	function cookiesList(category) {
		var names = (Array.isArray(category.cookies) ? category.cookies : []).filter(function (name) {
			return typeof name === 'string' && name !== '';
		});

		if (!names.length) {
			return null;
		}

		return el(
			'ul',
			{ class: 'cmk__cookies' },
			names.map(function (name) {
				return el('li', {}, [el('code', { text: name })]);
			}),
		);
	}

	function ticked(prefs) {
		var handles = [];

		Array.prototype.forEach.call(prefs.querySelectorAll('input[data-cmk-cat]'), function (box) {
			if (box.checked && !box.disabled) {
				handles.push(box.getAttribute('data-cmk-cat'));
			}
		});

		return handles;
	}

	function post(categories) {
		if (!boot.post || typeof w.fetch !== 'function') {
			return;
		}

		try {
			w.fetch(boot.post, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ v: boot.v, c: categories }),
			}).catch(function () {
				/* the choice is already saved in the browser; the acknowledgement is best effort */
			});
		} catch (e) {
			/* same */
		}
	}

	function close(restoreFocus) {
		if (state.root && state.root.parentNode) {
			state.root.parentNode.removeChild(state.root);
		}

		state.root = null;

		if (restoreFocus && state.widget) {
			state.widget.focus();
		}
	}

	function commit(handles) {
		var previous = cmk.get();
		var saved = cmk.save(handles);
		var withdrew =
			previous &&
			previous.c.some(function (handle) {
				return saved.indexOf(handle) === -1;
			});

		post(saved);
		close(true);

		if (withdrew) {
			runtime.reload();
		}
	}

	function build(config, expanded) {
		var banner = config.banner || {};
		var labels = banner.labels || {};
		var style = config.style || {};
		var granted = cmk.get() ? cmk.get().c : [];
		var categories = validCategories(config);

		var root = el('div', {
			class: 'cmk',
			role: 'dialog',
			'aria-modal': style.position === 'center-modal' ? 'true' : 'false',
			'aria-labelledby': 'cmk-title',
			'aria-describedby': 'cmk-body',
			'data-position': pick(style.position, POSITIONS, 'bottom-right'),
			'data-layout': pick(style.layout, LAYOUTS, 'stacked'),
			'data-font': pick(style.font, FONTS, 'inherit'),
			'data-shadow': style.shadow ? 'on' : 'off',
		});

		applyStyle(root, style);

		var prefs = el('div', { class: 'cmk__prefs', id: 'cmk-prefs' });
		prefs.hidden = !expanded;

		categories.forEach(function (category) {
			var necessary = category.necessary === true;
			var box = el('input', { type: 'checkbox', id: 'cmk-cat-' + category.handle, 'data-cmk-cat': category.handle });
			box.checked = necessary || granted.indexOf(category.handle) !== -1;
			box.disabled = necessary;

			prefs.appendChild(
				el('div', { class: 'cmk__cat' }, [
					el('label', { class: 'cmk__cat-head', for: 'cmk-cat-' + category.handle }, [box, el('span', { class: 'cmk__cat-title', text: category.title })]),
					category.description ? el('p', { class: 'cmk__cat-desc' }, lines(category.description)) : null,
					servicesList(category),
					necessary ? cookiesList(category) : null,
				]),
			);
		});

		prefs.appendChild(
			button('cmk__btn cmk__btn--primary', labels.save, function () {
				commit(ticked(prefs));
			}),
		);

		var customise = button('cmk__btn cmk__btn--link', labels.customise, function () {
			prefs.hidden = !prefs.hidden;
			customise.setAttribute('aria-expanded', prefs.hidden ? 'false' : 'true');
		});
		customise.setAttribute('aria-controls', 'cmk-prefs');
		customise.setAttribute('aria-expanded', expanded ? 'true' : 'false');

		var href = safeHref(banner.policyUrl);

		root.appendChild(
			el('div', { class: 'cmk__panel' }, [
				el('h2', { id: 'cmk-title', class: 'cmk__title', tabindex: '-1', text: banner.title }),
				el('p', { id: 'cmk-body', class: 'cmk__body' }, lines(banner.body)),
				href && banner.policyLinkText ? el('a', { class: 'cmk__link', href: href, rel: 'noopener noreferrer', text: banner.policyLinkText }) : null,
				el('div', { class: 'cmk__actions' }, [
					button('cmk__btn cmk__btn--primary', labels.acceptAll, function () {
						commit(
							categories.map(function (category) {
								return category.handle;
							}),
						);
					}),
					button('cmk__btn', labels.rejectAll, function () {
						commit([]);
					}),
					customise,
				]),
				prefs,
			]),
		);

		return root;
	}

	function show(config, expanded, focus) {
		close(false);
		state.root = build(config, expanded);
		d.body.appendChild(state.root);

		if (focus) {
			var title = state.root.querySelector('#cmk-title');

			if (title) {
				title.focus();
			}
		}
	}

	function mountWidget(config) {
		var labels = (config.banner && config.banner.labels) || {};

		if (config.widget === false || state.widget || !labels.widget) {
			return;
		}

		state.widget = el('button', { type: 'button', class: 'cmk-widget', 'data-cmk-open': true, text: labels.widget });
		applyStyle(state.widget, config.style || {});
		d.body.appendChild(state.widget);
	}

	function onClick(event) {
		var node = event.target && event.target.closest ? event.target.closest('[data-cmk-open]') : null;

		if (node) {
			event.preventDefault();
			runtime.open();
		}
	}

	function onKey(event) {
		if (event.key === 'Escape' && state.root && cmk && cmk.get()) {
			close(true);
		}
	}

	runtime.start = function (config) {
		cmk = w.cmk;

		if (!cmk || !config || typeof config !== 'object' || state.started) {
			return;
		}

		state.started = true;
		state.config = config;
		d.addEventListener('click', onClick);
		d.addEventListener('keydown', onKey);
		mountWidget(config);

		if (!cmk.get()) {
			show(config, false, false);
		}
	};

	runtime.open = function () {
		if (state.config && cmk) {
			show(state.config, true, true);
		}
	};

	runtime.stop = function () {
		d.removeEventListener('click', onClick);
		d.removeEventListener('keydown', onKey);
		close(false);

		if (state.widget && state.widget.parentNode) {
			state.widget.parentNode.removeChild(state.widget);
		}

		state.widget = null;
		state.config = null;
		state.started = false;
	};

	w.cmkRuntime = runtime;

	function ready(fn) {
		if (d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	if (!boot.manual && boot.cfg && typeof w.fetch === 'function') {
		ready(function () {
			w.fetch(boot.cfg, { credentials: 'omit' })
				.then(function (response) {
					return response.ok ? response.json() : null;
				})
				.then(function (config) {
					if (config) {
						runtime.start(config);
					}
				})
				.catch(function () {
					/* without a config there is no banner; scripts simply stay blocked */
				});
		});
	}
})(window, document);
```

- [ ] **Step 4: Run to verify they pass**

Run: `npx vitest run`
Expected: all JS tests PASS (loader, runtime, sinks). If `style.getPropertyValue('--cmk-…')` returns `''` for valid colours in jsdom, check the installed `cssstyle` version supports custom properties and upgrade `jsdom` within this package only; do not weaken the assertion.

- [ ] **Step 5: Format and commit**

```bash
npx prettier --write js tests/js vitest.config.js
git add js/cmk-runtime.js tests/js
git commit -m "feat: add DOM-only banner runtime with preference centre and widget

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 12: CSS, inline loader markup and page injection

**Files:**
- Create: `css/cmk.css`, `src/Support/LoaderScript.php`, `src/Events/ConsentManager.php`
- Test: `tests/Support/LoaderScriptTest.php`

**Interfaces:**
- Consumes: `js/cmk-loader.js` (Task 10), `js/cmk-runtime.js` (Task 11), `Catalog::*` (Task 6), `TrackingCode::makeInert` (Task 9), `Output::json`/`Output::e` (Task 1).
- Produces:
  - `LoaderScript::source(): string` (the loader file, with `</script` made impossible) and `LoaderScript::inline(array $boot, ?string $nonce = null): string` (`<script[ nonce="…"]>window.cmkBoot=<json>;<loader></script>`; the nonce is only emitted when it matches `^[A-Za-z0-9+/=_-]{8,128}$`).
  - `ConsentManager::registerAssets(): void`, `::onBeforeRender(): void`, `::onPageOutput($event): void`. Asset handles: `cookie-manager-kit/css`, `cookie-manager-kit/runtime`, `cookie-manager-kit/dashboard`.
  - A CSP nonce can be supplied by binding `cookie_manager_kit.csp_nonce` in the container (string or callable returning a string).

- [ ] **Step 1: Write the failing test**

Create `tests/Support/LoaderScriptTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\LoaderScript;

final class LoaderScriptTest extends TestCase
{
    public function testSourceIsTheLoaderAndCannotCloseAScriptElement(): void
    {
        $source = LoaderScript::source();

        $this->assertStringContainsString('cmk_consent', $source);
        $this->assertStringNotContainsString('</script', strtolower($source));
    }

    public function testInlineCarriesTheBootDataAndTheLoader(): void
    {
        $html = LoaderScript::inline(['v' => 3, 'cats' => ['necessary', 'analytics'], 'days' => 180, 'cfg' => '/cookie-manager/config/en_GB.json?v=abc', 'post' => '/cookie-manager/consent']);

        $this->assertStringStartsWith('<script>window.cmkBoot=', $html);
        $this->assertStringEndsWith('</script>', $html);
        $this->assertStringContainsString('"v":3', $html);
        $this->assertStringContainsString('cmk_consent', $html);
        $this->assertSame(1, substr_count(strtolower($html), '</script'));
    }

    public function testHostileBootValuesCannotBreakOutOfTheScript(): void
    {
        $html = LoaderScript::inline(['cats' => ['</script><script>alert(1)</script>', '"><img src=x onerror=alert(1)>'], 'cfg' => "';alert(1);//"]);

        $this->assertSame(1, substr_count(strtolower($html), '</script'));
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function testNonceIsEmittedOnlyWhenItLooksLikeOne(): void
    {
        $this->assertStringStartsWith('<script nonce="abcDEF123456==">', LoaderScript::inline([], 'abcDEF123456=='));

        foreach (['', 'short', 'bad"nonce onload="alert(1)"', "line\nbreak12345", '<x>12345678'] as $bad) {
            $this->assertStringStartsWith('<script>', LoaderScript::inline([], $bad), $bad);
        }

        $this->assertStringStartsWith('<script>', LoaderScript::inline([], null));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpunit tests/Support/LoaderScriptTest.php`
Expected: FAIL, class `LoaderScript` not found.

- [ ] **Step 3: Implement `LoaderScript`**

Create `src/Support/LoaderScript.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * The inline <script> that goes into <head>: the boot data followed by the loader. Inlined (not an external file)
 * so consent is read before any other script can run. Output is identical for every visitor.
 */
final class LoaderScript
{
    private static ?string $source = null;

    public static function source(): string
    {
        if (self::$source === null) {
            $path = dirname(__DIR__, 2) . '/js/cmk-loader.js';
            $code = is_file($path) ? (string) file_get_contents($path) : '';

            // The loader never contains a closing tag, and this makes sure an edit can never introduce one.
            self::$source = (string) preg_replace('#</(script)#i', '<\\/$1', $code);
        }

        return self::$source;
    }

    /**
     * @param array<string, mixed> $boot
     */
    public static function inline(array $boot, ?string $nonce = null): string
    {
        $attribute = $nonce !== null && preg_match('#^[A-Za-z0-9+/=_-]{8,128}\z#', $nonce) === 1
            ? ' nonce="' . Output::e($nonce) . '"'
            : '';

        return '<script' . $attribute . '>window.cmkBoot=' . Output::json($boot) . ';' . self::source() . '</script>';
    }
}
```

- [ ] **Step 4: Create the stylesheet**

Create `css/cmk.css`:

```css
.cmk,
.cmk-widget {
	--cmk-bg: #ffffff;
	--cmk-fg: #111111;
	--cmk-accent: #0b5fff;
	--cmk-button-bg: #0b5fff;
	--cmk-button-fg: #ffffff;
	--cmk-radius: 8px;
}

.cmk {
	position: fixed;
	z-index: 2147483000;
	box-sizing: border-box;
	width: calc(100% - 32px);
	max-width: 440px;
	max-height: calc(100vh - 32px);
	margin: 16px;
	padding: 20px;
	overflow-y: auto;
	color: var(--cmk-fg);
	font-size: 15px;
	line-height: 1.5;
	background: var(--cmk-bg);
	border-radius: var(--cmk-radius);
}

.cmk[data-font='system'],
.cmk[data-font='system'] .cmk__btn {
	font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
}

.cmk[data-font='inherit'] {
	font-family: inherit;
}

.cmk[data-shadow='on'] {
	box-shadow: 0 8px 30px rgb(0 0 0 / 25%);
}

.cmk[data-position='bottom-right'] {
	right: 0;
	bottom: 0;
}

.cmk[data-position='bottom-left'] {
	bottom: 0;
	left: 0;
}

.cmk[data-position='bottom-bar'] {
	right: 0;
	bottom: 0;
	left: 0;
	width: auto;
	max-width: none;
	margin: 0;
	border-radius: var(--cmk-radius) var(--cmk-radius) 0 0;
}

.cmk[data-position='center-modal'] {
	top: 50%;
	left: 50%;
	width: min(560px, calc(100% - 32px));
	max-width: none;
	margin: 0;
	transform: translate(-50%, -50%);

	/* dims the page behind the dialog */
	box-shadow: 0 0 0 100vmax rgb(0 0 0 / 45%);
}

.cmk [hidden] {
	display: none !important;
}

.cmk__title {
	margin: 0 0 8px;
	font-size: 1.2em;
	line-height: 1.3;
	color: inherit;
}

.cmk__title:focus {
	outline: none;
}

.cmk__body {
	margin: 0 0 8px;
}

.cmk__link {
	color: var(--cmk-accent);
}

.cmk__actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 16px;
}

.cmk[data-layout='inline'] .cmk__actions {
	flex-direction: row;
	flex-wrap: wrap;
	align-items: center;
}

.cmk__btn {
	padding: 10px 16px;
	font: inherit;
	color: var(--cmk-fg);
	cursor: pointer;
	background: transparent;
	border: 1px solid var(--cmk-accent);
	border-radius: calc(var(--cmk-radius) / 1.5);
	appearance: none;
}

.cmk__btn--primary {
	color: var(--cmk-button-fg);
	background: var(--cmk-button-bg);
	border-color: var(--cmk-button-bg);
}

.cmk__btn--link {
	padding-right: 0;
	padding-left: 0;
	text-decoration: underline;
	border-color: transparent;
}

.cmk__btn:focus-visible,
.cmk-widget:focus-visible,
.cmk__link:focus-visible {
	outline: 3px solid var(--cmk-accent);
	outline-offset: 2px;
}

.cmk__prefs {
	margin-top: 16px;
	padding-top: 12px;
	border-top: 1px solid rgb(127 127 127 / 40%);
}

.cmk__cat {
	margin-bottom: 14px;
}

.cmk__cat-head {
	display: flex;
	gap: 8px;
	align-items: center;
	font-weight: 600;
}

.cmk__cat-desc {
	margin: 4px 0 0;
	font-size: 0.93em;
}

.cmk__services,
.cmk__cookies {
	margin: 6px 0 0;
	padding-left: 20px;
	font-size: 0.9em;
}

.cmk-widget {
	position: fixed;
	bottom: 16px;
	left: 16px;
	z-index: 2147482999;
	padding: 8px 14px;
	font: inherit;
	font-size: 14px;
	color: var(--cmk-button-fg);
	cursor: pointer;
	background: var(--cmk-button-bg);
	border: 0;
	border-radius: var(--cmk-radius);
	box-shadow: 0 2px 10px rgb(0 0 0 / 25%);
}

@media (prefers-reduced-motion: no-preference) {
	.cmk-widget {
		transition: transform 0.15s ease;
	}

	.cmk-widget:hover {
		transform: translateY(-1px);
	}
}
```

- [ ] **Step 5: Implement `ConsentManager`**

Create `src/Events/ConsentManager.php`:

```php
<?php

namespace CookieManagerKit\Events;

use Concrete\Core\Page\Page;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Support\Facade\Url;
use Concrete\Core\Localization\Localization;
use CookieManagerKit\Support\{Catalog, LoaderScript, TrackingCode};

/**
 * Front end entry point. The output depends only on the page and the admin's settings, never on the visitor, so
 * Concrete's full-page cache stays valid: an inline loader in <head>, two registered assets, and the site's SEO
 * tracking snippets turned inert until the visitor consents to analytics.
 */
class ConsentManager
{
    private const PACKAGE = 'cookie_manager_kit';
    private const EXCLUDED_PATHS = ['/login'];
    private const TRACKING_CATEGORY = 'analytics';

    private static bool $outputHooked = false;

    public static function registerAssets(): void
    {
        $list = AssetList::getInstance();
        $options = ['minify' => true, 'combine' => true];

        $list->register('css', 'cookie-manager-kit/css', 'css/cmk.css', $options, self::PACKAGE);
        $list->register('javascript', 'cookie-manager-kit/runtime', 'js/cmk-runtime.js', $options, self::PACKAGE);
        $list->register('javascript', 'cookie-manager-kit/dashboard', 'js/cmk-dashboard.js', ['minify' => true, 'combine' => false], self::PACKAGE);
    }

    public static function onBeforeRender(): void
    {
        $page = Page::getCurrentPage();

        if (!$page || $page->isError() || $page->isAdminArea() || $page->isEditMode() || $page->isMasterCollection()) {
            return;
        }

        if (in_array((string) $page->getCollectionPath(), self::EXCLUDED_PATHS, true)) {
            return;
        }

        $locale = Catalog::resolveLocale((string) Localization::activeLocale());
        $controller = $page->getPageController();

        self::registerAssets();

        $boot = [
            'v' => Catalog::policyVersion(),
            'cats' => Catalog::handles(),
            'days' => Catalog::lifetimeDays(),
            'cfg' => self::path('/cookie-manager/config/' . $locale . '.json') . '?v=' . Catalog::hash(),
            'post' => self::path('/cookie-manager/consent'),
        ];

        $controller->addHeaderItem(LoaderScript::inline($boot, self::nonce()));
        $controller->requireAsset('css', 'cookie-manager-kit/css');
        $controller->requireAsset('javascript', 'cookie-manager-kit/runtime');

        if (!self::$outputHooked) {
            self::$outputHooked = true;
            app('director')->addListener('on_page_output', [self::class, 'onPageOutput']);
        }
    }

    /**
     * Concrete prints the SEO tracking snippets itself, in the form the admin pasted them. Find them verbatim in the
     * finished page and make them inert. If the analytics category has been deleted the snippet is still made
     * inert and simply never activates, which is the safe failure.
     */
    public static function onPageOutput($event): void
    {
        $contents = (string) $event->getArgument('contents');
        $repository = app('site')->getSite()->getConfigRepository();
        $changed = false;

        foreach (['seo.tracking.code.header', 'seo.tracking.code.footer'] as $key) {
            $code = trim((string) $repository->get($key));

            if ($code !== '' && str_contains($contents, $code)) {
                $contents = str_replace($code, TrackingCode::makeInert($code, self::TRACKING_CATEGORY), $contents);
                $changed = true;
            }
        }

        if ($changed) {
            $event->setArgument('contents', $contents);
        }
    }

    /**
     * A same-origin path, so the page works whichever host serves it (www or not) and whether or not pretty URLs
     * are on.
     */
    private static function path(string $route): string
    {
        $path = parse_url((string) Url::to($route), PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, '/') ? $path : $route;
    }

    /**
     * Sites with a Content-Security-Policy bind `cookie_manager_kit.csp_nonce` (a string, or a callable returning
     * one) so the inline loader carries the request's nonce.
     */
    private static function nonce(): ?string
    {
        $app = app();

        if (!$app->bound('cookie_manager_kit.csp_nonce')) {
            return null;
        }

        $nonce = $app->make('cookie_manager_kit.csp_nonce');
        $nonce = is_callable($nonce) ? $nonce() : $nonce;

        return is_string($nonce) ? $nonce : null;
    }
}
```

- [ ] **Step 6: Run to verify tests pass and lint**

Run: `composer test && composer typecheck && npx prettier --check css js`
Expected: tests PASS; typecheck shows no new errors in these files; Prettier clean (run `npx prettier --write css` if needed).

- [ ] **Step 7: Commit**

```bash
git add css src/Support/LoaderScript.php src/Events/ConsentManager.php tests/Support/LoaderScriptTest.php
git commit -m "feat: add stylesheet, inline loader markup and cache-safe page injection

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Package wiring (install, seed, routes, events)

**Files:**
- Modify: `controller.php`
- Create: `src/Support/Installer.php`, `elements/dashboard/uninstall.php`
- Test: `tests/Support/InstallerTest.php`

**Interfaces:**
- Consumes: entities (Tasks 3, 4), `Catalog` (Task 6), `ConsentManager` (Task 12), `Consent\*` (Task 7).
- Produces:
  - `Installer::CATEGORIES` (list of `['handle','sort','necessary','title','description']`; `necessary` first at sort 0), `Installer::seed(EntityManagerInterface $em, string $locale): void` (idempotent: creates the settings row, any missing default category with content for `$locale`, and default banner content for `$locale`; never overwrites existing rows; marks `necessary` as necessary if an older row lacks the flag; ends with `Catalog::flush()`).
  - Package controller: single pages `/dashboard/cookie_manager` (+ `banner`, `categories`, `services`, `appearance`), routes `cookie_manager_kit.config` (GET/HEAD) and `cookie_manager_kit.consent` (POST), `on_before_render` listener, `ConsentRecorder` bound to `NullConsentRecorder`, `$databaseTables` for purge on uninstall, version `0.1.0`.

- [ ] **Step 1: Write the failing test**

Create `tests/Support/InstallerTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\{Installer, Text};

final class InstallerTest extends TestCase
{
    public function testDefaultCategoriesAreValid(): void
    {
        $handles = array_column(Installer::CATEGORIES, 'handle');

        $this->assertSame(['necessary', 'preferences', 'analytics', 'marketing'], $handles);
        $this->assertSame($handles, array_values(array_unique($handles)));

        foreach (Installer::CATEGORIES as $category) {
            $this->assertTrue(Text::isHandle($category['handle']));
            $this->assertNotSame('', $category['title']);
            $this->assertNotSame('', $category['description']);
            $this->assertSame($category['title'], strip_tags($category['title']));
            $this->assertSame($category['description'], strip_tags($category['description']));
        }
    }

    public function testOnlyNecessaryIsNecessaryAndItComesFirst(): void
    {
        $necessary = array_filter(Installer::CATEGORIES, static fn(array $c): bool => $c['necessary']);

        $this->assertCount(1, $necessary);
        $this->assertSame('necessary', Installer::CATEGORIES[0]['handle']);
        $this->assertSame(0, Installer::CATEGORIES[0]['sort']);

        $sorts = array_column(Installer::CATEGORIES, 'sort');
        $sorted = $sorts;
        sort($sorted);
        $this->assertSame($sorted, $sorts);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpunit tests/Support/InstallerTest.php`
Expected: FAIL, class `Installer` not found.

- [ ] **Step 3: Implement `Installer`**

Create `src/Support/Installer.php`:

```php
<?php

namespace CookieManagerKit\Support;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{BannerContent, Category, Settings};

/**
 * Default data created on install and upgrade. Idempotent: it only ever adds what is missing and never overwrites
 * what an admin has edited.
 */
final class Installer
{
    public const CATEGORIES = [
        [
            'handle' => 'necessary',
            'sort' => 0,
            'necessary' => true,
            'title' => 'Strictly necessary',
            'description' => 'These are needed for the site to work, for example to keep you signed in and to remember your choice here. They cannot be switched off.',
        ],
        [
            'handle' => 'preferences',
            'sort' => 10,
            'necessary' => false,
            'title' => 'Preferences',
            'description' => 'These remember choices you make, such as your language or region.',
        ],
        [
            'handle' => 'analytics',
            'sort' => 20,
            'necessary' => false,
            'title' => 'Analytics',
            'description' => 'These help us understand how visitors use the site so we can improve it.',
        ],
        [
            'handle' => 'marketing',
            'sort' => 30,
            'necessary' => false,
            'title' => 'Marketing',
            'description' => 'These are used to show you relevant content and to measure how well it works.',
        ],
    ];

    public static function seed(EntityManagerInterface $em, string $locale): void
    {
        if ($em->getRepository(Settings::class)->findOneBy([]) === null) {
            $em->persist(new Settings());
        }

        foreach (self::CATEGORIES as $definition) {
            $category = $em->getRepository(Category::class)->findOneBy(['handle' => $definition['handle']]);

            if ($category === null) {
                $category = (new Category())
                    ->setHandle($definition['handle'])
                    ->setSortOrder($definition['sort'])
                    ->setNecessary($definition['necessary']);
                $category->setContent($locale, $definition['title'], $definition['description']);
                $em->persist($category);
            } elseif ($definition['necessary'] && !$category->isNecessary()) {
                $category->setNecessary(true);
            }
        }

        if ($em->getRepository(BannerContent::class)->findOneBy(['locale' => $locale]) === null) {
            $defaults = Catalog::DEFAULTS;

            $em->persist(
                (new BannerContent())
                    ->setLocale($locale)
                    ->setTitle($defaults['title'])
                    ->setBody($defaults['body'])
                    ->setAcceptAllLabel($defaults['acceptAll'])
                    ->setRejectAllLabel($defaults['rejectAll'])
                    ->setCustomiseLabel($defaults['customise'])
                    ->setSaveLabel($defaults['save'])
                    ->setWidgetLabel($defaults['widget'])
                    ->setPolicyLinkText($defaults['policyLinkText']),
            );
        }

        $em->flush();
        Catalog::flush();
    }
}
```

- [ ] **Step 4: Create the uninstall element**

Create `elements/dashboard/uninstall.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>

<div class="form-group">
    <p><?php echo t('Are you sure you want to uninstall this package?') ?></p>
</div>
<div class="checkbox">
    <label>
        <input type="checkbox" name="purge_database_tables" value="1">
        <?php echo t('Also delete all banner text, categories, services and settings (this cannot be undone)') ?>
    </label>
</div>
```

- [ ] **Step 5: Wire the package controller**

In `controller.php`, make these edits (keep every existing docblock and property as it is):

1. Replace the `use` block at the top:

```php
use Concrete\Core\Entity\Package;
use ClassKit\Package\PackageController;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Events\ConsentManager;
use Concrete\Core\Support\Facade\{Events, Route};
use CookieManagerKit\Support\{Catalog, Installer};
use ClassKit\Package\Traits\{AttributeTrait, BlockTrait, PageTrait};
use CookieManagerKit\Consent\{ConsentRecorder, NullConsentRecorder};
```

2. Set the version: `protected $pkgVersion = '0.1.0';`

3. Add this property after `$pkgAutoloaderRegistries`:

```php
    /**
     * Tables dropped on uninstall when "purge database tables" is ticked, children before parents.
     *
     * @var array
     */
    protected $databaseTables = [
        'cmkServiceContents',
        'cmkCategoryContents',
        'cmkServices',
        'cmkCategories',
        'cmkBannerContents',
        'cmkSettings',
    ];
```

4. Replace the empty `installOrUpgrade`, `registerRoutes` and `registerEvents` methods with:

```php
    public function installOrUpgrade(Package $pkg)
    {
        $this->addSinglePage('/dashboard/cookie_manager', $pkg, t('Cookie Manager'), t('Manage the cookie banner, categories and appearance'));
        $this->addSinglePage('/dashboard/cookie_manager/banner', $pkg, t('Banner'), t('Banner text for each language'));
        $this->addSinglePage('/dashboard/cookie_manager/categories', $pkg, t('Categories'), t('Consent categories'));
        $this->addSinglePage('/dashboard/cookie_manager/services', $pkg, t('Services'), t('Third-party services listed to visitors'));
        $this->addSinglePage('/dashboard/cookie_manager/appearance', $pkg, t('Appearance'), t('Colours, position and layout'));

        Installer::seed(app(EntityManagerInterface::class), Catalog::defaultLocale());
    }

    public function registerRoutes(): void
    {
        // Public and read-only: identical for every visitor, so it is cacheable.
        Route::register(
            '/cookie-manager/config/{locale}.json',
            '\CookieManagerKit\Ajax\Config::serve',
            'cookie_manager_kit.config',
            ['locale' => '[A-Za-z0-9_\-]{1,32}'],
            [],
            '',
            [],
            ['GET', 'HEAD'],
        );

        // Public: pages are cached and anonymous, so it is defended by origin, schema and rate limit. See Ajax\Consent.
        Route::register(
            '/cookie-manager/consent',
            '\CookieManagerKit\Ajax\Consent::record',
            'cookie_manager_kit.consent',
            [],
            [],
            '',
            [],
            ['POST'],
        );
    }

    public function registerEvents(): void
    {
        // Phase 3 rebinds this to a recorder that writes the audit log.
        app()->bind(ConsentRecorder::class, NullConsentRecorder::class);

        Events::addListener('on_before_render', function () {
            ConsentManager::onBeforeRender();
        });
    }
```

(`PackageController::on_start()` already calls `registerRoutes()` and `registerEvents()`.)

- [ ] **Step 6: Run the checks**

Run: `php -l controller.php && composer test && composer typecheck`
Expected: `No syntax errors detected`; tests PASS; no new PHPStan errors for the changed files.

- [ ] **Step 7: Install smoke test on a real site (ask first)**

Ask the user which local ConcreteCMS 9.5 site to install the package into and how it is linked (for example a symlink in that site's `packages/`). Do not guess and do not install anywhere unasked. If no site is available yet, say so and defer this step to Task 18; do not mark Task 13 as verified.

On the chosen site, install the package from the dashboard (`/dashboard/extend/install`), then check:

```bash
# tables exist (adjust the DB access to that site)
mysql -e "SHOW TABLES LIKE 'cmk%'" <database>
# four default categories and the settings row were seeded
mysql -e "SELECT handle, necessary FROM cmkCategories ORDER BY sortOrder; SELECT COUNT(*) FROM cmkSettings" <database>
# the public endpoints respond (replace the host)
curl -si https://<site>/cookie-manager/config/en_GB.json | head -20
curl -si -X POST https://<site>/cookie-manager/consent -H 'Content-Type: application/json' -d '{"v":1,"c":[]}' | head -12
```

Expected: five `cmk*` tables; categories `necessary(1) preferences analytics marketing`; the config request returns `200` JSON with `X-Content-Type-Options: nosniff`, an `ETag`, and the four categories (not a Concrete HTML 404 page); the POST without an `Origin` header returns `403 {"error":"invalid"}`. If the config route 404s, the `{locale}.json` route pattern is not matching: adjust the pattern in `registerRoutes()` (for example register `/cookie-manager/config/{locale}` and keep `ConfigRequest` parsing `.json` out) and update `Task 8` accordingly before continuing.

- [ ] **Step 8: Commit**

```bash
git add controller.php src/Support/Installer.php elements/dashboard/uninstall.php tests/Support/InstallerTest.php
git commit -m "feat: wire install, seed, routes and events into the package controller

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Dashboard foundation and static security tests

**Files:**
- Create: `src/Support/SiteLocales.php`, `controllers/single_page/dashboard/cookie_manager.php`, `single_pages/dashboard/cookie_manager.php`
- Test: `tests/ViewEscapingTest.php`, `tests/ControllerSecurityTest.php`

**Interfaces:**
- Consumes: Concrete's `app('site')`.
- Produces:
  - `SiteLocales::options(): array<string, string>` (locale ID => label, for the site being edited), `SiteLocales::defaultLocale(): string`.
  - Two always-on static checks that every later dashboard task must keep green: **(1)** every value echoed in `single_pages/` and `elements/` is encoded for its context; **(2)** every dashboard controller `save`/`delete` validates the CSRF token before it writes, and never redirects to user input (spec §8.2, "Enforcement").
  - Controller class naming used throughout: file `controllers/single_page/dashboard/cookie_manager/<page>.php` declares `Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager\<Page>`; the view is `single_pages/dashboard/cookie_manager/<page>.php`. Views alias the encoder as `use CookieManagerKit\Support\Output as O;`.

- [ ] **Step 1: Write the failing static tests**

Create `tests/ViewEscapingTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec §8.2: every dynamic value in a dashboard view is encoded for its context. An echoed expression is only
 * accepted when it is a string literal, a literal-only translation, a cast integer, the CSRF token field, a
 * Concrete form helper call, or ONE call to the package encoder. Everything else (a bare variable, a
 * concatenation that mixes raw and encoded parts, t() with variables) is reported.
 */
final class ViewEscapingTest extends TestCase
{
    private const ENCODER_PREFIXES = ['O::e(', 'O::json(', 'O::url('];
    private const HELPER_PREFIXES = ['$form->submit(', '$form->select(', '$form->hidden(', '$form->text(', '$form->textarea(', '$form->checkbox(', '$pageSelector->selectPage('];

    /**
     * @return list<string>
     */
    private static function views(): array
    {
        $files = [];
        $root = dirname(__DIR__);

        foreach (['single_pages', 'elements'] as $folder) {
            if (!is_dir($root . '/' . $folder)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string> every expression handed to echo or <?=
     */
    public static function expressions(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_ECHO, T_OPEN_TAG_WITH_ECHO], true)) {
                continue;
            }

            $expression = '';
            $depth = 0;

            for ($i++; $i < $count; $i++) {
                $token = $tokens[$i];

                if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                    break;
                }

                if (!is_array($token)) {
                    if (in_array($token, ['(', '[', '{'], true)) {
                        $depth++;
                    } elseif (in_array($token, [')', ']', '}'], true)) {
                        $depth--;
                    } elseif ($depth === 0 && ($token === ';' || $token === ',')) {
                        $found[] = trim($expression);
                        $expression = '';

                        if ($token === ';') {
                            break;
                        }

                        continue;
                    }
                }

                $expression .= is_array($token) ? $token[1] : $token;
            }

            if (trim($expression) !== '') {
                $found[] = trim($expression);
            }
        }

        return $found;
    }

    private static function isSingleCall(string $expression): bool
    {
        $open = strpos($expression, '(');

        if ($open === false) {
            return false;
        }

        $length = strlen($expression);
        $depth = 0;
        $quote = null;

        for ($i = $open; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i === $length - 1;
                }
            }
        }

        return false;
    }

    public static function isSafe(string $expression): bool
    {
        $expression = trim($expression);

        if ($expression === '') {
            return true;
        }

        $patterns = [
            '~^(?:\'[^\']*\'|"[^"$\\\\{]*")\z~s',                                  // string literal
            '~^t\((?:\'(?:[^\'\\\\]|\\\\.)*\'|"[^"$\\\\{]*")\)\z~s',               // t('literal') only
            '~^\(int\)\s*\$[A-Za-z_]\w*(?:(?:->|::)\w+(?:\(\))?)*\z~',             // (int) $a->b()
            '~^\$token->output\(\'[a-z_]+\'\)\z~',                                  // CSRF field
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $expression) === 1) {
                return true;
            }
        }

        foreach ([...self::ENCODER_PREFIXES, ...self::HELPER_PREFIXES] as $prefix) {
            if (str_starts_with($expression, $prefix) && self::isSingleCall($expression)) {
                return true;
            }
        }

        return false;
    }

    public function testEveryEchoedValueInAViewIsEncodedForItsContext(): void
    {
        $offenders = [];

        foreach (self::views() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::expressions($source) as $expression) {
                if (!self::isSafe($expression)) {
                    $offenders[] = substr($file, strlen(dirname(__DIR__)) + 1) . ': ' . $expression;
                }
            }
        }

        $this->assertSame([], $offenders, "Encode with O::e() / O::json() (see spec §8.2) or use a literal:\n" . implode("\n", $offenders));
    }

    public function testViewsNeverUseOtherOutputConstructs(): void
    {
        $offenders = [];

        foreach (self::views() as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_PRINT, T_EVAL, T_EXIT], true)) {
                    $offenders[] = substr($file, strlen(dirname(__DIR__)) + 1) . ': ' . $token[1];
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function checkerProvider(): array
    {
        return [
            'encoded variable' => ['O::e($name)', true],
            'encoded expression' => ['O::e($a . $b)', true],
            'encoded translation with variables' => ['O::e(t(\'Hello %s\', $name))', true],
            'json' => ['O::json([\'a\' => $b])', true],
            'literal translation' => ['t(\'Save (draft)\')', true],
            'csrf field' => ['$token->output(\'submit\')', true],
            'int cast of a getter' => ['(int) $settings->getRadius()', true],
            'int cast of a constant' => ['(int) $settings::MIN_LIFETIME_DAYS', true],
            'string literal' => ["'<br>'", true],
            'form helper' => ['$form->submit(\'save\', t(\'Save\'), [\'class\' => \'btn\'])', true],
            'bare variable' => ['$name', false],
            'raw then encoded' => ['$a . O::e($b)', false],
            'encoded then raw' => ['O::e($a) . $b', false],
            'translation with variable' => ['t(\'Hello %s\', $name)', false],
            'cast then concatenation' => ['(int) $a . $b', false],
            'interpolated string' => ['"Hello $name"', false],
            'unwrapped action url' => ['$view->action(\'save\')', false],
            'getter without encoding' => ['$category->getTitle()', false],
        ];
    }

    #[DataProvider('checkerProvider')]
    public function testTheCheckerItself(string $expression, bool $safe): void
    {
        $this->assertSame($safe, self::isSafe($expression), $expression);
    }

    public function testExpressionExtractionHandlesShortTagsMultilineAndLists(): void
    {
        $source = "<?php echo O::e(\n    \$a\n) ?>\n<?= \$b ?>\n<?php echo 'x', \$c; ?>";

        $this->assertSame(["O::e(\n    \$a\n)", '$b', "'x'", '$c'], self::expressions($source));
    }
}
```

Create `tests/ControllerSecurityTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Spec §8.2: every dashboard action that changes data checks the request method and a valid CSRF token before it
 * touches the database, and no redirect target comes from the request.
 */
final class ControllerSecurityTest extends TestCase
{
    private const WRITE_ACTIONS = ['save', 'delete'];

    /**
     * @return list<string>
     */
    private static function controllers(): array
    {
        $files = glob(dirname(__DIR__) . '/controllers/single_page/dashboard/cookie_manager/*.php') ?: [];
        sort($files);

        return $files;
    }

    private static function body(string $source, string $method): ?string
    {
        if (preg_match('/function\s+' . $method . '\s*\([^)]*\)[^{]*\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}' && --$depth === 0) {
                return substr($source, $start, $i - $start);
            }
        }

        return null;
    }

    public function testThereAreDashboardControllersToCheck(): void
    {
        $this->assertNotSame([], self::controllers());
    }

    public function testEveryWriteActionChecksMethodAndTokenBeforeWriting(): void
    {
        $offenders = [];

        foreach (self::controllers() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::WRITE_ACTIONS as $action) {
                $body = self::body($source, $action);

                if ($body === null) {
                    continue;
                }

                $name = basename($file) . '::' . $action;
                $token = strpos($body, "\$this->token->validate('submit')");
                $post = strpos($body, '$this->request->isPost()');
                $write = PHP_INT_MAX;

                foreach (['->persist(', '->remove(', '->flush(', '->bumpPolicyVersion('] as $call) {
                    $at = strpos($body, $call);
                    $write = $at === false ? $write : min($write, $at);
                }

                if ($token === false) {
                    $offenders[] = $name . ' does not validate the CSRF token';
                } elseif ($token > $write) {
                    $offenders[] = $name . ' writes before validating the CSRF token';
                }

                if ($post === false) {
                    $offenders[] = $name . ' does not require POST';
                } elseif ($post > $write) {
                    $offenders[] = $name . ' writes before checking the method';
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testNoRedirectTargetComesFromTheRequest(): void
    {
        $offenders = [];

        foreach (self::controllers() as $file) {
            if (preg_match('/redirect\s*\([^;]*(?:->post\(|->get\(|->request|\$_(?:GET|POST|REQUEST)|\$id)/i', (string) file_get_contents($file)) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'redirect to fixed routes only');
    }

    public function testControllersNeverEchoOrRenderRequestDataDirectly(): void
    {
        $offenders = [];

        foreach (self::controllers() as $file) {
            if (preg_match('/\b(?:echo|print|die|exit)\b/', (string) file_get_contents($file)) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'controllers set view variables and flash constant messages only');
    }
}
```

- [ ] **Step 2: Run to verify the view test passes vacuously and the controller test fails**

Run: `composer test`
Expected: `ViewEscapingTest` PASSES against the existing `elements/dashboard/uninstall.php`; `ControllerSecurityTest::testThereAreDashboardControllersToCheck` FAILS (no controllers yet).

- [ ] **Step 3: Implement `SiteLocales`**

Create `src/Support/SiteLocales.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * The locales of the site being edited in the dashboard, for the per-language tabs.
 */
final class SiteLocales
{
    /**
     * @return array<string, string> locale ID => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (app('site')->getActiveSiteForEditing()->getLocales() as $locale) {
            $options[$locale->getLocale()] = $locale->getLanguageText() . ' (' . $locale->getLocale() . ')';
        }

        return $options;
    }

    public static function defaultLocale(): string
    {
        $locale = app('site')->getActiveSiteForEditing()->getDefaultLocale();

        return $locale ? $locale->getLocale() : (array_key_first(self::options()) ?? 'en_US');
    }
}
```

- [ ] **Step 4: Create the parent page**

Create `controllers/single_page/dashboard/cookie_manager.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard;

use Concrete\Core\Page\Controller\DashboardPageController;

class CookieManager extends DashboardPageController
{
    public function view()
    {
        $this->buildRedirect('/dashboard/cookie_manager/banner')->send();
    }
}
```

Create `single_pages/dashboard/cookie_manager.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');
```

Create the folder placeholders by creating the sub-page controllers in the next tasks. For this task only, add a minimal controller so the security test has something to inspect: create `controllers/single_page/dashboard/cookie_manager/banner.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use Concrete\Core\Page\Controller\DashboardPageController;

class Banner extends DashboardPageController
{
    public function view() {}
}
```

and `single_pages/dashboard/cookie_manager/banner.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');
```

(Task 15 replaces both files.)

- [ ] **Step 5: Run to verify everything passes**

Run: `composer test`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Support/SiteLocales.php controllers single_pages tests/ViewEscapingTest.php tests/ControllerSecurityTest.php
git commit -m "feat: add dashboard foundation and static output-encoding and CSRF checks

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Banner content page

**Files:**
- Modify (replace): `controllers/single_page/dashboard/cookie_manager/banner.php`, `single_pages/dashboard/cookie_manager/banner.php`

**Interfaces:**
- Consumes: `BannerContent`, `Settings` (Tasks 3, 4), `Catalog::flush`, `Catalog::DEFAULTS` (Task 6), `SiteLocales` (Task 14), `Text`, `StyleRules` (Tasks 1, 3), `Output as O`.
- Produces: `/dashboard/cookie_manager/banner`: per-locale tabs for title, body, five button/widget labels, policy link text and policy page; "Behaviour" fieldset with `lifetimeDays`, `widgetEnabled` and `askAgain` (bumps the policy version so every visitor is asked again). Form field names: `content[<locale>][title|body|acceptAll|rejectAll|customise|save|widget|policyLinkText]`, `policyPage_<locale>`, `lifetimeDays`, `widgetEnabled`, `askAgain`.

- [ ] **Step 1: Replace the controller**

Replace `controllers/single_page/dashboard/cookie_manager/banner.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use Concrete\Core\Page\Page;
use CookieManagerKit\Support\{Catalog, SiteLocales, StyleRules, Text};
use CookieManagerKit\Entity\{BannerContent, Settings};
use Concrete\Core\Page\Controller\DashboardPageController;

class Banner extends DashboardPageController
{
    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private const URL = '/dashboard/cookie_manager/banner';

    public function view()
    {
        $contents = [];

        foreach ($this->entityManager->getRepository(BannerContent::class)->findAll() as $row) {
            $contents[$row->getLocale()] = $row;
        }

        $this->set('locales', SiteLocales::options());
        $this->set('defaultLocale', SiteLocales::defaultLocale());
        $this->set('contents', $contents);
        $this->set('settings', Settings::get());
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $lifetime = $this->post('lifetimeDays');

        if (!StyleRules::isValidLifetime($lifetime)) {
            $this->flash('error', t('Remember a visitor\'s choice for a whole number of days between %s and %s.', StyleRules::MIN_LIFETIME_DAYS, StyleRules::MAX_LIFETIME_DAYS));

            return $this->buildRedirect(self::URL)->send();
        }

        $locales = SiteLocales::options();
        $default = SiteLocales::defaultLocale();
        $posted = $this->post('content');
        $posted = is_array($posted) ? $posted : [];
        $values = [];

        foreach (array_keys($locales) as $locale) {
            $input = is_array($posted[$locale] ?? null) ? $posted[$locale] : [];
            $values[$locale] = [
                'title' => Text::plain($input['title'] ?? ''),
                'body' => Text::multiline($input['body'] ?? ''),
                'acceptAll' => Text::plain($input['acceptAll'] ?? '', 64),
                'rejectAll' => Text::plain($input['rejectAll'] ?? '', 64),
                'customise' => Text::plain($input['customise'] ?? '', 64),
                'save' => Text::plain($input['save'] ?? '', 64),
                'widget' => Text::plain($input['widget'] ?? '', 64),
                'policyLinkText' => Text::plain($input['policyLinkText'] ?? ''),
                'policyPage' => $this->existingPageId($this->post('policyPage_' . $locale)),
            ];
        }

        if (!isset($values[$default]) || $values[$default]['title'] === '' || $values[$default]['body'] === '') {
            $this->flash('error', t('The default language needs a heading and a message.'));

            return $this->buildRedirect(self::URL)->send();
        }

        foreach ($values as $locale => $value) {
            $row = $this->entityManager->getRepository(BannerContent::class)->findOneBy(['locale' => $locale]);
            $blank = $value['policyPage'] === null && implode('', array_diff_key($value, ['policyPage' => 1])) === '';

            // A language with nothing filled in is removed, so visitors in that language get the default language.
            if ($blank && $locale !== $default) {
                if ($row) {
                    $this->entityManager->remove($row);
                }

                continue;
            }

            $row ??= (new BannerContent())->setLocale($locale);
            $row->setTitle($value['title'])
                ->setBody($value['body'])
                ->setAcceptAllLabel($value['acceptAll'])
                ->setRejectAllLabel($value['rejectAll'])
                ->setCustomiseLabel($value['customise'])
                ->setSaveLabel($value['save'])
                ->setWidgetLabel($value['widget'])
                ->setPolicyLinkText($value['policyLinkText'])
                ->setPolicyPageId($value['policyPage']);

            $this->entityManager->persist($row);
        }

        $settings = Settings::get();
        $settings->setLifetimeDays($lifetime)->setWidgetEnabled($this->post('widgetEnabled') !== null);

        if ($this->post('askAgain') !== null) {
            $settings->bumpPolicyVersion();
        }

        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Banner saved.'));

        return $this->buildRedirect(self::URL)->send();
    }

    private function existingPageId(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $cid = preg_match('/^\d+\z/', (string) $value) === 1 ? (int) $value : 0;

        if ($cid < 1) {
            return null;
        }

        $page = Page::getByID($cid);

        return $page && !$page->isError() ? $cid : null;
    }
}
```

- [ ] **Step 2: Replace the view**

Replace `single_pages/dashboard/cookie_manager/banner.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Catalog;
use CookieManagerKit\Support\Output as O;

/** @var array<string, string> $locales */
/** @var string $defaultLocale */
/** @var array<string, CookieManagerKit\Entity\BannerContent> $contents */
/** @var CookieManagerKit\Entity\Settings $settings */

$pageSelector = app('helper/form/page_selector');
$defaults = Catalog::DEFAULTS;
$labelFields = [
    'acceptAll' => [t('"Accept all" button'), 'getAcceptAllLabel'],
    'rejectAll' => [t('"Reject all" button'), 'getRejectAllLabel'],
    'customise' => [t('"Customise" button'), 'getCustomiseLabel'],
    'save' => [t('"Save my choices" button'), 'getSaveLabel'],
    'widget' => [t('Floating settings button'), 'getWidgetLabel'],
];
?>
<form method="post" action="<?php echo O::e($view->action('save')) ?>">
    <?php echo $token->output('submit') ?>

    <p class="text-muted"><?php echo t('Plain text only. Leave a language empty to use the default language for those visitors. Empty button labels use built-in English text.') ?></p>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <?php $index = 0; foreach ($locales as $locale => $label) { $tabId = 'cmk-tab-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale); ?>
            <li class="nav-item" role="presentation">
                <button type="button" class="nav-link <?php echo O::e($index === 0 ? 'active' : '') ?>" data-bs-toggle="tab" data-bs-target="#<?php echo O::e($tabId) ?>" role="tab">
                    <?php echo O::e($label) ?>
                    <?php if ($locale === $defaultLocale) { ?><span class="badge text-bg-secondary"><?php echo t('Default') ?></span><?php } ?>
                </button>
            </li>
        <?php $index++; } ?>
    </ul>

    <div class="tab-content mb-4">
        <?php $index = 0; foreach ($locales as $locale => $label) {
            $tabId = 'cmk-tab-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale);
            $row = $contents[$locale] ?? null;
            $name = 'content[' . $locale . ']';
            $isDefault = $locale === $defaultLocale;
            ?>
            <div class="tab-pane fade <?php echo O::e($index === 0 ? 'show active' : '') ?>" id="<?php echo O::e($tabId) ?>" role="tabpanel">
                <div class="form-group mb-3">
                    <label class="form-label" for="<?php echo O::e($tabId) ?>-title"><?php echo t('Heading') ?></label>
                    <input type="text" class="form-control" maxlength="255" id="<?php echo O::e($tabId) ?>-title" name="<?php echo O::e($name . '[title]') ?>" value="<?php echo O::e($row ? $row->getTitle() : '') ?>" placeholder="<?php echo O::e($defaults['title']) ?>" <?php echo O::e($isDefault ? 'required' : '') ?>>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="<?php echo O::e($tabId) ?>-body"><?php echo t('Message') ?></label>
                    <textarea class="form-control" rows="4" maxlength="2000" id="<?php echo O::e($tabId) ?>-body" name="<?php echo O::e($name . '[body]') ?>" placeholder="<?php echo O::e($defaults['body']) ?>" <?php echo O::e($isDefault ? 'required' : '') ?>><?php echo O::e($row ? $row->getBody() : '') ?></textarea>
                </div>

                <div class="row">
                    <?php foreach ($labelFields as $key => [$fieldLabel, $getter]) { ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="<?php echo O::e($tabId . '-' . $key) ?>"><?php echo O::e($fieldLabel) ?></label>
                            <input type="text" class="form-control" maxlength="64" id="<?php echo O::e($tabId . '-' . $key) ?>" name="<?php echo O::e($name . '[' . $key . ']') ?>" value="<?php echo O::e($row ? $row->{$getter}() : '') ?>" placeholder="<?php echo O::e($defaults[$key]) ?>">
                        </div>
                    <?php } ?>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="<?php echo O::e($tabId) ?>-policyLinkText"><?php echo t('Cookie policy link text') ?></label>
                        <input type="text" class="form-control" maxlength="255" id="<?php echo O::e($tabId) ?>-policyLinkText" name="<?php echo O::e($name . '[policyLinkText]') ?>" value="<?php echo O::e($row ? $row->getPolicyLinkText() : '') ?>" placeholder="<?php echo O::e($defaults['policyLinkText']) ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label"><?php echo t('Cookie policy page') ?></label>
                        <?php echo $pageSelector->selectPage('policyPage_' . $locale, $row ? $row->getPolicyPageId() : null) ?>
                    </div>
                </div>
            </div>
        <?php $index++; } ?>
    </div>

    <fieldset>
        <legend><?php echo t('Behaviour') ?></legend>

        <div class="form-group mb-3">
            <label class="form-label" for="lifetimeDays"><?php echo t('Remember a visitor\'s choice for (days)') ?></label>
            <input type="number" class="form-control" style="max-width: 12rem" id="lifetimeDays" name="lifetimeDays" min="30" max="395" step="1" value="<?php echo O::e($settings->getLifetimeDays()) ?>">
            <div class="help-block form-text"><?php echo t('Between 30 and 395 days (13 months).') ?></div>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="widgetEnabled" name="widgetEnabled" value="1" <?php echo O::e($settings->isWidgetEnabled() ? 'checked' : '') ?>>
            <label class="form-check-label" for="widgetEnabled"><?php echo t('Show a floating button so visitors can change their choice at any time') ?></label>
            <div class="help-block form-text"><?php echo t('You can also add the attribute data-cmk-open to any link or button to open the cookie settings.') ?></div>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="askAgain" name="askAgain" value="1">
            <label class="form-check-label" for="askAgain"><?php echo t('Ask every visitor again') ?></label>
            <div class="help-block form-text"><?php echo O::e(t('Tick this when your cookie policy changes. Current policy version: %s.', $settings->getPolicyVersion())) ?></div>
        </div>
    </fieldset>

    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <?php echo $form->submit('save', t('Save Banner'), ['class' => 'btn btn-primary float-end']) ?>
        </div>
    </div>
</form>
```

`t()` with a variable argument is only accepted inside one `O::e(...)` call, as in the policy-version line above: the translated text contains no HTML, so encoding the whole string is correct.

- [ ] **Step 3: Run the static checks**

Run: `composer test`
Expected: PASS, including `ViewEscapingTest` (no unencoded echo) and `ControllerSecurityTest` (`save` validates method and token before `persist`/`flush`/`bumpPolicyVersion`, and has no `echo`, `print`, `die` or `exit`).

- [ ] **Step 4: Verify in the browser (needs the installed site from Task 13 Step 7)**

If no site is available, say so explicitly and leave this step open for Task 18; do not claim the page works.

On the site, log in as an administrator and open `/dashboard/cookie_manager/banner`. Check, and record what you saw:
1. One tab per site locale; the default one is marked "Default" and its heading and message are required.
2. Save with the defaults: success message, values persist after reload.
3. Enter `<script>alert(1)</script>"><img src=x onerror=alert(1)>` as the heading and body of every language: save, reload. Expected: the text appears as plain text in the form (tags stripped by `Text::plain`), no alert, no console errors. Repeat in the policy link text.
4. Save with lifetime `5` and `abc`: error flash, nothing saved. Tick "Ask every visitor again": policy version increments by one.
5. Clear a non-default language completely and save: that tab is empty afterwards and the front end shows the default language for that locale (checked in Task 18).
6. Browser console: no errors on load or after saving.

- [ ] **Step 5: Commit**

```bash
git add controllers single_pages
git commit -m "feat: add multilingual banner content page

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 16: Categories and services pages

**Files:**
- Create: `controllers/single_page/dashboard/cookie_manager/categories.php`, `controllers/single_page/dashboard/cookie_manager/services.php`, `single_pages/dashboard/cookie_manager/categories.php`, `single_pages/dashboard/cookie_manager/services.php`

**Interfaces:**
- Consumes: `Category`, `CategoryContent`, `Service`, `ServiceContent` (Task 4), `EssentialCookies::names()` (Task 2), `SiteLocales`, `Catalog::flush`, `Text`, `Output as O`.
- Produces:
  - `/dashboard/cookie_manager/categories` (actions `view` list, `add`, `edit/<id>`, `save`, `delete`). The list shows the always-allowed ConcreteCMS cookies read-only ("Required by ConcreteCMS"). `necessary` cannot be deleted and its handle never changes; custom categories can be deleted only when they have no services.
  - `/dashboard/cookie_manager/services` (same actions). Service domains are validated hostnames.
  - Form fields: categories `id`, `handle` (new only), `sortOrder`, `content[<locale>][title|description]`; services `id`, `name`, `vendor`, `categoryID`, `domains`, `active`, `content[<locale>][description]`.

- [ ] **Step 1: Create the categories controller**

Create `controllers/single_page/dashboard/cookie_manager/categories.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Entity\Category;
use CookieManagerKit\Support\{Catalog, SiteLocales, Text};
use Concrete\Core\Page\Controller\DashboardPageController;

class Categories extends DashboardPageController
{
    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private const URL = '/dashboard/cookie_manager/categories';

    private function prepare(string $mode, ?Category $category = null): void
    {
        $this->set('mode', $mode);
        $this->set('category', $category);
        $this->set('locales', SiteLocales::options());
        $this->set('defaultLocale', SiteLocales::defaultLocale());
        $this->set('categories', $this->entityManager->getRepository(Category::class)->findBy([], ['sortOrder' => 'ASC', 'handle' => 'ASC']));
    }

    private function find(mixed $id): ?Category
    {
        if (!is_string($id) && !is_int($id)) {
            return null;
        }

        if (preg_match('/^\d+\z/', (string) $id) !== 1) {
            return null;
        }

        $category = $this->entityManager->getRepository(Category::class)->find((int) $id);

        return $category instanceof Category ? $category : null;
    }

    public function view()
    {
        $this->prepare('list');
    }

    public function add()
    {
        $this->prepare('form');
    }

    public function edit($id = null)
    {
        $category = $this->find($id);

        if ($category === null) {
            $this->flash('error', t('That category could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->prepare('form', $category);
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $id = $this->post('id');
        $category = $id !== null && $id !== '' ? $this->find($id) : null;

        if ($id !== null && $id !== '' && $category === null) {
            $this->flash('error', t('That category could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $locales = SiteLocales::options();
        $default = SiteLocales::defaultLocale();
        $posted = $this->post('content');
        $posted = is_array($posted) ? $posted : [];
        $content = [];

        foreach (array_keys($locales) as $locale) {
            $input = is_array($posted[$locale] ?? null) ? $posted[$locale] : [];
            $content[$locale] = [Text::plain($input['title'] ?? ''), Text::multiline($input['description'] ?? '', 1000)];
        }

        if (!isset($content[$default]) || $content[$default][0] === '') {
            $this->flash('error', t('The default language needs a title.'));

            return $this->buildRedirect($category ? self::URL . '/edit/' . $category->getID() : self::URL . '/add')->send();
        }

        if ($category === null) {
            $handle = $this->post('handle');

            if (!Text::isHandle($handle) || $handle === Category::NECESSARY || $this->entityManager->getRepository(Category::class)->findOneBy(['handle' => $handle])) {
                $this->flash('error', t('Choose a handle that is not already used: lowercase letters, numbers, hyphens and underscores, up to 32 characters.'));

                return $this->buildRedirect(self::URL . '/add')->send();
            }

            $category = (new Category())->setHandle($handle);
        }

        $category->setSortOrder($this->post('sortOrder'));

        foreach ($content as $locale => [$title, $description]) {
            $existing = $category->getContent($locale);

            // A language with nothing filled in is removed, so visitors in that language get the default language.
            if ($title === '' && $description === '' && $locale !== $default) {
                if ($existing) {
                    $category->getContents()->removeElement($existing);
                }

                continue;
            }

            $category->setContent($locale, $title, $description);
        }

        $this->entityManager->persist($category);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Category saved.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $category = $this->find($this->post('id'));

        if ($category === null || !$category->canBeDeleted()) {
            $this->flash('error', t('This category cannot be deleted. The necessary category is always kept, and a category with services must have them moved or removed first.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Category deleted.'));

        return $this->buildRedirect(self::URL)->send();
    }
}
```

- [ ] **Step 2: Create the categories view**

Create `single_pages/dashboard/cookie_manager/categories.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\EssentialCookies;
use CookieManagerKit\Support\Output as O;

/** @var string $mode */
/** @var CookieManagerKit\Entity\Category|null $category */
/** @var CookieManagerKit\Entity\Category[] $categories */
/** @var array<string, string> $locales */
/** @var string $defaultLocale */
?>
<?php if ($mode === 'list') { ?>
    <div class="mb-3 clearfix">
        <a href="<?php echo O::e($view->action('add')) ?>" class="btn btn-primary float-end"><?php echo t('Add Category') ?></a>
    </div>

    <table class="table table-striped">
        <thead>
            <tr>
                <th><?php echo t('Title') ?></th>
                <th><?php echo t('Handle') ?></th>
                <th><?php echo t('Order') ?></th>
                <th><?php echo t('Services') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $row) {
                $content = $row->getContent($defaultLocale);
                ?>
                <tr>
                    <td>
                        <a href="<?php echo O::e($view->action('edit', $row->getID())) ?>"><?php echo O::e($content ? $content->getTitle() : $row->getHandle()) ?></a>
                        <?php if ($row->isNecessary()) { ?><span class="badge text-bg-secondary"><?php echo t('Always on') ?></span><?php } ?>
                    </td>
                    <td><code><?php echo O::e($row->getHandle()) ?></code></td>
                    <td><?php echo O::e($row->getSortOrder()) ?></td>
                    <td><?php echo O::e($row->getServices()->count()) ?></td>
                    <td class="text-end">
                        <?php if ($row->canBeDeleted()) { ?>
                            <form method="post" action="<?php echo O::e($view->action('delete')) ?>" class="d-inline">
                                <?php echo $token->output('submit') ?>
                                <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo t('Delete') ?></button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <div class="card mt-4">
        <div class="card-body">
            <h5 class="card-title"><?php echo t('Required by ConcreteCMS') ?></h5>
            <p class="card-text"><?php echo t('These cookies are always allowed. They keep you and your visitors signed in and make the dashboard work, so they are never blocked, whatever a visitor chooses.') ?></p>
            <p class="card-text">
                <?php foreach (EssentialCookies::names() as $name) { ?>
                    <code class="me-2"><?php echo O::e($name) ?></code>
                <?php } ?>
            </p>
        </div>
    </div>
<?php } else { ?>
    <?php
    $isNew = $category === null;
    $isNecessary = !$isNew && $category->isNecessary();
    ?>
    <form method="post" action="<?php echo O::e($view->action('save')) ?>">
        <?php echo $token->output('submit') ?>
        <?php if (!$isNew) { ?><input type="hidden" name="id" value="<?php echo O::e($category->getID()) ?>"><?php } ?>

        <?php if ($isNecessary) { ?>
            <div class="alert alert-info"><?php echo t('This category is always on. It holds the cookies the site needs to work, so visitors cannot refuse it and it cannot be deleted.') ?></div>
        <?php } ?>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label" for="handle"><?php echo t('Handle') ?></label>
                <?php if ($isNew) { ?>
                    <input type="text" class="form-control" id="handle" name="handle" maxlength="32" pattern="[a-z0-9_\-]{1,32}" required>
                    <div class="help-block form-text"><?php echo t('Used in the data-cmk-category attribute. Lowercase letters, numbers, hyphens and underscores. It cannot be changed later.') ?></div>
                <?php } else { ?>
                    <input type="text" class="form-control" id="handle" value="<?php echo O::e($category->getHandle()) ?>" disabled>
                <?php } ?>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="sortOrder"><?php echo t('Order') ?></label>
                <input type="number" class="form-control" id="sortOrder" name="sortOrder" min="0" max="9999" step="1" value="<?php echo O::e($isNew ? 40 : $category->getSortOrder()) ?>">
            </div>
        </div>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <?php $index = 0; foreach ($locales as $locale => $label) { $tabId = 'cmk-cat-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale); ?>
                <li class="nav-item" role="presentation">
                    <button type="button" class="nav-link <?php echo O::e($index === 0 ? 'active' : '') ?>" data-bs-toggle="tab" data-bs-target="#<?php echo O::e($tabId) ?>" role="tab">
                        <?php echo O::e($label) ?>
                        <?php if ($locale === $defaultLocale) { ?><span class="badge text-bg-secondary"><?php echo t('Default') ?></span><?php } ?>
                    </button>
                </li>
            <?php $index++; } ?>
        </ul>

        <div class="tab-content mb-4">
            <?php $index = 0; foreach ($locales as $locale => $label) {
                $tabId = 'cmk-cat-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale);
                $row = $isNew ? null : $category->getContent($locale);
                $isDefault = $locale === $defaultLocale;
                ?>
                <div class="tab-pane fade <?php echo O::e($index === 0 ? 'show active' : '') ?>" id="<?php echo O::e($tabId) ?>" role="tabpanel">
                    <div class="form-group mb-3">
                        <label class="form-label" for="<?php echo O::e($tabId) ?>-title"><?php echo t('Title') ?></label>
                        <input type="text" class="form-control" maxlength="255" id="<?php echo O::e($tabId) ?>-title" name="<?php echo O::e('content[' . $locale . '][title]') ?>" value="<?php echo O::e($row ? $row->getTitle() : '') ?>" <?php echo O::e($isDefault ? 'required' : '') ?>>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label" for="<?php echo O::e($tabId) ?>-description"><?php echo t('Description') ?></label>
                        <textarea class="form-control" rows="3" maxlength="1000" id="<?php echo O::e($tabId) ?>-description" name="<?php echo O::e('content[' . $locale . '][description]') ?>"><?php echo O::e($row ? $row->getDescription() : '') ?></textarea>
                    </div>
                </div>
            <?php $index++; } ?>
        </div>

        <?php if ($isNecessary) { ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title"><?php echo t('Required by ConcreteCMS') ?></h5>
                    <p class="card-text">
                        <?php foreach (CookieManagerKit\Support\EssentialCookies::names() as $name) { ?>
                            <code class="me-2"><?php echo O::e($name) ?></code>
                        <?php } ?>
                    </p>
                </div>
            </div>
        <?php } ?>

        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <a href="<?php echo O::e($view->url('/dashboard/cookie_manager/categories')) ?>" class="btn btn-secondary float-start"><?php echo t('Cancel') ?></a>
                <?php echo $form->submit('save', t('Save Category'), ['class' => 'btn btn-primary float-end']) ?>
            </div>
        </div>
    </form>
<?php } ?>
```

- [ ] **Step 3: Create the services controller**

Create `controllers/single_page/dashboard/cookie_manager/services.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Entity\{Category, Service};
use CookieManagerKit\Support\{Catalog, SiteLocales, Text};
use Concrete\Core\Page\Controller\DashboardPageController;

class Services extends DashboardPageController
{
    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private const URL = '/dashboard/cookie_manager/services';

    private function prepare(string $mode, ?Service $service = null): void
    {
        $default = SiteLocales::defaultLocale();
        $categories = [];

        foreach ($this->entityManager->getRepository(Category::class)->findBy([], ['sortOrder' => 'ASC', 'handle' => 'ASC']) as $category) {
            $content = $category->getContent($default);
            $categories[$category->getID()] = $content ? $content->getTitle() : $category->getHandle();
        }

        $this->set('mode', $mode);
        $this->set('service', $service);
        $this->set('categories', $categories);
        $this->set('locales', SiteLocales::options());
        $this->set('defaultLocale', $default);
        $this->set('services', $this->entityManager->getRepository(Service::class)->findBy([], ['name' => 'ASC']));
    }

    private function find(mixed $id): ?Service
    {
        if ((!is_string($id) && !is_int($id)) || preg_match('/^\d+\z/', (string) $id) !== 1) {
            return null;
        }

        $service = $this->entityManager->getRepository(Service::class)->find((int) $id);

        return $service instanceof Service ? $service : null;
    }

    public function view()
    {
        $this->prepare('list');
    }

    public function add()
    {
        $this->prepare('form');
    }

    public function edit($id = null)
    {
        $service = $this->find($id);

        if ($service === null) {
            $this->flash('error', t('That service could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->prepare('form', $service);
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $id = $this->post('id');
        $service = $id !== null && $id !== '' ? $this->find($id) : null;

        if ($id !== null && $id !== '' && $service === null) {
            $this->flash('error', t('That service could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $back = $service ? self::URL . '/edit/' . $service->getID() : self::URL . '/add';
        $name = Text::plain($this->post('name'));
        $categoryId = $this->post('categoryID');
        $category = is_string($categoryId) && preg_match('/^\d+\z/', $categoryId) === 1
            ? $this->entityManager->getRepository(Category::class)->find((int) $categoryId)
            : null;

        if ($name === '' || !$category instanceof Category) {
            $this->flash('error', t('A service needs a name and a category.'));

            return $this->buildRedirect($back)->send();
        }

        $domains = $this->post('domains');

        $service ??= new Service();
        $service->setName($name)
            ->setVendor(Text::plain($this->post('vendor')))
            ->setCategory($category)
            ->setDomainsText(is_string($domains) ? $domains : '')
            ->setActive($this->post('active') !== null);

        $posted = $this->post('content');
        $posted = is_array($posted) ? $posted : [];
        $default = SiteLocales::defaultLocale();

        foreach (array_keys(SiteLocales::options()) as $locale) {
            $input = is_array($posted[$locale] ?? null) ? $posted[$locale] : [];
            $description = Text::multiline($input['description'] ?? '', 1000);
            $existing = $service->getContent($locale);

            if ($description === '' && $locale !== $default) {
                if ($existing) {
                    $service->getContents()->removeElement($existing);
                }

                continue;
            }

            $service->setContent($locale, $description);
        }

        $this->entityManager->persist($service);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Service saved.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $service = $this->find($this->post('id'));

        if ($service === null) {
            $this->flash('error', t('That service could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->entityManager->remove($service);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Service deleted.'));

        return $this->buildRedirect(self::URL)->send();
    }
}
```

- [ ] **Step 4: Create the services view**

Create `single_pages/dashboard/cookie_manager/services.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

/** @var string $mode */
/** @var CookieManagerKit\Entity\Service|null $service */
/** @var CookieManagerKit\Entity\Service[] $services */
/** @var array<int, string> $categories category ID => title */
/** @var array<string, string> $locales */
/** @var string $defaultLocale */
?>
<?php if ($mode === 'list') { ?>
    <div class="mb-3 clearfix">
        <a href="<?php echo O::e($view->action('add')) ?>" class="btn btn-primary float-end"><?php echo t('Add Service') ?></a>
    </div>

    <?php if ($services === []) { ?>
        <p class="text-muted"><?php echo t('No services yet. Add the tools your site loads (for example an analytics tool) so visitors can see what each category covers.') ?></p>
    <?php } else { ?>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th><?php echo t('Name') ?></th>
                    <th><?php echo t('Vendor') ?></th>
                    <th><?php echo t('Category') ?></th>
                    <th><?php echo t('Listed') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $row) { ?>
                    <tr>
                        <td><a href="<?php echo O::e($view->action('edit', $row->getID())) ?>"><?php echo O::e($row->getName()) ?></a></td>
                        <td><?php echo O::e($row->getVendor()) ?></td>
                        <td><?php echo O::e($row->getCategory() ? ($categories[$row->getCategory()->getID()] ?? $row->getCategory()->getHandle()) : '') ?></td>
                        <td><?php echo O::e($row->isActive() ? t('Yes') : t('No')) ?></td>
                        <td class="text-end">
                            <form method="post" action="<?php echo O::e($view->action('delete')) ?>" class="d-inline">
                                <?php echo $token->output('submit') ?>
                                <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo t('Delete') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
<?php } else { ?>
    <?php $isNew = $service === null; ?>
    <form method="post" action="<?php echo O::e($view->action('save')) ?>">
        <?php echo $token->output('submit') ?>
        <?php if (!$isNew) { ?><input type="hidden" name="id" value="<?php echo O::e($service->getID()) ?>"><?php } ?>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label" for="name"><?php echo t('Name') ?></label>
                <input type="text" class="form-control" id="name" name="name" maxlength="255" required value="<?php echo O::e($isNew ? '' : $service->getName()) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="vendor"><?php echo t('Vendor') ?></label>
                <input type="text" class="form-control" id="vendor" name="vendor" maxlength="255" value="<?php echo O::e($isNew ? '' : $service->getVendor()) ?>">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label" for="categoryID"><?php echo t('Category') ?></label>
                <select class="form-select" id="categoryID" name="categoryID" required>
                    <?php foreach ($categories as $categoryId => $title) { ?>
                        <option value="<?php echo O::e($categoryId) ?>" <?php echo O::e(!$isNew && $service->getCategory() && $service->getCategory()->getID() === $categoryId ? 'selected' : '') ?>><?php echo O::e($title) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="domains"><?php echo t('Domains') ?></label>
                <textarea class="form-control" id="domains" name="domains" rows="3" placeholder="www.example.com"><?php echo O::e($isNew ? '' : $service->getDomainsText()) ?></textarea>
                <div class="help-block form-text"><?php echo t('One host name per line, for example www.googletagmanager.com or *.example.com. Anything that is not a plain host name is ignored.') ?></div>
            </div>
        </div>

        <div class="form-check mb-4">
            <input type="checkbox" class="form-check-input" id="active" name="active" value="1" <?php echo O::e($isNew || $service->isActive() ? 'checked' : '') ?>>
            <label class="form-check-label" for="active"><?php echo t('List this service to visitors') ?></label>
        </div>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <?php $index = 0; foreach ($locales as $locale => $label) { $tabId = 'cmk-svc-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale); ?>
                <li class="nav-item" role="presentation">
                    <button type="button" class="nav-link <?php echo O::e($index === 0 ? 'active' : '') ?>" data-bs-toggle="tab" data-bs-target="#<?php echo O::e($tabId) ?>" role="tab">
                        <?php echo O::e($label) ?>
                        <?php if ($locale === $defaultLocale) { ?><span class="badge text-bg-secondary"><?php echo t('Default') ?></span><?php } ?>
                    </button>
                </li>
            <?php $index++; } ?>
        </ul>

        <div class="tab-content mb-4">
            <?php $index = 0; foreach ($locales as $locale => $label) {
                $tabId = 'cmk-svc-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale);
                $row = $isNew ? null : $service->getContent($locale);
                ?>
                <div class="tab-pane fade <?php echo O::e($index === 0 ? 'show active' : '') ?>" id="<?php echo O::e($tabId) ?>" role="tabpanel">
                    <label class="form-label" for="<?php echo O::e($tabId) ?>-description"><?php echo t('What this service does') ?></label>
                    <textarea class="form-control" rows="3" maxlength="1000" id="<?php echo O::e($tabId) ?>-description" name="<?php echo O::e('content[' . $locale . '][description]') ?>"><?php echo O::e($row ? $row->getDescription() : '') ?></textarea>
                </div>
            <?php $index++; } ?>
        </div>

        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <a href="<?php echo O::e($view->url('/dashboard/cookie_manager/services')) ?>" class="btn btn-secondary float-start"><?php echo t('Cancel') ?></a>
                <?php echo $form->submit('save', t('Save Service'), ['class' => 'btn btn-primary float-end']) ?>
            </div>
        </div>
    </form>
<?php } ?>
```

- [ ] **Step 5: Run the static checks**

Run: `composer test`
Expected: PASS. If `ViewEscapingTest` reports an offender, encode it (do not weaken the test). Known to double-check: `O::e($row->isActive() ? t('Yes') : t('No'))` is one `O::e` call and passes.

- [ ] **Step 6: Verify in the browser (needs the installed site)**

If no site is available, say so and leave this open for Task 18.

1. `/dashboard/cookie_manager/categories` lists the four seeded categories; `necessary` is badged "Always on" and has no Delete button; the "Required by ConcreteCMS" panel lists `CONCRETE` (or the site's custom session name), `CONCRETE_LOGIN`, `ccmAuthUserHash`, `ConcreteSitemapTreeID`, `ConcreteSitemap-expand`, `dashboardPanelStatus`, `cmk_consent`.
2. Edit `necessary`: handle is disabled; save works; sort order changes persist.
3. Add category `functional` with a title in the default language: it appears in the list with Delete. Try handles `Bad Handle`, `necessary`, `<script>` and an existing handle: each is refused with an error and nothing is created.
4. Add a service in `analytics` with domains `www.google-analytics.com`, `*.example.com`, `javascript:alert(1)`, `<script>`: only the two hostnames are kept after saving. Then try to delete `analytics` (refused while it has services) and delete the service.
5. Submit hostile text in every field (`"><img src=x onerror=alert(1)>`): no alert, text appears plain after reload, no console errors.
6. In the browser dev tools, replace the CSRF token field with garbage and submit a save and a delete: both are rejected with an error and change nothing.

- [ ] **Step 7: Commit**

```bash
git add controllers single_pages
git commit -m "feat: add categories and services dashboard pages

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 17: Appearance page with live preview

**Files:**
- Create: `controllers/single_page/dashboard/cookie_manager/appearance.php`, `single_pages/dashboard/cookie_manager/appearance.php`, `elements/dashboard/banner_preview.php`, `js/cmk-dashboard.js`
- Modify: `css/cmk.css` (preview stage rules)
- Test: `tests/js/dashboard.test.js`

**Interfaces:**
- Consumes: `Settings` (Task 3), `StyleRules` (Task 3), `Catalog::DEFAULTS`/`flush` (Task 6), `ConsentManager::registerAssets()` and asset handles `cookie-manager-kit/css`, `cookie-manager-kit/dashboard` (Task 12), `BannerContent` (Task 4), `SiteLocales` (Task 14).
- Produces: `/dashboard/cookie_manager/appearance` with fields `position`, `layout`, `font`, `radius`, `shadow`, and colour fields `tokenBg`, `tokenFg`, `tokenAccent`, `tokenButtonBg`, `tokenButtonFg`; a server-rendered preview (`elements/dashboard/banner_preview.php`, root `[data-cmk-preview]`) that `js/cmk-dashboard.js` updates as fields change by setting only validated CSS custom properties and allowlisted data attributes.

- [ ] **Step 1: Write the failing dashboard JS test**

Create `tests/js/dashboard.test.js`:

```js
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { loadScript } from './helpers.js';

function page() {
	document.body.innerHTML = `
		<div class="cmk-preview-stage"><div class="cmk" data-cmk-preview data-position="bottom-right" data-layout="stacked" data-font="inherit" data-shadow="on"></div></div>
		<input id="tokenBg" value="#ffffff"><input id="tokenFg" value="#111111"><input id="tokenAccent" value="#0b5fff">
		<input id="tokenButtonBg" value="#0b5fff"><input id="tokenButtonFg" value="#ffffff">
		<select id="position"><option value="bottom-right">x</option><option value="bottom-left">x</option></select>
		<select id="layout"><option value="stacked">x</option><option value="inline">x</option></select>
		<select id="font"><option value="inherit">x</option><option value="system">x</option></select>
		<input id="radius" value="8"><input id="shadow" type="checkbox" checked>`;
}

const preview = () => document.querySelector('[data-cmk-preview]');
const change = (id, value) => {
	const field = document.getElementById(id);
	field.value = value;
	field.dispatchEvent(new Event('input', { bubbles: true }));
};

beforeEach(() => {
	page();
	loadScript('cmk-dashboard.js');
});

afterEach(() => {
	document.body.innerHTML = '';
});

describe('the Appearance preview', () => {
	it('applies valid colours as custom properties', () => {
		change('tokenBg', '#102030');
		change('tokenButtonFg', '#AABBCC');

		expect(preview().style.getPropertyValue('--cmk-bg')).toBe('#102030');
		expect(preview().style.getPropertyValue('--cmk-button-fg')).toBe('#AABBCC');
	});

	it('ignores anything that is not a six digit hex colour', () => {
		change('tokenBg', '#102030');
		['red', '#fff', 'url(javascript:alert(1))', '#102030;}</style><script>alert(1)</script>', ''].forEach((bad) => {
			change('tokenBg', bad);
			expect(preview().style.getPropertyValue('--cmk-bg'), bad).toBe('#102030');
		});
	});

	it('applies position, layout and font only from the allowlist', () => {
		change('position', 'bottom-left');
		change('layout', 'inline');
		change('font', 'system');
		expect(preview().dataset.position).toBe('bottom-left');
		expect(preview().dataset.layout).toBe('inline');
		expect(preview().dataset.font).toBe('system');

		const select = document.getElementById('position');
		select.add(new Option('evil', '"><img src=x onerror=alert(1)>'));
		select.value = '"><img src=x onerror=alert(1)>';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(preview().dataset.position).toBe('bottom-left');
		expect(document.querySelector('img')).toBeNull();
	});

	it('clamps the radius to 0 to 32 and ignores nonsense', () => {
		change('radius', '12');
		expect(preview().style.getPropertyValue('--cmk-radius')).toBe('12px');
		change('radius', '999');
		expect(preview().style.getPropertyValue('--cmk-radius')).toBe('12px');
		change('radius', 'abc');
		expect(preview().style.getPropertyValue('--cmk-radius')).toBe('12px');
	});

	it('toggles the shadow', () => {
		const box = document.getElementById('shadow');
		box.checked = false;
		box.dispatchEvent(new Event('change', { bubbles: true }));

		expect(preview().dataset.shadow).toBe('off');
	});

	it('does nothing on pages without a preview', () => {
		document.body.innerHTML = '<input id="tokenBg" value="#ffffff">';

		expect(() => loadScript('cmk-dashboard.js')).not.toThrow();
	});
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx vitest run tests/js/dashboard.test.js`
Expected: FAIL, `ENOENT ... js/cmk-dashboard.js`.

- [ ] **Step 3: Implement the preview script**

Create `js/cmk-dashboard.js`:

```js
/*
 * Appearance page live preview. Only validated values are applied: six digit hex colours as CSS custom
 * properties, a clamped radius, and data attributes taken from fixed allowlists. Nothing is parsed as markup.
 */
(function (d) {
	'use strict';

	var HEX = /^#[0-9a-fA-F]{6}$/;
	var VARS = { tokenBg: '--cmk-bg', tokenFg: '--cmk-fg', tokenAccent: '--cmk-accent', tokenButtonBg: '--cmk-button-bg', tokenButtonFg: '--cmk-button-fg' };
	var CHOICES = {
		position: ['bottom-left', 'bottom-right', 'bottom-bar', 'center-modal'],
		layout: ['stacked', 'inline'],
		font: ['inherit', 'system'],
	};

	function apply() {
		var preview = d.querySelector('[data-cmk-preview]');

		if (!preview) {
			return;
		}

		Object.keys(VARS).forEach(function (id) {
			var field = d.getElementById(id);

			if (field && HEX.test(field.value)) {
				preview.style.setProperty(VARS[id], field.value);
			}
		});

		Object.keys(CHOICES).forEach(function (id) {
			var field = d.getElementById(id);

			if (field && CHOICES[id].indexOf(field.value) !== -1) {
				preview.setAttribute('data-' + id, field.value);
			}
		});

		var radiusField = d.getElementById('radius');
		var radius = radiusField ? parseInt(radiusField.value, 10) : NaN;

		if (radius >= 0 && radius <= 32) {
			preview.style.setProperty('--cmk-radius', radius + 'px');
		}

		var shadow = d.getElementById('shadow');

		if (shadow) {
			preview.setAttribute('data-shadow', shadow.checked ? 'on' : 'off');
		}
	}

	d.addEventListener('input', apply);
	d.addEventListener('change', apply);
	apply();
})(document);
```

- [ ] **Step 4: Run to verify it passes**

Run: `npx vitest run`
Expected: all JS tests PASS (including `sinks.test.js` now scanning `cmk-dashboard.js`).

- [ ] **Step 5: Add the preview stage rules to the stylesheet**

Append to `css/cmk.css`:

```css
/* Appearance preview: show the banner in normal flow inside the dashboard instead of fixed to the window. */
.cmk-preview-stage {
	padding: 24px;
	background: repeating-linear-gradient(45deg, #f1f1f1, #f1f1f1 10px, #e8e8e8 10px, #e8e8e8 20px);
	border-radius: 6px;
}

.cmk-preview-stage .cmk {
	position: relative;
	inset: auto;
	width: auto;
	max-width: 440px;
	margin: 0 auto;
	transform: none;
}

.cmk-preview-stage .cmk[data-position='bottom-bar'] {
	max-width: none;
	border-radius: var(--cmk-radius);
}

.cmk-preview-stage .cmk[data-position='center-modal'] {
	max-width: 560px;
	box-shadow: none;
}

.cmk-preview-stage .cmk[data-position='center-modal'][data-shadow='on'] {
	box-shadow: 0 8px 30px rgb(0 0 0 / 25%);
}
```

- [ ] **Step 6: Create the preview element**

Create `elements/dashboard/banner_preview.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Catalog;
use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Entity\Settings $settings */
/** @var CookieManagerKit\Entity\BannerContent|null $banner */

$defaults = Catalog::DEFAULTS;
$text = static fn(string $value, string $fallback): string => $value !== '' ? $value : $fallback;
$title = $text($banner ? $banner->getTitle() : '', $defaults['title']);
$body = $text($banner ? $banner->getBody() : '', $defaults['body']);
$accept = $text($banner ? $banner->getAcceptAllLabel() : '', $defaults['acceptAll']);
$reject = $text($banner ? $banner->getRejectAllLabel() : '', $defaults['rejectAll']);
$customise = $text($banner ? $banner->getCustomiseLabel() : '', $defaults['customise']);
$tokens = $settings->getTokens();
$style = '--cmk-bg: ' . $tokens['bg'] . '; --cmk-fg: ' . $tokens['fg'] . '; --cmk-accent: ' . $tokens['accent']
    . '; --cmk-button-bg: ' . $tokens['buttonBg'] . '; --cmk-button-fg: ' . $tokens['buttonFg'] . '; --cmk-radius: ' . $settings->getRadius() . 'px';
?>
<div class="cmk-preview-stage">
    <div class="cmk" data-cmk-preview data-position="<?php echo O::e($settings->getPosition()) ?>" data-layout="<?php echo O::e($settings->getLayout()) ?>" data-font="<?php echo O::e($settings->getFont()) ?>" data-shadow="<?php echo O::e($settings->hasShadow() ? 'on' : 'off') ?>" style="<?php echo O::e($style) ?>">
        <div class="cmk__panel">
            <h2 class="cmk__title"><?php echo O::e($title) ?></h2>
            <p class="cmk__body">
                <?php foreach (explode("\n", $body) as $line => $part) { ?>
                    <?php if ($line > 0) { ?><br><?php } ?>
                    <?php echo O::e($part) ?>
                <?php } ?>
            </p>
            <div class="cmk__actions">
                <button type="button" class="cmk__btn cmk__btn--primary"><?php echo O::e($accept) ?></button>
                <button type="button" class="cmk__btn"><?php echo O::e($reject) ?></button>
                <button type="button" class="cmk__btn cmk__btn--link"><?php echo O::e($customise) ?></button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 7: Create the Appearance controller**

Create `controllers/single_page/dashboard/cookie_manager/appearance.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Entity\{BannerContent, Settings};
use CookieManagerKit\Events\ConsentManager;
use CookieManagerKit\Support\{Catalog, SiteLocales, StyleRules};
use Concrete\Core\Page\Controller\DashboardPageController;

class Appearance extends DashboardPageController
{
    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private const URL = '/dashboard/cookie_manager/appearance';
    private const TOKENS = ['tokenBg' => 'bg', 'tokenFg' => 'fg', 'tokenAccent' => 'accent', 'tokenButtonBg' => 'buttonBg', 'tokenButtonFg' => 'buttonFg'];

    public function view()
    {
        ConsentManager::registerAssets();
        $this->requireAsset('css', 'cookie-manager-kit/css');
        $this->requireAsset('javascript', 'cookie-manager-kit/dashboard');

        $banner = $this->entityManager->getRepository(BannerContent::class)->findOneBy(['locale' => SiteLocales::defaultLocale()]);

        $this->set('settings', Settings::get());
        $this->set('banner', $banner instanceof BannerContent ? $banner : null);
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        foreach (array_keys(self::TOKENS) as $field) {
            if (!StyleRules::isHex($this->post($field))) {
                $this->flash('error', t('Colours must be six digit hex codes such as #0b5fff.'));

                return $this->buildRedirect(self::URL)->send();
            }
        }

        $radius = $this->post('radius');

        if (!is_string($radius) || preg_match('/^\d{1,2}\z/', $radius) !== 1 || (int) $radius > 32) {
            $this->flash('error', t('The corner radius must be a whole number between 0 and 32.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $choices = [
            'position' => StyleRules::POSITIONS,
            'layout' => StyleRules::LAYOUTS,
            'font' => StyleRules::FONTS,
        ];

        foreach ($choices as $field => $allowed) {
            if (!is_string($this->post($field)) || !in_array($this->post($field), $allowed, true)) {
                $this->flash('error', t('Please choose one of the listed options for position, layout and font.'));

                return $this->buildRedirect(self::URL)->send();
            }
        }

        $settings = Settings::get();
        $settings->setPosition($this->post('position'))
            ->setLayout($this->post('layout'))
            ->setFont($this->post('font'))
            ->setRadius($radius)
            ->setShadow($this->post('shadow') !== null);

        foreach (self::TOKENS as $field => $token) {
            $settings->setToken($token, $this->post($field));
        }

        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Appearance saved.'));

        return $this->buildRedirect(self::URL)->send();
    }
}
```

- [ ] **Step 8: Create the Appearance view**

Create `single_pages/dashboard/cookie_manager/appearance.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\View\View;
use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Entity\Settings $settings */
/** @var CookieManagerKit\Entity\BannerContent|null $banner */

$positions = [
    'bottom-right' => t('Bottom right'),
    'bottom-left' => t('Bottom left'),
    'bottom-bar' => t('Bottom bar (full width)'),
    'center-modal' => t('Centre of the page'),
];
$layouts = ['stacked' => t('Buttons stacked'), 'inline' => t('Buttons in a row')];
$fonts = ['inherit' => t('Use the theme font'), 'system' => t('Use the system font')];
$colours = [
    'tokenBg' => [t('Background'), 'bg'],
    'tokenFg' => [t('Text'), 'fg'],
    'tokenAccent' => [t('Accent and links'), 'accent'],
    'tokenButtonBg' => [t('Button background'), 'buttonBg'],
    'tokenButtonFg' => [t('Button text'), 'buttonFg'],
];
?>
<div class="row">
    <div class="col-lg-5">
        <form method="post" action="<?php echo O::e($view->action('save')) ?>">
            <?php echo $token->output('submit') ?>

            <fieldset class="mb-4">
                <legend><?php echo t('Position and layout') ?></legend>

                <div class="form-group mb-3">
                    <label class="form-label" for="position"><?php echo t('Position') ?></label>
                    <select class="form-select" id="position" name="position">
                        <?php foreach ($positions as $value => $label) { ?>
                            <option value="<?php echo O::e($value) ?>" <?php echo O::e($settings->getPosition() === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="layout"><?php echo t('Buttons') ?></label>
                    <select class="form-select" id="layout" name="layout">
                        <?php foreach ($layouts as $value => $label) { ?>
                            <option value="<?php echo O::e($value) ?>" <?php echo O::e($settings->getLayout() === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="font"><?php echo t('Font') ?></label>
                    <select class="form-select" id="font" name="font">
                        <?php foreach ($fonts as $value => $label) { ?>
                            <option value="<?php echo O::e($value) ?>" <?php echo O::e($settings->getFont() === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </fieldset>

            <fieldset class="mb-4">
                <legend><?php echo t('Colours') ?></legend>

                <?php foreach ($colours as $id => [$label, $tokenName]) { ?>
                    <div class="form-group mb-3">
                        <label class="form-label" for="<?php echo O::e($id) ?>"><?php echo O::e($label) ?></label>
                        <input type="color" class="form-control form-control-color" id="<?php echo O::e($id) ?>" name="<?php echo O::e($id) ?>" value="<?php echo O::e($settings->getToken($tokenName)) ?>">
                    </div>
                <?php } ?>
            </fieldset>

            <fieldset class="mb-4">
                <legend><?php echo t('Shape') ?></legend>

                <div class="form-group mb-3">
                    <label class="form-label" for="radius"><?php echo t('Corner radius (px)') ?></label>
                    <input type="number" class="form-control" style="max-width: 10rem" id="radius" name="radius" min="0" max="32" step="1" value="<?php echo O::e($settings->getRadius()) ?>">
                </div>

                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="shadow" name="shadow" value="1" <?php echo O::e($settings->hasShadow() ? 'checked' : '') ?>>
                    <label class="form-check-label" for="shadow"><?php echo t('Drop shadow') ?></label>
                </div>
            </fieldset>

            <div class="ccm-dashboard-form-actions-wrapper">
                <div class="ccm-dashboard-form-actions">
                    <?php echo $form->submit('save', t('Save Appearance'), ['class' => 'btn btn-primary float-end']) ?>
                </div>
            </div>
        </form>
    </div>

    <div class="col-lg-7">
        <h5><?php echo t('Preview') ?></h5>
        <p class="text-muted"><?php echo t('Uses the default language. The real banner is built the same way and follows these settings after you save.') ?></p>
        <?php View::element('dashboard/banner_preview', ['settings' => $settings, 'banner' => $banner], 'cookie_manager_kit') ?>
    </div>
</div>
```

`View::element(...)` outputs directly and is not an `echo`, so the encoding test does not flag it; everything the element prints is encoded inside it.

- [ ] **Step 9: Run all automated checks**

Run: `composer test && npx vitest run && npx prettier --check js css tests/js && composer typecheck`
Expected: all PASS. Fix reported encoding or formatting issues in the code, never by loosening a test.

- [ ] **Step 10: Verify in the browser (needs the installed site)**

If no site is available, say so and leave this open for Task 18.

1. `/dashboard/cookie_manager/appearance`: the preview shows the banner in the current style. Change each colour, the position, layout, font, radius and shadow: the preview updates immediately with no console errors.
2. Save, reload: values persist and the preview matches.
3. Using dev tools, set `tokenBg` to `red;}</style><script>alert(1)</script>` and `radius` to `999` and submit: error flash, nothing saved.
4. Set the heading in Banner to `<b>x</b>` first (stored as plain `x`): the preview shows plain text.

- [ ] **Step 11: Commit**

```bash
git add controllers single_pages elements js css tests/js
git commit -m "feat: add appearance page with live preview

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 18: End-to-end verification, docs and wrap-up

**Files:**
- Modify: `README.md`, `CHANGELOG.md`

**Interfaces:**
- Consumes: everything above.
- Produces: a verified phase 1; docs describing the data attribute contract, the `data-cmk-open` hook and the CSP nonce hook.

- [ ] **Step 1: Run every automated check from a clean state**

Run:

```bash
composer dump-autoload && composer test && composer typecheck && composer format:php:check && npx vitest run && npx prettier --check .
```

Expected: PHPUnit and Vitest PASS; PHPStan has no errors in package files (investigate, do not ignore); the formatters report clean. If `format:php:check` fails, run `composer format:php`, review the diff for anything unrelated to this plan, and commit it as `style: apply php-cs-fixer`.

- [ ] **Step 2: Choose the verification site**

Ask the user which local ConcreteCMS 9.5 site to use (do not guess). It needs: the package installed (Task 13 Step 7), at least two site locales (one default, one extra) for the language checks, and a throwaway SEO tracking snippet. If the user has no suitable site, report exactly which checks below were not run.

- [ ] **Step 3: Browser verification, banner and consent**

Use the browser tools. For each item, record what you observed and check the console for errors. Use a fresh private window (no cookies) for visitor checks.

1. **Before consent:** open the home page. The banner appears with the default-language text; the page works. In dev tools, Application > Cookies shows **no** `cmk_consent`, and Network shows no request to a third-party domain.
2. **Inert scripts:** in the site's SEO tracking settings (`/dashboard/system/seo/tracking_codes`) add `<script>window.__trackingRan=(window.__trackingRan||0)+1</script>`; add an author-marked script to a template or HTML block: `<?php echo CookieManagerKit\Support\Inert::script('marketing', '', 'window.__marketingRan=1'); ?>`. Reload as a visitor: `window.__trackingRan` and `window.__marketingRan` are `undefined`, and view-source shows both scripts as `type="text/plain" data-cmk-category=...`.
3. **Reject all:** `window.__trackingRan` stays undefined after a reload; cookie `cmk_consent` = `{"v":1,"c":["necessary"],"t":...}` (URL-encoded); the banner does not return; the floating "Cookie settings" button is visible.
4. **Change later (widget):** click the button, tick Analytics, Save: `__trackingRan` becomes `1` immediately (no reload, because nothing was withdrawn), `__marketingRan` stays undefined; reload: still `1`.
5. **Withdraw:** open the widget, untick Analytics, Save: the page reloads and `__trackingRan` is `undefined` again.
6. **Accept all:** every category in the cookie; both counters set after reload.
7. **Custom via a link:** add `<a href="#" data-cmk-open>Cookie settings</a>` to the footer: clicking it opens the preference centre with the current choice ticked.
8. **Keyboard and screen reader basics:** Tab reaches every button and checkbox in order; focus is visible; Escape closes the preference centre when a choice exists; the banner has `role="dialog"` with its heading as the label.
9. **Stale cookie:** in the dashboard tick "Ask every visitor again" and save; the visitor from step 6 now sees the banner again with no console error. Then set `cmk_consent` to `garbage` and to `%7B%22v%22%3A2%2C%22c%22%3A%5B%22nope%22%5D%2C%22t%22%3A1%7D`: the banner appears each time and nothing is activated.
10. **Cookies blocked:** in dev tools block all cookies for the site, click Accept all: scripts run for that page view, no console error, banner returns on the next page.
11. **Consent endpoint down:** block `/cookie-manager/consent` in dev tools (request blocking) and click Accept all: the choice is saved and applied, no console error.
12. **Mobile width (375px):** the banner fits, buttons are tappable, nothing causes horizontal scroll.

- [ ] **Step 4: Browser verification, languages and content**

1. Fill in the extra language's banner text, categories and a service description in the dashboard. Visit that language's section of the site: the banner shows that language. Visit a locale with no content (clear one language's tab in Banner): it shows the default language.
2. Request `/cookie-manager/config/<script>.json`, `/cookie-manager/config/..%2F..%2Fetc%2Fpasswd.json` and `/cookie-manager/config/zz_ZZ.json`: each returns the **default** locale's config (or 404), and the hostile text appears nowhere in the response.
3. With the hostile heading and body from Task 15 Step 5 saved, the front-end banner shows them as plain text; no alert; view-source of the config response has no raw `<` or `>`.

- [ ] **Step 5: Verify caching and identical output**

1. Turn on full-page caching (`/dashboard/system/optimization/cache`, "Full page caching": all pages).
2. Fetch a page as three visitors and compare:

```bash
curl -s https://<site>/ -o /tmp/cmk-a.html
curl -s -H 'Cookie: cmk_consent=%7B%22v%22%3A1%2C%22c%22%3A%5B%22necessary%22%2C%22analytics%22%5D%2C%22t%22%3A1%7D' https://<site>/ -o /tmp/cmk-b.html
curl -s -H 'Cookie: cmk_consent=garbage' https://<site>/ -o /tmp/cmk-c.html
diff /tmp/cmk-a.html /tmp/cmk-b.html && diff /tmp/cmk-a.html /tmp/cmk-c.html && echo IDENTICAL
```

Expected: `IDENTICAL` (only the inert-script markup and boot data, no per-visitor variation). Then save any change in the dashboard and fetch again: the page updates (the full-page cache was flushed by `Catalog::flush()`).
3. Request the config twice, the second time with `If-None-Match: "<ETag from the first response>"`: `304`.

- [ ] **Step 6: Verify essential ConcreteCMS cookies are untouched**

1. As a visitor, **Reject all**. Then log in at `/login` (tick "Stay signed in"). Expected: login works; the session cookie (name from Appearance > "Required by ConcreteCMS", default `CONCRETE`) and `ccmAuthUserHash` are set by Concrete and **not** removed or altered by any consent action (compare their values before and after clicking Reject all, Accept all and reopening the widget).
2. Withdraw a category while signed in (Step 3 item 5): you remain signed in after the reload.
3. Submit a form that uses a CSRF token (for example the dashboard banner save) after rejecting all cookies: it works.
4. Change the session name: add `Config::set('concrete.session.name', 'MYSITE')` early in a test package's `on_start` (or a site `application/bootstrap/app.php`), clear the cache, reload the dashboard: the "Required by ConcreteCMS" list shows `MYSITE` and `MYSITE_LOGIN`, and `/cookie-manager/config/<locale>.json` lists them under `necessary`. Remove the override afterwards.

- [ ] **Step 7: Verify uninstall and reinstall**

Uninstall the package with "Also delete..." ticked: `SHOW TABLES LIKE 'cmk%'` returns nothing and the front end has no banner or console error. Reinstall: the four default categories and default banner text are seeded again.

- [ ] **Step 8: Write the docs**

Replace the body of `README.md` with a short description plus these sections (write them in your own words, keeping these facts):

- **What it does** and the phase 1 scope (categories, services, multilingual banner, script blocking, consent cookie, widget, appearance). Later phases (scanner, audit log, regional rules, Consent Mode, declaration block, reports) are listed as not built yet.
- **Blocking a script:** `<script type="text/plain" data-cmk-category="analytics" src="..."></script>`; the PHP helper `CookieManagerKit\Support\Inert::script($category, $src, $inline, $type)`; `data-cmk-type="module"` for module scripts; the SEO tracking snippets are made inert under `analytics` automatically and their `<noscript>` parts are removed.
- **Reopening the settings:** add `data-cmk-open` to any element.
- **Events:** `window.cmk.on('consent:changed', fn)` with `{categories, previous}`; `window.cmk.get()`, `window.cmk.has('analytics')`.
- **CSP:** bind `cookie_manager_kit.csp_nonce` (string or callable) to put the request nonce on the inline loader.
- **Essential cookies:** which ConcreteCMS cookies are always allowed and that the session name is read from `concrete.session.name` at request time.
- **Security notes:** plain-text content only, endpoint protections, no rich text in this phase.
- **Developing:** `composer test`, `npx vitest run`, `composer typecheck`, `composer format`.

Add to `CHANGELOG.md` an entry `0.1.0` summarising phase 1.

- [ ] **Step 9: Final review and commit**

Run `git status` and `git diff --stat main` and confirm only the files in this plan changed. Then:

```bash
git add README.md CHANGELOG.md
git commit -m "docs: document phase 1 and add changelog entry

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Report to the user, in plain terms: which verification items passed, which were not run and why, and anything that behaved differently from this plan (for example if the `{locale}.json` route needed changing). Do not push or open a pull request unless asked.

---

## Spec coverage

| Spec requirement | Task(s) |
|---|---|
| §2.1 No non-necessary script runs before consent | 9, 10, 12, 18 |
| §2.2 All/some/none choices persist; §2.3 change any time via widget / `data-cmk-open` | 10, 11, 18 |
| §2.4 Per-locale content with default-locale fallback | 4, 5, 15, 18 |
| §2.5 Identical HTML for every visitor (full-page cache) | 12, 18 |
| §2.6 Admin styling, whitelisted | 3, 5, 11, 17 |
| §2.7 Endpoint and frontend XSS protection; §8.1 | 1, 5, 7, 8, 10, 11, 12 |
| §2.8 Backend XSS protection; §8.2 | 1, 14 (static checks), 15, 16, 17 |
| §2.9 / §5.1 Essential ConcreteCMS cookies always allowed; session name read at use time. Phase 1 has no cookie-clearing routine (automatic deletion arrives with the scanner in phase 2, spec §7), so `EssentialCookies::isEssential()` is the guard that routine must call first | 2, 5, 6, 16, 18 |
| §3 Architecture (loader, runtime, config and consent endpoints) | 7, 8, 10, 11, 12 |
| §4 Data model | 3, 4 |
| §5 Consent model (cookie format, version validity, reject all) | 7, 10 |
| §6 Admin UI (banner, categories and services, appearance with preview) | 15, 16, 17 |
| §7 Script blocking contract, SEO tracking snippets, withdrawal reload | 9, 10, 11, 12, 18 |
| §8 Config JSON | 5, 8 |
| §9 Extension points (`ConsentRecorder`, config sections, `cmk` events) | 7, 10 |
| §11 Testing and verification | every task, 18 |
| §12 Housekeeping (autoload fix, tooling, seeding) | 1, 13 |
