#!/bin/bash
# Install the PlayTube nginx site configs on the Azure VM and enable them.
#
# nginx does not read .htaccess, so the pretty-URL rewrites below are what make
# /watch/<id>, /shorts/<id>, /articles, ... reach index.php with ?link1=<page>.
# Without them every pretty URL silently renders the home page.
#
# Usage: run from the repo root on the VM:
#   sudo deploy/azure/config/install-nginx-sites.sh
set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/sites-available"
DST_DIR="/etc/nginx/sites-available"
EN_DIR="/etc/nginx/sites-enabled"
BACKUP_DIR="/var/backups/nginx-$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"

for site in pumba pumba-ssl; do
    if [ -f "$DST_DIR/$site" ]; then
        cp -a "$DST_DIR/$site" "$BACKUP_DIR/$site"
    fi
    install -m 644 "$SRC_DIR/$site" "$DST_DIR/$site"
    ln -sfn "$DST_DIR/$site" "$EN_DIR/$site"
    echo "installed $DST_DIR/$site -> $EN_DIR/$site"
done

if nginx -t; then
    systemctl reload nginx
    echo "backup of previous configs: $BACKUP_DIR"
    echo "nginx reloaded"
else
    echo "nginx config invalid - restoring previous configs" >&2
    for site in pumba pumba-ssl; do
        if [ -f "$BACKUP_DIR/$site" ]; then
            cp -a "$BACKUP_DIR/$site" "$DST_DIR/$site"
        else
            rm -f "$DST_DIR/$site" "$EN_DIR/$site"
        fi
    done
    nginx -t || true
    exit 1
fi
