[← AESBin docs](README.md) · [Project home](../README.md)

# Architecture

Directories whose contents are uniform are shown as directories: `app/actions/`
holds one endpoint file per operation, and listing them would be a list of
verbs rather than a map (the routes are listed in `app/includes/router.php`).
Everything whose name you would need to know before opening it (the service
classes, the views, the installer's parts) is named individually.

Only `public/` is served: the document root, or what the top `.htaccess` maps
every request into when the whole project sits in a web root that cannot be
changed. Everything else, `config.php` and the default storage directory
included, has no URL.

```text
AESBin/
├── app/                           # The code that is not served: no URL of its own
│   ├── actions/                   # Application endpoints
│   │   ├── auth/                  # Authentication, passkeys, TOTP and recovery
│   │   ├── container/             # Container management
│   │   ├── directory/             # Directory management
│   │   ├── file/                  # File management
│   │   ├── share/                 # Share creation, access and management
│   │   └── system/                # Administration, sessions, settings and maintenance
│   │
│   ├── includes/                  # Core application services
│   │   ├── AltchaAutoload.php     # ALTCHA library autoloader
│   │   ├── Auth.php               # Authentication and authorization
│   │   ├── CryptoService.php      # Encryption, key wrapping and decryption
│   │   ├── Csrf.php               # CSRF protection
│   │   ├── Database.php           # Database access
│   │   ├── FileService.php        # File and storage operations
│   │   ├── GuardFile.php          # Guard-file mechanism (installer and recovery)
│   │   ├── Lang.php               # Localization
│   │   ├── Migrator.php           # Database schema migrations
│   │   ├── MimeRegistry.php       # MIME type handling
│   │   ├── RecoveryGate.php       # Recovery gate and its guard file
│   │   ├── Requirements.php       # Server requirements, shared with the installer
│   │   ├── SecurityService.php    # Security-related checks and helpers
│   │   ├── SessionRegistry.php    # Session tracking and management
│   │   ├── ShareService.php       # Share-link operations
│   │   ├── Vendor.php             # Vendored library names, versions and licences
│   │   ├── bootstrap.php          # Application bootstrap
│   │   ├── config_template.php    # config.php renderer and setting defaults
│   │   ├── helpers.php            # Shared application helpers
│   │   ├── router.php             # Request routing
│   │   └── wordlist.php           # Recovery phrase word list
│   │
│   ├── lang/                      # Interface translations
│   │   ├── en.php
│   │   ├── it.php
│   │   ├── de.php
│   │   ├── es.php
│   │   └── fr.php
│   │
│   ├── setup/                     # Installation and maintenance, out of the
│   │   │                          # web root: no URL of its own
│   │   ├── index.php              # Installation and configuration wizard
│   │   ├── schema-sql.php         # Database schema
│   │   └── template.php           # Installer markup
│   │
│   ├── vendor/                    # PHP libraries, one folder each (see
│   │   │                          # docs/vendored-libraries.md)
│   │   ├── altcha-lib-php/        # ALTCHA PHP library
│   │   └── passkeys-php/          # WebAuthn / passkey library
│   │
│   ├── views/                     # User-facing PHP views (templates only)
│   │   ├── partials/
│   │   │   ├── document-start.php # Doctype, <head>, <body>, .page-wrap
│   │   │   ├── document-end.php   # window.* vars, scripts, </body></html>
│   │   │   ├── desc-toolbar.php   # Formatting toolbar and counter of a description field
│   │   │   └── page-footer.php    # Visible footer: ©, privacy, contact
│   │   ├── dashboard.php
│   │   ├── error.php              # 404 (no route) and 403 (refused by .htaccess)
│   │   ├── login.php
│   │   ├── privacy.php
│   │   ├── recovery.php
│   │   └── share.php
│   │
│   └── migrations.php             # Database migration steps, read by Migrator
│
├── public/                        # The web root: the only folder with URLs
│   ├── assets/
│   │   ├── css/                   # Stylesheets (application and installer)
│   │   ├── img/                   # Images, flags, icon sprite (icons.svg)
│   │   ├── js/                    # JavaScript (application and installer)
│   │   └── meta/                  # Icons and web app metadata
│   ├── vendor/                    # Libraries the browser loads, one folder each
│   │   ├── altcha/                # ALTCHA widget
│   │   ├── kjua/                  # QR code generation
│   │   ├── pdfjs/                 # PDF rendering
│   │   └── prism/                 # Syntax highlighting
│   ├── index.php                  # Entry point, the only PHP code in public/
│   │                              # (the other index.php are listing stubs);
│   │                              # runs app/setup/ while config.php is missing
│   ├── .htaccess                  # Apache/LiteSpeed rules: deny lists, front
│   │                              # controller, security headers, 403 page
│   ├── help.html                  # Documentation entry point for a downloaded
│   │                              # package; the footer of every page links here
│   ├── robots.txt, llms.txt       # Crawling allowed, indexing refused by each
│   │                              # response's noindex; summary for LLM readers
│   └── favicon.ico, apple-touch-icon.png
│
├── config-sample.php              # Configuration reference/template
├── .htaccess                      # Maps every request into public/, for a web
│                                  # root that cannot be pointed at public/
│
├── bin/                           # Dev scripts, not shipped in a release
│   ├── fetch-vendor.sh            # Downloads and stages the vendored libraries
│   ├── lint.sh                    # Pre-release checks; run before tagging
│   ├── make-release.sh            # The release zip of the sources, to test an install
│   └── tools/                     # Helpers lint.sh calls, not run by hand
│       ├── config-check.php       # A local config.php against what a save would write
│       ├── csp-hashes.php         # CSP hashes of help.html in public/.htaccess
│       ├── file-header.php        # The PSR-12 header of every PHP file
│       ├── filename-props.php     # Random names against every filename rule
│       ├── phpstan.neon           # Static analysis config (level 5)
│       ├── phpstan-constants.php  # Runtime constants declared for PHPStan only
│       ├── phpstan.phar           # Downloaded by lint.sh, latest release, git-ignored
│       ├── regex-anchors.php      # Whole-string regexes end with \z, not $
│       ├── smoke.js               # Loads the page scripts in a fake DOM
│       ├── stylelint.json         # Stylelint rules, shared with the CI job
│       ├── stylelint/             # Installed by lint.sh with npm, git-ignored
│       └── .latest-versions       # lint.sh's daily look at the latest PHPStan
│                                  # and Stylelint, git-ignored
├── docs/                          # Full documentation (this file lives here)
├── screenshots/                   # Images used by README.md
├── tests/upload/                  # Upload test pack (a zip) and how to use it
├── .github/                       # Issue/PR templates and CI workflows
│   ├── workflows/lint.yml         # CI: php -l, PHPStan, Stylelint, pinned versions
│   ├── dependabot.yml             # Weekly pull request updating the pinned actions
│   └── FUNDING.yml                # The Sponsor button: a link to the donation page
│
├── CHANGELOG.md                   # Release history
├── CONTRIBUTING.md                # How to report, patch and build
├── LICENSE                        # AGPL-3.0 license
├── README.md                      # Short project overview
└── SECURITY.md                    # Vulnerability reporting policy
```

## Request flow

Every request that is not a static asset reaches `public/index.php`, which loads
`app/includes/bootstrap.php`: `config.php`, the HTTPS check, the session (owner
routes only), the database connection and the services. It then hands over to
`app/includes/router.php`, which maps `METHOD /api/<area>/<action>` to
`app/actions/<area>/<action>.php` (the tables `API_ROUTES` and
`API_PATTERN_ROUTES`), and page routes (`/`, `/login`, `/recovery`,
`/privacy`, `/s/<token>`) to `app/views/*.php`. `/.well-known/security.txt`
has no view: the router prints it from `security_txt_body()` in
`helpers.php`, and redirects `/security.txt` to it. Action files run in the
router's scope, with the services from `bootstrap.php` already built; each one
states its route, guard, body and response in its header.

## Storage and configuration

The installer lives under `app/setup/`, out of the web root: `public/index.php`
runs it instead of the application for as long as `config.php` is missing. It
creates `config.php` from the
installation settings and initializes the database schema. The actual
configuration file is intentionally **not included in the repository**; use
`config-sample.php` as the reference for available options.

User-uploaded encrypted blobs are stored in the configured storage directory.
The installer suggests a randomly named folder next to `public/`, under the
project root: out of the web root with either layout. A folder inside
`public/` would be reachable, so the installer refuses it. The chosen path
is written to `config.php` and to the `storage_path` row of `{prefix}meta`,
so that reconnecting a database to a fresh `config.php` can start from the
right folder. The dashboard offers to update the row when it no longer matches
`config.php`.

## Third-party dependencies

Third-party libraries are bundled rather than loaded through Composer or a
CDN, one folder per library: under `public/vendor/` the ones the browser
loads, under `app/vendor/` the PHP ones.
[Vendored Libraries](vendored-libraries.md) has the list, versions, licences,
what was taken from each one and why, and the update procedure.

---

[← AESBin docs](README.md) · [Project home](../README.md)
