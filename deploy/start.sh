#!/usr/bin/env bash
# Start PlayTube's self-hosted services (Linux)
# Usage: ./deploy/start.sh --youtube-dir /opt/youtube-api --tiktok-dir /opt/tiktok-api \
#        --mediamtx /usr/local/bin/mediamtx --mediamtx-config /etc/mediamtx.yml
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="$ROOT/deploy/logs"
mkdir -p "$LOG_DIR"

YOUTUBE_DIR=""
TIKTOK_DIR=""
MEDIAMTX=""
MEDIAMTX_CONFIG=""
YOUTUBE_PORT=3000
TIKTOK_PORT=3001
RUN_PLAY=0

usage() {
  echo "Usage: $0 [--youtube-dir DIR] [--tiktok-dir DIR] [--mediamtx BIN] [--mediamtx-config FILE] [--youtube-port N] [--tiktok-port N] [--playtube]"
  exit 1
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --youtube-dir) YOUTUBE_DIR="$2"; shift 2;;
    --tiktok-dir)  TIKTOK_DIR="$2"; shift 2;;
    --mediamtx)    MEDIAMTX="$2"; shift 2;;
    --mediamtx-config) MEDIAMTX_CONFIG="$2"; shift 2;;
    --youtube-port) YOUTUBE_PORT="$2"; shift 2;;
    --tiktok-port)  TIKTOK_PORT="$2"; shift 2;;
    --playtube)     RUN_PLAY=1; shift;;
    -h|--help) usage;;
    *) echo "Unknown option: $1"; usage;;
  esac
done

PIDS=()
cleanup() {
  echo "[$(date +%T)] Stopping services..."
  for pid in "${PIDS[@]:-}"; do kill "$pid" 2>/dev/null || true; done
  wait 2>/dev/null || true
  echo "[$(date +%T)] Done."
}
trap cleanup EXIT INT TERM

run() {
  local name="$1"; shift
  echo "[$(date +%T)] Starting $name: $*"
  "$@" > "$LOG_DIR/$name.log" 2>&1 &
  PIDS+=($!)
}

if [ -n "$YOUTUBE_DIR" ]; then
  [ -f "$YOUTUBE_DIR/app.js" ] || { echo "youtube app.js not found in $YOUTUBE_DIR" >&2; exit 1; }
  ( cd "$YOUTUBE_DIR" && PORT=$YOUTUBE_PORT run youtube-api node app.js )
else
  echo "Skipping YouTube API (no --youtube-dir)."
fi

if [ -n "$TIKTOK_DIR" ]; then
  [ -f "$TIKTOK_DIR/app.js" ] || { echo "tiktok app.js not found in $TIKTOK_DIR" >&2; exit 1; }
  ( cd "$TIKTOK_DIR" && PORT=$TIKTOK_PORT run tiktok-api node app.js )
else
  echo "Skipping TikTok API (no --tiktok-dir)."
fi

if [ -n "$MEDIAMTX" ]; then
  [ -x "$MEDIAMTX" ] || { echo "mediamtx binary not found/executable at $MEDIAMTX" >&2; exit 1; }
  if [ -n "$MEDIAMTX_CONFIG" ]; then
    run mediamtx "$MEDIAMTX" --config "$MEDIAMTX_CONFIG"
  else
    run mediamtx "$MEDIAMTX"
  fi
else
  echo "Skipping MediaMTX (no --mediamtx)."
fi

if [ "$RUN_PLAY" = "1" ]; then
  run playtube php -S 127.0.0.1:8000 -t "$ROOT"
fi

if [ "${#PIDS[@]}" -eq 0 ]; then
  echo "Nothing to start. Provide at least one service." >&2; exit 1
fi

echo "[$(date +%T)] Services launched. Logs in $LOG_DIR. Ctrl+C to stop."
wait