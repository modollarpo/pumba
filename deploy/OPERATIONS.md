# Production Operations Checklist

Items surfaced by `system-status.php`, and the order to clear them.

`system-status.php` renders `getStatus()` from
`assets/includes/functions_one.php:3668`. Every line it prints is an `error`
unless noted — the page has no "warning" severity in practice, so read the
severity with judgement rather than treating all of them as equal.

## 1. Delete `./install` — do this first

```bash
rm -rf /path/to/webroot/install
```

`getStatus()` flags it at `functions_one.php:3789`. The stock PlayTube installer
is still reachable on a live site, which usually means the setup wizard can be
re-run against your database. It is not needed after installation.

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
