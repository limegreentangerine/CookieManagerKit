# CookieManagerKit Phase 2: Scanner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Find the cookies, storage keys, scripts, iframes and images a Concrete site really uses, suggest a consent category for each, and let an admin review and promote them to `Service` records, with a scheduled crawl that reports changes.

**Architecture:** A `Scanner` interface with a `CrawlScanner` (server-side, same-host, SSRF-guarded, WHATWG-parsed) and a `BeaconScanner` (admin scan-mode report). Both produce plain `Finding` value objects that a single `FindingIngestor` writes as `ScanFinding` rows, classifying each against `ClassificationEntry` rows (override, then refreshed, then bundled). Pure units (parser, classifier, token, validators) hold the logic; Doctrine, Guzzle and Concrete sit in thin adapters. Dashboard pages and Concrete tasks sit on top.

**Tech Stack:** PHP 8.4 (`Dom\HTMLDocument`), Concrete CMS 9.5, Doctrine ORM (annotations, ClassKit `src/Entity/Core` bases), Guzzle, PHPUnit 11, vanilla JS + Vitest/jsdom, PHPStan, php-cs-fixer, Prettier.

**Spec:** `docs/superpowers/specs/2026-10-16-cookie-manager-scanner-design.md` (phase 1 context: `docs/superpowers/specs/2026-10-15-cookie-manager-core-design.md`).

## Global Constraints

- PHP `^8.4`, Concrete `9.5.0+`; package handle `cookie_manager_kit`; namespace `CookieManagerKit\` maps to `src/`; PSR-4 test namespace `CookieManagerKit\Tests\`.
- Every PHP file under `src/` must declare a class, interface, trait or enum (enforced by `tests/SourceLayoutTest.php`). Data files live in `data/`, not `src/`.
- Output encoding: every dynamic value in a view goes through `CookieManagerKit\Support\Output` (`O::e`, `O::url`, `O::json`); the existing `tests/ViewEscapingTest.php` gate scans `single_pages/` and `elements/`.
- Dashboard actions: each public non-`view/add/edit/on_start/__construct` controller method starts with the `isPost()` guard and the `$this->token->validate('submit')` guard, both ending in an unconditional `return`; redirect targets are fixed routes or entity IDs (`tests/ControllerSecurityTest.php`).
- Browser JS never uses `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, `new Function`, string timers, `srcdoc` or `createContextualFragment` (`tests/js/sinks.test.js` reads every file in `js/`).
- Page HTML must be identical for every visitor, including an admin in scan mode (Concrete full-page cache).
- Scanned data is hostile: cookie names, storage keys, hosts and URLs are length-capped, control-stripped, and encoded on every output. Cookie and storage **values are never read or stored**.
- Crawler: canonical host only, exactly one DNS resolution per run (pinned with `CURLOPT_RESOLVE`), no redirects followed, 2,000,000 byte response cap, 8 s timeout, per-run page cap (default 50, max 500) and 240 s time budget.
- Scan-mode token: HMAC-SHA256, `<runId>.<expiresAt>.<hex>`, 1800 s lifetime, compared with `hash_equals`, secret from `app('config/database')->get('concrete.security.token.validation')`; refuse when the secret is empty.
- Beacon report body: `application/json`, at most 32768 bytes, keys exactly `c`, `h`, `p`, `s`; at most 100 items per array.
- Classification refresh: fixed HTTPS URL constant, 5,000,000 byte cap, schema-validated, all-or-nothing; off by default.
- Commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Run `composer format:php` and `npx prettier --write js tests/js` before each commit; the CI runs `composer format:check` and `composer typecheck`.

## Review Focus

The spec is silent on these; each is pinned by a test in the task named in brackets.

1. **Hostile cookie names / storage keys** (`<script>`, `"><img onerror=…>`, control characters, 10 000 characters, invalid UTF-8) from either scanner are stored capped and verbatim, and every view, flash message and email shows them encoded. [Task 2, Task 5, Task 10, Task 11, Task 13]
2. **A page that is not HTML, is empty, is not valid UTF-8, is huge, or has zero third-party tags** produces no findings and no crash, and the run still completes. [Task 3, Task 4, Task 5]
3. **Redirects, 4xx/5xx pages, timeouts or a truncated page list** mean the crawl is not "complete": nothing may be marked `gone` (a false `gone` would hide a live tracker from review). [Task 5, Task 8, Task 10]
4. **A forged, expired, wrong-run or replayed-after-stop scan token**, a body with extra keys, or a request made before the upgrade created the tables must be refused with a fixed body that echoes nothing. [Task 10, Task 11]
5. **An admin deletes a Service or a Category that findings or suggestions point at**: findings survive (the service link is nulled), the approve form falls back to a real category instead of failing. [Task 1, Task 13]

## File Structure

Create:

| File | Responsibility |
|---|---|
| `src/Entity/ScanRun.php` | One crawl or one scan-mode session |
| `src/Entity/ScanFinding.php` | One distinct thing seen, with review status |
| `src/Entity/ClassificationEntry.php` | One known cookie, storage key or host pattern |
| `src/Scan/Host.php` | Host validation and URL-to-host extraction |
| `src/Scan/Clean.php` | Name cleaning (cookie names, storage keys) |
| `src/Scan/Finding.php` | Plain value object a scanner returns |
| `src/Scan/PageParser.php` | HTML to findings, via the WHATWG parser |
| `src/Scan/CookieHeader.php` | `Set-Cookie` lines to cookie findings |
| `src/Scan/UnsafeUrl.php`, `src/Scan/FetchFailed.php` | Exceptions |
| `src/Scan/UrlGuard.php` | Same-host, pinned-IP, address-range guard |
| `src/Scan/Fetcher.php`, `FetchResult.php`, `GuzzleFetcher.php` | HTTP fetching behind the guard |
| `src/Scan/UrlSelector.php` | Exclude list and page cap |
| `src/Scan/Scanner.php`, `ScanContext.php`, `ScanResult.php` | Scanner interface and its value objects |
| `src/Scan/CrawlScanner.php`, `BeaconScanner.php` | The two scanners |
| `src/Scan/Pattern.php`, `Suggestion.php`, `Classifier.php` | Matching and precedence |
| `src/Scan/ClassificationFile.php` | CSV parsing and validation (bundled and Open Cookie Database formats) |
| `src/Scan/FindingStore.php`, `DoctrineFindingStore.php` | Storage seam for the ingestor |
| `src/Scan/FindingIngestor.php`, `IngestSummary.php` | The only code that writes findings |
| `src/Scan/ClassificationStore.php`, `SnapshotSource.php`, `GithubSnapshot.php`, `Refresher.php` | Classification persistence and refresh |
| `src/Scan/AlertMessage.php`, `Alerts.php`, `StaleCheck.php` | Alert text, delivery, stale-runtime rule |
| `src/Scan/UrlSource.php`, `PublishedPages.php`, `CrawlTarget.php`, `CrawlRunner.php`, `Services.php` | Crawl orchestration and wiring |
| `src/Scan/ScanToken.php`, `BeaconReport.php`, `ScanMode.php`, `ScanRuns.php` | Scan-mode token, report schema, session lifecycle |
| `src/Scan/Promoter.php` | Finding to Service promotion |
| `src/Ajax/ScanReport.php`, `src/Ajax/ScanBeacon.php` | Public beacon endpoints |
| `src/Command/Task/*` | Concrete tasks (crawl, refresh) |
| `data/classification-baseline.csv` | Bundled baseline |
| `js/cmk-beacon.js` | Scan-mode beacon |
| `controllers/single_page/dashboard/cookie_manager/{scanner,findings,scan_settings}.php` and matching `single_pages/…` views | Dashboard |
| `tasks.xml` | Task registration |
| `tests/Scan/*`, `tests/Entity/Scan*Test.php`, `tests/js/beacon.test.js`, … | Tests |

Modify: `src/Entity/Settings.php`, `src/Support/LoaderScript.php`, `src/Events/ConsentManager.php`, `js/cmk-runtime.js`, `controller.php`, `README.md`, `CHANGELOG.md`, `docs/superpowers/specs/2026-10-16-cookie-manager-scanner-design.md`.

## Spec adjustments made by this plan

These are small, deliberate refinements. Task 15 writes them back into the spec so the two stay in step.

1. `new` is **not a stored status**. A finding gets `matched` or `unclassified` on first sight; "new" is `ScanRun.newCount` and `firstSeen >= last run start`. Storing `new` as a status would need a second transition to settle it.
2. `ScanFinding` carries a `fingerprint` (`sha1(kind NUL name NUL host)`, unique) and a nullable `restoreStatus`. A composite unique key over `kind, name, host` can exceed index-length limits in utf8mb4; `restoreStatus` is how `gone` "returns to its previous status".
3. For `script`, `iframe`, `image` and `resource` findings `name` equals `host`, so one finding exists per `(kind, host)`, not per URL.
4. The dashboard is three single pages (`scanner`, `findings`, `scan_settings`), not one page with sections, because each page then keeps its own controller and view small.
5. "Run crawl now" runs the crawl inside the request (with `set_time_limit` and the 240 s budget); scheduled runs use a Concrete task. Large sites should schedule it.
6. The beacon is not referenced by page HTML. The runtime (already on every page) adds the beacon `<script>` only when the JavaScript-readable marker cookie `cmk_scan` exists, and the beacon route answers 204 without the signed HttpOnly token. This keeps ordinary visitors from making an extra request on every page, which the spec's "always reference the asset" wording would have caused. The beacon uses the Performance API (`getEntriesByType('resource')`), not a `PerformanceObserver`.
7. The crawl is one pass with a time budget, not batches: pages not reached count as failed, so an incomplete crawl never marks anything gone. This is simpler and has the same safety property.

---

### Task 1: Entities and settings

**Files:**
- Create: `src/Entity/ScanRun.php`, `src/Entity/ScanFinding.php`, `src/Entity/ClassificationEntry.php`
- Modify: `src/Entity/Settings.php` (append properties and accessors before the closing brace), `controller.php` (`$databaseTables`)
- Test: `tests/Entity/ScanRunTest.php`, `tests/Entity/ScanFindingTest.php`, `tests/Entity/ClassificationEntryTest.php`, `tests/Entity/SettingsScanTest.php`

**Interfaces:**
- Produces:
  - `ScanRun`: constants `CRAWL`, `BEACON`, `RUNNING`, `COMPLETE`, `FAILED`; `start(string $source, int $now): self`; `finish(string $status, int $now): self`; `getSource(): string`; `getStatus(): string`; `getStartedAt(): int`; `getFinishedAt(): ?int`; `getPagesScanned(): int`; `setPagesScanned(int): self`; `getNewCount(): int`; `getChangedCount(): int`; `getUnclassifiedCount(): int`; `setCounts(int $new, int $changed, int $unclassified): self`.
  - `ScanFinding`: constants `COOKIE='cookie'`, `STORAGE='storage'`, `SCRIPT='script'`, `IFRAME='iframe'`, `IMAGE='image'`, `RESOURCE='resource'`, `KINDS`, `UNCLASSIFIED`, `MATCHED`, `APPROVED`, `IGNORED`, `GONE`, `STATUSES`; `identify(string $kind, string $name, string $host): self`; `static fingerprintFor(string $kind, string $name, string $host): string`; getters `getKind/getName/getHost/getFingerprint/getFirstSeen/getLastSeen/getExamplePage/getStatus/getSuggestedCategory/getSuggestedVendor/getService`; `setFirstSeen(int)`, `setLastSeen(int)`, `setExamplePage(string)`, `setStatus(string)`, `setSuggestion(?string $category, ?string $vendor)`, `setService(?Service)`; `sources(): list<string>`, `hasSource(string): bool`, `addSource(string): self`, `removeSource(string): self`; `isReviewable(): bool`; `markGone(): self`; `restore(): self`.
  - `ClassificationEntry`: constants `COOKIE='cookie'`, `STORAGE='storage'`, `HOST='host'`, `BUNDLED`, `REFRESHED`, `OVERRIDE`; `define(string $kind, string $pattern, string $category, string $vendor, string $description, string $duration, string $origin): self`; matching getters.
  - `Settings`: `getScanMaxPages(): int`, `setScanMaxPages($v): self`, `getScanExcludes(): list<string>`, `getScanExcludesText(): string`, `setScanExcludesText($v): self`, `isScanScheduleEnabled(): bool`, `setScanScheduleEnabled($v): self`, `getScanAlertEmail(): string`, `setScanAlertEmail($v): self`, `getScanStaleDays(): int`, `setScanStaleDays($v): self`, `isClassificationRefreshEnabled(): bool`, `setClassificationRefreshEnabled($v): self`.

- [ ] **Step 1: Write the failing entity tests**

`tests/Entity/ScanRunTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ScanRun;

final class ScanRunTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertStringContainsString('name="cmkScanRuns"', (string) (new \ReflectionClass(ScanRun::class))->getDocComment());
    }

    public function testStartAndFinish(): void
    {
        $run = (new ScanRun())->start(ScanRun::CRAWL, 1000);

        $this->assertSame(ScanRun::CRAWL, $run->getSource());
        $this->assertSame(ScanRun::RUNNING, $run->getStatus());
        $this->assertSame(1000, $run->getStartedAt());
        $this->assertNull($run->getFinishedAt());

        $run->finish(ScanRun::COMPLETE, 1060)->setCounts(3, 1, 2)->setPagesScanned(12);

        $this->assertSame(ScanRun::COMPLETE, $run->getStatus());
        $this->assertSame(1060, $run->getFinishedAt());
        $this->assertSame([3, 1, 2, 12], [$run->getNewCount(), $run->getChangedCount(), $run->getUnclassifiedCount(), $run->getPagesScanned()]);
    }

    public function testUnknownSourceAndStatusFallBack(): void
    {
        $run = (new ScanRun())->start('evil', 1);
        $this->assertSame(ScanRun::CRAWL, $run->getSource());

        $run->finish('weird', 2);
        $this->assertSame(ScanRun::FAILED, $run->getStatus());
    }

    public function testCountsNeverGoNegative(): void
    {
        $run = (new ScanRun())->setCounts(-5, -1, -9)->setPagesScanned(-3);

        $this->assertSame([0, 0, 0, 0], [$run->getNewCount(), $run->getChangedCount(), $run->getUnclassifiedCount(), $run->getPagesScanned()]);
    }
}
```

`tests/Entity/ScanFindingTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ScanFinding;

final class ScanFindingTest extends TestCase
{
    public function testIdentityIsAFingerprintOfKindNameAndHost(): void
    {
        $a = (new ScanFinding())->identify('cookie', '_ga', 'example.com');
        $b = (new ScanFinding())->identify('cookie', '_ga', 'example.com');
        $c = (new ScanFinding())->identify('storage', '_ga', 'example.com');

        $this->assertSame($a->getFingerprint(), $b->getFingerprint());
        $this->assertNotSame($a->getFingerprint(), $c->getFingerprint());
        $this->assertSame(40, strlen($a->getFingerprint()));
    }

    public function testFingerprintDoesNotCollideAcrossFieldBoundaries(): void
    {
        $this->assertNotSame(
            ScanFinding::fingerprintFor('cookie', 'ab', 'c.example.com'),
            ScanFinding::fingerprintFor('cookie', 'a', 'bc.example.com'),
        );
    }

    public function testTableAndUniqueFingerprint(): void
    {
        $doc = (string) (new \ReflectionClass(ScanFinding::class))->getDocComment();

        $this->assertStringContainsString('name="cmkScanFindings"', $doc);
        $this->assertStringContainsString('columns={"fingerprint"}', $doc);
    }

    public function testDeletingAServiceKeepsTheFinding(): void
    {
        $property = (new \ReflectionClass(ScanFinding::class))->getProperty('service');

        $this->assertStringContainsString('onDelete="SET NULL"', (string) $property->getDocComment());
    }

    public function testKindAndStatusFallBackToSafeValues(): void
    {
        $finding = (new ScanFinding())->identify('<b>', 'x', 'example.com')->setStatus('hacked');

        $this->assertSame(ScanFinding::RESOURCE, $finding->getKind());
        $this->assertSame(ScanFinding::UNCLASSIFIED, $finding->getStatus());
    }

    public function testSourcesAreAddedOnceAndRemoved(): void
    {
        $finding = (new ScanFinding())->addSource('crawl')->addSource('crawl')->addSource('beacon')->addSource('nonsense');

        $this->assertSame(['crawl', 'beacon'], $finding->sources());
        $this->assertTrue($finding->hasSource('beacon'));

        $finding->removeSource('crawl');
        $this->assertSame(['beacon'], $finding->sources());

        $finding->removeSource('beacon');
        $this->assertSame([], $finding->sources());
    }

    public function testGoneRemembersTheStatusItReplaced(): void
    {
        $finding = (new ScanFinding())->setStatus(ScanFinding::MATCHED);

        $finding->markGone();
        $this->assertSame(ScanFinding::GONE, $finding->getStatus());

        $finding->restore();
        $this->assertSame(ScanFinding::MATCHED, $finding->getStatus());
    }

    public function testMarkingGoneTwiceKeepsTheOriginalMemory(): void
    {
        $finding = (new ScanFinding())->setStatus(ScanFinding::APPROVED);

        $finding->markGone()->markGone();
        $finding->restore();

        $this->assertSame(ScanFinding::APPROVED, $finding->getStatus());
    }

    public function testRestoreWithoutAMemoryFallsBackToUnclassified(): void
    {
        $finding = (new ScanFinding())->setStatus(ScanFinding::GONE);

        $finding->restore();

        $this->assertSame(ScanFinding::UNCLASSIFIED, $finding->getStatus());
    }

    public function testOnlyUnreviewedStatusesAreReviewable(): void
    {
        foreach ([ScanFinding::MATCHED, ScanFinding::UNCLASSIFIED] as $status) {
            $this->assertTrue((new ScanFinding())->setStatus($status)->isReviewable(), $status);
        }

        foreach ([ScanFinding::APPROVED, ScanFinding::IGNORED, ScanFinding::GONE] as $status) {
            $this->assertFalse((new ScanFinding())->setStatus($status)->isReviewable(), $status);
        }
    }

    public function testSuggestionIsStoredAndCleared(): void
    {
        $finding = (new ScanFinding())->setSuggestion('analytics', 'Google');
        $this->assertSame(['analytics', 'Google'], [$finding->getSuggestedCategory(), $finding->getSuggestedVendor()]);

        $finding->setSuggestion(null, null);
        $this->assertNull($finding->getSuggestedCategory());
        $this->assertNull($finding->getSuggestedVendor());
    }
}
```

`tests/Entity/ClassificationEntryTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ClassificationEntry;

final class ClassificationEntryTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertStringContainsString('name="cmkClassificationEntries"', (string) (new \ReflectionClass(ClassificationEntry::class))->getDocComment());
    }

    public function testDefineStoresCleanValues(): void
    {
        $entry = (new ClassificationEntry())->define('cookie', '_ga', 'analytics', "Goo\nogle", '<i>Distinguishes</i> visitors', '2 years', ClassificationEntry::BUNDLED);

        $this->assertSame('cookie', $entry->getKind());
        $this->assertSame('_ga', $entry->getPattern());
        $this->assertSame('analytics', $entry->getCategoryHandle());
        $this->assertSame('Goo ogle', $entry->getVendor());
        $this->assertSame('Distinguishes visitors', $entry->getDescription());
        $this->assertSame('2 years', $entry->getDuration());
        $this->assertSame(ClassificationEntry::BUNDLED, $entry->getOrigin());
    }

    public function testUnknownKindAndOriginFallBack(): void
    {
        $entry = (new ClassificationEntry())->define('weird', '_x', 'analytics', '', '', '', 'sneaky');

        $this->assertSame(ClassificationEntry::COOKIE, $entry->getKind());
        $this->assertSame(ClassificationEntry::BUNDLED, $entry->getOrigin());
    }
}
```

`tests/Entity/SettingsScanTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Settings;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsScanTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame(50, $settings->getScanMaxPages());
        $this->assertSame([], $settings->getScanExcludes());
        $this->assertFalse($settings->isScanScheduleEnabled());
        $this->assertSame('', $settings->getScanAlertEmail());
        $this->assertSame(60, $settings->getScanStaleDays());
        $this->assertFalse($settings->isClassificationRefreshEnabled());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function pageCaps(): array
    {
        return [
            'zero becomes one' => [0, 1],
            'negative becomes one' => [-4, 1],
            'too large is capped' => [99999, 500],
            'text falls back to the default' => ['abc', 50],
            'numeric text is used' => ['120', 120],
            'null falls back' => [null, 50],
        ];
    }

    #[DataProvider('pageCaps')]
    public function testMaxPagesIsClamped(mixed $given, int $expected): void
    {
        $this->assertSame($expected, (new Settings())->setScanMaxPages($given)->getScanMaxPages());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function staleDays(): array
    {
        return [
            'too small' => [1, 7],
            'too large' => [9999, 365],
            'text' => ['x', 60],
            'valid' => [30, 30],
        ];
    }

    #[DataProvider('staleDays')]
    public function testStaleDaysIsClamped(mixed $given, int $expected): void
    {
        $this->assertSame($expected, (new Settings())->setScanStaleDays($given)->getScanStaleDays());
    }

    public function testExcludesKeepOnlyPlainPathPatterns(): void
    {
        $settings = (new Settings())->setScanExcludesText("/members\n  /shop/*  \nno-slash\n/bad path\n<script>\n/members\nhttps://evil.example/x\n/ok-path_1.2~x%20y");

        $this->assertSame(['/members', '/shop/*', '/ok-path_1.2~x%20y'], $settings->getScanExcludes());
        $this->assertSame("/members\n/shop/*\n/ok-path_1.2~x%20y", $settings->getScanExcludesText());
    }

    public function testExcludesAreCapped(): void
    {
        $lines = implode("\n", array_map(static fn(int $i): string => '/p' . $i, range(1, 80)));

        $this->assertCount(50, (new Settings())->setScanExcludesText($lines)->getScanExcludes());
    }

    public function testNonStringExcludesAreIgnored(): void
    {
        $this->assertSame([], (new Settings())->setScanExcludesText(['/a'])->getScanExcludes());
    }

    public function testAlertEmailMustBeAValidAddress(): void
    {
        $settings = new Settings();

        $this->assertSame('ops@example.com', $settings->setScanAlertEmail(' ops@example.com ')->getScanAlertEmail());
        $this->assertSame('', $settings->setScanAlertEmail("a@example.com\nBcc: b@example.com")->getScanAlertEmail());
        $this->assertSame('', $settings->setScanAlertEmail('not an email')->getScanAlertEmail());
        $this->assertSame('', $settings->setScanAlertEmail(null)->getScanAlertEmail());
    }

    public function testSwitchesCoerceToBool(): void
    {
        $settings = (new Settings())->setScanScheduleEnabled('1')->setClassificationRefreshEnabled(1);

        $this->assertTrue($settings->isScanScheduleEnabled());
        $this->assertTrue($settings->isClassificationRefreshEnabled());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'ScanRunTest|ScanFindingTest|ClassificationEntryTest|SettingsScanTest'`
Expected: FAIL with "Class ... ScanRun not found" and "Call to undefined method Settings::getScanMaxPages".

- [ ] **Step 3: Write `src/Entity/ScanRun.php`**

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\BaseEntity;

/**
 * One scan: a crawl, or one admin scan-mode session. Times are UTC unix seconds.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkScanRuns",
 *      options={"comment": "Cookie Manager scan runs"}
 * )
 */
class ScanRun extends BaseEntity
{
    public const CRAWL = 'crawl';
    public const BEACON = 'beacon';
    public const RUNNING = 'running';
    public const COMPLETE = 'complete';
    public const FAILED = 'failed';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "crawl", "comment": "crawl or beacon"})
     */
    protected $source = self::CRAWL;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Start, UTC unix seconds"})
     */
    protected $startedAt = 0;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"comment": "End, UTC unix seconds"})
     */
    protected $finishedAt;

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "running", "comment": "running, complete or failed"})
     */
    protected $status = self::RUNNING;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Pages crawled, or reports received"})
     */
    protected $pagesScanned = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Findings first seen in this run"})
     */
    protected $newCount = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Findings that came back or went away"})
     */
    protected $changedCount = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Unclassified findings after this run"})
     */
    protected $unclassifiedCount = 0;

    public function start(string $source, int $now): self
    {
        $this->source = in_array($source, [self::CRAWL, self::BEACON], true) ? $source : self::CRAWL;
        $this->startedAt = $now;
        $this->status = self::RUNNING;
        $this->finishedAt = null;

        return $this;
    }

    public function finish(string $status, int $now): self
    {
        $this->status = in_array($status, [self::COMPLETE, self::FAILED], true) ? $status : self::FAILED;
        $this->finishedAt = $now;

        return $this;
    }

    public function getSource(): string
    {
        return (string) $this->source;
    }

    public function getStatus(): string
    {
        return (string) $this->status;
    }

    public function getStartedAt(): int
    {
        return (int) $this->startedAt;
    }

    public function getFinishedAt(): ?int
    {
        return $this->finishedAt === null ? null : (int) $this->finishedAt;
    }

    public function getPagesScanned(): int
    {
        return (int) $this->pagesScanned;
    }

    public function setPagesScanned(int $pages): self
    {
        $this->pagesScanned = max(0, $pages);

        return $this;
    }

    public function getNewCount(): int
    {
        return (int) $this->newCount;
    }

    public function getChangedCount(): int
    {
        return (int) $this->changedCount;
    }

    public function getUnclassifiedCount(): int
    {
        return (int) $this->unclassifiedCount;
    }

    public function setCounts(int $new, int $changed, int $unclassified): self
    {
        $this->newCount = max(0, $new);
        $this->changedCount = max(0, $changed);
        $this->unclassifiedCount = max(0, $unclassified);

        return $this;
    }
}
```

- [ ] **Step 4: Write `src/Entity/ScanFinding.php`**

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * One distinct thing a scan saw. Identity is (kind, name, host), stored as a fingerprint because a composite unique
 * key over those columns can exceed index-length limits. Names and hosts come from the public internet: they are
 * length-capped and control-stripped by the scanners, kept verbatim here, and encoded wherever they are shown.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkScanFindings",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_finding_fingerprint", columns={"fingerprint"})},
 *      indexes={@ORM\Index(name="idx_cmk_finding_status", columns={"status"})},
 *      options={"comment": "Cookie Manager scan findings"}
 * )
 */
class ScanFinding extends UpdatedEntity
{
    public const COOKIE = 'cookie';
    public const STORAGE = 'storage';
    public const SCRIPT = 'script';
    public const IFRAME = 'iframe';
    public const IMAGE = 'image';
    public const RESOURCE = 'resource';
    public const KINDS = [self::COOKIE, self::STORAGE, self::SCRIPT, self::IFRAME, self::IMAGE, self::RESOURCE];

    public const UNCLASSIFIED = 'unclassified';
    public const MATCHED = 'matched';
    public const APPROVED = 'approved';
    public const IGNORED = 'ignored';
    public const GONE = 'gone';
    public const STATUSES = [self::UNCLASSIFIED, self::MATCHED, self::APPROVED, self::IGNORED, self::GONE];

    private const SOURCES = ['crawl', 'beacon'];

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "resource", "comment": "cookie, storage, script, iframe, image or resource"})
     */
    protected $kind = self::RESOURCE;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Cookie or storage key name; the host for other kinds"})
     */
    protected $name = '';

    /**
     * @ORM\Column(type="string", length=253, nullable=false, options={"default": "", "comment": "Host it was seen on or loaded from"})
     */
    protected $host = '';

    /**
     * @ORM\Column(type="string", length=40, nullable=false, options={"comment": "sha1 of kind, name and host"})
     */
    protected $fingerprint = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "First seen, UTC unix seconds"})
     */
    protected $firstSeen = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Last seen, UTC unix seconds"})
     */
    protected $lastSeen = 0;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"default": "", "comment": "crawl, beacon or both"})
     */
    protected $sources = '';

    /**
     * @ORM\Column(type="string", length=512, nullable=false, options={"default": "", "comment": "A page it was found on"})
     */
    protected $examplePage = '';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "unclassified", "comment": "Review status"})
     */
    protected $status = self::UNCLASSIFIED;

    /**
     * @ORM\Column(type="string", length=16, nullable=true, options={"comment": "Status to return to if a gone finding reappears"})
     */
    protected $restoreStatus;

    /**
     * @ORM\Column(type="string", length=32, nullable=true, options={"comment": "Suggested category handle"})
     */
    protected $suggestedCategory;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, options={"comment": "Suggested vendor"})
     */
    protected $suggestedVendor;

    /**
     * @ORM\ManyToOne(targetEntity="Service")
     * @ORM\JoinColumn(name="serviceID", referencedColumnName="id", onDelete="SET NULL", nullable=true)
     */
    protected $service;

    public static function fingerprintFor(string $kind, string $name, string $host): string
    {
        return sha1($kind . "\0" . $name . "\0" . $host);
    }

    public function identify(string $kind, string $name, string $host): self
    {
        $this->kind = in_array($kind, self::KINDS, true) ? $kind : self::RESOURCE;
        $this->name = $name;
        $this->host = $host;
        $this->fingerprint = self::fingerprintFor($this->kind, $name, $host);

        return $this;
    }

    public function getKind(): string
    {
        return (string) $this->kind;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function getHost(): string
    {
        return (string) $this->host;
    }

    public function getFingerprint(): string
    {
        return (string) $this->fingerprint;
    }

    public function getFirstSeen(): int
    {
        return (int) $this->firstSeen;
    }

    public function setFirstSeen(int $time): self
    {
        $this->firstSeen = $time;

        return $this;
    }

    public function getLastSeen(): int
    {
        return (int) $this->lastSeen;
    }

    public function setLastSeen(int $time): self
    {
        $this->lastSeen = $time;

        return $this;
    }

    public function getExamplePage(): string
    {
        return (string) $this->examplePage;
    }

    public function setExamplePage(string $page): self
    {
        $this->examplePage = mb_substr($page, 0, 512, 'UTF-8');

        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = in_array($status, self::STATUSES, true) ? $status : self::UNCLASSIFIED;

        return $this;
    }

    public function getSuggestedCategory(): ?string
    {
        return $this->suggestedCategory === null ? null : (string) $this->suggestedCategory;
    }

    public function getSuggestedVendor(): ?string
    {
        return $this->suggestedVendor === null ? null : (string) $this->suggestedVendor;
    }

    public function setSuggestion(?string $category, ?string $vendor): self
    {
        $this->suggestedCategory = $category;
        $this->suggestedVendor = $vendor;

        return $this;
    }

    public function getService(): ?Service
    {
        return $this->service instanceof Service ? $this->service : null;
    }

    public function setService(?Service $service): self
    {
        $this->service = $service;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->sources === '' ? [] : explode(',', (string) $this->sources);
    }

    public function hasSource(string $source): bool
    {
        return in_array($source, $this->sources(), true);
    }

    public function addSource(string $source): self
    {
        if (in_array($source, self::SOURCES, true) && !$this->hasSource($source)) {
            $this->sources = implode(',', [...$this->sources(), $source]);
        }

        return $this;
    }

    public function removeSource(string $source): self
    {
        $this->sources = implode(',', array_values(array_filter($this->sources(), static fn(string $have): bool => $have !== $source)));

        return $this;
    }

    public function isReviewable(): bool
    {
        return in_array($this->getStatus(), [self::MATCHED, self::UNCLASSIFIED], true);
    }

    public function markGone(): self
    {
        if ($this->getStatus() !== self::GONE) {
            $this->restoreStatus = $this->getStatus();
            $this->status = self::GONE;
        }

        return $this;
    }

    public function restore(): self
    {
        if ($this->getStatus() === self::GONE) {
            $back = (string) $this->restoreStatus;
            $this->status = in_array($back, self::STATUSES, true) && $back !== self::GONE ? $back : self::UNCLASSIFIED;
            $this->restoreStatus = null;
        }

        return $this;
    }
}
```

- [ ] **Step 5: Write `src/Entity/ClassificationEntry.php`**

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\BaseEntity;

/**
 * One known cookie, storage key or host pattern and the category it usually belongs to. `origin` decides
 * precedence: override (an admin's choice) beats refreshed (downloaded) beats bundled (shipped with the package).
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkClassificationEntries",
 *      indexes={@ORM\Index(name="idx_cmk_classification_origin", columns={"origin"})},
 *      options={"comment": "Cookie Manager classification data"}
 * )
 */
class ClassificationEntry extends BaseEntity
{
    public const COOKIE = 'cookie';
    public const STORAGE = 'storage';
    public const HOST = 'host';
    public const BUNDLED = 'bundled';
    public const REFRESHED = 'refreshed';
    public const OVERRIDE = 'override';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "cookie", "comment": "cookie, storage or host"})
     */
    protected $kind = self::COOKIE;

    /**
     * @ORM\Column(type="string", length=128, nullable=false, options={"default": "", "comment": "Exact value, or a pattern with *"})
     */
    protected $pattern = '';

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"default": "", "comment": "Category handle"})
     */
    protected $categoryHandle = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Vendor"})
     */
    protected $vendor = '';

    /**
     * @ORM\Column(type="string", length=500, nullable=false, options={"default": "", "comment": "What it is for"})
     */
    protected $description = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": "", "comment": "How long it lasts"})
     */
    protected $duration = '';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "bundled", "comment": "bundled, refreshed or override"})
     */
    protected $origin = self::BUNDLED;

    public function define(string $kind, string $pattern, string $category, string $vendor, string $description, string $duration, string $origin): self
    {
        $this->kind = in_array($kind, [self::COOKIE, self::STORAGE, self::HOST], true) ? $kind : self::COOKIE;
        $this->pattern = mb_substr($pattern, 0, 128, 'UTF-8');
        $this->categoryHandle = Text::isHandle($category) ? $category : '';
        $this->vendor = Text::plain($vendor);
        $this->description = Text::plain($description, 500);
        $this->duration = Text::plain($duration, 64);
        $this->origin = in_array($origin, [self::BUNDLED, self::REFRESHED, self::OVERRIDE], true) ? $origin : self::BUNDLED;

        return $this;
    }

    public function getKind(): string
    {
        return (string) $this->kind;
    }

    public function getPattern(): string
    {
        return (string) $this->pattern;
    }

    public function getCategoryHandle(): string
    {
        return (string) $this->categoryHandle;
    }

    public function getVendor(): string
    {
        return (string) $this->vendor;
    }

    public function getDescription(): string
    {
        return (string) $this->description;
    }

    public function getDuration(): string
    {
        return (string) $this->duration;
    }

    public function getOrigin(): string
    {
        return (string) $this->origin;
    }
}
```

- [ ] **Step 6: Add the settings properties and accessors**

In `src/Entity/Settings.php`, add these properties directly after the `$policyVersion` property block (before the `get()` method):

```php
    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 50, "comment": "Most pages one crawl will fetch"})
     */
    protected $scanMaxPages = 50;

    /**
     * @ORM\Column(type="string", length=1000, nullable=false, options={"default": "", "comment": "Path patterns the crawl skips, one per line"})
     */
    protected $scanExcludes = '';

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false, "comment": "Run the scheduled crawl"})
     */
    protected $scanSchedule = false;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Where scan alerts are emailed"})
     */
    protected $scanAlertEmail = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 60, "comment": "Days without a scan-mode run before it is flagged"})
     */
    protected $scanStaleDays = 60;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false, "comment": "Download newer classification data"})
     */
    protected $classificationRefresh = false;
