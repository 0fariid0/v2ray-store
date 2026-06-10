#!/bin/bash

# V2Ray Store - unified install, update and maintenance script

set -o pipefail

BRAND_NAME="V2Ray Store"
BOT_SLUG="v2ray-store"
PANEL_SLUG="v2ray-store-panel"
BOT_DIR="/var/www/html/${BOT_SLUG}"
PANEL_DIR="/var/www/html/${PANEL_SLUG}"
BASE_INFO="${BOT_DIR}/baseInfo.php"
REPO_URL="https://github.com/0fariid0/v2ray-store.git"
RAW_INSTALL_URL="https://raw.githubusercontent.com/0fariid0/v2ray-store/main/v2raystore.sh"
PANEL_ZIP_URL="https://github.com/0fariid0/v2ray-store/releases/latest/download/v2raystore-panel.zip"
BACKUP_DIR="/root/v2raystore_update_backups"
CONFIG_DIR="/root/confv2raystore"
CONFIG_FILE="${CONFIG_DIR}/dbrootv2raystore.txt"
LOCAL_CMD="/usr/local/bin/v2ray-store"
LOG_FILE="/tmp/v2raystore_update.log"
DEFAULT_DB_NAME="v2raystore"

# Legacy identifiers are assembled so the old brand is not displayed anywhere.
LEGACY_PART="wiz"
LEGACY_NAME="${LEGACY_PART}${LEGACY_PART}"
LEGACY_BOT_SLUG="${LEGACY_NAME}xui-timebot"
LEGACY_BOT_DIR="/var/www/html/${LEGACY_BOT_SLUG}"
LEGACY_CONFIG_DIR="/root/conf${LEGACY_NAME}"
LEGACY_CONFIG_FILE="${LEGACY_CONFIG_DIR}/dbroot${LEGACY_NAME}.txt"
LEGACY_MESSAGE_FILE="message${LEGACY_NAME}.php"
LEGACY_BACKUP_FILE="dbbackup${LEGACY_NAME}.sh"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
DIM='\033[0;37m'
NC='\033[0m'

if [ "$(id -u)" -ne 0 ]; then
    echo -e "${YELLOW}Please run as root${NC}"
    exit 1
fi

line() { printf "${CYAN}%s${NC}\n" "────────────────────────────────────────────────────────"; }
section() { echo; printf "${YELLOW}▌${NC} ${WHITE}%s${NC}\n" "$1"; line; }
success() { echo -e "${GREEN}$1${NC}"; }
warning() { echo -e "${YELLOW}$1${NC}"; }
error() { echo -e "${RED}$1${NC}"; }
confirm() { local q="$1" a; read -rp "$q [y/n]: " a; [[ "$a" =~ ^[Yy]$ ]]; }
pause_screen() { echo; read -rp "Press Enter to continue..." _; }

banner() {
    clear
    echo -e "${CYAN}╭────────────────────────────────────────────────────────╮${NC}"
    echo -e "${CYAN}│${NC} ${WHITE}${BRAND_NAME} - Install / Update Center${NC}              ${CYAN}│${NC}"
    echo -e "${CYAN}╰────────────────────────────────────────────────────────╯${NC}"
}

run_step() {
    local title="$1" cmd="$2"
    : > "$LOG_FILE"
    echo -ne " ${YELLOW}⏳${NC} $title ..."
    bash -c "$cmd" >> "$LOG_FILE" 2>&1
    local rc=$?
    if [ "$rc" -eq 0 ]; then
        echo -e "\r ${GREEN}✔${NC} $title"
    else
        echo -e "\r ${RED}✘${NC} $title"
        tail -n 30 "$LOG_FILE" 2>/dev/null
    fi
    return "$rc"
}

