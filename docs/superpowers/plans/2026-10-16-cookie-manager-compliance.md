# CookieManagerKit Phase 3 (Compliance) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship 0.4.0: a persistent consent audit log with a constant per-browser consent ID (3a), reasoned and prompted re-consent (3d), Google Consent Mode v2 (3b), and a site-wide opt-in/opt-out default with GPC (3c).

**Architecture:** Logic lives in small pure classes and ports (interfaces) so it can be unit-tested without a database, the same way the scanner does (`FindingStore` / `InMemoryFindingStore`). Doctrine adapters are thin and tested with an EntityManager double. The browser side is the existing inline loader (`js/cmk-loader.js`, owns the cookie and Consent Mode) and runtime (`js/cmk-runtime.js`, owns the banner and the POST). Dashboard pages follow the existing controller and view conventions that `ControllerSecurityTest` and `ViewEscapingTest` police.

**Tech Stack:** PHP 8.4, ConcreteCMS 9.5, Doctrine ORM annotations, ClassKit entity bases, PHPUnit 11, Vitest 3 + jsdom, PHPStan, php-cs-fixer, Prettier.

**Spec:** `docs/superpowers/specs/2026-10-16-cookie-manager-compliance-design.md` (read it first; section numbers below refer to it).

## Global Constraints

- Tests: `composer test` (PHPUnit), `npx vitest run` (JS), `composer typecheck`, `composer format:check`. Run `composer format` before each commit if `format:check` fails.
- No raw IP, IP hash, region or raw user agent is ever stored, logged or exported. (Spec §2, §9)
- The consent ID is the GUID of `ConsentSubject` and is constant across changes; `ConsentLogEntry` rows are append-only and never updated. (Spec §4, §5)
- Consent GUID regex everywhere: `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$` (input is lowercased first on the server).
- Trigger allowlist, in one place (`ConsentTrigger`): `accept_all`, `reject_all`, `custom`, `gpc`, `reconsent`.
- The consent endpoint answers an identical shape for known, unknown and absent `i`, never reflects request data, and a storage failure never changes the visitor's experience. (Spec §3.3, §5, §9)
- Page HTML stays byte-identical for every visitor; all per-visitor behaviour is decided in the browser from the cookie. (Spec §3.7)
- Every dynamic value in a view goes through `O::e()` (`CookieManagerKit\Support\Output as O`); admin JS and runtime use `textContent` only; no `innerHTML`.
- CSV cells starting with `=`, `+`, `-`, `@`, tab or CR are prefixed with `'`; fields quoted; `Content-Disposition: attachment`; `X-Content-Type-Options: nosniff`.
- Re-consent is never automatic; policy version bumps require a reason of 1 to 500 characters.
- Consent Mode signal mapping (fixed): necessary → `security_storage`; preferences → `functionality_storage`, `personalization_storage`; analytics → `analytics_storage`; marketing → `ad_storage`, `ad_user_data`, `ad_personalization`. `wait_for_update: 500`.
- Defaults: `consentLogRetentionMonths` 24 (clamp 1 to 120), `consentModeEnabled` false, `defaultModel` `opt_in`.
- Table names: `cmkConsentSubjects`, `cmkConsentLog`, `cmkPolicyChanges`. Do not use `trigger` as a column name (MySQL reserved word); the column is `triggerName`.
- Version `0.4.0` in `controller.php`. Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Deviation from the spec text, deliberate: the Consent Mode mapping lives in one JavaScript object in the loader (the browser is where it runs), not a PHP class. Spec §12 should be read that way.
- Deviation: an unknown or malformed `l` (locale) or `i` rejects the whole consent body with 400, matching the existing "any deviation rejects the whole body" rule of `ConsentRequest`.

## Review Focus

1. A visitor whose cookie holds an `i` for a subject that retention already purged must get a fresh subject and a normal 200, not a 500 (Task 3).
2. Before the package Update has created the tables, the consent endpoint must still answer `{"ok":true}` and must not flood the log (Task 3).
3. A cookie with a stale policy version keeps its `i`, so the re-consent entry attaches to the same subject (Task 4).
4. A hostile or malformed `i` in the cookie or the request is dropped or rejected, never echoed back (Tasks 2 and 4).
5. The reason column in the CSV is free text that can start with `=`; it must be neutralised (Task 6). A consent-ID search that is not a GUID must be ignored and shown encoded, not run as a query (Task 6).

Each is pinned by a named test in the owning task.

---

## File Structure

**Create**
- `src/Entity/ConsentSubject.php`, `src/Entity/ConsentLogEntry.php`, `src/Entity/PolicyVersionChange.php`: the three tables.
- `src/Consent/ConsentTrigger.php`: trigger allowlist.
- `src/Consent/UserAgentFamily.php`: coarse `<browser>/<device>` bucket from a raw UA.
- `src/Consent/ConsentLogStore.php` (port), `src/Consent/DoctrineConsentLogStore.php`, `src/Consent/PersistingConsentRecorder.php`, `src/Consent/ConsentReply.php`.
- `src/Consent/ConsentLogFilter.php`, `src/Consent/ConsentLogReader.php` (port), `src/Consent/DoctrineConsentLogReader.php`, `src/Consent/ConsentLogCsv.php`, `src/Consent/ConsentLogRetention.php`.
- `src/Support/CsvCell.php`: formula neutralising.
- `src/Support/PolicyVersions.php`: bump with a reason.
- `src/Command/Task/PurgeConsentLogCommand.php`, `PurgeConsentLogCommandHandler.php`, `src/Command/Task/Controller/PurgeConsentLogController.php`.
- `controllers/single_page/dashboard/cookie_manager/consent_log.php`, `single_pages/dashboard/cookie_manager/consent_log.php`.
- Tests mirror each file under `tests/` (named in each task); `tests/Consent/InMemoryConsentLogStore.php`.

**Modify**
- `src/Consent/ConsentChoice.php`, `ConsentRecorder.php`, `NullConsentRecorder.php`
- `src/Support/ConsentRequest.php`, `Catalog.php`, `ConfigBuilder.php`, `LoaderScript.php`, `Installer.php`
- `src/Entity/Settings.php`
- `src/Ajax/Consent.php`, `src/Events/ConsentManager.php`
- `controller.php`, `tasks.xml`
- `controllers/single_page/dashboard/cookie_manager/banner.php`, `findings.php`, and their views in `single_pages/dashboard/cookie_manager/`
- `elements/dashboard/uninstall.php`
- `js/cmk-loader.js`, `js/cmk-runtime.js`
- `CHANGELOG.md`, `README.md`

---

### Task 1: Entities, settings fields, table list

**Files:**
- Create: `src/Entity/ConsentSubject.php`, `src/Entity/ConsentLogEntry.php`, `src/Entity/PolicyVersionChange.php`, `src/Consent/ConsentTrigger.php`
- Modify: `src/Entity/Settings.php`, `controller.php:105-116`
- Test: `tests/Entity/ConsentEntitiesTest.php`, `tests/Entity/SettingsComplianceTest.php`

**Interfaces:**
- Produces:
  - `ConsentTrigger::ALL` (list), `ConsentTrigger::isValid(mixed): bool`, constants `ACCEPT_ALL REJECT_ALL CUSTOM GPC RECONSENT`.
  - `ConsentSubject extends UpdatedGuidEntity`: no extra fields.
  - `ConsentLogEntry extends BaseEntity`: `setSubject(ConsentSubject): self`, `getSubject()`, `setPolicyVersion(int)`, `getPolicyVersion(): int`, `setCategories(array): self` (stores JSON), `getCategories(): array`, `setTrigger(string): self` (falls back to `custom` when not allowlisted), `getTrigger(): string`, `setLocale(string)`, `getLocale()`, `setUserAgentFamily(string)`, `getUserAgentFamily()`, `setCreatedAt(int)`, `getCreatedAt(): int`.
  - `PolicyVersionChange extends BaseEntity`: `setVersion(int)`, `getVersion()`, `setReason(string): self` (throws `\InvalidArgumentException` when empty after `Text::plain($v, 500)`), `getReason()`, `setAdminUserId(int)`, `getAdminUserId()`, `setCreatedAt(int)`, `getCreatedAt()`.
  - `Settings`: `getConsentLogRetentionMonths(): int`, `setConsentLogRetentionMonths($v): self`, `isConsentModeEnabled(): bool`, `setConsentModeEnabled($v): self`, `getDefaultModel(): string` (`opt_in` | `opt_out`), `setDefaultModel($v): self`.

- [ ] **Step 1: Create the branch**

```bash
git switch develop && git switch -c feature/compliance
```

- [ ] **Step 2: Write the failing entity tests**

`tests/Entity/ConsentEntitiesTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentTrigger;
use CookieManagerKit\Entity\{ConsentLogEntry, ConsentSubject, PolicyVersionChange};

final class ConsentEntitiesTest extends TestCase
{
    public function testTriggerAllowlist(): void
    {
        $this->assertSame(['accept_all', 'reject_all', 'custom', 'gpc', 'reconsent'], ConsentTrigger::ALL);
        $this->assertTrue(ConsentTrigger::isValid('gpc'));
        $this->assertFalse(ConsentTrigger::isValid('changed'));
        $this->assertFalse(ConsentTrigger::isValid('<script>'));
        $this->assertFalse(ConsentTrigger::isValid(['gpc']));
        $this->assertFalse(ConsentTrigger::isValid(null));
    }

    public function testALogEntryStoresCategoriesAsAList(): void
    {
        $entry = (new ConsentLogEntry())
            ->setSubject(new ConsentSubject())
            ->setPolicyVersion(3)
            ->setCategories(['necessary', 'analytics'])
            ->setTrigger('accept_all')
            ->setLocale('en_GB')
            ->setUserAgentFamily('Chrome/desktop')
            ->setCreatedAt(1700000000);

        $this->assertSame(['necessary', 'analytics'], $entry->getCategories());
        $this->assertSame(3, $entry->getPolicyVersion());
        $this->assertSame('accept_all', $entry->getTrigger());
        $this->assertSame('Chrome/desktop', $entry->getUserAgentFamily());
        $this->assertSame(1700000000, $entry->getCreatedAt());
    }

    public function testAnUnknownTriggerBecomesCustom(): void
    {
        $this->assertSame('custom', (new ConsentLogEntry())->setTrigger('"><script>')->getTrigger());
    }

    public function testCorruptStoredCategoriesReadAsEmpty(): void
    {
        $entry = new ConsentLogEntry();
        $property = new \ReflectionProperty(ConsentLogEntry::class, 'categories');
        $property->setValue($entry, '{not json');

        $this->assertSame([], $entry->getCategories());
    }

    public function testAPolicyChangeNeedsAReasonAndCapsItsLength(): void
    {
        $change = (new PolicyVersionChange())->setVersion(2)->setReason(str_repeat('a', 900))->setAdminUserId(1)->setCreatedAt(5);

        $this->assertSame(500, mb_strlen($change->getReason()));
        $this->assertSame(2, $change->getVersion());

        $this->expectException(\InvalidArgumentException::class);
        (new PolicyVersionChange())->setReason("  \n ");
    }

    public function testAReasonIsStoredAsPlainText(): void
    {
        $change = (new PolicyVersionChange())->setReason("Added <b>GA</b>\x00 to analytics");

        $this->assertStringNotContainsString("\x00", $change->getReason());
    }
}
```

`tests/Entity/SettingsComplianceTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Settings;

final class SettingsComplianceTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame(24, $settings->getConsentLogRetentionMonths());
        $this->assertFalse($settings->isConsentModeEnabled());
        $this->assertSame('opt_in', $settings->getDefaultModel());
    }

    public function testRetentionIsClamped(): void
    {
        $settings = new Settings();

        $this->assertSame(1, $settings->setConsentLogRetentionMonths(0)->getConsentLogRetentionMonths());
        $this->assertSame(120, $settings->setConsentLogRetentionMonths(9999)->getConsentLogRetentionMonths());
        $this->assertSame(24, $settings->setConsentLogRetentionMonths('abc')->getConsentLogRetentionMonths());
        $this->assertSame(36, $settings->setConsentLogRetentionMonths('36')->getConsentLogRetentionMonths());
    }

    public function testOnlyTwoModelsExist(): void
    {
        $settings = new Settings();

        $this->assertSame('opt_out', $settings->setDefaultModel('opt_out')->getDefaultModel());
        $this->assertSame('opt_in', $settings->setDefaultModel('"><script>')->getDefaultModel());
        $this->assertSame('opt_in', $settings->setDefaultModel(null)->getDefaultModel());
    }

    public function testConsentModeToggle(): void
    {
        $this->assertTrue((new Settings())->setConsentModeEnabled('1')->isConsentModeEnabled());
        $this->assertFalse((new Settings())->setConsentModeEnabled(null)->isConsentModeEnabled());
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `composer test -- --filter 'ConsentEntitiesTest|SettingsComplianceTest'`
Expected: FAIL (classes and methods not found).

- [ ] **Step 4: Implement `ConsentTrigger`**

`src/Consent/ConsentTrigger.php`:

```php
<?php

namespace CookieManagerKit\Consent;

/**
 * The only values a consent log entry's trigger may take. One list, so the endpoint, the entity and the dashboard
 * filter cannot drift apart.
 */
final class ConsentTrigger
{
    public const ACCEPT_ALL = 'accept_all';
    public const REJECT_ALL = 'reject_all';
    public const CUSTOM = 'custom';
    public const GPC = 'gpc';
    public const RECONSENT = 'reconsent';
    public const ALL = [self::ACCEPT_ALL, self::REJECT_ALL, self::CUSTOM, self::GPC, self::RECONSENT];

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::ALL, true);
    }
}
```

- [ ] **Step 5: Implement the entities**

`src/Entity/ConsentSubject.php`:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\UpdatedGuidEntity;

/**
 * One browser. Its GUID is the visitor's consent ID, kept in the consent cookie. `date_updated` is the time of the
 * latest decision and drives retention. Holds nothing else: no address, no user agent.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkConsentSubjects",
 *      options={"comment": "Cookie Manager consent log: one row per browser"}
 * )
 */
class ConsentSubject extends UpdatedGuidEntity {}
```

`src/Entity/ConsentLogEntry.php`:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\BaseEntity;
use CookieManagerKit\Consent\ConsentTrigger;

/**
 * One decision by one browser. Append-only: it is never updated. Everything in it came from a public request and
 * was validated against an allowlist before it got here; it is still encoded wherever it is shown.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkConsentLog",
 *      indexes={
 *          @ORM\Index(name="idx_cmk_log_subject", columns={"subjectID"}),
 *          @ORM\Index(name="idx_cmk_log_created", columns={"createdAt"})
 *      },
 *      options={"comment": "Cookie Manager consent log: one row per decision"}
 * )
 */
