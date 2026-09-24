<?php
// ============================================================
// PT Import / Encoding Worker (CLI)
// ============================================================
// Background worker for the two PlayTube ingestion queues:
//
//   1. import_queue (T_IMPORT_QUEUE) - external video imports
//      (youtube/tiktok/etc.), processed by PT_ProcessImportQueue().
//   2. queue (T_QUEUE) - the encoding/conversion queue; each row is
//      encoded by PT_ProcessQueueVideo(), the same code the old
//      cronjob.php inlined.
//
// This worker is the drop-in replacement for running these jobs
// from the web cronjob (cronjob.php keeps them for web-CRON users,
// but the worker runs the same shared logic and is safe to schedule
// concurrently thanks to its single-instance flock lock and the
// atomic per-row claims inside PT_ProcessImportQueue/PT_ProcessQueueVideo).
//
// Scheduling:
//   Windows:  deploy/import/install-windows-task.ps1  (Task Scheduler)
//   *nix:     cron ->  every minute, or better:
//             30 2 * * * php <repo>/deploy/import/import_worker.php --once
//             or keep a resident loop:
//             nohup php <repo>/deploy/import/import_worker.php >out.log 2>&1 &
//
// Usage:
//   php deploy\import\import_worker.php --once            one pass, then exit
//   php deploy\import\import_worker.php                    resident loop (config interval)
//   php deploy\import\import_worker.php --interval 120     resident loop, 120s between passes
//
// Heartbeat: every pass writes the current unix timestamp to config
// key `import_worker_heartbeat` so admins can detect a dead worker.
//
// Exit: 0 ok, 1 fatal bootstrap/loop error.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "import_worker.php - PlayTube import/encoding worker\n"
        . "  --once              run a single pass then exit\n"
        . "  --interval N        resident loop, N seconds between passes (overrides config)\n"
        . "  --help              this message\n");
    exit(0);
}

define('PT_IMPORT_WORKER_START', microtime(true));

// Single-instance guard: flock is advisory, so both Windows and *nix
// respect it as long as every invoker uses this same lock file.
$lockPath = sys_get_temp_dir() . '/pt-import-worker.lock';
$lockFh   = @fopen($lockPath, 'c');
if ($lockFh === false) {
    fwrite(STDERR, '[import] cannot open lock file ' . $lockPath . PHP_EOL);
    exit(1);
}
if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, '[import] another worker instance already running - skipping' . PHP_EOL);
    exit(0);
}

// ------------------------------------------------------------
// 1) Bootstrap the app like the other entry points
// ------------------------------------------------------------
chdir(realpath(__DIR__ . '/../..'));
require_once './assets/init.php';
decryptConfigData();

if (empty($pt) || empty($db)) {
    fwrite(STDERR, '[import] bootstrap failed: no $pt/$db' . PHP_EOL);
    exit(1);
}

$interval = (int) @$pt->config->import_worker_interval;
if ($interval < 1) {
    $interval = 60;
}
if (in_array('--once', $argv, true)) {
    $looping = false;
} else {
    $looping = true;
    $keyIdx  = array_search('--interval', $argv, true);
    if ($keyIdx !== false && isset($argv[$keyIdx + 1])) {
        $interval = max(1, (int) $argv[$keyIdx + 1]);
    }
}

// queue_count is the "how many videos to encode per pass" knob used by
// cronjob.php. Its seed value is '0', which MysqliDb turns into LIMIT 0
// (i.e. nothing) - so the worker falls back to a sane batch size.
$batchLimit = (int) @$pt->config->queue_count;
if ($batchLimit < 1) {
    $batchLimit = 5;
}

function pt_import_worker_pass($db, $batchLimit)
{
    $stats = array('import' => 0, 'ok' => 0, 'skipped' => 0, 'errors' => 0);

    // Feed the encoding queue from the import jobs first.
    try {
        $result = PT_ProcessImportQueue(5);
        $stats['import'] = isset($result['processed']) ? (int) $result['processed'] : 0;
    } catch (Exception $e) {
        $stats['import_errors'] = substr($e->getMessage(), 0, 300);
    }

    $queue = $db->get(T_QUEUE, $batchLimit, '*');
    if (!empty($queue)) {
        foreach ($queue as $item) {
            try {
                $res = PT_ProcessQueueVideo($item);
                if (isset($res['status'])) {
                    if ($res['status'] == 'ok') {
                        $stats['ok']++;
                    } elseif ($res['status'] == 'error') {
                        $stats['errors']++;
                    } else {
                        $stats['skipped']++;
                    }
                } else {
                    $stats['skipped']++;
                }
            } catch (Exception $e) {
                $stats['errors']++;
            }
        }
    }

    return $stats;
}

// ------------------------------------------------------------
// 2) Heartbeat + main loop
// ------------------------------------------------------------
do {
    try {
        $db->where('name', 'import_worker_heartbeat')->update(T_CONFIG, array('value' => time()));
    } catch (Exception $e) {
        // heartbeat is best-effort only
    }

    $stats   = pt_import_worker_pass($db, $batchLimit);
    $elapsed = number_format(microtime(true) - PT_IMPORT_WORKER_START, 2);
    $line    = '[' . date('c') . "] import={$stats['import']} queued ok={$stats['ok']} skipped={$stats['skipped']} errors={$stats['errors']} elapsed={$elapsed}s";
    if (isset($stats['import_errors'])) {
        $line .= ' IMPORT_ERR=' . $stats['import_errors'];
    }
    fwrite(STDOUT, $line . PHP_EOL);

    if ($looping) {
        sleep($interval);
    }
} while ($looping);

flock($lockFh, LOCK_UN);
fclose($lockFh);
exit(0);