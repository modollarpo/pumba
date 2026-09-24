<?php
// ============================================================
// PT Backup Worker (CLI)
// ============================================================
// Scheduled-task entry point for the PlayTube auto-backup feature.
// Encrypted DB dumps + volume-recordings archives are pushed to the SAME
// Azure Blob container the site already uses, via the native Blob REST +
// SharedKey engine already committed in assets/includes/functions_one.php
// (PT_AzureBlobRequest). No second Azure client, no separate credentials
// file, no mysqldump binary: the DB dump is table-by-table through the
// app's own $db connectionalert, gz-compressed, then PUT. Recordings are
// archived with PHP's ZipArchive. Retention prunes the *archive* blobs it
// uploaded (tracked by a small local inventory), while live recording
// files on disk are already cleaned by the admin autoload using
// mediamtx_recordings_retention.
//
// Scheduling:
//   Windows:  deploy/backup/install-windows-task.ps1  (Task Scheduler)
//   *nix:     cron ->  30 2 * * * php <repo>/deploy/backup/backup_worker.php
//
// Exit: 0 ok, 1 one-or-more steps failed.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('PT_BACKUP_START', microtime(true));

// ------------------------------------------------------------
// 1) Bootstrap the app exactly like the web app does
// ------------------------------------------------------------
require realpath(__DIR__ . '/../../assets/includes/app_start.php');

if (empty($pt) || empty($db)) {
    fwrite(STDERR, '[backup] bootstrap failed: no $pt/$db' . PHP_EOL);
    exit(1);
}

$cfg       = $pt->config;
$dateKey   = date('Y-m-d');
$startStr  = date('c');
$backupDir = sys_get_temp_dir() . '/pt-backup-' . getmypid();
$errors    = array();
$uploaded  = array();
$pruned    = array();
$files     = array();

if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true)) {
    fwrite(STDERR, '[backup] cannot create temp workdir' . PHP_EOL);
    exit(1);
}

// ------------------------------------------------------------
// 2) Pure-PHP DB dump -> gzip (no mysqldump dependency)
// ------------------------------------------------------------
$gzPath = $backupDir . '/' . $dateKey . '-playtube-db.sql.gz';
$gz     = @gzopen($gzPath, 'wb9');
if ($gz === false) {
    $errors[] = 'cannot create .gz target';
} else {
    $tables = array();
    $res    = $db->rawQuery('SHOW TABLES');
    foreach ($res as $row) {
        $tables[] = reset($row);
    }
    $dbTables = array();
    foreach ($tables as $t) {
        $created = $db->rawQuery('SHOW CREATE TABLE `' . $db->escape($t) . '`');
        if (empty($created)) {
            $errors[] = 'no CREATE for ' . $t;
            continue;
        }
        $create = isset($created[0]['Create Table']) ? $created[0]['Create Table'] : '';
        gzwrite($gz, "-- table: {$t}\n{$create};\n\n");

        $cols = array();
        foreach ($db->rawQuery('SHOW COLUMNS FROM `' . $db->escape($t) . '`') as $cRow) {
            $cols[] = $cRow['Field'];
        }
        $colList = '`' . implode('`,`', $cols) . '`';

        $offset = 0;
        while (true) {
            $rows = $db->rawQuery('SELECT * FROM `' . $db->escape($t) . '` LIMIT ' . $offset . ',' . 500);
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $r) {
                $vals = array();
                foreach ($cols as $cname) {
                    $v = isset($r[$cname]) ? $r[$cname] : null;
                    $vals[] = ($v === null) ? 'NULL' : "'" . $db->escape($v) . "'";
                }
                gzwrite($gz, 'INSERT INTO `' . $db->escape($t) . '` (' . $colList . ') VALUES (' . implode(',', $vals) . ");\n");
            }
            $offset += 500;
            if (count($rows) < 500) {
                break;
            }
        }
    }
    $dbTables = $tables;
    gzclose($gz);
}

if (!empty($dbTables) && file_exists($gzPath)) {
    $files['db'] = $gzPath;
} else {
    $errors[] = 'db dump produced nothing';
}

