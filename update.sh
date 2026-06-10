#!/bin/bash

# WizWiz XUI TimeBot - Professional Update & Maintenance Script
# Inspired by modern installer dashboards: visible status, diagnostics, repair tools,
# token/domain management, webhook/SSL checks and safer updates.

set -o pipefail

BOT_DIR="/var/www/html/wizwizxui-timebot"
BASE_INFO="$BOT_DIR/baseInfo.php"
REPO_URL="https://github.com/0fariid0/wizwizxui-timebot.git"
RAW_UPDATE_URL="https://raw.githubusercontent.com/0fariid0/wizwizxui-timebot/main/update.sh"
PANEL_ZIP_URL="https://github.com/0fariid0/wizwizxui-timebot/releases/download/10.2.1/wizwizpanel.zip"
BACKUP_DIR="/root/wizwiz_update_backups"
LOG_FILE="/tmp/wizwiz_update.log"
LOCAL_CMD="/usr/local/bin/wizwiz-update"

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

# ─────────────────────────────────────────────────────────────
# UI helpers
# ─────────────────────────────────────────────────────────────
line() { printf "${CYAN}%s${NC}\n" "────────────────────────────────────────────────────────"; }
dot() {
    case "$1" in
        ok) printf "${GREEN}●${NC}" ;;
        warn) printf "${YELLOW}●${NC}" ;;
        bad) printf "${RED}●${NC}" ;;
        *) printf "${DIM}●${NC}" ;;
    esac
}
section() { echo; printf "${YELLOW}▌${NC} ${WHITE}%s${NC}\n" "$1"; line; }
kv() { printf " ${DIM}%-17s${NC}: %b${NC}\n" "$1" "$2"; }
success() { echo -e "${GREEN}$1${NC}"; }
warning() { echo -e "${YELLOW}$1${NC}"; }
error() { echo -e "${RED}$1${NC}"; }
pause_screen() { echo; read -rp "Press Enter to continue..." _; }
confirm() { local q="$1" a; read -rp "$q [y/n]: " a; [[ "$a" =~ ^[Yy]$ ]]; }

banner() {
    clear
    echo -e "${CYAN}╭────────────────────────────────────────────────────────╮${NC}"
    echo -e "${CYAN}│${NC} ${WHITE}WizWiz XUI TimeBot - Update & Maintenance Center${NC} ${CYAN}│${NC}"
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
        echo -e "${RED}Last log lines:${NC}"
        tail -n 25 "$LOG_FILE" 2>/dev/null
    fi
    return "$rc"
}