```

Append these methods before the final `}` of the class:

```php
    private static function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        $number = is_int($value) || (is_string($value) && preg_match('/^-?\d{1,9}\z/', trim($value)) === 1) ? (int) $value : $default;

        return max($min, min($max, $number));
    }

    public function getScanMaxPages(): int
    {
        return self::clampInt($this->scanMaxPages, 1, 500, 50);
    }

    public function setScanMaxPages($value): self
    {
        $this->scanMaxPages = self::clampInt($value, 1, 500, 50);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getScanExcludes(): array
    {
        return $this->scanExcludes === '' ? [] : explode("\n", (string) $this->scanExcludes);
    }

    public function getScanExcludesText(): string
    {
        return (string) $this->scanExcludes;
    }

    /**
     * Keeps only plain path patterns: a leading slash, then path characters and `*`. Anything else is dropped.
     */
    public function setScanExcludesText($value): self
    {
        $patterns = [];

        foreach (preg_split('/\R/', is_string($value) ? $value : '') ?: [] as $line) {
            $line = trim($line);

            if (preg_match('#^/[A-Za-z0-9/_\-.~%*]{0,199}\z#', $line) === 1) {
                $patterns[] = $line;
            }
        }

        $this->scanExcludes = implode("\n", array_slice(array_values(array_unique($patterns)), 0, 50));

        return $this;
    }

    public function isScanScheduleEnabled(): bool
    {
        return (bool) $this->scanSchedule;
    }

    public function setScanScheduleEnabled($value): self
    {
        $this->scanSchedule = (bool) $value;

        return $this;
    }

    public function getScanAlertEmail(): string
    {
        return (string) $this->scanAlertEmail;
    }

    public function setScanAlertEmail($value): self
    {
        $email = is_string($value) ? trim($value) : '';

        $this->scanAlertEmail = strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';

        return $this;
    }

    public function getScanStaleDays(): int
    {
        return self::clampInt($this->scanStaleDays, 7, 365, 60);
    }

    public function setScanStaleDays($value): self
    {
        $this->scanStaleDays = self::clampInt($value, 7, 365, 60);

        return $this;
    }

    public function isClassificationRefreshEnabled(): bool
    {
        return (bool) $this->classificationRefresh;
    }

    public function setClassificationRefreshEnabled($value): self
    {
        $this->classificationRefresh = (bool) $value;

        return $this;
    }
```

- [ ] **Step 7: Register the tables for purge on uninstall**

In `controller.php`, replace the `$databaseTables` array with (children before parents; findings reference services, so they go first):

```php
    protected $databaseTables = [
        'cmkScanFindings',
        'cmkScanRuns',
        'cmkClassificationEntries',
        'cmkStats',
        'cmkServiceContents',
        'cmkCategoryContents',
        'cmkServices',
        'cmkCategories',
        'cmkBannerContents',
        'cmkSettings',
    ];
```

- [ ] **Step 8: Run the tests and the full suite**

Run: `composer test`
Expected: PASS (491 existing tests plus the new ones).

- [ ] **Step 9: Format, type-check, commit**

```bash
composer format:php && composer typecheck
git add src/Entity controller.php tests/Entity
git commit -m "feat: add scan run, finding and classification entities and scanner settings

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Host and name cleaning

**Files:**
- Create: `src/Scan/Host.php`, `src/Scan/Clean.php`
- Test: `tests/Scan/HostTest.php`, `tests/Scan/CleanTest.php`

**Interfaces:**
- Produces:
  - `Host::normalise(mixed $host): ?string` (lower-cased, trailing dot stripped; a valid domain name or IPv4 literal, else `null`)
  - `Host::fromUrl(mixed $url): ?string` (host of an absolute or scheme-relative `http(s)` URL; `null` for relative, other schemes, or invalid hosts)
  - `Host::sameSite(string $host, string $siteHost): bool` (equal, or the `www.` twin)
  - `Clean::name(mixed $value, int $max = 128): ?string` (UTF-8 scrubbed, control and format characters removed, trimmed, capped; `null` when empty or not a string)

- [ ] **Step 1: Write the failing tests**

`tests/Scan/HostTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\Host;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HostTest extends TestCase
{
    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function hosts(): array
    {
        return [
            'plain' => ['example.com', 'example.com'],
            'upper case' => ['WWW.Example.COM', 'www.example.com'],
            'trailing dot' => ['example.com.', 'example.com'],
            'punycode' => ['xn--bcher-kva.example', 'xn--bcher-kva.example'],
            'ipv4' => ['203.0.113.7', '203.0.113.7'],
            'single label' => ['localhost', null],
            'space' => ['exa mple.com', null],
            'underscore' => ['bad_host.example.com', null],
            'leading hyphen' => ['-bad.example.com', null],
            'angle brackets' => ['<script>.example.com', null],
            'numeric tld' => ['1.2.3.999', null],
            'too long' => [str_repeat('a', 64) . '.example.com', null],
            'whole name too long' => [str_repeat('a.', 130) . 'com', null],
            'empty' => ['', null],
            'array' => [['example.com'], null],
            'null' => [null, null],
            'ipv6' => ['[::1]', null],
        ];
    }

    #[DataProvider('hosts')]
    public function testNormalise(mixed $given, ?string $expected): void
    {
        $this->assertSame($expected, Host::normalise($given));
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function urls(): array
    {
        return [
            'https' => ['https://cdn.example.net/a.js?x=1', 'cdn.example.net'],
            'http' => ['http://CDN.Example.net/a.js', 'cdn.example.net'],
            'scheme relative' => ['//stats.example.net/p.gif', 'stats.example.net'],
            'with port' => ['https://cdn.example.net:8443/a.js', 'cdn.example.net'],
            'relative path' => ['/local.js', null],
            'relative file' => ['local.js', null],
            'javascript' => ['javascript:alert(1)', null],
            'data' => ['data:image/png;base64,AAAA', null],
            'ftp' => ['ftp://files.example.net/x', null],
            'userinfo trick' => ['https://example.com@evil.example.net/x', 'evil.example.net'],
            'garbage' => ['https://', null],
            'spaces in host' => ['https://exa mple.com/x', null],
            'whitespace around' => ["  https://cdn.example.net/a.js \n", 'cdn.example.net'],
            'not a string' => [42, null],
        ];
    }

    #[DataProvider('urls')]
    public function testFromUrl(mixed $given, ?string $expected): void
    {
        $this->assertSame($expected, Host::fromUrl($given));
    }

    public function testSameSiteIsExactOrTheWwwTwin(): void
    {
        $this->assertTrue(Host::sameSite('example.com', 'example.com'));
        $this->assertTrue(Host::sameSite('www.example.com', 'example.com'));
        $this->assertTrue(Host::sameSite('example.com', 'www.example.com'));
        $this->assertFalse(Host::sameSite('cdn.example.com', 'example.com'));
        $this->assertFalse(Host::sameSite('example.com.evil.net', 'example.com'));
    }
}
```

`tests/Scan/CleanTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\Clean;
use PHPUnit\Framework\TestCase;

final class CleanTest extends TestCase
{
    public function testKeepsMarkupVerbatimBecauseOutputEncodingIsTheDefence(): void
    {
        $this->assertSame('<b>x</b>', Clean::name('<b>x</b>'));
        $this->assertSame('"><img src=x onerror=alert(1)>', Clean::name('"><img src=x onerror=alert(1)>'));
    }

    public function testStripsControlAndFormatCharacters(): void
    {
        $this->assertSame('a[31mb', Clean::name("a\x00\x1b[31m\u{200B}b"));
        $this->assertSame('ab', Clean::name("a\r\nb"));
        $this->assertSame('ab', Clean::name("a\u{2028}b"));
    }

    public function testTrimsAndRejectsEmptyOrNonStrings(): void
    {
        $this->assertSame('_ga', Clean::name("  _ga \n"));
        $this->assertNull(Clean::name('   '));
        $this->assertNull(Clean::name("\x00\x01"));
        $this->assertNull(Clean::name(['_ga']));
        $this->assertNull(Clean::name(null));
    }

    public function testCapsLengthInCharactersNotBytes(): void
    {
        $this->assertSame(128, mb_strlen((string) Clean::name(str_repeat('é', 10000)), 'UTF-8'));
        $this->assertSame('abc', Clean::name('abcdef', 3));
    }

    public function testInvalidUtf8IsScrubbedNotFatal(): void
    {
        $clean = Clean::name("bad\xff\xfename");

        $this->assertIsString($clean);
        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'HostTest|CleanTest'`
Expected: FAIL with "Class CookieManagerKit\Scan\Host not found".

- [ ] **Step 3: Write `src/Scan/Host.php`**

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * Host validation for everything a scan records. A host is a domain name or an IPv4 literal; anything else
 * (IPv6 literals, single labels, spaces, markup) is not recorded.
 */
final class Host
{
    public static function normalise(mixed $host): ?string
    {
        if (!is_string($host)) {
            return null;
        }

        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host) !== 1) {
            return null;
        }

        // A numeric last label would make "1.2.3.999" look like a host; real top-level domains are never numeric.
        $last = substr($host, (int) strrpos($host, '.') + 1);

        return ctype_digit($last) ? null : $host;
    }

    /**
     * The host of an absolute or scheme-relative http(s) URL. Relative URLs belong to the page itself and give null.
     */
    public static function fromUrl(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }

        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        return self::normalise($parts['host'] ?? null);
    }

    public static function sameSite(string $host, string $siteHost): bool
    {
        $strip = static fn(string $value): string => str_starts_with($value, 'www.') ? substr($value, 4) : $value;

        return $strip(strtolower($host)) === $strip(strtolower($siteHost));
    }
}
```

- [ ] **Step 4: Write `src/Scan/Clean.php`**

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * Cleans a name that came from a page or a browser (cookie name, storage key). Markup is deliberately kept: names
 * are shown only through output encoding, and rewriting them would make two different cookies look the same.
 */
final class Clean
{
    public static function name(mixed $value, int $max = 128): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', '', $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `composer test -- --filter 'HostTest|CleanTest'`
Expected: PASS. (In the control-characters test the escape and zero-width characters are removed; the printable `[31m` stays.)

- [ ] **Step 6: Commit**

```bash
composer format:php && composer typecheck
git add src/Scan tests/Scan
git commit -m "feat: add host validation and name cleaning for scan data

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Page parser and cookie header parser

**Files:**
- Create: `src/Scan/Finding.php`, `src/Scan/PageParser.php`, `src/Scan/CookieHeader.php`
- Test: `tests/Scan/PageParserTest.php`, `tests/Scan/CookieHeaderTest.php`

**Interfaces:**
- Consumes: `Host::fromUrl`, `Host::sameSite`, `Host::normalise`, `Clean::name`, `ScanFinding` kind constants.
- Produces:
  - `Finding` (final readonly): `__construct(public string $kind, public string $name, public string $host, public string $page = '')`
  - `PageParser::findings(string $html, string $pageUrl, string $siteHost): list<Finding>` (third-party hosts only, de-duplicated per page by kind and host, at most 200)
  - `CookieHeader::findings(list<string> $setCookieLines, string $siteHost, string $pageUrl): list<Finding>`

- [ ] **Step 1: Write the failing tests**

`tests/Scan/PageParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\PageParser;
use PHPUnit\Framework\TestCase;

final class PageParserTest extends TestCase
{
    /**
     * @param list<Finding> $found
     *
     * @return list<string>
     */
    private static function summary(array $found): array
    {
        $rows = array_map(static fn(Finding $f): string => $f->kind . ' ' . $f->host, $found);
        sort($rows);

        return $rows;
    }

    public function testFindsThirdPartyTagsByKind(): void
    {
        $html = '<html><head>'
            . '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-1"></script>'
            . '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=X">'
            . '<link rel="canonical" href="https://other.example.org/">'
            . '</head><body>'
            . '<img src="//stats.example.net/p.gif">'
            . '<iframe src="https://www.youtube.com/embed/x"></iframe>'
            . '<script src="/local.js"></script>'
            . '<script>fetch("https://api.segment.io/v1/t")</script>'
            . '</body></html>';

        $found = PageParser::findings($html, 'https://example.com/about', 'example.com');

        $this->assertSame([
            'iframe www.youtube.com',
            'image stats.example.net',
            'resource fonts.googleapis.com',
            'script api.segment.io',
            'script www.googletagmanager.com',
        ], self::summary($found));
        $this->assertSame('https://example.com/about', $found[0]->page);
    }

    public function testNameIsTheHostForTagKinds(): void
    {
        $found = PageParser::findings('<script src="https://cdn.example.net/a.js"></script>', 'https://example.com/', 'example.com');

        $this->assertSame('cdn.example.net', $found[0]->name);
    }

    public function testTheSitesOwnHostAndItsWwwTwinAreNotFindings(): void
    {
        $html = '<script src="https://www.example.com/a.js"></script><script src="https://example.com/b.js"></script>';

        $this->assertSame([], PageParser::findings($html, 'https://example.com/', 'example.com'));
    }

    public function testUnsafeSchemesAreIgnored(): void
    {
        $html = '<img src="data:image/png;base64,AAAA"><script src="javascript:alert(1)"></script><iframe src="about:blank"></iframe>';

        $this->assertSame([], PageParser::findings($html, 'https://example.com/', 'example.com'));
    }

    public function testEachKindAndHostIsReportedOncePerPage(): void
    {
        $html = '<script src="https://cdn.example.net/a.js"></script><script src="https://cdn.example.net/b.js"></script><img src="https://cdn.example.net/c.gif">';

        $this->assertSame(['image cdn.example.net', 'script cdn.example.net'], self::summary(PageParser::findings($html, 'https://example.com/', 'example.com')));
    }

    public function testOnlyResourceLinkRelationsCount(): void
    {
        $html = '<link rel="preconnect" href="https://fonts.gstatic.com"><link rel="alternate" href="https://alt.example.net/">'
            . '<link rel="author" href="https://who.example.net/"><link rel="dns-prefetch" href="//ads.example.net">';

        $this->assertSame(['resource ads.example.net', 'resource fonts.gstatic.com'], self::summary(PageParser::findings($html, 'https://example.com/', 'example.com')));
    }

    public function testInlineScriptsContributeHostsTheyMention(): void
    {
        $html = '<script>var u="//tracker.example.net/t.js"; var v = "https://pix.example.org/p";</script>';

        $this->assertSame(['script pix.example.org', 'script tracker.example.net'], self::summary(PageParser::findings($html, 'https://example.com/', 'example.com')));
    }

    public function testAtMostTwoHundredFindingsPerPage(): void
    {
        $html = '';

        for ($i = 0; $i < 300; $i++) {
            $html .= '<script src="https://h' . $i . '.example.net/a.js"></script>';
        }

        $this->assertCount(200, PageParser::findings($html, 'https://example.com/', 'example.com'));
    }

    public function testGarbageEmptyAndBinaryInputGiveNothingAndNeverThrow(): void
    {
        foreach (['', '   ', "\xff\xfe<<>>\x00", '<<<<<>>>>>', str_repeat('<div>', 5000), '<script src=', '<script src="https://cdn.example.net/a.js'] as $html) {
            $found = PageParser::findings($html, 'https://example.com/', 'example.com');

            $this->assertContainsOnlyInstancesOf(Finding::class, $found);
        }

        $this->assertSame([], PageParser::findings('', 'https://example.com/', 'example.com'));
    }

    public function testHostileAttributeValuesNeverBecomeHosts(): void
    {
        $html = '<script src="https://&lt;script&gt;.example.net/x.js"></script><img src="https://exa mple.com/x.gif"><iframe src="https://[::1]/x"></iframe>';

        $this->assertSame([], PageParser::findings($html, 'https://example.com/', 'example.com'));
    }
}
```

`tests/Scan/CookieHeaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\CookieHeader;
use CookieManagerKit\Scan\Finding;
use PHPUnit\Framework\TestCase;

final class CookieHeaderTest extends TestCase
{
    /**
     * @param list<Finding> $found
     *
     * @return list<string>
     */
    private static function summary(array $found): array
    {
        return array_map(static fn(Finding $f): string => $f->kind . ' ' . $f->name . ' ' . $f->host, $found);
    }

    public function testNameAndHostFromSetCookieLines(): void
    {
        $found = CookieHeader::findings([
            '_ga=GA1.2.3; Domain=.example.com; Path=/; Max-Age=63072000',
            'sid=abc; Path=/; HttpOnly; Secure',
        ], 'www.example.com', 'https://www.example.com/');

        $this->assertSame(['cookie _ga example.com', 'cookie sid www.example.com'], self::summary($found));
        $this->assertSame('https://www.example.com/', $found[0]->page);
    }

    public function testADomainAttributeForAnotherSiteIsIgnored(): void
    {
        $found = CookieHeader::findings(['x=1; Domain=evil.example.net'], 'example.com', 'https://example.com/');

        $this->assertSame(['cookie x example.com'], self::summary($found));
    }

    public function testNeverReadsTheValue(): void
    {
        $found = CookieHeader::findings(['token=SECRET-VALUE-123; Path=/'], 'example.com', 'https://example.com/');

        $this->assertStringNotContainsString('SECRET', serialize($found));
    }

    public function testMarkupInANameIsKeptVerbatim(): void
    {
        $found = CookieHeader::findings(['<b>x</b>=1'], 'example.com', 'https://example.com/');

        $this->assertSame('<b>x</b>', $found[0]->name);
    }

    public function testEmptyAndMalformedLinesAreSkipped(): void
    {
        $found = CookieHeader::findings(['', '=novalue', 'noequals', "\x00=1", '   ; Path=/', 'ok=1'], 'example.com', 'https://example.com/');

        $this->assertSame(['cookie ok example.com'], self::summary($found));
    }

    public function testRepeatedNamesAreReportedOnce(): void
    {
        $found = CookieHeader::findings(['a=1', 'a=2; Path=/x'], 'example.com', 'https://example.com/');

        $this->assertCount(1, $found);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'PageParserTest|CookieHeaderTest'`
Expected: FAIL with "Class ... Finding not found".

- [ ] **Step 3: Write `src/Scan/Finding.php`**

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * What a scanner reports: one thing seen on one page. Plain data; nothing here is trusted.
 */
final readonly class Finding
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $host,
        public string $page = '',
    ) {}
}
```

- [ ] **Step 4: Write `src/Scan/PageParser.php`**

```php
<?php

namespace CookieManagerKit\Scan;

use Dom\HTMLDocument;
use CookieManagerKit\Entity\ScanFinding;

/**
 * Reads the third-party hosts a page loads. Uses the WHATWG HTML parser (like TrackingCode), so the tags found are
 * exactly those a browser would see; regex over markup is not used. Nothing is executed or fetched.
 */
final class PageParser
{
    private const MAX_FINDINGS = 200;
    private const LINK_RELATIONS = ['stylesheet', 'preload', 'prefetch', 'preconnect', 'dns-prefetch', 'modulepreload', 'icon'];

    /**
     * @return list<Finding>
     */
    public static function findings(string $html, string $pageUrl, string $siteHost): array
    {
        if (trim($html) === '') {
            return [];
        }

        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (\Throwable) {
            return [];
        }

        $found = [];
        $add = static function (string $kind, ?string $host) use (&$found, $siteHost, $pageUrl): void {
            if ($host === null || Host::sameSite($host, $siteHost) || count($found) >= self::MAX_FINDINGS) {
                return;
            }

            $key = $kind . ' ' . $host;

            if (!isset($found[$key])) {
                $found[$key] = new Finding($kind, $host, $host, $pageUrl);
            }
        };

        foreach ($document->querySelectorAll('script, iframe[src], img[src], link[href]') as $element) {
            $tag = strtolower($element->localName);

            if ($tag === 'script') {
                if ($element->hasAttribute('src')) {
                    $add(ScanFinding::SCRIPT, Host::fromUrl($element->getAttribute('src')));

                    continue;
                }

                preg_match_all('#(?:https?:)?//([A-Za-z0-9][A-Za-z0-9.-]{0,252}\.[A-Za-z0-9-]{2,63})(?![A-Za-z0-9.-])#', (string) $element->textContent, $matches);

                foreach ($matches[1] as $host) {
                    $add(ScanFinding::SCRIPT, Host::normalise($host));
                }
            } elseif ($tag === 'iframe') {
                $add(ScanFinding::IFRAME, Host::fromUrl($element->getAttribute('src')));
            } elseif ($tag === 'img') {
                $add(ScanFinding::IMAGE, Host::fromUrl($element->getAttribute('src')));
            } elseif ($tag === 'link' && self::isResourceLink((string) $element->getAttribute('rel'))) {
                $add(ScanFinding::RESOURCE, Host::fromUrl($element->getAttribute('href')));
            }
        }

        return array_values($found);
    }

