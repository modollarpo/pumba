# Production Operations Checklist

Items surfaced by `system-status.php`, and the order to clear them.

`system-status.php` renders `getStatus()` from
`assets/includes/functions_one.php:3668`. Every line it prints is an `error`
unless noted — the page has no "warning" severity in practice, so read the
severity with judgement rather than treating all of them as equal.

## 0. CRITICAL: the installer is publicly reachable

**Do this before anything else in this document.**

Verified on `https://play.keekii.net` (checked 2026-09-28):

```
GET /install/                  -> 200, 8988 bytes, <title>PlayTube | Installation</title>
GET /install/index.php?step=1  -> 200, 8988 bytes
```

The page is the live four-step setup wizard with a terms-acceptance form. It is
not a stub. `install/index.php` then:

- **line 117** — `file_put_contents($config_file_name, $file_content)` overwrites
  `../config.php` with submitted database credentials.
- **lines 179-181** — `INSERT INTO users (username, password, email, admin,
  active, registered) VALUES ('<submitted>', sha1('<submitted>'), ...)` creates an
  admin account with credentials supplied by whoever submits the form.
- **line 272** — checks `is_writable('../config.php')`; the wizard proceeds when
  the webserver can write it.

So anyone who can reach the site can accept the terms, run the wizard, and end
up with a database config they control plus an admin login. That is full site
takeover, not a cosmetic exposure.

Do it now:

```bash
rm -rf /path/to/webroot/install
```

Then confirm it is gone from outside:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://your-host/install/
```

Expect `404`.

Do not simply rename the directory to something guessable, and do not rely on
the wizard refusing to run — `getStatus()` merely *reports* this directory, it
does not block it. If you want a second layer, deny it at the webserver as well:

```apache
RedirectMatch 404 ^/install
```

**Also public on that host:** `system-status.php` returns 200 and prints the
full server inventory (PHP version, paths, writability of every directory, DB
version, cron age, SMTP state). That is useful to an attacker doing exactly the
reconnaissance this document describes. Remove it, or restrict it:

```apache
<Files "system-status.php">
    Require local
</Files>
```

`.git/config` already returns 403, so the vhost has *some* deny rules — check
that `install/` and `system-status.php` were simply missed.

## 1. Delete `./install` — covered above

This is the same finding as section 0, kept here because `getStatus()` reports
it at `functions_one.php:3807`. The stock PlayTube installer should not be
reachable on a live site at all.

## 2. Version drift — see the migration

`getStatus()` compares `$pt->config->version` (from the `config` table) against
`$pt->config->filesVersion`, which is hardcoded at
`assets/includes/app_start.php:586` to the version of the files on disk.

**Do not bump `config.version` to bust a browser cache.** That row doubles as
the schema version, so moving it makes the site report that the database is
running newer code than the files — and `app_start.php:588-592` responds to that
mismatch by calling `error_reporting(0)`, silencing PHP errors site-wide.

Use the `assets_version` row for assets:

```bash
UPDATE config SET value = '3.1.5' WHERE name = 'assets_version';
```

Schema migrations and asset bumps are separate concerns and should move at
separate times. Run `deploy/sql/2026-09-28-split-assets-version.sql` to split
them and to de-duplicate the `version` row (the shipped dump contains three, and
`PT_GetConfig()` is last-write-wins, so the effective version was whichever row
the query happened to return last).

The migration reads `version` **before** resetting it, and carries the old value
into `assets_version` — not into `3.1.2`. That matters: `version` was at
`3.1.4` precisely because someone bumped it to force a CSS refresh, so clients
are holding assets keyed on `?version=3.1.4`. Seeding `3.1.2` would issue a key
the browser has already cached from *before* the restyle, which is exactly the
stale-stylesheet problem this change exists to fix. Check the confirm query at
the end reports `3.1.4`.

Note that `config` has **no unique index on `name`**, which is why duplicates
exist in the first place and why the insert has to be guarded with `NOT EXISTS`
rather than `INSERT IGNORE`.

The shipped dump duplicates **10** of its 437 config names. Seven are
identical copies and harmless, but three disagree — and in all three the lower
id is empty while the higher id holds the real value:

| name | lower id | higher id |
|---|---|---|
| `ftp_host` | id 90 `''` | id 98 `'localhost'` |
| `ftp_port` | id 91 `''` | id 99 `'21'` |
| `ftp_endpoint` | id 95 `''` | id 103 `'storage.wowonder.com'` |

Because `PT_GetConfig()` resolves duplicates last-write-wins in row order, the
higher id is the value the site is actually using — but that is not guaranteed,
since the query has no `ORDER BY`. The effective value of any duplicated setting
is therefore arbitrary and can differ between hosts. Run
`deploy/sql/2026-09-28-dedupe-config-names.sql`, which keeps the highest id and
so preserves current behaviour while removing the ambiguity.

### Verified against production

Fetched `https://play.keekii.net` on 2026-09-28 (read-only, no writes):

