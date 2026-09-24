# ============================================================
# install-windows-task.ps1
# ============================================================
# Registers a Windows Scheduled Task that runs the PlayTube
# backup worker (deploy/backup/backup_worker.php) daily.
#
# Usage (from the repo root or anywhere):
#   powershell -ExecutionPolicy Bypass -File deploy\backup\install-windows-task.ps1
#
# Parameters:
#   -TaskName     (default "PlayTubeBackup")
#   -Time         (default "02:30")  - HH:mm 24h local
#   -PhpPath      (default: auto-detect php.exe on PATH)
#   -RepoRoot     (default: repo root resolved from this script's path)
#   -Remove       - remove the task instead of creating it
# ============================================================

param(
    [string]$TaskName  = 'PlayTubeBackup',
    [string]$Time      = '02:30',
    [string]$PhpPath   = '',
    [string]$RepoRoot  = '',
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($RepoRoot)) {
    $RepoRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
}

$worker = Join-Path $RepoRoot 'deploy\backup\backup_worker.php'
if (-not (Test-Path -LiteralPath $worker)) {
    throw "backup worker not found: $worker"
}

# --- locate PHP -----------------------------------------------------------
if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) {
        $PhpPath = $cmd.Source
    } else {
        throw 'php.exe not found on PATH - pass -PhpPath explicitly'
    }
}
if (-not (Test-Path -LiteralPath $PhpPath)) {
    throw "php not found: $PhpPath"
}

if ($Remove) {
    $existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($existing) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "Removed scheduled task: $TaskName"
    } else {
        Write-Host "No task named $TaskName to remove."
    }
    exit 0
}

$phpQuoted   = "'" + $PhpPath.Replace("'", "''") + "'"
$workerQuote = "'" + $worker.Replace("'", "''") + "'"
$timeParts   = $Time -split ':'
$hour        = [int]$timeParts[0]
$minute      = [int]$timeParts[1]

$action  = New-ScheduledTaskAction -Execute $PhpPath -Argument $workerQuote
$trigger = New-ScheduledTaskTrigger -Daily -At ([TimeSpan]::FromHours($hour).Add([TimeSpan]::FromMinutes($minute)))
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::FromHours(2)) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Description 'PlayTube auto-backup to Azure Blob' -Force | Out-Null

Write-Host "Registered scheduled task: $TaskName"
Write-Host ("  PHP:    {0}" -f $PhpPath)
Write-Host ("  Worker: {0}" -f $worker)
Write-Host ("  Daily:  {0:00}:{1:00}" -f $hour, $minute)
Write-Host 'Verify with: Get-ScheduledTask -TaskName'"$TaskName"