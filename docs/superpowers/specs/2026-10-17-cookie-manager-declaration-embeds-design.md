# Cookie Manager phase 4: declaration block, embed placeholders, translation assist (0.5.0)

## 1. Scope

Three features, built in this order because each later one covers the strings of the earlier ones:

- **4a** An admin-curated cookie list per Service and a `cookie_declaration` block that publishes it.
- **4b** Embed placeholders: iframes from known services are blocked until their category is consented.
- **4c** Translation assist: per-locale completeness view, copy-from-default, and a CSV export/import round trip.

Out of scope: machine translation or any outbound call, region detection, A/B testing, reports and share links (phase 5), blocking anything other than iframes (scripts already go through `Inert` and `TrackingCode`), and rewriting iframes that are not in rendered page output (for example JSON responses).

Carried-over rules (phase 1-3), which every part must keep:

- Page HTML is identical whatever the visitor's consent cookie says (full-page-cache safe). State is applied by the loader/runtime in the browser.
- All admin text is plain text (`Text::plain`) and every output is escaped (`Output::e`); URLs go through `Output::safeUrl`.
- Content is per locale, with fallback to the site default locale; locales are validated with `Text::isLocale` and resolved with `LocaleResolver`.
- Dashboard writes are token-protected and admin only; everything fails closed.
- The package only reads the consent cookie through `cmk-loader.js`.

## 2. 4a: cookie list and declaration block

### Data

- `Cookie` entity (`cmkCookies`): `service` (ManyToOne `Service`, `onDelete=CASCADE`, not null), `name` (string 128, plain), `durationDays` (integer, `0` = session, max 3650). Unique `(serviceID, name)`.
- `CookieContent` entity (`cmkCookieContents`): `cookie` (ManyToOne, cascade), `locale` (32), `purpose` (text, plain). Unique `(cookieID, locale)`. Same shape as `ServiceContent`.
- `Service` gets a `cookies` OneToMany collection; no other `Service` column changes. The provider shown is `Service::vendor`.

Duration is stored as days, not text, so it can be translated: `0` renders as the translated "Session", otherwise "N day(s)" using ConcreteCMS pluralisation (`t2`), with whole years and months not abbreviated (keeps it predictable).

### Admin

- The Services dashboard page gains a cookies section per Service: add, edit and remove rows (name, duration days, purpose per site locale). Same save path, token and validation style as the existing service form.
- Approving a scanner finding of `kind = cookie` pre-creates a `Cookie` row (name from the finding, `durationDays` 0, empty purpose) on the new Service. Duplicates by `(service, name)` are skipped. The admin completes it. Findings of other kinds create nothing.
- A cookie with an empty purpose in the visitor's locale and the default locale is shown with the purpose cell blank; the translation view (4c) reports it as missing.

### Block

- Block type `cookie_declaration` registered with `BlockTrait` in `controller.php`. No settings in this version.
- Output: one section per category (title, description), in category order. Each lists active Services' cookies in a table: cookie name, provider (`Service::vendor`), purpose, duration. Categories with no cookies are omitted, except `necessary`, which also lists the essential Concrete cookies from `EssentialCookies` (name, purpose, duration) because those are not stored as `Cookie` rows.
- Locale: the page's locale via `LocaleResolver`, falling back to the default locale per field, never an error.
- A "Change my consent" button carrying `data-cmk-open` (the runtime already opens the banner for that attribute). The button label is a package string (`t()`).
- Nothing in the block depends on the visitor's cookie, so the block is cache safe.

## 3. 4b: embed placeholders

### Matching

- An `<iframe>` is blocked when its `src` is an http(s) URL whose host equals, or is a subdomain of, a domain listed in an active Service's `domains` field, and that Service's category is not `necessary`. First match wins; the category is the Service's category.
- Services with `domains` empty never match. Disabled (inactive) Services never match. Hosts compare case-insensitively with IDN left as given.
- Relative, protocol-relative-resolved-to-the-page, `srcdoc`, `data:` and `javascript:` iframes are left alone (a protocol-relative `//host/...` URL is treated as http(s) and matched).

### Rewrite

- Done in the existing page-output listener (`ConsentManager::onPageOutput`), under the same gate (`isActive`), so edit mode, the dashboard and non-page responses are untouched. The rewrite is a pure function, unit tested, like `rewriteSnippets`.
- Regions inside `<script>`, `<style>`, `<template>`, `<noscript>` and HTML comments are masked and never rewritten.
- A matched iframe becomes a wrapper:
  `<div class="cmk-embed" data-cmk-embed="CATEGORY">` containing the placeholder (message, accept button, preferences button) and an inert iframe whose `src` has moved to `data-cmk-src` (other attributes kept, `title` kept for accessibility). The iframe has no `src` while blocked, so nothing loads.
