#!/usr/bin/env bash
# Bootstraps the PlayTube database on Azure Database for MySQL Flexible Server
# and applies the values needed for the Azure container deployment.
#
# Usage (Azure Cloud Shell, or any bash with mysql + envsubst):
#   export MYSQL_HOST=mysql-storegrill-free.mysql.database.azure.com
#   export MYSQL_ADMIN=playtube_admin
#   export MYSQL_ADMIN_PASSWORD=...
#   export SITE_URL=https://playtube-storegrill-free.azurewebsites.net
#   export YT_API_URL=https://yt-api-storegrill-free.azurewebsites.net
#   export TT_API_URL=https://tt-api-storegrill-free.azurewebsites.net
#   export YT_API_TOKEN=... TT_API_TOKEN=...          # must equal each API's API_TOKEN
#   export AZURE_STORAGE_ACCOUNT=ststoregrillfree
#   export AZURE_STORAGE_ACCOUNT_KEY='<base64 account key>'
#   ./scripts/bootstrap-db.sh playtube.sql
#
# The account key is the raw base64 string from
#   az storage account keys list -n "$AZURE_STORAGE_ACCOUNT" --query keys[0].value -o tsv
set -euo pipefail

SQL_FILE="${1:-playtube.sql}"
[ -f "$SQL_FILE" ] || { echo "missing SQL file: $SQL_FILE" >&2; exit 1; }

for v in MYSQL_HOST MYSQL_ADMIN MYSQL_ADMIN_PASSWORD SITE_URL YT_API_URL TT_API_URL YT_API_TOKEN TT_API_TOKEN AZURE_STORAGE_ACCOUNT AZURE_STORAGE_ACCOUNT_KEY; do
  [ -n "${!v:-}" ] || { echo "missing env var: $v" >&2; exit 1; }
done

DB="playtube"
MYSQL=(mysql -h "$MYSQL_HOST" -u "$MYSQL_ADMIN" -p"$MYSQL_ADMIN_PASSWORD" --ssl-mode=REQUIRED)

"${MYSQL[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
echo "database $DB ready"

"${MYSQL[@]}" "$DB" < "$SQL_FILE"
echo "schema + seed rows imported"

export AZURE_STORAGE_ENDPOINT="https://${AZURE_STORAGE_ACCOUNT}.blob.core.windows.net"
cat <<'SQL' | envsubst | "${MYSQL[@]}" "$DB"
UPDATE \`config\` SET \`value\` = '${SITE_URL}' WHERE \`name\` = 'site_url';
UPDATE \`config\` SET \`value\` = '${YT_API_URL}' WHERE \`name\` = 'yt_api_url';
UPDATE \`config\` SET \`value\` = '${YT_API_TOKEN}' WHERE \`name\` = 'yt_api_token';
UPDATE \`config\` SET \`value\` = '${TT_API_URL}' WHERE \`name\` = 'tiktok_api_url';
UPDATE \`config\` SET \`value\` = '${TT_API_TOKEN}' WHERE \`name\` = 'tiktok_api_token';
UPDATE \`config\` SET \`value\` = 'on' WHERE \`name\` = 'azure_blob_storage';
UPDATE \`config\` SET \`value\` = '${AZURE_STORAGE_ACCOUNT}' WHERE \`name\` = 'azure_blob_account';
UPDATE \`config\` SET \`value\` = '${AZURE_STORAGE_ACCOUNT_KEY}' WHERE \`name\` = 'azure_blob_secret';
UPDATE \`config\` SET \`value\` = 'media' WHERE \`name\` = 'azure_blob_bucket_name';
UPDATE \`config\` SET \`value\` = '${AZURE_STORAGE_ENDPOINT}' WHERE \`name\` = 'azure_blob_endpoint';
UPDATE \`config\` SET \`value\` = '2' WHERE \`name\` = 'queue_count';
UPDATE \`config\` SET \`value\` = '60' WHERE \`name\` = 'import_worker_interval';
SQL
echo "Azure-specific config applied (site_url, API URLs/tokens, blob, queue_count=2)"