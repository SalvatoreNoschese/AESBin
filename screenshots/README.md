# Screenshots

These images are referenced by the root [`README.md`](../README.md) (01–08) and by
[`docs/installation.md`](../docs/installation.md) (10–13). Keep the
filenames below — renaming one silently breaks the README on GitHub.

| File | What to capture |
|---|---|
| `01-dashboard.png` | The dashboard with a few containers/directories and files. Use realistic but harmless names. |
| `02-preview.png` | A file card with its preview open on something visual — an image or a syntax-highlighted file. |
| `03-share-viewer.png` | A public share page as a visitor sees it, ideally a directory share with several files. |
| `04-settings.png` | The settings panel, on a section that shows the app has real depth (security or activity log). |
| `05-mobile-dashboard.png` | The dashboard on a phone, same demo data as `01`. |
| `06-mobile-preview.png` | A preview open on a phone. |
| `07-mobile-share.png` | A public share page on a phone. |
| `08-mobile-settings.png` | The settings panel on a phone. |
| `10-installer-guard.png` | The installer asking for the guard code (used in `docs/installation.md`). Capture it before typing a code, or use one already spent. |
| `11-installer-requirements.png` | The requirements screen, all green (`docs/installation.md`). |
| `12-installer-database.png` | Wizard step 1. Use a throwaway database; no real host, user or password in view (`docs/installation.md`). |
| `13-installer-storage.png` | Wizard step 2, with the suggested storage folder (`docs/installation.md`). |

The README and the docs link these by absolute URL
(`github.com/.../raw/main/screenshots/`), so they also show on sites that render
them outside GitHub. They only resolve once the repository is public, and
`bin/lint.sh` (check 5c) fails if a linked name has no file here.

**Before capturing:**

- Use a throwaway instance or demo data. Filenames and descriptions are the one
  thing screenshots leak, and they are the thing this project encrypts.
- Blur or replace the hostname if you do not want your instance discovered.
- Not worth capturing, and never to publish: the installer's password/TOTP step and
  the recovery phrase step.
- Never capture the TOTP QR code, the recovery phrase screen, a share URL
  including its `#key` fragment, or the installer guard code.
- Capture at 2× or 3× pixel density, so they stay sharp on high-DPI screens;
  GitHub scales them down to the width the README gives them.
- Desktop (`01`–`04`): a 1440 × 900 viewport at 2×, so 2880 × 1800, PNG.
- Mobile (`05`–`08`): a 390 × 844 viewport at 3×, so 1170 × 2532, PNG, with
  no status bar or navigation bar.
- Installer (`10`–`13`): the setup card alone, cropped with a small margin,
  from a 1200 px wide viewport at 2× (1248 px wide, height as the card).
- Crop the browser chrome out. Keep each file under ~500 KB with lossless
  compression (`oxipng -o4`); `pngquant` bands the gradients of an image
  preview, so use it only if a file will not fit otherwise.