- Theme in use: **playtag**.
- Assets are served with `?version=3.1.4` (5 references) — this confirms
  `config.version` really is `3.1.4` in production, which is what the split
  migration's seed value depends on.
- **But `style.css` was served as `?version=1.0.7`.** `playtag` and `youplay`
  build their cache-buster from a `Tag_version()` helper defined *inside the
  template* (`themes/playtag/layout/container.html:2-4`) that hardcodes
  `echo '1.0.7'`. So the main theme stylesheet was pinned to `1.0.7` and
  bumping `config.version` never busted it — a restyle of `style.css` would
  silently not appear for returning visitors. Both themes now use
  `{{CONFIG assets_version}}` for it. `Tag_version()` is left in place for the
  `<meta name="version">` tag, which nothing in the repo reads.
- `night-mode.css` had no cache-buster at all in several templates, including
  the JS that injects it on dark-mode toggle, and `style.css` in the
  `hybird_view` layouts had none either. All now carry
  `?version={{CONFIG assets_version}}`.
- Third-party libraries (bootstrap, jquery, font-awesome, DataTables, swiper,
  trumbowyg…) are deliberately left **unversioned**. They do not change between
  releases, and versioning them would force a re-download of every vendor asset
  on each restyle.

Run the confirm query at the end of each migration and check the output before
moving on.

## 3. Sitemaps not writable

`getStatus()` wants `/sitemaps` set to `777`. Don't. That grants world-write to
a directory served by the webserver; it only needs write for the account PHP
runs as:

```bash
chown -R www-data:www-data /path/to/webroot/sitemaps
chmod 775 /path/to/webroot/sitemaps
```

The existing XML files stay readable either way — the generator simply cannot
refresh them until the directory is writable.

## 4. `cronjob.php` not running

`getStatus()` reports this when `config.cronjob_last_run` is more than 15
minutes old. `cronjob.php:49` updates that row, so a single manual run both
proves it works and clears the warning:

```bash
php /path/to/webroot/cronjob.php
```

Then schedule it (the page says "cron-job.php"; the real filename is
`cronjob.php` — that string in `functions_one.php:3807` is a typo):

```cron
*/5 * * * * php /path/to/webroot/cronjob.php >/dev/null 2>&1
```

See `README.md` for what this drives — video encoding, live stream cleanup and
per-resolution renderings.

## 5. SMTP not configured

Only bites when the site sends mail: password resets, notifications, contact
form. Until then it is cosmetic. Configure a real relay in the admin panel
rather than relying on the PHP default `mail()`, which needs a working MTA on
the host and usually does not have one.

## 6. SQL modes — ignore these two

`getStatus()` asks you to have your host disable `strict_trans_tables` and
`only_full_group_by`. Do not. Both are MySQL defaults since 5.7/8.0 and both
protect you:

- `only_full_group_by` rejects a `GROUP BY` that does not cover every selected
  non-aggregated column. It is telling you the query is wrong.
- `strict_trans_tables` rejects silent truncation and out-of-range values. It
  turns loud failures into quiet data corruption.

On shared or managed MySQL these are also server-wide: turning them off degrades
every other database on the instance. If a specific query trips one, fix the
query. See "Config rows missing on an existing install" in `DEPLOY.md` for the
row-level `INSERT IGNORE` path.
