#!/usr/bin/env bash
# Smoke test for the PlayTube self-hosted stack (Linux)
# Usage: ./deploy/smoke-test.sh [--youtube URL] [--tiktok URL] [--mediamtx URL] [--playtube URL] [--video-id ID] [--tiktok-url URL] [--tiktok-user NAME]
set -uo pipefail

YOUTUBE_API="http://127.0.0.1:3000"
TIKTOK_API="http://127.0.0.1:3001"
MEDIAMTX="http://127.0.0.1:8888"
PLAYTUBE=""
VIDEO_ID="dQw4w9WgXcQ"
TIKTOK_URL=""
TIKTOK_USER="therock"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --youtube)     YOUTUBE_API="$2"; shift 2;;
    --tiktok)      TIKTOK_API="$2"; shift 2;;
    --mediamtx)    MEDIAMTX="$2"; shift 2;;
    --playtube)    PLAYTUBE="$2"; shift 2;;
    --video-id)    VIDEO_ID="$2"; shift 2;;
    --tiktok-url)  TIKTOK_URL="$2"; shift 2;;
    --tiktok-user) TIKTOK_USER="$2"; shift 2;;
    -h|--help) echo "Usage: $0 [--youtube URL] [--tiktok URL] [--mediamtx URL] [--playtube URL]"; exit 0;;
    *) echo "Unknown option: $1" >&2; exit 1;;
  esac
done

PASS=0; FAIL=0; SKIP=0
log() { printf '[%s] %s\n' "$1" "$2"; }
check() { # $1 name, $2 url
  local code
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$2" || true)"
  if [ "$code" = "200" ]; then
    PASS=$((PASS+1)); log PASS "$1"
  else
    FAIL=$((FAIL+1)); log FAIL "$1 (HTTP $code)"
  fi
}

echo "=== Smoke test: PlayTube self-hosted stack ==="

# YouTube
check "YouTube /api/videos/search?query=test" "$YOUTUBE_API/api/videos/search?query=test&limit=3"
check "YouTube /api/video/info"               "$YOUTUBE_API/api/video/info?id=$VIDEO_ID"

echo "[...] YouTube download (first run may download via yt-dlp) ..."
DL_JSON="$(curl -s --max-time 600 "$YOUTUBE_API/api/video/download?id=$VIDEO_ID" || true)"
if printf '%s' "$DL_JSON" | grep -q '"success":true' && printf '%s' "$DL_JSON" | grep -q '"downloadUrl"'; then
  PASS=$((PASS+1)); log PASS "YouTube /api/video/download"
else
  FAIL=$((FAIL+1)); log FAIL "YouTube /api/video/download (no success/downloadUrl in response)"
fi

# TikTok
check "TikTok /api/tiktok/user"   "$TIKTOK_API/api/tiktok/user?username=$TIKTOK_USER&limit=3"
check "TikTok /api/tiktok/search" "$TIKTOK_API/api/tiktok/search?query=cats&limit=3"

if [ -n "$TIKTOK_URL" ]; then
  ENC="$(python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1], safe=""))' "$TIKTOK_URL" 2>/dev/null || printf '%s' "$TIKTOK_URL")"
  check "TikTok /api/tiktok/info" "$TIKTOK_API/api/tiktok/info?video_url=$ENC"
else
  SKIP=$((SKIP+1)); log SKIP "TikTok /api/tiktok/info (no --tiktok-url)"
fi

# MediaMTX: any HTTP status on a stream path proves the server is up
MM_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$MEDIAMTX/stream_health_check/index.m3u8" || true)"
if [ -n "$MM_CODE" ]; then
  PASS=$((PASS+1)); log PASS "MediaMTX reachable (HTTP $MM_CODE)"
else
  FAIL=$((FAIL+1)); log FAIL "MediaMTX unreachable"
fi

# PlayTube
if [ -n "$PLAYTUBE" ]; then
  check "PlayTube front page" "$PLAYTUBE/"
else
  SKIP=$((SKIP+1)); log SKIP "PlayTube front page (no --playtube)"
fi

echo "============================================="
echo "Passed: $PASS  Failed: $FAIL  Skipped: $SKIP"
[ "$FAIL" -eq 0 ]