# ─────────────────────────────────────────────────────────────
# Generic helpers
# ─────────────────────────────────────────────────────────────
need_bot_installation() {
    if [ ! -d "$BOT_DIR" ] || [ ! -f "$BASE_INFO" ]; then
        error "Bot installation was not found at: $BOT_DIR"
        error "baseInfo.php was not found. Please install the bot first."
        return 1
    fi
    return 0
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

install_basic_packages() {
    apt_recover
    apt-get update -y >/dev/null 2>&1 || true
    apt-get install -y curl wget git unzip ca-certificates python3 openssl lsb-release >/dev/null 2>&1
}

install_ssl_packages() {
    apt_recover
    apt-get update -y >/dev/null 2>&1 || true
    apt-get install -y curl certbot python3-certbot-apache openssl >/dev/null 2>&1
}

backup_file() {
    local file="$1" label="$2"
    mkdir -p "$BACKUP_DIR"
    [ -f "$file" ] || return 0
    local backup_file="$BACKUP_DIR/${label}.$(date +%Y%m%d-%H%M%S).bak"
    cp -a "$file" "$backup_file"
    success "Backup created: $backup_file"
}

backup_base_info() { backup_file "$BASE_INFO" "baseInfo.php"; }

backup_full_bot() {
    mkdir -p "$BACKUP_DIR"
    [ -d "$BOT_DIR" ] || return 0
    local f="$BACKUP_DIR/wizwizxui-timebot.$(date +%Y%m%d-%H%M%S).tar.gz"
    tar -czf "$f" -C "$(dirname "$BOT_DIR")" "$(basename "$BOT_DIR")" 2>/dev/null && success "Full backup created: $f"
}

php_var() {
    local var_name="$1"
    [ -f "$BASE_INFO" ] || return 0
    php -r 'error_reporting(0); include "'"$BASE_INFO"'"; $n="'"$var_name"'"; echo isset($$n) ? $$n : "";' 2>/dev/null
}

set_php_string_var() {
    local var_name="$1" var_value="$2"
    [ -f "$BASE_INFO" ] || return 1
    python3 - "$BASE_INFO" "$var_name" "$var_value" <<'PY'
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

normalize_bot_url() { echo "https://$1/wizwizxui-timebot/"; }

current_domain() {
    local url
    url=$(php_var botUrl)
    echo "$url" | sed -E 's#https?://([^/]+)/?.*#\1#'
}

validate_domain() { [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]]; }

get_server_ip() {
    local ip
    ip=$(curl -fsSL --max-time 4 https://api.ipify.org 2>/dev/null)
    [ -z "$ip" ] && ip=$(curl -fsSL --max-time 4 ifconfig.me 2>/dev/null)
    [ -z "$ip" ] && ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    [ -z "$ip" ] && ip="n/a"
    echo "$ip"
}

domain_points_here() {
    local dom="$1" server_ip resolved
    server_ip=$(get_server_ip)
    resolved=$(getent ahostsv4 "$dom" 2>/dev/null | awk '{print $1; exit}')
    [ -z "$resolved" ] && return 2
    [ "$resolved" = "$server_ip" ] && return 0
    return 1
}

validate_token() {
    local token="$1"
    [[ "$token" =~ ^[0-9]{8,12}:[A-Za-z0-9_-]{30,}$ ]] || return 1
    curl -fsS --connect-timeout 15 "https://api.telegram.org/bot${token}/getMe" 2>/dev/null | grep -q '"ok":true'
}

telegram_send_message() {
    local token="$1" chat_id="$2" text="$3"
    [ -z "$token" ] || [ -z "$chat_id" ] && return 1
    curl -fsS -X POST "https://api.telegram.org/bot${token}/sendMessage" \
        -d "chat_id=${chat_id}" \
        --data-urlencode "text=${text}" \
        -d "parse_mode=HTML" >/dev/null 2>&1
}

json_value() {
    local json="$1" key="$2"
    if command -v php >/dev/null 2>&1; then
        php -r '$j=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; $v=$j; foreach(explode(".",$k) as $p){ if(!is_array($v) || !array_key_exists($p,$v)){ exit; } $v=$v[$p]; } if(is_bool($v)) echo $v?"true":"false"; elseif(is_array($v)) echo json_encode($v, JSON_UNESCAPED_UNICODE); else echo $v;' "$key" <<< "$json" 2>/dev/null
    fi
}

set_bot_webhook() {
    local token="$1" bot_url="$2" webhook_url response
    webhook_url="${bot_url%/}/bot.php"
    if [ -z "$token" ] || [ -z "$bot_url" ]; then
        error "Token or bot URL is empty."
        return 1
    fi
    echo -e "${BLUE}Setting webhook:${NC} $webhook_url"
    response=$(curl -fsS -X POST "https://api.telegram.org/bot${token}/setWebhook" \
        --data-urlencode "url=${webhook_url}" \
        -d "drop_pending_updates=false" 2>/dev/null)
    if echo "$response" | grep -q '"ok":true'; then
        success "Webhook was set successfully."
        return 0
    fi
    error "Webhook setup failed. Telegram response:"
    echo "$response"
    return 1
}

show_webhook_raw() {
    need_bot_installation || return 1
    local token
    token=$(php_var botToken)
    [ -z "$token" ] && { error "Bot token is empty."; return 1; }
    line
    curl -s "https://api.telegram.org/bot${token}/getWebhookInfo"
    echo
    line
}

# ─────────────────────────────────────────────────────────────
# Dashboard and diagnostics
# ─────────────────────────────────────────────────────────────
ssl_days_left() {
    local dom="$1" cert="/etc/letsencrypt/live/${dom}/cert.pem" expiry
    [ -f "$cert" ] || return 1
    expiry=$(openssl x509 -enddate -noout -in "$cert" 2>/dev/null | cut -d= -f2)
    [ -z "$expiry" ] && return 1
    echo $(( ( $(date -d "$expiry" +%s 2>/dev/null || echo 0) - $(date +%s) ) / 86400 ))
}

cron_count() {
    crontab -l 2>/dev/null | grep -c "wizwizxui-timebot" 2>/dev/null || echo 0
}

show_dashboard() {
    banner
    local installed token bot_url dom phpv apache_s mysql_s mem_t mem_u disk load ip crons version
    installed="no"
    [ -f "$BASE_INFO" ] && installed="yes"

    section "Bot Status"
    if [ "$installed" = "yes" ]; then
        token=$(php_var botToken)
        bot_url=$(php_var botUrl)
        dom=$(current_domain)
        version=""
        [ -f "$BOT_DIR/version" ] && version=$(tr -d ' \t\r\n' < "$BOT_DIR/version")
        kv "State" "$(dot ok) ${GREEN}installed${NC}"
        kv "Path" "${DIM}${BOT_DIR}${NC}"
        kv "Bot URL" "${DIM}${bot_url:-not set}${NC}"
        kv "Domain" "${DIM}${dom:-not detected}${NC}"
        [ -n "$version" ] && kv "Version" "${DIM}${version}${NC}"
    else
        kv "State" "$(dot bad) ${RED}not installed${NC}"
        kv "Path" "${DIM}${BOT_DIR}${NC}"
    fi

    section "System"
    phpv=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo "n/a")
    apache_s=$(systemctl is-active apache2 2>/dev/null || echo "inactive")
    mysql_s=$(systemctl is-active mysql 2>/dev/null || systemctl is-active mariadb 2>/dev/null || echo "inactive")
    ip=$(get_server_ip)
    kv "PHP" "${DIM}${phpv}${NC}"
    [ "$apache_s" = "active" ] && kv "Apache" "$(dot ok) ${GREEN}active${NC}" || kv "Apache" "$(dot bad) ${RED}${apache_s}${NC}"
    [ "$mysql_s" = "active" ] && kv "MySQL/MariaDB" "$(dot ok) ${GREEN}active${NC}" || kv "MySQL/MariaDB" "$(dot bad) ${RED}${mysql_s}${NC}"
    kv "Server IP" "${DIM}${ip}${NC}"

    section "SSL / Cron / Webhook"
    if [ "$installed" = "yes" ] && [ -n "$dom" ]; then
        local days
        days=$(ssl_days_left "$dom" 2>/dev/null || true)
        if [ -n "$days" ]; then
            if [ "$days" -gt 14 ]; then kv "SSL" "$(dot ok) ${GREEN}${days} days left${NC}"
            elif [ "$days" -gt 0 ]; then kv "SSL" "$(dot warn) ${YELLOW}${days} days left${NC}"
            else kv "SSL" "$(dot bad) ${RED}expired${NC}"; fi
        else
            kv "SSL" "$(dot warn) ${YELLOW}certificate not found${NC}"
        fi
    else
        kv "SSL" "$(dot warn) ${DIM}n/a${NC}"
    fi
    crons=$(cron_count)
    [ "$crons" -gt 0 ] 2>/dev/null && kv "Cron jobs" "$(dot ok) ${GREEN}${crons} found${NC}" || kv "Cron jobs" "$(dot warn) ${YELLOW}not found${NC}"

    if [ "$installed" = "yes" ] && [ -n "$token" ]; then
        local info ok url pending err
        info=$(curl -fsSL --max-time 8 "https://api.telegram.org/bot${token}/getWebhookInfo" 2>/dev/null)
        ok=$(json_value "$info" "ok")
        url=$(json_value "$info" "result.url")
        pending=$(json_value "$info" "result.pending_update_count")
        err=$(json_value "$info" "result.last_error_message")
        if [ "$ok" = "true" ] && [ -n "$url" ]; then
            kv "Webhook" "$(dot ok) ${GREEN}set${NC} ${DIM}(${pending:-0} pending)${NC}"
            [ -n "$err" ] && kv "Webhook err" "$(dot bad) ${RED}${err}${NC}"
        else
            kv "Webhook" "$(dot bad) ${RED}not set / unreachable${NC}"
        fi
    else
        kv "Webhook" "$(dot warn) ${DIM}n/a${NC}"
    fi

    section "Resources"
    mem_t=$(free -m 2>/dev/null | awk '/^Mem:/{print $2}')
    mem_u=$(free -m 2>/dev/null | awk '/^Mem:/{print $3}')
    disk=$(df -h / 2>/dev/null | awk 'NR==2{print $3" / "$2" ("$5")"}')
    load=$(awk '{print $1", "$2", "$3}' /proc/loadavg 2>/dev/null)
    kv "RAM" "${DIM}${mem_u:-0}MB / ${mem_t:-0}MB${NC}"
    kv "Disk" "${DIM}${disk:-n/a}${NC}"
    kv "CPU load" "${DIM}${load:-n/a}${NC}"
}

run_diagnostics() {
    banner
    section "Diagnostics"
    local ok=1 dom token bot_url free_mb
    if [ -f "$BASE_INFO" ]; then kv "baseInfo.php" "$(dot ok) ${GREEN}found${NC}"; else kv "baseInfo.php" "$(dot bad) ${RED}missing${NC}"; ok=0; fi
    if command -v php >/dev/null 2>&1; then kv "PHP binary" "$(dot ok) ${GREEN}found${NC}"; else kv "PHP binary" "$(dot bad) ${RED}missing${NC}"; ok=0; fi
    if curl -fsSL --max-time 8 https://api.telegram.org >/dev/null 2>&1; then kv "Telegram API" "$(dot ok) ${GREEN}reachable${NC}"; else kv "Telegram API" "$(dot bad) ${RED}unreachable${NC}"; ok=0; fi
    if curl -fsSL --max-time 8 https://github.com >/dev/null 2>&1; then kv "GitHub" "$(dot ok) ${GREEN}reachable${NC}"; else kv "GitHub" "$(dot bad) ${RED}unreachable${NC}"; fi
    free_mb=$(df -Pm / 2>/dev/null | awk 'NR==2{print $4}')
    [ "${free_mb:-0}" -ge 1024 ] && kv "Disk free" "$(dot ok) ${GREEN}${free_mb} MB${NC}" || kv "Disk free" "$(dot warn) ${YELLOW}${free_mb:-0} MB${NC}"

    if [ -f "$BASE_INFO" ]; then
        token=$(php_var botToken)
        bot_url=$(php_var botUrl)
        dom=$(current_domain)
        [ -n "$token" ] && validate_token "$token" && kv "Bot token" "$(dot ok) ${GREEN}valid${NC}" || kv "Bot token" "$(dot bad) ${RED}invalid or unreachable${NC}"
        [ -n "$dom" ] && validate_domain "$dom" && kv "Domain format" "$(dot ok) ${GREEN}${dom}${NC}" || kv "Domain format" "$(dot warn) ${YELLOW}${dom:-not detected}${NC}"
        if [ -n "$dom" ]; then
            domain_points_here "$dom"
            case $? in
                0) kv "Domain DNS" "$(dot ok) ${GREEN}points to this server${NC}" ;;
                1) kv "Domain DNS" "$(dot warn) ${YELLOW}does not point to this server${NC}" ;;
                2) kv "Domain DNS" "$(dot bad) ${RED}cannot resolve${NC}" ;;
            esac
        fi
        [ -n "$bot_url" ] && kv "Webhook target" "${DIM}${bot_url%/}/bot.php${NC}"
    fi
    echo
    [ "$ok" -eq 1 ] && success "Diagnostics finished." || warning "Diagnostics found important issues. Use Quick Repair from the menu."
}

