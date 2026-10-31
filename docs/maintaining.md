[← AESBin docs](README.md) · [Project home](../README.md)

# Maintaining AESBin

Notes for whoever has to change this codebase later: most likely the same
person who wrote it, several months on, with none of the context still loaded.

## Database schema versions

Two version numbers exist and they are independent:

| | Example | Moves when | Lives in |
|---|---|---|---|
| `AESBIN_VERSION` | `"1.2.3"` | any release | a constant in `app/includes/bootstrap.php` |
| schema version | `1` | the database structure changes | derived: `Migrator::targetVersion()` |

The schema version this codebase expects is not a constant to keep in sync by
hand. `Migrator::targetVersion()` computes it as the highest key in
`app/migrations.php`, or 1 (the baseline schema) if nothing has been added yet. Adding
a migration step raises the target automatically; there is no separate
number that could disagree with what the steps say, because there is nothing
left to disagree.

The database stores its own counterpart in `{prefix}meta.schema_version`. At
boot the two are compared. The two directions of a mismatch are handled
differently:

- **Database older than code**: the code expects columns that may not exist.
  Recoverable: the application keeps serving, and the dashboard shows a
  banner that applies the migration.
- **Code older than database**: an old release running on a database a newer
  one already upgraded. It would write rows the new schema gives different
  meaning to, corrupting data with no error at the time. Restore the newer
  code; do not downgrade the database.

## Writing a migration

Every migration is one entry in `app/migrations.php`, keyed by
the schema version it produces. The engine that reads it,
`app/includes/Migrator.php`, is a separate file on purpose: it rarely changes,
while `app/migrations.php` is expected to change with every release that touches the
database, and whoever adds a step should only ever need to open the one
file. `app/migrations.php` carries a compact quick-reference; the rules below are the
full version. A version jump (deploying several schema versions at once) is not a special
case: `pending()` lists every entry above the current version, and `step()`
applies them in sequence, one at a time.

Adding a step means two changes in the same commit: a new entry in
`app/migrations.php` (which is also what raises `Migrator::targetVersion()`,
automatically) and the same structure added to `app/setup/schema-sql.php` so a
fresh install arrives at the new version directly instead of installing the
old one and immediately demanding an upgrade.

`app/migrations.php` sits outside `public/`, the same way `config.php` does, so it
has no URL: it never contains secrets, but there is no reason for it to be
requestable either.

Steps run from inside the application, never as standalone scripts, and only
behind an elevated session: `app/actions/system/migrate.php` requires that
before calling anything in `Migrator`. That is what gives every step
`DB_PREFIX`, `AESBIN_FILES`, and the session's MASTER_KEY already resolved,
unconditionally: there is no per-step flag for "needs the key", because
there is no way to reach a step without one already being available. A step
that only alters structure (`ALTER TABLE`, a new index) simply doesn't
reference the parameter it's handed. One that rewrites `*_enc` columns or
file blobs uses it. Both are written the same way; only whether the body
touches the key differs.

`run()` has two valid forms, told apart by parameter count:

- **`function (PDO $db, string $masterKey): void`**: one call, nothing
  returned. Not throwing is "done". This is most steps: a single
  `ALTER TABLE` or similar has no reason to know chunking exists.
- **`function (PDO $db, string $masterKey, int $offset): array`**: the
  batched form, for a step touching enough rows that one request cannot be
  trusted to finish before `max_execution_time`. Returns
  `["done" => bool, "next" => int, "message" => string]`; Migrator calls it
  again with whatever `next` was, until `done` is true.

Rule 2 below applies only to the second shape. Reach for it when a step
needs it; most don't.

These properties are what make a step safe to ship:

1. **Idempotent.** Detect already-migrated rows and skip them. This matters
   more than any cleanup step: a migration that is safe to re-run does not
   depend on the file being deleted afterwards, on the run completing in one
   request, or on the operator not double-clicking.
2. **Batched.** Work in small chunks across repeated requests. A single
   request cannot be relied on to finish within `max_execution_time`, and
   shared hosting sets it low.
