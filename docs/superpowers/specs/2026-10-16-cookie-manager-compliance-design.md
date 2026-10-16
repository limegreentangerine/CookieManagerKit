# CookieManagerKit Phase 3: Audit log, re-consent, Consent Mode v2, privacy rules (Design)

Date: 2026-10-16
Status: Draft, awaiting review
Parent plan: `/Users/david/.claude/plans/cryptic-frolicking-pixel.md` (5-phase roadmap)
Builds on: `docs/superpowers/specs/2026-10-15-cookie-manager-core-design.md` (phase 1) and `docs/superpowers/specs/2026-10-16-cookie-manager-scanner-design.md` (phase 2, shipped as 0.3.0)

## 1. Goal
Make consent provable (3a), re-askable with a recorded reason (3d), correct for Google tags (3b), and honour the visitor's privacy signal (3c). Phase 3 ships as 0.4.0, built in the order 3a, 3d, 3b, 3c. Each part is independently shippable, and 3d and 3c both write to the 3a log.

Out of scope: region detection (see section 2), a GeoIP database, per-region rule sets, admin-editable Consent Mode mapping, `url_passthrough` and `ads_data_redaction` options, automatic re-consent, storing IP addresses, and anything in phases 4 and 5 (declaration block, embed placeholders, translation assist, A/B testing, health check, PDF export, scheduled reports, share links).

## 2. Decisions agreed
- **No region detection.** The package does not determine where a visitor is. Region fields do not exist in the log or the config. Rules are site-wide.
- **Visitor identity is a random GUID.** No IP address, no IP hash, no raw user agent is stored.
- **The visitor's consent ID is constant across changes.** This takes two entities (section 4).
- **Re-consent is prompted, never automatic.** Nothing bumps the policy version without an admin choosing it.
- **Consent Mode uses a fixed mapping** with a single on/off setting.
- **GPC pre-declines marketing and the banner still appears.**
- **Opt-in remains the default model**; opt-out is an explicit admin setting with a warning.

## 3. Success criteria
1. Every accepted consent POST produces exactly one `ConsentLogEntry`, attached to a `ConsentSubject` whose GUID is returned to the browser and stored in `cmk_consent` as `i`. A later change by the same browser appends an entry to the same subject.
2. An admin can look up a consent ID and see that visitor's whole decision history, filter and export the log as CSV, and entries older than the retention period are purged by a scheduled task.
3. A failed log write never changes what the visitor sees or what the cookie holds.
4. Bumping the policy version requires a reason, and the reason and history are visible in the dashboard. Approving a scanner finding that adds a service to a non-necessary category offers a one-click "ask visitors again".
5. With Consent Mode on, `gtag('consent','default',…)` runs before any other script on the page, every signal is `denied`, and a stored choice or a new choice updates the signals to match the mapping in section 7. With it off, nothing Google-specific is emitted.
6. A browser sending the GPC signal starts with `marketing` declined and the banner shown. Nothing is recorded until the visitor acts; if they confirm the starting selection unchanged, the entry has trigger `gpc`.
7. Page HTML remains byte-identical for every visitor with and without consent, GPC or an ID.
8. Everything originating outside the admin's own typing (consent IDs, categories, locales, user agents, reasons shown in exports) is validated on ingestion and encoded on output (section 9).

## 4. Data model (3a and 3d)
New Doctrine entities under `src/Entity`.

- **`ConsentSubject extends UpdatedGuidEntity`**: one row per browser. Its GUID is the consent ID. `date_created` is the first decision and `date_updated` the latest decision, and retention is computed from `date_updated`. No other columns.
- **`ConsentLogEntry extends BaseEntity`**: one row per decision, append-only and never updated. Columns: `subject` (many-to-one, `ON DELETE CASCADE`), `policyVersion` (int), `categories` (JSON list of handles), `trigger` (`accept_all`, `reject_all`, `custom`, `gpc`, `reconsent`), `locale`, `userAgentFamily`.
- **`PolicyVersionChange extends BaseEntity`**: append-only history of policy versions. Columns: `version`, `reason` (required, 1 to 500 characters), `adminUserId`, and the creation date. Seeded on upgrade with the current version and the reason "Initial version".
- **`Settings`** gains `consentLogRetentionMonths` (default 24, clamped 1 to 120), `consentModeEnabled` (bool, default off) and `defaultModel` (`opt_in` or `opt_out`, default `opt_in`).

