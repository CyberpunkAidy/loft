# Releasing Loft Studio

1. Update the version in three places:
   - `loft-studio.php`: the `Version:` header and `define( 'LOFT_VERSION', … )`
   - the title of `README.md`
   - Bump `LOFT_DB_VERSION` in `loft-studio.php` only if the database tables change. That's what triggers `Installer::maybe_upgrade()`.
2. Add the release to the top of `CHANGELOG.md`.
3. Commit, then tag: `git tag -a v0.2.0 -m "Loft Studio 0.2.0"`.
4. Build the zip: `bin/build-zip.sh`, which writes `dist/loft-studio-0.2.0.zip`.
5. Install it on the staging site first (Plugins → Add New → Upload Plugin → Replace current with uploaded). Only then install it on a live site.

`git archive` builds the zip from the tag, so it never contains uncommitted work, and it leaves out the files marked `export-ignore` in `.gitattributes`. Those are repo-only files: this checklist, `bin/` and `dist/`.