class ConsentLogEntry extends BaseEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="CookieManagerKit\Entity\ConsentSubject")
     * @ORM\JoinColumn(name="subjectID", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $subject;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 1, "comment": "Policy version the choice was made under"})
     */
    protected $policyVersion = 1;

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "JSON list of category handles"})
     */
    protected $categories = '[]';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "custom", "comment": "accept_all, reject_all, custom, gpc or reconsent"})
     */
    protected $triggerName = ConsentTrigger::CUSTOM;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"default": "", "comment": "Site locale of the banner"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"default": "", "comment": "Coarse browser/device bucket, never the raw user agent"})
     */
    protected $userAgentFamily = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "UTC unix seconds"})
     */
    protected $createdAt = 0;

    public function setSubject(ConsentSubject $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getSubject(): ?ConsentSubject
    {
        return $this->subject;
    }

    public function setPolicyVersion(int $version): self
    {
        $this->policyVersion = max(1, $version);

        return $this;
    }

    public function getPolicyVersion(): int
    {
        return max(1, (int) $this->policyVersion);
    }

    /**
     * @param list<string> $handles
     */
    public function setCategories(array $handles): self
    {
        $this->categories = json_encode(array_values(array_map('strval', $handles)), JSON_THROW_ON_ERROR);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getCategories(): array
    {
        $decoded = json_decode((string) $this->categories, true);

        return is_array($decoded) && array_is_list($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    public function setTrigger(string $trigger): self
    {
        $this->triggerName = ConsentTrigger::isValid($trigger) ? $trigger : ConsentTrigger::CUSTOM;

        return $this;
    }

    public function getTrigger(): string
    {
        return (string) $this->triggerName;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = mb_substr($locale, 0, 32);

        return $this;
    }

    public function getLocale(): string
    {
        return (string) $this->locale;
    }

    public function setUserAgentFamily(string $family): self
    {
        $this->userAgentFamily = mb_substr($family, 0, 32);

        return $this;
    }

    public function getUserAgentFamily(): string
    {
        return (string) $this->userAgentFamily;
    }

    public function setCreatedAt(int $seconds): self
    {
        $this->createdAt = max(0, $seconds);

        return $this;
    }

    public function getCreatedAt(): int
    {
        return (int) $this->createdAt;
    }
}
```

`src/Entity/PolicyVersionChange.php`:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\BaseEntity;
use CookieManagerKit\Support\Text;

/**
 * Why a policy version exists. Append-only. The reason is admin-typed (or pre-filled from a scanner finding, which
 * came from the public internet), so it is plain text, length-capped here and encoded wherever it is shown.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkPolicyChanges",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_policy_version", columns={"version"})},
 *      options={"comment": "Cookie Manager policy version history"}
 * )
 */
class PolicyVersionChange extends BaseEntity
{
    public const MAX_REASON = 500;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 1, "comment": "The policy version this change created"})
     */
    protected $version = 1;

    /**
     * @ORM\Column(type="string", length=500, nullable=false, options={"default": "", "comment": "Why visitors were asked again"})
     */
    protected $reason = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Admin user who made the change, 0 for the system"})
     */
    protected $adminUserId = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "UTC unix seconds"})
     */
    protected $createdAt = 0;

    public function setVersion(int $version): self
    {
        $this->version = max(1, $version);

        return $this;
    }

    public function getVersion(): int
    {
        return max(1, (int) $this->version);
    }

    /**
     * @throws \InvalidArgumentException when nothing is left after cleaning
     */
    public function setReason(string $reason): self
    {
        $clean = Text::plain($reason, self::MAX_REASON);

        if ($clean === '') {
            throw new \InvalidArgumentException('A reason is required.');
        }

        $this->reason = $clean;

        return $this;
    }

    public function getReason(): string
    {
        return (string) $this->reason;
    }

    public function setAdminUserId(int $id): self
    {
        $this->adminUserId = max(0, $id);

        return $this;
    }

    public function getAdminUserId(): int
    {
        return (int) $this->adminUserId;
    }

    public function setCreatedAt(int $seconds): self
    {
        $this->createdAt = max(0, $seconds);

        return $this;
    }

    public function getCreatedAt(): int
    {
        return (int) $this->createdAt;
    }
}
```

- [ ] **Step 6: Add the settings fields**

In `src/Entity/Settings.php`, add after the `$classificationRefresh` property block (search for `protected $classificationRefresh`) three properties, and the methods at the end of the class:

```php
    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 24, "comment": "Months a browser's consent history is kept after its last decision"})
     */
    protected $consentLogRetentionMonths = 24;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false, "comment": "Emit Google Consent Mode v2 signals"})
     */
    protected $consentMode = false;

    /**
     * @ORM\Column(type="string", length=8, nullable=false, options={"default": "opt_in", "comment": "opt_in or opt_out"})
     */
    protected $defaultModel = 'opt_in';
```

```php
    public function getConsentLogRetentionMonths(): int
    {
        return self::clampInt($this->consentLogRetentionMonths, 1, 120, 24);
    }

    public function setConsentLogRetentionMonths($value): self
    {
        $this->consentLogRetentionMonths = self::clampInt($value, 1, 120, 24);

        return $this;
    }

    public function isConsentModeEnabled(): bool
    {
        return (bool) $this->consentMode;
    }

    public function setConsentModeEnabled($value): self
    {
        $this->consentMode = (bool) $value;

        return $this;
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel === 'opt_out' ? 'opt_out' : 'opt_in';
    }

    public function setDefaultModel($value): self
    {
        $this->defaultModel = $value === 'opt_out' ? 'opt_out' : 'opt_in';

        return $this;
    }
```

Check that `clampInt` returns the default for non-numeric input (it is private static in `Settings`; read it at `src/Entity/Settings.php` near line 123). If it does not, make the `'abc'` expectation match what it does and keep 24 as the default.

- [ ] **Step 7: Add the tables to the uninstall list**

In `controller.php`, change `$databaseTables` so children come first:

```php
    protected $databaseTables = [
        'cmkConsentLog',
        'cmkConsentSubjects',
        'cmkPolicyChanges',
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

- [ ] **Step 8: Run tests**

Run: `composer test && composer typecheck`
Expected: PASS (all green, including `SourceLayoutTest`).

- [ ] **Step 9: Commit**

```bash
composer format
git add src/Entity src/Consent/ConsentTrigger.php controller.php tests/Entity
git commit -m "feat: add the consent log and policy history entities and the compliance settings

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Request parsing, trigger and user agent bucket

**Files:**
- Create: `src/Consent/UserAgentFamily.php`
- Modify: `src/Support/ConsentRequest.php`
- Test: `tests/Consent/UserAgentFamilyTest.php`, `tests/Support/ConsentRequestTest.php` (modify existing expectations, add cases)

**Interfaces:**
- Consumes: `ConsentTrigger` (Task 1), `Text::isLocale`.
- Produces:
  - `UserAgentFamily::of(?string $userAgent): string`, always matches `^[A-Za-z]+/(desktop|mobile|tablet|unknown)\z` and is at most 32 chars.
  - `ConsentRequest::parse(?string $contentType, string $body, array $knownHandles, array $knownLocales = []): ?array` returning `array{v: int, c: list<string>, i: ?string, trigger: string, locale: string}`. Accepted body keys: required `c`, `v`; optional `i` (GUID), `g` (trigger), `l` (locale).

- [ ] **Step 1: Write the failing tests**

`tests/Consent/UserAgentFamilyTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\UserAgentFamily;
use PHPUnit\Framework\Attributes\DataProvider;

final class UserAgentFamilyTest extends TestCase
{
    /**
     * @return array<string, array{?string, string}>
     */
    public static function agents(): array
    {
        return [
            'chrome desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 'Chrome/desktop'],
            'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0', 'Edge/desktop'],
            'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0', 'Firefox/desktop'],
            'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari/mobile'],
            'chrome android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36', 'Chrome/mobile'],
            'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari/tablet'],
            'opera' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 OPR/111.0.0.0', 'Opera/desktop'],
            'unknown agent' => ['curl/8.4.0', 'Other/desktop'],
            'null' => [null, 'Other/unknown'],
            'empty' => ['', 'Other/unknown'],
        ];
    }

    #[DataProvider('agents')]
    public function testBuckets(?string $agent, string $expected): void
    {
        $this->assertSame($expected, UserAgentFamily::of($agent));
    }

    public function testNeverEchoesTheRawAgent(): void
    {
        $family = UserAgentFamily::of('"><script>alert(1)</script> Chrome/1 ' . str_repeat('x', 5000));

        $this->assertMatchesRegularExpression('#^[A-Za-z]+/(desktop|mobile|tablet|unknown)\z#', $family);
        $this->assertLessThanOrEqual(32, strlen($family));
    }
}
```

Add to `tests/Support/ConsentRequestTest.php` (and fix the two existing exact-shape assertions, see Step 4):

```php
    private const GUID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    public function testTheNewFieldsAreOptionalAndDefaulted(): void
    {
        $parsed = ConsentRequest::parse('application/json', '{"v":2,"c":["analytics"]}', self::KNOWN);

        $this->assertSame(['v' => 2, 'c' => ['necessary', 'analytics'], 'i' => null, 'trigger' => 'custom', 'locale' => ''], $parsed);
    }

    public function testTheTriggerIsDerivedWhenAbsent(): void
    {
        $all = ConsentRequest::parse('application/json', '{"v":1,"c":["preferences","analytics","marketing"]}', self::KNOWN);
        $none = ConsentRequest::parse('application/json', '{"v":1,"c":[]}', self::KNOWN);

        $this->assertSame('accept_all', $all['trigger']);
        $this->assertSame('reject_all', $none['trigger']);
    }

    public function testAcceptsAnIdTriggerAndLocale(): void
    {
        $body = '{"v":2,"c":["analytics"],"i":"' . strtoupper(self::GUID) . '","g":"reconsent","l":"en_GB"}';
        $parsed = ConsentRequest::parse('application/json', $body, self::KNOWN, ['en_GB', 'fr_FR']);

        $this->assertSame(self::GUID, $parsed['i']);
        $this->assertSame('reconsent', $parsed['trigger']);
        $this->assertSame('en_GB', $parsed['locale']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileExtras(): array
    {
        return [
            'id not a guid' => ['{"v":1,"c":[],"i":"abc"}'],
            'id with markup' => ['{"v":1,"c":[],"i":"<script>alert(1)</script>"}'],
            'id too long' => ['{"v":1,"c":[],"i":"' . str_repeat('a', 300) . '"}'],
            'id not a string' => ['{"v":1,"c":[],"i":5}'],
            'trigger unknown' => ['{"v":1,"c":[],"g":"changed"}'],
            'trigger markup' => ['{"v":1,"c":[],"g":"\"><img src=x>"}'],
            'trigger list' => ['{"v":1,"c":[],"g":["gpc"]}'],
            'locale markup' => ['{"v":1,"c":[],"l":"<b>"}'],
            'locale not a site locale' => ['{"v":1,"c":[],"l":"xx_XX"}'],
            'locale not a string' => ['{"v":1,"c":[],"l":7}'],
        ];
    }

    #[DataProvider('hostileExtras')]
    public function testRejectsHostileOptionalFields(string $body): void
    {
        $this->assertNull(ConsentRequest::parse('application/json', $body, self::KNOWN, ['en_GB']));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'UserAgentFamilyTest|ConsentRequestTest'`
Expected: FAIL.

- [ ] **Step 3: Implement `UserAgentFamily`**

`src/Consent/UserAgentFamily.php`:

```php
<?php

namespace CookieManagerKit\Consent;

/**
 * Turns a raw User-Agent into a coarse "<browser>/<device>" bucket from fixed lists. The raw string is never kept:
 * it is long, unique-ish and hostile (it comes straight from the request).
 */
final class UserAgentFamily
{
    public static function of(?string $userAgent): string
    {
        $agent = $userAgent === null ? '' : substr($userAgent, 0, 512);

        if ($agent === '') {
            return 'Other/unknown';
        }

        return self::browser($agent) . '/' . self::device($agent);
    }

    private static function browser(string $agent): string
    {
        // Order matters: Edge and Opera also say "Chrome", and Chrome also says "Safari".
        foreach ([
            'Edge' => '#\b(?:Edg|EdgA|EdgiOS|Edge)/#',
            'Opera' => '#\b(?:OPR|Opera)/#',
            'Firefox' => '#\b(?:Firefox|FxiOS)/#',
            'Chrome' => '#\b(?:Chrome|CriOS)/#',
            'Safari' => '#\bSafari/#',
        ] as $name => $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                return $name;
            }
        }

        return 'Other';
    }

    private static function device(string $agent): string
    {
        if (preg_match('#iPad|Tablet|Android(?!.*Mobile)#i', $agent) === 1) {
            return 'tablet';
        }

        if (preg_match('#Mobi|iPhone|iPod|Android#i', $agent) === 1) {
            return 'mobile';
        }

        return 'desktop';
    }
}
```

- [ ] **Step 4: Extend `ConsentRequest::parse`**

Replace the signature, keys check and return in `src/Support/ConsentRequest.php`:

```php
use CookieManagerKit\Consent\ConsentTrigger;
```
(add under the namespace line), then:

```php
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /**
     * @param list<string> $knownHandles
     * @param list<string> $knownLocales the site's locales; empty means "any well-formed locale"
     *
     * @return array{v: int, c: list<string>, i: ?string, trigger: string, locale: string}|null
     */
    public static function parse(?string $contentType, string $body, array $knownHandles, array $knownLocales = []): ?array
```

Replace the key check block:

```php
        $keys = array_keys($data);
        sort($keys);

        if ($keys !== ['c', 'v']) {
```
with:

```php
        if (!isset($data['c'], $data['v']) && !(array_key_exists('c', $data) && array_key_exists('v', $data))) {
            return null;
        }

        if (array_diff(array_keys($data), ['c', 'v', 'i', 'g', 'l']) !== []) {
```

(the body that follows, `return null; }`, stays.) After the categories loop and before the final `return`, add:

```php
        $id = null;

        if (array_key_exists('i', $data)) {
            $raw = $data['i'];

            if (!is_string($raw) || strlen($raw) !== 36 || preg_match(self::GUID, strtolower($raw)) !== 1) {
                return null;
            }

            $id = strtolower($raw);
        }

        $locale = '';

        if (array_key_exists('l', $data)) {
            $raw = $data['l'];

            if (!Text::isLocale($raw) || ($knownLocales !== [] && !in_array($raw, $knownLocales, true))) {
                return null;
            }

            $locale = $raw;
        }

        if (array_key_exists('g', $data)) {
            if (!ConsentTrigger::isValid($data['g'])) {
                return null;
            }

            $trigger = $data['g'];
        } else {
            $trigger = count($accepted) === 1
                ? ConsentTrigger::REJECT_ALL
                : (array_diff($knownHandles, $accepted) === [] ? ConsentTrigger::ACCEPT_ALL : ConsentTrigger::CUSTOM);
        }

        return ['v' => $version, 'c' => $accepted, 'i' => $id, 'trigger' => $trigger, 'locale' => $locale];
```

and delete the old `return ['v' => $version, 'c' => $accepted];`. The `isset($data['c'], $data['v'])` line is redundant with the `array_key_exists` pair; keep only the `array_key_exists` pair:

```php
        if (!array_key_exists('c', $data) || !array_key_exists('v', $data)) {
            return null;
        }
```

Update the existing test `testAcceptsAValidBodyAndAlwaysIncludesNecessary` to expect `['v' => 2, 'c' => ['necessary', 'analytics'], 'i' => null, 'trigger' => 'custom', 'locale' => '']`. `testDeduplicatesAndKeepsNecessaryOnce` reads only `['c']` and is unchanged. The existing rejected case `'unknown key'` (`"extra":true`) still rejects.

- [ ] **Step 5: Run tests**

Run: `composer test -- --filter 'UserAgentFamilyTest|ConsentRequestTest' && composer typecheck`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
composer format
git add src/Consent/UserAgentFamily.php src/Support/ConsentRequest.php tests
git commit -m "feat: accept and vet a consent ID, trigger and locale on the consent request

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Recording choices (store, recorder, endpoint)

**Files:**
- Create: `src/Consent/ConsentLogStore.php`, `src/Consent/DoctrineConsentLogStore.php`, `src/Consent/PersistingConsentRecorder.php`, `src/Consent/ConsentReply.php`, `tests/Consent/InMemoryConsentLogStore.php`
- Modify: `src/Consent/ConsentChoice.php`, `src/Consent/ConsentRecorder.php`, `src/Consent/NullConsentRecorder.php`, `src/Ajax/Consent.php`, `controller.php:224-228`
- Test: `tests/Consent/PersistingConsentRecorderTest.php`, `tests/Consent/DoctrineConsentLogStoreTest.php`, `tests/Consent/ConsentReplyTest.php`

**Interfaces:**
- Consumes: Tasks 1 and 2.
- Produces:
  - `ConsentChoice::__construct(int $policyVersion, array $categories, string $trigger = 'custom', string $locale = '', string $userAgentFamily = 'Other/unknown', ?string $subjectId = null)` with public readonly properties of the same names.
  - `ConsentRecorder::record(ConsentChoice $choice): ?string` (consent ID or null).
  - `ConsentLogStore::append(ConsentChoice $choice, \DateTimeImmutable $now): string` (returns the subject GUID; always returns an ID: an unknown `subjectId` creates a new subject).
  - `PersistingConsentRecorder::__construct(ConsentLogStore $store, ?\Closure $clock = null)`.
  - `ConsentReply::body(int $status, ?string $id): array`.

- [ ] **Step 1: Write the failing tests**

`tests/Consent/InMemoryConsentLogStore.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use CookieManagerKit\Consent\{ConsentChoice, ConsentLogStore};

/**
 * Same contract as the Doctrine store: an unknown or absent subject id creates a new subject.
 */
final class InMemoryConsentLogStore implements ConsentLogStore
{
    /** @var array<string, int> subject id => last decision (unix seconds) */
    public array $subjects = [];

    /** @var list<array{subject: string, choice: ConsentChoice, at: int}> */
    public array $entries = [];

    private int $counter = 0;

    public function append(ConsentChoice $choice, \DateTimeImmutable $now): string
    {
        $id = $choice->subjectId;

        if ($id === null || !isset($this->subjects[$id])) {
            $id = sprintf('00000000-0000-4000-8000-%012d', ++$this->counter);
        }

        $this->subjects[$id] = $now->getTimestamp();
        $this->entries[] = ['subject' => $id, 'choice' => $choice, 'at' => $now->getTimestamp()];

        return $id;
    }
}
```

`tests/Consent/PersistingConsentRecorderTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Exception\TableNotFoundException;
use CookieManagerKit\Consent\{ConsentChoice, ConsentLogStore, PersistingConsentRecorder};

final class PersistingConsentRecorderTest extends TestCase
{
    private function choice(?string $subject = null, array $categories = ['necessary'], string $trigger = 'reject_all'): ConsentChoice
    {
        return new ConsentChoice(2, $categories, $trigger, 'en_GB', 'Chrome/desktop', $subject);
    }

    public function testTheFirstChoiceCreatesASubjectAndReturnsItsId(): void
    {
        $store = new InMemoryConsentLogStore();
        $id = (new PersistingConsentRecorder($store))->record($this->choice());

        $this->assertNotNull($id);
        $this->assertCount(1, $store->subjects);
        $this->assertCount(1, $store->entries);
        $this->assertSame($id, $store->entries[0]['subject']);
    }

    public function testALaterChoiceKeepsTheSameIdAndAppendsAnEntry(): void
    {
        $store = new InMemoryConsentLogStore();
        $recorder = new PersistingConsentRecorder($store);

        $first = $recorder->record($this->choice());
        $second = $recorder->record($this->choice($first, ['necessary', 'analytics'], 'custom'));

        $this->assertSame($first, $second);
        $this->assertCount(1, $store->subjects);
        $this->assertCount(2, $store->entries);
        $this->assertSame(['necessary', 'analytics'], $store->entries[1]['choice']->categories);
    }

    public function testAnUnknownIdCreatesANewSubject(): void
    {
        // Review focus 1: the subject was purged by retention, or the id was made up.
        $store = new InMemoryConsentLogStore();
        $id = (new PersistingConsentRecorder($store))->record($this->choice('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        $this->assertNotNull($id);
        $this->assertNotSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $id);
        $this->assertCount(1, $store->subjects);
    }

    public function testAStorageFailureReturnsNullAndDoesNotThrow(): void
    {
        $store = new class implements ConsentLogStore {
            public function append(ConsentChoice $choice, \DateTimeImmutable $now): string
            {
                throw new \RuntimeException('disk full');
            }
        };

        $this->assertNull((new PersistingConsentRecorder($store))->record($this->choice()));
    }

    public function testAMissingTableIsSilentBeforeTheUpdateHasRun(): void
    {
        // Review focus 2.
        $driver = new class('no such table') extends \Exception implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string
            {
                return null;
            }
        };
        $store = new class(new TableNotFoundException($driver, null)) implements ConsentLogStore {
            public function __construct(private \Throwable $e) {}

            public function append(ConsentChoice $choice, \DateTimeImmutable $now): string
            {
                throw $this->e;
            }
        };

        $this->assertNull((new PersistingConsentRecorder($store))->record($this->choice()));
    }
}
```

`tests/Consent/DoctrineConsentLogStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use CookieManagerKit\Consent\{ConsentChoice, DoctrineConsentLogStore};
use CookieManagerKit\Entity\{ConsentLogEntry, ConsentSubject};

final class DoctrineConsentLogStoreTest extends TestCase
{
    /**
     * @return array{0: EntityManagerInterface, 1: \ArrayObject}
     */
    private function em(?ConsentSubject $existing): array
    {
        $persisted = new \ArrayObject();
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->willReturn($existing);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('wrapInTransaction')->willReturnCallback(static fn(callable $fn) => $fn());
        $em->method('persist')->willReturnCallback(static function (object $entity) use ($persisted): void {
            $persisted->append($entity);
        });
        // The database assigns a subject's GUID when the row is inserted, which is on flush.
        $em->method('flush')->willReturnCallback(static function () use ($persisted): void {
            foreach ($persisted as $entity) {
                if ($entity instanceof ConsentSubject && !(new \ReflectionProperty($entity, 'id'))->isInitialized($entity)) {
                    (new \ReflectionProperty($entity, 'id'))->setValue($entity, '3f2504e0-4f89-11d3-9a0c-0305e82c3301');
                }
            }
        });

        return [$em, $persisted];
    }

    public function testANewBrowserGetsASubjectAndAnEntry(): void
    {
        [$em, $persisted] = $this->em(null);
        $id = (new DoctrineConsentLogStore($em))->append(
            new ConsentChoice(4, ['necessary', 'analytics'], 'custom', 'en_GB', 'Firefox/desktop', null),
            new \DateTimeImmutable('2026-10-16 12:00:00 UTC'),
        );

        $this->assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $id);
        $classes = array_map(static fn(object $o): string => $o::class, $persisted->getArrayCopy());
        $this->assertSame([ConsentSubject::class, ConsentLogEntry::class], $classes);

        $entry = $persisted[1];
        $this->assertSame(4, $entry->getPolicyVersion());
        $this->assertSame(['necessary', 'analytics'], $entry->getCategories());
        $this->assertSame('custom', $entry->getTrigger());
        $this->assertSame('Firefox/desktop', $entry->getUserAgentFamily());
        $this->assertSame((new \DateTimeImmutable('2026-10-16 12:00:00 UTC'))->getTimestamp(), $entry->getCreatedAt());
    }

    public function testAKnownSubjectIsReusedAndTouched(): void
    {
        $subject = new ConsentSubject();
        (new \ReflectionProperty($subject, 'id'))->setValue($subject, '3f2504e0-4f89-11d3-9a0c-0305e82c3301');
        $subject->setDateUpdated(new \DateTimeImmutable('2020-01-01'));
        [$em, $persisted] = $this->em($subject);

        $now = new \DateTimeImmutable('2026-10-16 12:00:00');
        $id = (new DoctrineConsentLogStore($em))->append(
            new ConsentChoice(4, ['necessary'], 'reject_all', 'en_GB', 'Other/unknown', '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
            $now,
        );

        $this->assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $id);
        $this->assertCount(1, $persisted);
        $this->assertInstanceOf(ConsentLogEntry::class, $persisted[0]);
        $this->assertEquals($now, $subject->getDateUpdated());
    }
}
```

`tests/Consent/ConsentReplyTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentReply;

final class ConsentReplyTest extends TestCase
{
    public function testSuccessCarriesTheIdWhenThereIsOne(): void
    {
        $this->assertSame(['ok' => true, 'id' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301'], ConsentReply::body(200, '3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        $this->assertSame(['ok' => true], ConsentReply::body(200, null));
    }

    public function testEveryFailureIsTheSameFixedBody(): void
    {
        foreach ([400, 403, 405, 429, 500] as $status) {
            $this->assertSame(['error' => 'invalid'], ConsentReply::body($status, '3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        }
    }

    public function testAMalformedIdIsNeverSent(): void
    {
        $this->assertSame(['ok' => true], ConsentReply::body(200, '"><script>'));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'PersistingConsentRecorderTest|DoctrineConsentLogStoreTest|ConsentReplyTest'`
Expected: FAIL.

- [ ] **Step 3: Implement the contracts**

`src/Consent/ConsentChoice.php`:

```php
<?php

namespace CookieManagerKit\Consent;

/**
 * A validated consent decision, handed to a ConsentRecorder. `subjectId` is the consent ID the browser claims to
 * already have; it has been checked for shape only, so a recorder must not assume it exists.
 */
final class ConsentChoice
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public readonly int $policyVersion,
        public readonly array $categories,
        public readonly string $trigger = ConsentTrigger::CUSTOM,
        public readonly string $locale = '',
        public readonly string $userAgentFamily = 'Other/unknown',
        public readonly ?string $subjectId = null,
    ) {}
}
```

`src/Consent/ConsentRecorder.php`:

```php
<?php

namespace CookieManagerKit\Consent;

/**
 * Stores a visitor's choice. Returns the visitor's consent ID, or null when nothing was stored. Never throws: a
 * failure to record must not change what the visitor sees.
 */
interface ConsentRecorder
{
    public function record(ConsentChoice $choice): ?string;
}
```

`src/Consent/NullConsentRecorder.php`:

```php
<?php

namespace CookieManagerKit\Consent;

final class NullConsentRecorder implements ConsentRecorder
{
    public function record(ConsentChoice $choice): ?string
    {
        return null;
    }
}
```

`src/Consent/ConsentLogStore.php`:

```php
<?php

namespace CookieManagerKit\Consent;

/**
 * Persistence port for the audit log. Always returns a consent ID: when the choice names a subject that does not
 * exist (never created, or purged by retention) a new one is created.
 */
interface ConsentLogStore
{
    public function append(ConsentChoice $choice, \DateTimeImmutable $now): string;
}
```

`src/Consent/DoctrineConsentLogStore.php`:

```php
<?php

namespace CookieManagerKit\Consent;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{ConsentLogEntry, ConsentSubject};

final class DoctrineConsentLogStore implements ConsentLogStore
{
    public function __construct(private EntityManagerInterface $em) {}

    public function append(ConsentChoice $choice, \DateTimeImmutable $now): string
    {
        return $this->em->wrapInTransaction(function () use ($choice, $now): string {
            $subject = $choice->subjectId === null ? null : $this->em->getRepository(ConsentSubject::class)->find($choice->subjectId);

            if (!$subject instanceof ConsentSubject) {
                $subject = new ConsentSubject();
                $this->em->persist($subject);
                // The database assigns the GUID on insert, and the entry needs it.
                $this->em->flush();
            } else {
                $subject->setDateUpdated($now);
            }

            $this->em->persist(
                (new ConsentLogEntry())
                    ->setSubject($subject)
                    ->setPolicyVersion($choice->policyVersion)
                    ->setCategories($choice->categories)
                    ->setTrigger($choice->trigger)
                    ->setLocale($choice->locale)
                    ->setUserAgentFamily($choice->userAgentFamily)
                    ->setCreatedAt($now->getTimestamp()),
            );
            $this->em->flush();

            return $subject->getID();
        });
    }
}
```

`src/Consent/PersistingConsentRecorder.php`:

```php
<?php

namespace CookieManagerKit\Consent;

use CookieManagerKit\Support\ErrorLog;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Binds a ConsentRecorder to the audit log. Until an admin has run the package Update the tables do not exist; that
 * window is silent so a busy site does not flood its log. Any other failure is logged with a fixed-shape message.
 */
final class PersistingConsentRecorder implements ConsentRecorder
{
    public function __construct(private ConsentLogStore $store, private ?\Closure $clock = null) {}

    public function record(ConsentChoice $choice): ?string
    {
        try {
            $now = $this->clock === null ? new \DateTimeImmutable() : ($this->clock)();

            return $this->store->append($choice, $now);
        } catch (TableNotFoundException) {
            return null;
        } catch (\Throwable $e) {
            ErrorLog::record($e);

            return null;
        }
    }
}
```

`src/Consent/ConsentReply.php`:

```php
<?php

namespace CookieManagerKit\Consent;

/**
 * The body of the consent endpoint's answer. Fixed shapes only; nothing from the request is ever included, and an
 * id is included only if it is a well-formed GUID (it comes from our own database, but defence is cheap).
 */
final class ConsentReply
{
    /**
     * @return array<string, mixed>
     */
    public static function body(int $status, ?string $id): array
    {
        if ($status !== 200) {
            return ['error' => 'invalid'];
        }

        if ($id !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id) === 1) {
            return ['ok' => true, 'id' => $id];
        }

        return ['ok' => true];
    }
}
```

- [ ] **Step 4: Wire the endpoint**

In `src/Ajax/Consent.php`: add imports `use CookieManagerKit\Consent\{ConsentChoice, ConsentRecorder, ConsentReply, UserAgentFamily};` (replace the existing Consent import), change `status()` to return `array{int, ?string}`, and `record()` to use it:

```php
    /**
     * @return array{int, ?string} status and the visitor's consent ID
     */
    private function handle(Request $request): array
    {
        if (!$request->isMethod('POST')) {
            return [405, null];
        }

        if (!RequestGuard::isSameOrigin($request->headers->get('Origin'), $request->headers->get('Referer'), $request->getHttpHost())) {
            return [403, null];
        }

        if (!RateLimit::allow('consent/' . sha1((string) $request->getClientIp()), self::PER_MINUTE)) {
            return [429, null];
        }

        $parsed = ConsentRequest::parse($request->headers->get('Content-Type'), (string) $request->getContent(), Catalog::handles(), Catalog::siteLocales());

        if ($parsed === null || $parsed['v'] !== Catalog::policyVersion()) {
            return [400, null];
        }

        $id = app(ConsentRecorder::class)->record(new ConsentChoice(
            $parsed['v'],
            $parsed['c'],
            $parsed['trigger'],
            $parsed['locale'],
            UserAgentFamily::of($request->headers->get('User-Agent')),
            $parsed['i'],
        ));

        return [200, $id];
    }

    public function record(): Response
    {
        try {
            [$status, $id] = $this->handle(app(Request::class));
        } catch (\Throwable $e) {
            [$status, $id] = [500, null];
            ErrorLog::record($e);
        }

        return new Response(
            Output::json(ConsentReply::body($status, $id)),
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ],
        );
    }
```

Update the class docblock to mention that it now records to the audit log. In `controller.php`, replace the `registerEvents()` binding and its comment:

```php
        app()->bind(ConsentRecorder::class, static fn() => new PersistingConsentRecorder(new DoctrineConsentLogStore(app(EntityManagerInterface::class))));
```

and fix the imports: remove `NullConsentRecorder`, add `use CookieManagerKit\Consent\{ConsentRecorder, DoctrineConsentLogStore, PersistingConsentRecorder};`.

- [ ] **Step 5: Run tests**

Run: `composer test && composer typecheck`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
composer format
git add src controller.php tests
git commit -m "feat: record every consent choice in the audit log and return the visitor's consent ID

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Browser side of the consent ID (loader and runtime)

**Files:**
- Modify: `js/cmk-loader.js`, `js/cmk-runtime.js`, `tests/js/runtime.test.js:~200`
- Test: `tests/js/loader.test.js`, `tests/js/runtime.test.js`

**Interfaces:**
- Produces on `window.cmk` (loader):
  - `get()` now returns `{ v, c, t, i }` (`i` is a GUID string or `undefined`).
  - `id(): string` — the current consent ID, else the ID from a stale-version cookie, else `''`.
  - `reconsent(): boolean` — true when there is no valid choice but the cookie held a different policy version.
  - `setId(id): boolean` — stores a well-formed GUID in the current choice's cookie; false otherwise.
- Runtime POST body becomes `{ v, c, g: trigger, l?: locale, i?: id }`; on a reply with `id` it calls `cmk.setId`.

- [ ] **Step 1: Write the failing loader tests**

Append to `tests/js/loader.test.js`:

```js
const GUID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

describe('the consent id', () => {
	it('is read from a valid cookie and exposed', () => {
		setConsent(valid(['necessary', 'analytics'], { i: GUID }));
		start();

		expect(window.cmk.get().i).toBe(GUID);
		expect(window.cmk.id()).toBe(GUID);
		expect(window.cmk.reconsent()).toBe(false);
	});

	it('drops a malformed or hostile id but keeps the choice', () => {
		setConsent(valid(['necessary', 'analytics'], { i: '"><script>alert(1)</script>' }));
		start();

		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
		expect(window.cmk.get().i).toBeUndefined();
		expect(window.cmk.id()).toBe('');
	});

	it('rejects an id that is not lowercase hex', () => {
		setConsent(valid(['necessary'], { i: GUID.toUpperCase() }));
		start();

		expect(window.cmk.id()).toBe('');
	});

	it('keeps the id of a cookie from an older policy version and flags re-consent', () => {
		setConsent(valid(['necessary', 'analytics'], { v: 1, i: GUID }));
		start();

		expect(window.cmk.get()).toBeNull();
		expect(window.cmk.reconsent()).toBe(true);
		expect(window.cmk.id()).toBe(GUID);
	});

	it('does not flag re-consent for no cookie or a garbage cookie', () => {
		start();
		expect(window.cmk.reconsent()).toBe(false);

		setConsent('%7Bnot-json');
		start();
		expect(window.cmk.reconsent()).toBe(false);
		expect(window.cmk.id()).toBe('');
	});

	it('writes the id back into the cookie on the next save, so it stays constant', () => {
		setConsent(valid(['necessary'], { v: 1, i: GUID }));
		start();
		window.cmk.save(['analytics']);

		const stored = JSON.parse(decodeURIComponent(document.cookie.split('; ').find((p) => p.startsWith('cmk_consent=')).slice(12)));
		expect(stored.i).toBe(GUID);
		expect(stored.v).toBe(2);
		expect(window.cmk.reconsent()).toBe(false);
	});

	it('setId stores a well-formed id and refuses anything else', () => {
		start();
		window.cmk.save(['analytics']);

		expect(window.cmk.setId('nope')).toBe(false);
		expect(window.cmk.setId('<b>')).toBe(false);
		expect(window.cmk.id()).toBe('');
		expect(window.cmk.setId(GUID)).toBe(true);
		expect(window.cmk.id()).toBe(GUID);
		expect(JSON.parse(decodeURIComponent(document.cookie.split('; ').find((p) => p.startsWith('cmk_consent=')).slice(12))).i).toBe(GUID);
	});

	it('setId does nothing when there is no choice yet', () => {
		start();

		expect(window.cmk.setId(GUID)).toBe(false);
		expect(document.cookie).not.toContain('cmk_consent');
	});
});
```

Append to `tests/js/runtime.test.js`:

```js
describe('the consent id and the trigger', () => {
	const GUID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
	const body = () => JSON.parse(window.fetch.mock.calls[0][1].body);

	it('sends the trigger and locale and no id for a first-time visitor', () => {
		mount();
		button('Accept all').click();

		expect(body()).toEqual({ v: 2, c: ['necessary', 'preferences', 'analytics', 'marketing'], g: 'accept_all', l: 'en_GB' });
	});

	it('classifies reject all and custom choices', () => {
		mount();
		button('Reject all').click();
		expect(body().g).toBe('reject_all');
	});

	it('sends the id from the cookie on a later change, classified by the result not as "changed"', () => {
		document.cookie = 'cmk_consent=' + encodeURIComponent(JSON.stringify({ v: 2, c: ['necessary'], t: 1700000000, i: GUID })) + '; Path=/';
		mount();
		window.cmkRuntime.open();
		button('Customise')?.click();
		button('Save').click();

		expect(body().i).toBe(GUID);
		expect(['reject_all', 'custom']).toContain(body().g);
	});

	it('uses the reconsent trigger and keeps the id after a policy bump', () => {
		document.cookie = 'cmk_consent=' + encodeURIComponent(JSON.stringify({ v: 1, c: ['necessary', 'analytics'], t: 1700000000, i: GUID })) + '; Path=/';
		mount();
		button('Accept all').click();

		expect(body().g).toBe('reconsent');
		expect(body().i).toBe(GUID);
	});

	it('stores the id the server returns', async () => {
		window.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, id: GUID }) }));
		mount();
		button('Accept all').click();
		await new Promise((r) => setTimeout(r, 0));

		expect(window.cmk.id()).toBe(GUID);
	});

	it('ignores a hostile id in the reply and a reply with no json', async () => {
		window.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, id: '"><script>' }) }));
		mount();
		button('Accept all').click();
		await new Promise((r) => setTimeout(r, 0));

		expect(window.cmk.id()).toBe('');
		expect(saved()).toContain('analytics');
	});
});
```

- [ ] **Step 2: Run to verify failure**

Run: `npx vitest run tests/js/loader.test.js tests/js/runtime.test.js`
Expected: FAIL (`window.cmk.id is not a function`, body shape).

- [ ] **Step 3: Implement the loader**

In `js/cmk-loader.js`:

1. After the `HANDLE` constant add: `var GUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;` and `var prior = null;` next to `var current = null;`.
2. In `parse()`, replace the final `return { v: data.v, c: categories, t: data.t };` with:

```js
			var parsed = { v: data.v, c: categories, t: data.t };

			if (typeof data.i === 'string' && GUID.test(data.i)) {
				parsed.i = data.i;
			}

			return parsed;
```

3. After `parse()` add:

```js
	/* A cookie from another policy version is not consent, but its id is how the visitor stays one record. */
	function readPrior(raw) {
		if (!raw) {
			return null;
		}

		try {
			var data = JSON.parse(decodeURIComponent(raw));

			if (
				!data ||
				typeof data !== 'object' ||
				Array.isArray(data) ||
				typeof data.v !== 'number' ||
				data.v === version
			) {
				return null;
			}

			return { id: typeof data.i === 'string' && GUID.test(data.i) ? data.i : '' };
		} catch (e) {
			return null;
		}
	}

	function write() {
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
	}

	function currentId() {
		return current && current.i ? current.i : (prior && prior.id) || '';
	}
```

4. In `save()`, replace the `current = {...}` line and the `try { d.cookie = ... } catch` block with:

```js
		var id = currentId();

		current = { v: version, c: list, t: Math.floor(Date.now() / 1000) };

		if (id) {
			current.i = id;
		}

		write();
```

5. Replace `current = parse(readRaw());` with:

```js
	var raw = readRaw();

	current = parse(raw);
	prior = current ? null : readPrior(raw);
```

6. In the `w.cmk = {...}` object: change `get` to include `i`, and add the three methods:

```js
		get: function () {
			if (!current) {
				return null;
			}

			var copy = { v: current.v, c: current.c.slice(), t: current.t };

			if (current.i) {
				copy.i = current.i;
			}

			return copy;
		},
		id: currentId,
		reconsent: function () {
			return !current && prior !== null;
		},
		setId: function (id) {
			if (!current || typeof id !== 'string' || !GUID.test(id)) {
				return false;
			}

			current.i = id;
			write();

			return true;
		},
```

- [ ] **Step 4: Implement the runtime**

In `js/cmk-runtime.js`, replace `post()`, `choiceEvent()` and `commit()` with:

```js
	function post(categories, trigger) {
		if (!boot.post || typeof w.fetch !== 'function') {
			return;
		}

		var body = { v: boot.v, c: categories, g: trigger };
		var locale = String((state.config && state.config.locale) || '');
		var id = cmk && typeof cmk.id === 'function' ? cmk.id() : '';

		if (locale) {
			body.l = locale;
		}

		if (id) {
			body.i = id;
		}

		try {
			w.fetch(boot.post, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body)
			})
				.then(function (response) {
					return response && response.ok && typeof response.json === 'function'
						? response.json()
						: null;
				})
				.then(function (reply) {
					if (reply && typeof reply.id === 'string') {
						cmk.setId(reply.id); /* refuses anything that is not a GUID */
					}
				})
				.catch(function () {
					/* the choice is already saved in the browser; the acknowledgement is best effort */
				});
		} catch (e) {
			/* same */
		}
	}
```

```js
	function classify(saved, categories) {
		if (saved.length === 1) {
			return 'reject_all';
		}

		return categories.every(function (category) {
			return saved.indexOf(category.handle) !== -1;
		})
			? 'accept_all'
			: 'custom';
	}

	/* For the statistics: a later change is its own metric. */
	function choiceEvent(previous, saved, categories) {
		return previous ? 'changed' : classify(saved, categories);
	}

	/* For the audit log: what the visitor chose, or that they were asked again because the policy changed. */
	function triggerFor(reconsent, saved, categories) {
		return reconsent ? 'reconsent' : classify(saved, categories);
	}
```

```js
	function commit(handles) {
		var previous = cmk.get();
		var reconsent = cmk.reconsent();
		var saved = cmk.save(handles);
		var categories = validCategories(state.config);
		var withdrew =
			previous &&
			previous.c.some(function (handle) {
				return saved.indexOf(handle) === -1;
			});

		post(saved, triggerFor(reconsent, saved, categories));
		track(choiceEvent(previous, saved, categories));
		close(true);

		if (withdrew) {
			runtime.reload();
		}
	}
```

- [ ] **Step 5: Update the existing exact-body assertion**

In `tests/js/runtime.test.js` change line ~200 from
`expect(JSON.parse(options.body)).toEqual({ v: 2, c: [...] });` to
`expect(JSON.parse(options.body)).toEqual({ v: 2, c: ['necessary', 'preferences', 'analytics', 'marketing'], g: 'accept_all', l: 'en_GB' });`.

- [ ] **Step 6: Run tests**

Run: `npx vitest run && npx prettier --check js tests/js`
Expected: PASS. If Prettier fails run `npx prettier --write js tests/js`.

- [ ] **Step 7: Commit**

```bash
git add js tests/js
git commit -m "feat: keep a constant consent id in the consent cookie and send the trigger with each choice

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Retention purge task

**Files:**
- Create: `src/Consent/ConsentLogRetention.php`, `src/Command/Task/PurgeConsentLogCommand.php`, `src/Command/Task/PurgeConsentLogCommandHandler.php`, `src/Command/Task/Controller/PurgeConsentLogController.php`
- Modify: `tasks.xml`, `controller.php` (`$tasks`), `tests/TasksTest.php` if it enumerates handles
- Test: `tests/Consent/ConsentLogRetentionTest.php`, add a case to `tests/TaskHandlersTest.php`

**Interfaces:**
- Produces:
  - `ConsentLogRetention::__construct(\Doctrine\DBAL\Connection $connection)`; `purge(\DateTimeImmutable $cutoff, int $batch = 1000, int $maxBatches = 50): int` deletes `cmkConsentSubjects` rows whose `date_updated` is before `$cutoff` (entries cascade) and returns the number of subjects removed; swallows `TableNotFoundException` (returns 0).
  - `ConsentLogRetention::cutoff(int $months, \DateTimeImmutable $now): \DateTimeImmutable`.
  - Task handle `cmk_purge_consent_log`.

- [ ] **Step 1: Write the failing tests**

`tests/Consent/ConsentLogRetentionTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use CookieManagerKit\Consent\ConsentLogRetention;

final class ConsentLogRetentionTest extends TestCase
{
    public function testTheCutoffIsMonthsBeforeNow(): void
    {
        $cutoff = ConsentLogRetention::cutoff(24, new \DateTimeImmutable('2026-10-16 12:00:00'));

        $this->assertSame('2024-10-16 12:00:00', $cutoff->format('Y-m-d H:i:s'));
    }

    public function testItDeletesInBatchesUntilABatchComesBackShort(): void
    {
        $connection = $this->createMock(Connection::class);
        $calls = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params) use (&$calls): int {
            $calls[] = [$sql, $params];

            return count($calls) === 1 ? 1000 : 250;
        });

        $removed = (new ConsentLogRetention($connection))->purge(new \DateTimeImmutable('2024-01-01 00:00:00'), 1000);

        $this->assertSame(1250, $removed);
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('DELETE FROM cmkConsentSubjects', $calls[0][0]);
        $this->assertStringContainsString('date_updated < ?', $calls[0][0]);
        $this->assertSame(['2024-01-01 00:00:00'], $calls[0][1]);
    }

    public function testItStopsAtTheBatchLimit(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))->method('executeStatement')->willReturn(10);

        $this->assertSame(30, (new ConsentLogRetention($connection))->purge(new \DateTimeImmutable('2024-01-01'), 10, 3));
    }

    public function testNothingToDeleteIsZero(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $this->assertSame(0, (new ConsentLogRetention($connection))->purge(new \DateTimeImmutable('2024-01-01')));
    }
}
```

Append to `tests/TaskHandlersTest.php` a test (follow the file's existing `sink()` helper and subclass-the-handler style used for `CrawlCommandHandler`):

```php
    public function testPurgeReportsOnlyACount(): void
    {
        $sink = self::sink();
        $handler = new class extends \CookieManagerKit\Command\Task\PurgeConsentLogCommandHandler {
            protected function removed(): int
            {
                return 7;
            }
        };
        $handler->setOutput($sink);
        $handler(new \CookieManagerKit\Command\Task\PurgeConsentLogCommand());

        $this->assertSame(['Removed the consent records of 7 browsers that have not made a choice within the retention period.'], $sink->lines);
    }

    public function testPurgeFailureIsAFixedSentence(): void
    {
        $handler = new class extends \CookieManagerKit\Command\Task\PurgeConsentLogCommandHandler {
            protected function removed(): int
            {
                throw new \RuntimeException('SQLSTATE secret detail');
            }
        };
        $handler->setOutput(self::sink());

        try {
            $handler(new \CookieManagerKit\Command\Task\PurgeConsentLogCommand());
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertSame(\CookieManagerKit\Command\Task\PurgeConsentLogCommandHandler::FAILED, $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }
```

(`OutputAwareTrait` provides `setOutput`; check the trait's method name in `vendor/concrete5/core/.../OutputAwareTrait.php` and use it. The existing tests in this file show how they inject the sink: copy that exact call.)

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'ConsentLogRetentionTest|TaskHandlersTest'`
Expected: FAIL.

- [ ] **Step 3: Implement**

`src/Consent/ConsentLogRetention.php`:

```php
<?php

namespace CookieManagerKit\Consent;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Removes browsers whose last decision is older than the retention period. A subject's entries go with it (the
 * foreign key cascades). Batched so a large backlog cannot hold a table lock for long; every value is bound.
 */
final class ConsentLogRetention
{
    public function __construct(private Connection $connection) {}

    public static function cutoff(int $months, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-' . max(1, $months) . ' months');
    }

    public function purge(\DateTimeImmutable $cutoff, int $batch = 1000, int $maxBatches = 50): int
    {
        $removed = 0;
        $batch = max(1, $batch);

        try {
            for ($i = 0; $i < $maxBatches; $i++) {
                $count = (int) $this->connection->executeStatement(
                    'DELETE FROM cmkConsentSubjects WHERE date_updated < ? LIMIT ' . $batch,
                    [$cutoff->format('Y-m-d H:i:s')],
                );
                $removed += $count;

                if ($count < $batch) {
                    break;
                }
            }
        } catch (TableNotFoundException) {
        }

        return $removed;
    }
}
```

`src/Command/Task/PurgeConsentLogCommand.php`:

```php
<?php

namespace CookieManagerKit\Command\Task;

use Concrete\Core\Foundation\Command\Command;

class PurgeConsentLogCommand extends Command {}
```

`src/Command/Task/PurgeConsentLogCommandHandler.php`:

```php
<?php

namespace CookieManagerKit\Command\Task;

use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Consent\ConsentLogRetention;
use Concrete\Core\Command\Task\Output\{OutputAwareInterface, OutputAwareTrait};

/**
 * Output is a count or a fixed sentence; an exception's text (which can carry SQL) never reaches the task log.
 */
class PurgeConsentLogCommandHandler implements OutputAwareInterface
{
    use OutputAwareTrait;

    public const FAILED = 'The consent log could not be purged.';

    public function __invoke(PurgeConsentLogCommand $command)
    {
        try {
            $count = $this->removed();
        } catch (\Throwable $e) {
            ErrorLog::record($e);

            throw new \RuntimeException(t(self::FAILED));
        }

        $this->output->write(t('Removed the consent records of %s browsers that have not made a choice within the retention period.', $count));
    }

    protected function removed(): int
    {
        $months = Settings::get()->getConsentLogRetentionMonths();

        return (new ConsentLogRetention(app(EntityManagerInterface::class)->getConnection()))
            ->purge(ConsentLogRetention::cutoff($months, new \DateTimeImmutable()));
    }
}
```

`src/Command/Task/Controller/PurgeConsentLogController.php` (copy `CrawlController`):

```php
<?php

namespace CookieManagerKit\Command\Task\Controller;

use Concrete\Core\Command\Task\TaskInterface;
use CookieManagerKit\Command\Task\PurgeConsentLogCommand;
use Concrete\Core\Command\Task\Input\InputInterface;
use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Runner\{ProcessTaskRunner, TaskRunnerInterface};

class PurgeConsentLogController extends AbstractController
{
    public function getName(): string
    {
        return t('Purge old consent records');
    }

    public function getDescription(): string
    {
        return t('Deletes the consent history of browsers that have not made a choice within the retention period set on the Banner page. Purged records cannot be recovered.');
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new ProcessTaskRunner(
            $task,
            new PurgeConsentLogCommand(),
            $input,
            t('Purging old consent records...'),
        );
    }
}
```

`tasks.xml`: add `<task handle="cmk_purge_consent_log" package="cookie_manager_kit" />` under `<tasks>` and `<task handle="cmk_purge_consent_log" />` under the taskset. In `controller.php` add to `$tasks`:

```php
        'cmk_purge_consent_log' => \CookieManagerKit\Command\Task\Controller\PurgeConsentLogController::class,
```

Open `tests/TasksTest.php`; if it asserts the list of handles in `tasks.xml` / `$tasks`, add the new handle to those assertions.

- [ ] **Step 4: Run tests**

Run: `composer test && composer typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer format
git add src tasks.xml controller.php tests
git commit -m "feat: purge consent records past the retention period on a schedule

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Log query, CSV and filters

**Files:**
- Create: `src/Support/CsvCell.php`, `src/Consent/ConsentLogFilter.php`, `src/Consent/ConsentLogReader.php`, `src/Consent/DoctrineConsentLogReader.php`, `src/Consent/ConsentLogCsv.php`
- Test: `tests/Support/CsvCellTest.php`, `tests/Consent/ConsentLogFilterTest.php`, `tests/Consent/ConsentLogCsvTest.php`, `tests/Consent/DoctrineConsentLogReaderTest.php`

**Interfaces:**
- Consumes: `StatsRange::parse($from, $to, $today): array{from: string, to: string, ...}` (read `src/Support/StatsRange.php` for the exact keys before using), `ConsentTrigger`.
- Produces:
  - `CsvCell::neutralise(string): string`; `CsvCell::row(array $cells): string` (one CSV line with `\r\n`).
  - `ConsentLogFilter` (readonly): `from`, `to` (`Y-m-d`), `trigger` (`''` or allowlisted), `version` (`0` or int), `category` (`''` or a handle), `consentId` (`''` or GUID), plus `rejectedId` (the raw search text, truncated to 64 chars, only when it was not a GUID; for redisplay, encoded by the view). `ConsentLogFilter::parse(mixed $from, mixed $to, mixed $trigger, mixed $version, mixed $category, mixed $consentId, string $today): self`.
  - `ConsentLogReader::page(ConsentLogFilter $f, int $limit, int $offset): array{rows: list<array>, total: int}`; `ConsentLogReader::export(ConsentLogFilter $f, int $max): list<array>`.
  - Row shape (both methods): `array{createdAt: int, consentId: string, version: int, categories: list<string>, trigger: string, locale: string, userAgentFamily: string, reason: string}`.
  - `ConsentLogCsv::HEADER`, `ConsentLogCsv::render(array $rows): string`, `ConsentLogCsv::MAX_ROWS = 100000`.

- [ ] **Step 1: Write the failing tests**

`tests/Support/CsvCellTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;

final class CsvCellTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cells(): array
    {
        return [
            'plain' => ['hello', 'hello'],
            'equals' => ['=HYPERLINK("http://evil")', "'=HYPERLINK(\"http://evil\")"],
            'plus' => ['+1+1', "'+1+1"],
            'minus' => ['-2', "'-2"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\t=1", "'\t=1"],
            'carriage return' => ["\r=1", "'\r=1"],
            'safe middle' => ['a=b', 'a=b'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('cells')]
    public function testNeutralise(string $in, string $out): void
    {
        $this->assertSame($out, CsvCell::neutralise($in));
    }

    public function testRowQuotesEscapesAndNeutralises(): void
    {
        $this->assertSame("\"a\",\"he said \"\"hi\"\"\",\"'=1\"\r\n", CsvCell::row(['a', 'he said "hi"', '=1']));
    }

    public function testRowKeepsNewlinesInsideTheQuotes(): void
    {
        $this->assertSame("\"line1\nline2\"\r\n", CsvCell::row(["line1\nline2"]));
    }
}
```

`tests/Consent/ConsentLogFilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentLogFilter;

final class ConsentLogFilterTest extends TestCase
{
    private const GUID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    private function parse(array $in = []): ConsentLogFilter
    {
        $in += ['from' => null, 'to' => null, 'trigger' => null, 'version' => null, 'category' => null, 'id' => null];

        return ConsentLogFilter::parse($in['from'], $in['to'], $in['trigger'], $in['version'], $in['category'], $in['id'], '2026-10-16');
    }

    public function testEmptyInputMeansNoFilters(): void
    {
        $f = $this->parse();

        $this->assertSame('', $f->trigger);
        $this->assertSame(0, $f->version);
        $this->assertSame('', $f->category);
        $this->assertSame('', $f->consentId);
        $this->assertSame('', $f->rejectedId);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}\z/', $f->from);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}\z/', $f->to);
    }

    public function testValidValuesPass(): void
    {
        $f = $this->parse(['trigger' => 'gpc', 'version' => '3', 'category' => 'analytics', 'id' => strtoupper(self::GUID)]);

        $this->assertSame('gpc', $f->trigger);
        $this->assertSame(3, $f->version);
        $this->assertSame('analytics', $f->category);
        $this->assertSame(self::GUID, $f->consentId);
    }

    public function testHostileValuesAreIgnored(): void
    {
        $f = $this->parse(['trigger' => '"><script>', 'version' => '3 OR 1=1', 'category' => "x' OR '1'='1", 'from' => 'nope', 'to' => ['x']]);

        $this->assertSame('', $f->trigger);
        $this->assertSame(0, $f->version);
        $this->assertSame('', $f->category);
    }

    public function testASearchThatIsNotAGuidIsKeptOnlyForRedisplayAndNeverQueried(): void
    {
        // Review focus 5.
        $f = $this->parse(['id' => '"><script>alert(1)</script>' . str_repeat('x', 200)]);

        $this->assertSame('', $f->consentId);
        $this->assertSame(64, mb_strlen($f->rejectedId));
    }
}
```

`tests/Consent/ConsentLogCsvTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentLogCsv;

final class ConsentLogCsvTest extends TestCase
{
    private function row(array $over = []): array
    {
        return $over + [
            'createdAt' => 1760616000,
            'consentId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'version' => 2,
            'categories' => ['necessary', 'analytics'],
            'trigger' => 'reconsent',
            'locale' => 'en_GB',
            'userAgentFamily' => 'Chrome/desktop',
            'reason' => 'Added GA to analytics',
        ];
    }

    public function testHeaderAndRow(): void
    {
        $csv = ConsentLogCsv::render([$this->row()]);
        $lines = explode("\r\n", rtrim($csv, "\r\n"));

        $this->assertSame('"Date (UTC)","Consent ID","Policy version","Categories","Trigger","Locale","User agent family","Re-consent reason"', $lines[0]);
        $this->assertSame('"2025-10-16 12:00:00","3f2504e0-4f89-11d3-9a0c-0305e82c3301","2","necessary analytics","reconsent","en_GB","Chrome/desktop","Added GA to analytics"', $lines[1]);
    }

    public function testTheReasonIsNeutralisedAgainstFormulaInjection(): void
    {
        // Review focus 5: a reason pre-filled from a scanner finding that starts with "=".
        $csv = ConsentLogCsv::render([$this->row(['reason' => '=HYPERLINK("http://evil","x")'])]);

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://evil\"\",\"\"x\"\")\"", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    public function testEveryFreeTextCellIsNeutralised(): void
    {
        $csv = ConsentLogCsv::render([$this->row(['locale' => '=1', 'userAgentFamily' => '+2', 'trigger' => '-3'])]);

        $this->assertStringContainsString("\"'=1\"", $csv);
        $this->assertStringContainsString("\"'+2\"", $csv);
        $this->assertStringContainsString("\"'-3\"", $csv);
    }

    public function testAnEmptyReasonIsAnEmptyCell(): void
    {
        $this->assertStringContainsString(',""' . "\r\n", ConsentLogCsv::render([$this->row(['reason' => ''])]));
    }

    public function testNoRowsIsJustTheHeader(): void
    {
        $this->assertSame(1, substr_count(ConsentLogCsv::render([]), "\r\n"));
    }

    public function testTheRowCap(): void
    {
        $this->assertSame(100000, ConsentLogCsv::MAX_ROWS);
    }
}
```

`tests/Consent/DoctrineConsentLogReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use CookieManagerKit\Consent\{ConsentLogFilter, DoctrineConsentLogReader};

final class DoctrineConsentLogReaderTest extends TestCase
{
    public function testEveryFilterValueIsBoundNeverConcatenated(): void
    {
        $captured = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql, array $params) use (&$captured): array {
            $captured = [$sql, $params];

            return [];
        });

        $filter = ConsentLogFilter::parse('2026-10-01', '2026-10-16', 'gpc', '3', 'analytics', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', '2026-10-16');
        (new DoctrineConsentLogReader($connection))->page($filter, 50, 0);

        [$sql, $params] = $captured;
        $this->assertStringNotContainsString('gpc', $sql);
        $this->assertStringNotContainsString('analytics', $sql);
        $this->assertStringNotContainsString('3f2504e0', $sql);
        $this->assertContains('gpc', $params);
        $this->assertContains(3, $params);
        $this->assertContains('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $params);
        $this->assertContains('%"analytics"%', $params);
    }

    public function testRowsAreMappedToTheDocumentedShape(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('1');
        $connection->method('fetchAllAssociative')->willReturn([[
            'createdAt' => '1760616000',
            'consentId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'policyVersion' => '2',
            'categories' => '["necessary","analytics"]',
            'triggerName' => 'custom',
            'locale' => 'en_GB',
            'userAgentFamily' => 'Chrome/desktop',
            'reason' => null,
        ]]);

        $result = (new DoctrineConsentLogReader($connection))->page(ConsentLogFilter::parse(null, null, null, null, null, null, '2026-10-16'), 50, 0);

        $this->assertSame(1, $result['total']);
        $this->assertSame([
            'createdAt' => 1760616000,
            'consentId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'version' => 2,
            'categories' => ['necessary', 'analytics'],
            'trigger' => 'custom',
            'locale' => 'en_GB',
            'userAgentFamily' => 'Chrome/desktop',
            'reason' => '',
        ], $result['rows'][0]);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'CsvCellTest|ConsentLogFilterTest|ConsentLogCsvTest|DoctrineConsentLogReaderTest'`
Expected: FAIL.

- [ ] **Step 3: Implement**

`src/Support/CsvCell.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * CSV output that spreadsheets cannot turn into formulas. A cell that starts with = + - @ tab or CR is prefixed with
 * a single quote; every field is quoted and its quotes doubled.
 */
final class CsvCell
{
    public static function neutralise(string $cell): string
    {
        return $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false ? "'" . $cell : $cell;
    }

    /**
     * @param list<scalar> $cells
     */
    public static function row(array $cells): string
    {
        $out = [];

        foreach ($cells as $cell) {
            $out[] = '"' . str_replace('"', '""', self::neutralise((string) $cell)) . '"';
        }

        return implode(',', $out) . "\r\n";
    }
}
```

`src/Consent/ConsentLogFilter.php` (read `src/Support/StatsRange.php` first; it returns an array whose `from` and `to` keys are `Y-m-d` strings; the code below uses those two keys):

```php
<?php

namespace CookieManagerKit\Consent;

use CookieManagerKit\Support\{StatsRange, Text};

/**
 * The filters of the consent log page, vetted. Anything that does not pass is dropped, never queried. A search text
 * that is not a GUID is kept (truncated) only so the page can show it back, encoded, in a "no results" message.
 */
final class ConsentLogFilter
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $trigger,
        public readonly int $version,
        public readonly string $category,
        public readonly string $consentId,
        public readonly string $rejectedId,
    ) {}

    public static function parse(mixed $from, mixed $to, mixed $trigger, mixed $version, mixed $category, mixed $consentId, string $today): self
    {
        $range = StatsRange::parse($from, $to, $today);

        $search = is_string($consentId) ? strtolower(trim($consentId)) : '';
        $isGuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $search) === 1;

        return new self(
            $range['from'],
            $range['to'],
            ConsentTrigger::isValid($trigger) ? $trigger : '',
            is_string($version) && preg_match('/^\d{1,7}\z/', $version) === 1 ? (int) $version : 0,
            Text::isHandle($category) ? $category : '',
            $isGuid ? $search : '',
            !$isGuid && $search !== '' ? mb_substr(Text::plain($consentId, 64), 0, 64) : '',
        );
    }
}
```

Note: `rejectedId` uses `Text::plain` (which strips control characters and caps length); for the 200-`x` test input the result is 64 characters. If `Text::plain` strips `<`/`>` that is also fine for the test (it asserts only length 64, and the input is long enough either way).

`src/Consent/ConsentLogReader.php`:

```php
<?php

namespace CookieManagerKit\Consent;

interface ConsentLogReader
{
    /**
     * @return array{rows: list<array{createdAt: int, consentId: string, version: int, categories: list<string>, trigger: string, locale: string, userAgentFamily: string, reason: string}>, total: int}
     */
    public function page(ConsentLogFilter $filter, int $limit, int $offset): array;

    /**
     * @return list<array{createdAt: int, consentId: string, version: int, categories: list<string>, trigger: string, locale: string, userAgentFamily: string, reason: string}>
     */
    public function export(ConsentLogFilter $filter, int $max): array;
}
```

`src/Consent/DoctrineConsentLogReader.php`:

```php
<?php

namespace CookieManagerKit\Consent;

use Doctrine\DBAL\Connection;

final class DoctrineConsentLogReader implements ConsentLogReader
{
    private const FROM = 'FROM cmkConsentLog l
        INNER JOIN cmkConsentSubjects s ON s.id = l.subjectID
        LEFT JOIN cmkPolicyChanges p ON p.version = l.policyVersion';

    public function __construct(private Connection $connection) {}

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(ConsentLogFilter $f): array
    {
        $start = (new \DateTimeImmutable($f->from . ' 00:00:00 UTC'))->getTimestamp();
        $end = (new \DateTimeImmutable($f->to . ' 23:59:59 UTC'))->getTimestamp();
        $sql = ' WHERE l.createdAt BETWEEN ? AND ?';
        $params = [$start, $end];

        if ($f->trigger !== '') {
            $sql .= ' AND l.triggerName = ?';
            $params[] = $f->trigger;
        }

        if ($f->version > 0) {
            $sql .= ' AND l.policyVersion = ?';
            $params[] = $f->version;
        }

        if ($f->category !== '') {
            $sql .= ' AND l.categories LIKE ?';
            $params[] = '%"' . $f->category . '"%';
        }

        if ($f->consentId !== '') {
            $sql .= ' AND s.id = ?';
            $params[] = $f->consentId;
        }

        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{createdAt: int, consentId: string, version: int, categories: list<string>, trigger: string, locale: string, userAgentFamily: string, reason: string}
     */
    private static function map(array $row): array
    {
        $categories = json_decode((string) ($row['categories'] ?? '[]'), true);

        return [
            'createdAt' => (int) $row['createdAt'],
            'consentId' => (string) $row['consentId'],
            'version' => (int) $row['policyVersion'],
            'categories' => is_array($categories) && array_is_list($categories) ? array_values(array_filter($categories, 'is_string')) : [],
            'trigger' => (string) $row['triggerName'],
            'locale' => (string) $row['locale'],
            'userAgentFamily' => (string) $row['userAgentFamily'],
            'reason' => (string) ($row['reason'] ?? ''),
        ];
    }

    private function select(ConsentLogFilter $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($f);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.createdAt, s.id AS consentId, l.policyVersion, l.categories, l.triggerName, l.locale, l.userAgentFamily, p.reason AS reason '
            . self::FROM . $where . ' ORDER BY l.createdAt DESC, l.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params,
        );

        return array_map(self::map(...), $rows);
    }

    public function page(ConsentLogFilter $filter, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filter);
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) ' . self::FROM . $where, $params);

        return ['rows' => $this->select($filter, $limit, $offset), 'total' => $total];
    }

    public function export(ConsentLogFilter $filter, int $max): array
    {
        return $this->select($filter, $max, 0);
    }
}
```

`$limit` and `$offset` are cast to ints before concatenation, which is why they are the only non-bound values.

`src/Consent/ConsentLogCsv.php`:

```php
<?php

