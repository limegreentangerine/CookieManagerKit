# CookieManagerKit Phase 4 (Declaration, Embeds, Translations) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship 0.5.0: an admin-curated cookie list per Service with a `cookie_declaration` block (4a), automatic click-to-accept placeholders for iframes from known services (4b), and a translation completeness view with copy-from-default and a CSV round trip (4c).

**Architecture:** Same shape as phases 2 and 3: logic in small pure classes tested without a database or Concrete (`CookieForm`, `CookieDeclaration`, `EmbedRewriter`, `EmbedConfig`, `TranslationReport`, `TranslationCsv`), thin Concrete adapters (block controller, dashboard controllers, `ConsentManager::onPageOutput`), and the browser side in the existing loader (`js/cmk-loader.js`, activation) and runtime (`js/cmk-runtime.js`, the accept button). The cached `Catalog` snapshot carries everything the front end needs, so no extra queries run per page view.

**Tech Stack:** PHP 8.4, ConcreteCMS 9.5, Doctrine ORM annotations, ClassKit entity bases and `BlockTrait`, PHPUnit 11, Vitest 3 + jsdom, PHPStan, php-cs-fixer, Prettier.

**Spec:** `docs/superpowers/specs/2026-10-17-cookie-manager-declaration-embeds-design.md` (read it first; section numbers below refer to it).

## Global Constraints

- Tests: `composer test` (PHPUnit; `composer test -- --filter Name` for one class), `npx vitest run` (JS), `composer typecheck`, `composer format:check`. Run `composer format` before committing if `format:check` fails.
- Page HTML stays identical for every visitor whatever the consent cookie says; the placeholder is always rendered and the loader removes it in the browser. (Spec §1, §3)
- Every dynamic value in a view or generated markup goes through `Output::e()` (`CookieManagerKit\Support\Output as O` in views); admin text is stored through `Text::plain` / `Text::multiline`; URLs through `Output::safeUrl`. Admin JS and runtime use `textContent`, never `innerHTML`.
- Dashboard writes: POST only, `$this->token->validate('submit')`, redirect on failure; the CSV export is a POST like the consent log's (no data in query strings).
- CSV cells go through `CsvCell::row` (formula neutralising, quoting).
- Cookie: `name` max 128, `durationDays` 0 to 3650 (0 = session), purpose max 500, unique `(serviceID, name)`. Tables `cmkCookies`, `cmkCookieContents`.
- Embeds: only `http`/`https` iframes are matched; the category of the matching active Service must not be `necessary`; matching is host equals a service domain or is a subdomain of it (a leading `*.` in a domain is ignored); `srcdoc` iframes are left alone; a matched iframe that cannot be rewritten safely is removed and reported through `ErrorLog`.
- Embed text defaults (in `Catalog::DEFAULTS`): `embedMessage` = `This content is provided by {service} and needs your consent to load.`, `embedAcceptLabel` = `Accept {category} cookies`. `{service}` and `{category}` are replaced with the Service name and the category title.
- Translation CSV: columns `key,area,default_text,translation`; first line `# locale=<locale>`; keys `area|reference|field` (reference: category handle, numeric service/cookie ID, empty for banner); max 512 KB and 5000 rows; the default locale can never be a target; unknown key, wrong locale, duplicate key or malformed file rejects the whole file and writes nothing.
- Version `0.5.0` in `controller.php`. Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Deliberate deviations from the spec text: (1) the declaration block and the embed rewriter read services, cookies and domains from the cached `Catalog` snapshot (not the database), so `Catalog::flush()` must follow every write that changes them; (2) the CSV export is a POST (consistent with the consent log), not a GET.

## Review Focus

1. A host that only looks like a service domain (`evilyoutube.com`, `youtube.com.evil.com`, `https://youtube.com@evil.com/`) must not match; a real subdomain must (Task 7).
2. An `<iframe` inside a script, style, template, noscript or HTML comment must not be rewritten, and the surrounding HTML must be left byte-identical; an unterminated matched iframe is removed (Task 7).
3. Hostile admin text (a purpose or placeholder message containing quotes, `<script>` or `{service}`-style tokens) is escaped in the declaration block and in the placeholder (Tasks 5 and 7).
4. A CSV import with the wrong locale, an unknown key, the default locale as target, a duplicate key or an oversized file changes nothing; an exported cell starting with `=` is neutralised (Task 11).
5. A site with no cookies defined shows only the essential cookies in the block; a page with no `<iframe` pays no snapshot cost and its HTML is untouched (Tasks 5 and 8).

Each is pinned by a named test in the owning task.

---

## File Structure

**Create**
- `src/Entity/Cookie.php`, `src/Entity/CookieContent.php`: the cookie list.
- `src/Support/CookieForm.php`: parse and apply the posted cookie rows.
- `src/Support/CookieDeclaration.php`: builds the declaration sections from the snapshot.
- `blocks/cookie_declaration/controller.php`, `view.php`, `db.xml`: the block.
- `src/Support/EmbedRewriter.php`, `src/Support/EmbedConfig.php`: iframe blocking.
- `src/Translation/Slot.php`, `TranslationSlots.php`, `TranslationReport.php`, `TranslationCsv.php`.
- `controllers/single_page/dashboard/cookie_manager/translations.php`, `single_pages/dashboard/cookie_manager/translations.php`.
- Tests mirror each file under `tests/` (named in each task).

**Modify**
- `src/Entity/Service.php` (cookies relation), `src/Entity/BannerContent.php` (embed fields)
- `src/Scan/Review.php` (approve pre-fills a cookie)
- `src/Support/Catalog.php` (snapshot: service domains and cookies, banner embed fields, defaults)
- `src/Events/ConsentManager.php` (embed rewrite in `onPageOutput`)
- `controllers/single_page/dashboard/cookie_manager/services.php`, `banner.php`; `single_pages/dashboard/cookie_manager/services.php`, `banner.php`
- `js/cmk-loader.js`, `js/cmk-runtime.js`, `css/cmk.css`
- `tests/ViewEscapingTest.php` (scan `blocks/` too)
- `controller.php`, `CHANGELOG.md`, `README.md`

---

### Task 1: Cookie and CookieContent entities

**Files:**
- Create: `src/Entity/Cookie.php`, `src/Entity/CookieContent.php`
- Modify: `src/Entity/Service.php`, `controller.php` (`$databaseTables`)
- Test: `tests/Entity/CookieTest.php`, `tests/Entity/ServiceTest.php`

**Interfaces:**
- Produces: `Cookie::MAX_DAYS = 3650`, `Cookie::MAX_NAME = 128`, `Cookie::clampDays(mixed): int`, `Cookie::getID()`, `getService()/setService(Service)`, `getName()/setName(?string)`, `getDurationDays()/setDurationDays(mixed)`, `getContents(): Collection`, `getContent(string $locale): ?CookieContent`, `setContent(string $locale, string $purpose): CookieContent`. `CookieContent`: `getLocale()/setLocale()`, `getPurpose()/setPurpose(?string)`, `getCookie()/setCookie(Cookie)`. `Service::getCookies(): Collection`, `Service::getCookie(string $name): ?Cookie` (case-insensitive), `Service::addCookie(string $name): ?Cookie` (returns the existing one for a known name, null for an empty name).

- [ ] **Step 1: Write the failing tests**

`tests/Entity/CookieTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\{Cookie, Service};

final class CookieTest extends TestCase
{
    public function testTheNameIsPlainTextAndCapped(): void
    {
        $cookie = (new Cookie())->setName("  <b>_ga</b>\n" . str_repeat('x', 200));

        $this->assertStringStartsWith('_ga', $cookie->getName());
        $this->assertSame(Cookie::MAX_NAME, mb_strlen($cookie->getName()));
    }

    public function testDurationIsAWholeNumberOfDaysClamped(): void
    {
        $this->assertSame(0, (new Cookie())->getDurationDays());
        $this->assertSame(30, (new Cookie())->setDurationDays('30')->getDurationDays());
        $this->assertSame(0, (new Cookie())->setDurationDays('-5')->getDurationDays());
        $this->assertSame(0, (new Cookie())->setDurationDays('abc')->getDurationDays());
        $this->assertSame(0, (new Cookie())->setDurationDays(2.5)->getDurationDays());
        $this->assertSame(Cookie::MAX_DAYS, (new Cookie())->setDurationDays(999999)->getDurationDays());
    }

    public function testPurposeIsStoredPerLocaleAsPlainText(): void
    {
        $cookie = (new Cookie())->setName('_ga');
        $cookie->setContent('en_GB', "Counts visits <script>x</script>\nsecond line");
        $cookie->setContent('en_GB', 'Counts visits');

        $this->assertCount(1, $cookie->getContents());
        $this->assertSame('Counts visits', $cookie->getContent('en_GB')?->getPurpose());
        $this->assertNull($cookie->getContent('fr_FR'));
    }

    public function testAnInvalidLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Cookie())->setContent('not a locale', 'x');
    }

    public function testAServiceFindsAndDeduplicatesItsCookiesIgnoringCase(): void
    {
        $service = new Service();
        $first = $service->addCookie('_ga');

        $this->assertSame($first, $service->addCookie('_GA'));
        $this->assertSame($first, $service->getCookie('_Ga'));
        $this->assertNull($service->addCookie("   "));
        $this->assertCount(1, $service->getCookies());
        $this->assertSame($service, $first->getService());
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `composer test -- --filter CookieTest` → FAIL (class `Cookie` not found).

- [ ] **Step 3: Implement.** `src/Entity/Cookie.php` follows `Service.php` / `ServiceContent.php` exactly in style:

```php
<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;
use Doctrine\Common\Collections\{ArrayCollection, Collection};

/**
 * A cookie a service sets. Listed to visitors in the declaration block.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkCookies",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_ck_service_name", columns={"serviceID","name"})},
 *      options={"comment": "Cookies set by services, listed in the declaration"}
 * )
 */
class Cookie extends UpdatedEntity
{
    public const MAX_DAYS = 3650;
    public const MAX_NAME = 128;

    /**
     * @ORM\ManyToOne(targetEntity="Service", inversedBy="cookies")
     * @ORM\JoinColumn(name="serviceID", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    protected $service;

    /**
     * @ORM\Column(type="string", length=128, nullable=false, options={"default": "", "comment": "Cookie name"})
     */
    protected $name = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Lifetime in days, 0 = session"})
     */
    protected $durationDays = 0;

    /**
     * @ORM\OneToMany(targetEntity="CookieContent", mappedBy="cookie", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected $contents;

    public function __construct()
    {
        parent::__construct();

        $this->contents = new ArrayCollection();
    }

    public static function clampDays(mixed $value): int
    {
        if (is_string($value) && preg_match('/^\d{1,9}\z/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) ? max(0, min(self::MAX_DAYS, $value)) : 0;
    }

    public function getService(): ?Service
    {
        return $this->service;
    }

    public function setService(Service $service): self
    {
        $this->service = $service;

        return $this;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = Text::plain($name, self::MAX_NAME);

        return $this;
    }

    public function getDurationDays(): int
    {
        return (int) $this->durationDays;
    }

    public function setDurationDays(mixed $days): self
    {
        $this->durationDays = self::clampDays($days);

        return $this;
    }

    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function getContent(string $locale): ?CookieContent
    {
        foreach ($this->contents as $content) {
            if ($content->getLocale() === $locale) {
                return $content;
            }
        }

        return null;
    }

    public function setContent(string $locale, string $purpose): CookieContent
    {
        $content = $this->getContent($locale);

        if ($content === null) {
            $content = (new CookieContent())->setLocale($locale)->setCookie($this);
            $this->contents->add($content);
        }

        $content->setPurpose($purpose);

        return $content;
    }
}
```

`src/Entity/CookieContent.php` copies `ServiceContent` with table `cmkCookieContents`, index `idx_cmk_cc2_locale`, unique `uniq_cmk_cc2_cookie_locale` on `("cookieID","locale")`, a `cookie` ManyToOne (`inversedBy="contents"`, `onDelete="CASCADE"`), `purpose` (text) with `setPurpose(?string)` = `Text::multiline($purpose, 500)`.

In `Service.php` add a `cookies` collection (initialised in the constructor):

```php
    /**
     * @ORM\OneToMany(targetEntity="Cookie", mappedBy="service", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"name": "ASC"})
     */
    protected $cookies;

    public function getCookies(): Collection
    {
        return $this->cookies;
    }

    public function getCookie(string $name): ?Cookie
    {
        foreach ($this->cookies as $cookie) {
            if (strcasecmp($cookie->getName(), Text::plain($name, Cookie::MAX_NAME)) === 0) {
                return $cookie;
            }
        }

        return null;
    }