apt_recover() {
    export DEBIAN_FRONTEND=noninteractive
    systemctl stop apt-daily.service apt-daily-upgrade.service unattended-upgrades.service >/dev/null 2>&1 || true
    systemctl stop apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1 || true
    if ! pgrep -x 'apt|apt-get|dpkg|unattended-upgr' >/dev/null 2>&1; then
        rm -f /var/lib/apt/lists/lock /var/cache/apt/archives/lock /var/lib/dpkg/lock /var/lib/dpkg/lock-frontend 2>/dev/null || true
    fi
    dpkg --configure -a >/dev/null 2>&1 || true
    apt-get install -f -y >/dev/null 2>&1 || true
}

install_packages() {
    apt_recover
    apt-get update -y >/dev/null 2>&1 || true
    apt-get install -y apache2 mysql-server php libapache2-mod-php php-mbstring php-zip php-gd php-json php-curl php-soap php-ssh2 git wget curl unzip openssl ca-certificates certbot python3-certbot-apache >/dev/null 2>&1
    systemctl enable mysql.service >/dev/null 2>&1 || systemctl enable mariadb >/dev/null 2>&1 || true
    systemctl start mysql.service >/dev/null 2>&1 || systemctl start mariadb >/dev/null 2>&1 || true
    systemctl enable apache2 >/dev/null 2>&1 || true
    systemctl restart apache2 >/dev/null 2>&1 || true
    ufw allow 80 >/dev/null 2>&1 || true
    ufw allow 443 >/dev/null 2>&1 || true
}

backup_path() {
    local path="$1" label="$2"
    [ -e "$path" ] || return 0
    mkdir -p "$BACKUP_DIR"
    local out="${BACKUP_DIR}/${label}.$(date +%Y%m%d-%H%M%S).tar.gz"
    tar -czf "$out" -C "$(dirname "$path")" "$(basename "$path")" 2>/dev/null && success "Backup created: $out"
}

php_var() {
    local var_name="$1"
    [ -f "$BASE_INFO" ] || return 0
    php -r 'error_reporting(0); include "'"$BASE_INFO"'"; $n="'"$var_name"'"; echo isset($$n) ? $$n : "";' 2>/dev/null
}

set_php_string_var() {
    local var_name="$1" var_value="$2" file="${3:-$BASE_INFO}"
    [ -f "$file" ] || return 1
    python3 - "$file" "$var_name" "$var_value" <<'PY'
import re, sys
from pathlib import Path
path = Path(sys.argv[1])
name = sys.argv[2]
value = sys.argv[3].replace('\\', '\\\\').replace("'", "\\'")
text = path.read_text(encoding='utf-8', errors='ignore')
pattern = re.compile(r"\$" + re.escape(name) + r"\s*=\s*(['\"])(.*?)\1\s*;")
replacement = f"${name} = '{value}';"
if pattern.search(text):
    text = pattern.sub(replacement, text, count=1)
else:
    text = text.replace('?>', f"{replacement}\n?>") if '?>' in text else text + "\n" + replacement + "\n"
path.write_text(text, encoding='utf-8')
PY
}

normalize_domain() {
    local domain="$1"
    domain="${domain#http://}"
    domain="${domain#https://}"
    domain="${domain%%/*}"
    domain="${domain%/}"
    echo "$domain" | tr -d '[:space:]'
}

current_domain() {
    local url
    url=$(php_var botUrl)
    echo "$url" | sed -E 's#https?://([^/]+)/?.*#\1#'
}

bot_url_for_domain() { echo "https://${1}/${BOT_SLUG}/"; }

ensure_config_file() {
    mkdir -p "$CONFIG_DIR"
    if [ ! -f "$CONFIG_FILE" ]; then
        local pass
        pass=$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | cut -c1-30)
        cat > "$CONFIG_FILE" <<EOF2
\$user = 'root';
\$pass = '${pass}';
\$path = '${PANEL_SLUG}';
EOF2
        chmod 600 "$CONFIG_FILE"
        mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${pass}'; FLUSH PRIVILEGES;" >/dev/null 2>&1 || true
    fi
}

