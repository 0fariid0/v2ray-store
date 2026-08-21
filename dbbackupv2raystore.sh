#!/usr/bin/env bash
set -euo pipefail

base_info="/var/www/html/v2ray-store/baseInfo.php"
[ -r "$base_info" ] || { echo "baseInfo.php is missing" >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo "php is missing" >&2; exit 1; }
command -v mysqldump >/dev/null 2>&1 || { echo "mysqldump is missing" >&2; exit 1; }
command -v curl >/dev/null 2>&1 || { echo "curl is missing" >&2; exit 1; }

read_php_var() {
    php -r 'error_reporting(0); require $argv[1]; $name=$argv[2]; echo isset($$name) ? (string)$$name : "";' "$base_info" "$1"
}

bot_token=$(read_php_var botToken)
chat_id=$(read_php_var admin)
db_user=$(read_php_var dbUserName)
db_pass=$(read_php_var dbPassword)
db_name=$(read_php_var dbName)

[ -n "$bot_token" ] && [ -n "$chat_id" ] && [ -n "$db_user" ] && [ -n "$db_name" ] || {
    echo "Backup configuration is incomplete" >&2
    exit 1
}

backup_dir=$(mktemp -d /tmp/v2raystore-db-backup.XXXXXX)
trap 'rm -rf "$backup_dir"' EXIT
backup_file="${backup_dir}/v2raystore_$(date +'%Y-%m-%d_%H-%M-%S').sql.gz"

MYSQL_PWD="$db_pass" mysqldump \
    --single-transaction --quick --routines --events \
    --default-character-set=utf8mb4 \
    -u "$db_user" "$db_name" | gzip -9 > "$backup_file"
chmod 600 "$backup_file"

curl --fail --silent --show-error --retry 3 --connect-timeout 15 --max-time 180 \
    -F "chat_id=${chat_id}" \
    -F "document=@${backup_file}" \
    "https://api.telegram.org/bot${bot_token}/sendDocument" >/dev/null

echo "Database backup sent successfully."

