#!/bin/bash
# Apply PlayTube pretty-URL nginx config on every container boot.
set -u
SRC=/home/site/wwwroot/nginx-pt.conf
DST=/etc/nginx/sites-available/default
LOG=/home/site/wwwroot/nginx-fix.log
echo "=== $(date -u +%FT%TZ) start ===" >> "$LOG"
if [ -f "$SRC" ]; then
  cp -f "$SRC" "$DST" >> "$LOG" 2>&1
  if nginx -t >> "$LOG" 2>&1; then
    if pgrep -x nginx >/dev/null 2>&1; then
      service nginx reload >> "$LOG" 2>&1 || true
    else
      service nginx start >> "$LOG" 2>&1 || true
    fi
  else
    echo "nginx config invalid - keeping previous config" >> "$LOG"
    cp -f "$DST" "$SRC.bad" >> "$LOG" 2>&1 || true
  fi
else
  echo "missing $SRC" >> "$LOG"
fi
if pgrep -x nginx >/dev/null 2>&1; then
  echo "nginx: RUNNING" >> "$LOG"
else
  echo "nginx: NOT RUNNING" >> "$LOG"
  service nginx start >> "$LOG" 2>&1 || true
fi
echo "=== done ===" >> "$LOG"
true