    public function addCookie(string $name): ?Cookie
    {
        $name = Text::plain($name, Cookie::MAX_NAME);

        if ($name === '') {
            return null;
        }

        $cookie = $this->getCookie($name);

        if ($cookie === null) {
            $cookie = (new Cookie())->setName($name)->setService($this);
            $this->cookies->add($cookie);
        }

        return $cookie;
    }
```

In `controller.php` add `'cmkCookieContents', 'cmkCookies',` to `$databaseTables` directly before `'cmkServiceContents'`.

- [ ] **Step 4: Run** — `composer test` (whole suite: an existing test may enumerate entities or tables) and `composer typecheck`. Expected: PASS.
- [ ] **Step 5: Commit**

```bash
git add src/Entity controller.php tests/Entity
git commit -m "feat: add Cookie and CookieContent entities and the Service cookie list"
```

---

### Task 2: Cookie editor on the Services page

**Files:**
- Create: `src/Support/CookieForm.php`
- Modify: `controllers/single_page/dashboard/cookie_manager/services.php`, `single_pages/dashboard/cookie_manager/services.php`
- Test: `tests/Support/CookieFormTest.php`, extend `tests/ViewEscapingTest.php` only if the view needs it (it scans `single_pages/` already)

**Interfaces:**
- Consumes: `Cookie`, `Service::getCookies/getCookie/addCookie` (Task 1).
- Produces: `CookieForm::MAX_ROWS = 50`; `CookieForm::parse(mixed $posted, array $locales): list<array{id: ?int, name: string, days: int, purposes: array<string, string>}>`; `CookieForm::apply(Service $service, array $rows): void`.

- [ ] **Step 1: Write the failing tests** (`tests/Support/CookieFormTest.php`):

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Support\CookieForm;

final class CookieFormTest extends TestCase
{
    public function testBlankNamesAndDuplicatesAreDropped(): void
    {
        $rows = CookieForm::parse([
            ['name' => '_ga', 'days' => '730', 'purpose' => ['en_GB' => 'Counts visits']],
            ['name' => '', 'days' => '1'],
            ['name' => '_GA', 'days' => '5'],
            'junk',
        ], ['en_GB', 'fr_FR']);

        $this->assertCount(1, $rows);
        $this->assertSame('_ga', $rows[0]['name']);
        $this->assertSame(730, $rows[0]['days']);
        $this->assertSame(['en_GB' => 'Counts visits', 'fr_FR' => ''], $rows[0]['purposes']);
    }

    public function testHostileValuesAreNormalised(): void
    {
        $rows = CookieForm::parse([['id' => '12abc', 'name' => '<b>x</b>', 'days' => '-4', 'purpose' => ['en_GB' => '<script>a</script>ok', 'xx' => 'ignored']]], ['en_GB']);

        $this->assertNull($rows[0]['id']);
        $this->assertSame('x', $rows[0]['name']);
        $this->assertSame(0, $rows[0]['days']);
        $this->assertSame(['en_GB' => 'aok'], $rows[0]['purposes']);
    }

    public function testAtMostFiftyRowsAreKept(): void
    {
        $posted = [];
        for ($i = 0; $i < 80; $i++) {
            $posted[] = ['name' => 'c' . $i];
        }

        $this->assertCount(CookieForm::MAX_ROWS, CookieForm::parse($posted, ['en_GB']));
    }

    public function testApplyUpdatesAddsAndRemoves(): void
    {
        $service = new Service();
        $service->addCookie('keep')->setDurationDays(1);
        $service->addCookie('drop');

        CookieForm::apply($service, [
            ['id' => null, 'name' => 'keep', 'days' => 30, 'purposes' => ['en_GB' => 'Kept', 'fr_FR' => '']],
            ['id' => null, 'name' => 'new', 'days' => 0, 'purposes' => ['en_GB' => '']],
        ]);

        $this->assertNull($service->getCookie('drop'));
        $this->assertSame(30, $service->getCookie('keep')?->getDurationDays());
        $this->assertSame('Kept', $service->getCookie('keep')?->getContent('en_GB')?->getPurpose());
        $this->assertNull($service->getCookie('keep')?->getContent('fr_FR'));
        $this->assertNotNull($service->getCookie('new'));
        $this->assertNull($service->getCookie('new')?->getContent('en_GB'));
        $this->assertCount(2, $service->getCookies());
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `composer test -- --filter CookieFormTest`.
- [ ] **Step 3: Implement** `src/Support/CookieForm.php`:

```php
<?php

namespace CookieManagerKit\Support;

use CookieManagerKit\Entity\{Cookie, Service};

/**
 * The cookie rows of the service form. Pure: parse() cleans what was posted, apply() changes the entities it is
 * given and persists nothing (the service cascades to its cookies).
 */
final class CookieForm
{
    public const MAX_ROWS = 50;