# ─────────────────────────────────────────────────────────────
# Update / panel / backup
# ─────────────────────────────────────────────────────────────
clean_bot_after_update() {
    rm -rf "$BOT_DIR/webpanel" "$BOT_DIR/install" 2>/dev/null || true
    rm -f "$BOT_DIR/createDB.php" \
          "$BOT_DIR/updateShareConfig.php" \
          "$BOT_DIR/README.md" \
          "$BOT_DIR/README-fa.md" \
          "$BOT_DIR/LICENSE" \
          "$BOT_DIR/update.sh" \
          "$BOT_DIR/wizwiz.sh" \
          "$BOT_DIR/tempCookie.txt" \
          "$BOT_DIR/settings/messagewizwiz.json" 2>/dev/null || true
}

run_update_bot() {
    echo
    confirm "Are you sure you want to update the bot?" || { warning "Update canceled."; return; }
    need_bot_installation || return 1
    install_basic_packages
    backup_full_bot
    backup_base_info

    local old_token old_admin old_bot_url db_name db_user db_pass tmp_dir
    old_token=$(php_var botToken)
    old_admin=$(php_var admin)
    old_bot_url=$(php_var botUrl)
    db_name=$(php_var dbName)
    db_user=$(php_var dbUserName)
    db_pass=$(php_var dbPassword)
    tmp_dir="/tmp/wizwiz_update_$(date +%s)"
    rm -rf "$tmp_dir"

    run_step "Downloading latest bot files" "git clone --depth 1 '$REPO_URL' '$tmp_dir'" || { rm -rf "$tmp_dir"; return 1; }
    cp "$BASE_INFO" /root/baseInfo.php.wizwiz.tmp
    rm -rf "$BOT_DIR"
    mkdir -p "$BOT_DIR"
    cp -a "$tmp_dir/." "$BOT_DIR/"
    mv /root/baseInfo.php.wizwiz.tmp "$BASE_INFO"
    rm -rf "$tmp_dir"

    chown -R www-data:www-data "$BOT_DIR/" 2>/dev/null || true
    chmod -R 755 "$BOT_DIR/" 2>/dev/null || true

    if [ -n "$old_bot_url" ]; then
        curl -fsS "${old_bot_url%/}/install/install.php?updateBot" >/dev/null 2>&1 || true
    fi

    clean_bot_after_update
    set_bot_webhook "$old_token" "$old_bot_url" || true

    local message
    message="✅ ربات WizWiz با موفقیت آپدیت شد.

🔻 ادمین: <code>${old_admin}</code>
🔹 نام دیتابیس: <code>${db_name}</code>
🔹 یوزر دیتابیس: <code>${db_user}</code>
🔹 پسورد دیتابیس: <code>${db_pass}</code>"
    telegram_send_message "$old_token" "$old_admin" "$message" || true
    success "The bot was successfully updated."
}