    private static function isResourceLink(string $rel): bool
    {
        foreach (preg_split('/\s+/', strtolower(trim($rel))) ?: [] as $token) {
            if (in_array($token, self::LINK_RELATIONS, true)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Write `src/Scan/CookieHeader.php`**

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;

/**
 * Cookie names from `Set-Cookie` response lines. Only the name and the Domain attribute are read; the value is
 * never looked at, kept or logged.
 */
final class CookieHeader
{
    /**
     * @param list<string> $setCookieLines
     *
     * @return list<Finding>
     */
    public static function findings(array $setCookieLines, string $siteHost, string $pageUrl): array
    {
        $found = [];

        foreach ($setCookieLines as $line) {
            $parts = explode(';', (string) $line);
            $pair = explode('=', (string) array_shift($parts), 2);

            if (count($pair) !== 2) {
                continue;
            }

            $name = Clean::name($pair[0]);

            if ($name === null) {
                continue;
            }

            $host = self::host($parts, $siteHost);
            $key = $name . "\0" . $host;

            if (!isset($found[$key])) {
                $found[$key] = new Finding(ScanFinding::COOKIE, $name, $host, $pageUrl);
            }
        }

        return array_values($found);
    }

    /**
     * @param list<string> $attributes
     */
    private static function host(array $attributes, string $siteHost): string
    {
        foreach ($attributes as $attribute) {
            $pair = explode('=', trim($attribute), 2);

            if (count($pair) === 2 && strtolower(trim($pair[0])) === 'domain') {
                $domain = Host::normalise(ltrim(trim($pair[1]), '.'));

                // A browser refuses a Domain that is not the host or a parent of it, so anything else is not real.
                if ($domain !== null && ($domain === $siteHost || str_ends_with($siteHost, '.' . $domain))) {
                    return $domain;
                }
            }
        }

        return $siteHost;
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `composer test -- --filter 'PageParserTest|CookieHeaderTest'`
Expected: PASS. If `testHostileAttributeValuesNeverBecomeHosts` fails because the parser decodes `&lt;script&gt;` into a host that passes validation, check `Host::normalise` rejects `<`/`>`: it does (not in the label charset). If `LIBXML_NOERROR` is rejected by `createFromString`, call it with no options.

- [ ] **Step 7: Commit**

```bash
composer format:php && composer typecheck
git add src/Scan tests/Scan
git commit -m "feat: parse third-party hosts and cookie names from pages and headers

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: URL guard and fetcher

**Files:**
- Create: `src/Scan/UnsafeUrl.php`, `src/Scan/FetchFailed.php`, `src/Scan/UrlGuard.php`, `src/Scan/Fetcher.php`, `src/Scan/FetchResult.php`, `src/Scan/GuzzleFetcher.php`
- Test: `tests/Scan/UrlGuardTest.php`, `tests/Scan/GuzzleFetcherTest.php`

**Interfaces:**
- Consumes: `Host::normalise`.
- Produces:
  - `UnsafeUrl extends \RuntimeException`, `FetchFailed extends \RuntimeException`
  - `UrlGuard::forSite(string $baseUrl, ?callable $resolver = null): UrlGuard` (resolves the host once; throws `UnsafeUrl`), `check(string $url): void` (throws `UnsafeUrl`), `curlResolve(): string` (`host:port:ip`), `host(): string`
  - `Fetcher::fetch(string $url): FetchResult`
  - `FetchResult(public int $status, public array $headers, public string $body)` with `headers` as `array<string, list<string>>` keyed by lower-case name; `header(string $name): ?string` (first value); `cookies(): list<string>`; `isHtml(): bool`
  - `GuzzleFetcher::__construct(ClientInterface $client, UrlGuard $guard, int $maxBytes = 2000000, int $timeout = 8)`

- [ ] **Step 1: Write the failing tests**

`tests/Scan/UrlGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\UnsafeUrl;
use CookieManagerKit\Scan\UrlGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class UrlGuardTest extends TestCase
{
    private static function guard(string $base = 'https://example.com', string $ip = '203.0.113.7'): UrlGuard
    {
        return UrlGuard::forSite($base, static fn(string $host): string => $ip);
    }

    public function testResolvesTheSiteHostExactlyOnce(): void
    {
        $calls = 0;
        $guard = UrlGuard::forSite('https://example.com', static function () use (&$calls): string {
            $calls++;

            return '203.0.113.7';
        });

        $guard->check('https://example.com/a');
        $guard->check('https://example.com/b');

        $this->assertSame(1, $calls);
        $this->assertSame('example.com:443:203.0.113.7', $guard->curlResolve());
    }

    public function testDefaultPortsFollowTheScheme(): void
    {
        $this->assertSame('example.com:80:203.0.113.7', self::guard('http://example.com')->curlResolve());
        $this->assertSame('example.com:8080:203.0.113.7', self::guard('http://example.com:8080')->curlResolve());
    }

    public function testASitePageOnTheSameHostPasses(): void
    {
        self::guard()->check('https://example.com/about?x=1');
        self::guard()->check('https://EXAMPLE.com/');

        $this->addToAssertionCount(2);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'another host' => ['https://evil.example.net/x'],
            'a subdomain' => ['https://cdn.example.com/x'],
            'the www twin' => ['https://www.example.com/x'],
            'wrong scheme' => ['http://example.com/x'],
            'wrong port' => ['https://example.com:8443/x'],
            'userinfo' => ['https://user:pass@example.com/x'],
            'userinfo trick' => ['https://example.com@evil.example.net/x'],
            'internal ip' => ['https://169.254.169.254/latest/meta-data'],
            'loopback' => ['https://127.0.0.1/'],
            'ftp' => ['ftp://example.com/x'],
            'file' => ['file:///etc/passwd'],
            'relative' => ['/x'],
            'garbage' => ['https://'],
        ];
    }

    #[DataProvider('refused')]
    public function testRefusesAnythingButTheSitesOwnPages(string $url): void
    {
        $this->expectException(UnsafeUrl::class);

        self::guard()->check($url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badAddresses(): array
    {
        return [
            'link local' => ['169.254.169.254'],
            'this network' => ['0.0.0.0'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
        ];
    }

    #[DataProvider('badAddresses')]
    public function testRefusesSiteHostsThatResolveToNeverLegitimateAddresses(string $ip): void
    {
        $this->expectException(UnsafeUrl::class);

        UrlGuard::forSite('https://example.com', static fn(string $host): string => $ip);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function developmentAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10' => ['10.1.2.3'],
            'private 172' => ['172.20.0.5'],
            'private 192' => ['192.168.1.20'],
        ];
    }

    #[DataProvider('developmentAddresses')]
    public function testAllowsALocalSiteBecauseItIsTheSitesOwnAddress(string $ip): void
    {
        $this->assertSame('example.com:443:' . $ip, self::guard('https://example.com', $ip)->curlResolve());
    }

    public function testASiteThatDoesNotResolveIsRefused(): void
    {
        $this->expectException(UnsafeUrl::class);

        UrlGuard::forSite('https://example.com', static fn(string $host): ?string => null);
    }

    public function testABaseUrlThatIsNotPlainHttpIsRefused(): void
    {
        foreach (['ftp://example.com', 'https://u:p@example.com', 'not a url', '', 'https://[::1]/'] as $base) {
            try {
                UrlGuard::forSite($base, static fn(string $host): string => '203.0.113.7');
                $this->fail('accepted ' . $base);
            } catch (UnsafeUrl) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
```

`tests/Scan/GuzzleFetcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Handler\MockHandler;
use CookieManagerKit\Scan\FetchFailed;
use CookieManagerKit\Scan\GuzzleFetcher;
use CookieManagerKit\Scan\UnsafeUrl;
use CookieManagerKit\Scan\UrlGuard;

final class GuzzleFetcherTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function fetcher(array $queue, int $maxBytes = 2000000): GuzzleFetcher
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $guard = UrlGuard::forSite('https://example.com', static fn(string $host): string => '203.0.113.7');

        return new GuzzleFetcher(new Client(['handler' => $stack]), $guard, $maxBytes);
    }

    public function testReturnsStatusLowerCasedHeadersAndBody(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Set-Cookie' => ['a=1', 'b=2']], '<p>hi</p>')]);

        $result = $fetcher->fetch('https://example.com/');

        $this->assertSame(200, $result->status);
        $this->assertSame('<p>hi</p>', $result->body);
        $this->assertTrue($result->isHtml());
        $this->assertSame(['a=1', 'b=2'], $result->cookies());
        $this->assertSame('text/html; charset=utf-8', $result->header('Content-Type'));
    }

    public function testNeverFollowsRedirectsAndPinsTheAddress(): void
    {
        $fetcher = $this->fetcher([new Response(302, ['Location' => 'https://evil.example.net/'])]);

        $result = $fetcher->fetch('https://example.com/');

        $this->assertSame(302, $result->status);
        $this->assertCount(1, $this->history);
        $options = $this->history[0]['options'];
        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['http_errors']);

        if (defined('CURLOPT_RESOLVE')) {
            $this->assertSame(['example.com:443:203.0.113.7'], $options['curl'][CURLOPT_RESOLVE]);
        }
    }

    public function testRefusesAnOffHostUrlWithoutSendingARequest(): void
    {
        $fetcher = $this->fetcher([new Response(200, [], 'x')]);

        try {
            $fetcher->fetch('https://evil.example.net/');
            $this->fail('fetched an off-host URL');
        } catch (UnsafeUrl) {
            $this->assertCount(0, $this->history);
        }
    }

    public function testABodyOverTheCapIsRefused(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html'], str_repeat('a', 101))], 100);

        $this->expectException(FetchFailed::class);

        $fetcher->fetch('https://example.com/');
    }

    public function testADeclaredLengthOverTheCapIsRefusedBeforeReadingTheBody(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html', 'Content-Length' => '5000'], 'short')], 100);

        $this->expectException(FetchFailed::class);

        $fetcher->fetch('https://example.com/');
    }

    public function testTransportErrorsBecomeFetchFailed(): void
    {
        $fetcher = $this->fetcher([new \GuzzleHttp\Exception\ConnectException('timed out', new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/'))]);

        $this->expectException(FetchFailed::class);

        $fetcher->fetch('https://example.com/');
    }

    public function testANonHtmlResponseIsNotHtml(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'application/pdf'], '%PDF')]);

        $this->assertFalse($fetcher->fetch('https://example.com/a.pdf')->isHtml());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'UrlGuardTest|GuzzleFetcherTest'`
Expected: FAIL with "Class ... UrlGuard not found".

- [ ] **Step 3: Write the exceptions and value objects**

`src/Scan/UnsafeUrl.php`:

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * A URL the crawler must not request.
 */
class UnsafeUrl extends \RuntimeException {}
```

`src/Scan/FetchFailed.php`:

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * A request that did not produce a usable response (transport error, over the size cap).
 */
class FetchFailed extends \RuntimeException {}
```

`src/Scan/Fetcher.php`:

```php
<?php

namespace CookieManagerKit\Scan;

interface Fetcher
{
    /**
     * @throws UnsafeUrl    when the URL is not one the crawler may request
     * @throws FetchFailed  when the request fails or the response is too large
     */
    public function fetch(string $url): FetchResult;
}
```

`src/Scan/FetchResult.php`:

```php
<?php

namespace CookieManagerKit\Scan;

final readonly class FetchResult
{
    /**
     * @param array<string, list<string>> $headers keyed by lower-case header name
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function cookies(): array
    {
        return array_values(array_map('strval', $this->headers['set-cookie'] ?? []));
    }

    public function isHtml(): bool
    {
        return preg_match('#^(?:text/html|application/xhtml\+xml)\b#i', trim((string) $this->header('content-type'))) === 1;
    }
}
```

- [ ] **Step 4: Write `src/Scan/UrlGuard.php`**

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * Decides which URLs the crawler may request: only the site's own canonical host, same scheme and port, no
 * credentials. The host is resolved once, when the guard is made, and every request is pinned to that address, so a
 * DNS answer that changes mid-run cannot redirect the crawler. A development site on a private or loopback address
 * is allowed because that address is the site's own; addresses that are never a real web server (link-local,
 * "this network", multicast, reserved, broadcast) are always refused.
 */
final class UrlGuard
{
    private const REFUSED = [
        '0.0.0.0/8',
        '169.254.0.0/16',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $scheme,
        private readonly string $ip,
    ) {}

    /**
     * @param (callable(string): ?string)|null $resolver returns an IPv4 address, or null when the host does not resolve
     *
     * @throws UnsafeUrl
     */
    public static function forSite(string $baseUrl, ?callable $resolver = null): self
    {
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? Host::normalise($parts['host'] ?? null) : null;

        if ($host === null || !in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('The site URL is not a plain http(s) address.');
        }

        $resolver ??= static function (string $name): ?string {
            if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $name;
            }

            $ip = gethostbyname($name);

            return $ip === $name ? null : $ip;
        };

        $ip = $resolver($host);

        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new UnsafeUrl('The site host does not resolve to an IPv4 address.');
        }

        if (self::isRefused($ip)) {
            throw new UnsafeUrl('The site host resolves to an address that is never a web server.');
        }

        return new self($host, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), $scheme, $ip);
    }

    private static function isRefused(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return true;
        }

        foreach (self::REFUSED as $range) {
            [$base, $bits] = explode('/', $range);
            $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

            if (($long & $mask) === (ip2long($base) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws UnsafeUrl
     */
    public function check(string $url): void
    {
        $parts = parse_url($url);

        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('The URL is not a plain address.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if ($scheme !== $this->scheme || Host::normalise($parts['host'] ?? null) !== $this->host || $port !== $this->port) {
            throw new UnsafeUrl('The URL is not on the site being scanned.');
        }
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * The value for CURLOPT_RESOLVE: connect to the address resolved when the run began.
     */
    public function curlResolve(): string
    {
        return $this->host . ':' . $this->port . ':' . $this->ip;
    }
}
```

- [ ] **Step 5: Write `src/Scan/GuzzleFetcher.php`**

```php
<?php

namespace CookieManagerKit\Scan;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches one page for the crawler. The guard runs before every request; redirects are never followed (a redirect
 * is reported as the 3xx it is); the connection is pinned to the address the guard resolved; and the body is
 * capped by declared length, by download progress and by a final length check.
 *
 * `stream` is deliberately not used: with Guzzle's default handler chain it selects the stream handler, which
 * ignores the pinned address.
 */
final class GuzzleFetcher implements Fetcher
{
    public const MAX_BYTES = 2000000;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly UrlGuard $guard,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $timeout = 8,
    ) {}

    public function fetch(string $url): FetchResult
    {
        $this->guard->check($url);

        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
            'headers' => ['User-Agent' => 'CookieManagerKit-Scanner/1.0', 'Accept' => 'text/html,application/xhtml+xml'],
            'on_headers' => function (ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > $this->maxBytes) {
                    throw new FetchFailed('The response is larger than the scan limit.');
                }
            },
            'progress' => function ($downloadTotal, $downloaded): void {
                if ($downloaded > $this->maxBytes) {
                    throw new FetchFailed('The response is larger than the scan limit.');
                }
            },
        ];

        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [CURLOPT_RESOLVE => [$this->guard->curlResolve()]];
        }

        try {
            $response = $this->client->request('GET', $url, $options);
        } catch (FetchFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new FetchFailed('The request failed (' . $e::class . ').', 0, $e);
        }

        $body = (string) $response->getBody();

        if (strlen($body) > $this->maxBytes) {
            throw new FetchFailed('The response is larger than the scan limit.');
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = array_values(array_map('strval', $values));
        }

        return new FetchResult($response->getStatusCode(), $headers, $body);
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `composer test -- --filter 'UrlGuardTest|GuzzleFetcherTest'`
Expected: PASS. Notes for the implementer: the `on_headers` and `progress` callbacks run inside the Guzzle `MockHandler` too, so the declared-length test exercises the real path. If the over-cap test fails because `MockHandler` does not call `progress`, the final `strlen($body)` check still throws, and the test passes through that route.

- [ ] **Step 7: Commit**

```bash
composer format:php && composer typecheck
git add src/Scan tests/Scan
git commit -m "feat: add the crawler's URL guard and size-capped fetcher

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: URL selection and the crawl scanner

**Files:**
- Create: `src/Scan/UrlSelector.php`, `src/Scan/Scanner.php`, `src/Scan/ScanContext.php`, `src/Scan/ScanResult.php`, `src/Scan/CrawlScanner.php`
- Create (test helper): `tests/Scan/FakeFetcher.php`
- Test: `tests/Scan/UrlSelectorTest.php`, `tests/Scan/CrawlScannerTest.php`

**Interfaces:**
- Consumes: `Fetcher`, `FetchResult`, `UnsafeUrl`, `FetchFailed`, `PageParser::findings`, `CookieHeader::findings`, `Finding`.
- Produces:
  - `UrlSelector::select(list<string> $urls, list<string> $excludes, int $cap): array{urls: list<string>, truncated: bool, excluded: int}`
  - `Scanner::scan(ScanContext $context): ScanResult`
  - `ScanContext::__construct(public string $siteHost, public array $urls = [])` (Task 11 adds `report`)
  - `ScanResult::__construct(public array $findings, public int $pagesScanned, public int $pagesFailed)`; `isComplete(): bool` (true when `pagesFailed === 0`)
  - `CrawlScanner::__construct(Fetcher $fetcher, ?\Closure $clock = null, int $budgetSeconds = 240)`

- [ ] **Step 1: Write the test helper and the failing tests**

`tests/Scan/FakeFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\Fetcher;
use CookieManagerKit\Scan\FetchResult;

/**
 * Answers from a fixed map of URL to FetchResult (or a Throwable to throw), and remembers what was asked.
 */
final class FakeFetcher implements Fetcher
{
    /** @var list<string> */
    public array $requested = [];

    /**
     * @param array<string, FetchResult|\Throwable> $responses
     */
    public function __construct(private array $responses) {}

    public function fetch(string $url): FetchResult
    {
        $this->requested[] = $url;
        $response = $this->responses[$url] ?? new \CookieManagerKit\Scan\FetchFailed('no such page');

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public static function page(string $html, array $headers = [], int $status = 200): FetchResult
    {
        return new FetchResult($status, ['content-type' => ['text/html; charset=utf-8']] + $headers, $html);
    }
}
```

`tests/Scan/UrlSelectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\UrlSelector;

final class UrlSelectorTest extends TestCase
{
    public function testKeepsOrderAndDropsDuplicates(): void
    {
        $picked = UrlSelector::select(['https://e.com/a', 'https://e.com/b', 'https://e.com/a'], [], 10);

        $this->assertSame(['https://e.com/a', 'https://e.com/b'], $picked['urls']);
        $this->assertFalse($picked['truncated']);
        $this->assertSame(0, $picked['excluded']);
    }

    public function testCapsAndReportsTruncation(): void
    {
        $urls = array_map(static fn(int $i): string => 'https://e.com/p' . $i, range(1, 10));
        $picked = UrlSelector::select($urls, [], 4);

        $this->assertCount(4, $picked['urls']);
        $this->assertTrue($picked['truncated']);
    }

    public function testExactlyTheCapIsNotTruncated(): void
    {
        $urls = ['https://e.com/a', 'https://e.com/b'];

        $this->assertFalse(UrlSelector::select($urls, [], 2)['truncated']);
    }

    public function testExcludesByExactPathAndItsChildren(): void
    {
        $urls = ['https://e.com/', 'https://e.com/members', 'https://e.com/members/profile', 'https://e.com/membership', 'https://e.com/about'];
        $picked = UrlSelector::select($urls, ['/members'], 10);

        $this->assertSame(['https://e.com/', 'https://e.com/membership', 'https://e.com/about'], $picked['urls']);
        $this->assertSame(2, $picked['excluded']);
    }

    public function testExcludesByWildcardPrefix(): void
    {
        $urls = ['https://e.com/shop', 'https://e.com/shop/a', 'https://e.com/shopping', 'https://e.com/blog'];

        $this->assertSame(['https://e.com/shop', 'https://e.com/blog'], UrlSelector::select($urls, ['/shop/*', '/shopping*'], 10)['urls']);
    }

    public function testQueryStringsDoNotAffectMatching(): void
    {
        $this->assertSame([], UrlSelector::select(['https://e.com/members?x=1'], ['/members'], 10)['urls']);
    }

    public function testExcludedPagesDoNotUseUpTheCap(): void
    {
        $urls = ['https://e.com/x/1', 'https://e.com/x/2', 'https://e.com/keep'];
        $picked = UrlSelector::select($urls, ['/x/*'], 1);

        $this->assertSame(['https://e.com/keep'], $picked['urls']);
        $this->assertFalse($picked['truncated']);
    }

    public function testUnparseableUrlsAreSkipped(): void
    {
        $this->assertSame(['https://e.com/ok'], UrlSelector::select(['https://', 'https://e.com/ok', ''], [], 10)['urls']);
    }
}
```

`tests/Scan/CrawlScannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\FetchFailed;
use CookieManagerKit\Scan\FetchResult;
use CookieManagerKit\Scan\CrawlScanner;
use CookieManagerKit\Scan\ScanContext;
use CookieManagerKit\Scan\UnsafeUrl;

final class CrawlScannerTest extends TestCase
{
    /**
     * @param list<Finding> $found
     *
     * @return list<string>
     */
    private static function summary(array $found): array
    {
        $rows = array_map(static fn(Finding $f): string => $f->kind . ' ' . $f->name . ' ' . $f->host, $found);
        sort($rows);

        return $rows;
    }

    public function testCombinesTagsAndCookiesFromEveryPage(): void
    {
        $fetcher = new FakeFetcher([
            'https://e.com/' => FakeFetcher::page('<script src="https://www.googletagmanager.com/gtm.js"></script>', ['set-cookie' => ['_ga=1; Domain=.e.com']]),
            'https://e.com/about' => FakeFetcher::page('<img src="https://pix.example.net/p.gif">', ['set-cookie' => ['sid=2']]),
        ]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/', 'https://e.com/about']));

        $this->assertSame([
            'cookie _ga e.com',
            'cookie sid e.com',
            'image pix.example.net pix.example.net',
            'script www.googletagmanager.com www.googletagmanager.com',
        ], self::summary($result->findings));
        $this->assertSame(2, $result->pagesScanned);
        $this->assertSame(0, $result->pagesFailed);
        $this->assertTrue($result->isComplete());
    }

    public function testTheSameFindingOnManyPagesIsReportedOnceWithTheFirstPage(): void
    {
        $tag = '<script src="https://cdn.example.net/a.js"></script>';
        $fetcher = new FakeFetcher(['https://e.com/a' => FakeFetcher::page($tag), 'https://e.com/b' => FakeFetcher::page($tag)]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/a', 'https://e.com/b']));

        $this->assertCount(1, $result->findings);
        $this->assertSame('https://e.com/a', $result->findings[0]->page);
    }

    public function testRedirectsErrorsAndTransportFailuresMakeTheCrawlIncomplete(): void
    {
        $fetcher = new FakeFetcher([
            'https://e.com/ok' => FakeFetcher::page('<p>fine</p>'),
            'https://e.com/moved' => new FetchResult(301, ['location' => ['https://e.com/new']], ''),
            'https://e.com/missing' => FakeFetcher::page('<p>404</p>', [], 404),
            'https://e.com/slow' => new FetchFailed('timed out'),
            'https://e.com/off' => new UnsafeUrl('off host'),
        ]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', [
            'https://e.com/ok',
            'https://e.com/moved',
            'https://e.com/missing',
            'https://e.com/slow',
            'https://e.com/off',
        ]));

        $this->assertSame(1, $result->pagesScanned);
        $this->assertSame(4, $result->pagesFailed);
        $this->assertFalse($result->isComplete());
    }

    public function testAnErrorPageStillContributesTheCookiesItSets(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/missing' => FakeFetcher::page('<p>404</p>', ['set-cookie' => ['err=1']], 404)]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/missing']));

        $this->assertSame(['cookie err e.com'], self::summary($result->findings));
    }

    public function testANonHtmlResponseIsCountedButNotParsed(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/a.pdf' => new FetchResult(200, ['content-type' => ['application/pdf']], '<script src="https://cdn.example.net/x.js"></script>')]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/a.pdf']));

        $this->assertSame([], $result->findings);
        $this->assertSame(1, $result->pagesScanned);
    }

    public function testAZeroTagPageAndAnEmptyBodyAreFineAndComplete(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/' => FakeFetcher::page('<p>hello</p>'), 'https://e.com/empty' => FakeFetcher::page('')]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/', 'https://e.com/empty']));

        $this->assertSame([], $result->findings);
        $this->assertTrue($result->isComplete());
    }

    public function testTheTimeBudgetStopsNewFetchesAndCountsTheRestAsFailed(): void
    {
        $fetcher = new FakeFetcher([
            'https://e.com/1' => FakeFetcher::page('<p>1</p>'),
            'https://e.com/2' => FakeFetcher::page('<p>2</p>'),
            'https://e.com/3' => FakeFetcher::page('<p>3</p>'),
        ]);

        // The clock moves on 100 s every time it is read, so a 150 s budget lets the first page through
        // (0 s elapsed at the first check, 100 s at the second) and stops before the third.
        $now = 1000;
        $scanner = new CrawlScanner($fetcher, function () use (&$now): int {
            $now += 100;

            return $now - 100;
        }, 150);

        $result = $scanner->scan(new ScanContext('e.com', ['https://e.com/1', 'https://e.com/2', 'https://e.com/3']));

        $this->assertLessThan(3, count($fetcher->requested));
        $this->assertSame(3 - $result->pagesScanned, $result->pagesFailed);
        $this->assertFalse($result->isComplete());
    }

    public function testHostileCookieNamesFlowThroughCappedAndVerbatim(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/' => FakeFetcher::page('<p>x</p>', ['set-cookie' => ['"><svg/onload/alert(1)>=1', str_repeat('n', 10000) . '=1']])]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/']));

        $this->assertSame('"><svg/onload/alert(1)>', $result->findings[0]->name);
        $this->assertSame(128, strlen($result->findings[1]->name));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'UrlSelectorTest|CrawlScannerTest'`
Expected: FAIL with "Class ... UrlSelector not found".

- [ ] **Step 3: Write `src/Scan/UrlSelector.php`**

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * Chooses which pages a crawl fetches: drops duplicates and unparseable URLs, applies the admin's exclude patterns
 * (a pattern is a path, matching itself and its children, or a path ending in * for a prefix), then caps the list.
 */
final class UrlSelector
{
    /**
     * @param list<string> $urls
     * @param list<string> $excludes
     *
     * @return array{urls: list<string>, truncated: bool, excluded: int}
     */
    public static function select(array $urls, array $excludes, int $cap): array
    {
        $kept = [];
        $excluded = 0;

        foreach ($urls as $url) {
            $path = self::path((string) $url);

            if ($path === null || isset($kept[$url])) {
                continue;
            }

            if (self::isExcluded($path, $excludes)) {
                $excluded++;

                continue;
            }

            $kept[$url] = true;
        }

        $all = array_keys($kept);

        return [
            'urls' => array_slice($all, 0, max(0, $cap)),
            'truncated' => count($all) > $cap,
            'excluded' => $excluded,
        ];
    }

    private static function path(string $url): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        return (string) ($parts['path'] ?? '/');
    }

    /**
     * @param list<string> $excludes
     */
    private static function isExcluded(string $path, array $excludes): bool
    {
        foreach ($excludes as $pattern) {
            if (str_contains($pattern, '*')) {
                if (fnmatch($pattern, $path)) {
                    return true;
                }
            } elseif ($path === $pattern || str_starts_with($path, rtrim($pattern, '/') . '/')) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Write the scanner interface and value objects**

`src/Scan/Scanner.php`:

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * Something that can look at the site and report findings. Phase 2 has a server-side crawl and the admin's
 * scan-mode beacon; a headless-browser scanner can implement the same interface later.
 */
interface Scanner
{
    public function scan(ScanContext $context): ScanResult;
}
```

`src/Scan/ScanContext.php`:

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * What a scanner is told: the site's own host and, for a crawl, the pages to fetch.
 */
final readonly class ScanContext
{
    /**
     * @param list<string> $urls
     */
    public function __construct(
        public string $siteHost,
        public array $urls = [],
    ) {}
}
```

`src/Scan/ScanResult.php`:

```php
<?php

namespace CookieManagerKit\Scan;

final readonly class ScanResult
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public array $findings,
        public int $pagesScanned,
        public int $pagesFailed,
    ) {}

    /**
     * Every requested page was fetched and answered 2xx. Only a complete result may mark unseen findings as gone.
     */
    public function isComplete(): bool
    {
        return $this->pagesFailed === 0;
    }
}
```

- [ ] **Step 5: Write `src/Scan/CrawlScanner.php`**

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * Fetches each page as an anonymous visitor and reads it; it runs no JavaScript. A page that redirects, errors, times
 * out or is refused counts as failed, and so does every page left when the time budget runs out, because a crawl
 * that did not see everything must not conclude anything has disappeared.
 */
final class CrawlScanner implements Scanner
{
    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly ?\Closure $clock = null,
        private readonly int $budgetSeconds = 240,
    ) {}

    public function scan(ScanContext $context): ScanResult
    {
        $clock = $this->clock ?? static fn(): int => time();
        $started = $clock();
        $found = [];
        $scanned = 0;
        $failed = 0;
        $total = count($context->urls);

        foreach (array_values($context->urls) as $index => $url) {
            if ($clock() - $started >= $this->budgetSeconds) {
                $failed += $total - $index;

                break;
            }

            try {
                $response = $this->fetcher->fetch($url);
            } catch (UnsafeUrl | FetchFailed) {
                $failed++;

                continue;
            }

            if ($response->status >= 200 && $response->status < 300) {
                $scanned++;
            } else {
                $failed++;
            }

            $items = CookieHeader::findings($response->cookies(), $context->siteHost, $url);

            if ($response->status >= 200 && $response->status < 300 && $response->isHtml()) {
                $items = [...$items, ...PageParser::findings($response->body, $url, $context->siteHost)];
            }

            foreach ($items as $item) {
                $found[$item->kind . "\0" . $item->name . "\0" . $item->host] ??= $item;
            }
        }

        return new ScanResult(array_values($found), $scanned, $failed);
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `composer test -- --filter 'UrlSelectorTest|CrawlScannerTest'`
Expected: PASS. In `testTheTimeBudgetStops…` the clock advances 100 s per call, so with a 150 s budget at most one page is fetched.

- [ ] **Step 7: Commit**

```bash
composer format:php && composer typecheck
git add src/Scan tests/Scan
git commit -m "feat: add URL selection and the crawl scanner

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Pattern matching and the classifier

**Files:**
- Create: `src/Scan/Pattern.php`, `src/Scan/Suggestion.php`, `src/Scan/Classifier.php`
- Test: `tests/Scan/PatternTest.php`, `tests/Scan/ClassifierTest.php`

**Interfaces:**
- Consumes: `EssentialCookies::isEssential(string): bool`, `ScanFinding` kind constants, `ClassificationEntry` origin constants.
- Produces:
  - `Pattern::matches(string $pattern, string $subject, bool $caseInsensitive = false): bool`
  - `Pattern::matchesHost(string $pattern, string $host): bool` (`*.example.com` matches `example.com` and every subdomain)
  - `Suggestion(public string $category, public string $vendor)`
  - `Classifier::__construct(array $entries, ?callable $isEssential = null)` where each entry is `array{kind: string, pattern: string, category: string, vendor: string, origin: string}`; `classify(string $kind, string $name, string $host): ?Suggestion`

- [ ] **Step 1: Write the failing tests**

`tests/Scan/PatternTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\Pattern;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PatternTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function cases(): array
    {
        return [
            'exact' => ['_ga', '_ga', false, true],
            'exact is case sensitive' => ['_ga', '_GA', false, false],
            'exact insensitive' => ['_ga', '_GA', true, true],
            'prefix wildcard' => ['_ga_*', '_ga_ABC123', false, true],
            'prefix wildcard needs the prefix' => ['_ga_*', '_gb_ABC', false, false],
            'suffix wildcard' => ['*_session', 'x_session', false, true],
            'middle wildcard' => ['_pk_id.*.x', '_pk_id.1.x', false, true],
            'wildcard matches nothing' => ['_gat*', '_gat', false, true],
            'regex characters are literal' => ['a.b', 'axb', false, false],
            'regex characters in wildcards are literal' => ['a.*', 'a.b', false, true],
            'empty pattern never matches' => ['', '', false, false],
            'too many wildcards never match' => ['*a*b*c*d', 'xaxbxcxd', false, false],
            'overlong pattern never matches' => [str_repeat('a', 129), str_repeat('a', 129), false, false],
            'overlong subject never matches' => ['a*', 'a' . str_repeat('b', 300), false, false],
            'newline is not matched by the dot' => ['a*', "a\nb", false, true],
        ];
    }

    #[DataProvider('cases')]
    public function testMatches(string $pattern, string $subject, bool $insensitive, bool $expected): void
    {
        $this->assertSame($expected, Pattern::matches($pattern, $subject, $insensitive));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function hosts(): array
    {
        return [
            'exact' => ['www.google-analytics.com', 'www.google-analytics.com', true],
            'exact ignores case' => ['WWW.Google-Analytics.com', 'www.google-analytics.com', true],
            'wildcard matches a subdomain' => ['*.doubleclick.net', 'googleads.g.doubleclick.net', true],
            'wildcard matches the apex' => ['*.doubleclick.net', 'doubleclick.net', true],
            'wildcard does not match a lookalike' => ['*.doubleclick.net', 'baddoubleclick.net', false],
            'wildcard does not match a suffix trick' => ['*.doubleclick.net', 'doubleclick.net.evil.example', false],
            'inner wildcard' => ['ads*.example.com', 'ads2.example.com', true],
        ];
    }

    #[DataProvider('hosts')]
    public function testMatchesHost(string $pattern, string $host, bool $expected): void
    {
        $this->assertSame($expected, Pattern::matchesHost($pattern, $host));
    }
}
```

`tests/Scan/ClassifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Classifier;

final class ClassifierTest extends TestCase
{
    /**
     * @return array{kind: string, pattern: string, category: string, vendor: string, origin: string}
     */
    private static function entry(string $kind, string $pattern, string $category, string $origin, string $vendor = 'V'): array
    {
        return ['kind' => $kind, 'pattern' => $pattern, 'category' => $category, 'vendor' => $vendor, 'origin' => $origin];
    }

    private static function classifier(array $entries): Classifier
    {
        return new Classifier($entries, static fn(string $name): bool => $name === 'CONCRETE');
    }

    public function testOverrideBeatsRefreshedBeatsBundledEvenWhenTheOlderEntryIsMoreSpecific(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_ga', 'analytics', 'bundled', 'Bundled'),
            self::entry('cookie', '_g*', 'marketing', 'refreshed', 'Refreshed'),
            self::entry('cookie', '*', 'preferences', 'override', 'Override'),
        ]);

        $this->assertSame(['preferences', 'Override'], $this->pair($classifier->classify('cookie', '_ga', 'e.com')));
    }

    public function testRefreshedBeatsBundled(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_ga', 'analytics', 'bundled'),
            self::entry('cookie', '_ga', 'marketing', 'refreshed'),
        ]);

        $this->assertSame('marketing', $classifier->classify('cookie', '_ga', 'e.com')?->category);
    }

    public function testWithinAnOriginExactBeatsWildcardAndLongerBeatsShorter(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_g*', 'marketing', 'bundled'),
            self::entry('cookie', '_ga*', 'preferences', 'bundled'),
            self::entry('cookie', '_gat', 'analytics', 'bundled'),
        ]);

        $this->assertSame('analytics', $classifier->classify('cookie', '_gat', 'e.com')?->category);
        $this->assertSame('preferences', $classifier->classify('cookie', '_ga_X', 'e.com')?->category);
        $this->assertSame('marketing', $classifier->classify('cookie', '_gx', 'e.com')?->category);
    }

    public function testCookieNamesAreCaseSensitiveButHostsAreNot(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_ga', 'analytics', 'bundled'),
            self::entry('host', '*.doubleclick.net', 'marketing', 'bundled'),
        ]);

        $this->assertNull($classifier->classify('cookie', '_GA', 'e.com'));
        $this->assertSame('marketing', $classifier->classify('script', 'ads.DoubleClick.net', 'ads.DoubleClick.net')?->category);
    }

    public function testHostKindsMatchOnTheHostNotTheName(): void
    {
        $classifier = self::classifier([self::entry('host', 'cdn.example.net', 'marketing', 'bundled')]);

        foreach (['script', 'iframe', 'image', 'resource'] as $kind) {
            $this->assertSame('marketing', $classifier->classify($kind, 'ignored', 'cdn.example.net')?->category, $kind);
        }

        $this->assertNull($classifier->classify('cookie', 'cdn.example.net', 'e.com'));
    }

    public function testStorageKeysMatchStorageEntriesThenCookieEntries(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_ga', 'analytics', 'bundled'),
            self::entry('storage', 'cart', 'necessary', 'bundled'),
        ]);

        $this->assertSame('necessary', $classifier->classify('storage', 'cart', 'e.com')?->category);
        $this->assertSame('analytics', $classifier->classify('storage', '_ga', 'e.com')?->category);
        $this->assertNull($classifier->classify('cookie', 'cart', 'e.com'));
    }

    public function testEssentialConcreteCookiesAreAlwaysNecessaryWhateverAnOverrideSays(): void
    {
        $classifier = self::classifier([self::entry('cookie', 'CONCRETE', 'marketing', 'override')]);

        $this->assertSame(['necessary', 'ConcreteCMS'], $this->pair($classifier->classify('cookie', 'CONCRETE', 'e.com')));
    }

    public function testNoEntryMeansNoSuggestion(): void
    {
        $this->assertNull(self::classifier([])->classify('cookie', 'mystery', 'e.com'));
    }

    public function testHostileNamesAreJustStrings(): void
    {
        $classifier = self::classifier([self::entry('cookie', '_ga', 'analytics', 'bundled')]);

        $this->assertNull($classifier->classify('cookie', '"><img src=x onerror=alert(1)>', 'e.com'));
        $this->assertNull($classifier->classify('cookie', str_repeat('a', 10000), 'e.com'));
    }

    /**
     * @return array{string, string}|null
     */
    private function pair(?\CookieManagerKit\Scan\Suggestion $suggestion): ?array
    {
        return $suggestion === null ? null : [$suggestion->category, $suggestion->vendor];
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'PatternTest|ClassifierTest'`
Expected: FAIL with "Class ... Pattern not found".

- [ ] **Step 3: Write `src/Scan/Pattern.php`**

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * Matching for classification patterns. A pattern is an exact value, or a value with up to three `*` wildcards.
 * Everything except `*` is literal: patterns come from downloaded data and must never act as a regular expression.
 */
final class Pattern
{
    private const MAX_PATTERN = 128;
    private const MAX_SUBJECT = 255;
    private const MAX_WILDCARDS = 3;

    public static function matches(string $pattern, string $subject, bool $caseInsensitive = false): bool
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN || strlen($subject) > self::MAX_SUBJECT || substr_count($pattern, '*') > self::MAX_WILDCARDS) {
            return false;
        }

        if (!str_contains($pattern, '*')) {
            return $caseInsensitive ? strcasecmp($pattern, $subject) === 0 : $pattern === $subject;
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '\z/s' . ($caseInsensitive ? 'i' : '');

        return preg_match($regex, $subject) === 1;
    }

    /**
     * `*.example.com` matches `example.com` itself and every subdomain of it, never a lookalike such as
     * `badexample.com`.
     */
    public static function matchesHost(string $pattern, string $host): bool
    {
        $pattern = strtolower($pattern);
        $host = strtolower($host);

        if (str_starts_with($pattern, '*.') && !str_contains(substr($pattern, 2), '*')) {
            $apex = substr($pattern, 2);

            return $apex !== '' && ($host === $apex || str_ends_with($host, '.' . $apex));
        }

        return self::matches($pattern, $host, true);
    }
}
```

- [ ] **Step 4: Write `src/Scan/Suggestion.php` and `src/Scan/Classifier.php`**

`src/Scan/Suggestion.php`:

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * A suggested category and vendor. Only ever a suggestion: an admin approves it before it reaches visitors.
 */
final readonly class Suggestion
{
    public function __construct(
        public string $category,
        public string $vendor,
    ) {}
}
```

`src/Scan/Classifier.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;
use CookieManagerKit\Support\EssentialCookies;

/**
 * Suggests a category for a finding. Precedence: ConcreteCMS's own essential cookies first (they are always
 * necessary, so no data and no override can reclassify them), then override, refreshed and bundled entries in that
 * order; inside an origin an exact pattern beats a wildcard and a longer pattern beats a shorter one.
 */
final class Classifier
{
    private const RANK = ['override' => 0, 'refreshed' => 1, 'bundled' => 2];

    /** @var list<array{kind: string, pattern: string, category: string, vendor: string, origin: string}> */
    private array $entries;

    private \Closure $isEssential;

    /**
     * @param list<array{kind: string, pattern: string, category: string, vendor: string, origin: string}> $entries
     * @param (callable(string): bool)|null                                                                $isEssential defaults to the live Concrete cookie list
     */
    public function __construct(array $entries, ?callable $isEssential = null)
    {
        $entries = array_values($entries);

        usort($entries, static function (array $a, array $b): int {
            return [self::RANK[$a['origin']] ?? 9, str_contains($a['pattern'], '*') ? 1 : 0, -strlen($a['pattern'])]
                <=> [self::RANK[$b['origin']] ?? 9, str_contains($b['pattern'], '*') ? 1 : 0, -strlen($b['pattern'])];
        });

        $this->entries = $entries;
        $this->isEssential = $isEssential !== null
            ? \Closure::fromCallable($isEssential)
            : static fn(string $name): bool => EssentialCookies::isEssential($name);
    }

    public function classify(string $kind, string $name, string $host): ?Suggestion
    {
        $byHost = !in_array($kind, [ScanFinding::COOKIE, ScanFinding::STORAGE], true);

        if (!$byHost && ($this->isEssential)($name)) {
            return new Suggestion('necessary', 'ConcreteCMS');
        }

        $kinds = match ($kind) {
            ScanFinding::COOKIE => ['cookie'],
            ScanFinding::STORAGE => ['storage', 'cookie'],
            default => ['host'],
        };

        foreach ($this->entries as $entry) {
            if (!in_array($entry['kind'], $kinds, true)) {
                continue;
            }

            $matches = $byHost ? Pattern::matchesHost($entry['pattern'], $host) : Pattern::matches($entry['pattern'], $name);

            if ($matches) {
                return new Suggestion($entry['category'], $entry['vendor']);
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `composer test -- --filter 'PatternTest|ClassifierTest'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
composer format:php && composer typecheck
git add src/Scan tests/Scan
git commit -m "feat: add pattern matching and the classifier

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Classification files and the bundled baseline

**Files:**
- Create: `src/Scan/ClassificationFile.php`, `data/classification-baseline.csv`
- Test: `tests/Scan/ClassificationFileTest.php`

**Interfaces:**
- Consumes: `Text::isHandle`, `Text::plain`.
- Produces:
  - `ClassificationFile::MAX_BYTES` (5000000), `MAX_ROWS` (20000)
  - `ClassificationFile::parseBundled(string $csv): ?list<array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}>` (`null` when anything is wrong; a header-only file gives `[]`)
  - `ClassificationFile::parseOpenCookieDatabase(string $csv): ?list<…same row shape…>` (maps the Open Cookie Database columns; `null` for a wrong header, an over-size file, fewer than 50 rows, or more than 25% of rows unusable)
  - `ClassificationFile::baselinePath(): string`

- [ ] **Step 1: Write the failing tests**

`tests/Scan/ClassificationFileTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\ClassificationFile;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClassificationFileTest extends TestCase
{
    private const HEADER = "kind,pattern,category,vendor,description,duration\n";

    public function testParsesAValidBundledFile(): void
    {
        $rows = ClassificationFile::parseBundled(self::HEADER . "cookie,_ga,analytics,Google,\"Tells visitors apart, per browser\",2 years\nhost,*.doubleclick.net,marketing,Google,Ad serving,\n");

        $this->assertSame([
            ['kind' => 'cookie', 'pattern' => '_ga', 'category' => 'analytics', 'vendor' => 'Google', 'description' => 'Tells visitors apart, per browser', 'duration' => '2 years'],
            ['kind' => 'host', 'pattern' => '*.doubleclick.net', 'category' => 'marketing', 'vendor' => 'Google', 'description' => 'Ad serving', 'duration' => ''],
        ], $rows);
    }

    public function testAHeaderOnlyFileIsValidAndEmpty(): void
    {
        $this->assertSame([], ClassificationFile::parseBundled(self::HEADER));
    }

    public function testAByteOrderMarkIsTolerated(): void
    {
        $this->assertCount(1, (array) ClassificationFile::parseBundled("\xEF\xBB\xBF" . self::HEADER . "cookie,_ga,analytics,G,d,1 day\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        $h = "kind,pattern,category,vendor,description,duration\n";

        return [
            'empty' => [''],
            'wrong header' => ["kind,pattern,category\ncookie,_ga,analytics\n"],
            'extra column' => [$h . "cookie,_ga,analytics,G,d,1 day,extra\n"],
            'short row' => [$h . "cookie,_ga,analytics\n"],
            'unknown kind' => [$h . "script,_ga,analytics,G,d,1\n"],
            'pattern with a space' => [$h . "cookie,_g a,analytics,G,d,1\n"],
            'pattern with markup' => [$h . "cookie,<b>,analytics,G,d,1\n"],
            'empty pattern' => [$h . "cookie,,analytics,G,d,1\n"],
            'overlong pattern' => [$h . 'cookie,' . str_repeat('a', 129) . ",analytics,G,d,1\n"],
            'too many wildcards' => [$h . "cookie,*a*b*c*d,analytics,G,d,1\n"],
            'category not a handle' => [$h . "cookie,_ga,Analytics!,G,d,1\n"],
            'one bad row spoils the file' => [$h . "cookie,_ga,analytics,G,d,1\ncookie,bad row,analytics,G,d,1\n"],
        ];
    }

    #[DataProvider('invalid')]
    public function testAnythingWrongRejectsTheWholeBundledFile(string $csv): void
    {
        $this->assertNull(ClassificationFile::parseBundled($csv));
    }

    public function testAnOversizeFileIsRejected(): void
    {
        $this->assertNull(ClassificationFile::parseBundled(self::HEADER . str_repeat('x', ClassificationFile::MAX_BYTES)));
    }

    public function testTooManyRowsAreRejected(): void
    {
        $rows = str_repeat("cookie,_ga,analytics,G,d,1\n", ClassificationFile::MAX_ROWS + 1);

        $this->assertNull(ClassificationFile::parseBundled(self::HEADER . $rows));
    }

    public function testVendorAndDescriptionAreReducedToPlainText(): void
    {
        $rows = (array) ClassificationFile::parseBundled(self::HEADER . "cookie,_ga,analytics,<b>Google</b>,\"<script>x</script>Visitors\",1 day\n");

        $this->assertSame('Google', $rows[0]['vendor']);
        $this->assertSame('xVisitors', $rows[0]['description']);
    }

    public function testHostPatternsAreLowerCased(): void
    {
        $rows = (array) ClassificationFile::parseBundled(self::HEADER . "host,WWW.Example.COM,marketing,V,d,\n");

        $this->assertSame('www.example.com', $rows[0]['pattern']);
    }

    public function testTheShippedBaselineIsValidAndUseful(): void
    {
        $rows = ClassificationFile::parseBundled((string) file_get_contents(ClassificationFile::baselinePath()));

        $this->assertNotNull($rows);
        $this->assertGreaterThan(60, count($rows));

        $patterns = array_map(static fn(array $row): string => $row['kind'] . ':' . $row['pattern'], $rows);

        foreach (['cookie:_ga', 'cookie:_fbp', 'host:www.googletagmanager.com', 'host:connect.facebook.net', 'host:js.stripe.com'] as $expected) {
            $this->assertContains($expected, $patterns);
        }

        $this->assertSame(count($patterns), count(array_unique($patterns)), 'no duplicate patterns');

        foreach ($rows as $row) {
            $this->assertContains($row['category'], ['necessary', 'preferences', 'analytics', 'marketing']);
        }
    }

    /**
     * @param list<array<int, string>> $extra
     */
    private static function ocd(array $extra = [], int $count = 60): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['ID', 'Platform', 'Category', 'Cookie / Data Key name', 'Domain', 'Description', 'Retention period', 'Data Controller', 'User Privacy & GDPR Rights Portals', 'Wildcard match'], ',', '"', '');

        for ($i = 0; $i < $count; $i++) {
            fputcsv($out, ['id' . $i, 'Platform', 'Analytics', 'cookie_' . $i, 'example.com', 'Does a thing', '1 year', 'Example Inc', 'https://example.com/privacy', '0'], ',', '"', '');
        }

        foreach ($extra as $row) {
            fputcsv($out, $row, ',', '"', '');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function testParsesTheOpenCookieDatabaseColumns(): void
    {
        $rows = (array) ClassificationFile::parseOpenCookieDatabase(self::ocd([
            ['x1', 'Matomo', 'Analytics', '_pk_id.', 'example.com', 'Visitor id', '13 months', 'Matomo', '', '1'],
            ['x2', 'Cloudflare', 'Security', '__cf_bm', 'example.com', 'Bot management', '30 minutes', 'Cloudflare', '', '0'],
            ['x3', 'Site', 'Functional', 'lang', 'example.com', 'Language', 'Session', 'Site', '', '0'],
            ['x4', 'Ads', 'Marketing', 'ad_id', 'example.com', 'Ads', '1 year', 'Ad Co', '', '0'],
            ['x5', 'Site', 'Personalization', 'theme', 'example.com', 'Theme', '1 year', 'Site', '', '0'],
        ]));

        $by = [];

        foreach ($rows as $row) {
            $by[$row['pattern']] = $row;
        }

        $this->assertSame(['cookie', 'analytics', 'Matomo', '13 months'], [$by['_pk_id.*']['kind'], $by['_pk_id.*']['category'], $by['_pk_id.*']['vendor'], $by['_pk_id.*']['duration']]);
        $this->assertSame('necessary', $by['__cf_bm']['category']);
        $this->assertSame('preferences', $by['lang']['category']);
        $this->assertSame('marketing', $by['ad_id']['category']);
        $this->assertSame('preferences', $by['theme']['category']);
    }

    public function testTheOpenCookieDatabaseNeedsItsHeaderColumns(): void
    {
        $this->assertNull(ClassificationFile::parseOpenCookieDatabase("a,b,c\n1,2,3\n"));
        $this->assertNull(ClassificationFile::parseOpenCookieDatabase(''));
    }

    public function testTooSmallAnOpenCookieDatabaseFileIsRejectedAsSuspicious(): void
    {
        $this->assertNull(ClassificationFile::parseOpenCookieDatabase(self::ocd([], 10)));
    }

    public function testAFileWhoseRowsMostlyFailIsRejected(): void
    {
        $bad = [];

        for ($i = 0; $i < 40; $i++) {
            $bad[] = ['b' . $i, 'P', 'Alien', 'name' . $i, 'x.com', 'd', '1y', 'V', '', '0'];
        }

        $this->assertNull(ClassificationFile::parseOpenCookieDatabase(self::ocd($bad, 60)));
    }

    public function testASmallShareOfUnusableRowsIsDropped(): void
    {
        $rows = (array) ClassificationFile::parseOpenCookieDatabase(self::ocd([
            ['b1', 'P', 'Alien', 'dropped_one', 'x.com', 'd', '1y', 'V', '', '0'],
            ['b2', 'P', 'Analytics', 'has a space', 'x.com', 'd', '1y', 'V', '', '0'],
        ]));

        $patterns = array_column($rows, 'pattern');

        $this->assertNotContains('dropped_one', $patterns);
        $this->assertNotContains('has a space', $patterns);
        $this->assertCount(60, $rows);
    }

    public function testDuplicateOpenCookieDatabaseNamesKeepTheFirst(): void
    {
        $rows = (array) ClassificationFile::parseOpenCookieDatabase(self::ocd([
            ['d1', 'P', 'Analytics', 'dup', 'x.com', 'first', '1y', 'First', '', '0'],
            ['d2', 'P', 'Marketing', 'dup', 'x.com', 'second', '1y', 'Second', '', '0'],
        ]));

        $dups = array_values(array_filter($rows, static fn(array $row): bool => $row['pattern'] === 'dup'));

        $this->assertCount(1, $dups);
        $this->assertSame('First', $dups[0]['vendor']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter ClassificationFileTest`
Expected: FAIL with "Class ... ClassificationFile not found".

- [ ] **Step 3: Write `src/Scan/ClassificationFile.php`**

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Support\Text;

/**
 * Reads classification data. Two formats: the package's own CSV (the bundled baseline), and the Open Cookie
 * Database CSV that the optional refresh downloads. Both are validated row by row. The bundled format is strict (one
 * bad row rejects the file); the downloaded format tolerates a small share of unusable rows but rejects a file that
 * is too small, too large or mostly unusable, because a truncated or hostile download must never replace good data.
 */
final class ClassificationFile
{
    public const MAX_BYTES = 5000000;
    public const MAX_ROWS = 20000;

    private const HEADER = ['kind', 'pattern', 'category', 'vendor', 'description', 'duration'];
    private const KINDS = ['cookie', 'storage', 'host'];
    private const OCD_CATEGORIES = [
        'functional' => 'preferences',
        'personalization' => 'preferences',
        'analytics' => 'analytics',
        'marketing' => 'marketing',
        'security' => 'necessary',
    ];
    private const OCD_MIN_ROWS = 50;
    private const OCD_MAX_DROPPED = 0.25;

    public static function baselinePath(): string
    {
        return dirname(__DIR__, 2) . '/data/classification-baseline.csv';
    }

    /**
     * @return list<array<int, string>>|null
     */
    private static function read(string $csv): ?array
    {
        if ($csv === '' || strlen($csv) > self::MAX_BYTES) {
            return null;
        }

        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $rows[] = array_map(static fn($cell): string => (string) $cell, $row);

            if (count($rows) > self::MAX_ROWS + 1) {
                fclose($stream);

                return null;
            }
        }

        fclose($stream);

        return $rows === [] ? null : $rows;
    }

    /**
     * @return array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}|null
     */
    private static function row(string $kind, string $pattern, string $category, string $vendor, string $description, string $duration): ?array
    {
        $pattern = trim($pattern);

        if (!in_array($kind, self::KINDS, true)
            || preg_match('/^[A-Za-z0-9_.*:~%+\-]{1,128}\z/', $pattern) !== 1
            || substr_count($pattern, '*') > 3
            || !Text::isHandle($category)) {
            return null;
        }

        return [
            'kind' => $kind,
            'pattern' => $kind === 'host' ? strtolower($pattern) : $pattern,
            'category' => $category,
            'vendor' => Text::plain($vendor),
            'description' => Text::plain($description, 500),
            'duration' => Text::plain($duration, 64),
        ];
    }

    /**
     * @return list<array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}>|null
     */
    public static function parseBundled(string $csv): ?array
    {
        $rows = self::read($csv);

        if ($rows === null || array_map('strtolower', array_map('trim', array_shift($rows))) !== self::HEADER) {
            return null;
        }

        $out = [];

        foreach ($rows as $row) {
            if (count($row) !== count(self::HEADER)) {
                return null;
            }

            $entry = self::row($row[0], $row[1], $row[2], $row[3], $row[4], $row[5]);

            if ($entry === null) {
                return null;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return list<array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}>|null
     */
    public static function parseOpenCookieDatabase(string $csv): ?array
    {
        $rows = self::read($csv);

        if ($rows === null) {
            return null;
        }

        $header = array_map(static fn(string $cell): string => strtolower(trim($cell)), array_shift($rows));
        $at = [];

        foreach (['category', 'cookie / data key name', 'description', 'retention period', 'data controller', 'wildcard match'] as $column) {
            $position = array_search($column, $header, true);

            if ($position === false) {
                return null;
            }

            $at[$column] = $position;
        }

        $total = count($rows);
        $out = [];
        $dropped = 0;

        foreach ($rows as $row) {
            $category = self::OCD_CATEGORIES[strtolower(trim($row[$at['category']] ?? ''))] ?? null;
            $name = trim($row[$at['cookie / data key name']] ?? '');

            if ($category !== null && $name !== '' && trim($row[$at['wildcard match']] ?? '') === '1' && !str_ends_with($name, '*')) {
                $name .= '*';
            }

            $entry = $category === null ? null : self::row(
                'cookie',
                $name,
                $category,
                $row[$at['data controller']] ?? '',
                $row[$at['description']] ?? '',
                $row[$at['retention period']] ?? '',
            );

            if ($entry === null) {
                $dropped++;

                continue;
            }

            $out[$entry['kind'] . "\0" . $entry['pattern']] ??= $entry;
        }

        if ($total < self::OCD_MIN_ROWS || $dropped > $total * self::OCD_MAX_DROPPED || $out === []) {
            return null;
        }

        return array_values($out);
    }
}
```

- [ ] **Step 4: Write `data/classification-baseline.csv`**

This is a hand-curated baseline in the package's own format (original wording, well-known cookies and hosts). The refresh task can later add the Open Cookie Database on top. Create the file with exactly this content:

```csv
kind,pattern,category,vendor,description,duration
cookie,_ga,analytics,Google,Tells visitors apart for Google Analytics,2 years
cookie,_ga_*,analytics,Google,Keeps session state for Google Analytics 4,2 years
cookie,_gid,analytics,Google,Tells visitors apart for Google Analytics,1 day
cookie,_gat*,analytics,Google,Limits how often Google Analytics is called,1 minute
cookie,_gcl_*,marketing,Google,Links ad clicks to conversions for Google Ads,3 months
cookie,IDE,marketing,Google,Used by Google ad services to personalise ads,13 months
cookie,test_cookie,marketing,Google,Checks whether the browser accepts cookies for Google ads,15 minutes
cookie,NID,marketing,Google,Remembers preferences and personalises Google ads,6 months
cookie,PREF,preferences,Google,Remembers player and display preferences,8 months
cookie,VISITOR_INFO1_LIVE,marketing,YouTube,Estimates bandwidth and personalises YouTube,6 months
cookie,YSC,marketing,YouTube,Identifies a viewing session on embedded YouTube videos,Session
cookie,_fbp,marketing,Meta,Identifies browsers for Meta advertising,3 months
cookie,_fbc,marketing,Meta,Stores the last ad click for Meta advertising,3 months
cookie,fr,marketing,Meta,Delivers and measures Meta advertising,3 months
cookie,_hjSessionUser_*,analytics,Hotjar,Identifies a returning visitor for Hotjar,1 year
cookie,_hjSession_*,analytics,Hotjar,Holds Hotjar session data,30 minutes
cookie,_hjid,analytics,Hotjar,Identifies a visitor for Hotjar,1 year
cookie,_clck,analytics,Microsoft Clarity,Identifies a visitor for Microsoft Clarity,1 year
cookie,_clsk,analytics,Microsoft Clarity,Joins page views into one Clarity session,1 day
cookie,MUID,marketing,Microsoft,Identifies browsers for Microsoft advertising,1 year
cookie,_uetsid,marketing,Microsoft Advertising,Identifies a session for Microsoft Advertising,1 day
cookie,_uetvid,marketing,Microsoft Advertising,Identifies a visitor for Microsoft Advertising,13 months
cookie,_pk_id.*,analytics,Matomo,Identifies a visitor for Matomo,13 months
cookie,_pk_ses.*,analytics,Matomo,Holds Matomo session data,30 minutes
cookie,_pk_ref.*,analytics,Matomo,Remembers where a visitor came from for Matomo,6 months
cookie,_ttp,marketing,TikTok,Measures and improves TikTok advertising,13 months
cookie,_tt_enable_cookie,marketing,TikTok,Tells the TikTok pixel cookies are allowed,13 months
cookie,li_gc,marketing,LinkedIn,Stores consent for LinkedIn advertising,6 months
cookie,lidc,marketing,LinkedIn,Routes requests for LinkedIn,1 day
cookie,bcookie,marketing,LinkedIn,Identifies browsers for LinkedIn,1 year
cookie,_pin_unauth,marketing,Pinterest,Groups visitors for Pinterest advertising,1 year
cookie,_rdt_uuid,marketing,Reddit,Identifies visitors for Reddit advertising,3 months
cookie,hubspotutk,marketing,HubSpot,Identifies a visitor for HubSpot,6 months
cookie,__hstc,marketing,HubSpot,Tracks visits for HubSpot,6 months
cookie,__hssc,marketing,HubSpot,Counts sessions for HubSpot,30 minutes
cookie,__hssrc,marketing,HubSpot,Detects a browser restart for HubSpot,Session
cookie,__stripe_mid,necessary,Stripe,Helps Stripe detect fraud,1 year
cookie,__stripe_sid,necessary,Stripe,Helps Stripe detect fraud during a session,30 minutes
cookie,__cf_bm,necessary,Cloudflare,Tells bots and people apart,30 minutes
cookie,cf_clearance,necessary,Cloudflare,Remembers that a security check was passed,1 year
cookie,_cfuvid,necessary,Cloudflare,Applies rate limits,Session
host,www.google-analytics.com,analytics,Google,Google Analytics measurement,
host,*.google-analytics.com,analytics,Google,Google Analytics measurement,
host,analytics.google.com,analytics,Google,Google Analytics measurement,
host,www.googletagmanager.com,analytics,Google,Google Tag Manager loads other tags,
host,*.doubleclick.net,marketing,Google,Google ad serving and measurement,
host,www.googleadservices.com,marketing,Google,Google Ads conversion tracking,
host,*.googlesyndication.com,marketing,Google,Google ad serving,
host,connect.facebook.net,marketing,Meta,Meta pixel and social plugins,
host,*.facebook.net,marketing,Meta,Meta scripts,
host,www.facebook.com,marketing,Meta,Meta pixel and social plugins,
host,static.hotjar.com,analytics,Hotjar,Hotjar behaviour analytics,
host,script.hotjar.com,analytics,Hotjar,Hotjar behaviour analytics,
host,*.hotjar.com,analytics,Hotjar,Hotjar behaviour analytics,
host,*.clarity.ms,analytics,Microsoft Clarity,Microsoft Clarity behaviour analytics,
host,bat.bing.com,marketing,Microsoft Advertising,Microsoft Advertising conversion tracking,
host,snap.licdn.com,marketing,LinkedIn,LinkedIn Insight tag,
host,px.ads.linkedin.com,marketing,LinkedIn,LinkedIn advertising,
host,analytics.tiktok.com,marketing,TikTok,TikTok pixel,
host,s.pinimg.com,marketing,Pinterest,Pinterest tag,
host,ct.pinterest.com,marketing,Pinterest,Pinterest tag,
host,static.ads-twitter.com,marketing,X,X advertising pixel,
host,analytics.twitter.com,marketing,X,X advertising analytics,
host,platform.twitter.com,marketing,X,X social embeds,
host,www.youtube.com,marketing,YouTube,Embedded YouTube videos,
host,www.youtube-nocookie.com,marketing,YouTube,Embedded YouTube videos (privacy-enhanced mode),
host,player.vimeo.com,marketing,Vimeo,Embedded Vimeo videos,
host,*.vimeo.com,marketing,Vimeo,Vimeo,
host,js.hs-scripts.com,marketing,HubSpot,HubSpot tracking code,
host,js.hs-analytics.net,marketing,HubSpot,HubSpot analytics,
host,js.hsforms.net,marketing,HubSpot,HubSpot forms,
host,*.hubspot.com,marketing,HubSpot,HubSpot,
host,*.matomo.cloud,analytics,Matomo,Matomo analytics,
host,plausible.io,analytics,Plausible,Plausible analytics,
host,js.stripe.com,necessary,Stripe,Stripe payments and fraud prevention,
host,*.stripe.com,necessary,Stripe,Stripe payments and fraud prevention,
host,m.stripe.network,necessary,Stripe,Stripe fraud prevention,
host,challenges.cloudflare.com,necessary,Cloudflare,Cloudflare Turnstile security check,
host,www.recaptcha.net,necessary,Google reCAPTCHA,Spam protection on forms,
host,fonts.googleapis.com,preferences,Google Fonts,Web fonts loaded from Google,
host,fonts.gstatic.com,preferences,Google Fonts,Web fonts loaded from Google,
host,maps.googleapis.com,preferences,Google Maps,Embedded maps,
host,maps.gstatic.com,preferences,Google Maps,Embedded maps,
```

(That is 41 cookie rows and 38 host rows.)

- [ ] **Step 5: Run the tests**

Run: `composer test -- --filter ClassificationFileTest`
Expected: PASS.

- [ ] **Step 6: Verify the Open Cookie Database header assumption (best effort)**

Run: `curl -sSL --max-time 20 https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/open-cookie-database.csv | head -2`
Expected: a header line containing `Category`, `Cookie / Data Key name`, `Description`, `Retention period`, `Data Controller`, `Wildcard match`. If the header differs, edit the five column names in `parseOpenCookieDatabase` and the `ocd()` test helper to match, and record the change in the commit message. If the network is unavailable, keep the names, and say so in the final report so a person checks before enabling the refresh.

- [ ] **Step 7: Commit**

```bash
composer format:php && composer typecheck
git add src/Scan data tests/Scan
git commit -m "feat: parse classification files and ship a bundled baseline

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: The finding ingestor

**Files:**
- Create: `src/Scan/FindingStore.php`, `src/Scan/DoctrineFindingStore.php`, `src/Scan/IngestSummary.php`, `src/Scan/FindingIngestor.php`
- Create (test helper): `tests/Scan/InMemoryFindingStore.php`
- Test: `tests/Scan/FindingIngestorTest.php`

**Interfaces:**
- Consumes: `Finding`, `Classifier::classify`, `ScanFinding`, `ScanRun::CRAWL`.
- Produces:
  - `FindingStore`: `all(): array<string, ScanFinding>` (keyed by fingerprint), `add(ScanFinding $finding): void`, `flush(): void`
  - `DoctrineFindingStore::__construct(EntityManagerInterface $em)`
  - `IngestSummary(public int $new, public int $changed, public int $unclassified, public array $newItems)` where `newItems` is `list<array{kind: string, name: string, host: string}>` capped at 20
  - `FindingIngestor::__construct(FindingStore $store, Classifier $classifier)`; `ingest(list<Finding> $found, string $source, int $now, bool $markGone): IngestSummary`

- [ ] **Step 1: Write the test helper and the failing tests**

`tests/Scan/InMemoryFindingStore.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Entity\ScanFinding;
use CookieManagerKit\Scan\FindingStore;

final class InMemoryFindingStore implements FindingStore
{
    /** @var array<string, ScanFinding> */
    public array $findings = [];

    public int $flushes = 0;

    public function all(): array
    {
        return $this->findings;
    }

    public function add(ScanFinding $finding): void
    {
        $this->findings[$finding->getFingerprint()] = $finding;
    }

    public function flush(): void
    {
        $this->flushes++;
    }

    public function get(string $kind, string $name, string $host): ?ScanFinding
    {
        return $this->findings[ScanFinding::fingerprintFor($kind, $name, $host)] ?? null;
    }
}
```

`tests/Scan/FindingIngestorTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\Classifier;
use CookieManagerKit\Entity\ScanFinding;
use CookieManagerKit\Scan\FindingIngestor;

final class FindingIngestorTest extends TestCase
{
    private InMemoryFindingStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryFindingStore();
    }

    private function found(string $kind, string $name, string $host): ScanFinding
    {
        $finding = $this->store->get($kind, $name, $host);

        $this->assertNotNull($finding, 'finding ' . $kind . ' ' . $name . ' was not stored');

        return $finding;
    }

    /**
     * @param list<array{kind: string, pattern: string, category: string, vendor: string, origin: string}> $entries
     */
    private function ingestor(array $entries = []): FindingIngestor
    {
        return new FindingIngestor($this->store, new Classifier($entries, static fn(string $name): bool => false));
    }

    private static function ga(): array
    {
        return [['kind' => 'cookie', 'pattern' => '_ga', 'category' => 'analytics', 'vendor' => 'Google', 'origin' => 'bundled']];
    }

    private static function cookie(string $name, string $page = 'https://e.com/'): Finding
    {
        return new Finding('cookie', $name, 'e.com', $page);
    }

    public function testANewMatchedFindingIsStoredWithItsSuggestion(): void
    {
        $summary = $this->ingestor(self::ga())->ingest([self::cookie('_ga')], 'crawl', 100, false);

        $finding = $this->store->get('cookie', '_ga', 'e.com');

        $this->assertNotNull($finding);
        $this->assertSame(ScanFinding::MATCHED, $finding->getStatus());
        $this->assertSame(['analytics', 'Google'], [$finding->getSuggestedCategory(), $finding->getSuggestedVendor()]);
        $this->assertSame([100, 100], [$finding->getFirstSeen(), $finding->getLastSeen()]);
        $this->assertSame(['crawl'], $finding->sources());
        $this->assertSame('https://e.com/', $finding->getExamplePage());
        $this->assertSame(1, $summary->new);
        $this->assertSame(0, $summary->unclassified);
        $this->assertSame([['kind' => 'cookie', 'name' => '_ga', 'host' => 'e.com']], $summary->newItems);
    }

    public function testAnUnknownFindingIsUnclassifiedAndCounted(): void
    {
        $summary = $this->ingestor()->ingest([self::cookie('mystery')], 'crawl', 100, false);

        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'mystery', 'e.com')->getStatus());
        $this->assertSame(1, $summary->unclassified);
    }

    public function testTheSameFindingTwiceInOneBatchIsOneFinding(): void
    {
        $summary = $this->ingestor()->ingest([self::cookie('a'), self::cookie('a')], 'crawl', 100, false);

        $this->assertCount(1, $this->store->findings);
        $this->assertSame(1, $summary->new);
    }

    public function testASecondRunUpdatesLastSeenAndKeepsFirstSeenAndTheExamplePage(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a', 'https://e.com/one')], 'crawl', 100, false);
        $summary = $ingestor->ingest([self::cookie('a', 'https://e.com/two')], 'crawl', 200, false);

        $finding = $this->store->get('cookie', 'a', 'e.com');

        $this->assertSame([100, 200], [$finding?->getFirstSeen(), $finding?->getLastSeen()]);
        $this->assertSame('https://e.com/one', $finding?->getExamplePage());
        $this->assertSame(0, $summary->new);
    }

    public function testSourcesAccumulateAcrossScanners(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a')], 'crawl', 100, false);
        $ingestor->ingest([self::cookie('a')], 'beacon', 200, false);

        $this->assertSame(['crawl', 'beacon'], $this->found('cookie', 'a', 'e.com')->sources());
    }

    public function testACompleteCrawlMarksUnseenCrawlFindingsGoneAndCountsThem(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('keep'), self::cookie('lose')], 'crawl', 100, false);

        $summary = $ingestor->ingest([self::cookie('keep')], 'crawl', 200, true);

        $this->assertSame(ScanFinding::GONE, $this->found('cookie', 'lose', 'e.com')->getStatus());
        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'keep', 'e.com')->getStatus());
        $this->assertSame(1, $summary->changed);
    }

    public function testAnIncompleteCrawlNeverMarksAnythingGone(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a')], 'crawl', 100, false);

        $summary = $ingestor->ingest([], 'crawl', 200, false);

        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'a', 'e.com')->getStatus());
        $this->assertSame(0, $summary->changed);
    }

    public function testABeaconRunNeverMarksAnythingGoneEvenWhenAskedTo(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a')], 'crawl', 100, false);

        $ingestor->ingest([], 'beacon', 200, true);

        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'a', 'e.com')->getStatus());
    }

    public function testBeaconOnlyFindingsSurviveACompleteCrawl(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('runtime')], 'beacon', 100, false);

        $ingestor->ingest([], 'crawl', 200, true);

        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'runtime', 'e.com')->getStatus());
    }

    public function testAFindingSeenByBothLosesOnlyTheCrawlSourceWhenTheCrawlStopsSeeingIt(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('both')], 'crawl', 100, false);
        $ingestor->ingest([self::cookie('both')], 'beacon', 150, false);

        $summary = $ingestor->ingest([], 'crawl', 200, true);

        $finding = $this->found('cookie', 'both', 'e.com');

        $this->assertSame(['beacon'], $finding->sources());
        $this->assertNotSame(ScanFinding::GONE, $finding->getStatus());
        $this->assertSame(0, $summary->changed);
    }

    public function testAGoneFindingThatReturnsGoesBackToItsPreviousStatusAndCountsAsChanged(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a')], 'crawl', 100, false);
        $this->found('cookie', 'a', 'e.com')->setStatus(ScanFinding::APPROVED);
        $ingestor->ingest([], 'crawl', 200, true);

        $summary = $ingestor->ingest([self::cookie('a')], 'crawl', 300, false);

        $this->assertSame(ScanFinding::APPROVED, $this->found('cookie', 'a', 'e.com')->getStatus());
        $this->assertSame(1, $summary->changed);
        $this->assertSame(0, $summary->new);
    }

    public function testApprovedAndIgnoredFindingsAreNeverReclassified(): void
    {
        $this->ingestor()->ingest([self::cookie('_ga'), self::cookie('_gb')], 'crawl', 100, false);
        $this->found('cookie', '_ga', 'e.com')->setStatus(ScanFinding::APPROVED);
        $this->found('cookie', '_gb', 'e.com')->setStatus(ScanFinding::IGNORED);

        $this->ingestor(self::ga())->ingest([self::cookie('_ga'), self::cookie('_gb')], 'crawl', 200, false);

        $this->assertSame(ScanFinding::APPROVED, $this->found('cookie', '_ga', 'e.com')->getStatus());
        $this->assertNull($this->found('cookie', '_ga', 'e.com')->getSuggestedCategory());
        $this->assertSame(ScanFinding::IGNORED, $this->found('cookie', '_gb', 'e.com')->getStatus());
    }

    public function testAnUnreviewedFindingIsReclassifiedWhenBetterDataArrives(): void
    {
        $this->ingestor()->ingest([self::cookie('_ga')], 'crawl', 100, false);
        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', '_ga', 'e.com')->getStatus());

        $summary = $this->ingestor(self::ga())->ingest([self::cookie('_ga')], 'crawl', 200, false);

        $this->assertSame(ScanFinding::MATCHED, $this->found('cookie', '_ga', 'e.com')->getStatus());
        $this->assertSame('analytics', $this->found('cookie', '_ga', 'e.com')->getSuggestedCategory());
        $this->assertSame(0, $summary->unclassified);
    }

    public function testHostileNamesAreStoredVerbatim(): void
    {
        $name = '"><img src=x onerror=alert(1)>';

        $this->ingestor()->ingest([self::cookie($name)], 'crawl', 100, false);

        $this->assertSame($name, $this->found('cookie', $name, 'e.com')->getName());
    }

    public function testTheNewItemsListIsCapped(): void
    {
        $found = [];

        for ($i = 0; $i < 50; $i++) {
            $found[] = self::cookie('c' . $i);
        }

        $summary = $this->ingestor()->ingest($found, 'crawl', 100, false);

        $this->assertSame(50, $summary->new);
        $this->assertCount(20, $summary->newItems);
    }

    public function testEverythingIsFlushedOnce(): void
    {
        $this->ingestor()->ingest([self::cookie('a'), self::cookie('b')], 'crawl', 100, false);

        $this->assertSame(1, $this->store->flushes);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter FindingIngestorTest`
Expected: FAIL with "Class ... FindingStore not found" / "InMemoryFindingStore".

- [ ] **Step 3: Write the store seam and the summary**

`src/Scan/FindingStore.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;

/**
 * The only thing the ingestor needs from storage, so its rules can be tested without a database.
 */
interface FindingStore
{
    /**
     * @return array<string, ScanFinding> every stored finding, keyed by fingerprint
     */
    public function all(): array;

    public function add(ScanFinding $finding): void;

    public function flush(): void;
}
```

`src/Scan/DoctrineFindingStore.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\ScanFinding;

final class DoctrineFindingStore implements FindingStore
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function all(): array
    {
        $found = [];

        foreach ($this->em->getRepository(ScanFinding::class)->findAll() as $finding) {
            $found[$finding->getFingerprint()] = $finding;
        }

        return $found;
    }

    public function add(ScanFinding $finding): void
    {
        $this->em->persist($finding);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
```

`src/Scan/IngestSummary.php`:

```php
<?php

namespace CookieManagerKit\Scan;

final readonly class IngestSummary
{
    /**
     * @param list<array{kind: string, name: string, host: string}> $newItems at most 20, for alert emails
     */
    public function __construct(
        public int $new,
        public int $changed,
        public int $unclassified,
        public array $newItems,
    ) {}
}
```

- [ ] **Step 4: Write `src/Scan/FindingIngestor.php`**

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\ScanFinding;

/**
 * The only code that writes findings. A finding is identified by (kind, name, host). First sight stores it with a
 * suggestion (or as unclassified). Later sightings update when it was last seen and who saw it, and re-classify it
 * only while it is still unreviewed. A complete crawl removes the crawl as a source of findings it no longer sees;
 * a finding with no source left becomes `gone` (and remembers what it was, so it can come back).
 */
final class FindingIngestor
{
    private const MAX_NEW_ITEMS = 20;

    public function __construct(
        private readonly FindingStore $store,
        private readonly Classifier $classifier,
    ) {}

    /**
     * @param list<Finding> $found
     * @param bool          $markGone only honoured for a crawl: true when every page was fetched and nothing was excluded or cut off
     */
    public function ingest(array $found, string $source, int $now, bool $markGone): IngestSummary
    {
        $existing = $this->store->all();
        $seen = [];
        $new = 0;
        $changed = 0;
        $newItems = [];

        foreach ($found as $item) {
            $fingerprint = ScanFinding::fingerprintFor($item->kind, $item->name, $item->host);
            $seen[$fingerprint] = true;
            $finding = $existing[$fingerprint] ?? null;

            if ($finding === null) {
                $finding = (new ScanFinding())->identify($item->kind, $item->name, $item->host);
                $finding->setFirstSeen($now)->setExamplePage($item->page)->addSource($source)->setLastSeen($now);
                $this->classify($finding);
                $this->store->add($finding);
                $existing[$fingerprint] = $finding;
                $new++;

                if (count($newItems) < self::MAX_NEW_ITEMS) {
                    $newItems[] = ['kind' => $item->kind, 'name' => $item->name, 'host' => $item->host];
                }

                continue;
            }

            if ($finding->getStatus() === ScanFinding::GONE) {
                $finding->restore();
                $changed++;
            }

            $finding->addSource($source)->setLastSeen($now);

            if ($finding->getExamplePage() === '') {
                $finding->setExamplePage($item->page);
            }

            if ($finding->isReviewable()) {
                $this->classify($finding);
            }
        }

        if ($markGone && $source === ScanRun::CRAWL) {
            foreach ($existing as $fingerprint => $finding) {
                if (isset($seen[$fingerprint]) || !$finding->hasSource('crawl')) {
                    continue;
                }

                $finding->removeSource('crawl');

                if ($finding->sources() === [] && $finding->getStatus() !== ScanFinding::GONE) {
                    $finding->markGone();
                    $changed++;
                }
            }
        }

        $this->store->flush();

        $unclassified = count(array_filter(
            $existing,
            static fn(ScanFinding $finding): bool => $finding->getStatus() === ScanFinding::UNCLASSIFIED,
        ));

        return new IngestSummary($new, $changed, $unclassified, $newItems);
    }

    private function classify(ScanFinding $finding): void
    {
        $suggestion = $this->classifier->classify($finding->getKind(), $finding->getName(), $finding->getHost());

        $finding->setSuggestion($suggestion?->category, $suggestion?->vendor);
        $finding->setStatus($suggestion === null ? ScanFinding::UNCLASSIFIED : ScanFinding::MATCHED);
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `composer test -- --filter FindingIngestorTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
composer format:php && composer typecheck
git add src/Scan tests/Scan
git commit -m "feat: add the finding ingestor with gone, restore and reclassify rules

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Classification storage, install seeding and refresh

**Files:**
- Create: `src/Scan/ClassificationStore.php`, `src/Scan/SnapshotSource.php`, `src/Scan/GithubSnapshot.php`, `src/Scan/Refresher.php`
- Modify: `controller.php` (`installOrUpgrade`)
- Test: `tests/Scan/ClassificationStoreTest.php`, `tests/Scan/RefresherTest.php`, `tests/Scan/GithubSnapshotTest.php`

**Interfaces:**
- Consumes: `ClassificationFile::parseBundled`, `parseOpenCookieDatabase`, `baselinePath`; `ClassificationEntry::define`.
- Produces:
  - `ClassificationStore::__construct(EntityManagerInterface $em)`; `entries(): list<array{kind, pattern, category, vendor, origin}>`; `replaceOrigin(string $origin, list<array{kind, pattern, category, vendor, description, duration}> $rows): void`; `syncBundled(?string $path = null): void`; `addOverride(string $kind, string $pattern, string $category, string $vendor): void`
  - `SnapshotSource::fetch(): string`
  - `GithubSnapshot::__construct(ClientInterface $client)`; constant `URL`, `MAX_BYTES`
  - `Refresher::__construct(SnapshotSource $source, ClassificationStore $store)`; `refresh(): int` (rows stored; throws `\RuntimeException` and stores nothing when the download is not valid)

- [ ] **Step 1: Write the failing tests**

`tests/Scan/ClassificationStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Scan\ClassificationStore;
use CookieManagerKit\Entity\ClassificationEntry;
use Doctrine\DBAL\Driver\DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;

final class ClassificationStoreTest extends TestCase
{
    private function manager(): EntityManagerInterface&MockObject
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('transactional')->willReturnCallback(static fn(callable $work): mixed => $work());

        return $em;
    }

    public function testEntriesAreReturnedAsPlainArrays(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findAll')->willReturn([
            (new ClassificationEntry())->define('cookie', '_ga', 'analytics', 'Google', 'd', '2 years', ClassificationEntry::BUNDLED),
        ]);
        $em = $this->manager();
        $em->method('getRepository')->with(ClassificationEntry::class)->willReturn($repository);

        $this->assertSame(
            [['kind' => 'cookie', 'pattern' => '_ga', 'category' => 'analytics', 'vendor' => 'Google', 'origin' => 'bundled']],
            (new ClassificationStore($em))->entries(),
        );
    }

    public function testBeforeTheUpgradeHasCreatedTheTableThereAreNoEntries(): void
    {
        $driver = new class('no such table') extends \Exception implements DriverException {
            public function getErrorCode(): int
            {
                return 1146;
            }

            public function getSQLState(): string
            {
                return '42S02';
            }
        };
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findAll')->willThrowException(new TableNotFoundException('no such table', $driver));
        $em = $this->manager();
        $em->method('getRepository')->willReturn($repository);

        $this->assertSame([], (new ClassificationStore($em))->entries());
    }

    public function testReplaceOriginDeletesThatOriginAndStoresTheRowsInOneTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->with($this->stringContains('DELETE FROM cmkClassificationEntries WHERE origin = ?'), ['refreshed']);
        $em = $this->manager();
        $em->method('getConnection')->willReturn($connection);
        $persisted = [];
        $em->expects($this->exactly(2))->method('persist')->willReturnCallback(function (ClassificationEntry $entry) use (&$persisted): void {
            $persisted[] = $entry;
        });
        $em->expects($this->once())->method('flush');

        (new ClassificationStore($em))->replaceOrigin('refreshed', [
            ['kind' => 'cookie', 'pattern' => 'a', 'category' => 'analytics', 'vendor' => 'V', 'description' => 'd', 'duration' => '1'],
            ['kind' => 'host', 'pattern' => 'b.example.com', 'category' => 'marketing', 'vendor' => 'W', 'description' => '', 'duration' => ''],
        ]);

        $this->assertSame(['refreshed', 'refreshed'], array_map(static fn(ClassificationEntry $e): string => $e->getOrigin(), $persisted));
        $this->assertSame(['a', 'b.example.com'], array_map(static fn(ClassificationEntry $e): string => $e->getPattern(), $persisted));
    }

    public function testSyncBundledLoadsTheShippedBaseline(): void
    {
        $em = $this->manager();
        $em->method('getConnection')->willReturn($this->createMock(Connection::class));
        $count = 0;
        $em->method('persist')->willReturnCallback(static function () use (&$count): void {
            $count++;
        });

        (new ClassificationStore($em))->syncBundled();

        $this->assertGreaterThan(60, $count);
    }

    public function testSyncBundledRefusesAnInvalidFileAndTouchesNothing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cmk');
        file_put_contents($path, "not,the,right,header\n");
        $em = $this->manager();
        $em->expects($this->never())->method('getConnection');
        $em->expects($this->never())->method('persist');

        try {
            (new ClassificationStore($em))->syncBundled($path);
            $this->fail('accepted an invalid file');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            unlink($path);
        }
    }