- If an iframe cannot be rewritten safely (unparseable attributes), it is removed from the output (fail closed), and the error goes to `ErrorLog`.
- `width`, `height` and `style` of the original iframe size the placeholder box so layout does not jump.

### Activation (browser)

- `cmk-loader.js` `activate()` also handles `[data-cmk-embed]`: if the category is granted it restores `src` from `data-cmk-src` (only after checking it is still http(s)), hides the placeholder, and marks the node done. Nonce mode does not apply (no script is created).
- The accept button calls `cmk.save(current categories + this category)` and posts the choice through the existing runtime path (`commit`), so the audit log and statistics record it like any other custom choice. It never grants other categories. It reuses the existing Consent Mode `update`.
- The preferences button carries `data-cmk-open`.
- Opt-out model and prior consent: the category is already granted at load, so the iframe loads without the visitor seeing the placeholder.
- No-JS: the placeholder shows the message only. A `<noscript>` note is not needed because the message alone is visible without JS.

### Text

- New `BannerContent` columns: `embedMessage` (text, plain, default empty) and `embedAcceptLabel` (string 64). `embedAcceptLabel` may contain `{category}`, replaced with the category's title at render. The preferences button reuses `customiseLabel`.
- Empty fields fall back to the default locale, then to package defaults (`t()`): "This content is provided by {service} and needs your consent to load." / "Accept {category} cookies". `{service}` is replaced with the Service name.
- Banner dashboard page gets two fields per locale for these.

## 4. 4c: translation assist

### Completeness view

- New dashboard page `cookie_manager/translations` (single page, controller + view as the others), listed in the dashboard menu.
- Matrix: rows are areas, columns are site locales (`SiteLocales`). Areas: banner (title, body, labels, policy link text, embed texts), categories (title, description), services (description), cookies (purpose). A cell shows `filled / total` fields and links to the relevant edit page. The default locale is the reference; a field counts as filled when non-empty.

### Copy from default

- Per area and per locale, a token-protected action copies the default locale's value into every empty field of that locale. It never overwrites a non-empty field and never creates content for the default locale. Result is reported as a count. Rows are created when missing.

### CSV round trip

- **Export** (GET with token, no query data other than the locale): one CSV for one target locale of the fields that are empty there. Columns: `key`, `area`, `default_text`, `translation` (blank). `key` is `area|reference|field` where reference is the category handle, or the numeric service/cookie ID, or empty for banner. UTF-8 with BOM, `CsvCell` guard against formula injection, filename fixed per locale.
- **Import** (POST, token, file up to 512 KB, one locale selected in the form and repeated in the file's first line as `# locale=xx_XX`): only rows whose key matches an existing field are applied, and only non-blank translations. Values pass through `Text::plain` and the field's length limit. A key for the default locale, an unknown key, a bad locale or a malformed file rejects the whole file with an error and changes nothing. Applied in one transaction. Reports counts of applied and skipped rows.
- Import may overwrite a non-empty field in the target locale; that is what a translator edit is.
- Neither action leaves the server.

## 5. Release and housekeeping

- Version 0.5.0; `CHANGELOG` and `README` updated (README "not built yet" line loses declaration block, embed placeholders and translation assist).
- Schema changes come from the new entities and the `BannerContent` columns through the normal package Update.
- Open item from phase 3: settings did not save after Update until Clear Cache. During 4a, check whether `controller.php::installOrUpgrade` can flush the ORM metadata cache safely and add it if it can be done in one small change; otherwise record the finding in the changelog.

## 6. Testing

- Unit tests per piece; pure functions for the iframe rewrite, domain matching, key building, CSV parse/build.
- Rewrite tests must include: masked regions, attribute quoting variants, uppercase tags, multiple iframes, unmatched hosts, subdomain and lookalike hosts (`evilyoutube.com` must not match `youtube.com`), a `title` containing `>`, an unterminated iframe (fail closed), and nesting.
- Guard tests as in phase 3 (no `Request::getSession()`; no raw echo of admin text in the new views).
- JS: Vitest for the loader's embed activation (granted, not granted, non-http `data-cmk-src` refused, opt-out model).
- Browser check on the dev site before merge: block placeholder renders and accepts, audit log shows the entry, declaration block on a page, translation matrix, copy-from-default and a CSV import round trip. The CSV download needs the user's permission.
- PHPUnit, PHPStan and php-cs-fixer clean as before.

## 7. Rulings made in this spec

- Duration is days, not free text: translatable and sortable; costs flexibility for odd durations such as "until the browser closes", covered by `0`.
- The preferences button on the placeholder reuses `customiseLabel` instead of adding a field; a translator cannot give it separate wording.
- Import may overwrite non-empty translations; a preview step is not built.
- The essential Concrete cookies in the declaration block are listed from `EssentialCookies`, not stored, so they are not editable or translatable beyond the package's own strings.