namespace CookieManagerKit\Consent;

use CookieManagerKit\Support\CsvCell;

final class ConsentLogCsv
{
    public const MAX_ROWS = 100000;

    public const HEADER = ['Date (UTC)', 'Consent ID', 'Policy version', 'Categories', 'Trigger', 'Locale', 'User agent family', 'Re-consent reason'];

    /**
     * @param list<array{createdAt: int, consentId: string, version: int, categories: list<string>, trigger: string, locale: string, userAgentFamily: string, reason: string}> $rows
     */
    public static function render(array $rows): string
    {
        $out = CsvCell::row(self::HEADER);

        foreach ($rows as $row) {
            $out .= CsvCell::row([
                gmdate('Y-m-d H:i:s', $row['createdAt']),
                $row['consentId'],
                $row['version'],
                implode(' ', $row['categories']),
                $row['trigger'],
                $row['locale'],
                $row['userAgentFamily'],
                $row['reason'],
            ]);
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `composer test && composer typecheck`
Expected: PASS. If `testHeaderAndRow` fails on the timestamp, recompute: `gmdate('Y-m-d H:i:s', 1760616000)` and fix the expected string in the test (the value is not under test, the format is).

- [ ] **Step 5: Commit**

```bash
composer format
git add src tests
git commit -m "feat: query and export the consent log with neutralised CSV

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Consent Log dashboard page

**Files:**
- Create: `controllers/single_page/dashboard/cookie_manager/consent_log.php`, `single_pages/dashboard/cookie_manager/consent_log.php`, `tests/Dashboard/ConsentLogViewTest.php`
- Modify: `controller.php` (`installOrUpgrade`)
- Test: `tests/ControllerSecurityTest.php` and `tests/ViewEscapingTest.php` run automatically over the new files; add `tests/Dashboard/ConsentLogViewTest.php`.

**Interfaces:**
- Consumes: Task 6 (`ConsentLogFilter`, `DoctrineConsentLogReader`, `ConsentLogCsv`).
- Produces: dashboard page `/dashboard/cookie_manager/consent_log` with `view()` (GET, filters from the query string) and `export()` (POST + CSRF, filters from POST).

- [ ] **Step 1: Write the failing view test**

Model it on `tests/Dashboard/ScannerViewTest.php` (same `view()`/`token()` stubs, `ob_start(); include` pattern, and `translate.php` require). `tests/Dashboard/ConsentLogViewTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Dashboard;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentLogFilter;

require_once __DIR__ . '/translate.php';

final class ConsentLogViewTest extends TestCase
{
    public const HOSTILE = '"><img src=x onerror=alert(1)><script>alert(2)</script>';

    private static function render(array $rows, ConsentLogFilter $filter, int $total, int $page = 1): string
    {
        $view = new class {
            public function action(string $name): string
            {
                return '/dashboard/cookie_manager/consent_log/' . $name;
            }
        };
        $token = new class {
            public function output(string $action): string
            {
                return '<input type="hidden" name="ccm_token" value="t">';
            }
        };
        $form = new class {};
        $perPage = 50;
        $triggers = \CookieManagerKit\Consent\ConsentTrigger::ALL;
        $categories = ['necessary', 'analytics'];
        $capped = false;

        ob_start();
        include dirname(__DIR__, 2) . '/single_pages/dashboard/cookie_manager/consent_log.php';

        return (string) ob_get_clean();
    }

    private static function row(array $over = []): array
    {
        return $over + [
            'createdAt' => 1760616000,
            'consentId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'version' => 2,
            'categories' => ['necessary', 'analytics'],
            'trigger' => 'reconsent',
            'locale' => 'en_GB',
            'userAgentFamily' => 'Chrome/desktop',
            'reason' => 'Added GA',
        ];
    }

    public function testRowsRenderWithTheReason(): void
    {
        $html = self::render([self::row()], ConsentLogFilter::parse(null, null, null, null, null, null, '2026-10-16'), 1);

        $this->assertStringContainsString('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $html);
        $this->assertStringContainsString('Added GA', $html);
        $this->assertStringContainsString('Chrome/desktop', $html);
    }

    public function testHostileRowValuesAreEncoded(): void
    {
        $html = self::render(
            [self::row(['reason' => self::HOSTILE, 'locale' => self::HOSTILE, 'userAgentFamily' => self::HOSTILE, 'trigger' => self::HOSTILE, 'categories' => [self::HOSTILE]])],
            ConsentLogFilter::parse(null, null, null, null, null, null, '2026-10-16'),
            1,
        );

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function testASearchThatIsNotAGuidIsShownBackEncodedInTheNoResultsMessage(): void
    {
        $filter = ConsentLogFilter::parse(null, null, null, null, null, self::HOSTILE, '2026-10-16');
        $html = self::render([], $filter, 0);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('is not a valid consent ID', $html);
    }

    public function testTheExportFormCarriesATokenAndTheFilterAsHiddenFields(): void
    {
        $html = self::render([], ConsentLogFilter::parse('2026-10-01', '2026-10-16', 'gpc', '2', 'analytics', null, '2026-10-16'), 0);

        $this->assertStringContainsString('name="ccm_token"', $html);
        $this->assertStringContainsString('/dashboard/cookie_manager/consent_log/export', $html);
        $this->assertStringContainsString('name="trigger" value="gpc"', $html);
    }

    public function testTheRetentionPeriodIsStated(): void
    {
        $this->assertStringContainsString('Banner', self::render([], ConsentLogFilter::parse(null, null, null, null, null, null, '2026-10-16'), 0));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter ConsentLogViewTest`
Expected: FAIL (view file missing).

- [ ] **Step 3: Write the controller**

`controllers/single_page/dashboard/cookie_manager/consent_log.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Concrete\Core\Page\Controller\DashboardPageController;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\Catalog;
use CookieManagerKit\Consent\{ConsentLogCsv, ConsentLogFilter, ConsentTrigger, DoctrineConsentLogReader};

/**
 * The audit log. Listing and filtering read only (filters come from the query string and are vetted by
 * ConsentLogFilter before use); the export is a POST with a CSRF token because the log is personal-data-adjacent
 * and a GET link could be triggered by a third-party page. Nothing here changes data.
 */
class ConsentLog extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/consent_log';
    private const PER_PAGE = 50;

    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private function reader(): DoctrineConsentLogReader
    {
        return new DoctrineConsentLogReader($this->entityManager->getConnection());
    }

    public function view()
    {
        $query = $this->request->query;
        $filter = ConsentLogFilter::parse($query->get('from'), $query->get('to'), $query->get('trigger'), $query->get('version'), $query->get('category'), $query->get('id'), gmdate('Y-m-d'));
        $page = max(1, min(100000, (int) $query->get('page')));
        $rows = [];
        $total = 0;

        try {
            $result = $this->reader()->page($filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $rows = $result['rows'];
            $total = $result['total'];
        } catch (\Throwable) {
            // The tables do not exist until the package Update has run; show an empty log rather than an error.
        }

        $this->set('filter', $filter);
        $this->set('rows', $rows);
        $this->set('total', $total);
        $this->set('page', $page);
        $this->set('perPage', self::PER_PAGE);
        $this->set('triggers', ConsentTrigger::ALL);
        $this->set('categories', Catalog::handles());
        $this->set('retentionMonths', Settings::get()->getConsentLogRetentionMonths());
        $this->set('capped', $total > ConsentLogCsv::MAX_ROWS);
    }

    public function export()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $filter = ConsentLogFilter::parse(
            $this->post('from'),
            $this->post('to'),
            $this->post('trigger'),
            $this->post('version'),
            $this->post('category'),
            $this->post('id'),
            gmdate('Y-m-d'),
        );

        $rows = $this->reader()->export($filter, ConsentLogCsv::MAX_ROWS);

        return new Response(ConsentLogCsv::render($rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="consent-log-' . gmdate('Ymd') . '.csv"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
```

Remove the unused `EntityManagerInterface` import. If `ControllerSecurityTest::testControllersNeverEchoOrRenderRequestDataDirectly` flags the `Response`, read `outputProblems()` in that test to see what shape is accepted (the body is built from database rows by `ConsentLogCsv::render`, not from request data) and adjust the controller shape to satisfy the checker; do not weaken the checker.

- [ ] **Step 4: Write the view**

`single_pages/dashboard/cookie_manager/consent_log.php`. Model the filter form, table and pagination on `single_pages/dashboard/cookie_manager/findings.php` (open it and copy its pagination and filter markup). Required content, all values through `O::e()` / `O::url()`:

```php
<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Consent\ConsentLogFilter $filter */
/** @var list<array<string, mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var list<string> $triggers */
/** @var list<string> $categories */
/** @var int $retentionMonths */
/** @var bool $capped */

$pages = max(1, (int) ceil($total / $perPage));
$suffix = '&from=' . rawurlencode($filter->from) . '&to=' . rawurlencode($filter->to) . '&trigger=' . rawurlencode($filter->trigger)
    . '&version=' . rawurlencode($filter->version ? (string) $filter->version : '') . '&category=' . rawurlencode($filter->category)
    . '&id=' . rawurlencode($filter->consentId);
?>
<p class="text-muted"><?php echo O::e(t('Every choice a visitor makes is recorded under a random consent ID that lives in their cookie. No IP address or raw user agent is stored. A browser\'s history is deleted %s months after its last choice (set on the Banner page).', $retentionMonths)) ?></p>

<form method="get" class="row g-3 align-items-end mb-4">
    <div class="col-auto">
        <label class="form-label" for="cmk-from"><?php echo t('From') ?></label>
        <input type="date" class="form-control" id="cmk-from" name="from" value="<?php echo O::e($filter->from) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-to"><?php echo t('To') ?></label>
        <input type="date" class="form-control" id="cmk-to" name="to" value="<?php echo O::e($filter->to) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-trigger"><?php echo t('Trigger') ?></label>
        <select class="form-select" id="cmk-trigger" name="trigger">
            <option value=""><?php echo t('Any') ?></option>
            <?php foreach ($triggers as $value) { ?>
                <option value="<?php echo O::e($value) ?>" <?php echo O::e($filter->trigger === $value ? 'selected' : '') ?>><?php echo O::e($value) ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-version"><?php echo t('Policy version') ?></label>
        <input type="number" min="1" class="form-control" style="width: 8rem" id="cmk-version" name="version" value="<?php echo O::e($filter->version ?: '') ?>">
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-category"><?php echo t('Category') ?></label>
        <select class="form-select" id="cmk-category" name="category">
            <option value=""><?php echo t('Any') ?></option>
            <?php foreach ($categories as $handle) { ?>
                <option value="<?php echo O::e($handle) ?>" <?php echo O::e($filter->category === $handle ? 'selected' : '') ?>><?php echo O::e($handle) ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-id"><?php echo t('Consent ID') ?></label>
        <input type="text" class="form-control" style="width: 22rem" maxlength="64" id="cmk-id" name="id" value="<?php echo O::e($filter->consentId !== '' ? $filter->consentId : $filter->rejectedId) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary"><?php echo t('Filter') ?></button>
    </div>
</form>

<?php if ($filter->rejectedId !== '') { ?>
    <div class="alert alert-warning"><?php echo O::e(t('"%s" is not a valid consent ID, so it was ignored.', $filter->rejectedId)) ?></div>
<?php } ?>

<form method="post" action="<?php echo O::e($view->action('export')) ?>" class="mb-3">
    <?php echo $token->output('submit') ?>
    <input type="hidden" name="from" value="<?php echo O::e($filter->from) ?>">
    <input type="hidden" name="to" value="<?php echo O::e($filter->to) ?>">
    <input type="hidden" name="trigger" value="<?php echo O::e($filter->trigger) ?>">
    <input type="hidden" name="version" value="<?php echo O::e($filter->version ?: '') ?>">
    <input type="hidden" name="category" value="<?php echo O::e($filter->category) ?>">
    <input type="hidden" name="id" value="<?php echo O::e($filter->consentId) ?>">
    <button type="submit" class="btn btn-secondary"><?php echo t('Download CSV') ?></button>
    <?php if ($capped) { ?><span class="text-muted"><?php echo t('The export is limited to the newest 100,000 rows.') ?></span><?php } ?>
</form>

<?php if ($rows === []) { ?>
    <p><?php echo t('No consent records match.') ?></p>
<?php } else { ?>
    <table class="table table-striped">
        <thead><tr>
            <th><?php echo t('Date (UTC)') ?></th><th><?php echo t('Consent ID') ?></th><th><?php echo t('Version') ?></th>
            <th><?php echo t('Categories') ?></th><th><?php echo t('Trigger') ?></th><th><?php echo t('Locale') ?></th>
            <th><?php echo t('Browser') ?></th><th><?php echo t('Re-consent reason') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row) { ?>
            <tr>
                <td><?php echo O::e(gmdate('Y-m-d H:i', (int) $row['createdAt'])) ?></td>
                <td><a href="<?php echo O::e('?id=' . rawurlencode((string) $row['consentId'])) ?>"><code><?php echo O::e($row['consentId']) ?></code></a></td>
                <td><?php echo O::e((int) $row['version']) ?></td>
                <td><?php echo O::e(implode(', ', $row['categories'])) ?></td>
                <td><?php echo O::e($row['trigger']) ?></td>
                <td><?php echo O::e($row['locale']) ?></td>
                <td><?php echo O::e($row['userAgentFamily']) ?></td>
                <td><?php echo O::e($row['reason']) ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <?php if ($pages > 1) { ?>
        <div class="d-flex gap-1 flex-wrap">
            <?php for ($n = 1; $n <= $pages; $n++) { ?>
                <a class="btn btn-sm <?php echo O::e($n === $page ? 'btn-primary' : 'btn-outline-secondary') ?>" href="<?php echo O::e('?page=' . (int) $n . $suffix) ?>"><?php echo O::e($n) ?></a>
            <?php } ?>
        </div>
    <?php } ?>
<?php } ?>
```

The filter form, the table and the pagination follow `single_pages/dashboard/cookie_manager/findings.php` (same classes, same `O::e` plus `rawurlencode` for query values). The test stub passes `$total = 0`/`1`, so pagination is not rendered in `ConsentLogViewTest`; add one case there with `$total = 120` asserting that `?page=3` appears and that a hostile `category` filter (which `ConsentLogFilter` has already dropped) never reaches an `href`.

- [ ] **Step 5: Register the page**

In `controller.php::installOrUpgrade`, after the `scan_settings` line:

```php
        $this->addSinglePage('/dashboard/cookie_manager/consent_log', $pkg, t('Consent Log'), t('Who consented to what, and when'));
```

- [ ] **Step 6: Run tests**

Run: `composer test && composer typecheck`
Expected: PASS, including `ControllerSecurityTest` and `ViewEscapingTest` over the new files.

- [ ] **Step 7: Commit**

```bash
composer format
git add controllers single_pages controller.php tests
git commit -m "feat: add the Consent Log dashboard page with filters and CSV export

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Policy versions with a reason

**Files:**
- Create: `src/Support/PolicyVersions.php`
- Modify: `src/Support/Installer.php`, `controllers/single_page/dashboard/cookie_manager/banner.php`, `single_pages/dashboard/cookie_manager/banner.php`
- Test: `tests/Support/PolicyVersionsTest.php`, extend `tests/Support/InstallerTest.php`

**Interfaces:**
- Consumes: `Settings::bumpPolicyVersion()`, `PolicyVersionChange` (Task 1).
- Produces: `PolicyVersions::bump(EntityManagerInterface $em, Settings $settings, string $reason, int $adminUserId, ?\DateTimeImmutable $now = null): PolicyVersionChange` — persists the change and bumps the version; throws `\InvalidArgumentException` (reason empty) before touching anything. `PolicyVersions::history(EntityManagerInterface $em, int $limit = 10): list<PolicyVersionChange>`.

- [ ] **Step 1: Write the failing tests**

`tests/Support/PolicyVersionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\{PolicyVersionChange, Settings};
use CookieManagerKit\Support\PolicyVersions;
use Doctrine\ORM\EntityManagerInterface;

final class PolicyVersionsTest extends TestCase
{
    public function testBumpingIncrementsTheVersionAndRecordsWhy(): void
    {
        $settings = new Settings();
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });

        $change = PolicyVersions::bump($em, $settings, 'Added Google Analytics', 7, new \DateTimeImmutable('@1760616000'));

        $this->assertSame(2, $settings->getPolicyVersion());
        $this->assertSame(2, $change->getVersion());
        $this->assertSame('Added Google Analytics', $change->getReason());
        $this->assertSame(7, $change->getAdminUserId());
        $this->assertSame(1760616000, $change->getCreatedAt());
        $this->assertSame([$change], $persisted);
    }

    public function testAnEmptyReasonChangesNothing(): void
    {
        $settings = new Settings();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        try {
            PolicyVersions::bump($em, $settings, "  \n", 1);
            $this->fail('expected an exception');
        } catch (\InvalidArgumentException) {
            $this->assertSame(1, $settings->getPolicyVersion());
        }
    }

    public function testAnOverlongReasonIsCapped(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $change = PolicyVersions::bump($em, new Settings(), str_repeat('é', 800), 1);

        $this->assertSame(500, mb_strlen($change->getReason()));
    }
}
```

Add to `tests/Support/InstallerTest.php`:

```php
    public function testSeedRecordsTheInitialPolicyVersionOnce(): void
    {
        $this->assertSame('Initial version', Installer::INITIAL_REASON);
    }
```

(The persistence in `seed()` is covered by the install browser check in Task 12, since `seed()` is not unit-tested today; keep `INITIAL_REASON` as the one testable piece.)

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'PolicyVersionsTest|InstallerTest'`
Expected: FAIL.

- [ ] **Step 3: Implement**

`src/Support/PolicyVersions.php`:

```php
<?php

namespace CookieManagerKit\Support;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{PolicyVersionChange, Settings};

/**
 * The only way to bump the policy version: it needs a reason, and the reason is kept. The caller flushes and
 * flushes the Catalog cache.
 */
final class PolicyVersions
{
    /**
     * @throws \InvalidArgumentException when the reason is empty after cleaning; nothing has changed then
     */
    public static function bump(EntityManagerInterface $em, Settings $settings, string $reason, int $adminUserId, ?\DateTimeImmutable $now = null): PolicyVersionChange
    {
        $change = (new PolicyVersionChange())->setReason($reason);

        $settings->bumpPolicyVersion();

        $change
            ->setVersion($settings->getPolicyVersion())
            ->setAdminUserId($adminUserId)
            ->setCreatedAt(($now ?? new \DateTimeImmutable())->getTimestamp());

        $em->persist($change);

        return $change;
    }

    /**
     * @return list<PolicyVersionChange>
     */
    public static function history(EntityManagerInterface $em, int $limit = 10): array
    {
        return array_values($em->getRepository(PolicyVersionChange::class)->findBy([], ['version' => 'DESC'], max(1, $limit)));
    }
}
```

In `src/Support/Installer.php` add `public const INITIAL_REASON = 'Initial version';`, import `PolicyVersionChange` and, in `seed()` after the Settings block, add:

```php
        if ($em->getRepository(PolicyVersionChange::class)->findOneBy([]) === null) {
            $em->persist(
                (new PolicyVersionChange())
                    ->setVersion(Settings::get()->getPolicyVersion())
                    ->setReason(self::INITIAL_REASON)
                    ->setCreatedAt(time()),
            );
        }
```

`Settings::get()` reads the row, which may not be flushed yet on a first install; to avoid that, flush once before this block: put `$em->flush();` immediately after the Settings persist block, then the PolicyVersionChange block. (`seed()` already flushes at the end.)

- [ ] **Step 4: Banner controller and view**

In `controllers/single_page/dashboard/cookie_manager/banner.php` imports add `PolicyVersions`; in `save()` replace the `askAgain` block:

```php
        if ($this->post('askAgain') !== null) {
            try {
                PolicyVersions::bump($this->entityManager, $settings, Text::plain($this->post('askAgainReason'), 500), (int) (new \Concrete\Core\User\User())->getUserID());
            } catch (\InvalidArgumentException) {
                $this->flash('error', t('Say why visitors are being asked again, so the log can explain it.'));

                return $this->buildRedirect(self::URL)->send();
            }
        }
```

The early redirect leaves earlier unflushed changes unsaved, which is the desired "nothing saved on this error" behaviour, consistent with the other error paths in `save()`. Also in `view()` add `$this->set('history', PolicyVersions::history($this->entityManager));` (wrap in try/catch Throwable → `[]` for the pre-Update window).

In the Banner view, replace the "Ask every visitor again" block with:

```php
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="askAgain" name="askAgain" value="1">
            <label class="form-check-label" for="askAgain"><?php echo t('Ask every visitor again') ?></label>
            <div class="help-block form-text"><?php echo O::e(t('Tick this when your cookie policy changes. Current policy version: %s.', $settings->getPolicyVersion())) ?></div>
        </div>
        <div class="form-group mb-3">
            <label class="form-label" for="askAgainReason"><?php echo t('Why are visitors being asked again?') ?></label>
            <input type="text" class="form-control" maxlength="500" id="askAgainReason" name="askAgainReason" value="">
            <div class="help-block form-text"><?php echo t('Required when the box above is ticked. Kept in the consent log and its export.') ?></div>
        </div>

        <?php if ($history !== []) { ?>
            <table class="table table-sm">
                <thead><tr><th><?php echo t('Version') ?></th><th><?php echo t('Date (UTC)') ?></th><th><?php echo t('Reason') ?></th></tr></thead>
                <tbody>
                <?php foreach ($history as $change) { ?>
                    <tr>
                        <td><?php echo O::e($change->getVersion()) ?></td>
                        <td><?php echo O::e(gmdate('Y-m-d H:i', $change->getCreatedAt())) ?></td>
                        <td><?php echo O::e($change->getReason()) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
```

Add `/** @var list<CookieManagerKit\Entity\PolicyVersionChange> $history */` to the view's docblock. Do not remove the `bumpPolicyVersion()` method; `PolicyVersions` calls it.

- [ ] **Step 5: Run tests**

Run: `composer test && composer typecheck`
Expected: PASS (`SettingsTest` still covers `bumpPolicyVersion`).

- [ ] **Step 6: Commit**

```bash
composer format
git add src controllers single_pages tests
git commit -m "feat: require and record a reason whenever visitors are asked again

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Re-consent prompt after approving a finding

**Files:**
- Modify: `src/Scan/Review.php`, `controllers/single_page/dashboard/cookie_manager/findings.php`, `single_pages/dashboard/cookie_manager/findings.php`
- Test: extend `tests/Scan/ReviewTest.php`, `tests/Dashboard/FindingsViewTest.php`

**Interfaces:**
- Consumes: `PolicyVersions::bump` (Task 8).
- Produces:
  - `Review::reconsentReason(Service $service): ?string` — `null` when the service has no category or its category is `necessary`; otherwise `"Added <service name> to <category handle>"` run through `Text::plain(…, 500)`.
  - Findings page: after a successful `approve()` or `approveMatched()` that yields a non-null reason, the next `view()` shows a notice with an editable reason and an "Ask visitors again" button posting to a new `ask_again` action.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Scan/ReviewTest.php` (copy how existing tests build a `Category` and `Service`; use the same helpers):

```php
    public function testReconsentIsOfferedOnlyForOptionalCategories(): void
    {
        $analytics = (new Category())->setHandle('analytics')->setNecessary(false);
        $necessary = (new Category())->setHandle('necessary')->setNecessary(true);

        $this->assertSame('Added Google Analytics to analytics', Review::reconsentReason((new Service())->setName('Google Analytics')->setCategory($analytics)));
        $this->assertNull(Review::reconsentReason((new Service())->setName('Session')->setCategory($necessary)));
        $this->assertNull(Review::reconsentReason(new Service()));
    }

    public function testTheReasonIsPlainTextAndCapped(): void
    {
        $category = (new Category())->setHandle('marketing')->setNecessary(false);
        $reason = Review::reconsentReason((new Service())->setName("<b>Evil</b>\x00" . str_repeat('x', 900))->setCategory($category));

        $this->assertNotNull($reason);
        $this->assertLessThanOrEqual(500, mb_strlen($reason));
        $this->assertStringNotContainsString("\x00", $reason);
    }
```

(`Service::setName` may already clean; the assertions hold either way. If `Category::setNecessary`/`setHandle` signatures differ, read `src/Entity/Category.php` and adjust the calls only.)

Add to `tests/Dashboard/FindingsViewTest.php` (follow its existing render helper; add a `$reconsent` view variable, `null` for the existing tests):

```php
    public function testTheReconsentNoticeShowsAnEditableEncodedReasonAndAToken(): void
    {
        $html = self::renderWithReconsent('"><img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('name="reason"', $html);
        $this->assertStringContainsString('name="ccm_token"', $html);
        $this->assertStringContainsString('ask_again', $html);
    }

    public function testNoNoticeWithoutASuggestion(): void
    {
        $this->assertStringNotContainsString('ask_again', self::renderWithReconsent(null));
    }
```

where `renderWithReconsent(?string $reason)` is a small helper you add next to the file's existing render helper, setting `$reconsent = $reason` before the `include`.

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'ReviewTest|FindingsViewTest'`
Expected: FAIL.

- [ ] **Step 3: Implement**

In `src/Scan/Review.php` add:

```php
    /**
     * The pre-filled reason for asking visitors again after a service was added to the banner, or null when the
     * service's category is always on (visitors are not asked about necessary cookies) or it has none.
     */
    public static function reconsentReason(Service $service): ?string
    {
        $category = $service->getCategory();

        if ($category === null || $category->isNecessary()) {
            return null;
        }

        return Text::plain('Added ' . $service->getName() . ' to ' . $category->getHandle(), 500);
    }
```

In `controllers/single_page/dashboard/cookie_manager/findings.php`:

1. Imports: add `PolicyVersions`, `Settings`.
2. In `approve()`, after `Catalog::flush();` and before the success flash, add:

```php
        $this->suggestReconsent(Review::reconsentReason($service));
```

3. In `approveAllMatched()` (the action around line 305-331), after its `Catalog::flush();`, add: `foreach ($created as $service) { $this->suggestReconsent(Review::reconsentReason($service)); }` — the last non-null reason wins, which is acceptable because the admin edits it; if `$created` is empty nothing is suggested.
4. Add helpers and the new action:

```php
    private function suggestReconsent(?string $reason): void
    {
        if ($reason !== null) {
            $this->request->getSession()->getFlashBag()->set('cmk_reconsent', [$reason]);
        }
    }

    public function ask_again()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        try {
            PolicyVersions::bump($this->entityManager, Settings::get(), Text::plain($this->post('reason'), 500), (int) (new \Concrete\Core\User\User())->getUserID());
        } catch (\InvalidArgumentException) {
            $this->flash('error', t('Say why visitors are being asked again, so the log can explain it.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Visitors will be asked again.'));

        return $this->buildRedirect(self::URL)->send();
    }
```

5. In `view()`, add near the other `$this->set` calls:

```php
        $suggested = $this->request->getSession()->getFlashBag()->get('cmk_reconsent');
        $this->set('reconsent', is_array($suggested) && isset($suggested[0]) && is_string($suggested[0]) ? $suggested[0] : null);
```

If `ControllerSecurityTest::testOnlyPublicInstanceActionsOtherThanTheExemptOnesAreGated` requires snake-case names to be treated as actions, `ask_again` is a public instance method and will be checked for the method + token guards, which it has.

In the Findings view, add near the top, above the table:

```php
<?php if ($reconsent !== null) { ?>
    <div class="alert alert-info">
        <form method="post" action="<?php echo O::e($view->action('ask_again')) ?>" class="row g-2 align-items-end">
            <?php echo $token->output('submit') ?>
            <div class="col-md-8">
                <label class="form-label" for="cmk-reconsent-reason"><?php echo t('A service in an optional category was added. Ask every visitor again?') ?></label>
                <input type="text" class="form-control" maxlength="500" id="cmk-reconsent-reason" name="reason" value="<?php echo O::e($reconsent) ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary"><?php echo t('Ask visitors again') ?></button>
            </div>
        </form>
    </div>
<?php } ?>
```

and add `/** @var string|null $reconsent */` to the docblock. Existing view tests that include this view must set `$reconsent = null`; add that line to the shared render helper in `FindingsViewTest.php`.

- [ ] **Step 4: Run tests**

Run: `composer test && composer typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer format
git add src controllers single_pages tests
git commit -m "feat: offer to ask visitors again after a service is added to an optional category

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Settings plumbing for retention, Consent Mode and the default model

**Files:**
- Modify: `src/Support/Catalog.php`, `src/Support/ConfigBuilder.php`, `src/Support/LoaderScript.php`, `src/Events/ConsentManager.php`, `controllers/single_page/dashboard/cookie_manager/banner.php`, `single_pages/dashboard/cookie_manager/banner.php`
- Test: `tests/Support/ConfigBuilderTest.php`, `tests/Support/LoaderScriptTest.php`, `tests/ViewEscapingTest.php` (runs over the view)

**Interfaces:**
- Consumes: Task 1 settings.
- Produces:
  - Catalog snapshot `settings` gains `consentMode` (bool), `defaultModel` (`opt_in`|`opt_out`); `Catalog::consentMode(): bool`, `Catalog::defaultModel(): string`.
  - Config document gains top-level `consentMode: bool`, `defaultModel: string`.
  - `LoaderScript::boot(int $version, array $categories, int $lifetimeDays, string $configUrl, string $postUrl, ?string $nonce, string $eventUrl = '', string $scanUrl = '', bool $consentMode = false, string $model = 'opt_in')` returns the old keys plus `cm` (bool) and `model` (string).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Support/ConfigBuilderTest.php` (use the file's existing helper that builds a snapshot; if it is named differently adapt the call, the assertions are what matter):

```php
    public function testConsentModeAndDefaultModelReachTheConfig(): void
    {
        $data = ['settings' => ['consentMode' => true, 'defaultModel' => 'opt_out'], 'categories' => [], 'banner' => [], 'defaults' => []];
        $config = ConfigBuilder::build($data, 'en_GB', 'en_GB', []);

        $this->assertTrue($config['consentMode']);
        $this->assertSame('opt_out', $config['defaultModel']);
    }

    public function testTheirDefaultsAreOffAndOptIn(): void
    {
        $config = ConfigBuilder::build(['settings' => [], 'categories' => [], 'banner' => [], 'defaults' => []], 'en_GB', 'en_GB', []);

        $this->assertFalse($config['consentMode']);
        $this->assertSame('opt_in', $config['defaultModel']);
    }

    public function testAHostileModelFallsBackToOptIn(): void
    {
        $config = ConfigBuilder::build(['settings' => ['defaultModel' => '"><script>'], 'categories' => [], 'banner' => [], 'defaults' => []], 'en_GB', 'en_GB', []);

        $this->assertSame('opt_in', $config['defaultModel']);
    }

    public function testChangingTheModelChangesTheConfigVersion(): void
    {
        $a = ConfigBuilder::build(['settings' => ['defaultModel' => 'opt_in']], 'en_GB', 'en_GB', []);
        $b = ConfigBuilder::build(['settings' => ['defaultModel' => 'opt_out']], 'en_GB', 'en_GB', []);

        $this->assertNotSame($a['version'], $b['version']);
    }
```

Add to `tests/Support/LoaderScriptTest.php`:

```php
    public function testBootCarriesTheComplianceFlags(): void
    {
        $boot = LoaderScript::boot(2, ['necessary'], 180, '/c', '/p', null, '', '', true, 'opt_out');

        $this->assertTrue($boot['cm']);
        $this->assertSame('opt_out', $boot['model']);
    }

    public function testBootDefaultsToConsentModeOffAndOptIn(): void
    {
        $boot = LoaderScript::boot(2, ['necessary'], 180, '/c', '/p', null);

        $this->assertFalse($boot['cm']);
        $this->assertSame('opt_in', $boot['model']);
    }

    public function testAHostileModelIsNeverEmitted(): void
    {
        $this->assertSame('opt_in', LoaderScript::boot(2, [], 180, '/c', '/p', null, '', '', false, '"><script>')['model']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'ConfigBuilderTest|LoaderScriptTest'`
Expected: FAIL.

- [ ] **Step 3: Implement**

`src/Support/Catalog.php`: in `build()`'s `'settings'` array add:

```php
                'consentMode' => $settings->isConsentModeEnabled(),
                'defaultModel' => $settings->getDefaultModel(),
```

and add accessors after `lifetimeDays()`:

```php
    public static function consentMode(): bool
    {
        return (bool) (self::snapshot()['settings']['consentMode'] ?? false);
    }

    public static function defaultModel(): string
    {
        return ($model = self::snapshot()['settings']['defaultModel'] ?? 'opt_in') === 'opt_out' ? 'opt_out' : 'opt_in';
    }
```

Bump the cache key to `cookie_manager_kit/snapshot/v2` (existing cached snapshots lack the new keys; the accessors tolerate that, but a new key avoids a 12-hour mixed state).

`src/Support/ConfigBuilder.php`: in the `$config = [...]` array add before `'widget'`:

```php
            'consentMode' => (bool) ($settings['consentMode'] ?? false),
            'defaultModel' => ($settings['defaultModel'] ?? 'opt_in') === 'opt_out' ? 'opt_out' : 'opt_in',
```

`src/Support/LoaderScript.php`: extend the `boot()` signature, docblock return type (add `cm: bool, model: string`) and array:

```php
    public static function boot(int $version, array $categories, int $lifetimeDays, string $configUrl, string $postUrl, ?string $nonce, string $eventUrl = '', string $scanUrl = '', bool $consentMode = false, string $model = 'opt_in'): array
    {
        return [
            // ... existing keys ...
            'cm' => $consentMode,
            'model' => $model === 'opt_out' ? 'opt_out' : 'opt_in',
        ];
    }
```

`src/Events/ConsentManager.php`: append `Catalog::consentMode(), Catalog::defaultModel(),` to the `LoaderScript::boot(` call after the scan beacon path.

Banner controller `save()`: before `$settings->setLifetimeDays(...)` chain add:

```php
        $settings
            ->setConsentModeEnabled($this->post('consentMode') !== null)
            ->setDefaultModel($this->post('defaultModel'))
            ->setConsentLogRetentionMonths($this->post('retentionMonths'));
```

Banner view, inside the "Behaviour" fieldset, before the "askAgain" block:

```php
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="consentMode" name="consentMode" value="1" <?php echo O::e($settings->isConsentModeEnabled() ? 'checked' : '') ?>>
            <label class="form-check-label" for="consentMode"><?php echo t('Send Google Consent Mode v2 signals') ?></label>
            <div class="help-block form-text"><?php echo t('Starts every signal denied, then updates ad_storage, ad_user_data, ad_personalization, analytics_storage, functionality_storage, personalization_storage and security_storage from the visitor\'s choice. Scripts are still blocked by category as before.') ?></div>
        </div>

        <div class="form-group mb-3">
            <label class="form-label" for="defaultModel"><?php echo t('Before a visitor chooses') ?></label>
            <select class="form-select" style="max-width: 24rem" id="defaultModel" name="defaultModel">
                <option value="opt_in" <?php echo O::e($settings->getDefaultModel() === 'opt_in' ? 'selected' : '') ?>><?php echo t('Optional cookies are off until they agree (opt-in)') ?></option>
                <option value="opt_out" <?php echo O::e($settings->getDefaultModel() === 'opt_out' ? 'selected' : '') ?>><?php echo t('Optional cookies are on until they object (opt-out)') ?></option>
            </select>
            <div class="help-block form-text"><?php echo t('Opt-out is not suitable for visitors in the EU or UK. A browser that sends Global Privacy Control always starts with Marketing off.') ?></div>
        </div>

        <div class="form-group mb-3">
            <label class="form-label" for="retentionMonths"><?php echo t('Keep a browser\'s consent history for (months after its last choice)') ?></label>
            <input type="number" class="form-control" style="max-width: 12rem" id="retentionMonths" name="retentionMonths" min="1" max="120" step="1" value="<?php echo O::e($settings->getConsentLogRetentionMonths()) ?>">
            <div class="help-block form-text"><?php echo t('Between 1 and 120. Older records are deleted by the "Purge old consent records" scheduled task and cannot be recovered.') ?></div>
        </div>
```

- [ ] **Step 4: Run tests**

Run: `composer test && composer typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer format
git add src controllers single_pages tests
git commit -m "feat: settings for Consent Mode, the default model and consent log retention

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Consent Mode v2 in the loader

**Files:**
- Modify: `js/cmk-loader.js`
- Test: `tests/js/loader.test.js`

**Interfaces:**
- Consumes: `boot.cm` (bool) from Task 10.
- Produces: when `boot.cm === true`: `window.dataLayer` and `window.gtag` exist (created if absent); `gtag('consent','default',{…denied…, wait_for_update: 500})` is the first consent call on every page; `gtag('consent','update',{…})` follows immediately when a valid choice exists, and again on every `consent:changed`. Nothing Google-specific is touched when `boot.cm` is not `true`.

Signal object order: `ad_storage, ad_user_data, ad_personalization, analytics_storage, functionality_storage, personalization_storage, security_storage`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/js/loader.test.js`:

```js
describe('Consent Mode v2', () => {
	const SIGNALS = [
		'ad_storage',
		'ad_user_data',
		'ad_personalization',
		'analytics_storage',
		'functionality_storage',
		'personalization_storage',
		'security_storage'
	];
	const calls = () => window.dataLayer.map((args) => [...args]);

	beforeEach(() => {
		delete window.dataLayer;
		delete window.gtag;
	});

	it('emits nothing when it is off', () => {
		start({ ...BOOT, cm: false });

		expect(window.dataLayer).toBeUndefined();
		expect(window.gtag).toBeUndefined();
	});

	it('emits nothing when cm is not exactly true', () => {
		start({ ...BOOT, cm: 'yes' });

		expect(window.dataLayer).toBeUndefined();
	});

	it('denies every signal by default, before anything else, with wait_for_update', () => {
		start({ ...BOOT, cm: true });
		const [first] = calls();

		expect(first[0]).toBe('consent');
		expect(first[1]).toBe('default');
		expect(Object.keys(first[2]).sort()).toEqual([...SIGNALS, 'wait_for_update'].sort());
		SIGNALS.filter((s) => s !== 'security_storage').forEach((s) => expect(first[2][s]).toBe('denied'));
		expect(first[2].security_storage).toBe('granted');
		expect(first[2].wait_for_update).toBe(500);
		expect(calls()).toHaveLength(1);
	});

	it('applies a stored choice in the same tick as the default', () => {
		setConsent(valid(['necessary', 'analytics']));
		start({ ...BOOT, cm: true });
		const all = calls();

		expect(all[0][1]).toBe('default');
		expect(all[1][1]).toBe('update');
		expect(all[1][2]).toMatchObject({
			analytics_storage: 'granted',
			ad_storage: 'denied',
			ad_user_data: 'denied',
			ad_personalization: 'denied',
			functionality_storage: 'denied',
			personalization_storage: 'denied',
			security_storage: 'granted'
		});
	});

	it.each([
		[['necessary', 'preferences'], { functionality_storage: 'granted', personalization_storage: 'granted', analytics_storage: 'denied', ad_storage: 'denied' }],
		[['necessary', 'marketing'], { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted', analytics_storage: 'denied' }],
		[['necessary'], { ad_storage: 'denied', analytics_storage: 'denied', functionality_storage: 'denied', security_storage: 'granted' }]
	])('maps %j', (categories, expected) => {
		start({ ...BOOT, cm: true });
		window.cmk.save(categories);
		const updates = calls().filter((c) => c[1] === 'update');

		expect(updates.at(-1)[2]).toMatchObject(expected);
	});

	it('updates on every change of choice, and a withdrawal denies again', () => {
		start({ ...BOOT, cm: true });
		window.cmk.save(['analytics']);
		window.cmk.save([]);
		const updates = calls().filter((c) => c[1] === 'update');

		expect(updates).toHaveLength(2);
		expect(updates[0][2].analytics_storage).toBe('granted');
		expect(updates[1][2].analytics_storage).toBe('denied');
	});

	it('keeps an existing dataLayer and gtag and only appends', () => {
		window.dataLayer = [['js', 'x']];
		const existing = function () {};
		window.gtag = existing;
		start({ ...BOOT, cm: true });

		expect(window.gtag).toBe(existing);
		expect(window.dataLayer[0]).toEqual(['js', 'x']);
	});

	it('a category with no signal (custom) changes nothing Google sees', () => {
		start({ ...BOOT, cats: [...BOOT.cats, 'social'], cm: true });
		window.cmk.save(['social']);
		const last = calls().filter((c) => c[1] === 'update').at(-1)[2];

		SIGNALS.filter((s) => s !== 'security_storage').forEach((s) => expect(last[s]).toBe('denied'));
	});
});
```

Note on the `gtag` shim: a real `gtag` pushes the `arguments` object into `dataLayer`, which is why the tests spread `[...args]`. The loader must push `arguments`, not an array, because Google's tag only recognises `arguments` objects.

- [ ] **Step 2: Run to verify failure**

Run: `npx vitest run tests/js/loader.test.js`
Expected: FAIL.

- [ ] **Step 3: Implement**

In `js/cmk-loader.js`, after the `known` setup and before `function readRaw()`, add:

```js
	/* Google Consent Mode v2: the fixed category-to-signal mapping, in one place. */
	var SIGNALS = {
		ad_storage: ['marketing'],
		ad_user_data: ['marketing'],
		ad_personalization: ['marketing'],
		analytics_storage: ['analytics'],
		functionality_storage: ['preferences'],
		personalization_storage: ['preferences'],
		security_storage: [NECESSARY]
	};
	var consentMode = boot.cm === true;

	function signalsFor(granted) {
		var out = {};

		Object.keys(SIGNALS).forEach(function (name) {
			out[name] = SIGNALS[name].some(function (handle) {
				return granted.indexOf(handle) !== -1;
			})
				? 'granted'
				: 'denied';
		});

		return out;
	}

	function gtagConsent(mode, state) {
		/* Google's tag only recognises an `arguments` object in the dataLayer, so use the standard shim. */
		w.dataLayer = w.dataLayer || [];
		w.gtag =
			w.gtag ||
			function () {
				w.dataLayer.push(arguments);
			};
		w.gtag('consent', mode, state);
	}

	function consentModeDefault() {
		var state = signalsFor([]);

		state.wait_for_update = 500;
		gtagConsent('default', state);
	}
```

In `save()`, just before `activate();` add:

```js
		if (consentMode) {
			gtagConsent('update', signalsFor(list));
		}
```

At the init point, replace `current = parse(raw); prior = ...` block with:

```js
	current = parse(raw);
	prior = current ? null : readPrior(raw);

	if (consentMode) {
		consentModeDefault();

		if (current) {
			gtagConsent('update', signalsFor(current.c));
		}
	}
```

Because `signalsFor([])` leaves `security_storage` denied but the tests and the spec require it granted by default, make `security_storage` always granted: in `signalsFor`, treat `NECESSARY` as always present: change the first line of `signalsFor` to `granted = granted.concat([NECESSARY]);`.

- [ ] **Step 4: Run tests**

Run: `npx vitest run && npx prettier --check js tests/js`
Expected: PASS. Run `npx prettier --write js tests/js` if needed.

- [ ] **Step 5: Commit**

```bash
git add js tests/js
git commit -m "feat: send Google Consent Mode v2 signals from the loader

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Default model and GPC in the loader and runtime

**Files:**
- Modify: `js/cmk-loader.js`, `js/cmk-runtime.js`
- Test: `tests/js/loader.test.js`, `tests/js/runtime.test.js`

**Interfaces:**
- Consumes: `boot.model` (`opt_in`|`opt_out`) from Task 10; `config.defaultModel`.
- Produces on `window.cmk`:
  - `gpc(): boolean` — `navigator.globalPrivacyControl === true`.
  - `initial(): string[]` — the starting selection when there is no stored choice: `['necessary']` for opt-in; every known handle for opt-out; `marketing` removed when `gpc()`.
  - `has()`/activation use `initial()` while there is no stored choice.
  - Consent Mode: with no stored choice, the loader sends `default` (all denied) and, if `initial()` grants anything beyond necessary, an immediate `update` for `initial()`.
- Runtime: the preference centre's boxes start from `cmk.initial()` when there is no stored choice; a note "Pre-set by your browser's Global Privacy Control signal." appears beside Marketing when `cmk.gpc()` and `marketing` exists and there is no stored choice; the log trigger is `gpc` when no previous choice, GPC is on, and the saved set equals `initial()` (`reconsent` still wins).

- [ ] **Step 1: Write the failing loader tests**

Append to `tests/js/loader.test.js`:

```js
describe('the default model and GPC', () => {
	const setGpc = (value) => Object.defineProperty(window.navigator, 'globalPrivacyControl', { value, configurable: true });

	afterEach(() => {
		delete window.navigator.globalPrivacyControl;
		delete window.dataLayer;
		delete window.gtag;
	});

	it('opt-in starts with only necessary', () => {
		start({ ...BOOT, model: 'opt_in' });

		expect(window.cmk.initial()).toEqual(['necessary']);
	});

	it('opt-out starts with every category', () => {
		start({ ...BOOT, model: 'opt_out' });

		expect(window.cmk.initial()).toEqual(['necessary', 'preferences', 'analytics', 'marketing']);
	});

	it('an unknown or missing model is opt-in', () => {
		start({ ...BOOT, model: '"><script>' });
		expect(window.cmk.initial()).toEqual(['necessary']);

		start({ ...BOOT });
		expect(window.cmk.initial()).toEqual(['necessary']);
	});

	it('GPC removes marketing from the starting selection in either model', () => {
		setGpc(true);
		start({ ...BOOT, model: 'opt_out' });
		expect(window.cmk.gpc()).toBe(true);
		expect(window.cmk.initial()).toEqual(['necessary', 'preferences', 'analytics']);

		start({ ...BOOT, model: 'opt_in' });
		expect(window.cmk.initial()).toEqual(['necessary']);
	});

	it('only a real true counts as GPC', () => {
		setGpc('1');
		start({ ...BOOT });
		expect(window.cmk.gpc()).toBe(false);
	});

	it('opt-out runs optional scripts before any choice, GPC keeps marketing inert', () => {
		document.body.innerHTML =
			'<script type="text/plain" data-cmk-category="analytics">a()</script>' +
			'<script type="text/plain" data-cmk-category="marketing">m()</script>';
		setGpc(true);
		start({ ...BOOT, model: 'opt_out' });

		expect(live().map((s) => s.text)).toEqual(['a()']);
		expect(window.cmk.has('analytics')).toBe(true);
		expect(window.cmk.has('marketing')).toBe(false);
		expect(window.cmk.get()).toBeNull();
	});

	it('opt-out writes no cookie until the visitor acts', () => {
		start({ ...BOOT, model: 'opt_out' });

		expect(document.cookie).not.toContain('cmk_consent');
	});

	it('a stored choice always beats the model', () => {
		setConsent(valid(['necessary']));
		start({ ...BOOT, model: 'opt_out' });

		expect(window.cmk.has('analytics')).toBe(false);
	});

	it('Consent Mode applies the opt-out starting selection straight after the default', () => {
		start({ ...BOOT, model: 'opt_out', cm: true });
		const calls = window.dataLayer.map((a) => [...a]);

		expect(calls[0][1]).toBe('default');
		expect(calls[1][1]).toBe('update');
		expect(calls[1][2]).toMatchObject({ analytics_storage: 'granted', ad_storage: 'granted' });
	});

	it('Consent Mode under opt-out and GPC keeps ad signals denied', () => {
		setGpc(true);
		start({ ...BOOT, model: 'opt_out', cm: true });
		const update = window.dataLayer.map((a) => [...a]).find((c) => c[1] === 'update');

		expect(update[2]).toMatchObject({ analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
	});

	it('Consent Mode under opt-in sends no update before a choice', () => {
		start({ ...BOOT, model: 'opt_in', cm: true });

		expect(window.dataLayer.map((a) => [...a]).filter((c) => c[1] === 'update')).toHaveLength(0);
	});
});
```

- [ ] **Step 2: Run to verify failure**

Run: `npx vitest run tests/js/loader.test.js`
Expected: FAIL.

- [ ] **Step 3: Implement the loader**

In `js/cmk-loader.js`:

1. After `var consentMode = boot.cm === true;` add `var optOut = boot.model === 'opt_out';`.
2. Before `function readRaw()` add:

```js
	function gpc() {
		try {
			return w.navigator.globalPrivacyControl === true;
		} catch (e) {
			return false;
		}
	}

	/* What is on before the visitor has chosen anything. A stored choice always replaces it. */
	function initial() {
		var list = optOut ? known.slice() : [NECESSARY];

		if (gpc()) {
			list = list.filter(function (handle) {
				return handle !== 'marketing';
			});
		}

		return list;
	}
```

3. In `activate()` change `var granted = current ? current.c : [NECESSARY];` to `var granted = current ? current.c : initial();`.
4. In the init block from Task 11, replace

```js
		if (current) {
			gtagConsent('update', signalsFor(current.c));
		}
```
with:

```js
		if (current) {
			gtagConsent('update', signalsFor(current.c));
		} else if (initial().length > 1) {
			gtagConsent('update', signalsFor(initial()));
		}
```

5. In the `w.cmk` object, change `has` and add `gpc` / `initial`:

```js
		has: function (handle) {
			return (current ? current.c : initial()).indexOf(handle) !== -1;
		},
		gpc: gpc,
		initial: initial,
```

(`has('necessary')` is still true with no choice because `initial()` always contains it.)

6. In `save()`, `previous.c` logic is unchanged. Under opt-out the "previous" categories reported in `consent:changed` stay `null` when there was no stored choice; that matches the existing meaning.

- [ ] **Step 4: Write the failing runtime tests**

Append to `tests/js/runtime.test.js`:

```js
describe('the default model and GPC in the banner', () => {
	const setGpc = (value) => Object.defineProperty(window.navigator, 'globalPrivacyControl', { value, configurable: true });
	const boxes = () => [...document.querySelectorAll('input[data-cmk-cat]')];
	const box = (handle) => boxes().find((b) => b.getAttribute('data-cmk-cat') === handle);
	const body = () => JSON.parse(window.fetch.mock.calls[0][1].body);

	afterEach(() => {
		delete window.navigator.globalPrivacyControl;
	});

	it('opt-in: optional boxes start unticked', () => {
		mount(config(), { ...BOOT, model: 'opt_in' });
		button('Customise').click();

		expect(box('analytics').checked).toBe(false);
	});

	it('opt-out: optional boxes start ticked', () => {
		mount(config(), { ...BOOT, model: 'opt_out' });
		button('Customise').click();

		expect(box('analytics').checked).toBe(true);
		expect(box('marketing').checked).toBe(true);
	});

	it('GPC: marketing starts unticked with a note, the rest follow the model, the banner still shows', () => {
		setGpc(true);
		mount(config(), { ...BOOT, model: 'opt_out' });
		button('Customise').click();

		expect(root()).not.toBeNull();
		expect(box('analytics').checked).toBe(true);
		expect(box('marketing').checked).toBe(false);
		expect(prefs().textContent).toContain('Global Privacy Control');
	});

	it('GPC: the note is plain text and appears only once, only for marketing', () => {
		setGpc(true);
		mount();
		button('Customise').click();

		expect(prefs().textContent.split('Global Privacy Control').length - 1).toBe(1);
		expect(prefs().querySelector('script')).toBeNull();
	});

	it('no GPC note without GPC', () => {
		mount();
		button('Customise').click();

		expect(prefs().textContent).not.toContain('Global Privacy Control');
	});

	it('GPC: confirming the starting selection unchanged logs the gpc trigger', () => {
		setGpc(true);
		mount(config(), { ...BOOT, model: 'opt_in' });
		button('Customise').click();
		button('Save').click();

		expect(body().g).toBe('gpc');
		expect(body().c).toEqual(['necessary']);
	});

	it('GPC: a different choice uses the normal trigger', () => {
		setGpc(true);
		mount();
		button('Accept all').click();

		expect(body().g).toBe('accept_all');
	});

	it('without GPC an unchanged selection is not logged as gpc', () => {
		mount();
		button('Customise').click();
		button('Save').click();

		expect(body().g).toBe('reject_all');
	});

	it('re-consent wins over gpc', () => {
		setGpc(true);
		document.cookie = 'cmk_consent=' + encodeURIComponent(JSON.stringify({ v: 1, c: ['necessary'], t: 1700000000 })) + '; Path=/';
		mount();
		button('Customise').click();
		button('Save').click();

		expect(body().g).toBe('reconsent');
	});

	it('opt-out: nothing is posted or stored until the visitor acts', () => {
		mount(config(), { ...BOOT, model: 'opt_out' });

		expect(window.fetch).not.toHaveBeenCalled();
		expect(window.cmk.get()).toBeNull();
	});
});
```

(If the `button('Save')` label in the existing fixture is `'Save'`, as `config()` defines `labels.save: 'Save'`, the selectors above are right. The first test file lines show `labels: { …, save: 'Save', … }`.)

- [ ] **Step 5: Implement the runtime**

In `js/cmk-runtime.js`:

1. In `build()` (`js/cmk-runtime.js:342`) replace `var granted = cmk.get() ? cmk.get().c : [];` with:

```js
		var stored = cmk.get();
		var granted = stored ? stored.c : cmk.initial();
		var gpcNote = !stored && cmk.gpc();
```

   The existing line `box.checked = necessary || granted.indexOf(category.handle) !== -1;` then needs no change: with no stored choice the boxes follow `initial()`.

2. In the same `categories.forEach`, add the note as one more child of the category's `el('div', { class: 'cmk__cat' }, [...])`, after the `servicesList(category)` entry and before `necessary ? cookiesList(category) : null`:

```js
					gpcNote && category.handle === 'marketing'
						? el('p', {
								class: 'cmk__note',
								text: "Pre-set by your browser's Global Privacy Control signal."
							})
						: null,
```

   (`el()` with `text` sets `textContent`, so the note is never parsed as markup; `el()` already skips `null` children, as the neighbouring entries rely on.)

3. Extend `triggerFor` from Task 4:

```js
	function sameSet(a, b) {
		return (
			a.length === b.length &&
			a.every(function (handle) {
				return b.indexOf(handle) !== -1;
			})
		);
	}

	function triggerFor(reconsent, previous, saved, categories) {
		if (reconsent) {
			return 'reconsent';
		}

		if (!previous && cmk.gpc() && sameSet(saved, cmk.initial())) {
			return 'gpc';
		}

		return classify(saved, categories);
	}
```

and in `commit()` capture the initial selection **before** `cmk.save()` (the call changes `has()` but `initial()` is independent of the stored choice, so order does not matter for correctness; still compute `previous` before saving as the code already does) and call `triggerFor(reconsent, previous, saved, categories)`.

Note the Task 4 runtime test "uses the reconsent trigger and keeps the id after a policy bump" and the stats-metric `choiceEvent` are unchanged.

- [ ] **Step 6: Add one line of CSS for the note**

In `css/` (open the existing stylesheet for the banner, `css/cmk.css` or similar) add `.cmk__note{margin:.25rem 0 0;font-size:.85em;opacity:.8}`. Keep the file's existing formatting style.

- [ ] **Step 7: Run tests**

Run: `npx vitest run && npx prettier --check . && composer test`
Expected: PASS. Fix Prettier with `npx prettier --write js tests/js css`.

- [ ] **Step 8: Commit**

```bash
git add js css tests/js
git commit -m "feat: honour the default model and Global Privacy Control in the loader and banner

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Release housekeeping, full verification, browser check

**Files:**
- Modify: `controller.php` (`$pkgVersion = '0.4.0'`), `CHANGELOG.md`, `README.md`, `elements/dashboard/uninstall.php`

- [ ] **Step 1: Version, uninstall text, changelog, README**

`controller.php`: `protected $pkgVersion = '0.4.0';`

`elements/dashboard/uninstall.php`: change the checkbox label to `Also delete all banner text, categories, services, statistics, the consent log and settings (this cannot be undone)`.

`CHANGELOG.md`: add above `## 0.3.0`:

```markdown
## 0.4.0

Phase 3: audit log, re-consent, Consent Mode v2 and privacy rules.

### Added

- Consent Log (`/dashboard/cookie_manager/consent_log`): every choice a visitor makes is recorded under a random consent ID kept in their `cmk_consent` cookie (as `i`). A later change by the same browser adds an entry to the same record. Filter by date, trigger, policy version, category or consent ID, and download a CSV that includes the re-consent reason. No IP address, region or raw user agent is stored; the user agent is reduced to a coarse bucket such as `Chrome/desktop`.
- Retention: the scheduled task "Purge old consent records" (`cmk_purge_consent_log`) deletes a browser's history a set number of months (default 24, Banner page) after its last choice.
- Re-consent: asking visitors again now needs a reason, which is kept in a history shown on the Banner page and in the log export. Approving a scanner finding that adds a service to an optional category offers a one-click "Ask visitors again" (never automatic).
- Google Consent Mode v2 (off by default, Banner page): every signal starts denied and updates from the visitor's choice (analytics → `analytics_storage`; marketing → `ad_storage`, `ad_user_data`, `ad_personalization`; preferences → `functionality_storage`, `personalization_storage`).
- Default model (Banner page): opt-in (default) or opt-out before a visitor chooses. Browsers that send Global Privacy Control always start with Marketing off, and the banner is still shown.

### Upgrade notes

- Run **Update** for the package in the dashboard. This creates the three consent tables, the Consent Log page, registers the purge task (tasks are only registered on install or upgrade) and records the current policy version as "Initial version".
- Visitors who already have a consent cookie keep it. Their next choice creates their consent record.
- Opt-out is not suitable for visitors in the EU or UK.
```

`README.md`: add a short "Consent log and privacy" section stating what is stored (consent ID, policy version, categories, trigger, locale, coarse browser/device bucket, time), what is not (IP, region, raw user agent), the 24-month default retention and how to change it, and the Consent Mode and GPC behaviour, in the README's existing tone.

- [ ] **Step 2: Run the whole suite**

Run: `composer test && npx vitest run && composer typecheck && composer format:check`
Expected: all PASS. Fix any formatting with `composer format`.

- [ ] **Step 3: Commit**

```bash
git add controller.php CHANGELOG.md README.md elements
git commit -m "chore: release 0.4.0

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 4: Browser check on the local dev site**

Use the `local-dev-site` memory (site `https://newslettesignup.local/`; the user signs in to the dashboard themselves, never enter passwords; the package is symlinked so the working tree is live). Ask the user to sign in and to run Dashboard > Extend Concrete > Update Add-Ons > Update for Cookie Manager (a second click may be needed because of opcache). Then, with the Chrome tools, verify and report each line as pass/fail with evidence (do not claim success for any line you could not exercise):

1. Dashboard > Cookie Manager > Consent Log loads empty without errors; Banner page shows the reason field, history table (one "Initial version" row), Consent Mode, default model and retention fields; console clean.
2. Front end, fresh browser profile (no cookie): banner shows; Accept all → `cmk_consent` cookie now contains `"i":"<guid>"` (read via `document.cookie`); Consent Log shows one row.
3. Reopen via the widget, change to Reject all → the cookie's `i` is unchanged; Consent Log shows two rows under the same ID (click the ID to filter).
4. Banner page: tick "Ask every visitor again" with a reason, save → banner reappears; answer → cookie `i` unchanged; the new log row has trigger `reconsent` and the reason appears in the table; Download CSV and confirm the reason column.
5. Enable Consent Mode, save, reload with no cookie: `window.dataLayer` starts with a `consent default` entry, all denied except `security_storage`; after Accept all a `consent update` follows with granted signals.
6. Set default model to opt-out; fresh profile: Customise shows optional boxes ticked; no cookie written and no request to `/cookie-manager/consent` before choosing. Repeat with Global Privacy Control enabled (set `Object.defineProperty(navigator,'globalPrivacyControl',{value:true})` before the page loads via a bookmarklet-free approach, or use Chrome's GPC flag/extension; if neither is possible say so) and confirm Marketing is unticked with the note.
7. Page source is identical with and without a cookie (compare the HTML of two requests with and without the cookie header using `curl`).
8. Console: no errors on any page above.

- [ ] **Step 5: Update memory and report**

Update `/Users/david/.claude/projects/-Users-david-Sites-localhost-Composer-C5-Packages-CookieManagerKit/memory/cookie-manager-scanner-phase2.md`: phase 2 is merged to `develop`; phase 3 is specced and planned (`docs/superpowers/specs|plans/2026-10-16-cookie-manager-compliance*`), implemented on `feature/compliance` (state the actual outcome and whether it is merged); remaining roadmap is phases 4 and 5. Report the verification results to the user, including anything that failed or could not be tested.

---

## Self-Review

**Spec coverage**

| Spec | Task |
|---|---|
| §4 entities, settings | 1 |
| §5 endpoint fields, flow, response, failure | 2, 3 |
| §5 runtime/loader cookie `i` | 4 |
| §5 retention task | 5 |
| §5 dashboard, filters, CSV (incl. reason) | 6, 7 |
| §6 reason, history, reconsent trigger, prompt | 4 (trigger), 8, 9 |
| §7 Consent Mode | 10 (setting, config, boot), 11 |
| §8 default model, GPC, trigger, note | 10, 12 |
| §9 security | endpoint 2/3, admin 7, CSV 6 |
| §10 testing | each task; browser Task 13 |
| §11 housekeeping | 1 (tables), 7 (page), 8 (seed), 13 |

**Placeholder scan:** none left. Task 7's filter form and pagination and Task 12's checkbox and note changes are written out in full against the current code. Still to confirm by running, not by reading: the exact `Text::plain` behaviour on `<`/`>` (Task 6 filter test asserts only length), `Category::setNecessary`/`Service::setCategory` signatures (Task 9 tests), the `OutputAwareTrait` setter name (Task 5 tests), and `StatsRange::parse`'s return keys (Task 6). Each task says where to look if the call differs.

**Type consistency:** `ConsentChoice` constructor order `(policyVersion, categories, trigger, locale, userAgentFamily, subjectId)` is used identically in Tasks 3 (tests, store, endpoint). `ConsentRequest::parse` returns `trigger`/`locale`/`i`, consumed in the endpoint exactly so. `PolicyVersions::bump($em, $settings, $reason, $adminId, $now)` matches Tasks 8 and 9. `ConsentLogFilter::parse` argument order `(from, to, trigger, version, category, consentId, today)` is the same in Tasks 6 and 7. `cmk.id/reconsent/setId/gpc/initial` names match between loader tasks and runtime use. Row shape keys (`createdAt consentId version categories trigger locale userAgentFamily reason`) match reader, CSV and view.
