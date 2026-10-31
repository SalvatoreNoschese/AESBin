# Upload test pack

`AESBin-upload-test.zip` holds 113 files that exercise what AESBin does with an
upload, and the result expected for each one:

- `files/`: 113 files named for the case they test (a PNG called `.exe`, a
  ZIP called `.docx`, a name with no extension, a dot-only name...).
- `metadata/`: 8 photos, videos, audio and a PDF carrying GPS, device and
  author metadata, to try the metadata removal.
- `EXPECTED-RESULTS.txt`: for every file, the stored name, the stored type
  and whether it previews.

## How to use it

1. Unzip it and upload everything in `files/` into one folder, hidden files
   included (`.htaccess` and `....`).
2. Compare what the dashboard shows with `EXPECTED-RESULTS.txt`.
3. For `metadata/`, follow the second half of that file: upload twice, with
   and without the "Remove metadata from photos and videos" box ticked.

The stored types were obtained on PHP 8.3. Another PHP version may name a type
differently (`audio/wav` instead of `audio/x-wav`); the final name and the
preview must still match.

Run it after any change to `MimeRegistry`, the filename rules or
`FileService::upload()`.

## Why it is a zip

The files are deliberately mislabelled, and one is a real `.htaccess`: unpacked
inside a repository that someone clones into a web root, it would be read by
the web server. As a zip it stays inert, and `*.zip binary` in
`.gitattributes` keeps git from touching its bytes. The folder is left out of
the *Source code* archive by `tests/ export-ignore`, like `docs/` and `bin/`.