    /**
     * @param list<string> $locales
     *
     * @return list<array{id: ?int, name: string, days: int, purposes: array<string, string>}>
     */
    public static function parse(mixed $posted, array $locales): array
    {
        $rows = [];
        $seen = [];

        foreach (is_array($posted) ? $posted : [] as $input) {
            if (!is_array($input)) {
                continue;
            }

            $name = Text::plain($input['name'] ?? '', Cookie::MAX_NAME);
            $key = mb_strtolower($name);

            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $id = $input['id'] ?? '';
            $given = is_array($input['purpose'] ?? null) ? $input['purpose'] : [];
            $purposes = [];

            foreach ($locales as $locale) {
                $purposes[$locale] = Text::multiline($given[$locale] ?? '', 500);
            }

            $rows[] = [
                'id' => is_string($id) && preg_match('/^\d{1,10}\z/', $id) === 1 ? (int) $id : null,
                'name' => $name,
                'days' => Cookie::clampDays($input['days'] ?? 0),
                'purposes' => $purposes,
            ];

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param list<array{id: ?int, name: string, days: int, purposes: array<string, string>}> $rows
     */
    public static function apply(Service $service, array $rows): void
    {
        $byId = [];

        foreach ($service->getCookies() as $cookie) {
            $byId[(int) $cookie->getID()] = $cookie;
        }

        $kept = [];

        foreach ($rows as $row) {
            $cookie = $row['id'] !== null ? ($byId[$row['id']] ?? null) : null;
            $cookie ??= $service->getCookie($row['name']);

            if ($cookie === null) {
                $cookie = $service->addCookie($row['name']);
            } else {
                $cookie->setName($row['name']);
            }

            if ($cookie === null) {
                continue;
            }

            $cookie->setDurationDays($row['days']);

            foreach ($row['purposes'] as $locale => $purpose) {
                if ($purpose === '') {
                    $existing = $cookie->getContent((string) $locale);

                    if ($existing) {
                        $cookie->getContents()->removeElement($existing);
                    }

                    continue;
                }

                $cookie->setContent((string) $locale, $purpose);
            }

            $kept[spl_object_id($cookie)] = true;
        }

        foreach ($service->getCookies()->toArray() as $cookie) {
            if (!isset($kept[spl_object_id($cookie)])) {
                $service->getCookies()->removeElement($cookie);
            }
        }
    }
}
```

In `controllers/.../services.php` `save()`, after the existing content loop and before `persist`:

```php
        CookieForm::apply($service, CookieForm::parse($this->post('cookies'), array_keys(SiteLocales::options())));
```

(add `CookieForm` to the `Support` import). In the view `single_pages/dashboard/cookie_manager/services.php` add, between the locale tabs block and the form actions, a cookies section that follows the file's existing markup and escaping style: a heading `t('Cookies')`, a help line (`t('The cookies this service sets, shown in the cookie declaration. Leave the name empty to remove a row.')`), and a table whose rows are the service's existing cookies (`$isNew ? [] : $service->getCookies()->toArray()`) followed by three blank rows. Each row holds `cookies[N][id]` (hidden, empty for blank rows), `cookies[N][name]` (maxlength 128), `cookies[N][days]` (number, min 0, max 3650, help `t('0 means the cookie lasts for the browser session')`) and one `cookies[N][purpose][LOCALE]` text input per site locale labelled with the locale ID. Every value is `O::e(...)`, field names built as `O::e('cookies[' . $i . '][name]')`, as the existing tabs do.

- [ ] **Step 4: Run** — `composer test` (`ViewEscapingTest` and `ControllerSecurityTest` police the view and controller), `composer typecheck`, `composer format:check`. Expected: PASS.
- [ ] **Step 5: Commit**

```bash
git add src controllers single_pages tests
git commit -m "feat: edit the cookies a service sets on the Services page"
```

---

### Task 3: Approving a cookie finding pre-fills the cookie list

**Files:**
- Modify: `src/Scan/Review.php`
- Test: `tests/Scan/ReviewTest.php`

**Interfaces:**
- Consumes: `Service::addCookie`, `Cookie::MAX_NAME` (Task 1).
- Produces: `Review::approve` also adds `$finding->getName()` as a cookie (days 0, no purpose) to the service when the finding's kind is `cookie` and the name fits.

- [ ] **Step 1: Write the failing tests** in `tests/Scan/ReviewTest.php` (reuse its existing helpers for building a finding, a category and a service; look at the file's top for the factory it uses):

```php
    public function testApprovingACookieFindingAddsItToTheServiceCookieList(): void
    {
        $finding = $this->finding(ScanFinding::COOKIE, '_ga', 'example.com');
        $service = Review::approve($finding, $this->category('analytics'), null, 'Analytics', 'Google');

        $this->assertNotNull($service->getCookie('_ga'));
        $this->assertSame(0, $service->getCookie('_ga')->getDurationDays());
    }

    public function testApprovingTheSameCookieTwiceDoesNotDuplicateIt(): void
    {
        $service = Review::approve($this->finding(ScanFinding::COOKIE, '_ga', 'example.com'), $this->category('analytics'), null, 'A', 'G');
        Review::approve($this->finding(ScanFinding::COOKIE, '_GA', 'example.com'), null, $service, '', '');

        $this->assertCount(1, $service->getCookies());
    }

    public function testOtherFindingKindsAndOverlongNamesAddNoCookie(): void
    {
        $script = Review::approve($this->finding(ScanFinding::SCRIPT, 'x.js', 'cdn.example.com'), $this->category('analytics'), null, 'A', 'G');
        $long = Review::approve($this->finding(ScanFinding::COOKIE, str_repeat('a', 200), 'example.com'), $this->category('analytics'), null, 'B', 'G');

        $this->assertCount(0, $script->getCookies());
        $this->assertCount(0, $long->getCookies());
    }
```

Adapt `$this->finding(...)` / `$this->category(...)` to the helpers that file really has (read it first); the assertions are the requirement.

- [ ] **Step 2: Run to verify it fails** — `composer test -- --filter ReviewTest`.
- [ ] **Step 3: Implement.** In `Review::approve`, immediately before `$finding->setService($service)->setStatus(ScanFinding::APPROVED);` add:

```php
        // The declaration block lists cookies per service; a cookie the scanner found starts as a row the admin completes.
        if ($finding->getKind() === ScanFinding::COOKIE && mb_strlen($finding->getName()) <= Cookie::MAX_NAME) {
            $service->addCookie($finding->getName());
        }
```

and `use CookieManagerKit\Entity\Cookie;`. Update the class docblock sentence "records the host on the service" to also mention the cookie.

- [ ] **Step 4: Run** — `composer test`, `composer typecheck`. Expected: PASS (the `approveMatched` tests must still pass).
- [ ] **Step 5: Commit**

```bash
git add src/Scan/Review.php tests/Scan/ReviewTest.php
git commit -m "feat: approving a cookie finding pre-fills the service's cookie list"
```

---

### Task 4: Snapshot carries service domains and cookies; declaration builder

**Files:**
- Create: `src/Support/CookieDeclaration.php`
- Modify: `src/Support/Catalog.php`
- Test: `tests/Support/CookieDeclarationTest.php`

**Interfaces:**
- Consumes: `Service::getDomains()`, `Service::getCookies()` (Task 1).
- Produces: snapshot service entries become `['name' => string, 'vendor' => string, 'content' => array<locale, string>, 'domains' => list<string>, 'cookies' => list<array{name: string, days: int, content: array<locale, string>}>]`. `CookieDeclaration::build(array $snapshot, string $locale, string $default, array $essential, callable $duration): list<array{handle: string, title: string, description: string, cookies: list<array{name: string, provider: string, purpose: string, duration: string}>}>` where `$essential` is the `EssentialCookies::describe(...)` shape `list<array{name, purpose, duration}>` and `$duration` is `callable(int): string`.

- [ ] **Step 1: Write the failing tests** (`tests/Support/CookieDeclarationTest.php`):

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\CookieDeclaration;

final class CookieDeclarationTest extends TestCase
{
    private function snapshot(): array
    {
        return ['categories' => [
            ['handle' => 'necessary', 'necessary' => true, 'sort' => 0, 'content' => ['en_GB' => ['title' => 'Necessary', 'description' => 'Needed']], 'services' => []],
            ['handle' => 'analytics', 'necessary' => false, 'sort' => 20, 'content' => ['en_GB' => ['title' => 'Analytics', 'description' => 'Measures']], 'services' => [
                ['name' => 'GA', 'vendor' => 'Google', 'content' => [], 'domains' => ['google-analytics.com'], 'cookies' => [
                    ['name' => '_ga', 'days' => 730, 'content' => ['en_GB' => 'Counts visits']],
                    ['name' => '_gid', 'days' => 0, 'content' => []],
                ]],
            ]],
            ['handle' => 'marketing', 'necessary' => false, 'sort' => 30, 'content' => [], 'services' => [
                ['name' => 'Pixel', 'vendor' => '', 'content' => [], 'domains' => [], 'cookies' => []],
            ]],
        ]];
    }

    private function build(string $locale = 'en_GB', array $essential = []): array
    {
        return CookieDeclaration::build($this->snapshot(), $locale, 'en_GB', $essential, static fn(int $days): string => $days === 0 ? 'Session' : $days . ' days');
    }

    public function testCategoriesWithoutCookiesAreOmittedButNecessaryAlwaysListsTheEssentialCookies(): void
    {
        $sections = $this->build('en_GB', [['name' => 'CONCRETE', 'purpose' => 'Session', 'duration' => 'Session']]);

        $this->assertSame(['necessary', 'analytics'], array_column($sections, 'handle'));
        $this->assertSame('CONCRETE', $sections[0]['cookies'][0]['name']);
        $this->assertSame('', $sections[0]['cookies'][0]['provider']);
    }

    public function testWithNoCookiesDefinedAndNoEssentialCookiesNothingIsListed(): void
    {
        $this->assertSame([], CookieDeclaration::build(['categories' => []], 'en_GB', 'en_GB', [], static fn(int $d): string => (string) $d));
    }

    public function testRowsCarryProviderPurposeAndDuration(): void
    {
        $rows = $this->build()[0]['cookies'];

        $this->assertSame(['name' => '_ga', 'provider' => 'Google', 'purpose' => 'Counts visits', 'duration' => '730 days'], $rows[0]);
        $this->assertSame('Session', $rows[1]['duration']);
    }

    public function testAMissingTranslationFallsBackToTheDefaultLocaleThenToNothing(): void
    {
        $rows = $this->build('fr_FR')[0]['cookies'];

        $this->assertSame('Counts visits', $rows[0]['purpose']);
        $this->assertSame('', $rows[1]['purpose']);
        $this->assertSame('Analytics', $this->build('fr_FR')[0]['title']);
    }

    public function testTheProviderFallsBackToTheServiceName(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['categories'][1]['services'][0]['vendor'] = '';

        $rows = CookieDeclaration::build($snapshot, 'en_GB', 'en_GB', [], static fn(int $d): string => (string) $d)[0]['cookies'];

        $this->assertSame('GA', $rows[0]['provider']);
    }

    public function testAnInvalidHandleOrMalformedEntriesAreDropped(): void
    {
        $snapshot = ['categories' => ['junk', ['handle' => 'Bad Handle!', 'services' => [['cookies' => [['name' => 'x', 'days' => 1]]]]], ['handle' => 'ok', 'content' => [], 'services' => [['name' => 'S', 'cookies' => [['name' => '', 'days' => 1], ['name' => 'real', 'days' => 3]]]]]]];

        $sections = CookieDeclaration::build($snapshot, 'en_GB', 'en_GB', [], static fn(int $d): string => (string) $d);

        $this->assertSame(['ok'], array_column($sections, 'handle'));
        $this->assertSame(['real'], array_column($sections[0]['cookies'], 'name'));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `composer test -- --filter CookieDeclarationTest`.
- [ ] **Step 3: Implement.** `src/Support/CookieDeclaration.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Pure: turns the cached snapshot into the sections the cookie declaration block prints. Fallback is per field:
 * the visitor's locale, then the site default locale, then nothing. Nothing here depends on the visitor.
 */
final class CookieDeclaration
{
    /**
     * @param array<string, mixed>                                    $snapshot
     * @param list<array{name: string, purpose: string, duration: string}> $essential
     * @param callable(int): string                                   $duration
     *
     * @return list<array{handle: string, title: string, description: string, cookies: list<array{name: string, provider: string, purpose: string, duration: string}>}>
     */
    public static function build(array $snapshot, string $locale, string $default, array $essential, callable $duration): array
    {
        $sections = [];

        foreach (is_array($snapshot['categories'] ?? null) ? $snapshot['categories'] : [] as $category) {
            $handle = is_array($category) ? ($category['handle'] ?? null) : null;

            if (!Text::isHandle($handle)) {
                continue;
            }

            $content = is_array($category['content'] ?? null) ? $category['content'] : [];
            $rows = [];

            if (($category['necessary'] ?? false) === true) {
                foreach ($essential as $cookie) {
                    $rows[] = [
                        'name' => (string) ($cookie['name'] ?? ''),
                        'provider' => '',
                        'purpose' => (string) ($cookie['purpose'] ?? ''),
                        'duration' => (string) ($cookie['duration'] ?? ''),
                    ];
                }
            }

            foreach (is_array($category['services'] ?? null) ? $category['services'] : [] as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $provider = (string) (($service['vendor'] ?? '') !== '' ? $service['vendor'] : ($service['name'] ?? ''));

                foreach (is_array($service['cookies'] ?? null) ? $service['cookies'] : [] as $cookie) {
                    $name = is_array($cookie) ? ($cookie['name'] ?? '') : '';

                    if (!is_string($name) || $name === '') {
                        continue;
                    }

                    $rows[] = [
                        'name' => $name,
                        'provider' => $provider,
                        'purpose' => self::pick(is_array($cookie['content'] ?? null) ? $cookie['content'] : [], $locale, $default),
                        'duration' => $duration(max(0, (int) ($cookie['days'] ?? 0))),
                    ];
                }
            }

            if ($rows === []) {
                continue;
            }

            $sections[] = [
                'handle' => $handle,
                'title' => self::pickField($content, $locale, $default, 'title') ?: $handle,
                'description' => self::pickField($content, $locale, $default, 'description'),
                'cookies' => $rows,
            ];
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $byLocale locale => string
     */
    private static function pick(array $byLocale, string $locale, string $default): string
    {
        foreach ([$locale, $default] as $candidate) {
            $value = $byLocale[$candidate] ?? '';

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $byLocale locale => ['title' => ..., 'description' => ...]
     */
    private static function pickField(array $byLocale, string $locale, string $default, string $key): string
    {
        $values = [];

        foreach ($byLocale as $candidate => $row) {
            $values[$candidate] = is_array($row) ? ($row[$key] ?? '') : '';
        }

        return self::pick($values, $locale, $default);
    }
}
```

In `Catalog::build()` replace the `$services[] = [...]` line with:

```php
                $cookies = [];

                foreach ($service->getCookies() as $cookie) {
                    $purposes = [];

                    foreach ($cookie->getContents() as $row) {
                        $purposes[$row->getLocale()] = $row->getPurpose();
                    }

                    $cookies[] = ['name' => $cookie->getName(), 'days' => $cookie->getDurationDays(), 'content' => $purposes];
                }

                $services[] = [
                    'name' => $service->getName(),
                    'vendor' => $service->getVendor(),
                    'content' => $descriptions,
                    'domains' => $service->getDomains(),
                    'cookies' => $cookies,
                ];
```

and bump `KEY` to `'cookie_manager_kit/snapshot/v3'` (the stored shape changed).

- [ ] **Step 4: Run** — `composer test`, `composer typecheck`. `ConfigBuilderTest` must still pass (the builder ignores the extra keys).
- [ ] **Step 5: Commit**

```bash
git add src/Support tests/Support
git commit -m "feat: snapshot service domains and cookies, and build declaration sections from it"
```

---

### Task 5: The cookie_declaration block

**Files:**
- Create: `blocks/cookie_declaration/controller.php`, `blocks/cookie_declaration/view.php`, `blocks/cookie_declaration/db.xml`
- Modify: `controller.php` (`autoInstallBlocks`), `css/cmk.css`, `tests/ViewEscapingTest.php`
- Test: `tests/BlockViewTest.php` (new)

**Interfaces:**
- Consumes: `CookieDeclaration::build` (Task 4), `Catalog::snapshot()/resolveLocale()/defaultLocale()/lifetimeDays()`, `EssentialCookies::describe(EssentialCookies::names(), Catalog::lifetimeDays())`.
- Produces: block type `cookie_declaration`; the view receives `$sections` in the `CookieDeclaration::build` shape.

- [ ] **Step 1: Read a working block for conventions** — `/Users/david/Sites/localhost/Composer-C5-Packages/AltoKit/blocks/sold_property_list/` (`controller.php`, `db.xml`, `view.php`) and how `AltoKit/controller.php` calls `autoInstallBlocks($pkg)` and lists block tables. Follow it. Block namespace: `Concrete\Package\CookieManagerKit\Block\CookieDeclaration`.
- [ ] **Step 2: Write the failing tests.** First extend `tests/ViewEscapingTest.php`: in `views()` change `foreach (['single_pages', 'elements'] as $folder)` to `['single_pages', 'elements', 'blocks']`. Then `tests/BlockViewTest.php` renders the view with hostile data and asserts it is escaped:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;

final class BlockViewTest extends TestCase
{
    private function render(array $sections, bool $editMode = false): string
    {
        if (!defined('C5_EXECUTE')) {
            define('C5_EXECUTE', true);
        }

        $c = new class ($editMode) {
            public function __construct(private bool $edit) {}
            public function isEditMode(): bool { return $this->edit; }
        };

        ob_start();
        (static function () use ($sections, $c): void {
            require dirname(__DIR__) . '/blocks/cookie_declaration/view.php';
        })();

        return (string) ob_get_clean();
    }

    public function testHostileAdminTextIsEscaped(): void
    {
        $html = $this->render([[
            'handle' => 'analytics',
            'title' => '<script>alert(1)</script>',
            'description' => '"><img src=x onerror=1>',
            'cookies' => [['name' => '<b>_ga</b>', 'provider' => '"x"', 'purpose' => "<script>bad()</script>", 'duration' => '1 <i>day</i>']],
        ]]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $html);
    }

    public function testTheChangeConsentButtonOpensTheBanner(): void
    {
        $this->assertStringContainsString('data-cmk-open', $this->render([['handle' => 'a', 'title' => 'A', 'description' => '', 'cookies' => [['name' => 'x', 'provider' => '', 'purpose' => '', 'duration' => '']]]]));
    }

    public function testWithNothingToListThereIsStillTheButtonAndAnEmptyNote(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('data-cmk-open', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testEditModeShowsAPlaceholderInsteadOfTheTables(): void
    {
        $html = $this->render([['handle' => 'a', 'title' => 'A', 'description' => '', 'cookies' => [['name' => 'x', 'provider' => '', 'purpose' => '', 'duration' => '']]]], true);

        $this->assertStringContainsString('ccm-edit-mode-disabled-item', $html);
        $this->assertStringNotContainsString('<table', $html);
    }
}
```

(`t()` and `O` must be loadable in this test; `tests/Dashboard/translate.php` and the `ViewEscapingTest` bootstrap show how other view tests get `t()`; reuse that. If `t2` is needed by the view it must be defined there too.)

- [ ] **Step 3: Run to verify it fails** — `composer test -- --filter BlockViewTest` → FAIL (view missing).
- [ ] **Step 4: Implement.**

`blocks/cookie_declaration/db.xml`: a table `btCookieDeclaration` with only the `bID` primary key, in the format of the AltoKit `db.xml`.

`blocks/cookie_declaration/controller.php`:

```php
<?php

namespace Concrete\Package\CookieManagerKit\Block\CookieDeclaration;

use Concrete\Core\Block\BlockController;
use Concrete\Core\Localization\Localization;
use CookieManagerKit\Support\{Catalog, CookieDeclaration, EssentialCookies, ErrorLog};

class Controller extends BlockController
{
    protected $btTable = 'btCookieDeclaration';
    protected $btInterfaceWidth = 400;
    protected $btInterfaceHeight = 200;
    protected $btCacheBlockOutput = false;

    public function getBlockTypeName()
    {
        return t('Cookie Declaration');
    }

    public function getBlockTypeDescription()
    {
        return t('Lists the cookies this site sets, by category, with a link to change consent.');
    }

    public function view()
    {
        $sections = [];

        try {
            $sections = CookieDeclaration::build(
                Catalog::snapshot(),
                Catalog::resolveLocale((string) Localization::activeLocale()),
                Catalog::defaultLocale(),
                EssentialCookies::describe(EssentialCookies::names(), Catalog::lifetimeDays()),
                static fn(int $days): string => $days === 0 ? t('Session') : t2('%d day', '%d days', $days),
            );
        } catch (\Throwable $e) {
            // A page must never fail to render because the declaration could not be built (for example before the Update has run).
            ErrorLog::record($e);
        }

        $this->set('sections', $sections);
    }
}
```

`blocks/cookie_declaration/view.php`: starts with `defined('C5_EXECUTE') or die('Access Denied.');` and `use CookieManagerKit\Support\Output as O;`. If `is_object($c) && $c->isEditMode()` print `<div class="ccm-edit-mode-disabled-item"><?php echo t('Cookie declaration') ?></div>` and `return;`. Otherwise print `<div class="cmk-declaration">`; for each section an `<h3>` (title, `O::e`), a `<p>` when the description is non-empty, and a `<table class="cmk-declaration__table">` with `<thead>` (`t('Cookie')`, `t('Provider')`, `t('Purpose')`, `t('Duration')`) and a row per cookie, every cell `O::e(...)`. After the sections always print `<p><button type="button" class="cmk-declaration__open" data-cmk-open><?php echo t('Change my consent') ?></button></p>` and close the div. When `$sections === []` print `<p class="cmk-declaration__empty"><?php echo t('This site does not list any cookies.') ?></p>` before the button.

`controller.php`: in `installOrUpgrade` add `$this->autoInstallBlocks($pkg);` after the single pages. (Block `use` imports: the package controller already uses `BlockTrait`.)

`css/cmk.css`: append a minimal, theme-neutral block style:

```css
.cmk-declaration__table {
	width: 100%;
	border-collapse: collapse;
}

.cmk-declaration__table th,
.cmk-declaration__table td {
	padding: 6px 8px;
	text-align: left;
	vertical-align: top;
	border-bottom: 1px solid rgba(0, 0, 0, 0.12);
}
```

- [ ] **Step 5: Run** — `composer test` (the extended `ViewEscapingTest` now reads the block view), `composer typecheck`, `composer format:check`. Expected: PASS.
- [ ] **Step 6: Commit**

```bash
git add blocks controller.php css tests
git commit -m "feat: add the cookie declaration block"
```

---

### Task 6: Banner embed text fields

**Files:**
- Modify: `src/Entity/BannerContent.php`, `src/Support/Catalog.php`, `controllers/single_page/dashboard/cookie_manager/banner.php`, `single_pages/dashboard/cookie_manager/banner.php`
- Test: `tests/Entity/BannerContentTest.php`

**Interfaces:**
- Produces: `BannerContent::getEmbedMessage()/setEmbedMessage(?string)` (multiline, max 500), `getEmbedAcceptLabel()/setEmbedAcceptLabel(?string)` (plain, max 64); `isEmpty()` accounts for both. Snapshot `banner[locale]` gains `embedMessage`, `embedAcceptLabel`; `Catalog::DEFAULTS` gains `embedMessage`, `embedAcceptLabel` (values in Global Constraints).

- [ ] **Step 1: Write the failing tests** in `tests/Entity/BannerContentTest.php`:

```php
    public function testEmbedTextIsPlainAndCapped(): void
    {
        $row = (new BannerContent())->setLocale('en_GB')
            ->setEmbedMessage('<b>Needs</b> consent ' . str_repeat('x', 600))
            ->setEmbedAcceptLabel('<i>Accept</i> ' . str_repeat('y', 100));

        $this->assertStringStartsWith('Needs consent', $row->getEmbedMessage());
        $this->assertSame(500, mb_strlen($row->getEmbedMessage()));
        $this->assertSame(64, mb_strlen($row->getEmbedAcceptLabel()));
    }

    public function testARowWithOnlyEmbedTextIsNotEmpty(): void
    {
        $row = (new BannerContent())->setLocale('en_GB');
        $this->assertTrue($row->isEmpty());

        $row->setEmbedAcceptLabel('Accept');
        $this->assertFalse($row->isEmpty());
    }
```

- [ ] **Step 2: Run to verify it fails** — `composer test -- --filter BannerContentTest`.
- [ ] **Step 3: Implement.** In `BannerContent`: add

```php
    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Message shown instead of a blocked embed (plain text)"})
     */
    protected $embedMessage = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": "", "comment": "Button on a blocked embed"})
     */
    protected $embedAcceptLabel = '';
```

with getters/setters (`Text::multiline($value, 500)` and `Text::plain($value, 64)`), and extend `isEmpty()` with `&& $this->embedMessage === '' && $this->embedAcceptLabel === ''`. A MySQL `text` column cannot have a default, which is why the message column has none, as `body` does.

`Catalog::DEFAULTS` gets the two new defaults. In `Catalog::build()` the banner array gets `'embedMessage' => $row->getEmbedMessage(), 'embedAcceptLabel' => $row->getEmbedAcceptLabel(),`.

Banner controller `save()`: add `'embedMessage' => Text::multiline($input['embedMessage'] ?? '', 500), 'embedAcceptLabel' => Text::plain($input['embedAcceptLabel'] ?? '', 64),` to `$values[$locale]`, and `->setEmbedMessage($value['embedMessage'])->setEmbedAcceptLabel($value['embedAcceptLabel'])` in the setter chain (the blank-row check already implodes every non-`policyPage` value, so it covers both).

Banner view: in each locale tab add two fields after the existing label fields, built exactly like their neighbours: a textarea `content[LOCALE][embedMessage]` (maxlength 500, label `t('Blocked embed message')`, help `t('Shown in place of an embedded video or map until the visitor accepts. {service} is replaced with the service name.')`) and a text input `content[LOCALE][embedAcceptLabel]` (maxlength 64, label `t('Blocked embed button')`, help `t('{category} is replaced with the category name. The "Customise" label is used for the second button.')`).

- [ ] **Step 4: Run** — `composer test`, `composer typecheck`. Expected: PASS.
- [ ] **Step 5: Commit**

```bash
git add src controllers single_pages tests
git commit -m "feat: per-locale placeholder text for blocked embeds"
```

---

### Task 7: EmbedRewriter (pure)

**Files:**
- Create: `src/Support/EmbedRewriter.php`
- Test: `tests/Support/EmbedRewriterTest.php`

**Interfaces:**
- Produces: `EmbedRewriter::host(string $url): ?string` (lower-case ASCII host of an http(s) URL, null otherwise); `EmbedRewriter::match(string $url, array $services): ?array`; `EmbedRewriter::rewrite(string $html, array $services, array $text, callable $onError): string`. `$services` is `list<array{name: string, category: string, categoryTitle: string, domains: list<string>}>`; `$text` is `array{message: string, accept: string, customise: string}`; `$onError` is `callable(\Throwable): void`.

- [ ] **Step 1: Write the failing tests** (`tests/Support/EmbedRewriterTest.php`):

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use CookieManagerKit\Support\EmbedRewriter;

final class EmbedRewriterTest extends TestCase
{
    private const SERVICES = [
        ['name' => 'YouTube', 'category' => 'marketing', 'categoryTitle' => 'Marketing', 'domains' => ['youtube.com', 'www.youtube-nocookie.com']],
        ['name' => 'Maps', 'category' => 'preferences', 'categoryTitle' => 'Preferences', 'domains' => ['*.google.com']],
    ];
    private const TEXT = ['message' => 'Provided by {service}.', 'accept' => 'Accept {category} cookies', 'customise' => 'Customise'];

    /** @var list<\Throwable> */
    private array $errors = [];

    private function rewrite(string $html): string
    {
        $this->errors = [];

        return EmbedRewriter::rewrite($html, self::SERVICES, self::TEXT, function (\Throwable $e): void {
            $this->errors[] = $e;
        });
    }

    #[DataProvider('matching')]
    public function testHostsMatchOnlyTheDomainOrItsSubdomains(string $url, ?string $expected): void
    {
        $match = EmbedRewriter::match($url, self::SERVICES);

        $this->assertSame($expected, $match['name'] ?? null);
    }

    public static function matching(): iterable
    {
        yield 'exact' => ['https://youtube.com/embed/x', 'YouTube'];
        yield 'subdomain' => ['https://www.youtube.com/embed/x', 'YouTube'];
        yield 'uppercase' => ['HTTPS://WWW.YouTube.COM/embed/x', 'YouTube'];
        yield 'trailing dot' => ['https://youtube.com./embed/x', 'YouTube'];
        yield 'wildcard domain, subdomain' => ['https://maps.google.com/embed', 'Maps'];
        yield 'wildcard domain, the base host' => ['https://google.com/embed', 'Maps'];
        yield 'lookalike suffix' => ['https://evilyoutube.com/embed/x', null];
        yield 'domain as a subdomain of another host' => ['https://youtube.com.evil.com/embed/x', null];
        yield 'userinfo trick' => ['https://youtube.com@evil.com/embed/x', null];
        yield 'path only' => ['https://evil.com/youtube.com', null];
        yield 'not http' => ['ftp://youtube.com/x', null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'relative' => ['/embed/x', null];
        yield 'empty' => ['', null];
    }

    public function testAMatchedIframeBecomesAPlaceholderWithAnUnloadedFrame(): void
    {
        $out = $this->rewrite('<p>Hi</p><iframe width="560" height="315" src="https://www.youtube.com/embed/abc" title="A &gt; B" allowfullscreen></iframe><p>Bye</p>');

        $this->assertStringContainsString('<p>Hi</p>', $out);
        $this->assertStringContainsString('<p>Bye</p>', $out);
        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
        $this->assertStringContainsString('data-cmk-src="https://www.youtube.com/embed/abc"', $out);
        $this->assertStringContainsString('data-cmk-embed-accept="marketing"', $out);
        $this->assertStringContainsString('data-cmk-open', $out);
        $this->assertStringContainsString('Provided by YouTube.', $out);
        $this->assertStringContainsString('Accept Marketing cookies', $out);
        $this->assertStringContainsString('title="A &gt; B"', $out);
        $this->assertStringContainsString('allowfullscreen=""', $out);
        $this->assertStringContainsString('width:560px;', $out);
        $this->assertStringContainsString('min-height:315px;', $out);
        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/i', $out);
        $this->assertSame([], $this->errors);
    }

    public function testUnmatchedAndSrcdocAndSourcelessIframesAreLeftAlone(): void
    {
        $html = '<iframe src="https://example.org/x"></iframe><iframe srcdoc="<p>hi</p>" src="https://youtube.com/x"></iframe><iframe></iframe><iframe src="/local"></iframe>';

        $this->assertSame($html, $this->rewrite($html));
    }

    public function testQuotesAndSingleQuotedAndUnquotedAttributesAreHandled(): void
    {
        $out = $this->rewrite("<IFRAME SRC='https://youtube.com/embed/1' class=video data-x=\"1\"></IFRAME>");

        $this->assertStringContainsString('data-cmk-src="https://youtube.com/embed/1"', $out);
        $this->assertStringContainsString('class="video"', $out);
        $this->assertStringContainsString('data-x="1"', $out);
    }

    public function testASourceWithoutAProtocolIsTreatedAsHttps(): void
    {
        $this->assertStringContainsString('data-cmk-src="https://www.youtube.com/e"', $this->rewrite('<iframe src="//www.youtube.com/e"></iframe>'));
    }

    public function testSeveralIframesAreAllRewrittenIndependently(): void
    {
        $out = $this->rewrite('<iframe src="https://youtube.com/a"></iframe>X<iframe src="https://example.org/b"></iframe>Y<iframe src="https://maps.google.com/c"></iframe>');

        $this->assertSame(2, substr_count($out, 'data-cmk-embed="'));
        $this->assertStringContainsString('data-cmk-embed="preferences"', $out);
        $this->assertStringContainsString('<iframe src="https://example.org/b"></iframe>', $out);
    }

    #[DataProvider('maskedRegions')]
    public function testIframesInsideScriptStyleTemplateNoscriptAndCommentsAreNotTouched(string $html): void
    {
        $this->assertSame($html, $this->rewrite($html));
    }

    public static function maskedRegions(): iterable
    {
        $frame = '<iframe src="https://youtube.com/x"></iframe>';

        yield 'script' => ['<script>document.write(\'' . $frame . '\')</script>'];
        yield 'style' => ['<style>/* ' . $frame . ' */</style>'];
        yield 'template' => ['<template>' . $frame . '</template>'];
        yield 'noscript' => ['<noscript>' . $frame . '</noscript>'];
        yield 'comment' => ['<!-- ' . $frame . ' -->'];
        yield 'unterminated script' => ['<script>var a = \'' . $frame . '\';'];
        yield 'unterminated comment' => ['<!-- ' . $frame];
    }

    public function testAnIframeAfterAMaskedRegionIsStillRewritten(): void
    {
        $out = $this->rewrite('<script>var a = 1;</script><iframe src="https://youtube.com/x"></iframe>');

        $this->assertStringContainsString('<script>var a = 1;</script>', $out);
        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
    }

    public function testAnUnterminatedMatchedIframeIsRemovedAndReported(): void
    {
        $out = $this->rewrite('<p>A</p><iframe src="https://youtube.com/x"><p>B</p>');

        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertStringContainsString('<p>A</p><p>B</p>', $out);
        $this->assertCount(1, $this->errors);
    }

    public function testAnUnterminatedUnmatchedIframeIsLeftAlone(): void
    {
        $html = '<iframe src="https://example.org/x"><p>B</p>';

        $this->assertSame($html, $this->rewrite($html));
        $this->assertSame([], $this->errors);
    }

    public function testHostileTextAndAttributesAreEscaped(): void
    {
        $services = [['name' => '<script>x</script>', 'category' => 'marketing', 'categoryTitle' => '"><img src=x>', 'domains' => ['youtube.com']]];
        $out = EmbedRewriter::rewrite(
            '<iframe src="https://youtube.com/x" title=\'"><script>bad()</script>\' onload="evil()" data-cmk-src="https://evil.example/" data-cmk-embed="x"></iframe>',
            $services,
            ['message' => '{service}', 'accept' => '{category}', 'customise' => '<b>c</b>'],
            static function (\Throwable $e): void {},
        );

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringNotContainsString('<b>', $out);
        $this->assertStringNotContainsString('evil.example', $out);
        $this->assertSame(1, substr_count($out, 'data-cmk-src='));
    }

    public function testNonNumericSizesAreIgnored(): void
    {
        $out = $this->rewrite('<iframe src="https://youtube.com/x" width="100%;position:fixed" height="calc(1px)"></iframe>');

        $this->assertStringNotContainsString('style=', $out);
    }

    public function testAPageWithoutIframesOrServicesIsReturnedUntouched(): void
    {
        $this->assertSame('<p>x</p>', $this->rewrite('<p>x</p>'));
        $this->assertSame('<iframe src="https://youtube.com/x"></iframe>', EmbedRewriter::rewrite('<iframe src="https://youtube.com/x"></iframe>', [], self::TEXT, static function (\Throwable $e): void {}));
    }

    public function testALookalikeElementIsNotAnIframe(): void
    {
        $html = '<iframe-x src="https://youtube.com/x"></iframe-x><iframes src="https://youtube.com/x"></iframes>';

        $this->assertSame($html, $this->rewrite($html));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `composer test -- --filter EmbedRewriterTest`.
- [ ] **Step 3: Implement** `src/Support/EmbedRewriter.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Pure: blocks iframes that belong to a known service until the visitor consents to its category. A matched iframe
 * becomes a placeholder (message, accept button, preferences button) next to an iframe whose `src` has moved to
 * `data-cmk-src`; the loader restores it in the browser. The output does not depend on the visitor.
 *
 * Fails closed: a matched iframe that cannot be rewritten safely is removed, and a regex failure removes every
 * iframe tag from the page rather than leaving one live.
 */
final class EmbedRewriter
{
    /** Regions where an `<iframe` is text, not an element. Unterminated ones run to the end, as a browser reads them. */
    private const MASKED = '#<!--.*?(?:-->|\z)|<(script|style|template|noscript)(?=[\s/>])[^>]*+>.*?(?:</\1\s*>|\z)#is';
    private const IFRAME = '#<iframe(?=[\s/>])((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>(?:.*?</iframe\s*>)?#is';
    private const ATTRIBUTE = '#([^\s"\'<>/=]++)(?:\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s"\'=<>`]++)))?#';

    /**
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     * @param array{message: string, accept: string, customise: string}                                  $text
     * @param callable(\Throwable): void                                                                 $onError
     */
    public static function rewrite(string $html, array $services, array $text, callable $onError): string
    {
        if ($services === [] || stripos($html, '<iframe') === false) {
            return $html;
        }

        $token = 'cmk' . bin2hex(random_bytes(8));
        $masks = [];
        $masked = preg_replace_callback(self::MASKED, static function (array $found) use (&$masks, $token): string {
            $key = '<!--' . $token . count($masks) . '-->';
            $masks[$key] = $found[0];

            return $key;
        }, $html);

        $done = $masked === null ? null : preg_replace_callback(
            self::IFRAME,
            static fn(array $found): string => self::one($found[0], $found[1], $services, $text, $onError),
            $masked,
        );

        if ($done === null) {
            self::report($onError, new \RuntimeException('Embed rewriting failed; iframes were removed from the page.'));

            return (string) preg_replace('#</?iframe(?=[\s/>])[^>]*+>#i', '', $html);
        }

        return $masks === [] ? $done : strtr($done, $masks);
    }

    /**
     * The lower-case host of an http(s) URL, or null.
     */
    public static function host(string $url): ?string
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host)) {
            return null;
        }

        $host = rtrim(strtolower($host), '.');

        return preg_match('/^[a-z0-9.-]+\z/', $host) === 1 ? $host : null;
    }

    /**
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     *
     * @return array{name: string, category: string, categoryTitle: string, domains: list<string>}|null
     */
    public static function match(string $url, array $services): ?array
    {
        $host = self::host($url);

        if ($host === null) {
            return null;
        }

        foreach ($services as $service) {
            foreach ($service['domains'] as $domain) {
                $base = str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;

                if ($base !== '' && ($host === $base || str_ends_with($host, '.' . $base))) {
                    return $service;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     * @param array{message: string, accept: string, customise: string}                                  $text
     */
    private static function one(string $whole, string $attributeText, array $services, array $text, callable $onError): string
    {
        try {
            $attributes = self::attributes($attributeText);
            $source = $attributes['src'] ?? null;

            if ($source === null || isset($attributes['srcdoc'])) {
                return $whole;
            }

            $source = trim($source);
            $source = str_starts_with($source, '//') ? 'https:' . $source : $source;
            $url = Output::safeUrl($source);
            $service = $url === '' ? null : self::match($url, $services);

            if ($service === null || !Text::isHandle($service['category'])) {
                return $whole;
            }

            if (preg_match('#</iframe\s*+>\z#i', $whole) !== 1) {
                self::report($onError, new \RuntimeException('An unterminated iframe was removed.'));

                return '';
            }

            return self::placeholder($attributes, $url, $service, $text);
        } catch (\Throwable $e) {
            self::report($onError, $e);

            return '';
        }
    }

    /**
     * @param array<string, string>                                                                    $attributes
     * @param array{name: string, category: string, categoryTitle: string, domains: list<string>} $service
     * @param array{message: string, accept: string, customise: string}                                  $text
     */
    private static function placeholder(array $attributes, string $url, array $service, array $text): string
    {
        $kept = '';

        foreach ($attributes as $name => $value) {
            if ($name === 'src' || str_starts_with($name, 'data-cmk-') || preg_match('/^[a-z][a-z0-9_:-]{0,63}\z/', $name) !== 1) {
                continue;
            }

            $kept .= ' ' . $name . '="' . Output::e($value) . '"';
        }

        $style = '';

        if (preg_match('/^(\d{1,4})(px)?\z/', $attributes['width'] ?? '', $width) === 1) {
            $style .= 'width:' . $width[1] . 'px;';
        }

        if (preg_match('/^(\d{1,4})(px)?\z/', $attributes['height'] ?? '', $height) === 1) {
            $style .= 'min-height:' . $height[1] . 'px;';
        }

        $replace = ['{service}' => $service['name'], '{category}' => $service['categoryTitle']];
        $category = Output::e($service['category']);

        return '<span class="cmk-embed" data-cmk-embed="' . $category . '"' . ($style === '' ? '' : ' style="' . Output::e($style) . '"') . '>'
            . '<span class="cmk-embed__box" data-cmk-embed-placeholder>'
            . '<span class="cmk-embed__message">' . Output::e(strtr($text['message'], $replace)) . '</span>'
            . '<span class="cmk-embed__actions">'
            . '<button type="button" class="cmk-embed__accept" data-cmk-embed-accept="' . $category . '">' . Output::e(strtr($text['accept'], $replace)) . '</button> '
            . '<button type="button" class="cmk-embed__prefs" data-cmk-open>' . Output::e($text['customise']) . '</button>'
            . '</span></span>'
            . '<iframe' . $kept . ' data-cmk-src="' . Output::e($url) . '"></iframe>'
            . '</span>';
    }

    /**
     * Attribute names are lower-cased and the first of a duplicate wins, as a browser reads them.
     *
     * @return array<string, string>
     */
    private static function attributes(string $text): array
    {
        $out = [];

        preg_match_all(self::ATTRIBUTE, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = strtolower($match[1]);

            if (isset($out[$name])) {
                continue;
            }

            $value = ($match[2] ?? '') !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
            $out[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $out;
    }

    private static function report(callable $onError, \Throwable $error): void
    {
        try {
            $onError($error);
        } catch (\Throwable) {
        }
    }
}
```

If a test fails because of the exact markup (for example attribute order), fix the code to meet the stated behaviour, not the test; if a test's expectation is itself wrong about the HTML spec, say so in the report rather than silently changing it.

- [ ] **Step 4: Run** — `composer test -- --filter EmbedRewriterTest`, then `composer test`, `composer typecheck`, `composer format:check`. Expected: PASS. (PHPStan may want the array-shape docblocks aligned; fix the docblocks, not the logic.)
- [ ] **Step 5: Commit**

```bash
git add src/Support/EmbedRewriter.php tests/Support/EmbedRewriterTest.php
git commit -m "feat: rewrite iframes of known services into consent placeholders"
```

---

### Task 8: Build the embed config from the snapshot and wire it into page output

**Files:**
- Create: `src/Support/EmbedConfig.php`
- Modify: `src/Events/ConsentManager.php`, `css/cmk.css`
- Test: `tests/Support/EmbedConfigTest.php`, `tests/Events/ConsentManagerEmbedTest.php`

**Interfaces:**
- Consumes: snapshot service entries with `name` and `domains` (Task 4), banner `embedMessage` / `embedAcceptLabel` / `customise` and `defaults` (Task 6), `EmbedRewriter::rewrite` (Task 7).
- Produces: `EmbedConfig::fromSnapshot(array $snapshot, string $locale, string $default): array{services: list<...>, text: array{message, accept, customise}}`; `ConsentManager::rewriteEmbeds(string $contents, array $snapshot, string $locale, string $default, callable $onError): string` (pure, public static) used by `onPageOutput`.

- [ ] **Step 1: Write the failing tests.**

`tests/Support/EmbedConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\EmbedConfig;

final class EmbedConfigTest extends TestCase
{
    private function snapshot(): array
    {
        return [
            'defaults' => ['embedMessage' => 'Default {service}', 'embedAcceptLabel' => 'Default {category}', 'customise' => 'Customise'],
            'banner' => [
                'en_GB' => ['embedMessage' => 'English {service}', 'embedAcceptLabel' => '', 'customise' => 'Customise it'],
                'fr_FR' => ['embedMessage' => '', 'embedAcceptLabel' => 'Accepter {category}', 'customise' => ''],
            ],
            'categories' => [
                ['handle' => 'necessary', 'necessary' => true, 'content' => [], 'services' => [['name' => 'Session', 'domains' => ['example.com']]]],
                ['handle' => 'marketing', 'necessary' => false, 'content' => ['en_GB' => ['title' => 'Marketing']], 'services' => [
                    ['name' => 'YouTube', 'domains' => ['youtube.com']],
                    ['name' => 'NoDomains', 'domains' => []],
                ]],
                ['handle' => 'Bad Handle', 'necessary' => false, 'content' => [], 'services' => [['name' => 'X', 'domains' => ['x.com']]]],
            ],
        ];
    }

    public function testOnlyNonNecessaryServicesWithDomainsAreOffered(): void
    {
        $services = EmbedConfig::fromSnapshot($this->snapshot(), 'en_GB', 'en_GB')['services'];

        $this->assertSame(['YouTube'], array_column($services, 'name'));
        $this->assertSame('marketing', $services[0]['category']);
        $this->assertSame('Marketing', $services[0]['categoryTitle']);
    }

    public function testTextFallsBackFieldByFieldToTheDefaultLocaleThenThePackageDefault(): void
    {
        $en = EmbedConfig::fromSnapshot($this->snapshot(), 'en_GB', 'en_GB')['text'];
        $fr = EmbedConfig::fromSnapshot($this->snapshot(), 'fr_FR', 'en_GB')['text'];

        $this->assertSame(['message' => 'English {service}', 'accept' => 'Default {category}', 'customise' => 'Customise it'], $en);
        $this->assertSame(['message' => 'English {service}', 'accept' => 'Accepter {category}', 'customise' => 'Customise it'], $fr);
    }

    public function testAMalformedSnapshotGivesNoServicesAndBuiltInText(): void
    {
        $config = EmbedConfig::fromSnapshot([], 'en_GB', 'en_GB');

        $this->assertSame([], $config['services']);
        $this->assertNotSame('', $config['text']['message']);
        $this->assertNotSame('', $config['text']['accept']);
    }
}
```

`tests/Events/ConsentManagerEmbedTest.php` (pure `rewriteEmbeds`):

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Events;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Events\ConsentManager;

final class ConsentManagerEmbedTest extends TestCase
{
    private function snapshot(): array
    {
        return [
            'defaults' => ['embedMessage' => 'M {service}', 'embedAcceptLabel' => 'A {category}', 'customise' => 'C'],
            'banner' => [],
            'categories' => [['handle' => 'marketing', 'necessary' => false, 'content' => [], 'services' => [['name' => 'YouTube', 'domains' => ['youtube.com']]]]],
        ];
    }

    public function testAPageWithoutIframesIsReturnedWithoutTouchingTheSnapshot(): void
    {
        $this->assertSame('<p>x</p>', ConsentManager::rewriteEmbeds('<p>x</p>', fn() => throw new \LogicException('snapshot read'), 'en_GB', 'en_GB', static function (\Throwable $e): void {}));
    }

    public function testAMatchedIframeIsReplaced(): void
    {
        $out = ConsentManager::rewriteEmbeds('<iframe src="https://youtube.com/x"></iframe>', fn() => $this->snapshot(), 'en_GB', 'en_GB', static function (\Throwable $e): void {});

        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
    }

    public function testAFailureLeavesThePageAsItWasAndIsReported(): void
    {
        $errors = [];
        $html = '<iframe src="https://youtube.com/x"></iframe>';

        $out = ConsentManager::rewriteEmbeds($html, fn() => throw new \RuntimeException('db down'), 'en_GB', 'en_GB', function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $this->assertSame($html, $out);
        $this->assertCount(1, $errors);
    }
}
```

(The snapshot is passed as a callable so a page with no iframe never builds or reads it; `onPageOutput` passes `Catalog::snapshot(...)`.)

- [ ] **Step 2: Run to verify it fails** — `composer test -- --filter "EmbedConfigTest|ConsentManagerEmbedTest"`.
- [ ] **Step 3: Implement.** `src/Support/EmbedConfig.php`:

```php
<?php

namespace CookieManagerKit\Support;

/**
 * Pure: the services whose iframes are blocked and the placeholder text, from the cached snapshot. Services of the
 * always-on category, services without domains and categories with an invalid handle are never offered.
 */
final class EmbedConfig
{
    private const FALLBACK = [
        'embedMessage' => 'This content is provided by {service} and needs your consent to load.',
        'embedAcceptLabel' => 'Accept {category} cookies',
        'customise' => 'Customise',
    ];

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array{services: list<array{name: string, category: string, categoryTitle: string, domains: list<string>}>, text: array{message: string, accept: string, customise: string}}
     */
    public static function fromSnapshot(array $snapshot, string $locale, string $default): array
    {
        $services = [];

        foreach (is_array($snapshot['categories'] ?? null) ? $snapshot['categories'] : [] as $category) {
            $handle = is_array($category) ? ($category['handle'] ?? null) : null;

            if (!Text::isHandle($handle) || ($category['necessary'] ?? false) === true) {
                continue;
            }

            $title = self::pick(self::column(is_array($category['content'] ?? null) ? $category['content'] : [], 'title'), $locale, $default) ?: $handle;

            foreach (is_array($category['services'] ?? null) ? $category['services'] : [] as $service) {
                $domains = is_array($service) && is_array($service['domains'] ?? null) ? array_values(array_filter($service['domains'], 'is_string')) : [];

                if ($domains === []) {
                    continue;
                }

                $services[] = ['name' => (string) ($service['name'] ?? ''), 'category' => $handle, 'categoryTitle' => $title, 'domains' => $domains];
            }
        }

        $banner = is_array($snapshot['banner'] ?? null) ? $snapshot['banner'] : [];
        $defaults = is_array($snapshot['defaults'] ?? null) ? $snapshot['defaults'] : [];
        $text = static fn(string $key): string => self::pick(self::column($banner, $key), $locale, $default)
            ?: (is_string($defaults[$key] ?? null) && $defaults[$key] !== '' ? $defaults[$key] : self::FALLBACK[$key]);

        return ['services' => $services, 'text' => ['message' => $text('embedMessage'), 'accept' => $text('embedAcceptLabel'), 'customise' => $text('customise')]];
    }

    /**
     * @param array<string, mixed> $byLocale locale => row
     *
     * @return array<string, string> locale => the row's $key
     */
    private static function column(array $byLocale, string $key): array
    {
        $out = [];

        foreach ($byLocale as $locale => $row) {
            $out[(string) $locale] = is_array($row) && is_string($row[$key] ?? null) ? $row[$key] : '';
        }

        return $out;
    }

    /**
     * @param array<string, string> $byLocale
     */
    private static function pick(array $byLocale, string $locale, string $default): string
    {
        foreach ([$locale, $default] as $candidate) {
            if (($byLocale[$candidate] ?? '') !== '') {
                return $byLocale[$candidate];
            }
        }

        return '';
    }
}
```

In `ConsentManager` add:

```php
    /**
     * Pure apart from the callable that supplies the snapshot, which is only called when the page has an iframe. A
     * failure leaves the page as it was and is reported; the rewriter itself fails closed per iframe.
     *
     * @param callable(): array<string, mixed> $snapshot
     * @param callable(\Throwable): void       $onError
     */
    public static function rewriteEmbeds(string $contents, callable $snapshot, string $locale, string $default, callable $onError): string
    {
        if (stripos($contents, '<iframe') === false) {
            return $contents;
        }

        try {
            $config = EmbedConfig::fromSnapshot($snapshot(), $locale, $default);

            return EmbedRewriter::rewrite($contents, $config['services'], $config['text'], $onError);
        } catch (\Throwable $e) {
            self::report($onError, $e);

            return $contents;
        }
    }
```

and, in `onPageOutput`, after the existing `self::guarded(...)` snippet step (still behind the `isActive` gate that already returned early), add a second independent guarded step:

```php
        self::guarded(static function () use ($event): void {
            $contents = (string) $event->getArgument('contents');
            $rewritten = self::rewriteEmbeds(
                $contents,
                Catalog::snapshot(...),
                Catalog::resolveLocale((string) Localization::activeLocale()),
                Catalog::defaultLocale(),
                ErrorLog::record(...),
            );

            if ($rewritten !== $contents) {
                $event->setArgument('contents', $rewritten);
            }
        });
```

Add `EmbedConfig, EmbedRewriter` to the `Support` import. Note the existing early-exit in the snippet closure must not skip this step: keep them as two separate `guarded` calls.

`css/cmk.css`, append (use the file's existing custom properties; define the fallback values inline because the placeholder is not inside `.cmk`):

```css
.cmk-embed {
	position: relative;
	display: block;
	box-sizing: border-box;
	max-width: 100%;
}

.cmk-embed > iframe:not([src]) {
	display: none;
}

.cmk-embed__box {
	display: flex;
	flex-direction: column;
	gap: 12px;
	align-items: flex-start;
	justify-content: center;
	box-sizing: border-box;
	min-height: 160px;
	padding: 20px;
	color: #111111;
	font-size: 15px;
	line-height: 1.5;
	background: #f2f2f2;
	border: 1px solid rgba(0, 0, 0, 0.15);
	border-radius: 8px;
}

.cmk-embed__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.cmk-embed__accept,
.cmk-embed__prefs {
	padding: 8px 14px;
	font: inherit;
	cursor: pointer;
	border: 1px solid #0b5fff;
	border-radius: 6px;
}

.cmk-embed__accept {
	color: #ffffff;
	background: #0b5fff;
}

.cmk-embed__prefs {
	color: #0b5fff;
	background: transparent;
}
```

- [ ] **Step 4: Run** — `composer test`, `composer typecheck`, `composer format:check`. Expected: PASS.
- [ ] **Step 5: Commit**

```bash
git add src css tests
git commit -m "feat: block iframes of known services in page output"
```

---

### Task 9: Loader activation and the runtime accept button

**Files:**
- Modify: `js/cmk-loader.js`, `js/cmk-runtime.js`
- Test: `tests/js/loader.test.js`, `tests/js/runtime.test.js`

**Interfaces:**
- Consumes: markup from Task 7: `[data-cmk-embed="<category>"]` wrapper containing `[data-cmk-embed-placeholder]`, an `iframe[data-cmk-src]`, a `[data-cmk-embed-accept="<category>"]` button.
- Produces: `cmk.activate()` also restores embeds of granted categories; the runtime handles a click on an accept button by saving `current choice + that category`.

- [ ] **Step 1: Write the failing tests.**

Append to `tests/js/loader.test.js`:

```js
describe('embeds', () => {
	const EMBED = (category = 'marketing', src = 'https://www.youtube.com/embed/x') =>
		`<span class="cmk-embed" data-cmk-embed="${category}"><span data-cmk-embed-placeholder>blocked</span><iframe data-cmk-src="${src}"></iframe></span>`;
	const frame = () => document.querySelector('iframe');
	const placeholder = () => document.querySelector('[data-cmk-embed-placeholder]');

	it('keeps the iframe unloaded and the placeholder shown without consent', () => {
		document.body.innerHTML = EMBED();
		start();

		expect(frame().hasAttribute('src')).toBe(false);
		expect(placeholder()).not.toBeNull();
	});

	it('loads the iframe and removes the placeholder once its category is consented', () => {
		document.body.innerHTML = EMBED();
		setConsent(valid(['necessary', 'marketing']));
		start();

		expect(frame().getAttribute('src')).toBe('https://www.youtube.com/embed/x');
		expect(frame().hasAttribute('data-cmk-src')).toBe(false);
		expect(placeholder()).toBeNull();
	});

	it('does not load an embed of a category that was not consented', () => {
		document.body.innerHTML = EMBED('marketing');
		setConsent(valid(['necessary', 'analytics']));
		start();

		expect(frame().hasAttribute('src')).toBe(false);
	});

	it.each(['javascript:alert(1)', 'data:text/html,hi', '//evil.example/x', '/local', ''])('refuses the source %j', (src) => {
		document.body.innerHTML = EMBED('marketing', src);
		setConsent(valid(['necessary', 'marketing']));
		start();

		expect(frame().hasAttribute('src')).toBe(false);
	});

	it('loads straight away in the opt-out model', () => {
		document.body.innerHTML = EMBED();
		start({ ...BOOT, model: 'opt_out' });

		expect(frame().getAttribute('src')).toBe('https://www.youtube.com/embed/x');
	});

	it('does not load for a category the page does not know about', () => {
		document.body.innerHTML = EMBED('Bad Handle!');
		setConsent(valid(['necessary', 'marketing']));
		start();

		expect(frame().hasAttribute('src')).toBe(false);
	});

	it('loads the embed after save() grants its category', () => {
		document.body.innerHTML = EMBED();
		start();
		window.cmk.save(['marketing']);

		expect(frame().getAttribute('src')).toBe('https://www.youtube.com/embed/x');
	});

	it('does not touch a frame twice', () => {
		document.body.innerHTML = EMBED();
		setConsent(valid(['necessary', 'marketing']));
		start();
		frame().setAttribute('src', 'https://changed.example/');
		window.cmk.activate();

		expect(frame().getAttribute('src')).toBe('https://changed.example/');
	});
});
```

Append to `tests/js/runtime.test.js`:

```js
describe('embed accept button', () => {
	const EMBED =
		'<span class="cmk-embed" data-cmk-embed="marketing"><span data-cmk-embed-placeholder><button type="button" data-cmk-embed-accept="marketing">Accept Marketing</button></span><iframe data-cmk-src="https://www.youtube.com/embed/x"></iframe></span>';
	const frame = () => document.querySelector('iframe');
	const accept = () => document.querySelector('[data-cmk-embed-accept]');

	it('grants only that category, loads the iframe and closes the banner', () => {
		document.body.innerHTML = EMBED;
		mount();
		expect(root()).not.toBeNull();

		accept().click();

		expect(saved()).toEqual(['necessary', 'marketing']);
		expect(frame().getAttribute('src')).toBe('https://www.youtube.com/embed/x');
		expect(root()).toBeNull();
	});

	it('keeps what was already granted', () => {
		consent(['necessary', 'analytics']);
		document.body.innerHTML = EMBED;
		mount();

		accept().click();

		expect(saved().sort()).toEqual(['analytics', 'marketing', 'necessary']);
	});

	it('records the choice on the consent endpoint', () => {
		document.body.innerHTML = EMBED;
		mount();

		accept().click();

		expect(window.fetch).toHaveBeenCalled();
	});

	it('ignores a category the config does not know', () => {
		document.body.innerHTML = EMBED.replaceAll('marketing', 'nonsense');
		mount();

		accept().click();

		expect(window.cmk.get()).toBeNull();
		expect(frame().hasAttribute('src')).toBe(false);
	});
});
```

(Use the file's existing `root`, `saved`, `consent`, `mount` helpers; if the consent POST body assertion needs the exact shape, mirror how the existing runtime tests assert `fetch`.)

- [ ] **Step 2: Run to verify it fails** — `npx vitest run tests/js/loader.test.js tests/js/runtime.test.js`.
- [ ] **Step 3: Implement.** In `js/cmk-loader.js` extend `activate()` (add after the scripts loop, before the closing brace of `activate`):

```js
		var embeds = d.querySelectorAll('[data-cmk-embed]');

		for (var e = 0; e < embeds.length; e++) {
			var wrapper = embeds[e];
			var category = wrapper.getAttribute('data-cmk-embed');
			var frame = wrapper.querySelector('iframe[data-cmk-src]');
			var source = frame ? frame.getAttribute('data-cmk-src') : '';

			if (
				!frame ||
				wrapper.hasAttribute('data-cmk-done') ||
				granted.indexOf(category) === -1 ||
				!/^https?:\/\//i.test(source)
			) {
				continue;
			}

			wrapper.setAttribute('data-cmk-done', '1');
			frame.setAttribute('src', source);
			frame.removeAttribute('data-cmk-src');

			var box = wrapper.querySelector('[data-cmk-embed-placeholder]');

			if (box && box.parentNode) {
				box.parentNode.removeChild(box);
			}
		}
```

(`granted` is the existing variable at the top of `activate`.) Update the file header comment: "activates inert scripts and blocked embeds".

In `js/cmk-runtime.js` replace `onClick` with:

```js
	function acceptEmbed(handle) {
		var known = validCategories(state.config).map(function (category) {
			return category.handle;
		});

		if (!HANDLE.test(handle) || known.indexOf(handle) === -1) {
			return;
		}

		var stored = cmk.get();
		var granted = (stored ? stored.c : cmk.initial()).slice();

		if (granted.indexOf(handle) === -1) {
			granted.push(handle);
		}

		commit(granted);
	}

	function onClick(event) {
		var target = event.target && event.target.closest ? event.target : null;
		var accept = target ? target.closest('[data-cmk-embed-accept]') : null;

		if (accept) {
			event.preventDefault();
			acceptEmbed(accept.getAttribute('data-cmk-embed-accept'));

			return;
		}

		var node = target ? target.closest('[data-cmk-open]') : null;

		if (node) {
			event.preventDefault();
			runtime.open();
		}
	}
```

(`HANDLE` already exists in the runtime; confirm and reuse.)

- [ ] **Step 4: Run** — `npx vitest run`, `composer test` (nothing PHP changed but the suite must stay green), `npx prettier --check .` (run `npx prettier --write js tests/js` if it fails). Expected: PASS.
- [ ] **Step 5: Commit**

```bash
git add js tests/js
git commit -m "feat: load blocked embeds once their category is consented, with an accept button"
```

---

### Task 10: Translation slots, completeness report and copy-from-default

**Files:**
- Create: `src/Translation/Slot.php`, `src/Translation/TranslationSlots.php`, `src/Translation/TranslationReport.php`
- Test: `tests/Translation/TranslationReportTest.php`, `tests/Translation/TranslationSlotsTest.php`

**Interfaces:**
- Consumes: `BannerContent`, `Category`, `Service`, `Cookie` and their content setters.
- Produces: `Slot` (readonly `area`, `key`, `label`; `read(string $locale): string`; `write(string $locale, string $value): void`); `TranslationSlots::collect(EntityManagerInterface $em): list<Slot>`; `TranslationReport::AREAS = ['banner', 'category', 'service', 'cookie']`; `TranslationReport::matrix(array $slots, array $locales, string $default): array<string, array<string, array{filled: int, total: int}>>`; `TranslationReport::missing(array $slots, string $locale, string $default): list<Slot>`; `TranslationReport::copyFromDefault(array $slots, string $area, string $locale, string $default): int`.

- [ ] **Step 1: Write the failing tests.** A shared in-memory slot factory in `tests/Translation/FakeSlots.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Translation;

use CookieManagerKit\Translation\Slot;

final class FakeSlots
{
    /**
     * @param array<string, array<string, string>> $values key => locale => text
     * @param array<string, string>                $areas  key => area
     *
     * @return list<Slot>
     */
    public static function make(array &$values, array $areas): array
    {
        $slots = [];

        foreach ($areas as $key => $area) {
            $slots[] = new Slot(
                $area,
                $key,
                'Label ' . $key,
                static function (string $locale) use (&$values, $key): string {
                    return $values[$key][$locale] ?? '';
                },
                static function (string $locale, string $value) use (&$values, $key): void {
                    $values[$key][$locale] = $value;
                },
            );
        }

        return $slots;
    }
}
```

`tests/Translation/TranslationReportTest.php`:

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Translation;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Translation\TranslationReport;

final class TranslationReportTest extends TestCase
{
    public function testMatrixCountsOnlyFieldsThatHaveDefaultText(): void
    {
        $values = [
            'banner||title' => ['en_GB' => 'Hello', 'fr_FR' => 'Bonjour'],
            'banner||body' => ['en_GB' => 'Body'],
            'banner||save' => ['en_GB' => ''],
            'cookie|1|purpose' => ['en_GB' => 'P', 'fr_FR' => ''],
        ];
        $slots = FakeSlots::make($values, ['banner||title' => 'banner', 'banner||body' => 'banner', 'banner||save' => 'banner', 'cookie|1|purpose' => 'cookie']);

        $matrix = TranslationReport::matrix($slots, ['en_GB', 'fr_FR'], 'en_GB');

        $this->assertSame(['filled' => 2, 'total' => 2], $matrix['banner']['en_GB']);
        $this->assertSame(['filled' => 1, 'total' => 2], $matrix['banner']['fr_FR']);
        $this->assertSame(['filled' => 0, 'total' => 1], $matrix['cookie']['fr_FR']);
        $this->assertSame(['filled' => 0, 'total' => 0], $matrix['service']['fr_FR']);
    }

    public function testMissingListsEmptyTargetFieldsThatHaveDefaultText(): void
    {
        $values = ['a' => ['en_GB' => 'A', 'fr_FR' => ''], 'b' => ['en_GB' => 'B', 'fr_FR' => 'B!'], 'c' => ['en_GB' => '']];
        $slots = FakeSlots::make($values, ['a' => 'banner', 'b' => 'banner', 'c' => 'banner']);

        $this->assertSame(['a'], array_map(static fn($s) => $s->key, TranslationReport::missing($slots, 'fr_FR', 'en_GB')));
    }

    public function testCopyFromDefaultFillsOnlyEmptyFieldsOfTheChosenAreaAndNeverOverwrites(): void
    {
        $values = ['a' => ['en_GB' => 'A', 'fr_FR' => ''], 'b' => ['en_GB' => 'B', 'fr_FR' => 'Mine'], 'c' => ['en_GB' => 'C']];
        $slots = FakeSlots::make($values, ['a' => 'banner', 'b' => 'banner', 'c' => 'service']);

        $count = TranslationReport::copyFromDefault($slots, 'banner', 'fr_FR', 'en_GB');

        $this->assertSame(1, $count);
        $this->assertSame('A', $values['a']['fr_FR']);
        $this->assertSame('Mine', $values['b']['fr_FR']);
        $this->assertArrayNotHasKey('fr_FR', $values['c']);
    }

    public function testCopyIntoTheDefaultLocaleDoesNothing(): void
    {
        $values = ['a' => ['en_GB' => '']];
        $slots = FakeSlots::make($values, ['a' => 'banner']);

        $this->assertSame(0, TranslationReport::copyFromDefault($slots, 'banner', 'en_GB', 'en_GB'));
    }

    public function testAnUnknownAreaCopiesNothing(): void
    {
        $values = ['a' => ['en_GB' => 'A']];
        $slots = FakeSlots::make($values, ['a' => 'banner']);

        $this->assertSame(0, TranslationReport::copyFromDefault($slots, 'nonsense', 'fr_FR', 'en_GB'));
        $this->assertArrayNotHasKey('fr_FR', $values['a']);
    }
}
```

`tests/Translation/TranslationSlotsTest.php` (entities with an `EntityManagerInterface` mock whose repositories return fixtures; mirror how `tests/Scan/ClassificationStoreTest.php` or `DoctrineConsentLogStoreTest` builds its EntityManager double):

```php
    public function testSlotsExistForEveryTranslatableField(): void
    {
        // banner: 10 fields, per category: title + description, per service: description, per cookie: purpose
        $slots = TranslationSlots::collect($this->em([$banner], [$category], [$service]));  // $service has one cookie

        $keys = array_map(static fn($s) => $s->key, $slots);

        $this->assertContains('banner||title', $keys);
        $this->assertContains('banner||embedAcceptLabel', $keys);
        $this->assertContains('category|analytics|description', $keys);
        $this->assertContains('service|7|description', $keys);
        $this->assertContains('cookie|9|purpose', $keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function testWritingCreatesTheMissingLocaleRowThroughTheEntitySetters(): void
    {
        // read before/after on one banner slot, one category slot, one service slot, one cookie slot
        // e.g. $slot->write('fr_FR', '<b>Bonjour</b>') then $slot->read('fr_FR') === 'Bonjour' (the entity setter strips tags)
    }

    public function testCategoryTitleAndDescriptionWritesDoNotEraseEachOther(): void
    {
        // write title for fr_FR, then description for fr_FR: both are kept
    }
```

Write these three tests out in full against the real entities (`Category::setContent($locale, $title, $description)`, `Service::setContent`, `Cookie::setContent`, `BannerContent` rows persisted through `$em->persist`). The assertions above are the requirement. Use `$service->getCookies()`'s real cookie IDs by setting the `id` property through reflection if the entities have no ID setter (check `UpdatedEntity`).

- [ ] **Step 2: Run to verify it fails** — `composer test -- --filter "TranslationReportTest|TranslationSlotsTest"`.
- [ ] **Step 3: Implement.**

`src/Translation/Slot.php`:

```php
<?php

namespace CookieManagerKit\Translation;

/**
 * One translatable field: a stable key, where it lives, and how to read and write one locale of it.
 */
final class Slot
{
    /**
     * @param \Closure(string): string       $read
     * @param \Closure(string, string): void $write
     */
    public function __construct(
        public readonly string $area,
        public readonly string $key,
        public readonly string $label,
        private readonly \Closure $read,
        private readonly \Closure $write,
    ) {}

    public function read(string $locale): string
    {
        return ($this->read)($locale);
    }

    public function write(string $locale, string $value): void
    {
        ($this->write)($locale, $value);
    }
}
```

`src/Translation/TranslationReport.php`:

```php
<?php

namespace CookieManagerKit\Translation;

/**
 * Pure: what is translated, what is missing, and filling the gaps from the default locale. A field only counts
 * when the default locale has text for it (there is nothing to translate otherwise).
 */
final class TranslationReport
{
    public const AREAS = ['banner', 'category', 'service', 'cookie'];

    /**
     * @param list<Slot>   $slots
     * @param list<string> $locales
     *
     * @return array<string, array<string, array{filled: int, total: int}>> area => locale => counts
     */
    public static function matrix(array $slots, array $locales, string $default): array
    {
        $matrix = [];

        foreach (self::AREAS as $area) {
            foreach ($locales as $locale) {
                $matrix[$area][$locale] = ['filled' => 0, 'total' => 0];
            }
        }

        foreach ($slots as $slot) {
            if (!isset($matrix[$slot->area]) || $slot->read($default) === '') {
                continue;
            }

            foreach ($locales as $locale) {
                $matrix[$slot->area][$locale]['total']++;

                if ($slot->read($locale) !== '') {
                    $matrix[$slot->area][$locale]['filled']++;
                }
            }
        }

        return $matrix;
    }

    /**
     * @param list<Slot> $slots
     *
     * @return list<Slot>
     */
    public static function missing(array $slots, string $locale, string $default): array
    {
        return array_values(array_filter(
            $slots,
            static fn(Slot $slot): bool => $locale !== $default && $slot->read($default) !== '' && $slot->read($locale) === '',
        ));
    }

    /**
     * Fills empty fields of one area in $locale with the default locale's text. Never overwrites, never writes to
     * the default locale.
     *
     * @param list<Slot> $slots
     */
    public static function copyFromDefault(array $slots, string $area, string $locale, string $default): int
    {
        if (!in_array($area, self::AREAS, true)) {
            return 0;
        }

        $count = 0;

        foreach (self::missing($slots, $locale, $default) as $slot) {
            if ($slot->area === $area) {
                $slot->write($locale, $slot->read($default));
                $count++;
            }
        }

        return $count;
    }
}
```

`src/Translation/TranslationSlots.php`: builds slots from the entities. Banner fields table (key → label, getter, setter), then loops:

```php
    private const BANNER = [
        'title' => ['Heading', 'getTitle', 'setTitle'],
        'body' => ['Message', 'getBody', 'setBody'],
        'acceptAll' => ['Accept all button', 'getAcceptAllLabel', 'setAcceptAllLabel'],
        'rejectAll' => ['Reject all button', 'getRejectAllLabel', 'setRejectAllLabel'],
        'customise' => ['Customise button', 'getCustomiseLabel', 'setCustomiseLabel'],
        'save' => ['Save button', 'getSaveLabel', 'setSaveLabel'],
        'widget' => ['Settings button', 'getWidgetLabel', 'setWidgetLabel'],
        'policyLinkText' => ['Policy link text', 'getPolicyLinkText', 'setPolicyLinkText'],
        'embedMessage' => ['Blocked embed message', 'getEmbedMessage', 'setEmbedMessage'],
        'embedAcceptLabel' => ['Blocked embed button', 'getEmbedAcceptLabel', 'setEmbedAcceptLabel'],
    ];
```

`collect(EntityManagerInterface $em): array`: load `BannerContent` rows into `$rows` by locale (an array captured by reference); for each BANNER entry create `new Slot('banner', 'banner||' . $field, $label, fn($locale) => isset($rows[$locale]) ? $rows[$locale]->$getter() : '', function ($locale, $value) use (&$rows, $em, $setter) { $row = $rows[$locale] ??= (new BannerContent())->setLocale($locale); $row->$setter($value); $em->persist($row); })`. For each `Category` (ordered by `sortOrder`, `handle`): slots `category|<handle>|title` and `category|<handle>|description`, reading `$category->getContent($locale)?->getTitle() ?? ''` and writing with `$category->setContent($locale, $value, $category->getContent($locale)?->getDescription() ?? '')` (title) / `($locale, $category->getContent($locale)?->getTitle() ?? '', $value)` (description). For each `Service` (by name): `service|<id>|description` via `getContent($locale)?->getDescription()` / `setContent($locale, $value)`; label `Service: <name>`; and for each of its cookies `cookie|<id>|purpose` via `getContent($locale)?->getPurpose()` / `setContent($locale, $value)`, label `Cookie: <name> (<service>)`. Labels are admin-facing English plus names; the view escapes them. A slot write on an entity reached through a managed parent is persisted by cascade (verify `Category::$contents` has `cascade={"persist"}` like `Service`; if it does not, `persist` the returned content in the closure).

- [ ] **Step 4: Run** — `composer test`, `composer typecheck`, `composer format:check`. Expected: PASS.
- [ ] **Step 5: Commit**

```bash
git add src/Translation tests/Translation
git commit -m "feat: translation slots, completeness report and copy-from-default"
```

---

### Task 11: Translation CSV export, parse and import

**Files:**
- Create: `src/Translation/TranslationCsv.php`
- Test: `tests/Translation/TranslationCsvTest.php`

**Interfaces:**
- Consumes: `Slot`, `TranslationReport::missing`, `CsvCell::row`.
- Produces: `TranslationCsv::MAX_BYTES = 524288`, `TranslationCsv::MAX_ROWS = 5000`; `export(array $slots, string $locale, string $default): string`; `parse(string $csv, string $locale): list<array{key: string, value: string}>` (throws `\InvalidArgumentException` with a fixed English message on any problem; blank translations are returned with `value === ''` so the caller can count them); `apply(array $slots, array $rows, string $locale, string $default): array{applied: int, skipped: int}` (validates first, throws before writing anything).

- [ ] **Step 1: Write the failing tests** (`tests/Translation/TranslationCsvTest.php`, using `FakeSlots` from Task 10):

```php
<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Translation;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Translation\TranslationCsv;

final class TranslationCsvTest extends TestCase
{
    /** @return array{0: array<string, array<string, string>>, 1: list<\CookieManagerKit\Translation\Slot>} */
    private function fixture(): array
    {
        $values = [
            'banner||title' => ['en_GB' => 'Hello', 'fr_FR' => ''],
            'banner||body' => ['en_GB' => "Line one\nLine \"two\"", 'fr_FR' => ''],
            'cookie|3|purpose' => ['en_GB' => '=HYPERLINK("http://evil")', 'fr_FR' => ''],
            'service|2|description' => ['en_GB' => 'Done', 'fr_FR' => 'Fait'],
        ];

        return [$values, FakeSlots::make($values, ['banner||title' => 'banner', 'banner||body' => 'banner', 'cookie|3|purpose' => 'cookie', 'service|2|description' => 'service'])];
    }

    private function body(string $locale, array $lines): string
    {
        return "\xEF\xBB\xBF" . '"# locale=' . $locale . '"' . "\r\n" . '"key","area","default_text","translation"' . "\r\n" . implode('', $lines);
    }

    public function testExportListsOnlyMissingFieldsWithAHeaderAndLocaleLine(): void
    {
        [, $slots] = $this->fixture();
        $csv = TranslationCsv::export($slots, 'fr_FR', 'en_GB');

        $this->assertStringStartsWith("\xEF\xBB\xBF\"# locale=fr_FR\"\r\n\"key\",\"area\",\"default_text\",\"translation\"\r\n", $csv);
        $this->assertStringContainsString('"banner||title","banner","Hello",""', $csv);
        $this->assertStringNotContainsString('service|2', $csv);
    }

    public function testExportNeutralisesFormulaCellsAndKeepsMultilineText(): void
    {
        [, $slots] = $this->fixture();
        $csv = TranslationCsv::export($slots, 'fr_FR', 'en_GB');

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://evil\"\")\"", $csv);
        $this->assertStringContainsString("\"Line one\nLine \"\"two\"\"\"", $csv);
    }

    public function testAnExportedFileParsesBackToTheSameKeys(): void
    {
        [, $slots] = $this->fixture();
        $rows = TranslationCsv::parse(TranslationCsv::export($slots, 'fr_FR', 'en_GB'), 'fr_FR');

        $this->assertSame(['banner||title', 'banner||body', 'cookie|3|purpose'], array_column($rows, 'key'));
        $this->assertSame(['', '', ''], array_column($rows, 'value'));
    }

    public function testParseReadsTranslationsIncludingQuotesAndNewlines(): void
    {
        $rows = TranslationCsv::parse($this->body('fr_FR', ["\"banner||body\",\"banner\",\"x\",\"Ligne un\nLigne \"\"deux\"\"\"\r\n"]), 'fr_FR');

        $this->assertSame([['key' => 'banner||body', 'value' => "Ligne un\nLigne \"deux\""]], $rows);
    }

    /** @dataProvider rejected */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function testAMalformedFileIsRejected(string $csv): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TranslationCsv::parse($csv, 'fr_FR');
    }

    public static function rejected(): iterable
    {
        $header = '"key","area","default_text","translation"' . "\r\n";

        yield 'empty' => [''];
        yield 'no locale line' => [$header . '"a","b","c","d"' . "\r\n"];
        yield 'wrong locale' => ['"# locale=de_DE"' . "\r\n" . $header];
        yield 'wrong header' => ['"# locale=fr_FR"' . "\r\n" . '"id","text"' . "\r\n"];
        yield 'short row' => ['"# locale=fr_FR"' . "\r\n" . $header . '"banner||title","banner"' . "\r\n"];
        yield 'empty key' => ['"# locale=fr_FR"' . "\r\n" . $header . '"","banner","x","y"' . "\r\n"];
        yield 'duplicate key' => ['"# locale=fr_FR"' . "\r\n" . $header . '"banner||title","banner","x","y"' . "\r\n" . '"banner||title","banner","x","z"' . "\r\n"];
        yield 'too big' => ['"# locale=fr_FR"' . "\r\n" . $header . str_repeat('x', TranslationCsv::MAX_BYTES)];
    }

    public function testTooManyRowsAreRejected(): void
    {
        $lines = [];
        for ($i = 0; $i <= TranslationCsv::MAX_ROWS; $i++) {
            $lines[] = '"k' . $i . '","a","d","t"' . "\r\n";
        }

        $this->expectException(\InvalidArgumentException::class);

        TranslationCsv::parse($this->body('fr_FR', $lines), 'fr_FR');
    }

    public function testApplyWritesNonBlankTranslationsAndCountsTheRest(): void
    {
        [$values, $slots] = $this->fixture();

        $result = TranslationCsv::apply($slots, [['key' => 'banner||title', 'value' => 'Bonjour'], ['key' => 'banner||body', 'value' => '']], 'fr_FR', 'en_GB');

        $this->assertSame(['applied' => 1, 'skipped' => 1], $result);
        $this->assertSame('Bonjour', $values['banner||title']['fr_FR']);
        $this->assertSame('', $values['banner||body']['fr_FR']);
    }

    public function testApplyMayOverwriteAnExistingTranslation(): void
    {
        [$values, $slots] = $this->fixture();

        TranslationCsv::apply($slots, [['key' => 'service|2|description', 'value' => 'Terminé']], 'fr_FR', 'en_GB');

        $this->assertSame('Terminé', $values['service|2|description']['fr_FR']);
    }

    public function testAnUnknownKeyRejectsTheWholeFileAndWritesNothing(): void
    {
        [$values, $slots] = $this->fixture();

        try {
            TranslationCsv::apply($slots, [['key' => 'banner||title', 'value' => 'Bonjour'], ['key' => 'banner||nope', 'value' => 'x']], 'fr_FR', 'en_GB');
            $this->fail('Expected an exception.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('', $values['banner||title']['fr_FR']);
        }
    }

    public function testTheDefaultLocaleCanNeverBeTheTarget(): void
    {
        [$values, $slots] = $this->fixture();

        $this->expectException(\InvalidArgumentException::class);

        TranslationCsv::apply($slots, [['key' => 'banner||title', 'value' => 'Changed']], 'en_GB', 'en_GB');
    }
}
```

(Remove the stray `/** @dataProvider rejected */` docblock line if PHPUnit 11 complains; the attribute is the one that counts.)

- [ ] **Step 2: Run to verify it fails** — `composer test -- --filter TranslationCsvTest`.
- [ ] **Step 3: Implement** `src/Translation/TranslationCsv.php`:

```php
<?php

namespace CookieManagerKit\Translation;

use CookieManagerKit\Support\CsvCell;

/**
 * Pure: the translation CSV. One file is for one target locale. Import is all or nothing: the file is parsed and
 * every key checked before a single value is written.
 */
final class TranslationCsv
{
    public const MAX_BYTES = 524288;
    public const MAX_ROWS = 5000;
    private const HEADER = ['key', 'area', 'default_text', 'translation'];
    private const LOCALE_LINE = '# locale=';

    /**
     * @param list<Slot> $slots
     */
    public static function export(array $slots, string $locale, string $default): string
    {
        $out = "\xEF\xBB\xBF" . CsvCell::row([self::LOCALE_LINE . $locale]) . CsvCell::row(self::HEADER);

        foreach (TranslationReport::missing($slots, $locale, $default) as $slot) {
            $out .= CsvCell::row([$slot->key, $slot->area, $slot->read($default), '']);
        }

        return $out;
    }

    /**
     * @return list<array{key: string, value: string}>
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $csv, string $locale): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The file is too large.');
        }

        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw new \InvalidArgumentException('The file could not be read.');
        }

        try {
            fwrite($handle, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
            rewind($handle);

            $first = fgetcsv($handle, 0, ',', '"', '');

            if (!is_array($first) || ($first[0] ?? null) !== self::LOCALE_LINE . $locale) {
                throw new \InvalidArgumentException('The file is not for this language.');
            }

            if (fgetcsv($handle, 0, ',', '"', '') !== self::HEADER) {
                throw new \InvalidArgumentException('The file does not have the expected columns.');
            }

            $rows = [];
            $seen = [];

            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }

                if (count($cells) < 4) {
                    throw new \InvalidArgumentException('A row has too few columns.');
                }

                $key = (string) $cells[0];

                if ($key === '' || isset($seen[$key])) {
                    throw new \InvalidArgumentException('A row has an empty or repeated key.');
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new \InvalidArgumentException('The file has too many rows.');
                }

                $seen[$key] = true;
                $rows[] = ['key' => $key, 'value' => (string) $cells[3]];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<Slot>                                 $slots
     * @param list<array{key: string, value: string}>    $rows
     *
     * @return array{applied: int, skipped: int}
     *
     * @throws \InvalidArgumentException
     */
    public static function apply(array $slots, array $rows, string $locale, string $default): array
    {
        if ($locale === $default) {
            throw new \InvalidArgumentException('The default language cannot be imported.');
        }

        $byKey = [];

        foreach ($slots as $slot) {
            $byKey[$slot->key] = $slot;
        }

        foreach ($rows as $row) {
            if (!isset($byKey[$row['key']])) {
                throw new \InvalidArgumentException('The file contains a field that does not exist.');
            }
        }

        $applied = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (trim($row['value']) === '') {
                $skipped++;

                continue;
            }

            $byKey[$row['key']]->write($locale, $row['value']);
            $applied++;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }
}
```

- [ ] **Step 4: Run** — `composer test`, `composer typecheck`, `composer format:check`. Expected: PASS.
- [ ] **Step 5: Commit**

```bash
git add src/Translation tests/Translation
git commit -m "feat: translation CSV export and all-or-nothing import"
```

---

### Task 12: Translations dashboard page

**Files:**
- Create: `controllers/single_page/dashboard/cookie_manager/translations.php`, `single_pages/dashboard/cookie_manager/translations.php`
- Modify: `controller.php` (register the single page)
- Test: `tests/Dashboard/TranslationsViewTest.php`

**Interfaces:**
- Consumes: `TranslationSlots::collect`, `TranslationReport::*`, `TranslationCsv::*`, `SiteLocales::options()/defaultLocale()`, `Catalog::flush()`.
- Produces: page `/dashboard/cookie_manager/translations` with actions `view`, `copy`, `export`, `import` (all writes POST + token `submit`).

- [ ] **Step 1: Read first** — `controllers/single_page/dashboard/cookie_manager/consent_log.php` (the POST export response, `use` list, `URL` constant), `tests/Dashboard/ConsentLogViewTest.php` (how a view is rendered in a test) and `tests/ControllerSecurityTest.php` (what every controller must satisfy). Mirror them.
- [ ] **Step 2: Write the failing test** (`tests/Dashboard/TranslationsViewTest.php`), modelled on `ConsentLogViewTest`: render the view with a `$matrix`, `$locales` (`['en_GB' => 'English (en_GB)', 'fr_FR' => '<b>Français</b>']`), `$defaultLocale = 'en_GB'`; assert (a) every locale label is escaped, (b) each non-default locale has a copy form, an export form and an import form each containing the token output, (c) the default locale has none, (d) the `filled / total` counts are printed, (e) no form contains a GET method. Hostile locale labels must not appear unescaped.

```php
    public function testEveryNonDefaultLocaleGetsCopyExportAndImportFormsAndTheDefaultGetsNone(): void
    {
        $html = $this->render();

        $this->assertSame(1, substr_count($html, 'name="locale" value="fr_FR"') / 3);   // copy, export, import
        $this->assertStringNotContainsString('name="locale" value="en_GB"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }
```

(Adapt `render()` and the exact markup assertions to the view you write; the requirements are the five points above.)

- [ ] **Step 3: Run to verify it fails** — `composer test -- --filter TranslationsViewTest`.
- [ ] **Step 4: Implement.**

Controller `Translations extends DashboardPageController`, `private const URL = '/dashboard/cookie_manager/translations'`, `$helpers = ['form', 'concrete/ui']`, and:

```php
    private function slots(): array
    {
        return TranslationSlots::collect($this->entityManager);
    }

    /** A target locale from a POST: one of the site's own, and not the default. */
    private function targetLocale(): ?string
    {
        $locale = $this->post('locale');
        $locale = is_string($locale) && array_key_exists($locale, SiteLocales::options()) ? $locale : null;

        return $locale !== null && $locale !== SiteLocales::defaultLocale() ? $locale : null;
    }

    public function view()
    {
        $locales = SiteLocales::options();

        $this->set('locales', $locales);
        $this->set('defaultLocale', SiteLocales::defaultLocale());
        $this->set('matrix', TranslationReport::matrix($this->slots(), array_keys($locales), SiteLocales::defaultLocale()));
        $this->set('areas', [
            'banner' => [t('Banner'), '/dashboard/cookie_manager/banner'],
            'category' => [t('Categories'), '/dashboard/cookie_manager/categories'],
            'service' => [t('Services'), '/dashboard/cookie_manager/services'],
            'cookie' => [t('Cookies'), '/dashboard/cookie_manager/services'],
        ]);
    }
```

`copy()`: POST + token guard (copy the guard block from `services.php::save`); `$locale = $this->targetLocale()` else flash error `t('Choose a language other than the default.')`; `$area = $this->post('area')`; areas to run = `$area === 'all' ? TranslationReport::AREAS : [$area]` (only values in `TranslationReport::AREAS`); sum `TranslationReport::copyFromDefault($slots, $a, $locale, $default)` over them with ONE `$slots` collection; `$this->entityManager->flush(); Catalog::flush();` flash success `t2('Copied %d field from the default language.', 'Copied %d fields from the default language.', $count)` (use `t('Copied %s fields from the default language.', $count)` if `t2` complicates `ControllerSecurityTest`).

`export()`: POST + token guard; `$locale = targetLocale()`; response exactly like `consent_log.php::export` with body `TranslationCsv::export($this->slots(), $locale, $default)` and filename `'translations-' . $locale . '-' . gmdate('Ymd') . '.csv'` (`$locale` already validated against the site's locales, so it is safe in a header).

`import()`: POST + token guard; `$locale = targetLocale()`; `$file = $this->request->files->get('csv')`; require `$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->isValid() && $file->getSize() <= TranslationCsv::MAX_BYTES`; read `(string) file_get_contents($file->getPathname())` (never use the client file name); then

```php
        try {
            $slots = $this->slots();
            $result = TranslationCsv::apply($slots, TranslationCsv::parse($contents, $locale), $locale, SiteLocales::defaultLocale());
            $this->entityManager->flush();
            Catalog::flush();
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', t('The file could not be imported. %s', $e->getMessage()));

            return $this->buildRedirect(self::URL)->send();
        }
```

then flash success with `$result['applied']` and `$result['skipped']`. Because `apply()` validates everything before writing and `flush()` is a single unit of work, a rejected file writes nothing; if `flush()` throws a non-argument exception, let `ErrorLog::record($e)` and flash a generic error (same pattern as `consent_log.php`).

View: a table, rows = `$areas` (area label linking to its page with `O::e($view->url(...))`), columns = locales (`O::e($label)`, with the default badge as in `services.php`), cells `filled / total` printed with `O::e($cell['filled'] . ' / ' . $cell['total'])` and a `text-success` class when `filled === total` and `total > 0`. Below, for each non-default locale (loop `$locales`, skip `$defaultLocale`): a card titled with the escaped label containing three forms, all `method="post"` with `<?php echo $token->output('submit') ?>` and `<input type="hidden" name="locale" value="LOCALE">`:
1. copy: `action=$view->action('copy')`, a `<select name="area">` with `all` (`t('All areas')`) and each area, and a submit `t('Copy missing text from the default language')`;
2. export: `action=$view->action('export')`, submit `t('Download CSV of missing text')`;
3. import: `action=$view->action('import')`, `enctype="multipart/form-data"`, `<input type="file" name="csv" accept=".csv,text/csv" required>`, submit `t('Import translated CSV')`, and a help line `t('The file must be a CSV downloaded here for this language. Existing translations in this language are replaced by the values in the file. Nothing is changed if any row is invalid.')`.

When there is only one site locale, print `<p class="text-muted"><?php echo t('Your site has a single language, so there is nothing to translate.') ?></p>` instead of the cards.

`controller.php`: add `$this->addSinglePage('/dashboard/cookie_manager/translations', $pkg, t('Translations'), t('See what is translated in each language'));` after the consent_log line.

- [ ] **Step 5: Run** — `composer test` (`ControllerSecurityTest`, `ViewEscapingTest`, `SessionAccessTest` all police the new files), `composer typecheck`, `composer format:check`. Expected: PASS.
- [ ] **Step 6: Commit**

```bash
git add controllers single_pages controller.php tests
git commit -m "feat: add the Translations dashboard page"
```

---

### Task 13: Release 0.5.0

**Files:**
- Modify: `controller.php`, `CHANGELOG.md`, `README.md`

- [ ] **Step 1:** In `controller.php` set `$pkgVersion = '0.5.0';`. In `installOrUpgrade`, after the seed and `syncBundled()` calls, add:

```php
        // After an upgrade the new columns and tables must be visible to every cached layer, or settings saved on the
        // new fields are silently dropped until the cache is cleared by hand (seen on the 0.3.0 to 0.4.0 Update).
        app('cache')->flush();
        Catalog::flush();
```

If `app('cache')` is not the right binding in this Concrete version, find the one `Dashboard > Clear Cache` uses (`\Concrete\Core\Cache\CacheLocal` / `Cache::flush()` facade) and use that; do not invent a service name.

- [ ] **Step 2:** `CHANGELOG.md`: add a `0.5.0` entry listing, in the file's existing style: the cookie list per Service and the `cookie_declaration` block (Cookie Declaration), cookie rows pre-filled when a cookie finding is approved, embed placeholders (automatic, matched against Service domains, accept button grants only that category, and logged like any other choice), the two new Banner fields, the Translations page (completeness, copy from default, CSV round trip), and the cache flush on upgrade. Note under "Upgrading" that blocked embeds need Services to list their domains.
- [ ] **Step 3:** `README.md`: remove "declaration block, embed placeholders, translation assist" from the "Not built yet" line (leave regional rules and the phase 5 items) and add one short paragraph for each of the three features.
- [ ] **Step 4: Full verification:** `composer test`, `npx vitest run`, `composer typecheck`, `composer format:check` all pass.
- [ ] **Step 5: Commit**

```bash
git add controller.php CHANGELOG.md README.md
git commit -m "chore: release 0.5.0"
```

The browser check on the dev site (package Update 0.4.0 to 0.5.0 without clearing the cache, declaration block on a page, an iframe page for the placeholder and accept flow, audit log entry, the Translations page, copy-from-default and a CSV import round trip) is run by the controller after the final branch review, not by a subagent.