3. **Verify before writing.** Read and check every row before the first
   write; if any check fails, throw before anything is written.
4. **Write, verify, then swap.** For file blobs: write beside the original,
   read the new copy back, and only then replace. Commit the database row
   before renaming the file, so no state exists where the row describes a
   body encoded the other way.
5. **Bump `schema_version` last**, once everything else has succeeded.

An interrupted migration must leave a database that is either at the old
version or the new one, never in between.

## Things that must not change

Some values are part of the stored format rather than part of the code.
Changing them makes existing data permanently unreadable, because they are
authenticated and there is no way to decrypt with a different value:

- every AAD builder and constant in the "Associated data" section of
  `CryptoService` (`fileAad()`, `fileMetaAad()`, `rowAad()`,
  `shareLabelAad()`, `AAD_TOTP`, …). Call sites never write an AAD by hand,
  so this is the only place to look
- the derivation in `CryptoService::deriveShareKey()`

Each is marked in place. If one genuinely has to change, that is a migration
of the re-encryption kind, and a major version.

## Extra rows in the auth table

`{P}auth` is meant to hold exactly one row, `id = 1`: every read in the
codebase filters or looks up that id explicitly, nothing reads the table any
other way. `chk_single_user` enforces this at the schema level (MySQL
8.0.16+ and MariaDB; MySQL 5.7 parses CHECK clauses and ignores them), but a
`mysqldump`/phpMyAdmin export can drop that constraint on the way out (see
the Integrity checker note in `docs/features.md`), and a restore that merges rows
from more than one backup, or a manual edit made while it was missing, can
leave a second row behind. `app/actions/system/integrity_check.php` flags this
(`multi_user`) but does not offer a fix button: deleting the wrong row
destroys someone's only copy of their wrapped MASTER_KEY, and nothing in the
request tells the app which row that is.

Resolving it by hand, on the actual server:

1. Ask the owner which password (and, if they use it, which recovery
   phrase) currently works.
2. For each row, `password_verify($password, $row['password_hash'])`, a
   throwaway PHP script or `php -a` is enough. The row that verifies is the
   real one; if none of them do, try the recovery phrase against
   `recovery_hash` instead.
3. Back up the row about to be deleted before deleting it, in case the
   check above needs revisiting.
4. `DELETE FROM {P}auth WHERE id != 1` keeps id 1; if the surviving row is
   not already id 1, `UPDATE` its id after the delete rather than
   reinserting it; nothing else in the schema has a foreign key on
   `auth.id`, so this is safe on its own.
5. Re-run the integrity checker to confirm `multi_user` clears, then use
   `add_missing_check` (same screen) if `chk_single_user` itself is
   reported missing, which is what let the second row exist in the first
   place.

## Column sizes and encrypted data

Every `*_enc` column holds base64 of AES-GCM ciphertext, and GCM preserves
plaintext length. A `VARCHAR(n)` column therefore holds `n / 4 * 3` bytes of
plaintext: `VARCHAR(512)` holds 384 bytes, not 512 characters.

When adding a validated field, check the **byte** length against that ceiling,
not the character count: 120 characters of 4-byte UTF-8 are 480 bytes.
`is_valid_display_name()` in `app/includes/helpers.php` is the worked example.

`Database.php` forces `STRICT_TRANS_TABLES` so an overflow fails the INSERT
rather than truncating, but that is the second line of defence. A truncated
AEAD ciphertext never authenticates again: the data is gone, not corrupted.

## The activity log is one row per event, not one row per occurrence

The obvious shape for a log is one row per occurrence, folded at read time by
matching on event, ip, target and meta fields. That scheme needs a new special
case every time two similar occurrences must stay apart, and it leaves the
table open to flooding by anyone who can vary the thing being matched on:
hitting `share.token_probe` with a different token each time means no two rows
ever match, nothing folds, and an unauthenticated visitor decides how large
the log grows.