`UpdatedGuidEntity` assigns its GUID on persist (`GeneratedValue(strategy="UUID")`), so the subject is flushed before the entry is written and the ID is known only afterwards. The interface in section 5 reflects that.

`userAgentFamily` is a coarse bucket derived server-side from the user agent, formatted `<browser>/<device>` (for example `Chrome/desktop`), chosen from fixed lists with `Other` as the fallback. The raw string is discarded.

## 5. Audit log (3a)
- **Interface change:** `ConsentRecorder::record(ConsentChoice $choice): ?string` returns the consent ID or null. `ConsentChoice` gains `trigger`, `locale`, `userAgentFamily` and an optional `subjectId`. `NullConsentRecorder` returns null. `PersistingConsentRecorder` is bound by the package controller.
- **Endpoint flow (`Ajax\Consent`):** the existing same-origin, JSON, schema, size and rate-limit checks stay. `ConsentRequest::parse` additionally accepts optional `i` (a canonical lowercase GUID), `trigger` (allowlist above) and `locale` (allowlist of site locales). The recorder then:
  1. loads the subject for `i` if it parses and exists; otherwise creates one;
  2. appends a `ConsentLogEntry`;
  3. touches the subject so `date_updated` moves;
  4. all in one transaction.
- **Response:** `{"ok":true,"id":"<guid>"}`. The same body and status are returned whether `i` was found, new or unknown, so the endpoint cannot be used to test whether an ID exists. Nothing from the request is reflected.
- **Failure:** the recorder catches storage errors, logs a fixed message through `ErrorLog`, and returns null. The endpoint then answers `{"ok":true}` without an `id`, and the runtime keeps its existing cookie (with or without `i`).
- **Runtime and loader:** the runtime sends the current `i` (when the cookie holds a well-formed one) and the trigger, and writes the returned `id` into the cookie as `i`. The cookie value becomes `{"v":..,"c":[..],"t":..,"i":"<guid>"}`. The loader's parser tolerates a missing or malformed `i`, which is dropped, and consent validity is unchanged (still decided by `v`).
- **Dashboard page `/dashboard/cookie_manager/consent_log`:** a table (50 per page) with date, consent ID, version, categories, trigger and locale. Filters: date range, trigger, version, category, and consent ID. Looking up an ID shows that subject's full history. Every filter value redisplayed is encoded.
- **CSV export:** for the current filter, with formula neutralisation (section 9), `Content-Disposition: attachment` and `nosniff`. Columns: date, consent ID, policy version, categories, trigger, locale, user agent family, and the re-consent reason. The reason is the `PolicyVersionChange.reason` of the version the entry was recorded under (joined on `policyVersion`), so it explains why the visitor was asked; it is empty for a version with no change row. The lookup view of a consent ID shows the same reason beside each entry. It is capped at 100,000 rows with a visible notice when the cap applies.
- **Retention:** a scheduled task `cmk_purge_consent_log` deletes subjects whose `date_updated` is older than `consentLogRetentionMonths` (entries cascade), in batches of 1,000 within a time limit. Registered in `tasks.xml` and the `cookie_manager_kit` task set, following `cmk_scan_crawl`. The Settings screen states the retention period and that purged records can't be recovered.
- **Statistics:** the existing banner statistics are untouched. They are anonymous counters and stay separate from the log.