run_update_panel() {
    echo
    confirm "Are you sure you want to update the panel?" || { warning "Panel update canceled."; return; }
    need_bot_installation || return 1
    install_basic_packages

    cd /var/www/html/ || return 1
    find . -mindepth 1 -maxdepth 1 ! -name wizwizxui-timebot -type d -exec rm -rf {} \; 2>/dev/null || true
    echo "<!DOCTYPE html><html><head><title>My Website</title></head><body><h1>Hello, world!</h1></body></html>" > /var/www/html/index.html

    local random_code destination_dir token admin message
    random_code=$(LC_CTYPE=C tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 40)
    mkdir -p "/var/www/html/${random_code}"
    run_step "Downloading panel" "wget -O /var/www/html/wizwizpanel.zip '$PANEL_ZIP_URL'" || return 1
    destination_dir="/var/www/html/${random_code}"
    mv /var/www/html/wizwizpanel.zip "$destination_dir/"
    yes | unzip "$destination_dir/wizwizpanel.zip" -d "$destination_dir/" >/dev/null
    rm -f "$destination_dir/wizwizpanel.zip"
    chmod -R 755 "$destination_dir/" 2>/dev/null || true
    chown -R www-data:www-data "$destination_dir/" 2>/dev/null || true

    token=$(php_var botToken)
    admin=$(php_var admin)
    message="🕹 پنل WizWiz با موفقیت آپدیت شد."
    telegram_send_message "$token" "$admin" "$message" || true
    success "Panel updated successfully."
    echo -e "${YELLOW}Panel address:${NC} https://domain.com/${random_code}/login.php"
}