This model inverts it: one row per **event name**, created on its first
occurrence and deleted only when the owner empties the log. Each occurrence
appends an item to a capped, most-recent-first array in that row's
`meta.items`; target, ip, ua, referer, and every other per-occurrence detail
live on the item, not on the row, since which target or visitor an occurrence
involves varies per item rather than per row. `log_activity()` in
`helpers.php` is the only place that writes this shape; `activity.php`
decorates and returns whatever is currently in each row's `items`.

Two independent settings bound what a card holds: how many items show
without expanding anything (`activity_log_preview`), and how many are kept
at all, in storage and behind "show more" (`activity_log_item_cap`), both
in `SETTINGS_DEFAULTS`/`SETTINGS_ALLOWED` in `config_template.php`. Neither
bounds how many cards exist; that is bounded by how many distinct event
names the app ever logs, roughly the number of `log_activity()` call sites,
never something a visitor can inflate.

One time limit applies on top of the cap: the cards in
`ACTIVITY_UNAUTH_EVENTS` (404s and failed attempts before authentication,
the ones that record addresses that need not be the owner's) lose items
older than `ACTIVITY_UNAUTH_TTL_DAYS`, and the card itself once it is
empty. `activity_log_expire()` runs from `bootstrap.php`, at most once an
hour (the mtime of `.activity_expire` in the storage directory). The privacy
page quotes the same constant, so the two cannot disagree. A new event that
logs a stranger's address belongs in that list.

Items say what changed, not only that something did. `settings.update`
carries `changes`, built by `settings_log_changes()` from the fixed list
`SETTINGS_LOG_FIELDS` in `helpers.php`: old and new value, except for the
free-text fields (privacy identity, contact link), logged only as set,
cleared or changed, since the log is not encrypted. A new setting goes in
that list with its kind, plus a `log_setting_*` label in every language;
the captcha secrets must never be added. Moves carry the origin and the
destination, and a retarget the previous target, as names sealed like the
target's own (`activity_name_enc_as()`), which `activity.php` decrypts.

This is what makes an unauthenticated flood harmless, with no per-event
matching logic to get right: a scanner hammering
`share.token_probe` with a hundred different tokens still only ever touches
ONE row, rotating its own oldest items out as new ones arrive. Every other
card (logins, passkey use, TOTP reveals, …) is unaffected, because it is a
different row entirely.

Visitor events (`VISITOR_EVENTS` in `helpers.php`) never carry ip/ua on
their items: `log_activity()` enforces this itself, not each of the
half-dozen call sites that log one.

Events nobody performs (`ACTORLESS_EVENTS`, such as `share.expire`) carry no
ip/ua either: whoever finds the expiry, the owner's share list or a visitor
opening the dead link, is not its actor, and in the visitor case the item
would tie their address to the share. They are not visitor events: the card
stays on the owner's side, and the dashboard shows "no IP recorded" where an
address would be.

## Elevation gates are about what outlives the session, not the endpoint

`Auth::requireElevated()` exists to stop a hijacked-but-unelevated session
from doing something that changes what the instance *is* going forward,
weakening the rate limiter, disabling ALTCHA, adding a passkey the owner
never asked for, revealing the TOTP secret, wiping the activity log,
permanently destroying data with nothing left to restore, rather than
something that merely uses access the session already, necessarily, has.

An authenticated session can already unwrap the MASTER_KEY server-side:
it can read, download and decrypt every file in the instance. Gating
a file or share action behind a second password check adds a prompt without
adding confidentiality, because the session's *reach* is identical either
way. This is why creating, updating, or revoking a share, and moving a file,
directory, or container to the trash, shared or not, do not require
elevation, even though `FileService::trash()`, `directory/delete.php`, and
`container/delete.php` can all kill a share as a side effect of destroying
what it points to, exactly as `share/revoke.php` does directly. Gating one
of those paths and not the others would be inconsistent, but the fix is
symmetry in the *unelevated* direction, not the elevated one: neither path
hands a hijacked session anything it couldn't already reach by downloading
the file and re-sharing it however it likes outside AESBin, so neither
needs the gate. Destroying something for good is the other side of that
line and does require it: `file/delete.php`, `activity_purge.php`,
`integrity_fix.php` and `migrate.php` all call `requireElevated()`. See
`docs/security-model.md` § Elevation for the full reasoning, including what
elevation does and does not defend against.

The full list of what actually is gated: `$elevatedKeys` in `settings.php`
(rate limit, ALTCHA, session policy, the activity-log item cap,
upload/storage limits, privacy/contact info); the standalone checks in
`totp_secret.php`, `passkey_challenge.php` (registration mode),
`passkey_register.php`, `passkey_register_key.php` and `passkey_remove.php`
(TOTP/passkey credential management); `fix_403.php` and `storage_meta.php`
(they rewrite `public/.htaccess` and the recorded storage path); and
`activity_purge.php`, `integrity_fix.php`, `migrate.php` and
`file/delete.php` (irreversible: nothing is left to restore).
When adding a new settings field or action, the test is not "can this
affect a share/file" but "does this change a standing property of the
instance (a limit, a toggle, a credential, the schema) that keeps mattering
after this session ends, or destroy something with no path back?" If yes,
gate it; if it only takes away access the session could already reach
directly and the owner can recreate, it does not need to.

## The setup wizard's DB-reconnect step needs no recovery phrase, and should not ask for one

`app/setup/index.php`'s `verify_existing` action rebuilds `config.php` for a
database that already has an `auth` row. It is reachable only while
`config.php` is absent, and only past `setup_guard_passed()`: a code written
to a file on the server and never sent in any HTTP response, so reaching the
step already proves filesystem access.

It does not ask for the recovery phrase because the phrase guards something
else. `Argon2id(recovery_phrase, recovery_kek_salt)` unwraps
`recovery_wrapped_master` so a lost password can be replaced
(`recovery_verify.php` / `recovery_execute.php`, the only places the phrase
is checked). Rebuilding `config.php` touches no key: it copies connection
details into a file, which anyone with that filesystem access could write by
hand from `config-sample.php`. A phrase prompt there would be friction for
the owner and no obstacle to anyone else.

Nothing here weakens what protects a stolen database. Files decrypt only
through `wrapped_master`, whose KEY-encryption key derives from the true
password and is stored nowhere. Overwriting `password_hash` in the `auth`
row lets a chosen password pass `password_verify()`, but the unwrap that
follows fails and the login ends in `key_mismatch`. See
`docs/security-model.md` § Threat model, "Database stolen".

## One file owns every setting's default value

`SETTINGS_DEFAULTS` and `SETTINGS_ALLOWED` in `config_template.php` are the
single source for every setting that has a factory default and/or a fixed
set of valid values (rate limiting, session timeout, activity-log
preview and item cap, upload/quota limits). `setting_int()` in
the same file is the one function that reads a value out of a config array,
falls back to the default when it is missing or not actually an int
(`is_int()`, checked before anything else: a `(int)` cast first would
silently coerce `"1800foo"` into `1800`), and snaps back to the default
again if it has an ALLOWED entry and the value isn't in it.

`render_setting_options()`, same file, builds every `<option>` for a
select-driven setting straight from `SETTINGS_ALLOWED`/`SETTINGS_DEFAULTS`
too: `dashboard.php`'s dropdowns call it instead of listing their own
`<option>` tags. Add a value to `SETTINGS_ALLOWED` and it becomes
selectable and valid at the same time; there is nothing else to update.
The two free-range fields with no ALLOWED entry (upload/quota, entered in
MB/GB) still use a plain `<input value="...">`, but even that value is
`<?= SETTINGS_DEFAULTS[...] ?>`, not a written-out number.

Only one copy is kept on purpose, not out of neglect, and it says so where
it lives: `config-sample.php`, a hand-maintained example file for anyone
writing `config.php` directly, the same relationship WordPress's own
`wp-config-sample.php` has to its defaults. Nothing at runtime reads it.

Not every key is a setting. `force_https`, `recovery`, `server_rules_configured`
and `markdown_tools` live in `config.php` only: they are read at boot, never
written by the settings action, and never offered in the dashboard. Three of
them are kept out of reach of a stolen session, and the fourth names
third-party sites the package will not vouch for. They still have to appear in
`render_config_php()` so a save from the dashboard preserves them, and in
`config-sample.php` so a hand-written file has them; `bin/lint.sh` fails when
the two disagree.

Before adding a new setting with a default, add it to `SETTINGS_DEFAULTS`
(and `SETTINGS_ALLOWED` if it is dropdown-only) rather than writing the
number inline anywhere else: `render_config_php()`, `settings.php`'s GET
and POST, `dashboard.php`'s own HTML, and `dashboard.js`'s
`_settingsDefaults` (populated from GET's own `"defaults"` key) all read
from there already.

## A saved locale is read from the session once, then from config.php

`config.php` is included by every request, so OPcache holds it with a
revalidation deadline. When the settings action rewrites the file, a request
arriving before that deadline is served the previous compilation, and the
reload the dashboard fires 600 ms after the save lands inside the default
two-second window nearly every time. The symptom is a page that comes back in
the old language while the file on disk already holds the new one.

`opcache_invalidate()` is the normal answer and `write_config()` calls it, but
shared hosts disable the whole OPcache API while leaving automatic
revalidation on. So `settings.php` also leaves the new locale in
`$_SESSION["locale_hint"]`, and `bootstrap.php` prefers it over `config.php`
until the two agree, and then deletes the hint.

Note *until the two agree*, not *for one request*. Consuming it on the first
request would race: the dashboard's deferred boot checks (sessions,
integrity, updates) can land between the save and the reload, take the hint
and leave the reload showing the old language. Removing it only
once `config.php` reports the new locale leaves nothing to race, and where
`opcache_invalidate()` works the file is current on the next request anyway, so
the hint lives just as briefly.

Two things follow. The dashboard language select is marked from
`Lang::current()`, not `$config["locale"]`, or on that one request the dropdown
would disagree with the page around it. And the hint is not a
general cache for settings: every other setting is applied without a reload, so
a two-second delay is not observable, and a second source of truth that never
expires is the kind of thing that drifts.

The structural fix is moving the locale into the `{prefix}meta` table, which
already exists. It is not done because `Lang::load()` runs before
`Database::connect()` in `bootstrap.php`, and reordering those two means the
database error path renders untranslated. A candidate for after 1.0, not for a
patch.

## Translated strings take named placeholders

A value inside a translated string is written `{name}` (lower case, letters
and underscores), in every language file: `"update_available_msg" => "AESBin
{latest} is available (you have {current})."`. The server fills it with
`__("key", ["name" => $value])` (`Lang::fill()` in `app/includes/Lang.php`), the
browser with `fmt(t('key'), { name: value })` (`helper.js`). Both work the same
way: one pass, a value is never scanned again, and a placeholder left without
a value stays visible instead of vanishing.

Named rather than positional (`%s`) so a translation can put the values in the
order its grammar wants, and so a literal `%` in a translation is just a
character. `sprintf()` is not used on translated strings. `bin/lint.sh` 5d
fails when a language lacks or adds a placeholder, when a `%s`-style token
appears in a language file, or when a literal `__()`/`fmt()` call passes
different names from the English string. A key built at runtime
(`t('integrity_confirm_' + action)`) is outside that last check, so every key
of such a family must use the same names.

## Duplicated logic

The defect to watch for is the same rule written in two places and then
changed in one. The pairs that have to stay in step are
`container/delete.php` against `directory/delete.php` (their common steps
live in `trash_files_in()`, `revoke_directory_shares()` and
`log_deleted_directories()`), `test_db` against
`verify_existing` against `install` in the setup. The browser-side helpers have
a single home: escaping (`esc()`, `fmtDesc()`), placeholder filling
(`fmt()`), application URLs (`appUrl()`), JSON requests (`fetchJson()`),
MIME classification, date and size
formatting, the checksum block and the recovery-phrase download live in
`public/assets/js/helper.js`, which every page that builds markup (installer
included) loads before its own scripts. The page footer has one template,
`app/views/partials/page-footer.php`. On the server the same goes for share
deletion (`share_delete()`, `destroy_file_shares()`), blob removal
(`destroy_blob()`), request ids (`body_id()`), rate-limit answers (`SecurityService::enforceRateLimit()`),
name locks (`name_scope_lock()`), the `.htaccess` server test
(`Requirements::serverReadsHtaccess()`) and the recovery gate
(`recovery_require_gate()`). The settings defaults are the clearest case,
which is why one file owns every default (see above).

Before fixing anything, check whether a sibling has the same code. Where a
rule has a single home (`is_valid_display_name()`, the AAD builders in
`CryptoService`, `MimeRegistry`, `helper.js`), keep it that way rather than
inlining a copy.

## Stylesheets

Every page loads `base.css` first, then its own file. The two pages that
preview files (dashboard, share viewer) also load `preview.css` in between.

| page | files |
|---|---|
| login, recovery | `base.css` + `auth.css` |
| dashboard | `base.css` + `preview.css` + `dashboard.css` |
| share viewer | `base.css` + `preview.css` + `share.css` |
| privacy policy | `base.css` + `privacy.css` |
| 404, 403 | `base.css` + `error.css` |
| installer | `base.css` + `setup.css` |

**Where does a rule go?** If the change should be visible on more than one
page, it goes in `base.css`. If it belongs to one page, it goes in that page's
file. There is no third option and no import graph to trace.

`base.css` holds the design tokens (`:root`), the primitives (buttons, alerts,
fields, password input), the page shell (`.page-wrap` and the no-JS machinery),
the `.card` panel, and the header/footer chrome used by the standalone public
pages. Some pages use none of the last two groups. That is intended: an unused
rule costs a few bytes, whereas a duplicated one costs a silent visual drift
the day somebody edits one copy and misses the others.

Keeping the palette in several files lets them diverge silently: a file can
lose a token and nothing looks broken until a rule needs it.

**Inside a file**, rules are grouped by component under a `/* ── Name ── */`
heading, in the order the markup nests them. A phone variant
(`max-width: 680px`) or a desktop one (`min-width: 681px`) sits right after
the rule it adapts, not in a block of its own at the end of the file: what a
component looks like at every width reads in one place. A declaration that
only repeats what the element already gets (the reset's zero margins, an
inherited colour, a value another class on the same element sets) is left
out.

**Naming.** A class prefix names what the rule *is*, not the page it first
appeared on. `.card` is not `.auth-card` because the share password prompt and
the 404 use it too; `.page-footer` is not `.sv-footer` because privacy shows
the same footer as the share viewer. If you move a rule into `base.css` and
its name still refers to one page, rename it in the same commit; otherwise
the next reader trusts the name over the file.

**Cache.** `public/.htaccess` caches static files for a year with
`immutable`, which is only safe because every URL the application prints for
one carries `?v=<modification time>`: `asset()` in PHP, or
`asset_version()` where the base URL is not `AESBIN_BASE` (the installer, the
flags in `lang_select()`), and the URLs `document-end.php` hands to
JavaScript (`AESBIN_PREVIEW_ASSETS`). A changed file gets a new
URL, so browsers fetch it at once. A file requested by a URL the application
does not print (the favicon, the manifest icons, pdf.js's `nowasm/` decoders)
is listed as an exception in the same block with a short cache. Adding a
static file means one or the other; `bin/lint.sh` (check 3m) fails on a
`/vendor/` or `/assets/` URL built by hand.

**Prism.** `public/assets/css/prism-aesbin.css` is the theme plus every
AESBin addition (line-numbers geometry, palette tie-in), all AESBin's own:
not vendor code. `public/vendor/prism/` holds only `prism.min.js`, assembled by
`bin/fetch-vendor.sh` from upstream components and never hand-edited. See
[Vendored libraries § Prism](vendored-libraries.md#prism).

## Before a release

Run these from the project root. None of them need network access, except
`[6/6]` on a terminal: the PHPStan and Stylelint downloads it offers, and a
daily look at their latest versions (below); `php` is the only hard
requirement.

```sh
./bin/lint.sh
```

It runs `php -l` over every non-vendor PHP file, `node --check` over the
non-vendor JavaScript if Node is present, and a set of project-specific
consistency greps described below. Vendored code is excluded on purpose: it
is taken from upstream builds as-is (see [Vendored libraries](vendored-libraries.md)) and is not held
to this project's style.

`[6/6]` needs the PHPStan phar in `bin/tools/phpstan.phar` (~27 MB, git-ignored).
When it is missing the section offers to fetch it, and skips if you decline or
if the shell is not interactive; nothing else depends on it. Configuration is
`bin/tools/phpstan.neon` (level 5), shared with the CI job, plus
`bin/tools/phpstan-constants.php` for the constants `bootstrap.php` builds at runtime.
Two exceptions are set there on purpose, each explained in the file: the
variables that `app/actions/*` receive from the router, ignored by exact name, and
those constants treated as unknown strings. Any new error is a real one: fix
it rather than widening either exception.

**Versions: latest here, pinned in CI.** On a terminal, `[6/6]` asks GitHub
(PHPStan) and npm (Stylelint) for their latest release and offers to download
it when the copy in `bin/tools/` is older, so a new check shows up here
first. The CI jobs install a pinned release instead (`tools: phpstan:X` and
`stylelint@X` in `.github/workflows/lint.yml`), so the public badge changes
only when the code does. When the latest release passes and is newer than
the pin, the run offers to raise the pin in `lint.yml` (or prints the line
to change); commit that file with your next change. When it fails, it says
whether CI would see the failure too.

The GitHub Actions in `lint.yml` (checkout, setup-php) are pinned too, to a
commit with the release named beside it. Those are not the script's job:
`.github/dependabot.yml` has GitHub look for new releases once a week and
open one pull request that moves every pin and its comment. Merge it once
CI is green.

The lookup is the only network request the script makes without asking. It
runs at most once a day and its answer is kept in `bin/tools/.latest-versions`
(git-ignored); a lookup that gets no answer (offline, a proxy) is retried
after an hour, so an offline machine waits for the timeouts, a few
seconds, once rather than on every run. `AESBIN_LINT_OFFLINE=1 ./bin/lint.sh`
skips it; under CI or in a pipe it never runs. Without it the installed
copies are used as they are.

The same section runs Stylelint over the stylesheets, with the rules in
`bin/tools/stylelint.json` (shared with the CI job): no duplicate selectors
or properties, no shorthand overridden or spelled out in longhands, no unit
on a zero, no unknown property, unit or pseudo-class, at most three compound
selectors in a row. It needs Node.js 20.19 or later and npm; when Stylelint
is missing the section offers to install it with npm into
`bin/tools/stylelint/` (git-ignored), and skips otherwise. On Android,
Termux's `pkg install nodejs` is enough.

`[3/6]` includes a cross-check between `app/includes/Vendor.php` and the two
documents that list library versions. `fetch-vendor.sh` prints the same
reminder, but a reminder can be read and forgotten; this one fails the build.

Four checks the script cannot do for you, all of which have to happen on a
real host:

- **A full install from scratch through the guard code.** The installer is the
  only pre-authentication surface in the project, and the guard-code flow
  (`setup_guard_passed()`) cannot be exercised meaningfully anywhere else.
- **The installer only while `config.php` is missing.** Before: any address
  shows it (after the redirect to the base URL). After: the same addresses
  reach the application, and `/setup/index.php` is a 404 like any other
  unknown URL.
- **Both layouts.** `public/` as the document root, and the whole project in
  the web root with the top `.htaccess` mapping requests into `public/`, each
  at a domain root and in a subdirectory: the pages, the installer, a share
  link and the 403 page, and `config.php`, `app/includes/` and the storage
  directory refused.
- **`trusted_proxies` on a host that does NOT rewrite `REMOTE_ADDR`.** The
  installer seeds that list only when `X-Forwarded-For` carries a client
  address different from `REMOTE_ADDR`, positive evidence that PHP is not
  seeing the visitor. Most shared hosts (LiteSpeed, `mod_remoteip`, Cloudflare
  configured at the origin) hand PHP the real address, so on those the correct
  outcome is an empty list and the seeding branch never runs. Reproducing the
  other case takes a proxy in front of PHP (nginx with
  `proxy_set_header X-Forwarded-For $remote_addr;` and `X-Forwarded-Proto
  https` is enough), where the generated `config.php` should name the proxy.
  Getting this wrong in the other direction is what matters: an entry naming
  the *visitor* would let that address forge `X-Forwarded-For` and walk past
  the rate limiter and `session_ip_lock`.

### Consistency greps

These encode invariants that are easy to reintroduce. `bin/lint.sh` runs them,
but knowing them helps when writing code.

**Request body fields must be type-checked before any cast.** `(int)` on an
array is `1`, `(string)` on an array is the literal `"Array"`; neither is
fatal, so both reach the sink silently.

```sh
grep -rn '(\(int\|string\|bool\)) (\?\$body\[\|trim(\$body\[' app/actions/
```

Every hit must be preceded, in the same file, by either `require_fields()` on
that key (mandatory fields) or `normalize_body_field()` (optional ones). The
pattern covers both `(string) $body[…]` and `(string) ($body[…] ?? …)`.

The installer has its own equivalent, `setup_body_scalars()`. Every handler
that reads a body gets it from `json_body()` (always an array) and either
passes it to `setup_db_fields()`, which checks the six connection fields, or
calls `setup_body_scalars()` on the keys it reads; `totp_init` and
`gen_phrase` read no body. The grep above does not reach `app/setup/index.php`,
so a new handler that does neither is on the reviewer to notice.

**Deleting a file row does not cascade to its shares.** `share_file_keys`
cascades away with the row via its foreign key, but `{P}shares` does not:
`target_id` is polymorphic (`file|directory`), so it carries no FK. Any code
path that deletes a `files` row, or a directory or container, has to revoke
the matching shares by hand *and* write a `share.revoke` activity row:
`docs/features.md` promises the loss is never silent. The existing implementations to
copy are `FileService::trash()`, `FileService::deletePermanently()`,
`app/actions/directory/delete.php` and `app/actions/container/delete.php`.

**Share salts are decoded by `ShareService::salt()` and nowhere else.**
`base64_decode(..., true)` returns `false`, not an empty string, and under
`strict_types` that reaches `deriveShareKey(string $salt)` as a `TypeError`:
an `Error`, not a `RuntimeException`, so a `catch (RuntimeException)` around
the loop would not hold it. `salt()` throws a `RuntimeException` instead.

```sh
grep -rn 'base64_decode([^;]*_salt' app/includes/ app/actions/
```

The only hit allowed is the passkey `prf_salt`, which is not a share salt.

**Associated data is written only in `CryptoService`.** Every AAD comes
from the builders and constants in its "Associated data" section. A string
typed at a call site can drift from the one the data was sealed with, and
nothing says so until a decrypt fails.

`bin/lint.sh` (check 3l) fails when a string shaped like an AAD appears
anywhere else in `app/includes/`, `app/actions/` or the installer.

**JavaScript names a language key only inside `t('…')`.**
`Lang::forScripts()` ships each page the keys it finds as literal `t('key')`
calls, plus every key under a `t('prefix' + …)` or `// @lang-prefix` prefix.
A key written anywhere else (in a lookup table, or passed as an argument to a
helper that calls `t()`) is never shipped, and the page shows the raw key.
Resolve it where it is named: `{ hint: t('some_hint') }`, not
`{ hint: 'some_hint' }`. `bin/lint.sh` (check 3e2) fails on a quoted key that
no script ships.

**One value, two languages.** A few constants exist in PHP and in the
browser, and `bin/lint.sh` compares the pair: the minimum password length
(`CryptoService::PASSWORD_MIN_LENGTH`, `helper.js`). The colour tokens live
only in `public/assets/css/base.css`, which the installer loads too; the lint
fails if another stylesheet redeclares one.

**`:last-child` in CSS breaks when anything is appended to a container.**
Adding an action button to a card silently disables a
`.row:last-child { border-bottom: none }` rule. Prefer `:last-of-type` where
the last element of a given type is what you actually mean.

---

[← AESBin docs](README.md) · [Project home](../README.md)