    public function testAnOverrideIsStoredWithTheOverrideOrigin(): void
    {
        $em = $this->manager();
        $stored = null;
        $em->method('persist')->willReturnCallback(function (ClassificationEntry $entry) use (&$stored): void {
            $stored = $entry;
        });
        $em->expects($this->once())->method('flush');

        (new ClassificationStore($em))->addOverride('cookie', 'my_cookie', 'preferences', 'Us');

        $this->assertSame([ClassificationEntry::OVERRIDE, 'my_cookie', 'preferences'], [$stored?->getOrigin(), $stored?->getPattern(), $stored?->getCategoryHandle()]);
    }
}
```

`tests/Scan/RefresherTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Refresher;
use CookieManagerKit\Scan\FetchFailed;
use CookieManagerKit\Scan\SnapshotSource;
use CookieManagerKit\Scan\ClassificationStore;

final class RefresherTest extends TestCase
{
    private static function source(string|\Throwable $result): SnapshotSource
    {
        return new class($result) implements SnapshotSource {
            public function __construct(private string|\Throwable $result) {}

            public function fetch(): string
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    private static function validCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['ID', 'Platform', 'Category', 'Cookie / Data Key name', 'Domain', 'Description', 'Retention period', 'Data Controller', 'User Privacy & GDPR Rights Portals', 'Wildcard match'], ',', '"', '');

        for ($i = 0; $i < 60; $i++) {
            fputcsv($out, ['i' . $i, 'P', 'Analytics', 'c' . $i, 'x.com', 'd', '1 year', 'V', '', '0'], ',', '"', '');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function testStoresTheRefreshedRowsAndReturnsTheCount(): void
    {
        $store = $this->createMock(ClassificationStore::class);
        $store->expects($this->once())->method('replaceOrigin')->with('refreshed', $this->callback(static fn(array $rows): bool => count($rows) === 60));

        $this->assertSame(60, (new Refresher(self::source(self::validCsv()), $store))->refresh());
    }

    public function testAnInvalidDownloadStoresNothing(): void
    {
        $store = $this->createMock(ClassificationStore::class);
        $store->expects($this->never())->method('replaceOrigin');

        $this->expectException(\RuntimeException::class);

        (new Refresher(self::source("<html>captive portal</html>"), $store))->refresh();
    }

    public function testADownloadFailureStoresNothingAndSurfaces(): void
    {
        $store = $this->createMock(ClassificationStore::class);
        $store->expects($this->never())->method('replaceOrigin');

        $this->expectException(FetchFailed::class);

        (new Refresher(self::source(new FetchFailed('offline')), $store))->refresh();
    }
}
```

`tests/Scan/GithubSnapshotTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Handler\MockHandler;
use CookieManagerKit\Scan\FetchFailed;
use CookieManagerKit\Scan\GithubSnapshot;

final class GithubSnapshotTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /**
     * @param list<Response> $queue
     */
    private function snapshot(array $queue): GithubSnapshot
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new GithubSnapshot(new Client(['handler' => $stack]));
    }

    public function testTheUrlIsAFixedHttpsConstant(): void
    {
        $this->assertStringStartsWith('https://raw.githubusercontent.com/', GithubSnapshot::URL);
    }

