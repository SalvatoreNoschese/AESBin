## What this changes

<!-- One or two sentences. Link the issue if there is one. -->

## Why

<!-- The reasoning. If the change looks wrong at first glance, explain here. -->

## Checklist

- [ ] `./bin/lint.sh` passes
- [ ] New user-facing strings go through `__()` and exist in all five `app/lang/` files
- [ ] Schema changes ship with a migration (see `docs/maintaining.md`)
- [ ] `CHANGELOG.md` updated under `## [Unreleased]`
- [ ] No new CDN, Composer or npm dependency
- [ ] Tested on PHP <!-- version --> against <!-- MySQL/MariaDB version -->
