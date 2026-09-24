# Smoke test for the PlayTube self-hosted stack (Windows / PowerShell)
# Usage: .\deploy\smoke-test.ps1 -YouTubeApi "http://127.0.0.1:3000" -TikTokApi "http://127.0.0.1:3001" -MediaMTX "http://127.0.0.1:8888" -PlayTube "http://127.0.0.1:8000" -ApiToken "secret"
# The downloader APIs require auth (HMAC or Bearer). Pass -ApiToken to test the
# signed path; without it the API checks are marked SKIP (they would 401), but
# /health and the MediaMTX/PlayTube checks still run.
param(
    [string]$YouTubeApi = "http://127.0.0.1:3000",
    [string]$TikTokApi = "http://127.0.0.1:3001",
    [string]$MediaMTX = "http://127.0.0.1:8888",
    [string]$PlayTube = "",
    [string]$ApiToken = "",
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

# Build HMAC auth headers the same way the PHP site does
# (Authorization: HMAC <sig>, X-Auth-Timestamp, X-Auth-Nonce):
# sig = hex( HMAC-SHA256(secret, "GET " + path?query + "\n" + ts + "\n" + nonce) )
function Get-HmacHeaders([string]$url) {
    if ([string]::IsNullOrWhiteSpace($ApiToken)) { return @{} }
    $u      = [System.Uri]::new($url)
    $pathQ  = $u.AbsolutePath + $u.Query            # e.g. /api/videos/search?query=test&limit=3
    $ts     = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
    $nonce  = ([guid]::NewGuid().ToString('N')).Substring(0, 16)
    $raw    = "GET " + $pathQ + "`n" + $ts + "`n" + $nonce
    $hmac   = New-Object System.Security.Cryptography.HMACSHA256
    $hmac.Key = [System.Text.Encoding]::UTF8.GetBytes($ApiToken)
    $sigBytes = $hmac.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($raw))
    $sig = -join ($sigBytes | ForEach-Object { $_.ToString('x2') })
    return @{
        'Authorization'    = "HMAC $sig"
        'X-Auth-Timestamp' = $ts
        'X-Auth-Nonce'     = $nonce
    }
}

function ApiCall([string]$url) {
    $hdrs = Get-HmacHeaders $url
    $resp = Invoke-WebRequest -Uri $url -Headers $hdrs -UseBasicParsing -TimeoutSec 60
    return (@($resp.StatusCode) -contains 200)
}

Write-Host "=== Smoke test: PlayTube self-hosted stack ===" -ForegroundColor Cyan
if ([string]::IsNullOrWhiteSpace($ApiToken)) {
    Write-Host "[.!.] no -ApiToken given; downloader API checks will SKIP (they require auth)" -ForegroundColor Yellow
} else {
    Write-Host "[.!.] -ApiToken given; checking HMAC-signed requests" -ForegroundColor Cyan
}

# -- downloader APIs (only meaningful with a token) -------------------------
if (-not [string]::IsNullOrWhiteSpace($ApiToken)) {
    # YouTube
    Check "YouTube /api/videos/search (HMAC)" { apiCall "$YouTubeApi/api/videos/search?query=test&limit=3" }
    Check "YouTube /api/video/info (HMAC)"    { apiCall "$YouTubeApi/api/video/info?id=$VideoId" }
    Check "YouTube /api/video/download (HMAC)" {
        $hdrs = Get-HmacHeaders "$YouTubeApi/api/video/download?id=$VideoId"
        $resp = Invoke-WebRequest -Uri "$YouTubeApi/api/video/download?id=$VideoId" -Headers $hdrs -UseBasicParsing -TimeoutSec 600
        $json = $resp.Content | ConvertFrom-Json
        ($json.success -eq $true) -and -not [string]::IsNullOrWhiteSpace($json.downloadUrl)
    }

    # TikTok
    Check "TikTok /api/tiktok/user (HMAC)"   { apiCall "$TikTokApi/api/tiktok/user?username=$TikTokUser&limit=3" }
    Check "TikTok /api/tiktok/search (HMAC)" { apiCall "$TikTokApi/api/tiktok/search?query=cats&limit=3" }
    if ($TikTokUrl) {
        Check "TikTok /api/tiktok/info (HMAC)" { apiCall "$TikTokApi/api/tiktok/info?video_url=$([uri]::EscapeDataString($TikTokUrl))" }
    } else {
        $Skipped++; Write-Host "[SKIP] TikTok /api/tiktok/info (no -TikTokUrl given)" -ForegroundColor Yellow
    }

    # Negative: a request without auth must be rejected (401/403)
    Check "YouTube rejects unauthenticated request" {
        $blocked = $false
        try {
            Invoke-WebRequest -Uri "$YouTubeApi/api/video/info?id=$VideoId" -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop | Out-Null
        } catch {
            $code = $_.Exception.Response.StatusCode.value__
            $blocked = ($code -eq 401 -or $code -eq 403)
        }
        $blocked
    }
} else {
    $Skipped += 6
    Write-Host "[SKIP] YouTube search/info/download + TikTok user/search (no -ApiToken)" -ForegroundColor Yellow
    if (-not $TikTokUrl) { $Skipped++ }
}

# -- public /health probes (no auth) ----------------------------------------
Check "YouTube /health" { 
    $h = Invoke-WebRequest -Uri "$YouTubeApi/health" -UseBasicParsing -TimeoutSec 15
    $h.StatusCode -eq 200
}
Check "TikTok /health" { 
    $h = Invoke-WebRequest -Uri "$TikTokApi/health" -UseBasicParsing -TimeoutSec 15
    $h.StatusCode -eq 200
}

# -- MediaMTX ----------------------------------------------------------------
Check "MediaMTX (HLS port reachable)" {
    try {
        $resp = Invoke-WebRequest -Uri "$MediaMTX/stream_health_check/index.m3u8" -UseBasicParsing -TimeoutSec 15 -ErrorAction SilentlyContinue
        $true
    } catch {
        $code = $_.Exception.Response.StatusCode.value__
        ($null -ne $code) -and ($code -eq 404 -or $code -eq 400 -or $code -eq 200)
    }
}

# -- PlayTube ----------------------------------------------------------------
if ($PlayTube) {
    Check "PlayTube front page" { apiCall "$PlayTube/" }
} else {
    $Skipped++; Write-Host "[SKIP] PlayTube front page (no -PlayTube given)" -ForegroundColor Yellow
}

Write-Host ("=============================================") -ForegroundColor Cyan
Write-Host ("Passed: {0}  Failed: {1}  Skipped: {2}" -f $Pass, $Fail, $Skipped)
if ($Fail -gt 0) { exit 1 } else { exit 0 }