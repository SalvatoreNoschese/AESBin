# Contributing to AESBin

Thanks for taking the time. AESBin is maintained by one person, so the most
useful contributions are the ones that are easy to verify.

## Before you open an issue

- Search existing issues first.
- For anything security-relevant, **do not open a public issue**; follow
  [SECURITY.md](SECURITY.md) instead.
- Include your PHP version, database version, web server, and whether you are on
  shared hosting. Most reports come down to one of those four.

## Development setup

There is no build step and no package manager. Clone the repository, point a
PHP 8.3+ virtual host at its `public/` folder over HTTPS (or at the clone
itself, with `AllowOverride All`), create an empty MySQL database and open
the site: with no `config.php` yet, it shows the installer. That is the
whole setup.

Do not add a Composer or npm dependency without discussing it in an issue first.
Bundling libraries by hand is a choice, not an oversight; the
reasoning is in [docs/vendored-libraries.md](docs/vendored-libraries.md).

## Before you open a pull request

Run the checks:

```sh
./bin/lint.sh
```

It runs `php -l` over every non-vendored PHP file, a JavaScript syntax check if
Node is available, the consistency and release-hygiene checks described in
[docs/maintaining.md](docs/maintaining.md), and PHPStan. CI runs the same script, so a clean
run locally means a clean run there.

Then:

- Keep the diff focused on one thing.
- Match the surrounding style: 4 spaces (see `.editorconfig`). Every PHP
  file opens with the PSR-12 header: `<?php`, the file's doc comment,
  `declare(strict_types=1);`, the `namespace` and `use` lines, one blank line
  after each block. Under `app/` (vendor libraries aside) and in
  `config-sample.php` the first statement after it is
  `defined("AESBIN_APP") || exit;`. The classes in `app/includes/` are in the
  `AESBin` namespace, with their doc comment above the class; whoever uses
  one imports it (`use AESBin\Lang;`). An `index.php` silence stub in every
  folder under `app/` and `public/`, and in every folder AESBin creates at
  run time (storage, `_trash`, its shards, the guard folders), which
  `make_dir()` in `helpers.php` writes. `bin/lint.sh` checks the shipped ones.
- Comments explain *why*, not *what*. If a decision looks wrong at first glance,
  the comment saying why it isn't is the valuable part.
- Any user-facing string goes through `__()` and must be added to all five
  language files under `app/lang/`.
- Touching the database schema means a migration: read
  [docs/maintaining.md](docs/maintaining.md) § Writing a migration first.
- Touching upload handling (`MimeRegistry`, the filename rules,
  `FileService::upload()`) means running the
  [upload test pack](tests/upload/README.md) and, if the behaviour changed on
  purpose, updating its expected results.
- Update `CHANGELOG.md` under an `## [Unreleased]` heading, above the latest
  version (add the heading if it is not there: it exists only while there are
  changes waiting for a release).

## Things that will be declined

- Multi-user support. AESBin is single-user by design; the auth table having one
  row is an invariant the whole codebase relies on.
- Loading any asset from a CDN.
- Anything that weakens the CSP, adds an inline script or style without a nonce, or introduces
  a third-party runtime request.
- Client-side encryption bolted onto the current server-side model. It is a
  legitimate design, but it is a different application, not a patch.

## Translations

New languages are welcome. Copy `app/lang/en.php`, translate the values (never the
keys), and mention in the PR which locale you added. Keys must stay in the same
order as `en.php` so the files can be diffed against each other.
Words in braces, such as `{name}` or `{count}`, are filled in by the code: keep
each one exactly as written, and move it wherever your language's word order
needs it. `./bin/lint.sh` checks that every language has the same ones.
The language selectors show `public/assets/img/flags/<code>.svg` when it exists;
add one if the language has a flag that fits.

## Cutting a release

1. Update `CHANGELOG.md`: rename `## [Unreleased]` to `## [x.y.z] - <date>`
   and add the version's link at the bottom.
2. Update `AESBIN_VERSION` in `app/includes/bootstrap.php` to the same number.
3. Run `./bin/lint.sh` (among the rest, it checks that the *Source code*
   archive will hold the site only), and test an install from the package
   `./bin/make-release.sh` builds in `bin/dist/`: the same files GitHub's
   *Source code* zip will hold.
4. On github.com: **Releases → Draft a new release**, create the tag `vx.y.z`,
   write the notes, and **Publish release**. There is nothing to attach:
   GitHub's own *Source code* zip of the tag is the package, and
   `.gitattributes` (`export-ignore`) keeps the documentation, `bin/`, CI and
   screenshots out of it. The dashboard's update check links to that archive.
   In the notes, say which download is for what: the *Source code* zip to
   install AESBin, a clone of the repository to work on it. The two differ in
   tidiness, not in safety: none of the files left out has a URL either way.

Steps 1 and 2 have to agree: `update_check.php` compares the tag against
`AESBIN_VERSION`, so a mismatch tells every existing install it is out of date
the moment they upgrade.