    public function testReturnsTheBodyOfAFixedUrlWithoutFollowingRedirects(): void
    {
        $snapshot = $this->snapshot([new Response(200, [], 'csv-body')]);

        $this->assertSame('csv-body', $snapshot->fetch());
        $this->assertSame(GithubSnapshot::URL, (string) $this->history[0]['request']->getUri());
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testANon200IsRefused(): void
    {
        $this->expectException(FetchFailed::class);

        $this->snapshot([new Response(302, ['Location' => 'https://evil.example.net/'])])->fetch();
    }

    public function testAnOversizeBodyIsRefused(): void
    {
        $this->expectException(FetchFailed::class);

        $this->snapshot([new Response(200, [], str_repeat('a', GithubSnapshot::MAX_BYTES + 1))])->fetch();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'ClassificationStoreTest|RefresherTest|GithubSnapshotTest'`
Expected: FAIL with "Class ... ClassificationStore not found".

- [ ] **Step 3: Write `src/Scan/ClassificationStore.php`**

```php
<?php

namespace CookieManagerKit\Scan;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\ClassificationEntry;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Reads and writes classification rows. Replacing an origin is one transaction: either every new row is stored or
 * the old rows stay.
 */
class ClassificationStore
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @return list<array{kind: string, pattern: string, category: string, vendor: string, origin: string}>
     */
    public function entries(): array
    {
        try {
            $rows = $this->em->getRepository(ClassificationEntry::class)->findAll();
        } catch (TableNotFoundException) {
            // The package Update has not run yet; there is simply nothing to match against.
            return [];
        }

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = [
                'kind' => $row->getKind(),
                'pattern' => $row->getPattern(),
                'category' => $row->getCategoryHandle(),
                'vendor' => $row->getVendor(),
                'origin' => $row->getOrigin(),
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}> $rows
     */
    public function replaceOrigin(string $origin, array $rows): void
    {
        $this->em->transactional(function () use ($origin, $rows): void {
            $this->em->getConnection()->executeStatement('DELETE FROM cmkClassificationEntries WHERE origin = ?', [$origin]);

            foreach ($rows as $row) {
                $this->em->persist((new ClassificationEntry())->define($row['kind'], $row['pattern'], $row['category'], $row['vendor'], $row['description'], $row['duration'], $origin));
            }

            $this->em->flush();
        });
    }

    /**
     * Loads the shipped baseline over the previous bundled rows. Refreshed rows and overrides are untouched.
     */
    public function syncBundled(?string $path = null): void
    {
        $csv = @file_get_contents($path ?? ClassificationFile::baselinePath());
        $rows = $csv === false ? null : ClassificationFile::parseBundled($csv);

        if ($rows === null) {
            throw new \RuntimeException('The bundled classification file is missing or invalid.');
        }

        $this->replaceOrigin(ClassificationEntry::BUNDLED, $rows);
    }

    public function addOverride(string $kind, string $pattern, string $category, string $vendor): void
    {
        $this->em->persist((new ClassificationEntry())->define($kind, $pattern, $category, $vendor, '', '', ClassificationEntry::OVERRIDE));
        $this->em->flush();
    }
}
```

- [ ] **Step 4: Write the snapshot source and the refresher**

`src/Scan/SnapshotSource.php`:

```php
<?php

namespace CookieManagerKit\Scan;

interface SnapshotSource
{
    /**
     * @throws FetchFailed
     */
    public function fetch(): string;
}
```

`src/Scan/GithubSnapshot.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Downloads the Open Cookie Database CSV from one fixed HTTPS URL. The URL is a constant (never admin input or
 * request data), redirects are not followed, and the body is size-capped.
 */
final class GithubSnapshot implements SnapshotSource
{
    public const URL = 'https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/open-cookie-database.csv';
    public const MAX_BYTES = ClassificationFile::MAX_BYTES;

    public function __construct(private readonly ClientInterface $client) {}

    public function fetch(): string
    {
        try {
            $response = $this->client->request('GET', self::URL, [
                'allow_redirects' => false,
                'http_errors' => false,
                'timeout' => 30,
                'connect_timeout' => 10,
                'headers' => ['User-Agent' => 'CookieManagerKit-Scanner/1.0'],
            ]);
        } catch (GuzzleException $e) {
            throw new FetchFailed('The download failed (' . $e::class . ').', 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new FetchFailed('The download answered ' . $response->getStatusCode() . '.');
        }

        $body = (string) $response->getBody();

        if (strlen($body) > self::MAX_BYTES) {
            throw new FetchFailed('The download is larger than the limit.');
        }

        return $body;
    }
}
```

`src/Scan/Refresher.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ClassificationEntry;

/**
 * Replaces the `refreshed` classification rows with a validated download. Nothing is stored unless the whole file
 * passes; any failure leaves the previous rows exactly as they were.
 */
final class Refresher
{
    public function __construct(
        private readonly SnapshotSource $source,
        private readonly ClassificationStore $store,
    ) {}

    /**
     * @return int how many rows were stored
     *
     * @throws FetchFailed       when the download fails
     * @throws \RuntimeException when the download is not a valid snapshot
     */
    public function refresh(): int
    {
        $rows = ClassificationFile::parseOpenCookieDatabase($this->source->fetch());

        if ($rows === null) {
            throw new \RuntimeException('The downloaded classification data failed validation and was discarded.');
        }

        $this->store->replaceOrigin(ClassificationEntry::REFRESHED, $rows);

        return count($rows);
    }
}
```

- [ ] **Step 5: Seed the bundled rows on install and upgrade**

In `controller.php` add the import `use CookieManagerKit\Scan\ClassificationStore;` to the existing `use` block, and change the end of `installOrUpgrade` to:

```php
        $em = app(EntityManagerInterface::class);

        Installer::seed($em, Catalog::defaultLocale());
        (new ClassificationStore($em))->syncBundled();
    }
```

(replacing the single `Installer::seed(app(EntityManagerInterface::class), Catalog::defaultLocale());` line).

- [ ] **Step 6: Run the tests and the suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
composer format:php && composer typecheck
git add src/Scan controller.php tests/Scan
git commit -m "feat: store classification data, seed the baseline and add the refresher

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Crawl orchestration, alerts and the stale-runtime rule

**Files:**
- Create (test helpers): `tests/Scan/RecordingAlerts.php`, `tests/Scan/RecordingMailer.php`
- Create: `src/Scan/StaleCheck.php`, `src/Scan/ScanRuns.php`, `src/Scan/AlertMessage.php`, `src/Scan/AlertSender.php`, `src/Scan/MailAlerts.php`, `src/Scan/UrlSource.php`, `src/Scan/PublishedPages.php`, `src/Scan/CrawlTarget.php`, `src/Scan/CrawlRunner.php`, `src/Scan/Services.php`
- Test: `tests/Scan/StaleCheckTest.php`, `tests/Scan/ScanRunsTest.php`, `tests/Scan/AlertMessageTest.php`, `tests/Scan/MailAlertsTest.php`, `tests/Scan/PublishedPagesTest.php`, `tests/Scan/CrawlRunnerTest.php`

**Interfaces:**
- Consumes: `UrlSelector::select`, `Scanner`, `ScanContext`, `FindingIngestor::ingest`, `IngestSummary`, `ScanRun`, `Settings`, `ClassificationStore::entries`, `Classifier`, `UrlGuard::forSite`, `GuzzleFetcher`, `CrawlScanner`, `Output::safeUrl`, `ErrorLog::record`.
- Produces:
  - `StaleCheck::isStale(?int $lastRuntimeScanAt, int $now, int $days): bool` (true when never run, or older than `$days` days)
  - `ScanRuns::SESSION_SECONDS` (1800); `ScanRuns::isExpired(ScanRun $run, int $now): bool` (a `running` run past `SESSION_SECONDS + 60`); `ScanRuns::acceptsReports(?ScanRun $run, int $now): bool` (a `running` beacon run inside `SESSION_SECONDS`); `ScanRuns::closeExpired(EntityManagerInterface $em, int $now): int`; `ScanRuns::lastBeaconAt(EntityManagerInterface $em): ?int`; `ScanRuns::recent(EntityManagerInterface $em, int $limit): list<ScanRun>`
  - `AlertMessage::build(string $siteHost, IngestSummary $summary, bool $runtimeStale, int $staleDays, string $findingsUrl): array{subject: string, body: string}`
  - `AlertSender::send(ScanRun $run, IngestSummary $summary, bool $runtimeStale): void`; `MailAlerts::__construct(Settings $settings, string $siteHost, string $findingsUrl, ?\Closure $mail = null)`
  - `UrlSource::all(): list<string>`; `PublishedPages::__construct(string $baseUrl)`, `PublishedPages::rebase(string $url, string $baseUrl): ?string`; `CrawlTarget::baseUrl(): string`
  - `CrawlRunner::__construct(EntityManagerInterface $em, Settings $settings, UrlSource $urls, Scanner $scanner, FindingIngestor $ingestor, AlertSender $alerts, string $siteHost, \Closure $lastRuntimeScanAt, ?\Closure $clock = null)`; `run(): ScanRun`
  - `Services::classifier(): Classifier`, `ingestor(): FindingIngestor`, `crawlRunner(): CrawlRunner`

- [ ] **Step 1: Write the test helpers and the failing tests**

`tests/Scan/RecordingAlerts.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Scan\AlertSender;
use CookieManagerKit\Scan\IngestSummary;

/**
 * Remembers every alert it was asked to send.
 */
final class RecordingAlerts implements AlertSender
{
    /** @var list<array{ScanRun, IngestSummary, bool}> */
    public array $sent = [];

    public function send(ScanRun $run, IngestSummary $summary, bool $runtimeStale): void
    {
        $this->sent[] = [$run, $summary, $runtimeStale];
    }
}
```

`tests/Scan/RecordingMailer.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

/**
 * Stands in for Concrete's mail service and remembers every call made to it.
 */
final class RecordingMailer
{
    /** @var list<array<int, string>> */
    public array $calls = [];

    public function to(string $email): void
    {
        $this->calls[] = ['to', $email];
    }

    public function setSubject(string $subject): void
    {
        $this->calls[] = ['subject', $subject];
    }

    public function setBody(string $body): void
    {
        $this->calls[] = ['body', $body];
    }

    public function sendMail(): void
    {
        $this->calls[] = ['send'];
    }
}
```

`tests/Scan/StaleCheckTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\StaleCheck;

final class StaleCheckTest extends TestCase
{
    public function testNeverRunIsStale(): void
    {
        $this->assertTrue(StaleCheck::isStale(null, 1000000, 60));
    }

    public function testRecentIsNotStaleAndOldIs(): void
    {
        $now = 100 * 86400;

        $this->assertFalse(StaleCheck::isStale($now - 59 * 86400, $now, 60));
        $this->assertFalse(StaleCheck::isStale($now - 60 * 86400, $now, 60));
        $this->assertTrue(StaleCheck::isStale($now - 60 * 86400 - 1, $now, 60));
    }

    public function testATimeInTheFutureIsNotStale(): void
    {
        $this->assertFalse(StaleCheck::isStale(2000000, 1000000, 60));
    }
}
```

`tests/Scan/ScanRunsTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Scan\ScanRuns;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ScanRunsTest extends TestCase
{
    private static function beacon(int $started, string $status = ScanRun::RUNNING): ScanRun
    {
        $run = (new ScanRun())->start(ScanRun::BEACON, $started);

        return $status === ScanRun::RUNNING ? $run : $run->finish($status, $started + 10);
    }

    public function testARunningBeaconRunAcceptsReportsInsideItsSession(): void
    {
        $run = self::beacon(1000);

        $this->assertTrue(ScanRuns::acceptsReports($run, 1000));
        $this->assertTrue(ScanRuns::acceptsReports($run, 1000 + ScanRuns::SESSION_SECONDS));
        $this->assertFalse(ScanRuns::acceptsReports($run, 1000 + ScanRuns::SESSION_SECONDS + 1));
    }

    public function testAnEndedMissingOrCrawlRunNeverAcceptsReports(): void
    {
        $this->assertFalse(ScanRuns::acceptsReports(null, 1000));
        $this->assertFalse(ScanRuns::acceptsReports(self::beacon(1000, ScanRun::COMPLETE), 1001));
        $this->assertFalse(ScanRuns::acceptsReports(self::beacon(1000, ScanRun::FAILED), 1001));
        $this->assertFalse(ScanRuns::acceptsReports((new ScanRun())->start(ScanRun::CRAWL, 1000), 1001));
    }

    public function testIsExpiredNeedsRunningAndTheGracePeriod(): void
    {
        $run = self::beacon(1000);

        $this->assertFalse(ScanRuns::isExpired($run, 1000 + ScanRuns::SESSION_SECONDS + 60));
        $this->assertTrue(ScanRuns::isExpired($run, 1000 + ScanRuns::SESSION_SECONDS + 61));
        $this->assertFalse(ScanRuns::isExpired(self::beacon(1000, ScanRun::COMPLETE), 999999));
    }

    public function testCloseExpiredFinishesOnlyExpiredRunningBeaconRuns(): void
    {
        $old = self::beacon(1000);
        $fresh = self::beacon(100000);
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findBy')->with(['source' => ScanRun::BEACON, 'status' => ScanRun::RUNNING])->willReturn([$old, $fresh]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(ScanRun::class)->willReturn($repository);
        $em->expects($this->once())->method('flush');

        $closed = ScanRuns::closeExpired($em, 100001);

        $this->assertSame(1, $closed);
        $this->assertSame(ScanRun::COMPLETE, $old->getStatus());
        $this->assertSame(ScanRun::RUNNING, $fresh->getStatus());
    }

    public function testLastBeaconAtPrefersTheFinishTime(): void
    {
        $finished = self::beacon(1000, ScanRun::COMPLETE);
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->with(['source' => ScanRun::BEACON], ['startedAt' => 'DESC'])->willReturn($finished);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $this->assertSame(1010, ScanRuns::lastBeaconAt($em));
    }

    public function testLastBeaconAtIsNullWhenThereWasNeverARun(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $this->assertNull(ScanRuns::lastBeaconAt($em));
    }
}
```

`tests/Scan/AlertMessageTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\AlertMessage;
use CookieManagerKit\Scan\IngestSummary;

final class AlertMessageTest extends TestCase
{
    private static function summary(int $new = 2, int $changed = 1, int $unclassified = 3, array $items = []): IngestSummary
    {
        return new IngestSummary($new, $changed, $unclassified, $items);
    }

    public function testSubjectAndBodyCarryTheCounts(): void
    {
        $message = AlertMessage::build('example.com', self::summary(2, 1, 3, [['kind' => 'cookie', 'name' => '_ga', 'host' => 'example.com']]), false, 60, 'https://example.com/dashboard/cookie_manager/findings');

        $this->assertSame('Cookie scan: 2 new, 1 changed on example.com', $message['subject']);
        $this->assertStringContainsString('2 new', $message['body']);
        $this->assertStringContainsString('1 changed', $message['body']);
        $this->assertStringContainsString('3 findings still need a category', $message['body']);
        $this->assertStringContainsString('- cookie: _ga (example.com)', $message['body']);
        $this->assertStringContainsString('https://example.com/dashboard/cookie_manager/findings', $message['body']);
        $this->assertStringNotContainsString('runtime scan', $message['body']);
    }

    public function testTheStaleWarningAppearsOnlyWhenStale(): void
    {
        $message = AlertMessage::build('example.com', self::summary(), true, 60, '');

        $this->assertStringContainsString('No runtime scan has run in the last 60 days', $message['body']);
    }

    public function testNamesCannotInjectLinesOrHeaders(): void
    {
        $hostile = "x\r\nBcc: evil@example.com\r\n\r\nClick https://evil.example.net";
        $message = AlertMessage::build("example.com\r\nBcc: a@b.c", self::summary(1, 0, 0, [['kind' => 'cookie', 'name' => $hostile, 'host' => "h\nost"]]), false, 60, '');

        $this->assertStringNotContainsString("\r", $message['subject'] . $message['body']);
        $this->assertStringNotContainsString("\n", $message['subject']);
        $this->assertSame(1, substr_count($message['body'], 'Bcc:'));
        $this->assertStringContainsString('- cookie: x Bcc: evil@example.com Click https://evil.example.net (h ost)', $message['body']);
    }

    public function testLongNamesAreCapped(): void
    {
        $message = AlertMessage::build('example.com', self::summary(1, 0, 0, [['kind' => 'cookie', 'name' => str_repeat('n', 5000), 'host' => 'example.com']]), false, 60, '');

        $this->assertLessThan(1000, strlen($message['body']));
    }

    public function testAnUnsafeFindingsUrlIsLeftOut(): void
    {
        $message = AlertMessage::build('example.com', self::summary(), false, 60, 'javascript:alert(1)');

        $this->assertStringNotContainsString('javascript', $message['body']);
    }

    public function testTheSiteHostInTheSubjectIsReducedToHostCharacters(): void
    {
        $message = AlertMessage::build('<b>exa mple.com</b>', self::summary(), false, 60, '');

        $this->assertSame('Cookie scan: 2 new, 1 changed on bexample.comb', $message['subject']);
    }
}
```

`tests/Scan/MailAlertsTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Scan\IngestSummary;
use CookieManagerKit\Scan\MailAlerts;

final class MailAlertsTest extends TestCase
{
    public function testSendsAPlainTextMailToTheConfiguredAddress(): void
    {
        $mailer = new RecordingMailer();
        $settings = (new Settings())->setScanAlertEmail('ops@example.com');
        $alerts = new MailAlerts($settings, 'example.com', 'https://example.com/f', static fn(): object => $mailer);

        $alerts->send(new ScanRun(), new IngestSummary(1, 0, 0, []), false);

        $this->assertSame(['to', 'ops@example.com'], $mailer->calls[0]);
        $this->assertSame('subject', $mailer->calls[1][0]);
        $this->assertSame('body', $mailer->calls[2][0]);
        $this->assertSame(['send'], $mailer->calls[3]);
    }

    public function testSendsNothingWithoutAnAddress(): void
    {
        $mailer = new RecordingMailer();
        $alerts = new MailAlerts(new Settings(), 'example.com', '', static fn(): object => $mailer);

        $alerts->send(new ScanRun(), new IngestSummary(1, 0, 0, []), false);

        $this->assertSame([], $mailer->calls);
    }

    public function testAMailFailureNeverEscapes(): void
    {
        $settings = (new Settings())->setScanAlertEmail('ops@example.com');
        $alerts = new MailAlerts($settings, 'example.com', '', static function (): object {
            throw new \RuntimeException('smtp down');
        });

        $alerts->send(new ScanRun(), new IngestSummary(1, 0, 0, []), false);

        $this->addToAssertionCount(1);
    }
}
```

`tests/Scan/PublishedPagesTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\PublishedPages;

final class PublishedPagesTest extends TestCase
{
    public function testTheHostAlwaysComesFromTheConfiguredBaseNeverFromTheResolvedUrl(): void
    {
        $this->assertSame('https://example.com/about', PublishedPages::rebase('https://attacker.example.net/about', 'https://example.com'));
        $this->assertSame('https://example.com/about?x=1', PublishedPages::rebase('http://other.example.org:8080/about?x=1', 'https://example.com'));
    }

    public function testThePathPrefixOfASubdirectoryInstallIsKept(): void
    {
        $this->assertSame('https://example.com/site/about', PublishedPages::rebase('https://example.com/site/about', 'https://example.com'));
    }

    public function testRelativeResolvedUrlsGetTheBase(): void
    {
        $this->assertSame('https://example.com/about', PublishedPages::rebase('/about', 'https://example.com'));
        $this->assertSame('https://example.com/', PublishedPages::rebase('', 'https://example.com'));
    }

    public function testAnUnusableResolvedUrlGivesNull(): void
    {
        $this->assertNull(PublishedPages::rebase('http://', 'https://example.com'));
    }
}
```

`tests/Scan/CrawlRunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\Scanner;
use CookieManagerKit\Scan\Classifier;
use CookieManagerKit\Scan\ScanResult;
use CookieManagerKit\Scan\UrlSource;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Scan\CrawlRunner;
use CookieManagerKit\Scan\ScanContext;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Entity\ScanFinding;
use CookieManagerKit\Scan\FindingIngestor;
use Doctrine\ORM\EntityManagerInterface;

final class CrawlRunnerTest extends TestCase
{
    private InMemoryFindingStore $store;

    private RecordingAlerts $alerts;

    protected function setUp(): void
    {
        $this->store = new InMemoryFindingStore();
        $this->alerts = new RecordingAlerts();
    }

    private function found(string $kind, string $name, string $host): ScanFinding
    {
        $finding = $this->store->get($kind, $name, $host);

        $this->assertNotNull($finding, 'finding ' . $kind . ' ' . $name . ' was not stored');

        return $finding;
    }

    /**
     * @param list<string>  $pages
     * @param ScanResult|\Throwable $result
     */
    private function runner(array $pages, ScanResult|\Throwable $result, ?Settings $settings = null, ?int $lastRuntime = 999990): CrawlRunner
    {
        $urls = new class($pages) implements UrlSource {
            public function __construct(private array $pages) {}

            public function all(): array
            {
                return $this->pages;
            }
        };
        $scanner = new class($result) implements Scanner {
            public ?ScanContext $context = null;

            public function __construct(private ScanResult|\Throwable $result) {}

            public function scan(ScanContext $context): ScanResult
            {
                $this->context = $context;

                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
        return new CrawlRunner(
            $this->createMock(EntityManagerInterface::class),
            $settings ?? new Settings(),
            $urls,
            $scanner,
            new FindingIngestor($this->store, new Classifier([], static fn(string $name): bool => false)),
            $this->alerts,
            'e.com',
            static fn(): ?int => $lastRuntime,
            static fn(): int => 1000000,
        );
    }

    private static function cookie(string $name): Finding
    {
        return new Finding('cookie', $name, 'e.com', 'https://e.com/');
    }

    public function testACompleteCrawlStoresFindingsCompletesTheRunAndAlerts(): void
    {
        $run = $this->runner(['https://e.com/'], new ScanResult([self::cookie('a')], 1, 0))->run();

        $this->assertSame(ScanRun::COMPLETE, $run->getStatus());
        $this->assertSame(ScanRun::CRAWL, $run->getSource());
        $this->assertSame(1000000, $run->getStartedAt());
        $this->assertSame(1000000, $run->getFinishedAt());
        $this->assertSame([1, 1], [$run->getPagesScanned(), $run->getNewCount()]);
        $this->assertNotNull($this->store->get('cookie', 'a', 'e.com'));
        $this->assertCount(1, $this->alerts->sent);
        $this->assertFalse($this->alerts->sent[0][2]);
    }

    public function testTheScannerIsToldTheSiteHostAndTheSelectedPages(): void
    {
        $settings = (new Settings())->setScanMaxPages(2)->setScanExcludesText('/private');
        $runner = $this->runner(['https://e.com/a', 'https://e.com/private', 'https://e.com/b', 'https://e.com/c'], new ScanResult([], 2, 0), $settings);

        $runner->run();

        $reflection = new \ReflectionProperty($runner, 'scanner');
        $context = $reflection->getValue($runner)->context;

        $this->assertSame('e.com', $context->siteHost);
        $this->assertSame(['https://e.com/a', 'https://e.com/b'], $context->urls);
    }

    public function testACompleteUntruncatedUnexcludedCrawlMarksVanishedFindingsGone(): void
    {
        $this->store->add((new ScanFinding())->identify('cookie', 'old', 'e.com')->addSource('crawl')->setStatus(ScanFinding::UNCLASSIFIED));

        $run = $this->runner(['https://e.com/'], new ScanResult([], 1, 0))->run();

        $this->assertSame(ScanFinding::GONE, $this->found('cookie', 'old', 'e.com')->getStatus());
        $this->assertSame(1, $run->getChangedCount());
    }

    public function testAFailedPageMeansNothingIsMarkedGone(): void
    {
        $this->store->add((new ScanFinding())->identify('cookie', 'old', 'e.com')->addSource('crawl'));

        $this->runner(['https://e.com/', 'https://e.com/broken'], new ScanResult([], 1, 1))->run();

        $this->assertNotSame(ScanFinding::GONE, $this->found('cookie', 'old', 'e.com')->getStatus());
    }

    public function testATruncatedPageListMeansNothingIsMarkedGone(): void
    {
        $this->store->add((new ScanFinding())->identify('cookie', 'old', 'e.com')->addSource('crawl'));
        $settings = (new Settings())->setScanMaxPages(1);

        $this->runner(['https://e.com/a', 'https://e.com/b'], new ScanResult([], 1, 0), $settings)->run();

        $this->assertNotSame(ScanFinding::GONE, $this->found('cookie', 'old', 'e.com')->getStatus());
    }

    public function testExcludedPagesMeanNothingIsMarkedGone(): void
    {
        $this->store->add((new ScanFinding())->identify('cookie', 'old', 'e.com')->addSource('crawl'));
        $settings = (new Settings())->setScanExcludesText('/members');

        $this->runner(['https://e.com/a', 'https://e.com/members'], new ScanResult([], 1, 0), $settings)->run();

        $this->assertNotSame(ScanFinding::GONE, $this->found('cookie', 'old', 'e.com')->getStatus());
    }

    public function testNothingNewOrChangedSendsNoAlert(): void
    {
        $this->runner(['https://e.com/'], new ScanResult([], 1, 0))->run();

        $this->assertSame([], $this->alerts->sent);
    }

    public function testAStaleRuntimeScanIsPassedToTheAlert(): void
    {
        $stale = $this->runner(['https://e.com/'], new ScanResult([self::cookie('a')], 1, 0), null, null)->run();

        $this->assertTrue($this->alerts->sent[0][2]);
        $this->assertSame(ScanRun::COMPLETE, $stale->getStatus());
    }

    public function testAScannerThatThrowsFailsTheRunAndRethrows(): void
    {
        $runner = $this->runner(['https://e.com/'], new \RuntimeException('boom'));

        try {
            $runner->run();
            $this->fail('swallowed the failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->alerts->sent);
        $this->assertSame([], $this->store->findings);
    }
}
```

> `testTheScannerIsToldTheSiteHostAndTheSelectedPages` reads the private `scanner` property by reflection; keep the constructor property named `$scanner`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'StaleCheckTest|ScanRunsTest|AlertMessageTest|MailAlertsTest|PublishedPagesTest|CrawlRunnerTest'`
Expected: FAIL with "Class ... StaleCheck not found".

- [ ] **Step 3: Write `StaleCheck` and `ScanRuns`**

`src/Scan/StaleCheck.php`:

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * The runtime scan is manual, so the scheduled task flags it when it has not been done for a while.
 */
final class StaleCheck
{
    public static function isStale(?int $lastRuntimeScanAt, int $now, int $days): bool
    {
        return $lastRuntimeScanAt === null || $now - $lastRuntimeScanAt > $days * 86400;
    }
}
```

`src/Scan/ScanRuns.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lifecycle rules for scan runs. A scan-mode session lasts SESSION_SECONDS; after that its token no longer works, and
 * a run still marked `running` is closed the next time anything looks (no background job is needed).
 */
final class ScanRuns
{
    public const SESSION_SECONDS = 1800;
    private const GRACE_SECONDS = 60;

    public static function isExpired(ScanRun $run, int $now): bool
    {
        return $run->getStatus() === ScanRun::RUNNING && $now > $run->getStartedAt() + self::SESSION_SECONDS + self::GRACE_SECONDS;
    }

    public static function acceptsReports(?ScanRun $run, int $now): bool
    {
        return $run !== null
            && $run->getSource() === ScanRun::BEACON
            && $run->getStatus() === ScanRun::RUNNING
            && $now <= $run->getStartedAt() + self::SESSION_SECONDS;
    }

    public static function closeExpired(EntityManagerInterface $em, int $now): int
    {
        $closed = 0;

        foreach ($em->getRepository(ScanRun::class)->findBy(['source' => ScanRun::BEACON, 'status' => ScanRun::RUNNING]) as $run) {
            if (self::isExpired($run, $now)) {
                $run->finish(ScanRun::COMPLETE, $run->getStartedAt() + self::SESSION_SECONDS);
                $closed++;
            }
        }

        if ($closed > 0) {
            $em->flush();
        }

        return $closed;
    }

    public static function lastBeaconAt(EntityManagerInterface $em): ?int
    {
        $run = $em->getRepository(ScanRun::class)->findOneBy(['source' => ScanRun::BEACON], ['startedAt' => 'DESC']);

        return $run instanceof ScanRun ? ($run->getFinishedAt() ?? $run->getStartedAt()) : null;
    }

    /**
     * @return list<ScanRun>
     */
    public static function recent(EntityManagerInterface $em, int $limit): array
    {
        return array_values($em->getRepository(ScanRun::class)->findBy([], ['id' => 'DESC'], $limit));
    }
}
```

- [ ] **Step 4: Write the alert classes**

`src/Scan/AlertMessage.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Support\Output;

/**
 * The plain-text alert. Names and hosts came from the public internet, so every one is flattened to a single line
 * (no control characters, so nothing can add a header or a fake paragraph) and capped. There is no HTML part.
 */
final class AlertMessage
{
    private const MAX_LINE = 80;

    private static function line(string $value, int $max = self::MAX_LINE): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}\s]+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, $max, 'UTF-8');
    }

    /**
     * @return array{subject: string, body: string}
     */
    public static function build(string $siteHost, IngestSummary $summary, bool $runtimeStale, int $staleDays, string $findingsUrl): array
    {
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $siteHost) ?? '';
        $subject = 'Cookie scan: ' . $summary->new . ' new, ' . $summary->changed . ' changed on ' . $host;

        $lines = [
            'A scheduled scan of ' . $host . ' found changes.',
            '',
            $summary->new . ' new, ' . $summary->changed . ' changed.',
            $summary->unclassified . ' findings still need a category.',
        ];

        if ($summary->newItems !== []) {
            $lines[] = '';
            $lines[] = 'New findings:';

            foreach ($summary->newItems as $item) {
                $lines[] = '- ' . self::line($item['kind'], 16) . ': ' . self::line($item['name']) . ' (' . self::line($item['host']) . ')';
            }
        }

        if ($runtimeStale) {
            $lines[] = '';
            $lines[] = 'No runtime scan has run in the last ' . $staleDays . ' days, so cookies and storage set by scripts may be missing. Start scan mode in the dashboard and browse the site.';
        }

        $url = Output::safeUrl($findingsUrl);

        if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
            $lines[] = '';
            $lines[] = 'Review them: ' . self::line($url, 300);
        }

        return ['subject' => $subject, 'body' => implode("\n", $lines) . "\n"];
    }
}
```

`src/Scan/AlertSender.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;

interface AlertSender
{
    public function send(ScanRun $run, IngestSummary $summary, bool $runtimeStale): void;
}
```

`src/Scan/MailAlerts.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;

/**
 * Emails the alert to the configured address through Concrete's mail service. A failure is logged and swallowed:
 * a mail problem must never fail a scan.
 */
final class MailAlerts implements AlertSender
{
    /**
     * @param (\Closure(): object)|null $mail a factory for the mail service; defaults to Concrete's
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly string $siteHost,
        private readonly string $findingsUrl,
        private readonly ?\Closure $mail = null,
    ) {}

    public function send(ScanRun $run, IngestSummary $summary, bool $runtimeStale): void
    {
        $to = $this->settings->getScanAlertEmail();

        if ($to === '') {
            return;
        }

        try {
            $message = AlertMessage::build($this->siteHost, $summary, $runtimeStale, $this->settings->getScanStaleDays(), $this->findingsUrl);
            $mail = ($this->mail ?? static fn(): object => app('mail'))();

            $mail->to($to);
            $mail->setSubject($message['subject']);
            $mail->setBody($message['body']);
            $mail->sendMail();
        } catch (\Throwable $e) {
            ErrorLog::record($e);
        }
    }
}
```

- [ ] **Step 5: Write the page source, target and runner**

`src/Scan/UrlSource.php`:

```php
<?php

namespace CookieManagerKit\Scan;

interface UrlSource
{
    /**
     * @return list<string> absolute URLs on the site's own canonical host
     */
    public function all(): array;
}
```

`src/Scan/PublishedPages.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Support\Facade\Url;

/**
 * Concrete's published, non-system pages as absolute URLs. The host always comes from the configured base URL: the
 * URL Concrete resolves for a page can reflect the request's Host header, and the crawler must never be pointed at
 * a host a request chose.
 */
final class PublishedPages implements UrlSource
{
    private const LIMIT = 5000;

    public function __construct(private readonly string $baseUrl) {}

