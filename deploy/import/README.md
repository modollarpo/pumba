# PlayTube import / encoding worker

Background worker for the two PlayTube ingestion queues, so imports and
video encoding do **not** depend on a web page load or the web `cronjob.php`.

## What it is

A CLI PHP worker (`import_worker.php`) that runs on a schedule and every pass:

1. **Imports**: processes up to 5 pending jobs from `import_queue`
   (`PT_ProcessImportQueue(5)`) - YouTube/TikTok/other external imports.
2. **Encodes**: converts up to `queue_count` videos from the `queue` table
   (`PT_ProcessQueueVideo()`) - the exact same encoding pipeline the old
   `cronjob.php` ran inline (gif, shorts, per-resolution `*p` renderings,
   demo videos, original-file cleanup, channel notifications).

The encoding pipeline now lives once in `assets/includes/functions_one.php`
as `PT_ProcessQueueVideo($queue_item)` and is shared by `cronjob.php` (web
CRON) and this worker, so both behave identically.

## Why not just the old cronjob?

- `cronjob.php` runs its encoding loop during a **web request**; the worker
  runs it from the CLI under watchdog/scheduled-task control.
- `PT_ProcessQueueVideo()` claims each queue row **atomically**
  (`UPDATE ... SET processing=1 WHERE id=? AND processing=0` + `$db->count`),
  so two workers / cron + worker can never encode the same row. The old
  loop marked rows non-atomically and could double-encode.
- It **restores the per-video quality flags** after each item, so one short's
  `turnOffVideoQuality()` state can never leak into the next video in a
  long-running process.
- It fixes the never-firing demo-video branch: the old loop referenced the
  undefined `$data_insert['sell_video']` / `$duration_file['playtime_seconds']`
  (demo videos were never created from the queue). Now the sell-video flag and
  duration come from the real video row + a getID3 analyze, mirroring the
  upload path.

## Scheduling

Windows (`Task Scheduler`, runs `--once` every N minutes):

```powershell
powershell -ExecutionPolicy Bypass -File deploy\import\install-windows-task.ps1
```

Params: `-TaskName PlayTubeImport` (default), `-RepeatMinutes 1`,
`-PhpPath`, `-RepoRoot`, `-Remove` (to unregister).

Unix cron (every minute, `--once`):

```cron
* * * * * php /path/to/deploy/import/import_worker.php --once >> /var/log/playtube-import.log 2>&1
```

Or a single resident loop process instead:

```bash
nohup php /path/to/deploy/import/import_worker.php > /var/log/playtube-import.log 2>&1 &
```

## Manual run

From the repo root:

```
php deploy\import\import_worker.php --once        # one pass
php deploy\import\import_worker.php --interval 60 # resident loop, 60s
```

CLI-only: refuses to run from a web request (`403 CLI only`).

## Single instance

The worker takes an advisory **flock** lock (`<temp>/pt-import-worker.lock`)
at startup. A second concurrent instance exits `0` immediately, so running it
from cron *and* as a resident loop at the same time is safe. Everything is
still safe even without the lock because each queue row claim is atomic.

## Configuration keys (PlayTube admin config table)

| key                        | purpose                                   | default |
|----------------------------|-------------------------------------------|---------|
| `queue_count`              | max videos encoded per pass (seed `'0'`)  | 5*      |
| `import_worker_interval`   | resident-loop sleep between passes (s)    | 60      |
| `import_worker_heartbeat`  | unix ts written each pass by the worker   | 0       |

`*` `queue_count`'s seeded value `'0'` is what `cronjob.php` also feeds
straight into MysqliDb - which renders `LIMIT 0` (nothing). The worker treats
`< 1` as "use a batch of 5 per pass". To encode more per pass:

```sql
UPDATE `config` SET `value` = '10' WHERE `name` = 'queue_count';
```

`import_worker_heartbeat` lets you detect a dead worker, e.g. with
`PT_GetConfig()['import_worker_heartbeat']` in the admin panel, or:

```sql
SELECT value > UNIX_TIMESTAMP() - 300 FROM config WHERE name = 'import_worker_heartbeat';
```

## Exit codes

`0` normal (including "another instance already running"). `1` fatal
bootstrap/lock error.

## Notes / assumptions

- Reuses the site's own `config.php` + `assets/init.php` bootstrap, so no
  separate credentials file. Must run with the DB creds the site uses.
- ffmpeg, ImageMagick-style helpers are whatever the existing admin settings
  point to (`ffmpeg_binary_file`, `convert_speed`, the `p*` quality toggles).
- The worker writes a one-line report to stdout per pass; redirect it to a
  log from cron/task and alert on `IMPORT_ERR=` appearing in the line.
- Keep `cronjob.php`'s web-request encoding or disable it (set `queue_count`
  low there) - both use the same atomic claims, but the worker is the
  recommended production path.