## 6. Re-consent (3d)
- **Bumping requires a reason.** The Banner page's "ask again" checkbox gains a required reason field. Saving writes a `PolicyVersionChange` and increments `policyVersion` in one transaction. Without a reason the save is refused with an encoded error.
- **History.** The Banner page lists recent `PolicyVersionChange` rows (version, date, admin, reason).
- **Trigger.** After a bump, a visitor's first answer is logged with trigger `reconsent`, not `accept_all`/`reject_all`/`custom`. The runtime detects this when the cookie held a valid-shaped value with an older `v`; the server also accepts it only from the allowlist.
- **Prompt, never automatic.** When an admin approves a scanner finding that creates or extends a Service in a non-necessary category (`Scan\Review`), the Findings page shows a notice with a button "Ask visitors again". It posts to the same bump action with the reason pre-filled to "Added <service name> to <category title>", editable before saving. If the admin ignores it, nothing changes.
- **Expiry.** The existing cookie lifetime (`consentLifetimeDays`) still returns the banner after that period. No new setting.

## 7. Consent Mode v2 (3b)
- **Setting:** a single `consentModeEnabled` checkbox on the Banner page, off by default, with help text naming the signals.
- **Config:** the config builder exposes `consentMode: true` so the runtime and the inline loader agree.
- **Loader emission (inline, before any other script):** when enabled, the loader defines `window.dataLayer` and `window.gtag` if absent and calls `gtag('consent','default',{…})` with every signal `denied` and `wait_for_update: 500`. If a valid stored choice exists it is applied at once with `gtag('consent','update',{…})`, so the default and update happen in the same tick on every page load.
- **Updates:** on `consent:changed` the runtime calls `gtag('consent','update',{…})` with the mapped signals. The loader exposes no other Google behaviour.
- **Fixed mapping:**

  | Category | Signals |
  |---|---|
  | `necessary` | `security_storage` (always granted) |
  | `preferences` | `functionality_storage`, `personalization_storage` |
  | `analytics` | `analytics_storage` |
  | `marketing` | `ad_storage`, `ad_user_data`, `ad_personalization` |

  A signal is `granted` when its category is in the stored choice and `denied` otherwise. Custom categories added by an admin map to no signal.
- **Page output.** Because the loader derives everything from the cookie in the browser, the inline snippet is the same for every visitor (it contains only the enabled flag), which keeps the cache-identical rule.
- **Interaction with inert scripts.** Consent Mode is additive: category-based script blocking works exactly as before, and Consent Mode only informs Google tags that are already allowed to load.

## 8. Privacy default model and GPC (3c)
- **Default model (site-wide):** `defaultModel` is `opt_in` or `opt_out`.
  - `opt_in` is today's behaviour: all non-necessary categories are off until the visitor chooses.
  - `opt_out`: with no stored choice, non-necessary categories are active by default (so inert scripts for those categories run on first load), the banner is shown as a notice with a withdraw option, and the first-load choice is not written until the visitor acts. Consent Mode defaults still start `denied` unless the categories are active, in which case the loader applies the active set via the update call.
  - The Banner page states in help text that `opt_out` is not suitable for EU or UK audiences. No other validation is applied.
- **GPC:** the loader reads `navigator.globalPrivacyControl === true`. When true and no stored choice exists, the starting selection excludes `marketing` regardless of the default model, `marketing` scripts stay inert, and the banner still appears for every other category. The preference centre shows `marketing` as off with a short note that it was pre-set by the browser's Global Privacy Control signal; the visitor may still turn it on.
- **Logging:** nothing is recorded until the visitor acts. If GPC pre-declined `marketing` and the visitor confirms the starting selection without changing it (for example by pressing Save in the preference centre), the entry has trigger `gpc`. Any other choice uses its normal trigger.
- **Not covered:** the `Sec-GPC` request header is not read, because pages are served identically to all visitors; only the browser property is used.