    public function all(): array
    {
        $list = new PageList();
        $list->setSiteTreeToAll();
        $list->ignorePermissions();
        $list->sortByCollectionIDAscending();

        $pagination = $list->getPagination();
        $pagination->setMaxPerPage(self::LIMIT);

        $urls = [];

        foreach ($pagination->getCurrentPageResults() as $page) {
            if (!$page instanceof Page || $page->isError()) {
                continue;
            }

            $url = self::rebase((string) Url::to($page), $this->baseUrl);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Keeps the path and query of $url and puts them on $baseUrl's scheme, host and port.
     */
    public static function rebase(string $url, string $baseUrl): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return null;
        }

        if ($parts !== [] && isset($parts['scheme']) && !isset($parts['host'])) {
            return null;
        }

        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;

        return $origin . (str_starts_with($path, '/') ? '' : '/') . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
```

`src/Scan/CrawlTarget.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use Concrete\Core\Http\Request;

/**
 * Which host the crawler is allowed to visit: the site's configured canonical URL. Without one, a web request's own
 * scheme and host stand in (a developer clicking "Run crawl now" on a local site); a command-line or scheduled run has
 * no request, so it needs the canonical URL.
 */
final class CrawlTarget
{
    /**
     * @throws UnsafeUrl
     */
    public static function baseUrl(): string
    {
        $site = app('site')->getSite();

        foreach ([(string) $site->getSiteCanonicalURL(), (string) app('config')->get('concrete.seo.canonical_url')] as $candidate) {
            if (trim($candidate) !== '') {
                return rtrim(trim($candidate), '/');
            }
        }

        if (PHP_SAPI !== 'cli') {
            return rtrim((string) app(Request::class)->getSchemeAndHttpHost(), '/');
        }

        throw new UnsafeUrl('Set the canonical URL in Dashboard > System & Settings > SEO & Statistics > URLs and Redirection so the scanner knows which host is yours.');
    }
}
```

`src/Scan/CrawlRunner.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One crawl, start to finish: pick the pages, scan them, ingest the findings, record the run, and alert. Findings are
 * only allowed to go `gone` when the crawl saw everything: every page fetched, none cut off by the cap, none excluded.
 */
final class CrawlRunner
{
    /**
     * @param \Closure(): ?int  $lastRuntimeScanAt when the last scan-mode run happened, or null
     * @param (\Closure(): int)|null $clock        defaults to time()
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly UrlSource $urls,
        private readonly Scanner $scanner,
        private readonly FindingIngestor $ingestor,
        private readonly AlertSender $alerts,
        private readonly string $siteHost,
        private readonly \Closure $lastRuntimeScanAt,
        private readonly ?\Closure $clock = null,
    ) {}

    private function now(): int
    {
        return ($this->clock ?? static fn(): int => time())();
    }

    /**
     * @throws \Throwable whatever the scan raised, after the run has been marked failed
     */
    public function run(): ScanRun
    {
        $run = (new ScanRun())->start(ScanRun::CRAWL, $this->now());
        $this->em->persist($run);
        $this->em->flush();

        try {
            $selected = UrlSelector::select($this->urls->all(), $this->settings->getScanExcludes(), $this->settings->getScanMaxPages());
            $result = $this->scanner->scan(new ScanContext($this->siteHost, $selected['urls']));
            $sawEverything = $result->isComplete() && !$selected['truncated'] && $selected['excluded'] === 0;
            $summary = $this->ingestor->ingest($result->findings, ScanRun::CRAWL, $this->now(), $sawEverything);
        } catch (\Throwable $e) {
            $run->finish(ScanRun::FAILED, $this->now());
            $this->em->flush();

            throw $e;
        }

        $run->setPagesScanned($result->pagesScanned)
            ->setCounts($summary->new, $summary->changed, $summary->unclassified)
            ->finish(ScanRun::COMPLETE, $this->now());
        $this->em->flush();

        if ($summary->new > 0 || $summary->changed > 0) {
            $stale = StaleCheck::isStale(($this->lastRuntimeScanAt)(), $this->now(), $this->settings->getScanStaleDays());

            $this->alerts->send($run, $summary, $stale);
        }

        return $run;
    }
}
```

`src/Scan/Services.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use GuzzleHttp\Client;
use Concrete\Core\Support\Facade\Url;
use CookieManagerKit\Entity\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the scanner's collaborators from Concrete's container. Everything with logic lives in the classes this
 * wires together; this class only chooses the real implementations.
 */
final class Services
{
    private static function em(): EntityManagerInterface
    {
        return app(EntityManagerInterface::class);
    }

    public static function classifier(): Classifier
    {
        return new Classifier((new ClassificationStore(self::em()))->entries());
    }

    public static function ingestor(): FindingIngestor
    {
        return new FindingIngestor(new DoctrineFindingStore(self::em()), self::classifier());
    }

    /**
     * @throws UnsafeUrl when the site's host cannot be scanned (not resolvable, no canonical URL for a scheduled run)
     */
    public static function crawlRunner(): CrawlRunner
    {
        $em = self::em();
        $settings = Settings::get();
        $baseUrl = CrawlTarget::baseUrl();
        $guard = UrlGuard::forSite($baseUrl);
        $siteHost = $guard->host();

        return new CrawlRunner(
            $em,
            $settings,
            new PublishedPages($baseUrl),
            new CrawlScanner(new GuzzleFetcher(new Client(), $guard)),
            self::ingestor(),
            new MailAlerts($settings, $siteHost, (string) Url::to('/dashboard/cookie_manager/findings')),
            $siteHost,
            static fn(): ?int => ScanRuns::lastBeaconAt($em),
        );
    }
}
```

- [ ] **Step 6: Verify the Concrete APIs this task assumes**

Run each and confirm it exists; fix the call in `PublishedPages`/`CrawlTarget` if one differs:

```bash
grep -n "function getSiteCanonicalURL" vendor/concrete5/core/src/Entity/Site/Site.php
grep -n "function getPagination\|function createPaginationObject" vendor/concrete5/core/src/Search/ItemList/Database/ItemList.php vendor/concrete5/core/src/Page/PageList.php
grep -n "function setMaxPerPage\|class Pagination" vendor/concrete5/core/src/Search/Pagination/Pagination.php
grep -n "function getResult(\$queryRow)" -A12 vendor/concrete5/core/src/Page/PageList.php
```

Expected: `getResult` returns a `Page` (so `getCurrentPageResults()` yields `Page` objects). If `getCurrentPageResults()` yields something else, adapt `PublishedPages::all()` to build pages with `Page::getByID`.

- [ ] **Step 7: Run the tests**

Run: `composer test -- --filter 'StaleCheckTest|ScanRunsTest|AlertMessageTest|MailAlertsTest|PublishedPagesTest|CrawlRunnerTest'`
Expected: PASS. (`testTheSiteHostInTheSubjectIsReducedToHostCharacters` expects `bexample.comb`: `<`, `>`, `/` and the space are removed, the letters and the dot stay.)

- [ ] **Step 8: Commit**

```bash
composer format:php && composer typecheck
git add src/Scan tests/Scan
git commit -m "feat: orchestrate crawls with alerts, run lifecycle and stale-runtime rule

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Scan mode: token, report schema, endpoints

**Files:**
- Create: `src/Scan/ScanToken.php`, `src/Scan/BeaconReport.php`, `src/Scan/BeaconScanner.php`, `src/Ajax/ScanReport.php`, `src/Ajax/ScanBeacon.php`
- Modify: `src/Scan/ScanContext.php`, `controller.php` (routes)
- Test: `tests/Scan/ScanTokenTest.php`, `tests/Scan/BeaconReportTest.php`, `tests/Scan/BeaconScannerTest.php`

**Interfaces:**
- Consumes: `ScanRuns::SESSION_SECONDS`, `ScanRuns::acceptsReports`, `Clean::name`, `Host::normalise`, `Host::sameSite`, `Finding`, `RequestGuard::isSameOrigin`, `RateLimit::allow`, `Output::json`, `ErrorLog::record`, `Services::ingestor`.
- Produces:
  - `ScanToken::COOKIE` (`cmk_scan_token`), `ScanToken::MARKER` (`cmk_scan`), `ScanToken::LIFETIME` (1800); `issue(int $runId, int $now, string $secret): string`; `verify(mixed $token, int $now, string $secret): ?array{run: int, expires: int}`; `secret(): string`
  - `BeaconReport::__construct(public string $path, public array $cookies, public array $storage, public array $hosts)`; `BeaconReport::parse(?string $contentType, string $body): ?BeaconReport`; `MAX_BYTES` 32768, `MAX_ITEMS` 100
  - `ScanContext` gains `public ?BeaconReport $report = null` as its third constructor argument
  - `BeaconScanner::scan(ScanContext $context): ScanResult`

- [ ] **Step 1: Write the failing tests**

`tests/Scan/ScanTokenTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\ScanToken;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScanTokenTest extends TestCase
{
    private const SECRET = 'server-side-pepper';

    public function testAFreshTokenVerifiesAndCarriesTheRunAndExpiry(): void
    {
        $token = ScanToken::issue(42, 1700000000, self::SECRET);

        $this->assertSame(['run' => 42, 'expires' => 1700000000 + ScanToken::LIFETIME], ScanToken::verify($token, 1700000001, self::SECRET));
    }

    public function testItExpires(): void
    {
        $token = ScanToken::issue(42, 1700000000, self::SECRET);

        $this->assertNotNull(ScanToken::verify($token, 1700000000 + ScanToken::LIFETIME - 1, self::SECRET));
        $this->assertNull(ScanToken::verify($token, 1700000000 + ScanToken::LIFETIME, self::SECRET));
        $this->assertNull(ScanToken::verify($token, 1700000000 + ScanToken::LIFETIME + 1, self::SECRET));
    }

    public function testAnotherSecretOrATamperedPartIsRejected(): void
    {
        $token = ScanToken::issue(42, 1700000000, self::SECRET);
        [$run, $expires, $signature] = explode('.', $token);

        $this->assertNull(ScanToken::verify($token, 1700000001, 'another-secret'));
        $this->assertNull(ScanToken::verify('43.' . $expires . '.' . $signature, 1700000001, self::SECRET));
        $this->assertNull(ScanToken::verify($run . '.' . ((int) $expires + 9999) . '.' . $signature, 1700000001, self::SECRET));
        $this->assertNull(ScanToken::verify($run . '.' . $expires . '.' . str_repeat('0', 64), 1700000001, self::SECRET));
    }

    public function testAnEmptySecretNeverVerifiesAnything(): void
    {
        $token = ScanToken::issue(1, 1700000000, '');

        $this->assertNull(ScanToken::verify($token, 1700000001, ''));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformed(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'array' => [['1.2.3']],
            'two parts' => ['1.1700000000'],
            'four parts' => ['1.1700000000.' . '0' . '.x'],
            'letters in run' => ['a.1700000000.' . '0'],
            'short signature' => ['1.1700000000.abcd'],
            'upper case signature' => ['1.1700000000.' . 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789'],
            'trailing newline' => ["1.1700000000." . str_repeat('a', 64) . "\n"],
            'huge run' => ['99999999999.1700000000.' . 'a'],
        ];
    }

    #[DataProvider('malformed')]
    public function testMalformedTokensAreRejected(mixed $token): void
    {
        $this->assertNull(ScanToken::verify($token, 1700000001, self::SECRET));
    }
}
```

`tests/Scan/BeaconReportTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\BeaconReport;
use PHPUnit\Framework\Attributes\DataProvider;

final class BeaconReportTest extends TestCase
{
    private static function body(array $overrides = []): string
    {
        return json_encode($overrides + ['c' => ['_ga', 'sid'], 's' => ['cart'], 'h' => ['cdn.example.net'], 'p' => '/about'], JSON_THROW_ON_ERROR);
    }

    public function testParsesAValidReport(): void
    {
        $report = BeaconReport::parse('application/json', self::body());

        $this->assertNotNull($report);
        $this->assertSame('/about', $report->path);
        $this->assertSame(['_ga', 'sid'], $report->cookies);
        $this->assertSame(['cart'], $report->storage);
        $this->assertSame(['cdn.example.net'], $report->hosts);
    }

    public function testTheContentTypeMayCarryACharset(): void
    {
        $this->assertNotNull(BeaconReport::parse('application/json; charset=utf-8', self::body()));
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function rejected(): array
    {
        $ok = ['c' => [], 's' => [], 'h' => [], 'p' => '/'];

        return [
            'no content type' => [null, json_encode($ok)],
            'form content type' => ['application/x-www-form-urlencoded', json_encode($ok)],
            'text/plain' => ['text/plain', json_encode($ok)],
            'empty body' => ['application/json', ''],
            'not json' => ['application/json', '{not json'],
            'a list' => ['application/json', '[]'],
            'a scalar' => ['application/json', '"x"'],
            'missing key' => ['application/json', json_encode(['c' => [], 's' => [], 'p' => '/'])],
            'extra key' => ['application/json', json_encode($ok + ['x' => 1])],
            'values key' => ['application/json', json_encode($ok + ['v' => ['a' => 'SECRET']])],
            'cookies not a list' => ['application/json', json_encode(['c' => 'x'] + $ok)],
            'cookies an object' => ['application/json', json_encode(['c' => ['a' => 'b']] + $ok)],
            'non-string cookie' => ['application/json', json_encode(['c' => [1]] + $ok)],
            'nested cookie' => ['application/json', json_encode(['c' => [['a']]] + $ok)],
            'non-string host' => ['application/json', json_encode(['h' => [null]] + $ok)],
            'path not a string' => ['application/json', json_encode(['p' => 5] + $ok)],
            'path without slash' => ['application/json', json_encode(['p' => 'about'] + $ok)],
            'protocol relative path' => ['application/json', json_encode(['p' => '//evil.example.net/x'] + $ok)],
            'full url as path' => ['application/json', json_encode(['p' => 'https://evil.example.net/'] + $ok)],
            'path with a newline' => ['application/json', json_encode(['p' => "/a\nb"] + $ok)],
            'overlong path' => ['application/json', json_encode(['p' => '/' . str_repeat('a', 600)] + $ok)],
            'too many cookies' => ['application/json', json_encode(['c' => array_map(static fn(int $i): string => 'c' . $i, range(1, 101))] + $ok)],
            'too deeply nested' => ['application/json', '{"c":[],"s":[],"h":[],"p":"/","z":{"a":{"b":{"c":{}}}}}'],
        ];
    }

    #[DataProvider('rejected')]
    public function testStructuralViolationsRejectTheWholeReport(?string $contentType, string $body): void
    {
        $this->assertNull(BeaconReport::parse($contentType, $body));
    }

    public function testAnOversizeBodyIsRejected(): void
    {
        $padding = str_repeat('a', BeaconReport::MAX_BYTES);

        $this->assertNull(BeaconReport::parse('application/json', self::body(['p' => '/' . $padding])));
    }

    public function testExactlyOneHundredItemsIsAccepted(): void
    {
        $names = array_map(static fn(int $i): string => 'c' . $i, range(1, 100));

        $report = BeaconReport::parse('application/json', self::body(['c' => $names]));

        $this->assertNotNull($report);
        $this->assertCount(100, $report->cookies);
    }

    public function testInvalidItemsAreSkippedNotFatal(): void
    {
        $report = BeaconReport::parse('application/json', self::body([
            'c' => ['good', '', "\u{0000}\u{0001}", '   ', 'also_good'],
            'h' => ['cdn.example.net', 'not a host', '[::1]', '<script>.example.com', 'localhost', 'cdn.example.net', 'CDN.Example.net'],
        ]));

        $this->assertNotNull($report);
        $this->assertSame(['good', 'also_good'], $report->cookies);
        $this->assertSame(['cdn.example.net'], $report->hosts);
    }

    public function testHostileNamesAreKeptVerbatimAndCapped(): void
    {
        $report = BeaconReport::parse('application/json', self::body(['c' => ['"><img src=x onerror=alert(1)>', str_repeat('n', 5000)]]));

        $this->assertNotNull($report);
        $this->assertSame('"><img src=x onerror=alert(1)>', $report->cookies[0]);
        $this->assertSame(128, strlen($report->cookies[1]));
    }

    public function testDuplicatesAreRemoved(): void
    {
        $report = BeaconReport::parse('application/json', self::body(['c' => ['a', 'a', 'b'], 's' => ['k', 'k']]));

        $this->assertNotNull($report);
        $this->assertSame(['a', 'b'], $report->cookies);
        $this->assertSame(['k'], $report->storage);
    }
}
```

`tests/Scan/BeaconScannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\ScanContext;
use CookieManagerKit\Scan\BeaconReport;
use CookieManagerKit\Scan\BeaconScanner;

final class BeaconScannerTest extends TestCase
{
    /**
     * @param list<Finding> $found
     *
     * @return list<string>
     */
    private static function summary(array $found): array
    {
        $rows = array_map(static fn(Finding $f): string => $f->kind . ' ' . $f->name . ' ' . $f->host, $found);
        sort($rows);

        return $rows;
    }

    public function testTurnsAReportIntoCookieStorageAndHostFindings(): void
    {
        $report = new BeaconReport('/about', ['_ga'], ['cart'], ['cdn.example.net', 'www.example.com', 'example.com']);

        $result = (new BeaconScanner())->scan(new ScanContext('example.com', [], $report));

        $this->assertSame([
            'cookie _ga example.com',
            'resource cdn.example.net cdn.example.net',
            'storage cart example.com',
        ], self::summary($result->findings));
        $this->assertSame('/about', $result->findings[0]->page);
        $this->assertSame([1, 0], [$result->pagesScanned, $result->pagesFailed]);
    }

    public function testWithoutAReportThereIsNothing(): void
    {
        $result = (new BeaconScanner())->scan(new ScanContext('example.com'));

        $this->assertSame([], $result->findings);
        $this->assertSame([0, 0], [$result->pagesScanned, $result->pagesFailed]);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'ScanTokenTest|BeaconReportTest|BeaconScannerTest'`
Expected: FAIL with "Class ... ScanToken not found".

- [ ] **Step 3: Write `src/Scan/ScanToken.php`**

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * The scan-mode credential: `<runId>.<expiresAt>.<hmac>`. It only lets a browser that holds it post scan reports for
 * that one run until it expires. The HMAC is compared in constant time, and nothing is accepted when there is no
 * server-side secret to sign with.
 */
final class ScanToken
{
    public const COOKIE = 'cmk_scan_token';
    public const MARKER = 'cmk_scan';
    public const LIFETIME = ScanRuns::SESSION_SECONDS;

    public static function issue(int $runId, int $now, string $secret): string
    {
        $payload = $runId . '.' . ($now + self::LIFETIME);

        return $payload . '.' . self::sign($payload, $secret);
    }

    /**
     * @return array{run: int, expires: int}|null
     */
    public static function verify(mixed $token, int $now, string $secret): ?array
    {
        if (!is_string($token) || $secret === '' || preg_match('/^(\d{1,9})\.(\d{10})\.([a-f0-9]{64})\z/', $token, $match) !== 1) {
            return null;
        }

        $payload = $match[1] . '.' . $match[2];

        if (!hash_equals(self::sign($payload, $secret), $match[3]) || (int) $match[2] <= $now) {
            return null;
        }

        return ['run' => (int) $match[1], 'expires' => (int) $match[2]];
    }

    /**
     * The per-installation pepper Concrete uses for its own tokens; empty on a site that has none, which disables scan mode.
     */
    public static function secret(): string
    {
        return (string) app('config/database')->get('concrete.security.token.validation');
    }

    private static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', 'cmk-scan|' . $payload, $secret);
    }
}
```

> `testAnEmptySecretNeverVerifiesAnything` signs with `''` (HMAC accepts it) and verification refuses because `$secret === ''`. `testMalformedTokens` case `'huge run'` has an 11-digit run and a one-character signature; both fail the regex.

- [ ] **Step 4: Write `src/Scan/BeaconReport.php`**

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * Reads and vets the body the scan-mode beacon posts. Pure: no Concrete, no database. Same rule as StatsEvent:
 * structural violations (type, keys, size, counts) reject the whole body; individual names and hosts that are not
 * usable are skipped, because one odd entry should not lose a whole page's worth of findings.
 */
final readonly class BeaconReport
{
    public const MAX_BYTES = 32768;
    public const MAX_ITEMS = 100;

    /**
     * @param list<string> $cookies
     * @param list<string> $storage
     * @param list<string> $hosts
     */
    public function __construct(
        public string $path,
        public array $cookies,
        public array $storage,
        public array $hosts,
    ) {}

    public static function parse(?string $contentType, string $body): ?self
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

        if ($keys !== ['c', 'h', 'p', 's']) {
            return null;
        }

        $path = $data['p'];

        if (!is_string($path) || ($path !== '/' && preg_match('#^/[^/\x00-\x1F\x7F][^\x00-\x1F\x7F]{0,510}\z#', $path) !== 1)) {
            return null;
        }

        $cookies = self::strings($data['c']);
        $storage = self::strings($data['s']);
        $hosts = self::strings($data['h']);

        if ($cookies === null || $storage === null || $hosts === null) {
            return null;
        }

        return new self(
            $path,
            self::unique(array_map(static fn(string $name): ?string => Clean::name($name), $cookies)),
            self::unique(array_map(static fn(string $name): ?string => Clean::name($name), $storage)),
            self::unique(array_map(static fn(string $host): ?string => Host::normalise($host), $hosts)),
        );
    }

    /**
     * @return list<string>|null null when the value is not a list of at most MAX_ITEMS strings
     */
    private static function strings(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ITEMS) {
            return null;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }
        }

        return $value;
    }

    /**
     * @param list<string|null> $items
     *
     * @return list<string>
     */
    private static function unique(array $items): array
    {
        return array_values(array_unique(array_filter($items, static fn(?string $item): bool => $item !== null)));
    }
}
```

- [ ] **Step 5: Add `report` to `ScanContext` and write `BeaconScanner`**

Replace `src/Scan/ScanContext.php`:

```php
<?php

namespace CookieManagerKit\Scan;

/**
 * What a scanner is told: the site's own host and, for a crawl, the pages to fetch or, for the beacon, the report.
 */
final readonly class ScanContext
{
    /**
     * @param list<string> $urls
     */
    public function __construct(
        public string $siteHost,
        public array $urls = [],
        public ?BeaconReport $report = null,
    ) {}
}
```

`src/Scan/BeaconScanner.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;

/**
 * Turns one beacon report into findings. Cookies and storage keys belong to the host the page was served from (the
 * server knows that; the browser is not asked); resource hosts are third-party loads, and the site's own host is skipped.
 */
final class BeaconScanner implements Scanner
{
    public function scan(ScanContext $context): ScanResult
    {
        $report = $context->report;

        if ($report === null) {
            return new ScanResult([], 0, 0);
        }

        $found = [];

        foreach ($report->cookies as $name) {
            $found[] = new Finding(ScanFinding::COOKIE, $name, $context->siteHost, $report->path);
        }

        foreach ($report->storage as $name) {
            $found[] = new Finding(ScanFinding::STORAGE, $name, $context->siteHost, $report->path);
        }

        foreach ($report->hosts as $host) {
            if (!Host::sameSite($host, $context->siteHost)) {
                $found[] = new Finding(ScanFinding::RESOURCE, $host, $host, $report->path);
            }
        }

        return new ScanResult($found, 1, 0);
    }
}
```

- [ ] **Step 6: Write the two public endpoints**

`src/Ajax/ScanReport.php`:

```php
<?php

namespace CookieManagerKit\Ajax;

use CookieManagerKit\Scan\Host;
use CookieManagerKit\Scan\ScanRuns;
use CookieManagerKit\Scan\Services;
use CookieManagerKit\Scan\ScanToken;
use CookieManagerKit\Entity\ScanRun;
use Concrete\Core\Http\Request;
use CookieManagerKit\Scan\ScanContext;
use CookieManagerKit\Scan\BeaconReport;
use CookieManagerKit\Scan\BeaconScanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\DBAL\Exception\TableNotFoundException;
use CookieManagerKit\Support\{ErrorLog, Output, RateLimit, RequestGuard};

/**
 * Receives what the scan-mode beacon saw on a page. Public (pages are cached and anonymous), so it is defended like
 * the consent endpoint: same-origin only, JSON only, a strict schema and size cap, a per-run rate limit, and it
 * additionally needs the signed scan-mode token, which only an admin who started scan mode holds. It stores names and
 * hosts only, answers with a fixed body, and never reflects anything from the request.
 */
class ScanReport
{
    private const PER_MINUTE = 120;

    private function status(Request $request): int
    {
        if (!$request->isMethod('POST')) {
            return 405;
        }

        if (!RequestGuard::isSameOrigin($request->headers->get('Origin'), $request->headers->get('Referer'), $request->getHttpHost())) {
            return 403;
        }

        $now = time();
        $token = ScanToken::verify($request->cookies->get(ScanToken::COOKIE), $now, ScanToken::secret());

        if ($token === null) {
            return 401;
        }

        if (!RateLimit::allow('scan/' . $token['run'], self::PER_MINUTE)) {
            return 429;
        }

        $report = BeaconReport::parse($request->headers->get('Content-Type'), (string) $request->getContent());
        $host = Host::normalise($request->getHost());

        if ($report === null || $host === null) {
            return 400;
        }

        $em = app(EntityManagerInterface::class);
        $run = $em->getRepository(ScanRun::class)->find($token['run']);

        // A token for a session the admin ended (or that ran out) must not keep feeding findings.
        if (!$run instanceof ScanRun || !ScanRuns::acceptsReports($run, $now)) {
            return 401;
        }

        $result = (new BeaconScanner())->scan(new ScanContext($host, [], $report));
        $summary = Services::ingestor()->ingest($result->findings, ScanRun::BEACON, $now, false);

        $run->setPagesScanned($run->getPagesScanned() + 1)
            ->setCounts($run->getNewCount() + $summary->new, $run->getChangedCount() + $summary->changed, $summary->unclassified);
        $em->flush();

        return 200;
    }

    public function record(): Response
    {
        try {
            $status = $this->status(app(Request::class));
        } catch (TableNotFoundException) {
            // Before the package Update has created the tables there is nothing to record into.
            $status = 400;
        } catch (\Throwable $e) {
            $status = 500;
            ErrorLog::record($e);
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

`src/Ajax/ScanBeacon.php`:

```php
<?php

namespace CookieManagerKit\Ajax;

use Concrete\Core\Http\Request;
use CookieManagerKit\Scan\ScanToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the beacon script, but only to a browser that holds a valid scan-mode token. Everyone else gets an empty
 * 204, so the page HTML (which never mentions scan mode) stays identical for every visitor and cacheable.
 */
class ScanBeacon
{
    public function script(): Response
    {
        $valid = false;

        try {
            $request = app(Request::class);
            $valid = $request->isMethod('GET') || $request->isMethod('HEAD')
                ? ScanToken::verify($request->cookies->get(ScanToken::COOKIE), time(), ScanToken::secret()) !== null
                : false;
        } catch (\Throwable) {
        }

        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'];

        if (!$valid) {
            return new Response('', 204, $headers);
        }

        $source = (string) @file_get_contents(dirname(__DIR__, 2) . '/js/cmk-beacon.js');

        return new Response($source, 200, $headers + ['Content-Type' => 'application/javascript; charset=utf-8']);
    }
}
```

- [ ] **Step 7: Register the routes**

In `controller.php`, add at the end of `registerRoutes()` (after the `/cookie-manager/event` route):

```php
        // Public, but only useful with the signed scan-mode token an admin gets from the dashboard. See Ajax\ScanReport.
        Route::register(
            '/cookie-manager/scan/report',
            '\CookieManagerKit\Ajax\ScanReport::record',
            'cookie_manager_kit.scan_report',
            [],
            [],
            '',
            [],
            ['POST'],
        );

        // Empty for everyone without the scan-mode token. See Ajax\ScanBeacon.
        Route::register(
            '/cookie-manager/scan/beacon.js',
            '\CookieManagerKit\Ajax\ScanBeacon::script',
            'cookie_manager_kit.scan_beacon',
            [],
            [],
            '',
            [],
            ['GET', 'HEAD'],
        );
```

- [ ] **Step 8: Run the tests**

Run: `composer test`
Expected: PASS (including `SourceLayoutTest`, which checks the new `src/Ajax` files declare classes).

- [ ] **Step 9: Commit**

```bash
composer format:php && composer typecheck
git add src controller.php tests/Scan
git commit -m "feat: add scan-mode token, report schema and the beacon endpoints

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 12: The browser beacon and its hook in the runtime

**Files:**
- Create: `js/cmk-beacon.js`, `tests/js/beacon.test.js`
- Modify: `js/cmk-runtime.js`, `src/Support/LoaderScript.php`, `src/Events/ConsentManager.php`, `tests/Support/LoaderScriptTest.php`, `tests/js/runtime.test.js`

**Interfaces:**
- Consumes: the `cmk_scan=1` marker cookie, `window.cmkBoot.scan` (a same-origin path), `POST /cookie-manager/scan/report` (relative `report` next to `beacon.js`).
- Produces:
  - `js/cmk-beacon.js`: self-running; does nothing without the marker cookie; posts `{c, s, h, p}` (names, keys, hosts, path) once about 2.5 s after load and again on `pagehide` if anything changed.
  - `window.cmkRuntime.startBeacon()`: appends a `<script src=boot.scan>` when the marker cookie is present and `boot.scan` is a same-origin path; called automatically when the runtime is not in `manual` mode.
  - `LoaderScript::boot(int $version, array $categories, int $lifetimeDays, string $configUrl, string $postUrl, ?string $nonce, string $eventUrl = '', string $scanUrl = '')` now returns the key `scan` between `event` and `nonce`.

- [ ] **Step 1: Write the failing beacon tests**

`tests/js/beacon.test.js`:

```js
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadScript } from './helpers.js';

function clearCookies() {
	document.cookie.split(';').forEach((cookie) => {
		const name = cookie.split('=')[0].trim();
		if (name) document.cookie = name + '=; Max-Age=0; Path=/';
	});
}

function scanMode(extra = []) {
	document.cookie = 'cmk_scan=1; Path=/';
	extra.forEach((cookie) => (document.cookie = cookie + '; Path=/'));
}

function resources(names) {
	vi.spyOn(window.performance, 'getEntriesByType').mockReturnValue(names.map((name) => ({ name })));
}

const sent = () => JSON.parse(window.fetch.mock.calls[0][1].body);

beforeEach(() => {
	vi.useFakeTimers();
	clearCookies();
	localStorage.clear();
	sessionStorage.clear();
	window.fetch = vi.fn(() => Promise.resolve({ status: 200 }));
});

afterEach(() => {
	vi.useRealTimers();
	vi.restoreAllMocks();
	clearCookies();
	localStorage.clear();
	sessionStorage.clear();
});

describe('the scan-mode beacon', () => {
	it('does nothing without the scan-mode marker cookie', () => {
		document.cookie = '_ga=1; Path=/';
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(5000);

		expect(window.fetch).not.toHaveBeenCalled();
	});

	it('reports names and hosts only, never values', () => {
		scanMode(['_ga=SECRET-COOKIE-VALUE']);
		localStorage.setItem('cart', 'SECRET-LOCAL-VALUE');
		sessionStorage.setItem('draft', 'SECRET-SESSION-VALUE');
		resources(['https://cdn.example.net/a.js?token=SECRET-QUERY', window.location.origin + '/own.js', 'not a url']);

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const body = window.fetch.mock.calls[0][1].body;
		const report = JSON.parse(body);

		expect(report.c).toEqual(expect.arrayContaining(['_ga', 'cmk_scan']));
		expect(report.s).toEqual(expect.arrayContaining(['cart', 'draft']));
		expect(report.h).toEqual(['cdn.example.net']);
		expect(report.p).toBe('/');
		expect(Object.keys(report).sort()).toEqual(['c', 'h', 'p', 's']);
		expect(body).not.toContain('SECRET');
	});

	it('posts JSON to the report endpoint next to the script, with the browser credentials', () => {
		scanMode();
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const [url, options] = window.fetch.mock.calls[0];

		expect(new URL(url).pathname.endsWith('/report')).toBe(true);
		expect(options.method).toBe('POST');
		expect(options.credentials).toBe('same-origin');
		expect(options.headers['Content-Type']).toBe('application/json');
	});

	it('waits for the page to settle before reporting', () => {
		scanMode();
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(1000);

		expect(window.fetch).not.toHaveBeenCalled();
	});

	it('caps each list at 100 items and each name at 128 characters', () => {
		scanMode();

		for (let i = 0; i < 150; i++) localStorage.setItem('key' + i, 'v');
		localStorage.setItem('x'.repeat(500), 'v');

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const report = sent();

		expect(report.s.length).toBeLessThanOrEqual(100);
		report.s.forEach((name) => expect(name.length).toBeLessThanOrEqual(128));
	});

	it('removes duplicates', () => {
		scanMode();
		localStorage.setItem('same', '1');
		sessionStorage.setItem('same', '2');
		resources(['https://cdn.example.net/a.js', 'https://cdn.example.net/b.js']);

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const report = sent();

		expect(report.s.filter((name) => name === 'same')).toHaveLength(1);
		expect(report.h).toEqual(['cdn.example.net']);
	});

	it('sends hostile names as plain strings and never touches the page', () => {
		scanMode();
		localStorage.setItem('"><img src=x onerror=alert(1)>', 'v');
		document.body.innerHTML = '<p id="keep">unchanged</p>';

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		expect(sent().s).toContain('"><img src=x onerror=alert(1)>');
		expect(document.body.innerHTML).toBe('<p id="keep">unchanged</p>');
	});

	it('stops by clearing the marker when the server refuses the token', async () => {
		scanMode();
		window.fetch = vi.fn(() => Promise.resolve({ status: 401 }));

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);
		await Promise.resolve();
		await Promise.resolve();

		expect(document.cookie).not.toContain('cmk_scan=1');
	});

	it('survives storage that throws', () => {
		scanMode();
		vi.spyOn(Storage.prototype, 'key').mockImplementation(() => {
			throw new Error('blocked');
		});
		localStorage.setItem('a', '1');

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		expect(sent().c).toContain('cmk_scan');
	});
});
```

Add to `tests/js/runtime.test.js`, after the existing `describe` blocks (end of file):

```js
describe('starting the scan-mode beacon', () => {
	const SCAN_BOOT = { ...BOOT, scan: '/cookie-manager/scan/beacon.js' };

	const beaconScripts = () => [...document.querySelectorAll('script[src*="beacon.js"]')];

	afterEach(() => {
		document.cookie = 'cmk_scan=; Path=/; Max-Age=0';
		document.head.querySelectorAll('script[src*="beacon.js"]').forEach((node) => node.remove());
	});

	it('adds the beacon script when scan mode is on', () => {
		document.cookie = 'cmk_scan=1; Path=/';
		mount(config(), SCAN_BOOT);
		window.cmkRuntime.startBeacon();

		expect(beaconScripts()).toHaveLength(1);
		expect(beaconScripts()[0].getAttribute('src')).toBe('/cookie-manager/scan/beacon.js');
	});

	it('adds nothing for ordinary visitors', () => {
		mount(config(), SCAN_BOOT);
		window.cmkRuntime.startBeacon();

		expect(beaconScripts()).toHaveLength(0);
	});

	it('adds nothing when the server gave no beacon URL', () => {
		document.cookie = 'cmk_scan=1; Path=/';
		mount(config(), BOOT);
		window.cmkRuntime.startBeacon();

		expect(beaconScripts()).toHaveLength(0);
	});

	it.each(['https://evil.example.net/x.js', '//evil.example.net/x.js', 'javascript:alert(1)', 'beacon.js'])('refuses a beacon URL that is not a same-origin path: %s', (url) => {
		document.cookie = 'cmk_scan=1; Path=/';
		mount(config(), { ...BOOT, scan: url });
		window.cmkRuntime.startBeacon();

		expect(document.querySelectorAll('script[src]')).toHaveLength(0);
	});

	it('adds the script only once', () => {
		document.cookie = 'cmk_scan=1; Path=/';
		mount(config(), SCAN_BOOT);
		window.cmkRuntime.startBeacon();
		window.cmkRuntime.startBeacon();

		expect(beaconScripts()).toHaveLength(1);
	});
});
```

- [ ] **Step 2: Run the JS tests to verify they fail**

Run: `npx vitest run tests/js/beacon.test.js tests/js/runtime.test.js`
Expected: FAIL (`cmk-beacon.js` missing; `startBeacon` is not a function).

- [ ] **Step 3: Write `js/cmk-beacon.js`**

```js
/*
 * Cookie Manager scan-mode beacon. The server sends this file only to a browser that holds a valid scan-mode token
 * (an admin who started scan mode in the dashboard), and the runtime loads it only while the marker cookie is set.
 * It reports cookie NAMES, storage KEYS and the HOSTS of loaded resources. It never reads a cookie or storage value
 * and never changes the page.
 */
(function (w, d) {
	'use strict';

	var MARKER = 'cmk_scan=1';
	var MAX_ITEMS = 100;
	var MAX_NAME = 128;
	var SETTLE_MS = 2500;

	if (d.cookie.indexOf(MARKER) === -1) {
		return;
	}

	var endpoint;

	try {
		var current = d.currentScript;
		endpoint = new URL('report', current && current.src ? current.src : w.location.href).href;
	} catch (error) {
		return;
	}

	var lastSent = '';

	function unique(list) {
		return list.filter(function (item, index) {
			return item !== '' && list.indexOf(item) === index;
		});
	}

	function cap(list) {
		return unique(list)
			.slice(0, MAX_ITEMS)
			.map(function (name) {
				return String(name).slice(0, MAX_NAME);
			});
	}

	function cookieNames() {
		return d.cookie.split(';').map(function (part) {
			return part.split('=')[0].trim();
		});
	}

	function storageKeys(store) {
		var keys = [];

		try {
			for (var i = 0; i < store.length; i++) {
				keys.push(store.key(i));
			}
		} catch (error) {
			/* blocked or unavailable storage simply reports nothing */
		}

		return keys.filter(function (key) {
			return typeof key === 'string';
		});
	}

	function resourceHosts() {
		var hosts = [];

		try {
			(w.performance.getEntriesByType('resource') || []).forEach(function (entry) {
				try {
					var host = new URL(entry.name).hostname;

					if (host && host !== w.location.hostname) {
						hosts.push(host);
					}
				} catch (error) {
					/* a name that is not a URL is not a host */
				}
			});
		} catch (error) {
			/* no Performance API */
		}

		return hosts;
	}

	function stop() {
		d.cookie = 'cmk_scan=; Max-Age=0; Path=/; SameSite=Strict';
	}

	function report() {
		if (d.cookie.indexOf(MARKER) === -1 || typeof w.fetch !== 'function') {
			return;
		}

		var body = JSON.stringify({
			c: cap(cookieNames()),
			s: cap(storageKeys(w.localStorage).concat(storageKeys(w.sessionStorage))),
			h: cap(resourceHosts()),
			p: String(w.location.pathname || '/').slice(0, 500)
		});

		if (body === lastSent) {
			return;
		}

		lastSent = body;

		w.fetch(endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'Content-Type': 'application/json' },
			body: body
		})
			.then(function (response) {
				if (response && (response.status === 401 || response.status === 403)) {
					stop();
				}
			})
			.catch(function () {
				/* scanning is best effort and never affects the page */
			});
	}

	function later() {
		w.setTimeout(report, SETTLE_MS);
	}

	if (d.readyState === 'complete') {
		later();
	} else {
		w.addEventListener('load', later);
	}

	w.addEventListener('pagehide', report);
})(window, document);
```

- [ ] **Step 4: Add `startBeacon` to `js/cmk-runtime.js`**

Insert this function immediately before the line `w.cmkRuntime = runtime;`:

```js
	runtime.startBeacon = function () {
		var url = boot.scan;

		// Only a same-origin path is ever loaded; the marker cookie exists only in an admin's browser in scan mode.
		if (
			typeof url !== 'string' ||
			!/^\/(?!\/)/.test(url) ||
			d.cookie.indexOf('cmk_scan=1') === -1 ||
			d.querySelector('script[data-cmk-beacon]')
		) {
			return;
		}

		var script = d.createElement('script');

		script.src = url;
		script.async = true;
		script.setAttribute('data-cmk-beacon', '');
		(d.head || d.documentElement).appendChild(script);
	};
```

And change the final `ready` block so the beacon starts for every visitor-facing page load that is not in manual mode. Replace:

```js
	if (!boot.manual && boot.cfg && typeof w.fetch === 'function') {
		ready(function () {
```

with:

```js
	if (!boot.manual) {
		ready(runtime.startBeacon);
	}

	if (!boot.manual && boot.cfg && typeof w.fetch === 'function') {
		ready(function () {
```

- [ ] **Step 5: Add `scan` to the boot data**

Three exact edits in `src/Support/LoaderScript.php` (each old string occurs once):

1. In the docblock above `boot()`, replace
   `     * @return array{v: int, cats: list<string>, days: int, cfg: string, post: string, event: string, nonce: bool}`
   with
   `     * @return array{v: int, cats: list<string>, days: int, cfg: string, post: string, event: string, scan: string, nonce: bool}`
2. Replace the signature line
   `    public static function boot(int $version, array $categories, int $lifetimeDays, string $configUrl, string $postUrl, ?string $nonce, string $eventUrl = ''): array`
   with
   `    public static function boot(int $version, array $categories, int $lifetimeDays, string $configUrl, string $postUrl, ?string $nonce, string $eventUrl = '', string $scanUrl = ''): array`
3. Replace
   `            'event' => $eventUrl,\n            'nonce' => self::isValidNonce($nonce),`
   with
   `            'event' => $eventUrl,\n            'scan' => $scanUrl,\n            'nonce' => self::isValidNonce($nonce),`

In `src/Events/ConsentManager.php`, in the `LoaderScript::boot(...)` call inside `beforeRender()`, replace
```php
            $nonce,
            self::path('/cookie-manager/event'),
        );
```
with
```php
            $nonce,
            self::path('/cookie-manager/event'),
            self::path('/cookie-manager/scan/beacon.js'),
        );
```

In `tests/Support/LoaderScriptTest.php` replace the key-order assertion
`        $this->assertSame(['v', 'cats', 'days', 'cfg', 'post', 'event', 'nonce'], array_keys($boot));`
with
`        $this->assertSame(['v', 'cats', 'days', 'cfg', 'post', 'event', 'scan', 'nonce'], array_keys($boot));`
and add this test to the class:

```php
    public function testBootScanUrlDefaultsToEmptyAndIsEncoded(): void
    {
        $this->assertSame('', LoaderScript::boot(1, [], 1, '', '', null)['scan']);

        $json = Output::json(LoaderScript::boot(1, [], 1, '', '', null, '', '"><script>x</script>'));

        foreach (['<', '>', '&', "'"] as $char) {
            $this->assertStringNotContainsString($char, $json);
        }
    }
```

- [ ] **Step 6: Run everything**

Run: `composer test && npx vitest run`
Expected: PASS for both. `tests/js/sinks.test.js` automatically scans `cmk-beacon.js` for banned sinks and must stay green.

- [ ] **Step 7: Commit**

```bash
npx prettier --write js tests/js
composer format:php && composer typecheck
git add js tests src
git commit -m "feat: add the scan-mode beacon and start it from the runtime

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Review logic and the dashboard pages

**Files:**
- Create: `src/Scan/Review.php`
- Modify: `src/Scan/ScanRuns.php` (add `endRunning`), `controller.php` (`installOrUpgrade`)
- Create: `controllers/single_page/dashboard/cookie_manager/scanner.php`, `findings.php`, `scan_settings.php`; `single_pages/dashboard/cookie_manager/scanner.php`, `findings.php`, `scan_settings.php`
- Test: `tests/Scan/ReviewTest.php`, `tests/Scan/ScanRunsEndTest.php`, `tests/Dashboard/FindingsViewTest.php`, `tests/Dashboard/translate.php` (the existing `tests/ControllerSecurityTest.php` and `tests/ViewEscapingTest.php` pick the new controllers and views up on their own)

**Interfaces:**
- Consumes: `ScanFinding`, `Category`, `Service`, `Classifier`, `ClassificationStore::addOverride`, `Services::crawlRunner/classifier`, `ScanRuns::*`, `StaleCheck::isStale`, `ScanToken::*`, `Refresher`, `GithubSnapshot`, `Catalog::flush`, `SiteLocales::defaultLocale`, `Text::plain`, `ErrorLog::record`, `Output`.
- Produces:
  - `Review::approve(ScanFinding $finding, Category $category, ?Service $service, string $name, string $vendor): Service` (throws `\InvalidArgumentException` for a new service without a name)
  - `Review::approveMatched(list<ScanFinding> $findings, array<string, Category> $categories, list<Service> $services): list<Service>` (the services it created)
  - `Review::ignore(ScanFinding): void`, `Review::unignore(ScanFinding, Classifier): void`, `Review::reassign(ScanFinding, string $handle): void`, `Review::reclassify(ScanFinding, Classifier): void`
  - `Review::overrideRule(ScanFinding): ?array{kind: string, pattern: string}`
  - `Review::defaultCategory(?string $suggested, list<string> $handles): ?string`
  - `ScanRuns::endRunning(EntityManagerInterface $em, int $now): int`
  - Pages `/dashboard/cookie_manager/scanner`, `/findings`, `/scan_settings`; controller classes `Scanner`, `Findings`, `ScanSettings`.

- [ ] **Step 1: Write the failing `Review` tests**

`tests/Scan/ReviewTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Review;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Entity\Category;
use CookieManagerKit\Scan\Classifier;
use CookieManagerKit\Entity\ScanFinding;

final class ReviewTest extends TestCase
{
    private static function category(string $handle): Category
    {
        return (new Category())->setHandle($handle);
    }

