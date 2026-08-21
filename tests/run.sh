#!/usr/bin/env bash
set -euo pipefail

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

bash -n "$root/v2raystore.sh" "$root/install.sh" "$root/update.sh" "$root/dbbackupv2raystore.sh"

while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
done < <(find "$root" -type f -name '*.php' -not -path '*/.git/*' -print0)

grep -Fq '/panel/api/clients/add' "$root/config.php"
grep -Fq '/panel/api/clients/update/' "$root/config.php"
grep -Fq '/panel/api/clients/lastOnline' "$root/settings/proFeatures.php"
grep -Fq '/panel/api/clients/onlines' "$root/settings/proFeatures.php"
grep -Fq '/panel/api/setting/defaultSettings' "$root/config.php"
grep -Fq 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' "$root/bot.php"
grep -Fq "SET \`state\` = 'paid'" "$root/pay/back.php"
grep -Fq 'SCRIPT_VERSION="13.0.0"' "$root/v2raystore.sh"
[ "$(tr -d '[:space:]' < "$root/VERSION")" = "13.0.0" ]

if command -v shellcheck >/dev/null 2>&1; then
    shellcheck -S error "$root/v2raystore.sh" "$root/install.sh" "$root/update.sh" "$root/dbbackupv2raystore.sh"
fi

test_area=$(mktemp -d /tmp/v2raystore-deploy-test.XXXXXX)
trap 'rm -rf "$test_area"' EXIT
source_repo="$test_area/source"
installed="$test_area/www/v2ray-store"
mkdir -p "$source_repo" "$installed"
cp -a "$root/." "$source_repo/"
rm -rf "$source_repo/.git"
git -C "$source_repo" init -q
git -C "$source_repo" config user.email tests@v2raystore.local
git -C "$source_repo" config user.name "V2Ray Store Tests"
git -C "$source_repo" add .
git -C "$source_repo" commit -qm test-source
printf '%s\n' '<?php $botToken = '\''preserve-me'\''; ?>' > "$installed/baseInfo.php"
cp "$installed/baseInfo.php" "$test_area/expected-baseInfo.php"
printf '%s\n' old > "$installed/old-file.txt"

export V2RAYSTORE_BOT_DIR="$installed"
export V2RAYSTORE_PANEL_DIR="$test_area/www/v2ray-store-panel"
export V2RAYSTORE_REPO_URL="$source_repo"
export V2RAYSTORE_BACKUP_DIR="$test_area/backups"
export V2RAYSTORE_CONFIG_DIR="$test_area/config"
export V2RAYSTORE_SKIP_SYSTEM_CONFIG=1
export V2RAYSTORE_SKIP_PREREQUISITE_CHECK=1
source "$root/v2raystore.sh"

install_or_update_bot_files update >/dev/null
cmp -s "$installed/baseInfo.php" "$test_area/expected-baseInfo.php"
[ -f "$installed/VERSION" ]
[ ! -e "$installed/old-file.txt" ]

echo "All static, syntax, installer and 3x-ui contract checks passed."