root_db_user() { grep '\$user' "$CONFIG_FILE" 2>/dev/null | cut -d"'" -f2 | head -n1; }
root_db_pass() { grep '\$pass' "$CONFIG_FILE" 2>/dev/null | cut -d"'" -f2 | head -n1; }

update_panel_db_include() {
    [ -f "$PANEL_DIR/includ/db.php" ] || return 0
    cat > "$PANEL_DIR/includ/db.php" <<'PHP'
<?php
include '../v2ray-store/baseInfo.php';
$servername = "localhost";
$conn = new mysqli($servername, $dbUserName, $dbPassword, $dbName);
$conn->set_charset("utf8mb4");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
PHP
}

clean_legacy_crons() {
    local tmp
    tmp=$(mktemp)
    crontab -l 2>/dev/null > "$tmp" || true
    grep -v "${LEGACY_BOT_SLUG}" "$tmp" | \
    grep -v "conf${LEGACY_NAME}" | \
    grep -v "${LEGACY_BACKUP_FILE}" | \
    grep -v "${LEGACY_MESSAGE_FILE}" | \
    grep -v "${BOT_SLUG}/settings/messagev2raystore.php" | \
    grep -v "${PANEL_SLUG}/backupnutif.php" > "${tmp}.new" || true
    crontab "${tmp}.new" 2>/dev/null || true
    rm -f "$tmp" "${tmp}.new"
}
update_crons_for_domain() {
    local domain="$1"
    [ -z "$domain" ] && return 1
    local tmp
    tmp=$(mktemp)
    crontab -l 2>/dev/null > "$tmp" || true
    grep -v "${BOT_SLUG}/settings/messagev2raystore.php" "$tmp" | \
    grep -v "${BOT_SLUG}/settings/rewardReport.php" | \
    grep -v "${BOT_SLUG}/settings/warnusers.php" | \
    grep -v "${BOT_SLUG}/settings/gift2all.php" | \
    grep -v "${BOT_SLUG}/settings/tronChecker.php" | \
    grep -v "${BOT_SLUG}/settings/reportGroupBackup.php" | \
    grep -v "${PANEL_SLUG}/backupnutif.php" | \
    grep -v "v2raystore" > "${tmp}.new" || true
    {
        cat "${tmp}.new"
        echo "* * * * * curl -fsS https://${domain}/${BOT_SLUG}/settings/messagev2raystore.php >/dev/null 2>&1"
        echo "* * * * * curl -fsS https://${domain}/${BOT_SLUG}/settings/rewardReport.php >/dev/null 2>&1"
        echo "* * * * * curl -fsS https://${domain}/${BOT_SLUG}/settings/warnusers.php >/dev/null 2>&1"
        echo "* * * * * curl -fsS https://${domain}/${BOT_SLUG}/settings/gift2all.php >/dev/null 2>&1"
        echo "*/3 * * * * curl -fsS https://${domain}/${BOT_SLUG}/settings/tronChecker.php >/dev/null 2>&1"
        echo "* * * * * cd ${BOT_DIR} && php settings/reportGroupBackup.php >/dev/null 2>&1"
        echo "* * * * * curl -fsS https://${domain}/${PANEL_SLUG}/backupnutif.php >/dev/null 2>&1"
    } | sort -u | crontab -
    rm -f "$tmp" "${tmp}.new"
}

set_bot_webhook() {
    local token="$1" bot_url="$2" webhook_url response
    webhook_url="${bot_url%/}/bot.php"
    [ -z "$token" ] || [ -z "$bot_url" ] && return 1
    response=$(curl -fsS -X POST "https://api.telegram.org/bot${token}/setWebhook" --data-urlencode "url=${webhook_url}" -d "drop_pending_updates=false" 2>/dev/null || true)
    echo "$response" | grep -q '"ok":true'
}

send_admin_message() {
    local text="$1" token admin
    token=$(php_var botToken)
    admin=$(php_var admin)
    [ -z "$token" ] || [ -z "$admin" ] && return 0
    curl -fsS -X POST "https://api.telegram.org/bot${token}/sendMessage" -d "chat_id=${admin}" --data-urlencode "text=${text}" -d "parse_mode=HTML" >/dev/null 2>&1 || true
}