run_backup_setup() {
    install_basic_packages
    cd /root || return 1
    (crontab -l 2>/dev/null; echo "0 * * * * /root/dbbackupwizwiz.sh >/dev/null 2>&1") | sort -u | crontab -
    wget -O /root/dbbackupwizwiz.sh https://raw.githubusercontent.com/0fariid0/wizwizxui-timebot/main/dbbackupwizwiz.sh
    chmod +x /root/dbbackupwizwiz.sh
    /root/dbbackupwizwiz.sh
    success "Backup settings have been completed successfully."
}

# ─────────────────────────────────────────────────────────────
# Domain / token / webhook / SSL
# ─────────────────────────────────────────────────────────────
update_crons_for_domain() {
    local domain="$1" pathsss="" old_cron
    if [ -f /root/confwizwiz/dbrootwizwiz.txt ]; then
        pathsss=$(grep '\$path' /root/confwizwiz/dbrootwizwiz.txt | cut -d"'" -f2 | head -n1)
    fi
    old_cron=$(mktemp)
    crontab -l 2>/dev/null > "$old_cron" || true

    grep -v "wizwizxui-timebot/settings/messagewizwiz.php" "$old_cron" | \
    grep -v "wizwizxui-timebot/settings/rewardReport.php" | \
    grep -v "wizwizxui-timebot/settings/warnusers.php" | \
    grep -v "wizwizxui-timebot/settings/gift2all.php" | \
    grep -v "wizwizxui-timebot/settings/tronChecker.php" | \
    grep -v "wizwizxui-timebot/settings/reportGroupBackup.php" | \
    grep -v "settings/reportGroupBackup.php" | \
    grep -v "backupnutif.php" > "${old_cron}.new" || true

    {
        cat "${old_cron}.new"
        echo "* * * * * curl -fsS https://${domain}/wizwizxui-timebot/settings/messagewizwiz.php >/dev/null 2>&1"
        echo "* * * * * curl -fsS https://${domain}/wizwizxui-timebot/settings/rewardReport.php >/dev/null 2>&1"
        echo "* * * * * curl -fsS https://${domain}/wizwizxui-timebot/settings/warnusers.php >/dev/null 2>&1"
        echo "* * * * * curl -fsS https://${domain}/wizwizxui-timebot/settings/gift2all.php >/dev/null 2>&1"
        echo "*/3 * * * * curl -fsS https://${domain}/wizwizxui-timebot/settings/tronChecker.php >/dev/null 2>&1"
        echo "* * * * * cd ${BOT_DIR} && php settings/reportGroupBackup.php >/dev/null 2>&1"
        [ -n "$pathsss" ] && echo "* * * * * curl -fsS https://${domain}/${pathsss}/backupnutif.php >/dev/null 2>&1"
    } | sort -u | crontab -

    rm -f "$old_cron" "${old_cron}.new"
    success "Cron jobs were updated for the domain."
}

