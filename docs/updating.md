[← AESBin docs](README.md) · [Project home](../README.md)

# Updating

When the dashboard says a new release is out, updating means replacing the
code and keeping your data. Your data is two things, and neither ships in a
release:

- **`config.php`**, at the top of the project: the database credentials, the
  storage path and every setting you made in the dashboard. Without it the
  installer comes back, and reconnecting rebuilds it with default settings.
- **The storage folder**, the one `storage_path` in `config.php` names. By
  default it sits at the top of the project too (`_storage-…`), next to `app/`:
  deleting the whole project folder to upload a fresh one deletes your files.

## Steps

1. **Back up** the database (a dump from your hosting panel or `mysqldump`),
   `config.php` and the storage folder. The files in it are ciphertext, useless
   without the database, and the database is useless without them: keep the
   three together.
2. **Download** the new release (the *Source code* zip under Assets) and read
   its notes, on the release page or in `CHANGELOG.md` in the repository
   (the zip holds the site only).
3. **Replace `app/` and `public/`** with the ones from the release, and the
   other files at the top (`.htaccess`, `LICENSE`, `config-sample.php`).
   Delete the old `app/` and `public/` first rather than copying over them, so
   a file the new release removed does not stay behind. Do not delete
   `config.php`, the storage folder, or anything you added to `public/`
   yourself (your own `.well-known/security.txt`, say): put those back if they
   lived inside a folder you replaced.
4. **Log in.** If the database needs updating, the dashboard says so in a
   banner with a button that applies it; nothing else is needed. Files and
   share links keep working while it is pending.
5. **Check the dashboard's requirements panel.** In a subdirectory install the
   new `public/.htaccess` has lost the 403 page line the installer wrote; the
   "Custom 403 page" row offers to set it again in one click.

`config.php` is never changed by an update. A new setting a release adds
takes its default until you set it in the dashboard; `config-sample.php`
lists every key.

## If something goes wrong

Put back the old `app/` and `public/`. If the database banner has already
run, restore the database dump too: an older release refuses to start on a
newer database rather than write rows it does not understand (see
[Maintaining](maintaining.md)).

---

[← AESBin docs](README.md) · [Project home](../README.md)