legacy_installation_exists() {
    [ -d "$LEGACY_BOT_DIR" ] || [ -f "$LEGACY_BOT_DIR/baseInfo.php" ] || [ -f "$LEGACY_CONFIG_FILE" ] && return 0
    return 1
}

legacy_baseinfo_exists() {
    [ -f "$LEGACY_BOT_DIR/baseInfo.php" ]
}

read_legacy_config_value() {
    local var_name="$1"
    [ -f "$LEGACY_CONFIG_FILE" ] || return 0
    grep "\$${var_name}" "$LEGACY_CONFIG_FILE" 2>/dev/null | cut -d"'" -f2 | head -n1
}

write_config_from_values() {
    local user="$1" pass="$2"
    mkdir -p "$CONFIG_DIR"
    [ -z "$user" ] && user="root"
    cat > "$CONFIG_FILE" <<EOF2
\$user = '${user}';
\$pass = '${pass}';
\$path = '${PANEL_SLUG}';
EOF2
    chmod 600 "$CONFIG_FILE" 2>/dev/null || true
}

find_legacy_panel_dir() {
    local path candidate legacy_db_include
    legacy_db_include="${LEGACY_BOT_SLUG}/baseInfo.php"

    if [ -f "$LEGACY_CONFIG_FILE" ]; then
        path=$(read_legacy_config_value path)
        for candidate in "/var/www/html/${path}" "/var/www/html/panel${path}" "/var/www/html/${LEGACY_NAME}panel${path}"; do
            [ -n "$path" ] && [ -f "$candidate/login.php" ] && { echo "$candidate"; return 0; }
        done
    fi

    for candidate in /var/www/html/*; do
        [ -d "$candidate" ] || continue
        [ "$candidate" = "$BOT_DIR" ] && continue
        [ "$candidate" = "$LEGACY_BOT_DIR" ] && continue
        [ "$candidate" = "$PANEL_DIR" ] && continue
        [ -f "$candidate/login.php" ] || continue
        [ -f "$candidate/includ/db.php" ] || continue
        if grep -Rq "$legacy_db_include" "$candidate/includ" "$candidate"/*.php 2>/dev/null; then
            echo "$candidate"
            return 0
        fi
    done

    find /var/www/html -mindepth 1 -maxdepth 1 -type d \
        ! -path "$BOT_DIR" ! -path "$LEGACY_BOT_DIR" ! -path "$PANEL_DIR" \
        -exec test -f '{}/login.php' \; -exec test -f '{}/includ/db.php' \; -print 2>/dev/null | head -n1
}
migrate_legacy_installation() {
    local changed=0 legacy_panel legacy_user legacy_pass legacy_path dom token url
    mkdir -p "$BACKUP_DIR"

    if ! legacy_installation_exists && [ ! -f "$BASE_INFO" ]; then
        return 1
    fi

    if [ -f "$LEGACY_CONFIG_FILE" ]; then
        legacy_user=$(read_legacy_config_value user)
        legacy_pass=$(read_legacy_config_value pass)
        legacy_path=$(read_legacy_config_value path)
        backup_path "$LEGACY_CONFIG_DIR" "legacy-config"
        write_config_from_values "$legacy_user" "$legacy_pass"
        changed=1
    elif [ ! -f "$CONFIG_FILE" ]; then
        ensure_config_file
    fi

    if [ -d "$LEGACY_BOT_DIR" ]; then
        backup_path "$LEGACY_BOT_DIR" "legacy-bot"
        if [ ! -d "$BOT_DIR" ]; then
            mv "$LEGACY_BOT_DIR" "$BOT_DIR"
        else
            [ -f "$LEGACY_BOT_DIR/baseInfo.php" ] && cp -a "$LEGACY_BOT_DIR/baseInfo.php" "$BASE_INFO"
            rm -rf "$LEGACY_BOT_DIR"
        fi
        changed=1
    fi

    legacy_panel=$(find_legacy_panel_dir || true)
    if [ -n "$legacy_panel" ] && [ "$legacy_panel" != "$PANEL_DIR" ]; then
        backup_path "$legacy_panel" "legacy-panel"
        rm -rf "$PANEL_DIR"
        mv "$legacy_panel" "$PANEL_DIR"
        changed=1
    fi

    if [ -f "$BASE_INFO" ]; then
        dom=$(current_domain)
        [ -n "$dom" ] && set_php_string_var botUrl "$(bot_url_for_domain "$dom")"
    fi

    update_panel_db_include
    clean_legacy_crons
    if [ -f "$BASE_INFO" ]; then
        dom=$(current_domain)
        url=$(php_var botUrl)
        token=$(php_var botToken)
        [ -n "$dom" ] && update_crons_for_domain "$dom"
        [ -n "$token" ] && [ -n "$url" ] && set_bot_webhook "$token" "$url" || true
    fi

    if [ -f "$LEGACY_CONFIG_FILE" ] && [ -f "$CONFIG_FILE" ]; then
        rm -rf "$LEGACY_CONFIG_DIR"
    fi

    if [ "$changed" -eq 1 ]; then
        success "Legacy installation was migrated safely to ${BOT_SLUG}/${PANEL_SLUG}."
    fi
    return 0
}
install_or_update_bot_files() {
    install_packages
    mkdir -p /var/www/html
    backup_path "$BOT_DIR" "bot"
    local tmp_dir
    tmp_dir="/tmp/v2raystore_bot_$(date +%s)"
    rm -rf "$tmp_dir"
    run_step "Downloading ${BRAND_NAME} files" "git clone --depth 1 '$REPO_URL' '$tmp_dir'" || { rm -rf "$tmp_dir"; return 1; }
    if [ -f "$BASE_INFO" ]; then
        cp -a "$BASE_INFO" /root/baseInfo.v2raystore.tmp
    fi
    rm -rf "$BOT_DIR"
    mkdir -p "$BOT_DIR"
    cp -a "$tmp_dir/." "$BOT_DIR/"
    [ -f /root/baseInfo.v2raystore.tmp ] && mv /root/baseInfo.v2raystore.tmp "$BASE_INFO"
    rm -rf "$tmp_dir"
    chown -R www-data:www-data "$BOT_DIR/" 2>/dev/null || true
    chmod -R 755 "$BOT_DIR/" 2>/dev/null || true
}

create_database_and_baseinfo() {
    local root_user root_pass dbname dbuser dbpass token admin domain domain_clean bot_url random_user random_pass
    ensure_config_file
    root_user=$(root_db_user)
    root_pass=$(root_db_pass)
    random_user="vs_$(openssl rand -hex 5)"
    random_pass=$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | cut -c1-24)

    read -rp "Bot token: " token
    read -rp "Admin chat ID: " admin
    read -rp "Domain: " domain
    domain_clean=$(normalize_domain "$domain")
    [ -z "$token" ] || [ -z "$admin" ] || [ -z "$domain_clean" ] && { error "Token, admin chat ID and domain are required."; return 1; }

    read -rp "Database name [${DEFAULT_DB_NAME}]: " dbname
    dbname="${dbname:-$DEFAULT_DB_NAME}"
    read -rp "Database username [${random_user}]: " dbuser
    dbuser="${dbuser:-$random_user}"
    read -rp "Database password [${random_pass}]: " dbpass
    dbpass="${dbpass:-$random_pass}"

    mysql -u "$root_user" -p"$root_pass" -e "CREATE DATABASE IF NOT EXISTS \`${dbname}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;" || return 1
    mysql -u "$root_user" -p"$root_pass" -e "CREATE USER IF NOT EXISTS '${dbuser}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${dbpass}'; GRANT ALL PRIVILEGES ON \`${dbname}\`.* TO '${dbuser}'@'localhost'; FLUSH PRIVILEGES;" || return 1
    mysql -u "$root_user" -p"$root_pass" -e "CREATE USER IF NOT EXISTS '${dbuser}'@'%' IDENTIFIED WITH mysql_native_password BY '${dbpass}'; GRANT ALL PRIVILEGES ON \`${dbname}\`.* TO '${dbuser}'@'%'; FLUSH PRIVILEGES;" >/dev/null 2>&1 || true

    bot_url=$(bot_url_for_domain "$domain_clean")
    cat > "$BASE_INFO" <<EOF2
<?php
error_reporting(0);
\$botToken = '${token}';
\$dbUserName = '${dbuser}';
\$dbPassword = '${dbpass}';
\$dbName = '${dbname}';
\$botUrl = '${bot_url}';
\$admin = ${admin};
?>
EOF2
    curl -fsS "${bot_url}createDB.php" >/dev/null 2>&1 || php "${BOT_DIR}/createDB.php" >/dev/null 2>&1 || true
    set_bot_webhook "$token" "$bot_url" || warning "Webhook could not be set automatically. You can repair it from the menu."
    update_crons_for_domain "$domain_clean"
    success "Database and baseInfo.php were created."
}

install_or_update_panel() {
    install_packages
    backup_path "$PANEL_DIR" "panel"
    rm -rf "$PANEL_DIR"
    mkdir -p "$PANEL_DIR"
    run_step "Downloading panel" "wget -O /tmp/v2raystore-panel.zip '$PANEL_ZIP_URL'" || return 1
    unzip -oq /tmp/v2raystore-panel.zip -d "$PANEL_DIR"
    rm -f /tmp/v2raystore-panel.zip
    update_panel_db_include
    chown -R www-data:www-data "$PANEL_DIR/" 2>/dev/null || true
    chmod -R 755 "$PANEL_DIR/" 2>/dev/null || true
    if [ -f "$CONFIG_FILE" ]; then
        python3 - "$CONFIG_FILE" "$PANEL_SLUG" <<'PY'
import re, sys
from pathlib import Path
p = Path(sys.argv[1])
slug = sys.argv[2]
text = p.read_text(errors='ignore')
if re.search(r"\$path\s*=", text):
    text = re.sub(r"\$path\s*=\s*['\"].*?['\"]\s*;", f"$path = '{slug}';", text)
else:
    text += f"\n$path = '{slug}';\n"
p.write_text(text)
PY
    fi
    success "Panel installed/updated at: ${PANEL_DIR}"
}

change_panel_password() {
    [ -f "$BASE_INFO" ] || { error "baseInfo.php not found."; return 1; }
    local dbuser dbpass dbname new_user new_pass
    dbuser=$(php_var dbUserName)
    dbpass=$(php_var dbPassword)
    dbname=$(php_var dbName)
    read -rp "New panel username [admin]: " new_user
    new_user="${new_user:-admin}"
    read -rsp "New panel password: " new_pass
    echo
    [ -z "$new_pass" ] && { error "Password cannot be empty."; return 1; }
    mysql -u "$dbuser" -p"$dbpass" "$dbname" -e "UPDATE admins SET username='${new_user}', password='${new_pass}' WHERE id='1'; INSERT INTO admins (id, username, password, chat_id, backupchannel, lang) SELECT 1, '${new_user}', '${new_pass}', '', '', 'fa' WHERE NOT EXISTS (SELECT 1 FROM admins WHERE id=1);" >/dev/null 2>&1 \
        && success "Panel username/password changed." \
        || error "Could not change panel password. Check database credentials."
}

repair_webhook() {
    local token url
    token=$(php_var botToken)
    url=$(php_var botUrl)
    set_bot_webhook "$token" "$url" && success "Webhook repaired." || error "Webhook repair failed."
}

install_local_command() {
    install -m 0755 "$0" "$LOCAL_CMD" 2>/dev/null || cp "$0" "$LOCAL_CMD"
    chmod +x "$LOCAL_CMD"
    success "Local command installed: v2ray-store"
}

show_status() {
    banner
    section "Status"
    if [ -f "$BASE_INFO" ]; then
        echo -e "Bot path: ${GREEN}${BOT_DIR}${NC}"
        echo -e "Panel path: ${GREEN}${PANEL_DIR}${NC}"
        echo -e "Bot URL: ${GREEN}$(php_var botUrl)${NC}"
        echo -e "Database: ${GREEN}$(php_var dbName)${NC}"
    elif legacy_baseinfo_exists; then
        echo -e "Legacy bot detected."
        echo -e "Action: choose ${GREEN}Install / Update${NC} to move it safely to ${BOT_DIR} without deleting data."
    elif legacy_installation_exists; then
        echo -e "Legacy files detected."
        echo -e "Action: choose ${GREEN}Install / Update${NC} to migrate and update safely."
    else
        warning "Bot is not installed yet."
    fi
}
full_install_or_update() {
    banner
    local had_legacy=0
    legacy_installation_exists && had_legacy=1

    if [ "$had_legacy" -eq 1 ] && [ ! -f "$BASE_INFO" ]; then
        warning "Legacy installation detected. Migrating it first, keeping database and settings..."
        migrate_legacy_installation || { error "Legacy migration failed. Nothing was deleted."; return 1; }
    elif [ "$had_legacy" -eq 1 ]; then
        warning "Legacy folders/config were detected. They will be migrated before update."
        migrate_legacy_installation || { error "Legacy migration failed. Nothing was deleted."; return 1; }
    fi

    if [ -f "$BASE_INFO" ]; then
        if [ "$had_legacy" -eq 0 ]; then
            confirm "Existing installation found. Update ${BRAND_NAME} now?" || return 0
        else
            warning "Migration finished. Updating files now..."
        fi
        install_or_update_bot_files || return 1
        migrate_legacy_installation || true
        install_or_update_panel || true
        local dom token url
        dom=$(current_domain)
        url=$(php_var botUrl)
        token=$(php_var botToken)
        [ -n "$dom" ] && update_crons_for_domain "$dom"
        [ -n "$token" ] && [ -n "$url" ] && set_bot_webhook "$token" "$url" || true
        send_admin_message "✅ ${BRAND_NAME} با موفقیت آپدیت و منتقل شد."
        success "Update/migration finished. Your database and baseInfo.php were preserved."
    else
        confirm "No installation found. Install ${BRAND_NAME} now?" || return 0
        install_or_update_bot_files || return 1
        create_database_and_baseinfo || return 1
        install_or_update_panel || true
        send_admin_message "✅ ${BRAND_NAME} با موفقیت نصب شد."
        success "Installation finished."
    fi
}
main_menu() {
    while true; do
        show_status
        section "Menu"
        options=(
            "Install / Update"
            "Update panel"
            "Change panel username/password"
            "Repair webhook"
            "Install local command"
            "Exit"
        )
        PS3="Please select action: "
        select opt in "${options[@]}"; do
            case "$opt" in
                "Install / Update") full_install_or_update; pause_screen; break ;;
                "Update panel") install_or_update_panel; pause_screen; break ;;
                "Change panel username/password") change_panel_password; pause_screen; break ;;
                "Repair webhook") repair_webhook; pause_screen; break ;;
                "Install local command") install_local_command; pause_screen; break ;;
                "Exit") exit 0 ;;
                *) error "Invalid option." ;;
            esac
        done
    done
}
case "${1:-menu}" in
    menu) main_menu ;;
    install|update) full_install_or_update ;;
    migrate) migrate_legacy_installation ;;
    panel) install_or_update_panel ;;
    password|panel-password) change_panel_password ;;
    webhook) repair_webhook ;;
    status) show_status ;;
    help|-h|--help)
        echo "${BRAND_NAME}"
        echo "Install/update command: bash <(curl -s ${RAW_INSTALL_URL})"
        ;;
    *) main_menu ;;
esac