obtain_ssl_for_domain() {
    local domain="$1" email certbot_email_args
    install_ssl_packages
    ufw allow 80 >/dev/null 2>&1 || true
    ufw allow 443 >/dev/null 2>&1 || true
    systemctl enable certbot.timer >/dev/null 2>&1 || true
    systemctl start certbot.timer >/dev/null 2>&1 || true

    read -rp "Email for Let's Encrypt notices (optional): " email
    if [ -n "$email" ]; then certbot_email_args=(--email "$email" --no-eff-email); else certbot_email_args=(--register-unsafely-without-email); fi

    certbot --apache --agree-tos --redirect --preferred-challenges http -d "$domain" "${certbot_email_args[@]}"
}

change_bot_domain() {
    need_bot_installation || return 1
    local current_url old_domain new_domain bot_url token admin
    current_url=$(php_var botUrl)
    old_domain=$(current_domain)
    echo -e "Current bot URL: ${YELLOW}${current_url}${NC}"
    read -rp "Enter new domain (example.com): " new_domain
    new_domain=$(normalize_domain "$new_domain")

    if ! validate_domain "$new_domain"; then error "Invalid domain format."; return 1; fi
    domain_points_here "$new_domain"
    case $? in
        1) warning "Warning: this domain does not point to this server IP ($(get_server_ip))." ;;
        2) warning "Warning: this domain could not be resolved yet." ;;
    esac

    echo -e "New domain: ${YELLOW}${new_domain}${NC}"
    confirm "Continue, issue/repair SSL, update cron jobs and reset webhook?" || { warning "Canceled."; return; }

    if ! obtain_ssl_for_domain "$new_domain"; then
        error "SSL setup failed. baseInfo.php was not changed."
        return 1
    fi

    backup_base_info
    bot_url=$(normalize_bot_url "$new_domain")
    set_php_string_var botUrl "$bot_url"
    update_crons_for_domain "$new_domain"
    token=$(php_var botToken)
    admin=$(php_var admin)
    set_bot_webhook "$token" "$bot_url"
    systemctl reload apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1 || true
    telegram_send_message "$token" "$admin" "✅ دامنه ربات با موفقیت تغییر کرد.\n\n🌐 دامنه قبلی: ${old_domain}\n🌐 دامنه جدید: ${new_domain}" || true
    success "Domain changed successfully."
    echo -e "${YELLOW}New bot URL:${NC} ${bot_url}"
}

change_bot_token() {
    need_bot_installation || return 1
    local old_token new_token bot_url admin bot_username
    old_token=$(php_var botToken)
    bot_url=$(php_var botUrl)
    admin=$(php_var admin)
    echo -e "Current bot URL: ${YELLOW}${bot_url}${NC}"
    read -rp "Enter new bot token: " new_token

    if ! validate_token "$new_token"; then error "The new token is invalid or Telegram API is not reachable."; return 1; fi
    bot_username=$(curl -fsS "https://api.telegram.org/bot${new_token}/getMe" 2>/dev/null | sed -n 's/.*"username":"\([^"]*\)".*/\1/p')
    echo -e "New bot username: ${YELLOW}@${bot_username}${NC}"
    confirm "Change bot token, delete old webhook and set new webhook?" || { warning "Canceled."; return; }

    backup_base_info
    [ -n "$old_token" ] && curl -fsS "https://api.telegram.org/bot${old_token}/deleteWebhook" >/dev/null 2>&1 || true
    set_php_string_var botToken "$new_token"
    set_bot_webhook "$new_token" "$bot_url" || return 1
    telegram_send_message "$new_token" "$admin" "✅ توکن ربات با موفقیت تغییر کرد و وبهوک جدید تنظیم شد." || true
    success "Bot token changed successfully."
}

