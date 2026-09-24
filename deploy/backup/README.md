# PlayTube auto-backup

Scheduled backup automation for this PlayTube (pumba) deployment.

## What it is

A CLI PHP worker (`backup_worker.php`) that runs unattended and pushes a full
database dump plus the MediaMTX live-recording archive to the **same Azure Blob
container the site already uses**, through the native Blob REST + SharedKey
engine that is already committed in `assets/includes/functions_one.php`
(`PT_AzureBlobRequest`).

No second Azure client, no separate credentials file, no `mysqldump` binary:
the DB dump is table-by-table through the app's own `$db` connection and
gzip-compressed before upload. The recordings archive is built with PHP's
built-in `ZipArchive`.

## What it does (in order)

1. Bootstraps the app through `assets/init.php` + `decryptConfigData()` (the
   same path `cronjob.php`/`ajax.php` use — same `$db` and `$pt->config`,
   including the decrypted Azure keys). No separate credentials file.
2. Pure-PHP DB dump -> `backups/<YYYY-MM-DD>/<date>-playtube-db.sql.gz`
3. Archives the MediaMTX recordings dir (if `mediamtx_recordings_path` is set)
   into `backups/<YYYY-MM-DD>/<date>-recordings.zip`
4. Uploads both to the configured Azure blob container via the committed
   `PT_AzureBlobRequest('PUT', ..., fopen(...), filesize(...))` engine.
5. Retention: prunes archive blobs older than `backup_retention_days`
   (default 30). Because `PT_AzureBlobRequest` only returns a boolean (no
   container-list body), retention is tracked with a small deterministic local
   inventory file and deletes the known-old blob keys.
   Live recording **files on disk** are cleaned separately by the admin autoload
   using `mediamtx_recordings_retention` + `PT_CleanLiveRecordings` - this worker
   only prunes the *archive* blobs in blob storage.
6. Deletes the local temp dump/zip after upload; prints a one-line report to stderr.

Exit code is `0` on success and `1` if any step failed.

## Configuration keys (PlayTube admin config table)

Reuses the existing Azure settings; only the retention key may be new:

| key                          | purpose                                    | default |
|------------------------------|--------------------------------------------|---------|
| `azure_blob_storage`         | must be `on` for upload                    | off     |
| `azure_blob_account`         | storage account name (SharedKey)           |         |
| `azure_blob_secret`          | account key (base64)                       |         |
| `azure_blob_endpoint`        | optional blob endpoint override            | auto    |
| `azure_blob_bucket_name`     | container name                             |         |
| `mediamtx_recordings_path`   | recordings dir to archive                  |         |
| `backup_retention_days`      | how many days of `backups/` blobs to keep  | 30      |

`backup_retention_days` is already seeded with the other config rows in
`playtube.sql`. To change it later:

```sql
UPDATE `config` SET `value` = '60' WHERE `name` = 'backup_retention_days';
```

(MediaMTX live-recording retention is handled by the existing
`mediamtx_recordings_retention` key through the admin autoload.)

## Manual run

From the repo root (any machine that can reach PHP + the DB):

```
php deploy\backup\backup_worker.php
```

Or from a cron/task with a log redirect:

```
30 2 * * * php /path/to/deploy/backup/backup_worker.php >> /var/log/playtube-backup.log 2>&1
```

CLI-only: the worker refuses to run from a web request (`403 CLI only`).

## Windows scheduled task

`install-windows-task.ps1` registers a daily Task Scheduler job:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\backup\install-windows-task.ps1
```

Parameters:

| param       | meaning                              | default           |
|-------------|--------------------------------------|-------------------|
| `-TaskName` | scheduled task name                  | `PlayTubeBackup`  |
| `-Time`     | run time (`HH:mm`, 24h local)        | `02:30`           |
| `-PhpPath`  | explicit `php.exe` path              | auto-detect PATH  |
| `-RepoRoot` | repo root (defaults to script parent)| auto              |
| `-Remove`   | unregister the task instead          | -                 |

Example with explicit PHP and a CRON-equivalent time:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\backup\install-windows-task.ps1 ^
  -TaskName PlayTubeBackup -Time 03:00 -PhpPath "C:\php\php.exe"
```

Schedule runs as the current (interactive) user; ensure that user can reach
the MySQL server and that the machine is on / `Start-When-Available` is used.

## Unix cron

```cron
30 2 * * * php /path/to/deploy/backup/backup_worker.php >> /var/log/playtube-backup.log 2>&1
```

## Notes / assumptions

- The worker runs from an account that can connect to the same DB as the site
  (it reuses `assets/includes/app_start.php`, so DB credentials come from the
  app's existing config - nothing new to store).
- Blob upload only happens when `azure_blob_storage = on`; otherwise the dump
  & recordings are reported as errors (so a broken Azure config fails loudly
  instead of silently skipping the backup).
- Retention is best-effort and driven by the worker's own inventory file -
  it will not delete blobs it did not create.