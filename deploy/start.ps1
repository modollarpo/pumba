# Start PlayTube's self-hosted services (Windows / PowerShell)
# Usage: .\deploy\start.ps1 -YouTubeDir "C:\api\youtube-media-downloader-api" -TikTokDir "C:\api\tiktok-downloader-api" -MediaMTX "C:\mediamtx\mediamtx.exe" -MediaMTXConfig "C:\mediamtx\mediamtx.yml"
param(
    [string]$YouTubeDir = "",
    [string]$TikTokDir = "",
    [string]$MediaMTX = "",
    [string]$MediaMTXConfig = "",
    [int]$YouTubePort = 3000,
    [int]$TikTokPort = 3001,
    [switch]$RunPlayTube
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$LogDir = Join-Path $Root 'deploy\logs'
if (-not $YouTubeDir) { $YouTubeDir = Join-Path $Root 'deploy\apis\youtube-media-downloader-api' }
if (-not $TikTokDir)  { $TikTokDir  = Join-Path $Root 'deploy\apis\tiktok-downloader-api' }
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

$Start = Get-Date
$Jobs = @()

function Write-Header([string]$msg) {
    Write-Host ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $msg) -ForegroundColor Cyan
}

# --- YouTube API ---
if ($YouTubeDir) {
    if (-not (Test-Path (Join-Path $YouTubeDir 'app.js'))) { Write-Host "YouTube app.js not found in $YouTubeDir" -ForegroundColor Red; exit 1 }
    Write-Header "Starting YouTube API on port $YouTubePort"
    $J = Start-Job -Name 'youtube-api' -ArgumentList $YouTubeDir, $YouTubePort -ScriptBlock {
        param($dir, $port)
        Set-Location $dir
        $env:PORT = "$port"
        & node app.js
    }
    $Jobs += $J
} else {
    Write-Host "Skipping YouTube API (no -YouTubeDir given)."
}

# --- TikTok API ---
if ($TikTokDir) {
    if (-not (Test-Path (Join-Path $TikTokDir 'app.js'))) { Write-Host "TikTok app.js not found in $TikTokDir" -ForegroundColor Red; exit 1 }
    Write-Header "Starting TikTok API on port $TikTokPort"
    $J = Start-Job -Name 'tiktok-api' -ArgumentList $TikTokDir, $TikTokPort -ScriptBlock {
        param($dir, $port)
        Set-Location $dir
        $env:PORT = "$port"
        & node app.js
    }
    $Jobs += $J
} else {
    Write-Host "Skipping TikTok API (no -TikTokDir given)."
}

# --- MediaMTX ---
if ($MediaMTX) {
    if (-not (Test-Path $MediaMTX)) { Write-Host "MediaMTX not found at $MediaMTX" -ForegroundColor Red; exit 1 }
    Write-Header "Starting MediaMTX"
    $MediaMTXArgs = @()
    if ($MediaMTXConfig) { $MediaMTXArgs += "-config"; $MediaMTXArgs += $MediaMTXConfig }
    $J = Start-Job -Name 'mediamtx' -ArgumentList $MediaMTX, $MediaMTXArgs -ScriptBlock {
        param($bin, $argsArr)
        & $bin @argsArr
    }
    $Jobs += $J
} else {
    Write-Host "Skipping MediaMTX (no -MediaMTX given)."
}

# --- PlayTube PHP dev server (optional) ---
if ($RunPlayTube) {
    Write-Header "Starting PHP built-in server on 127.0.0.1:8000 (docroot $Root)"
    $phpBin = (Get-Command php -ErrorAction SilentlyContinue).Source
    if (-not $phpBin) { Write-Host "php not found on PATH" -ForegroundColor Red; exit 1 }
    $J = Start-Job -Name 'playtube' -ArgumentList $Root, $phpBin -ScriptBlock {
        param($root, $php)
        Set-Location $root
        & $php -S 127.0.0.1:8000
    }
    $Jobs += $J
}

if ($Jobs.Count -eq 0) { Write-Host "Nothing to start. Provide at least one service path." -ForegroundColor Yellow; exit 0 }

Write-Header "All services launched. Streaming logs below (Ctrl+C to stop everything; logs also saved to $LogDir)."
try {
    while ($true) {
        foreach ($J in $Jobs) {
            while ($J.HasMoreData) {
                $line = Receive-Job $J
                if ($line) {
                    Write-Host ("[{0}] {1}" -f $J.Name, $line)
                    $line | Out-File (Join-Path $LogDir ($J.Name + '.log')) -Append -Encoding utf8
                }
            }
        }
        Start-Sleep -Milliseconds 500
    }
} finally {
    Write-Header "Stopping jobs..."
    $Jobs | Stop-Job
    $Jobs | Remove-Job -Force
    Write-Header "Done. Uptime: $([math]::Round(((Get-Date)-$Start).TotalMinutes,1)) min"
}