repair_webhook() {
    need_bot_installation || return 1
    local token bot_url
    token=$(php_var botToken)
    bot_url=$(php_var botUrl)
    set_bot_webhook "$token" "$bot_url"
    show_webhook_raw
}

enable_auto_ssl_renew() {
    install_ssl_packages
    systemctl enable certbot.timer >/dev/null 2>&1 || true
    systemctl start certbot.timer >/dev/null 2>&1 || true
    (crontab -l 2>/dev/null | grep -v "certbot renew"; echo "0 4 * * * certbot renew --quiet --deploy-hook 'systemctl reload apache2' >/dev/null 2>&1") | crontab -
    success "Automatic SSL renewal was enabled."
}

renew_ssl_now() { install_ssl_packages; certbot renew --deploy-hook "systemctl reload apache2"; }
dry_run_ssl_renew() { install_ssl_packages; certbot renew --dry-run; }

ssl_menu() {
    while true; do
        banner
        section "SSL Tools"
        select ssl_opt in "Enable automatic SSL renewal" "Renew SSL now" "Test renewal dry-run" "Issue/repair cert for current domain" "Back"; do
            case "$ssl_opt" in
                "Enable automatic SSL renewal") enable_auto_ssl_renew; pause_screen; break ;;
                "Renew SSL now") renew_ssl_now; pause_screen; break ;;
                "Test renewal dry-run") dry_run_ssl_renew; pause_screen; break ;;
                "Issue/repair cert for current domain") local d; d=$(current_domain); [ -n "$d" ] && obtain_ssl_for_domain "$d" || error "Domain not found"; pause_screen; break ;;
                "Back") return ;;
                *) error "Invalid option!" ;;
            esac
        done
    done
}

# ─────────────────────────────────────────────────────────────
# Quick repair tools
# ─────────────────────────────────────────────────────────────
repair_permissions() {
    need_bot_installation || return 1
    chown -R www-data:www-data "$BOT_DIR/" 2>/dev/null || true
    chmod -R 755 "$BOT_DIR/" 2>/dev/null || true
    success "Permissions repaired."
}

repair_services() {
    apt_recover
    systemctl restart apache2 >/dev/null 2>&1 || true
    systemctl restart mysql >/dev/null 2>&1 || systemctl restart mariadb >/dev/null 2>&1 || true
    success "Apache/MySQL restart attempted."
}

repair_crons() {
    need_bot_installation || return 1
    local dom
    dom=$(current_domain)
    [ -z "$dom" ] && { error "Domain not detected."; return 1; }
    update_crons_for_domain "$dom"
}

repair_dns() {
    cp -a /etc/resolv.conf /etc/resolv.conf.wizwiz.bak 2>/dev/null || true
    cat > /etc/resolv.conf <<EOF
nameserver 1.1.1.1
nameserver 8.8.8.8
nameserver 9.9.9.9
EOF
    success "DNS resolvers were reset to public resolvers."
}

quick_repair_menu() {
    while true; do
        banner
        section "Quick Repair"
        select opt in "Repair webhook" "Repair permissions" "Repair cron jobs" "Restart Apache/MySQL" "Repair apt/dpkg locks" "Reset DNS resolvers" "Enable SSL auto-renew" "Back"; do
            case "$opt" in
                "Repair webhook") repair_webhook; pause_screen; break ;;
                "Repair permissions") repair_permissions; pause_screen; break ;;
                "Repair cron jobs") repair_crons; pause_screen; break ;;
                "Restart Apache/MySQL") repair_services; pause_screen; break ;;
                "Repair apt/dpkg locks") apt_recover; success "apt/dpkg recovery finished."; pause_screen; break ;;
                "Reset DNS resolvers") repair_dns; pause_screen; break ;;
                "Enable SSL auto-renew") enable_auto_ssl_renew; pause_screen; break ;;
                "Back") return ;;
                *) error "Invalid option!" ;;
            esac
        done
    done
}

install_local_command() {
    install -m 0755 "$0" "$LOCAL_CMD" 2>/dev/null || cp "$0" "$LOCAL_CMD"
    chmod +x "$LOCAL_CMD"
    success "Local command installed: wizwiz-update"
}

