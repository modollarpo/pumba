#!/usr/bin/env bash
# Smoke test for the PlayTube self-hosted stack (Linux)
# Usage: ./deploy/smoke-test.sh [--youtube URL] [--tiktok URL] [--mediamtx URL] [--playtube URL] [--api-token TOKEN] [--video-id ID] [--tiktok-url URL] [--tiktok-user NAME]
# The downloader APIs require auth (HMAC or Bearer). Pass --api-token to test
# the signed path; without it the API checks are SKIPped (they would 401) but
# /health and MediaMTX/PlayTube checks still run.
set -uo pipefail

YOUTUBE_API="http://127.0.0.1:3000"
TIKTOK_API="http://127.0.0.1:3001"
MEDIAMTX="http://127.0.0.1:8888"
PLAYTUBE=""
API_TOKEN=""
VIDEO_ID="dQw4w9WgXcQ"
TIKTOK_URL=""
TIKTOK_USER="therock"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --youtube)     YOUTUBE_API="$2"; shift 2;;
    --tiktok)      TIKTOK_API="$2"; shift 2;;
    --mediamtx)    MEDIAMTX="$2"; shift 2;;
    --playtube)    PLAYTUBE="$2"; shift 2;;
    --api-token)   API_TOKEN="$2"; shift 2;;
    --video-id)    VIDEO_ID="$2"; shift 2;;
    --tiktok-url)  TIKTOK_URL="$2"; shift 2;;
    --tiktok-user) TIKTOK_USER="$2"; shift 2;;
    -h|--help) echo "Usage: $0 [--youtube URL] [--tiktok URL] [--mediamtx URL] [--playtube URL] [--api-token TOKEN]"; exit 0;;
    *) echo "Unknown option: $1" >&2; exit 1;;
  esac
done

PASS=0; FAIL=0; SKIP=0
log() { printf '[%s] %s\n' "$1" "$2"; }

# Build HMAC auth headers the same way the PHP site does
# (Authorization: HMAC <sig>, X-Auth-Timestamp, X-Auth-Nonce):
# sig = hex( HMAC-SHA256(secret, "GET " + path?query + "\n" + ts + "\n" + nonce) )
hmac_headers() { # $1 url -> outputs -H flags (or nothing when unauthed)
  [ -n "$API_TOKEN" ] || return 0
  local url="$1" ts nonce pathq raw sig
  ts="$(date +%s)"
  nonce="$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  pathq="$(printf '%s' "$url" | sed -E 's#^[a-zA-Z][a-zA-Z0-9+.-]*://[^/]+##')"
  raw="GET ${pathq}\n${ts}\n${nonce}"
  sig="$(printf '%b' "$raw" | openssl dgst -sha256 -hmac "$API_TOKEN" -hex 2>/dev/null | sed 's/^.*= //')"
  printf -- '-H "Authorization: HMAC %s" -H "X-Auth-Timestamp: %s" -H "X-Auth-Nonce: %s"' "$sig" "$ts" "$nonce"
}

check_http() { # $1 name, $2 url, expected codes...
  local name="$1" url="$2"; shift 2
  local code
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 $(hmac_headers "$url") "$url" || true)"
  for want in "$@"; do
    if [ "$code" = "$want" ]; then
      PASS=$((PASS+1)); log PASS "$name"
      return 0
    fi
  done
  FAIL=$((FAIL+1)); log FAIL "$name (HTTP $code)"
}

echo "=== Smoke test: PlayTube self-hosted stack ==="
if [ -z "$API_TOKEN" ]; then
  echo "[.!.] no --api-token given; downloader API checks will be skipped (they require auth)"
else
  echo "[.!.] --api-token given; checking HMAC-signed requests"
fi

# -- downloader APIs (only meaningful with a token) -------------------------
if [ -n "$API_TOKEN" ]; then
  # YouTube
  check_http "YouTube /api/videos/search (HMAC)" "$YOUTUBE_API/api/videos/search?query=test&limit=3" 200
  check_http "YouTube /api/video/info (HMAC)"    "$YOUTUBE_API/api/video/info?id=$VIDEO_ID" 200

  echo "[...] YouTube download (first run may download via yt-dlp) ..."
  DL_JSON="$(curl -s --max-time 600 $(hmac_headers "$YOUTUBE_API/api/video/download?id=$VIDEO_ID") "$YOUTUBE_API/api/video/download?id=$VIDEO_ID" || true)"
  if printf '%s' "$DL_JSON" | grep -q '"success":true' && printf '%s' "$DL_JSON" | grep -q '"downloadUrl"'; then
    PASS=$((PASS+1)); log PASS "YouTube /api/video/download (HMAC)"
  else
    FAIL=$((FAIL+1)); log FAIL "YouTube /api/video/download (no success/downloadUrl in response)"
  fi

  # TikTok
  check_http "TikTok /api/tiktok/user (HMAC)"   "$TIKTOK_API/api/tiktok/user?username=$TIKTOK_USER&limit=3" 200
  check_http "TikTok /api/tiktok/search (HMAC)" "$TIKTOK_API/api/tiktok/search?query=cats&limit=3" 200

  if [ -n "$TIKTOK_URL" ]; then
    ENC="$(python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1], safe=""))' "$TIKTOK_URL" 2>/dev/null || printf '%s' "$TIKTOK_URL")"
    check_http "TikTok /api/tiktok/info (HMAC)" "$TIKTOK_API/api/tiktok/info?video_url=$ENC" 200
  else
    SKIP=$((SKIP+1)); log SKIP "TikTok /api/tiktok/info (no --tiktok-url)"
  fi

  # Negative: unauthenticated request must be rejected
  check_http "YouTube rejects unauthenticated request" "$YOUTUBE_API/api/video/info?id=$VIDEO_ID" 401 403
else
  SKIP=$((SKIP+7)); log SKIP "YouTube search/info/download + TikTok user/search (+reject check) (no --api-token)"
fi

# -- public /health probes (no auth) ----------------------------------------
check_http "YouTube /health" "$YOUTUBE_API/health" 200
check_http "TikTok /health"  "$TIKTOK_API/health" 200

# -- MediaMTX: any HTTP status on a stream path proves the server is up -----
MM_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$MEDIAMTX/stream_health_check/index.m3u8" || true)"
if [ -n "$MM_CODE" ]; then
  PASS=$((PASS+1)); log PASS "MediaMTX reachable (HTTP $MM_CODE)"
else
  FAIL=$((FAIL+1)); log FAIL "MediaMTX unreachable"
fi

# -- PlayTube ----------------------------------------------------------------
if [ -n "$PLAYTUBE" ]; then
  check_http "PlayTube front page" "$PLAYTUBE/" 200
else
  SKIP=$((SKIP+1)); log SKIP "PlayTube front page (no --playtube)"
fi

echo "============================================="
echo "Passed: $PASS  Failed: $FAIL  Skipped: $SKIP"
[ "$FAIL" -eq 0 ]