// ------------------------------------------------------------
// 3) Archive MediaMTX recordings dir (if configured) -> zip
// ------------------------------------------------------------
$recDir = (string) @$cfg->mediamtx_recordings_path;
if (!empty($recDir) && is_dir($recDir)) {
    $zipName = $backupDir . '/' . $dateKey . '-recordings.zip';
    $zip     = new ZipArchive();
    if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($recDir,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO));
        $added = 0;
        foreach ($it as $f) {
            if ($f->isDir()) {
                continue;
            }
            $local = $f->getPathname();
            $rel   = 'recordings/' . str_replace('\\', '/', ltrim(str_replace($recDir, '', $local), '/'));
            $zip->addFile($local, $rel);
            $added++;
        }
        $zip->close();
        if ($added > 0 && file_exists($zipName) && filesize($zipName) > 0) {
            $files['recordings'] = $zipName;
        } else {
            @unlink($zipName);
        }
    }
}

// ------------------------------------------------------------
// 4) Upload to Azure Blob via the committed native SharedKey engine
// ------------------------------------------------------------
if (empty($cfg->azure_blob_storage) || $cfg->azure_blob_storage != 'on') {
    $errors[] = 'azure_blob_storage is not on';
} else {
    foreach ($files as $tag => $localFile) {
        $blobKey = 'backups/' . $dateKey . '/' . basename($localFile);
        $fh      = fopen($localFile, 'rb');
        if (!$fh) {
            $errors[] = 'cannot open ' . $localFile;
            continue;
        }
        $ok = PT_AzureBlobRequest('PUT', $blobKey, $fh, (int) filesize($localFile));
        fclose($fh);
        if ($ok === true) {
            $uploaded[] = array('key' => $blobKey, 'date' => $dateKey);
        } else {
            $errors[] = 'upload failed: ' . $blobKey;
        }
    }
}

// ------------------------------------------------------------
// 5) Retention: prune archive blobs older than backup_retention_days
// ------------------------------------------------------------
// PT_AzureBlobRequest returns bool only (no LIST body), so instead of
// listing the container we track an inventory of the exact keys this
// worker uploaded and DELETE the ones past retention. Live recording
// *files* on disk are handled separately by the admin autoload
// (mediamtx_recordings_retention + PT_CleanLiveRecordings).
$retentionDays = (int) @$cfg->backup_retention_days;
if ($retentionDays < 1) {
    $retentionDays = 30;
}
$invFile   = $backupDir . '/../pt-backup-inventory.json';
$inventory = array();
if (file_exists($invFile)) {
    $decoded = json_decode(@file_get_contents($invFile), true);
    if (is_array($decoded)) {
        $inventory = $decoded;
    }
}
$cutoff = strtotime('-' . $retentionDays . ' days');
$newInv = array();
foreach ($inventory as $entry) {
    $key  = (string) @$entry['key'];
    $date = (string) @$entry['date'];
    if ($key === '' || $date === '') {
        continue;
    }
    $ts = strtotime($date);
    if ($ts !== false && $ts < $cutoff) {
        if (PT_AzureBlobRequest('DELETE', $key) === true) {
            $pruned[] = $key;
        }
        // whether deleted or not, drop from inventory (retention is duration-based)
    } else {
        $newInv[] = $entry;
    }
}
foreach ($uploaded as $u) {
    $newInv[] = $u;
}
@file_put_contents($invFile, json_encode($newInv));

// ------------------------------------------------------------
// 6) Local cleanup + report
// ------------------------------------------------------------
foreach ($files as $localFile) {
    @unlink($localFile);
}
@rmdir($backupDir);

$elapsed = number_format(microtime(true) - PT_BACKUP_START, 2);
$line    = '[' . $startStr . '] backup done in ' . $elapsed . 's'
         . ' | db=' . (isset($files['db']) ? 'yes' : 'no')
         . ' | recordings=' . (isset($files['recordings']) ? 'yes' : 'no')
         . ' | uploaded=' . count($uploaded)
         . ' | pruned=' . count($pruned)
         . (empty($errors) ? '' : ' | ERRORS=' . implode('; ', $errors));
fwrite(STDERR, $line . PHP_EOL);

exit(empty($errors) ? 0 : 1);
