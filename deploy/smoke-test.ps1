# Smoke test for the PlayTube self-hosted stack (Windows / PowerShell)
# Usage: .\deploy\smoke-test.ps1 -YouTubeApi "http://127.0.0.1:3000" -TikTokApi "http://127.0.0.1:3001" -MediaMTX "http://127.0.0.1:8888" -PlayTube "http://127.0.0.1:8000"
param(
    [string]$YouTubeApi = "http://127.0.0.1:3000",
    [string]$TikTokApi = "http://127.0.0.1:3001",
    [string]$MediaMTX = "http://127.0.0.1:8888",
    [string]$PlayTube = "",
    [string]$VideoId = "dQw4w9WgXcQ",
    [string]$TikTokUrl = "",
    [string]$TikTokUser = "therock"
)

$ErrorActionPreference = 'Continue'
$Pass   = 0
$Fail   = 0
$Skipped = 0

function Check([string]$name, [scriptblock]$body) {
    try {
        $r = & $body
        if ($r) { $script:Pass++; Write-Host ("[PASS] {0}" -f $name) -ForegroundColor Green }
        else { $script:Fail++; Write-Host ("[FAIL] {0}" -f $name) -ForegroundColor Red }
    } catch {
        $script:Fail++; Write-Host ("[FAIL] {0} -- {1}" -f $name, $_.Exception.Message) -ForegroundColor Red
    }
}

function ApiCall([string]$url) {
    $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 60
    return (@($resp.StatusCode) -contains 200)
}

Write-Host "=== Smoke test: PlayTube self-hosted stack ===" -ForegroundColor Cyan

# YouTube
Check "YouTube /api/videos/search (query=test)" { apiCall "$YouTubeApi/api/videos/search?query=test&limit=3" }
Check "YouTube /api/video/info"                { apiCall "$YouTubeApi/api/video/info?id=$VideoId" }
Check "YouTube /api/video/download header"     { 
    $resp = Invoke-WebRequest -Uri "$YouTubeApi/api/video/download?id=$VideoId" -UseBasicParsing -TimeoutSec 600
    $json = $resp.Content | ConvertFrom-Json
    ($json.success -eq $true) -and -not [string]::IsNullOrWhiteSpace($json.downloadUrl)
}

# TikTok
Check "TikTok /api/tiktok/user"      { apiCall "$TikTokApi/api/tiktok/user?username=$TikTokUser&limit=3" }
Check "TikTok /api/tiktok/search"    { apiCall "$TikTokApi/api/tiktok/search?query=cats&limit=3" }
if ($TikTokUrl) {
    Check "TikTok /api/tiktok/info"   { apiCall "$TikTokApi/api/tiktok/info?video_url=$([uri]::EscapeDataString($TikTokUrl))" }
} else {
    $Skipped++; Write-Host "[SKIP] TikTok /api/tiktok/info (no -TikTokUrl given)" -ForegroundColor Yellow
}

# MediaMTX
Check "MediaMTX health (HLS port reachable)" {
    try {
        $resp = Invoke-WebRequest -Uri "$MediaMTX/stream_health_check/index.m3u8" -UseBasicParsing -TimeoutSec 15 -ErrorAction SilentlyContinue
        $true  # any HTTP response (200/404) proves the server is up
    } catch {
        $code = $_.Exception.Response.StatusCode.value__
        ($null -ne $code) -and ($code -eq 404 -or $code -eq 400 -or $code -eq 200)
    }
}

# PlayTube
if ($PlayTube) {
    Check "PlayTube front page" { apiCall "$PlayTube/" }
} else {
    $Skipped++; Write-Host "[SKIP] PlayTube front page (no -PlayTube given)" -ForegroundColor Yellow
}

Write-Host ("=============================================") -ForegroundColor Cyan
Write-Host ("Passed: {0}  Failed: {1}  Skipped: {2}" -f $Pass, $Fail, $Skipped)
if ($Fail -gt 0) { exit 1 } else { exit 0 }