    private static function finding(string $kind, string $name, string $host, string $status = ScanFinding::MATCHED): ScanFinding
    {
        return (new ScanFinding())->identify($kind, $name, $host)->setStatus($status);
    }

    public function testApproveAttachesToAnExistingServiceAndRecordsTheHost(): void
    {
        $analytics = self::category('analytics');
        $service = (new Service())->setName('GA')->setCategory($analytics)->setDomainsText('www.google-analytics.com');
        $finding = self::finding('script', 'cdn.example.net', 'cdn.example.net');

        $result = Review::approve($finding, $analytics, $service, '', '');

        $this->assertSame($service, $result);
        $this->assertSame(['www.google-analytics.com', 'cdn.example.net'], $service->getDomains());
        $this->assertSame(ScanFinding::APPROVED, $finding->getStatus());
        $this->assertSame($service, $finding->getService());
    }

    public function testApproveCreatesANewServiceInTheChosenCategory(): void
    {
        $marketing = self::category('marketing');
        $finding = self::finding('iframe', 'www.youtube.com', 'www.youtube.com');

        $service = Review::approve($finding, $marketing, null, 'YouTube', 'Google LLC');

        $this->assertSame(['YouTube', 'Google LLC', $marketing], [$service->getName(), $service->getVendor(), $service->getCategory()]);
        $this->assertSame(['www.youtube.com'], $service->getDomains());
        $this->assertSame($service, $finding->getService());
    }

    public function testANewServiceNeedsANameAndTheFindingIsLeftAlone(): void
    {
        $finding = self::finding('script', 'cdn.example.net', 'cdn.example.net');

        try {
            Review::approve($finding, self::category('analytics'), null, '   ', 'V');
            $this->fail('created a nameless service');
        } catch (\InvalidArgumentException) {
            $this->assertSame(ScanFinding::MATCHED, $finding->getStatus());
            $this->assertNull($finding->getService());
        }
    }

    public function testCookiesAndStorageKeysDoNotAddDomains(): void
    {
        $service = (new Service())->setName('GA')->setCategory(self::category('analytics'));

        Review::approve(self::finding('cookie', '_ga', 'example.com'), self::category('analytics'), $service, '', '');
        Review::approve(self::finding('storage', 'cart', 'example.com'), self::category('analytics'), $service, '', '');

        $this->assertSame([], $service->getDomains());
    }

    public function testAHostIsNotAddedTwiceAndAnIpAddressIsNotAddedAtAll(): void
    {
        $service = (new Service())->setName('X')->setCategory(self::category('analytics'))->setDomainsText('cdn.example.net');

        Review::approve(self::finding('script', 'cdn.example.net', 'cdn.example.net'), self::category('analytics'), $service, '', '');
        Review::approve(self::finding('script', '203.0.113.7', '203.0.113.7'), self::category('analytics'), $service, '', '');

        $this->assertSame(['cdn.example.net'], $service->getDomains());
    }

    public function testNamesAreReducedToPlainText(): void
    {
        $service = Review::approve(self::finding('script', 'a.example.net', 'a.example.net'), self::category('analytics'), null, '<script>x</script>Acme', '<b>Acme</b> Inc');

        $this->assertSame('xAcme', $service->getName());
        $this->assertSame('Acme Inc', $service->getVendor());
    }

    public function testApproveMatchedGroupsByCategoryAndVendorAndReusesExistingServices(): void
    {
        $analytics = self::category('analytics');
        $marketing = self::category('marketing');
        $existing = (new Service())->setName('google')->setCategory($analytics);

        $a = self::finding('cookie', '_ga', 'example.com')->setSuggestion('analytics', 'Google');
        $b = self::finding('script', 'www.google-analytics.com', 'www.google-analytics.com')->setSuggestion('analytics', 'Google');
        $c = self::finding('cookie', '_fbp', 'example.com')->setSuggestion('marketing', 'Meta');
        $d = self::finding('script', 'connect.facebook.net', 'connect.facebook.net')->setSuggestion('marketing', 'Meta');

        $created = Review::approveMatched([$a, $b, $c, $d], ['analytics' => $analytics, 'marketing' => $marketing], [$existing]);

        $this->assertCount(1, $created);
        $this->assertSame('Meta', $created[0]->getName());
        $this->assertSame($existing, $a->getService());
        $this->assertSame($existing, $b->getService());
        $this->assertSame($created[0], $c->getService());
        $this->assertSame($created[0], $d->getService());
        $this->assertSame(['www.google-analytics.com'], $existing->getDomains());

        foreach ([$a, $b, $c, $d] as $finding) {
            $this->assertSame(ScanFinding::APPROVED, $finding->getStatus());
        }
    }

    public function testApproveMatchedSkipsUnmatchedNoVendorAndUnknownCategoryFindings(): void
    {
        $analytics = self::category('analytics');
        $unclassified = self::finding('cookie', 'a', 'e.com', ScanFinding::UNCLASSIFIED);
        $noVendor = self::finding('cookie', 'b', 'e.com')->setSuggestion('analytics', '');
        $gone = self::finding('cookie', 'c', 'e.com')->setSuggestion('deleted_category', 'V');
        $ignored = self::finding('cookie', 'd', 'e.com', ScanFinding::IGNORED)->setSuggestion('analytics', 'V');

        $created = Review::approveMatched([$unclassified, $noVendor, $gone, $ignored], ['analytics' => $analytics], []);

        $this->assertSame([], $created);
        $this->assertSame(ScanFinding::UNCLASSIFIED, $unclassified->getStatus());
        $this->assertSame(ScanFinding::MATCHED, $noVendor->getStatus());
        $this->assertSame(ScanFinding::MATCHED, $gone->getStatus());
        $this->assertSame(ScanFinding::IGNORED, $ignored->getStatus());
    }

    public function testIgnoreOnlyAffectsUnreviewedFindings(): void
    {
        $open = self::finding('cookie', 'a', 'e.com');
        $approved = self::finding('cookie', 'b', 'e.com', ScanFinding::APPROVED);

        Review::ignore($open);
        Review::ignore($approved);

        $this->assertSame(ScanFinding::IGNORED, $open->getStatus());
        $this->assertSame(ScanFinding::APPROVED, $approved->getStatus());
    }

    public function testUnignoreClassifiesAgain(): void
    {
        $classifier = new Classifier([['kind' => 'cookie', 'pattern' => '_ga', 'category' => 'analytics', 'vendor' => 'Google', 'origin' => 'bundled']], static fn(string $name): bool => false);
        $known = self::finding('cookie', '_ga', 'e.com', ScanFinding::IGNORED);
        $unknown = self::finding('cookie', 'zzz', 'e.com', ScanFinding::IGNORED);
        $approved = self::finding('cookie', '_ga', 'f.com', ScanFinding::APPROVED);

        Review::unignore($known, $classifier);
        Review::unignore($unknown, $classifier);
        Review::unignore($approved, $classifier);

        $this->assertSame([ScanFinding::MATCHED, 'analytics'], [$known->getStatus(), $known->getSuggestedCategory()]);
        $this->assertSame(ScanFinding::UNCLASSIFIED, $unknown->getStatus());
        $this->assertSame(ScanFinding::APPROVED, $approved->getStatus());
    }

    public function testReassignChangesTheSuggestedCategoryOfAnUnreviewedFinding(): void
    {
        $open = self::finding('cookie', 'a', 'e.com', ScanFinding::UNCLASSIFIED);
        $approved = self::finding('cookie', 'b', 'e.com', ScanFinding::APPROVED)->setSuggestion('analytics', 'V');

        Review::reassign($open, 'marketing');
        Review::reassign($approved, 'marketing');

        $this->assertSame([ScanFinding::MATCHED, 'marketing'], [$open->getStatus(), $open->getSuggestedCategory()]);
        $this->assertSame('analytics', $approved->getSuggestedCategory());
    }