run_delete() {
    echo
    confirm "Are you sure you want to delete WizWiz completely?" || { warning "Delete canceled."; return; }
    local passs userrr pathsss passsword userrrname
    passs=$(grep '\$pass' /root/confwizwiz/dbrootwizwiz.txt 2>/dev/null | cut -d"'" -f2 | head -n1)
    userrr=$(grep '\$user' /root/confwizwiz/dbrootwizwiz.txt 2>/dev/null | cut -d"'" -f2 | head -n1)
    pathsss=$(grep '\$path' /root/confwizwiz/dbrootwizwiz.txt 2>/dev/null | cut -d"'" -f2 | head -n1)
    passsword=$(php_var dbPassword)
    userrrname=$(php_var dbUserName)

    if [ -n "$userrr" ] && [ -n "$passs" ]; then
        mysql -u "$userrr" -p"$passs" \
            -e "DROP DATABASE IF EXISTS wizwiz;" \
            -e "DROP USER IF EXISTS '$userrrname'@'localhost';" \
            -e "DROP USER IF EXISTS '$userrrname'@'%';" 2>/dev/null || true
    fi
    [ -n "$pathsss" ] && rm -rf "/var/www/html/wizpanel${pathsss}" 2>/dev/null || true
    rm -rf "$BOT_DIR" 2>/dev/null || true
    (crontab -l 2>/dev/null | grep -v "messagewizwiz.php" | grep -v "rewardReport.php" | grep -v "warnusers.php" | grep -v "backupnutif.php" | grep -v "gift2all.php" | grep -v "tronChecker.php" | grep -v "reportGroupBackup.php") | crontab -
    success "Removed successfully."
}

show_help() {
    banner
    section "Commands"
    kv "menu" "Open interactive dashboard"
    kv "status" "Show dashboard/status"
    kv "diagnostics" "Run checks"
    kv "repair" "Open repair menu"
    kv "update" "Update bot files"
    kv "panel" "Update web panel"
    kv "backup" "Install/run database backup"
    kv "token" "Change bot token and webhook"
    kv "domain" "Change domain, SSL, crons and webhook"
    kv "webhook" "Repair/show webhook"
    kv "ssl" "SSL tools"
    kv "delete" "Remove bot"
    echo
    echo -e "${YELLOW}Examples:${NC}"
    echo "bash <(curl -s $RAW_UPDATE_URL)"
    echo "bash update.sh status"
    echo "bash update.sh repair"
    echo "bash update.sh token"
}

show_donate() {
    echo -e "\n${RED}Bank (1212): ${CYAN}1212${NC}"
    echo -e "${RED}Tron(TRX): ${CYAN}TY8j7of18gbMtneB8bbL7SZk5gcntQEemG${NC}"
    echo -e "${RED}Bitcoin: ${CYAN}bc1qcnkjnqvs7kyxvlfrns8t4ely7x85dhvz5gqge4${NC}\n"
}

main_menu() {
    while true; do
        show_dashboard
        section "Menu"
        options=(
            "Update bot"
            "Update panel"
            "Backup"
            "Status / Diagnostics"
            "Quick repair"
            "Change bot token"
            "Change bot domain"
            "Repair webhook"
            "SSL tools"
            "Install local command"
            "Delete"
            "Donate"
            "Exit"
        )
        PS3=" Please Select Action: "
        select opt in "${options[@]}"; do
            case "$opt" in
                "Update bot") run_update_bot; pause_screen; break ;;
                "Update panel") run_update_panel; pause_screen; break ;;
                "Backup") run_backup_setup; pause_screen; break ;;
                "Status / Diagnostics") run_diagnostics; pause_screen; break ;;
                "Quick repair") quick_repair_menu; break ;;
                "Change bot token") change_bot_token; pause_screen; break ;;
                "Change bot domain") change_bot_domain; pause_screen; break ;;
                "Repair webhook") repair_webhook; pause_screen; break ;;
                "SSL tools") ssl_menu; break ;;
                "Install local command") install_local_command; pause_screen; break ;;
                "Delete") run_delete; pause_screen; break ;;
                "Donate") show_donate; pause_screen; break ;;
                "Exit") echo " "; exit 0 ;;
                *) error "Invalid option!" ;;
            esac
        done
    done
}

case "${1:-menu}" in
    menu) main_menu ;;
    status) show_dashboard ;;
    diagnostics|diag) run_diagnostics ;;
    repair) quick_repair_menu ;;
    update) run_update_bot ;;
    panel) run_update_panel ;;
    backup) run_backup_setup ;;
    token) change_bot_token ;;
    domain) change_bot_domain ;;
    webhook) repair_webhook ;;
    ssl) ssl_menu ;;
    delete|remove) run_delete ;;
    help|-h|--help) show_help ;;
    *) show_help; exit 1 ;;
esac
