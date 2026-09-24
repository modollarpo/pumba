# ============================================================
# install-windows-task.ps1
# ============================================================
# Registers a Windows Scheduled Task that runs the PlayTube
# import/encoding worker (deploy/import/import_worker.php) --once
# on a repeating schedule.
#
# Usage (from the repo root or anywhere):
#   powershell -ExecutionPolicy Bypass -File deploy\import\install-windows-task.ps1
#
# Parameters:
#   -TaskName       (default "PlayTubeImport")
#   -RepeatMinutes  (default 1)   - repeat interval of the --once run
#   -PhpPath        (default: auto-detect php.exe on PATH)
#   -RepoRoot       (default: repo root resolved from this script's path)
#   -Remove         - remove the task instead of creating it
# ============================================================

param(
    [string]$TaskName      = 'PlayTubeImport',
    [int]$RepeatMinutes    = 1,
    [string]$PhpPath       = '',
    [string]$RepoRoot      = '',
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($RepoRoot)) {
    $RepoRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
}

$worker = Join-Path $RepoRoot 'deploy\import\import_worker.php'
if (-not (Test-Path -LiteralPath $worker)) {
    throw "import worker not found: $worker"
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

if ($RepeatMinutes -lt 1) {
    $RepeatMinutes = 1
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

$action  = New-ScheduledTaskAction -Execute $PhpPath -Argument "$workerQuote --once"
$trigger = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) `
    -RepetitionInterval ([TimeSpan]::FromMinutes($RepeatMinutes)) `
    -RepetitionDuration ([TimeSpan]::FromDays(3650))
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Description 'PlayTube import + encoding queue worker' -Force | Out-Null

Write-Host "Registered scheduled task: $TaskName"
Write-Host ("  PHP:     {0}" -f $PhpPath)
Write-Host ("  Worker:  {0}" -f $worker)
Write-Host ("  Repeat:  every {0} minute(s), --once per run" -f $RepeatMinutes)
Write-Host 'Verify with: Get-ScheduledTask -TaskName'"$TaskName"