## 9. Security
- **Consent endpoint (public):** keeps every phase 1 defence (same-origin, JSON only, schema, size cap, rate limit, fixed body, never reflects input). New fields: `i` must match `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$` after lowercasing; `trigger` and `locale` are allowlisted; unknown keys are rejected. The ID is a 122-bit random value that exists only in that visitor's cookie, so appending to a stolen ID is infeasible to guess; the endpoint's identical response for known and unknown IDs prevents confirming one.
- **No personal data beyond the random ID.** There is no IP address, hash, raw user agent or region in storage, logs or exports. The consent ID in a cookie is the only link between a visitor and their records.
- **Admin pages:** `/consent_log` and the export check page permissions and, for the export and any state change, a CSRF token. Every value is encoded for its context (`h()`, `json_encode` with the hex flags, `rawurlencode`). Admin JS uses `textContent`. Consent IDs echoed in lookup fields and "no results" messages are encoded.
- **CSV:** formula neutralisation for cells starting with `=`, `+`, `-`, `@`, tab or carriage return, quoted fields, attachment disposition, `nosniff`. Consent IDs, categories and locales are already restricted. The reason column is free text, and its pre-filled form can contain a service name that came from a scanner finding, so it is treated as hostile: it is neutralised and quoted like every other cell and encoded wherever it is shown.
- **Reasons:** stored as plain text, 1 to 500 characters, encoded on every display.
- **Retention:** the purge task deletes inside transactions, batches, and logs counts only.
- **Essential cookies:** unchanged. `cmk_consent` stays in the essential list, and the consent flow never touches the session cookies (section 5.1 of the phase 1 spec).

## 10. Testing
- **PHPUnit:** `ConsentRequest` (valid and malformed `i`, unknown trigger or locale, unknown keys, oversize body); `PersistingConsentRecorder` (new subject, existing subject, unknown `i` creates a new subject, entry count, `date_updated` moves, rollback on failure, null return and no exception on storage error); endpoint (identical body for known and unknown IDs, headers, rate limit, status codes); retention task (cutoff, cascade, batching); `PolicyVersionChange` (reason required, length bounds, history order, version increments atomically); `Review` offers the re-consent prompt only for non-necessary categories; Consent Mode mapping table; config builder (`consentMode`, `defaultModel`); filters and CSV (formula injection including in the reason column, the reason matches the entry's policy version, an empty reason for a version with no change row, hostile consent ID, hostile search term redisplayed encoded); CSRF and permission checks on the dashboard actions.
- **Vitest:** loader (`i` parsing and dropping a malformed `i`, default/update `gtag` calls in order and in the same tick, signals for each category set, nothing emitted when disabled, GPC pre-decline, `opt_out` activation, trigger selection including `reconsent` and `gpc`); runtime (sends the stored `i`, stores the returned `id`, tolerates a response without `id`, keeps working if the request fails).
- **XSS cases:** hostile `i`, `trigger`, `locale` and `reason` values are rejected or rendered as text; a hostile cookie value is treated as no consent.
- **Browser, on the local dev site** (see the local-dev-site memory): the first choice returns and stores an ID; a later change keeps the same ID and the log viewer shows both entries; clearing the cookie makes a new ID; a bump with a reason forces the banner and logs `reconsent`; Consent Mode on shows `denied` defaults before any tag and updates after a choice (checked in `dataLayer`); a browser with GPC enabled starts with marketing off; HTML identical across visitors; no console errors.
- **Static:** PHPStan, php-cs-fixer, Prettier and the no-unsafe-sink checks from the earlier phases.

## 11. Housekeeping included in phase 3
- Version 0.4.0 and a CHANGELOG entry with upgrade notes: run **Update**; this creates the three tables and the dashboard page, registers `cmk_purge_consent_log` (tasks only register on install or upgrade), and seeds the first `PolicyVersionChange` row.
- Existing visitors keep working: a cookie without `i` is valid, and their next choice creates a subject.
- Dashboard navigation entry for Consent Log and an uninstall step for the new tables.
- The README documents the data stored and the retention default.

## 12. Extension points reserved for later phases
- The log is the data source for phase 5 CSV/PDF export and scheduled email reports; the export query is a service, not controller code.
- `ConsentChoice::trigger` is an allowlist in one place, so phase 5 A/B testing can add values.
- The Consent Mode mapping is a single class, so an admin-editable mapping can replace it later if needed.
- Region-specific rules would sit on top of `defaultModel` if detection is ever added.

## 13. Decisions deferred to the implementation plan
- Exact column types and indexes (indexes at minimum on `ConsentLogEntry.subject`, `ConsentSubject.date_updated` and `ConsentLogEntry.date_created`).
- Browser and device bucket lists for `userAgentFamily`.
- Whether the Consent Log page lives under the existing dashboard navigation group or beside Statistics.