    /**
     * @return array<string, array{ScanFinding, ?array{kind: string, pattern: string}}>
     */
    public static function rules(): array
    {
        return [
            'cookie' => [self::finding('cookie', '_ga', 'e.com'), ['kind' => 'cookie', 'pattern' => '_ga']],
            'storage' => [self::finding('storage', 'cart', 'e.com'), ['kind' => 'storage', 'pattern' => 'cart']],
            'script host' => [self::finding('script', 'cdn.example.net', 'cdn.example.net'), ['kind' => 'host', 'pattern' => 'cdn.example.net']],
            'iframe host' => [self::finding('iframe', 'x', 'www.youtube.com'), ['kind' => 'host', 'pattern' => 'www.youtube.com']],
            'markup' => [self::finding('cookie', '<b>x</b>', 'e.com'), null],
            'space' => [self::finding('cookie', 'a b', 'e.com'), null],
            'wildcard in a literal name' => [self::finding('cookie', 'a*b', 'e.com'), null],
            'too long' => [self::finding('cookie', str_repeat('a', 129), 'e.com'), null],
            'empty' => [self::finding('cookie', '', 'e.com'), null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rules')]
    public function testOverrideRule(ScanFinding $finding, ?array $expected): void
    {
        $this->assertSame($expected, Review::overrideRule($finding));
    }

    public function testDefaultCategoryPrefersTheSuggestionThenTheFirstThenNothing(): void
    {
        $this->assertSame('marketing', Review::defaultCategory('marketing', ['necessary', 'marketing']));
        $this->assertSame('necessary', Review::defaultCategory('deleted', ['necessary', 'marketing']));
        $this->assertSame('necessary', Review::defaultCategory(null, ['necessary', 'marketing']));
        $this->assertNull(Review::defaultCategory('x', []));
    }
}
```

`tests/Scan/ScanRunsEndTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Scan\ScanRuns;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ScanRunsEndTest extends TestCase
{
    public function testEndRunningFinishesEveryRunningBeaconRunNow(): void
    {
        $a = (new ScanRun())->start(ScanRun::BEACON, 1000);
        $b = (new ScanRun())->start(ScanRun::BEACON, 1100);
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findBy')->with(['source' => ScanRun::BEACON, 'status' => ScanRun::RUNNING])->willReturn([$a, $b]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(ScanRun::class)->willReturn($repository);
        $em->expects($this->once())->method('flush');

        $this->assertSame(2, ScanRuns::endRunning($em, 1200));
        $this->assertSame([ScanRun::COMPLETE, 1200], [$a->getStatus(), $a->getFinishedAt()]);
        $this->assertSame([ScanRun::COMPLETE, 1200], [$b->getStatus(), $b->getFinishedAt()]);
    }

    public function testEndRunningWithNothingRunningDoesNotFlush(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->expects($this->never())->method('flush');

        $this->assertSame(0, ScanRuns::endRunning($em, 1200));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'ReviewTest|ScanRunsEndTest'`
Expected: FAIL with "Class ... Review not found" and "Call to undefined method ScanRuns::endRunning".

- [ ] **Step 3: Write `src/Scan/Review.php` and add `ScanRuns::endRunning`**

`src/Scan/Review.php`:

```php
<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Support\Text;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Entity\Category;
use CookieManagerKit\Entity\ScanFinding;

/**
 * What an admin can do with a finding. Approving is the only path from a finding to a Service, which is what
 * visitors see in the banner; nothing here runs without an admin's action (the dashboard controllers check the
 * permission and the CSRF token before calling it). Pure: it changes the entities it is given and persists nothing.
 */
final class Review
{
    private const HOST_KINDS = [ScanFinding::SCRIPT, ScanFinding::IFRAME, ScanFinding::IMAGE, ScanFinding::RESOURCE];

    /**
     * Attaches the finding to $service, or to a new service named $name in $category, and records the host on the
     * service so the banner and the later declaration can say where it loads from.
     *
     * @throws \InvalidArgumentException when a new service is wanted without a name
     */
    public static function approve(ScanFinding $finding, Category $category, ?Service $service, string $name, string $vendor): Service
    {
        if ($service === null) {
            $name = Text::plain($name);

            if ($name === '') {
                throw new \InvalidArgumentException('A new service needs a name.');
            }

            $service = (new Service())->setName($name)->setVendor(Text::plain($vendor))->setCategory($category);
        }

        if (in_array($finding->getKind(), self::HOST_KINDS, true) && Service::parseDomains($finding->getHost()) !== []) {
            $domains = $service->getDomains();

            if (!in_array($finding->getHost(), $domains, true)) {
                $service->setDomainsText(implode("\n", [...$domains, $finding->getHost()]));
            }
        }

        $finding->setService($service)->setStatus(ScanFinding::APPROVED);

        return $service;
    }

    /**
     * Approves every matched finding that has a vendor and a category that still exists, one service per category and
     * vendor (an existing service with the vendor's name in that category is reused).
     *
     * @param list<ScanFinding>        $findings
     * @param array<string, Category>  $categories by handle
     * @param list<Service>            $services   existing services
     *
     * @return list<Service> the services that were created and still need persisting
     */
    public static function approveMatched(array $findings, array $categories, array $services): array
    {
        $byKey = [];
        $created = [];

        foreach ($services as $service) {
            $category = $service->getCategory();

            if ($category !== null) {
                $byKey[$category->getHandle() . '|' . mb_strtolower($service->getName())] ??= $service;
            }
        }

        foreach ($findings as $finding) {
            $handle = $finding->getSuggestedCategory();
            $vendor = Text::plain($finding->getSuggestedVendor());

            if ($finding->getStatus() !== ScanFinding::MATCHED || $handle === null || $vendor === '' || !isset($categories[$handle])) {
                continue;
            }

            $key = $handle . '|' . mb_strtolower($vendor);

            if (!isset($byKey[$key])) {
                $byKey[$key] = (new Service())->setName($vendor)->setVendor($vendor)->setCategory($categories[$handle]);
                $created[] = $byKey[$key];
            }

            self::approve($finding, $categories[$handle], $byKey[$key], '', '');
        }

        return $created;
    }

    public static function ignore(ScanFinding $finding): void
    {
        if ($finding->isReviewable()) {
            $finding->setStatus(ScanFinding::IGNORED);
        }
    }

    public static function unignore(ScanFinding $finding, Classifier $classifier): void
    {
        if ($finding->getStatus() === ScanFinding::IGNORED) {
            self::reclassify($finding, $classifier);
        }
    }

    public static function reassign(ScanFinding $finding, string $handle): void
    {
        if ($finding->isReviewable()) {
            $finding->setSuggestion($handle, $finding->getSuggestedVendor())->setStatus(ScanFinding::MATCHED);
        }
    }

    public static function reclassify(ScanFinding $finding, Classifier $classifier): void
    {
        $suggestion = $classifier->classify($finding->getKind(), $finding->getName(), $finding->getHost());

        $finding->setSuggestion($suggestion?->category, $suggestion?->vendor);
        $finding->setStatus($suggestion === null ? ScanFinding::UNCLASSIFIED : ScanFinding::MATCHED);
    }

    /**
     * The classification rule "save as override" would store for this finding, or null when its name could not be
     * stored as a pattern (unusual characters, too long, or a `*` that would turn a literal name into a wildcard).
     *
     * @return array{kind: string, pattern: string}|null
     */
    public static function overrideRule(ScanFinding $finding): ?array
    {
        if (in_array($finding->getKind(), [ScanFinding::COOKIE, ScanFinding::STORAGE], true)) {
            $name = $finding->getName();

            return preg_match('/^[A-Za-z0-9_.:~%+\-]{1,128}\z/', $name) === 1 ? ['kind' => $finding->getKind(), 'pattern' => $name] : null;
        }

        $host = $finding->getHost();

        return Host::normalise($host) === $host ? ['kind' => 'host', 'pattern' => $host] : null;
    }

    /**
     * The category to preselect in an approve form: the suggestion if it still exists, otherwise the first category.
     *
     * @param list<string> $handles
     */
    public static function defaultCategory(?string $suggested, array $handles): ?string
    {
        if ($suggested !== null && in_array($suggested, $handles, true)) {
            return $suggested;
        }

        return $handles[0] ?? null;
    }
}
```

Append to `src/Scan/ScanRuns.php` (before the final `}`):

```php

    /**
     * Ends every running scan-mode session now (the admin pressed Stop, or started a new session).
     */
    public static function endRunning(EntityManagerInterface $em, int $now): int
    {
        $ended = 0;

        foreach ($em->getRepository(ScanRun::class)->findBy(['source' => ScanRun::BEACON, 'status' => ScanRun::RUNNING]) as $run) {
            $run->finish(ScanRun::COMPLETE, $now);
            $ended++;
        }

        if ($ended > 0) {
            $em->flush();
        }

        return $ended;
    }
```

- [ ] **Step 4: Run the `Review` tests**

Run: `composer test -- --filter 'ReviewTest|ScanRunsEndTest'`
Expected: PASS. (In `testApproveMatchedGroups…` the existing service is named `google` in lower case to prove the vendor match is case-insensitive.)

- [ ] **Step 5: Write the `Scanner` dashboard controller**

`controllers/single_page/dashboard/cookie_manager/scanner.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Entity\{ScanFinding, ScanRun, Settings};
use CookieManagerKit\Support\ErrorLog;
use CookieManagerKit\Scan\{ScanRuns, ScanToken, Services, StaleCheck};
use Symfony\Component\HttpFoundation\Cookie;
use Concrete\Core\Page\Controller\DashboardPageController;

/**
 * Overview of scanning: run a crawl now, start or stop scan mode, and see how recent runs went. Every action is a
 * POST with a CSRF token. Nothing from a scan is shown here except counts and times.
 */
class Scanner extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/scanner';

    public function view()
    {
        $now = time();

        ScanRuns::closeExpired($this->entityManager, $now);

        $settings = Settings::get();
        $lastBeacon = ScanRuns::lastBeaconAt($this->entityManager);
        $active = $this->entityManager->getRepository(ScanRun::class)->findOneBy(['source' => ScanRun::BEACON, 'status' => ScanRun::RUNNING], ['startedAt' => 'DESC']);
        $counts = [];
        $findings = $this->entityManager->getRepository(ScanFinding::class);

        foreach (ScanFinding::STATUSES as $status) {
            $counts[$status] = $findings->count(['status' => $status]);
        }

        $this->set('runs', ScanRuns::recent($this->entityManager, 20));
        $this->set('counts', $counts);
        $this->set('lastBeacon', $lastBeacon);
        $this->set('stale', StaleCheck::isStale($lastBeacon, $now, $settings->getScanStaleDays()));
        $this->set('staleDays', $settings->getScanStaleDays());
        $this->set('scanModeActive', ScanRuns::acceptsReports($active instanceof ScanRun ? $active : null, $now));
        $this->set('secretMissing', ScanToken::secret() === '');
    }

    public function crawl()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        try {
            @set_time_limit(300);
            $run = Services::crawlRunner()->run();

            $this->flash('success', t('Crawled %1$s pages: %2$s new, %3$s changed.', $run->getPagesScanned(), $run->getNewCount(), $run->getChangedCount()));
        } catch (\Throwable $e) {
            ErrorLog::record($e);
            $this->flash('error', t('The crawl could not finish. Details are in the cookie_manager log.'));
        }

        return $this->buildRedirect(self::URL)->send();
    }

    public function startScanMode()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $secret = ScanToken::secret();

        if ($secret === '') {
            $this->flash('error', t('Scan mode needs the site security token, which this installation does not have yet.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $now = time();

        ScanRuns::endRunning($this->entityManager, $now);

        $run = (new ScanRun())->start(ScanRun::BEACON, $now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $response = $this->buildRedirect(self::URL);
        $expires = $now + ScanToken::LIFETIME;
        $secure = $this->request->isSecure();

        // The signed token is HttpOnly; the marker only tells the page's runtime that this browser is in scan mode.
        $response->headers->setCookie(new Cookie(ScanToken::COOKIE, ScanToken::issue($run->getID(), $now, $secret), $expires, '/', null, $secure, true, false, Cookie::SAMESITE_STRICT));
        $response->headers->setCookie(new Cookie(ScanToken::MARKER, '1', $expires, '/', null, $secure, false, false, Cookie::SAMESITE_STRICT));

        $this->flash('success', t('Scan mode is on in this browser for 30 minutes. Accept all cookies on the banner, then browse the site.'));

        return $response->send();
    }

    public function stopScanMode()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        ScanRuns::endRunning($this->entityManager, time());

        $response = $this->buildRedirect(self::URL);
        $response->headers->clearCookie(ScanToken::COOKIE, '/');
        $response->headers->clearCookie(ScanToken::MARKER, '/');

        $this->flash('success', t('Scan mode is off.'));

        return $response->send();
    }
}
```

- [ ] **Step 6: Write the `Findings` dashboard controller**

`controllers/single_page/dashboard/cookie_manager/findings.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Scan\{ClassificationStore, Review, Services};
use CookieManagerKit\Entity\{Category, ScanFinding, Service};
use CookieManagerKit\Support\{Catalog, SiteLocales, Text};
use Concrete\Core\Page\Controller\DashboardPageController;

/**
 * Review queue. Listing and filtering read only; every action that changes data is a POST with a CSRF token, takes
 * entity IDs that are checked against the database, and redirects to this page. Approving a finding is the only way a
 * scan can change what the banner lists, so it always starts with an admin clicking.
 */
class Findings extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/findings';
    private const PER_PAGE = 50;

    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private function id(mixed $value): ?int
    {
        return (is_string($value) || is_int($value)) && preg_match('/^\d{1,9}\z/', (string) $value) === 1 ? (int) $value : null;
    }

    private function finding(mixed $id): ?ScanFinding
    {
        $id = $this->id($id);
        $finding = $id === null ? null : $this->entityManager->getRepository(ScanFinding::class)->find($id);

        return $finding instanceof ScanFinding ? $finding : null;
    }

    private function category(mixed $id): ?Category
    {
        $id = $this->id($id);
        $category = $id === null ? null : $this->entityManager->getRepository(Category::class)->find($id);

        return $category instanceof Category ? $category : null;
    }

    private function service(mixed $id): ?Service
    {
        $id = $this->id($id);
        $service = $id === null ? null : $this->entityManager->getRepository(Service::class)->find($id);

        return $service instanceof Service ? $service : null;
    }

    private function choice(string $key, array $allowed): string
    {
        $value = $this->request->query->get($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    public function view()
    {
        $status = $this->choice('status', ScanFinding::STATUSES);
        $kind = $this->choice('kind', ScanFinding::KINDS);
        $criteria = array_filter(['status' => $status, 'kind' => $kind], static fn(string $value): bool => $value !== '');
        $repository = $this->entityManager->getRepository(ScanFinding::class);
        $total = $repository->count($criteria);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = $this->id($this->request->query->get('page')) ?? 1;
        $page = max(1, min($pages, $page));

        $default = SiteLocales::defaultLocale();
        $categories = [];

        foreach ($this->entityManager->getRepository(Category::class)->findBy([], ['sortOrder' => 'ASC', 'handle' => 'ASC']) as $category) {
            $content = $category->getContent($default);
            $categories[$category->getHandle()] = ['id' => $category->getID(), 'title' => $content ? $content->getTitle() : $category->getHandle()];
        }

        $this->set('findings', $repository->findBy($criteria, ['lastSeen' => 'DESC', 'id' => 'DESC'], self::PER_PAGE, ($page - 1) * self::PER_PAGE));
        $this->set('status', $status);
        $this->set('kind', $kind);
        $this->set('page', $page);
        $this->set('pages', $pages);
        $this->set('total', $total);
        $this->set('categories', $categories);
        $this->set('services', $this->entityManager->getRepository(Service::class)->findBy([], ['name' => 'ASC']));
    }

    public function approve()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));
        $category = $this->category($this->post('categoryID'));
        $serviceId = $this->post('serviceID');
        $service = is_string($serviceId) && $serviceId !== '' ? $this->service($serviceId) : null;

        if ($finding === null || $category === null || (is_string($serviceId) && $serviceId !== '' && $service === null)) {
            $this->flash('error', t('That finding, category or service could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        try {
            $service = Review::approve($finding, $category, $service, Text::plain($this->post('name')), Text::plain($this->post('vendor')));
        } catch (\InvalidArgumentException) {
            $this->flash('error', t('A new service needs a name.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->entityManager->persist($service);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Finding approved.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function ignore()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));

        if ($finding === null) {
            $this->flash('error', t('That finding could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        Review::ignore($finding);
        $this->entityManager->flush();

        $this->flash('success', t('Finding ignored.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function unignore()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));

        if ($finding === null) {
            $this->flash('error', t('That finding could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        Review::unignore($finding, Services::classifier());
        $this->entityManager->flush();

        $this->flash('success', t('Finding restored to the review queue.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function reassign()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));
        $category = $this->category($this->post('categoryID'));

        if ($finding === null || $category === null) {
            $this->flash('error', t('That finding or category could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        Review::reassign($finding, $category->getHandle());
        $this->entityManager->flush();

        $this->flash('success', t('Suggested category changed.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function override()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $finding = $this->finding($this->post('id'));
        $category = $this->category($this->post('categoryID'));
        $rule = $finding === null ? null : Review::overrideRule($finding);

        if ($finding === null || $category === null) {
            $this->flash('error', t('That finding or category could not be found.'));

            return $this->buildRedirect(self::URL)->send();
        }

        if ($rule === null) {
            $this->flash('error', t('This name has unusual characters, so it cannot be saved as a rule. Approve it instead.'));

            return $this->buildRedirect(self::URL)->send();
        }

        (new ClassificationStore($this->entityManager))->addOverride($rule['kind'], $rule['pattern'], $category->getHandle(), Text::plain($this->post('vendor')));

        $classifier = Services::classifier();

        foreach ($this->entityManager->getRepository(ScanFinding::class)->findBy(['status' => [ScanFinding::MATCHED, ScanFinding::UNCLASSIFIED]]) as $open) {
            Review::reclassify($open, $classifier);
        }

        $this->entityManager->flush();

        $this->flash('success', t('Rule saved. It overrides the built-in data from now on.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function bulkApprove()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $categories = [];

        foreach ($this->entityManager->getRepository(Category::class)->findAll() as $category) {
            $categories[$category->getHandle()] = $category;
        }

        $matched = $this->entityManager->getRepository(ScanFinding::class)->findBy(['status' => ScanFinding::MATCHED]);
        $created = Review::approveMatched(array_values($matched), $categories, array_values($this->entityManager->getRepository(Service::class)->findAll()));

        foreach ($created as $service) {
            $this->entityManager->persist($service);
        }

        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Approved matched findings. %s new services were created.', count($created)));

        return $this->buildRedirect(self::URL)->send();
    }
}
```

- [ ] **Step 7: Write the `ScanSettings` dashboard controller**

`controllers/single_page/dashboard/cookie_manager/scan_settings.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use GuzzleHttp\Client;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;
use CookieManagerKit\Scan\{ClassificationStore, GithubSnapshot, Refresher};
use Concrete\Core\Page\Controller\DashboardPageController;

/**
 * Scanner settings. Values are validated by the Settings entity (clamped, allow-listed or dropped), never trusted.
 */
class ScanSettings extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/scan_settings';

    public $helpers = [
        'form',
        'concrete/ui',
    ];

    public function view()
    {
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

        $settings = Settings::get();
        $settings->setScanMaxPages($this->post('scanMaxPages'))
            ->setScanExcludesText($this->post('scanExcludes'))
            ->setScanScheduleEnabled($this->post('scanSchedule') !== null)
            ->setScanAlertEmail($this->post('scanAlertEmail'))
            ->setScanStaleDays($this->post('scanStaleDays'))
            ->setClassificationRefreshEnabled($this->post('classificationRefresh') !== null);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        $this->flash('success', t('Scanner settings saved.'));

        return $this->buildRedirect(self::URL)->send();
    }

    public function refreshNow()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        try {
            $count = (new Refresher(new GithubSnapshot(new Client()), new ClassificationStore($this->entityManager)))->refresh();

            $this->flash('success', t('Classification data updated: %s entries.', $count));
        } catch (\Throwable $e) {
            ErrorLog::record($e);
            $this->flash('error', t('The classification data could not be updated. The previous data is still in use.'));
        }

        return $this->buildRedirect(self::URL)->send();
    }
}
```

- [ ] **Step 8: Write the three views**

`single_pages/dashboard/cookie_manager/scanner.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Entity\ScanRun[] $runs */
/** @var array<string, int> $counts */
/** @var int|null $lastBeacon */
/** @var bool $stale */
/** @var int $staleDays */
/** @var bool $scanModeActive */
/** @var bool $secretMissing */

$when = static fn(?int $time): string => $time === null ? t('never') : gmdate('Y-m-d H:i', $time) . ' UTC';
$sources = ['crawl' => t('Crawl'), 'beacon' => t('Scan mode')];
$statuses = ['running' => t('Running'), 'complete' => t('Complete'), 'failed' => t('Failed')];
?>
<?php if ($stale) { ?>
    <div class="alert alert-warning">
        <?php echo O::e(sprintf(t('No runtime scan has run in the last %d days, so cookies and storage set by scripts may be missing. Start scan mode below and browse the site.'), $staleDays)) ?>
    </div>
<?php } ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card"><div class="card-body">
            <div class="text-muted"><?php echo t('Unclassified') ?></div>
            <div class="fs-3"><?php echo O::e(number_format((int) ($counts['unclassified'] ?? 0))) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body">
            <div class="text-muted"><?php echo t('Matched, to review') ?></div>
            <div class="fs-3"><?php echo O::e(number_format((int) ($counts['matched'] ?? 0))) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body">
            <div class="text-muted"><?php echo t('Approved') ?></div>
            <div class="fs-3"><?php echo O::e(number_format((int) ($counts['approved'] ?? 0))) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body">
            <div class="text-muted"><?php echo t('Last runtime scan') ?></div>
            <div class="fs-6"><?php echo O::e($when($lastBeacon)) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <h3><?php echo t('Crawl the site') ?></h3>
        <p class="text-muted"><?php echo t('Fetches your published pages as a visitor would and lists the third-party scripts, frames, images and cookies it finds. It runs no JavaScript, so scripts that add cookies later are found by scan mode. Large sites should use the scheduled task instead.') ?></p>
        <form method="post" action="<?php echo O::e($view->action('crawl')) ?>">
            <?php echo $token->output('submit') ?>
            <button type="submit" class="btn btn-primary"><?php echo t('Run crawl now') ?></button>
        </form>
    </div>
    <div class="col-md-6">
        <h3><?php echo t('Scan mode') ?></h3>
        <p class="text-muted"><?php echo t('Start scan mode, accept all cookies on the banner, then browse the site in this browser. For 30 minutes this browser reports the names of cookies and storage keys and the hosts of what each page loaded. Values are never read, and other visitors are not affected.') ?></p>
        <?php if ($secretMissing) { ?>
            <div class="alert alert-danger"><?php echo t('Scan mode is unavailable: this installation has no site security token.') ?></div>
        <?php } elseif ($scanModeActive) { ?>
            <p><span class="badge bg-success"><?php echo t('Scan mode is on') ?></span></p>
            <form method="post" action="<?php echo O::e($view->action('stopScanMode')) ?>">
                <?php echo $token->output('submit') ?>
                <button type="submit" class="btn btn-outline-secondary"><?php echo t('Stop scan mode') ?></button>
            </form>
        <?php } else { ?>
            <form method="post" action="<?php echo O::e($view->action('startScanMode')) ?>">
                <?php echo $token->output('submit') ?>
                <button type="submit" class="btn btn-primary"><?php echo t('Start scan mode') ?></button>
            </form>
        <?php } ?>
    </div>
</div>

<h3><?php echo t('Recent runs') ?></h3>
<?php if ($runs === []) { ?>
    <p class="text-muted"><?php echo t('Nothing has been scanned yet.') ?></p>
<?php } else { ?>
    <table class="table table-striped">
        <thead>
            <tr>
                <th><?php echo t('Started') ?></th>
                <th><?php echo t('Type') ?></th>
                <th><?php echo t('Status') ?></th>
                <th><?php echo t('Pages') ?></th>
                <th><?php echo t('New') ?></th>
                <th><?php echo t('Changed') ?></th>
                <th><?php echo t('Unclassified') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($runs as $run) { ?>
                <tr>
                    <td><?php echo O::e($when($run->getStartedAt())) ?></td>
                    <td><?php echo O::e($sources[$run->getSource()] ?? $run->getSource()) ?></td>
                    <td><?php echo O::e($statuses[$run->getStatus()] ?? $run->getStatus()) ?></td>
                    <td><?php echo O::e($run->getPagesScanned()) ?></td>
                    <td><?php echo O::e($run->getNewCount()) ?></td>
                    <td><?php echo O::e($run->getChangedCount()) ?></td>
                    <td><?php echo O::e($run->getUnclassifiedCount()) ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
<?php } ?>
```

`single_pages/dashboard/cookie_manager/findings.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Scan\Review;
use CookieManagerKit\Support\Output as O;
use CookieManagerKit\Entity\ScanFinding;

/** @var CookieManagerKit\Entity\ScanFinding[] $findings */
/** @var string $status */
/** @var string $kind */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var array<string, array{id: int, title: string}> $categories handle => id and title */
/** @var CookieManagerKit\Entity\Service[] $services */

$statusLabels = [
    ScanFinding::UNCLASSIFIED => t('Unclassified'),
    ScanFinding::MATCHED => t('Matched'),
    ScanFinding::APPROVED => t('Approved'),
    ScanFinding::IGNORED => t('Ignored'),
    ScanFinding::GONE => t('Gone'),
];
$kindLabels = [
    ScanFinding::COOKIE => t('Cookie'),
    ScanFinding::STORAGE => t('Storage key'),
    ScanFinding::SCRIPT => t('Script'),
    ScanFinding::IFRAME => t('Frame'),
    ScanFinding::IMAGE => t('Image'),
    ScanFinding::RESOURCE => t('Other resource'),
];
$when = static fn(int $time): string => gmdate('Y-m-d H:i', $time) . ' UTC';
$handles = array_keys($categories);
?>
<form method="get" class="row g-3 align-items-end mb-4">
    <div class="col-auto">
        <label class="form-label" for="cmk-status"><?php echo t('Status') ?></label>
        <select class="form-select" id="cmk-status" name="status">
            <option value=""><?php echo t('All') ?></option>
            <?php foreach ($statusLabels as $value => $label) { ?>
                <option value="<?php echo O::e($value) ?>" <?php echo O::e($status === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-kind"><?php echo t('Type') ?></label>
        <select class="form-select" id="cmk-kind" name="kind">
            <option value=""><?php echo t('All') ?></option>
            <?php foreach ($kindLabels as $value => $label) { ?>
                <option value="<?php echo O::e($value) ?>" <?php echo O::e($kind === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary"><?php echo t('Filter') ?></button>
    </div>
</form>

<form method="post" action="<?php echo O::e($view->action('bulkApprove')) ?>" class="mb-3">
    <?php echo $token->output('submit') ?>
    <button type="submit" class="btn btn-outline-primary"><?php echo t('Approve all matched findings') ?></button>
    <span class="text-muted ms-2"><?php echo t('Creates one service per vendor and category. Findings without a vendor stay in the queue.') ?></span>
</form>

<p class="text-muted"><?php echo O::e(sprintf(t('%d findings.'), $total)) ?></p>

<?php if ($findings === []) { ?>
    <p><?php echo t('No findings match. Run a crawl or start scan mode on the Scanner page.') ?></p>
<?php } else { ?>
    <table class="table table-striped align-middle">
        <thead>
            <tr>
                <th><?php echo t('Type') ?></th>
                <th><?php echo t('Name') ?></th>
                <th><?php echo t('Host') ?></th>
                <th><?php echo t('Status') ?></th>
                <th><?php echo t('Suggestion') ?></th>
                <th><?php echo t('Last seen') ?></th>
                <th><?php echo t('Seen on') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($findings as $row) { ?>
                <?php $preselect = Review::defaultCategory($row->getSuggestedCategory(), $handles); ?>
                <tr>
                    <td><?php echo O::e($kindLabels[$row->getKind()] ?? $row->getKind()) ?></td>
                    <td><code><?php echo O::e($row->getName()) ?></code></td>
                    <td><?php echo O::e($row->getHost()) ?></td>
                    <td><?php echo O::e($statusLabels[$row->getStatus()] ?? $row->getStatus()) ?></td>
                    <td><?php echo O::e(trim(($row->getSuggestedCategory() ?? '') . ' ' . ($row->getSuggestedVendor() ?? ''))) ?></td>
                    <td><?php echo O::e($when($row->getLastSeen())) ?></td>
                    <td><?php echo O::e(implode(', ', $row->sources())) ?></td>
                    <td class="text-end">
                        <?php if ($row->isReviewable()) { ?>
                            <details>
                                <summary class="btn btn-sm btn-outline-primary"><?php echo t('Review') ?></summary>

                                <form method="post" action="<?php echo O::e($view->action('approve')) ?>" class="mt-2 text-start">
                                    <?php echo $token->output('submit') ?>
                                    <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                    <label class="form-label" for="svc-<?php echo O::e($row->getID()) ?>"><?php echo t('Service') ?></label>
                                    <select class="form-select mb-2" id="svc-<?php echo O::e($row->getID()) ?>" name="serviceID">
                                        <option value=""><?php echo t('Create a new service') ?></option>
                                        <?php foreach ($services as $service) { ?>
                                            <option value="<?php echo O::e($service->getID()) ?>"><?php echo O::e($service->getName()) ?></option>
                                        <?php } ?>
                                    </select>
                                    <input type="text" class="form-control mb-2" name="name" maxlength="255" placeholder="<?php echo t('New service name') ?>" value="<?php echo O::e($row->getSuggestedVendor() ?? $row->getHost()) ?>">
                                    <input type="text" class="form-control mb-2" name="vendor" maxlength="255" placeholder="<?php echo t('Vendor') ?>" value="<?php echo O::e($row->getSuggestedVendor() ?? '') ?>">
                                    <select class="form-select mb-2" name="categoryID">
                                        <?php foreach ($categories as $handle => $info) { ?>
                                            <option value="<?php echo O::e($info['id']) ?>" <?php echo O::e($handle === $preselect ? 'selected' : '') ?>><?php echo O::e($info['title']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary"><?php echo t('Approve') ?></button>
                                </form>

                                <form method="post" action="<?php echo O::e($view->action('reassign')) ?>" class="mt-2 text-start">
                                    <?php echo $token->output('submit') ?>
                                    <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                    <select class="form-select mb-2" name="categoryID">
                                        <?php foreach ($categories as $handle => $info) { ?>
                                            <option value="<?php echo O::e($info['id']) ?>" <?php echo O::e($handle === $preselect ? 'selected' : '') ?>><?php echo O::e($info['title']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo t('Change suggested category') ?></button>
                                </form>

                                <form method="post" action="<?php echo O::e($view->action('override')) ?>" class="mt-2 text-start">
                                    <?php echo $token->output('submit') ?>
                                    <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                    <input type="text" class="form-control mb-2" name="vendor" maxlength="255" placeholder="<?php echo t('Vendor for this rule') ?>" value="<?php echo O::e($row->getSuggestedVendor() ?? '') ?>">
                                    <select class="form-select mb-2" name="categoryID">
                                        <?php foreach ($categories as $handle => $info) { ?>
                                            <option value="<?php echo O::e($info['id']) ?>" <?php echo O::e($handle === $preselect ? 'selected' : '') ?>><?php echo O::e($info['title']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo t('Always classify like this') ?></button>
                                </form>

                                <form method="post" action="<?php echo O::e($view->action('ignore')) ?>" class="mt-2 text-start">
                                    <?php echo $token->output('submit') ?>
                                    <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo t('Ignore') ?></button>
                                </form>
                            </details>
                        <?php } elseif ($row->getStatus() === ScanFinding::IGNORED) { ?>
                            <form method="post" action="<?php echo O::e($view->action('unignore')) ?>">
                                <?php echo $token->output('submit') ?>
                                <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo t('Review again') ?></button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <?php if ($pages > 1) { ?>
        <nav class="mt-3">
            <?php for ($n = 1; $n <= $pages; $n++) { ?>
                <a class="btn btn-sm <?php echo O::e($n === $page ? 'btn-primary' : 'btn-outline-secondary') ?>" href="<?php echo O::e('?page=' . (int) $n . '&status=' . rawurlencode($status) . '&kind=' . rawurlencode($kind)) ?>"><?php echo O::e($n) ?></a>
            <?php } ?>
        </nav>
    <?php } ?>
<?php } ?>
```

`single_pages/dashboard/cookie_manager/scan_settings.php`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Entity\Settings $settings */
?>
<form method="post" action="<?php echo O::e($view->action('save')) ?>">
    <?php echo $token->output('submit') ?>

    <div class="row mb-3">
        <div class="col-md-4">
            <label class="form-label" for="scanMaxPages"><?php echo t('Most pages one crawl fetches') ?></label>
            <input type="number" class="form-control" id="scanMaxPages" name="scanMaxPages" min="1" max="500" value="<?php echo O::e($settings->getScanMaxPages()) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="scanStaleDays"><?php echo t('Flag a missing runtime scan after (days)') ?></label>
            <input type="number" class="form-control" id="scanStaleDays" name="scanStaleDays" min="7" max="365" value="<?php echo O::e($settings->getScanStaleDays()) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="scanAlertEmail"><?php echo t('Email alerts to') ?></label>
            <input type="email" class="form-control" id="scanAlertEmail" name="scanAlertEmail" maxlength="255" value="<?php echo O::e($settings->getScanAlertEmail()) ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="scanExcludes"><?php echo t('Pages the crawl skips') ?></label>
        <textarea class="form-control" id="scanExcludes" name="scanExcludes" rows="4" placeholder="/members"><?php echo O::e($settings->getScanExcludesText()) ?></textarea>
        <div class="help-block form-text"><?php echo t('One path per line. "/members" skips that page and everything under it; "/shop/*" skips everything that starts with /shop/. While any page is skipped, a crawl will not mark findings as gone.') ?></div>
    </div>

    <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="scanSchedule" name="scanSchedule" value="1" <?php echo O::e($settings->isScanScheduleEnabled() ? 'checked' : '') ?>>
        <label class="form-check-label" for="scanSchedule"><?php echo t('Run the scheduled crawl') ?></label>
        <div class="help-block form-text"><?php echo t('Schedule the "Scan the site for cookies and scripts" task under Automation. It does nothing unless this is ticked. Set the site canonical URL first.') ?></div>
    </div>

    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="classificationRefresh" name="classificationRefresh" value="1" <?php echo O::e($settings->isClassificationRefreshEnabled() ? 'checked' : '') ?>>
        <label class="form-check-label" for="classificationRefresh"><?php echo t('Let the scheduled task download newer classification data') ?></label>
        <div class="help-block form-text"><?php echo t('Off by default. The task downloads one public file (the Open Cookie Database) and sends nothing about your site. The built-in data is used if the download fails.') ?></div>
    </div>

    <button type="submit" class="btn btn-primary"><?php echo t('Save') ?></button>
</form>

<hr>

<form method="post" action="<?php echo O::e($view->action('refreshNow')) ?>">
    <?php echo $token->output('submit') ?>
    <button type="submit" class="btn btn-outline-secondary"><?php echo t('Update classification data now') ?></button>
    <span class="text-muted ms-2"><?php echo t('Downloads the Open Cookie Database once. Your own rules are never replaced.') ?></span>
</form>
```

- [ ] **Step 9: Register the pages**

In `controller.php` `installOrUpgrade`, add after the `statistics` single page:

```php
        $this->addSinglePage('/dashboard/cookie_manager/scanner', $pkg, t('Scanner'), t('Scan the site for cookies and scripts'));
        $this->addSinglePage('/dashboard/cookie_manager/findings', $pkg, t('Findings'), t('Review what the scanner found'));
        $this->addSinglePage('/dashboard/cookie_manager/scan_settings', $pkg, t('Scanner Settings'), t('Crawl limits, alerts and classification data'));
```

- [ ] **Step 10: Write the view rendering test**

`tests/Dashboard/FindingsViewTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Dashboard;

use ClassKit\Entity\Core\BaseEntity;
use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Entity\ScanFinding;

/**
 * Renders the real findings view with hostile data and checks that nothing reaches the page as markup. The static
 * ViewEscapingTest proves every echo uses the encoder; this proves the encoder is applied to the data that matters.
 */
final class FindingsViewTest extends TestCase
{
    private const HOSTILE = '"><img src=x onerror=alert(1)><script>alert(2)</script>';

    public static function setUpBeforeClass(): void
    {
        if (!defined('C5_EXECUTE')) {
            define('C5_EXECUTE', true);
        }

        require_once __DIR__ . '/translate.php';
    }

    private static function finding(int $id, string $kind, string $name, string $host, string $status): ScanFinding
    {
        $finding = (new ScanFinding())->identify($kind, $name, $host)->setStatus($status)->setLastSeen(1700000000)->addSource('crawl');
        (new \ReflectionProperty(BaseEntity::class, 'id'))->setValue($finding, $id);

        return $finding;
    }

    /**
     * @param list<ScanFinding> $findings
     */
    private static function render(array $findings, string $status = '', string $kind = ''): string
    {
        $view = new class {
            public function action(string $name): string
            {
                return '/dashboard/cookie_manager/findings/' . $name;
            }
        };
        $token = new class {
            public function output(string $action): string
            {
                return '<input type="hidden" name="ccm_token" value="t">';
            }
        };
        $categories = ['necessary' => ['id' => 1, 'title' => 'Necessary'], 'analytics' => ['id' => 2, 'title' => self::HOSTILE]];
        $services = [(new Service())->setName(self::HOSTILE)];
        (new \ReflectionProperty(BaseEntity::class, 'id'))->setValue($services[0], 5);
        $total = count($findings);
        $pages = 1;
        $page = 1;

        ob_start();
        include dirname(__DIR__, 2) . '/single_pages/dashboard/cookie_manager/findings.php';

        return (string) ob_get_clean();
    }

    public function testHostileNamesHostsSuggestionsAndTitlesAreEncoded(): void
    {
        $html = self::render([
            self::finding(1, 'cookie', self::HOSTILE, 'example.com', ScanFinding::MATCHED),
            self::finding(2, 'script', 'x', 'cdn.example.net', ScanFinding::UNCLASSIFIED),
        ]);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testASuggestionIsShownEncoded(): void
    {
        $finding = self::finding(1, 'cookie', '_ga', 'example.com', ScanFinding::MATCHED)->setSuggestion('analytics', self::HOSTILE);

        $html = self::render([$finding]);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('analytics &quot;&gt;&lt;img', $html);
    }

    public function testApprovedFindingsOfferNoActionsAndIgnoredOnesOfferReviewAgain(): void
    {
        $html = self::render([
            self::finding(1, 'cookie', 'approved_one', 'example.com', ScanFinding::APPROVED),
            self::finding(2, 'cookie', 'ignored_one', 'example.com', ScanFinding::IGNORED),
        ]);

        $this->assertSame(0, substr_count($html, '/findings/approve'));
        $this->assertSame(1, substr_count($html, '/findings/unignore'));
    }

    public function testAnEmptyListSaysSo(): void
    {
        $this->assertStringContainsString('No findings match', self::render([]));
    }
}
```

Create `tests/Dashboard/translate.php`, the stand-in for Concrete's `t()` that lets the view run outside Concrete:

```php
<?php

declare(strict_types=1);

if (!function_exists('t')) {
    /**
     * @param mixed ...$args
     */
    function t(string $text, ...$args): string
    {
        return $args === [] ? $text : vsprintf($text, $args);
    }
}
```

The test file sets the view's variables, so the view runs outside Concrete. Nothing else in the suite defines `t()`, and the `function_exists` guard keeps this safe if that changes.

- [ ] **Step 11: Run the whole suite, including the static gates**

Run: `composer test`
Expected: PASS. `ControllerSecurityTest` now covers `scanner.php`, `findings.php`, `scan_settings.php`; `ViewEscapingTest` covers the three new views. If a gate fails, fix the **controller or view** (never relax the gate): typical causes are an `echo` of a bare variable, `t()` with variables in a view, or a guard block that does not start the method.

- [ ] **Step 12: Commit**

```bash
composer format:php && composer typecheck
git add src controllers single_pages controller.php tests
git commit -m "feat: add the scanner, findings and settings dashboard pages

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Scheduled tasks

**Files:**
- Create: `src/Command/Task/CrawlCommand.php`, `CrawlCommandHandler.php`, `RefreshClassificationCommand.php`, `RefreshClassificationCommandHandler.php`, `src/Command/Task/Controller/CrawlController.php`, `src/Command/Task/Controller/RefreshClassificationController.php`, `tasks.xml`
- Modify: `controller.php` (`$tasks`, `installOrUpgrade`)
- Test: `tests/TasksTest.php`

**Interfaces:**
- Consumes: `Services::crawlRunner`, `ScanRuns::lastBeaconAt`, `StaleCheck::isStale`, `Settings::isScanScheduleEnabled`, `Settings::isClassificationRefreshEnabled`, `Refresher`, `GithubSnapshot`, `ClassificationStore`, `ErrorLog::record`.
- Produces: Concrete tasks `cmk_scan_crawl` and `cmk_refresh_classification`, in the task set `cookie_manager_kit`.

- [ ] **Step 1: Write the failing test**

`tests/TasksTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The task handles in tasks.xml, the map in the package controller and the classes on disk must all agree, or the
 * task shows up in Automation without a working controller (or the other way round).
 */
final class TasksTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testEveryXmlTaskHasAControllerEntryAndClassFiles(): void
    {
        $xml = simplexml_load_file(self::ROOT . '/tasks.xml');
        $controller = (string) file_get_contents(self::ROOT . '/controller.php');
        preg_match_all("/'([a-z_]+)'\s*=>\s*\\\\CookieManagerKit\\\\Command\\\\Task\\\\Controller\\\\([A-Za-z]+)::class/", $controller, $mapped, PREG_SET_ORDER);
        $map = [];

        foreach ($mapped as $row) {
            $map[$row[1]] = $row[2];
        }

        $this->assertNotEmpty($map);

        foreach ($xml->tasks->task as $task) {
            $handle = (string) $task['handle'];

            $this->assertSame('cookie_manager_kit', (string) $task['package'], $handle);
            $this->assertArrayHasKey($handle, $map, $handle . ' is missing from $tasks in controller.php');
            $this->assertFileExists(self::ROOT . '/src/Command/Task/Controller/' . $map[$handle] . '.php');
        }

        $this->assertSame(array_keys($map), array_map(static fn($task): string => (string) $task['handle'], iterator_to_array($xml->tasks->task, false)));
    }

    public function testEveryCommandHasAHandlerBesideIt(): void
    {
        foreach (glob(self::ROOT . '/src/Command/Task/*Command.php') ?: [] as $command) {
            $this->assertFileExists(preg_replace('/\.php\z/', 'Handler.php', $command), basename($command));
        }
    }

    public function testTheTasksetListsEveryTask(): void
    {
        $xml = simplexml_load_file(self::ROOT . '/tasks.xml');
        $tasks = array_map(static fn($task): string => (string) $task['handle'], iterator_to_array($xml->tasks->task, false));
        $inSet = array_map(static fn($task): string => (string) $task['handle'], iterator_to_array($xml->tasksets->taskset->task, false));

        $this->assertSame($tasks, $inSet);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter TasksTest`
Expected: FAIL (`tasks.xml` does not exist).

- [ ] **Step 3: Write the commands, handlers and controllers**

`src/Command/Task/CrawlCommand.php`:

```php
<?php

namespace CookieManagerKit\Command\Task;

use Concrete\Core\Foundation\Command\Command;

class CrawlCommand extends Command {}
```

`src/Command/Task/CrawlCommandHandler.php`:

```php
<?php

namespace CookieManagerKit\Command\Task;

use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;
use CookieManagerKit\Scan\{ScanRuns, Services, StaleCheck};
use Doctrine\ORM\EntityManagerInterface;
use Concrete\Core\Command\Task\Output\{OutputAwareInterface, OutputAwareTrait};

class CrawlCommandHandler implements OutputAwareInterface
{
    use OutputAwareTrait;

    public function __invoke(CrawlCommand $command)
    {
        $settings = Settings::get();

        if (!$settings->isScanScheduleEnabled()) {
            $this->output->write(t('Scheduled scanning is switched off in the Scanner settings, so nothing was scanned.'));

            return;
        }

        try {
            $run = Services::crawlRunner()->run();
        } catch (\Throwable $e) {
            ErrorLog::record($e);
            $this->output->write(t('The crawl could not finish. Details are in the cookie_manager log.'));

            return;
        }

        $this->output->write(t('Crawled %1$s pages: %2$s new, %3$s changed, %4$s unclassified.', $run->getPagesScanned(), $run->getNewCount(), $run->getChangedCount(), $run->getUnclassifiedCount()));

        $last = ScanRuns::lastBeaconAt(app(EntityManagerInterface::class));

        if (StaleCheck::isStale($last, time(), $settings->getScanStaleDays())) {
            $this->output->write(t('No runtime scan in the last %s days. Start scan mode in the dashboard and browse the site.', $settings->getScanStaleDays()));
        }
    }
}
```

`src/Command/Task/RefreshClassificationCommand.php`:

```php
<?php

namespace CookieManagerKit\Command\Task;

use Concrete\Core\Foundation\Command\Command;

class RefreshClassificationCommand extends Command {}
```

`src/Command/Task/RefreshClassificationCommandHandler.php`:

```php
<?php

namespace CookieManagerKit\Command\Task;

use GuzzleHttp\Client;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Scan\{ClassificationStore, GithubSnapshot, Refresher};
use Concrete\Core\Command\Task\Output\{OutputAwareInterface, OutputAwareTrait};

class RefreshClassificationCommandHandler implements OutputAwareInterface
{
    use OutputAwareTrait;

    public function __invoke(RefreshClassificationCommand $command)
    {
        if (!Settings::get()->isClassificationRefreshEnabled()) {
            $this->output->write(t('Downloading classification data is switched off in the Scanner settings, so nothing was downloaded.'));

            return;
        }

        try {
            $count = (new Refresher(new GithubSnapshot(new Client()), new ClassificationStore(app(EntityManagerInterface::class))))->refresh();
        } catch (\Throwable $e) {
            ErrorLog::record($e);
            $this->output->write(t('The classification data could not be updated. The previous data is still in use.'));

            return;
        }

        $this->output->write(t('Classification data updated: %s entries.', $count));
    }
}
```

`src/Command/Task/Controller/CrawlController.php`:

```php
<?php

namespace CookieManagerKit\Command\Task\Controller;

use CookieManagerKit\Command\Task\CrawlCommand;
use Concrete\Core\Command\Task\TaskInterface;
use Concrete\Core\Command\Task\Input\InputInterface;
use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Runner\{ProcessTaskRunner, TaskRunnerInterface};

class CrawlController extends AbstractController
{
    public function getName(): string
    {
        return t('Scan the site for cookies and scripts');
    }

    public function getDescription(): string
    {
        return t('Fetches the published pages, records the third-party scripts, frames, images and cookies they use, and emails an alert when something new appears. Does nothing unless scheduled scanning is switched on in the Scanner settings.');
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new ProcessTaskRunner(
            $task,
            new CrawlCommand(),
            $input,
            t('Scanning the site...'),
        );
    }
}
```

`src/Command/Task/Controller/RefreshClassificationController.php`:

```php
<?php

namespace CookieManagerKit\Command\Task\Controller;

use Concrete\Core\Command\Task\TaskInterface;
use Concrete\Core\Command\Task\Input\InputInterface;
use CookieManagerKit\Command\Task\RefreshClassificationCommand;
use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Runner\{ProcessTaskRunner, TaskRunnerInterface};

class RefreshClassificationController extends AbstractController
{
    public function getName(): string
    {
        return t('Update cookie classification data');
    }

    public function getDescription(): string
    {
        return t('Downloads the public Open Cookie Database and uses it to suggest categories for scan findings. Does nothing unless downloading is switched on in the Scanner settings; your own rules are never replaced.');
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new ProcessTaskRunner(
            $task,
            new RefreshClassificationCommand(),
            $input,
            t('Updating classification data...'),
        );
    }
}
```

- [ ] **Step 4: Write `tasks.xml` and register the tasks**

`tasks.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<concrete5-cif version="1.0">
    <tasks>
        <task handle="cmk_scan_crawl" package="cookie_manager_kit" />
        <task handle="cmk_refresh_classification" package="cookie_manager_kit" />
    </tasks>

    <tasksets>
        <taskset handle="cookie_manager_kit" name="Cookie Manager">
            <task handle="cmk_scan_crawl" />
            <task handle="cmk_refresh_classification" />
        </taskset>
    </tasksets>
</concrete5-cif>
```

In `controller.php` replace `protected $tasks = [];` with:

```php
    protected $tasks = [
        'cmk_scan_crawl' => \CookieManagerKit\Command\Task\Controller\CrawlController::class,
        'cmk_refresh_classification' => \CookieManagerKit\Command\Task\Controller\RefreshClassificationController::class,
    ];
```

and at the start of `installOrUpgrade` body (before the `addSinglePage` calls) add:

```php
        $this->installContentFile('tasks.xml');
```

- [ ] **Step 5: Run the tests**

Run: `composer test`
Expected: PASS (`SourceLayoutTest` sees the new `src/Command` classes).

- [ ] **Step 6: Commit**

```bash
composer format:php && composer typecheck
git add src/Command tasks.xml controller.php tests
git commit -m "feat: add the scheduled crawl and classification refresh tasks

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Documentation, release notes and full verification

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `controller.php` (`$pkgVersion`), `docs/superpowers/specs/2026-10-16-cookie-manager-scanner-design.md`

- [ ] **Step 1: Bump the version**

In `controller.php` set `protected $pkgVersion = '0.3.0';`.

- [ ] **Step 2: Add the changelog entry**

In `CHANGELOG.md`, insert above `## 0.2.0`:

```markdown
## 0.3.0

Phase 2: the scanner.

### Added

- Scanner dashboard pages: Scanner (`/dashboard/cookie_manager/scanner`), Findings (`/findings`) and Scanner Settings (`/scan_settings`).
- A server-side crawl of the published pages (capped, with an exclude list) that records third-party scripts, frames, images and other resources, and the cookies the pages set. It fetches only the site's own canonical host, pins the connection to one DNS answer, follows no redirects and runs no JavaScript.
- Scan mode: an admin starts a 30 minute session in their own browser; a beacon reports cookie names, storage keys and resource hosts (never values) to `POST /cookie-manager/scan/report`. Page HTML stays identical for every visitor.
- A review queue: findings are classified against admin rules, optionally downloaded Open Cookie Database data, and a bundled baseline, in that order. Approving a finding creates or extends a Service; nothing reaches the banner without an admin approving it.
- Scheduled tasks under Automation: "Scan the site for cookies and scripts" and "Update cookie classification data", with an optional plain-text email alert and a warning when no runtime scan has run for a while.

### Upgrade notes

- Run **Update** for the package in the dashboard to create the scanner tables, load the bundled classification data and add the new pages and tasks.
- Set the site's canonical URL (Dashboard > System & Settings > SEO & Statistics > URLs and Redirection) before scheduling a crawl; scheduled runs have no request to take a host from.
- Downloading classification data is off by default. When on, it fetches one public file and sends nothing about your site.
- Sites with a strict Content-Security-Policy must allow same-origin scripts (`script-src 'self'`) for scan mode, because the runtime loads the beacon as a same-origin script.
```

- [ ] **Step 3: Document the scanner in the README**

In `README.md`, add this section after the `## Statistics` section (keep the file's existing heading levels):

```markdown
## Scanner

The scanner finds what your site really sets and loads, suggests a consent category for each thing, and lets you turn the ones you approve into Services. Nothing a scan finds reaches the banner until you approve it.

### What it records

Cookie names, storage keys, and the hosts of scripts, frames, images and other resources. Cookie and storage **values are never read or stored**. Everything it records comes from the public internet, so names and hosts are cleaned, length-capped and always shown encoded.

### Crawl

**Scanner → Run crawl now**, or the scheduled task. The crawl fetches your published pages as an anonymous visitor and lists the third-party hosts each page loads and the cookies each response sets. It runs no JavaScript.

- It only ever requests your site's own canonical host, on the address that host resolved to when the crawl began, and it does not follow redirects. Set the canonical URL (Dashboard → System & Settings → SEO & Statistics → URLs and Redirection); a scheduled run has no request to take a host from.
- It is capped at the page limit in **Scanner Settings** and skips the paths you list there.
- A finding that stops appearing is only marked **Gone** after a crawl that saw everything: every page fetched, none skipped, none cut off by the cap. Otherwise nothing is marked gone.

### Scan mode

A crawl cannot see cookies and storage that scripts create in the browser. Scan mode can.

1. **Scanner → Start scan mode.**
2. In the same browser, open your site, choose **Accept all** on the banner, and browse a few pages.
3. For the next 30 minutes this browser reports cookie names, storage keys and the hosts each page loaded. **Stop scan mode** ends it early.

Only the browser that started scan mode is affected: it holds a signed, HttpOnly token (`cmk_scan_token`) and a marker cookie (`cmk_scan`). Page HTML is identical for everyone, so full-page caching is unaffected, and ordinary visitors make no extra request. A site with a Content-Security-Policy must allow same-origin scripts (`script-src 'self'`), because the runtime loads the beacon as a same-origin script.

### Review queue

**Findings** lists everything found. Each is **Matched** (a category was suggested) or **Unclassified**. For a finding you can:

- **Approve**: create a Service or add it to an existing one. The host is recorded on the Service, and the Service then appears in the banner.
- **Change suggested category**, or **Always classify like this** (saves a rule that beats the built-in data).
- **Ignore** it (and **Review again** later).
- **Approve all matched findings** to create one Service per vendor and category in one go.

### Classification data

Suggestions come from three sources, strongest first: your own rules, the optional downloaded data, and a small baseline that ships with the package. ConcreteCMS's own essential cookies are always suggested as **Necessary**. The optional download is the [Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database) (Apache-2.0); it is off by default, fetches one public file and sends nothing about your site, and is discarded entirely if it does not pass validation.

### Scheduling and alerts

Under **Automation** there are two tasks: **Scan the site for cookies and scripts** and **Update cookie classification data**. Each does nothing unless its switch is ticked in **Scanner Settings**. After a crawl that finds something new or changed, an optional plain-text email (counts and names only) goes to the address you set. The scheduled crawl cannot run scan mode, so the Scanner page and the email also warn when no scan-mode run has happened for the number of days you set.

### Known limitations

- No headless browser: scripts that add cookies later are only found by scan mode.
- No scanning behind logins.
- IPv6-only site hosts are not scanned.
- **Run crawl now** runs inside the request; on a large site use the scheduled task.
```

- [ ] **Step 4: Write the spec adjustments back into the spec**

Edit `docs/superpowers/specs/2026-10-16-cookie-manager-scanner-design.md`:

1. In section 4, replace the line
   `  - Status: \`new\` | \`unclassified\` | \`matched\` | \`approved\` | \`ignored\` | \`gone\`.`
   with
   `  - Status: \`unclassified\` | \`matched\` | \`approved\` | \`ignored\` | \`gone\`. "New" is not a stored status: it means \`firstSeen\` is at or after the last run's start, and is counted in \`ScanRun.newCount\`.`
2. In section 4, replace
   `Unique on \`(kind, name, host)\`.`
   with
   `Identity is \`(kind, name, host)\`, stored as a unique \`fingerprint\` (sha1) because a composite key can exceed index limits. A nullable \`restoreStatus\` remembers the status a \`gone\` finding returns to. For \`script\`, \`iframe\`, \`image\` and \`resource\` findings \`name\` equals \`host\`.`
3. In section 6, replace the heading `## 6. Dashboard (\`/dashboard/cookie_manager/scanner\`)` with
   `## 6. Dashboard (three pages: \`/dashboard/cookie_manager/scanner\`, \`/findings\`, \`/scan_settings\`)`.
4. In section 6, after the `Settings` bullet add the line
   `- "Run crawl now" runs the crawl inside the request (with a 240 s budget); scheduled runs use the Concrete task. Large sites should schedule it.`
5. In section 5.2, replace the bullet that begins `- Page HTML never changes. The page always references the same beacon asset;` with
   `- Page HTML never changes. The runtime (already on every page) adds the beacon script only when the JavaScript-readable marker cookie \`cmk_scan\` is present, which only an admin in scan mode has. The beacon route answers 204 unless the request also carries the signed HttpOnly token, so cached pages stay identical and ordinary visitors make no extra request.`
6. In section 5.2, replace `resource hosts from \`PerformanceObserver\`` with `resource hosts from the Performance API`.
7. In section 5.1, replace the bullet `- Works in batches per task step so a large site does not time out.` with
   `- Runs as one pass with a 240 s time budget and a 2,000,000 byte, 8 s per-page limit. Pages not reached when the budget ends count as failed, so an incomplete crawl never marks anything gone.`

- [ ] **Step 5: Run every automated check**

```bash
composer test
composer typecheck
composer format:check
npx vitest run
npx prettier --check .
```

Expected: all pass. Fix anything they report (formatting with `composer format` and `npx prettier --write .`); do not suppress findings.

- [ ] **Step 6: Commit the docs and the version**

```bash
git add README.md CHANGELOG.md controller.php
git add -f docs/superpowers/specs/2026-10-16-cookie-manager-scanner-design.md
git commit -m "docs: document the scanner and bump the package to 0.3.0

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 7: Verify in a browser on a local Concrete 9.5 site**

A clean test run does not catch runtime-only bugs, so do this before saying the work is done. Ask the person for the local site's URL and admin login if you do not have them. If no local site is available, say so plainly in the final report instead of claiming success.

1. In the dashboard run the package **Update**. Confirm the Scanner, Findings and Scanner Settings pages appear and the two tasks appear under Automation.
2. Open each of the three new pages. Confirm they render and the browser console has no errors.
3. Scanner Settings: save a page cap, an exclude line and an email. Reload and confirm the values stuck and an invalid email is dropped.
4. Scanner: **Run crawl now**. Confirm a run row appears, findings are listed on Findings, and a known tag (for example a Google Fonts link) is matched to its vendor.
5. Scanner: **Start scan mode**. In the same browser, open a public page, accept all on the banner, browse two or three pages, wait a few seconds. Back on Scanner confirm the run's page count rose; on Findings confirm runtime cookies appear with source `beacon`.
6. Confirm **page HTML is identical** with and without scan mode:
   ```bash
   curl -s https://YOUR-SITE/ | shasum
   curl -s -H 'Cookie: cmk_scan=1' https://YOUR-SITE/ | shasum
   ```
   The two hashes must match.
7. Confirm a visitor without the token gets nothing from the beacon route: `curl -si https://YOUR-SITE/cookie-manager/scan/beacon.js` answers `204` with an empty body, and `curl -si -X POST -H 'Content-Type: application/json' -d '{}' https://YOUR-SITE/cookie-manager/scan/report` answers `401` or `403` with `{"error":"invalid"}`.
8. Findings: approve one finding into a new Service; confirm it appears on the Services page and in the banner's preference centre. Ignore another and use "Review again".
9. **Stop scan mode**; confirm the beacon no longer loads (no `beacon.js` request) and the `cmk_scan*` cookies are gone.
10. Run the "Scan the site for cookies and scripts" task from Automation with scheduled scanning switched off (it must say it did nothing), then on (it must crawl and, with an alert email set and something new, send one).
11. Check the browser console and the `cookie_manager` log for errors throughout.

Report what you checked and anything you could not.
