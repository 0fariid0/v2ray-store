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
PANEL_ZIP_URL="https://raw.githubusercontent.com/0fariid0/v2ray-store/main/v2raystore-panel.zip"
PANEL_RELEASE_ZIP_URL="https://github.com/0fariid0/v2ray-store/releases/latest/download/v2raystore-panel.zip"
BACKUP_DIR="/root/v2raystore_update_backups"
CONFIG_DIR="/root/confv2raystore"
CONFIG_FILE="${CONFIG_DIR}/dbrootv2raystore.txt"
LOCAL_CMD="/usr/local/bin/v2ray-store"
LOG_FILE="/tmp/v2raystore_update.log"
DEFAULT_DB_NAME="v2raystore"
PHP_UPLOAD_LIMIT="1024M"
PHP_POST_LIMIT="1024M"
PHP_MEMORY_LIMIT="1024M"
PHP_MAX_EXECUTION_TIME="600"
PHP_MAX_INPUT_TIME="600"
PHP_MAX_INPUT_VARS="10000"
MYSQL_MAX_ALLOWED_PACKET="1024M"

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
dot() {
    case "$1" in
        ok) printf "${GREEN}●${NC}" ;;
        warn) printf "${YELLOW}●${NC}" ;;
        bad) printf "${RED}●${NC}" ;;
        *) printf "${DIM}●${NC}" ;;
    esac
}
kv() { printf " ${DIM}%-18s${NC}: %b${NC}\n" "$1" "$2"; }
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

    # Install web/PHP packages separately so a MySQL package-name mismatch
    # cannot cancel the whole installation on Debian/Ubuntu variants.
    apt-get install -y apache2 php libapache2-mod-php php-mysql php-mbstring php-zip php-gd php-json php-curl php-soap php-ssh2 php-opcache php-xml php-intl php-bcmath git wget curl unzip openssl ca-certificates certbot python3-certbot-apache >/dev/null 2>&1 || true

    if ! command -v mysql >/dev/null 2>&1; then
        apt-get install -y mysql-server mysql-client >/dev/null 2>&1 || true
    fi
    if ! command -v mysql >/dev/null 2>&1; then
        apt-get install -y default-mysql-server default-mysql-client >/dev/null 2>&1 || true
    fi
    if ! command -v mysql >/dev/null 2>&1; then
        apt-get install -y mariadb-server mariadb-client >/dev/null 2>&1 || true
    fi
    if ! command -v mysql >/dev/null 2>&1; then
        error "MySQL/MariaDB client was not installed. Run: apt update && apt install -y default-mysql-server default-mysql-client"
        return 1
    fi

    systemctl enable mysql.service >/dev/null 2>&1 || systemctl enable mariadb >/dev/null 2>&1 || true
    systemctl start mysql.service >/dev/null 2>&1 || systemctl start mariadb >/dev/null 2>&1 || true
    systemctl enable apache2 >/dev/null 2>&1 || true
    configure_php_performance --quiet
    systemctl restart apache2 >/dev/null 2>&1 || true
    ufw allow 80 >/dev/null 2>&1 || true
    ufw allow 443 >/dev/null 2>&1 || true
}

install_basic_packages() {
    apt_recover
    apt-get update -y >/dev/null 2>&1 || true
    apt-get install -y curl wget git unzip ca-certificates python3 openssl lsb-release >/dev/null 2>&1
}

install_ssl_packages() {
    apt_recover
    apt-get update -y >/dev/null 2>&1 || true
    apt-get install -y certbot python3-certbot-apache openssl curl >/dev/null 2>&1
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

validate_domain() { [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]]; }

json_value() {
    local json="$1" key="$2"
    php -r '$j=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; $v=$j; foreach(explode(".",$k) as $p){ if(!is_array($v) || !array_key_exists($p,$v)){ exit; } $v=$v[$p]; } if(is_bool($v)) echo $v?"true":"false"; elseif(is_array($v)) echo json_encode($v, JSON_UNESCAPED_UNICODE); else echo $v;' "$key" <<< "$json" 2>/dev/null || true
}

get_server_ip() {
    local ip
    ip=$(curl -fsSL --max-time 4 https://api.ipify.org 2>/dev/null || true)
    [ -z "$ip" ] && ip=$(curl -fsSL --max-time 4 https://ifconfig.me 2>/dev/null || true)
    [ -z "$ip" ] && ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    echo "${ip:-n/a}"
}

domain_points_here() {
    local dom="$1" server_ip resolved
    server_ip=$(get_server_ip)
    resolved=$(getent ahostsv4 "$dom" 2>/dev/null | awk '{print $1; exit}')
    [ -z "$resolved" ] && return 2
    [ "$resolved" = "$server_ip" ] && return 0
    return 1
}

ssl_days_left() {
    local dom="$1" cert="/etc/letsencrypt/live/${dom}/cert.pem" expiry
    [ -f "$cert" ] || return 1
    expiry=$(openssl x509 -enddate -noout -in "$cert" 2>/dev/null | cut -d= -f2)
    [ -z "$expiry" ] && return 1
    echo $(( ( $(date -d "$expiry" +%s 2>/dev/null || echo 0) - $(date +%s) ) / 86400 ))
}

cron_count() { crontab -l 2>/dev/null | grep -c "${BOT_SLUG}\|${PANEL_SLUG}\|v2raystore" 2>/dev/null || echo 0; }

bot_url_for_domain() { echo "https://${1}/${BOT_SLUG}/"; }

ensure_config_file() {
    mkdir -p "$CONFIG_DIR"
    if ! command -v mysql >/dev/null 2>&1; then
        install_packages || return 1
    fi
    if [ ! -f "$CONFIG_FILE" ]; then
        local pass
        pass=$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | cut -c1-30)
        cat > "$CONFIG_FILE" <<EOF2
\$user = 'root';
\$pass = '${pass}';
\$path = '${PANEL_SLUG}';
EOF2
        chmod 600 "$CONFIG_FILE"
        mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${pass}'; FLUSH PRIVILEGES;" >/dev/null 2>&1 || true
    fi
}

root_db_user() { grep '\$user' "$CONFIG_FILE" 2>/dev/null | cut -d"'" -f2 | head -n1; }
root_db_pass() { grep '\$pass' "$CONFIG_FILE" 2>/dev/null | cut -d"'" -f2 | head -n1; }


ini_set_value() {
    local file="$1" key="$2" value="$3"
    [ -f "$file" ] || return 0
    python3 - "$file" "$key" "$value" <<'PYINNER'
import re, sys
from pathlib import Path
path = Path(sys.argv[1])
key = sys.argv[2]
value = sys.argv[3]
try:
    text = path.read_text(encoding='utf-8', errors='ignore')
except FileNotFoundError:
    sys.exit(0)
line = f"{key} = {value}"
pattern = re.compile(rf"^\s*;?\s*{re.escape(key)}\s*=\s*.*$", re.MULTILINE)
if pattern.search(text):
    text = pattern.sub(line, text, count=1)
else:
    if text and not text.endswith('\n'):
        text += '\n'
    text += f"\n; V2Ray Store performance/upload tuning\n{line}\n"
path.write_text(text, encoding='utf-8')
PYINNER
}

configure_php_performance() {
    local quiet="${1:-}" php_ini opcache_ini mysql_conf apache_changed=0
    [ "$quiet" != "--quiet" ] && section "PHP / Upload Performance"

    if command -v a2enmod >/dev/null 2>&1; then
        a2enmod rewrite headers expires deflate >/dev/null 2>&1 && apache_changed=1 || true
    fi
    command -v phpenmod >/dev/null 2>&1 && phpenmod opcache >/dev/null 2>&1 || true

    if [ -d /etc/php ]; then
        while IFS= read -r php_ini; do
            [ -f "$php_ini" ] || continue
            [ -f "${php_ini}.v2raystore.bak" ] || cp -a "$php_ini" "${php_ini}.v2raystore.bak" 2>/dev/null || true
            ini_set_value "$php_ini" upload_max_filesize "$PHP_UPLOAD_LIMIT"
            ini_set_value "$php_ini" post_max_size "$PHP_POST_LIMIT"
            ini_set_value "$php_ini" memory_limit "$PHP_MEMORY_LIMIT"
            ini_set_value "$php_ini" max_execution_time "$PHP_MAX_EXECUTION_TIME"
            ini_set_value "$php_ini" max_input_time "$PHP_MAX_INPUT_TIME"
            ini_set_value "$php_ini" max_input_vars "$PHP_MAX_INPUT_VARS"
            ini_set_value "$php_ini" max_file_uploads "100"
            ini_set_value "$php_ini" default_socket_timeout "600"
            ini_set_value "$php_ini" realpath_cache_size "4096K"
            ini_set_value "$php_ini" realpath_cache_ttl "600"
            ini_set_value "$php_ini" expose_php "Off"
            ini_set_value "$php_ini" display_errors "Off"
            ini_set_value "$php_ini" log_errors "On"
            ini_set_value "$php_ini" output_buffering "4096"
        done < <(find /etc/php -type f -name php.ini 2>/dev/null)

        while IFS= read -r opcache_ini; do
            [ -f "$opcache_ini" ] || continue
            [ -f "${opcache_ini}.v2raystore.bak" ] || cp -a "$opcache_ini" "${opcache_ini}.v2raystore.bak" 2>/dev/null || true
            ini_set_value "$opcache_ini" opcache.enable "1"
            ini_set_value "$opcache_ini" opcache.enable_cli "1"
            ini_set_value "$opcache_ini" opcache.memory_consumption "256"
            ini_set_value "$opcache_ini" opcache.interned_strings_buffer "32"
            ini_set_value "$opcache_ini" opcache.max_accelerated_files "100000"
            ini_set_value "$opcache_ini" opcache.validate_timestamps "1"
            ini_set_value "$opcache_ini" opcache.revalidate_freq "60"
            ini_set_value "$opcache_ini" opcache.save_comments "1"
            ini_set_value "$opcache_ini" opcache.fast_shutdown "1"
        done < <(find /etc/php -type f -path '*/mods-available/opcache.ini' 2>/dev/null)
    fi

    mkdir -p /etc/mysql/conf.d 2>/dev/null || true
    mysql_conf="/etc/mysql/conf.d/v2raystore-performance.cnf"
    cat > "$mysql_conf" <<EOF
[mysqld]
max_allowed_packet=${MYSQL_MAX_ALLOWED_PACKET}
net_read_timeout=600
net_write_timeout=600
wait_timeout=28800
interactive_timeout=28800

[client]
max_allowed_packet=${MYSQL_MAX_ALLOWED_PACKET}
EOF

    if [ -d /etc/apache2/conf-available ]; then
        cat > /etc/apache2/conf-available/v2raystore-security-performance.conf <<'EOF'
ServerTokens Prod
ServerSignature Off
FileETag None
TraceEnable Off
EOF
        a2enconf v2raystore-security-performance >/dev/null 2>&1 && apache_changed=1 || true
    fi

    systemctl restart mysql >/dev/null 2>&1 || systemctl restart mariadb >/dev/null 2>&1 || true
    systemctl restart php*-fpm >/dev/null 2>&1 || true
    systemctl reload apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1 || true

    if [ "$quiet" != "--quiet" ]; then
        success "PHP performance and upload limits were applied automatically."
        kv "upload_max_filesize" "${DIM}${PHP_UPLOAD_LIMIT}${NC}"
        kv "post_max_size" "${DIM}${PHP_POST_LIMIT}${NC}"
        kv "memory_limit" "${DIM}${PHP_MEMORY_LIMIT}${NC}"
        kv "MySQL packet" "${DIM}${MYSQL_MAX_ALLOWED_PACKET}${NC}"
    fi
}


fix_panel_entrypoint() {
    [ -d "$PANEL_DIR" ] || return 0
    rm -f "$PANEL_DIR/index.html" 2>/dev/null || true
    if [ -f "$PANEL_DIR/.htaccess" ]; then
        grep -q '^DirectoryIndex index.php' "$PANEL_DIR/.htaccess" 2>/dev/null || sed -i '1iDirectoryIndex index.php' "$PANEL_DIR/.htaccess"
    else
        echo 'DirectoryIndex index.php' > "$PANEL_DIR/.htaccess"
    fi
}

update_panel_db_include() {
    [ -f "$PANEL_DIR/includ/db.php" ] || return 0
    cat > "$PANEL_DIR/includ/db.php" <<'PHP'
<?php
$baseInfoCandidates = array(
    dirname(__DIR__, 2) . '/v2ray-store/baseInfo.php',
    '/var/www/html/v2ray-store/baseInfo.php',
    (isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/v2ray-store/baseInfo.php' : ''),
);

$baseInfoLoaded = false;
foreach ($baseInfoCandidates as $baseInfoFile) {
    if ($baseInfoFile !== '' && is_file($baseInfoFile)) {
        include $baseInfoFile;
        $baseInfoLoaded = true;
        break;
    }
}

if (!$baseInfoLoaded) {
    http_response_code(500);
    die('V2Ray Store panel error: baseInfo.php was not found. Please run Install / Update again.');
}

$servername = "localhost";
$conn = new mysqli($servername, $dbUserName, $dbPassword, $dbName);
$conn->set_charset("utf8mb4");
if ($conn->connect_error) {
    http_response_code(500);
    die("V2Ray Store panel database connection failed: " . $conn->connect_error);
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

telegram_send_message() {
    local token="$1" chat_id="$2" text="$3"
    [ -z "$token" ] || [ -z "$chat_id" ] && return 1
    curl -fsS -X POST "https://api.telegram.org/bot${token}/sendMessage" \
        -d "chat_id=${chat_id}" \
        --data-urlencode "text=${text}" \
        -d "parse_mode=HTML" >/dev/null 2>&1
}

need_installation() {
    if [ ! -f "$BASE_INFO" ]; then
        error "${BRAND_NAME} is not installed yet. Run Install / Update first."
        return 1
    fi
    return 0
}

backup_base_info() { backup_path "$BASE_INFO" "baseInfo.php"; }

backup_full_bot() { backup_path "$BOT_DIR" "bot-full"; }

clean_bot_after_update() {
    rm -rf "$BOT_DIR/webpanel" 2>/dev/null || true
    rm -f "$BOT_DIR/tempCookie.txt" "$BOT_DIR/index.html" "$BOT_DIR/settings/message${LEGACY_NAME}.json" "$BOT_DIR/settings/${LEGACY_MESSAGE_FILE}" "$BOT_DIR/${LEGACY_NAME}.sh" "$BOT_DIR/${LEGACY_BACKUP_FILE}" 2>/dev/null || true
}

validate_token() {
    local token="$1"
    [[ "$token" =~ ^[0-9]{8,12}:[A-Za-z0-9_-]{30,}$ ]] || return 1
    curl -fsS --connect-timeout 15 "https://api.telegram.org/bot${token}/getMe" 2>/dev/null | grep -q '"ok":true'
}

show_webhook_raw() {
    need_installation || return 1
    local token
    token=$(php_var botToken)
    [ -z "$token" ] && { error "Bot token is empty."; return 1; }
    line
    curl -s "https://api.telegram.org/bot${token}/getWebhookInfo"
    echo
    line
}

run_diagnostics() {
    banner
    section "Diagnostics"
    local ok=1 dom token bot_url free_mb apache_s mysql_s
    [ -f "$BASE_INFO" ] && kv "baseInfo.php" "$(dot ok) ${GREEN}found${NC}" || { kv "baseInfo.php" "$(dot bad) ${RED}missing${NC}"; ok=0; }
    command -v php >/dev/null 2>&1 && kv "PHP binary" "$(dot ok) ${GREEN}found${NC}" || { kv "PHP binary" "$(dot bad) ${RED}missing${NC}"; ok=0; }
    command -v mysql >/dev/null 2>&1 && kv "MySQL client" "$(dot ok) ${GREEN}found${NC}" || kv "MySQL client" "$(dot warn) ${YELLOW}missing${NC}"
    apache_s=$(systemctl is-active apache2 2>/dev/null || echo inactive)
    mysql_s=$(systemctl is-active mysql 2>/dev/null || systemctl is-active mariadb 2>/dev/null || echo inactive)
    [ "$apache_s" = active ] && kv "Apache" "$(dot ok) ${GREEN}active${NC}" || kv "Apache" "$(dot bad) ${RED}${apache_s}${NC}"
    [ "$mysql_s" = active ] && kv "MySQL/MariaDB" "$(dot ok) ${GREEN}active${NC}" || kv "MySQL/MariaDB" "$(dot bad) ${RED}${mysql_s}${NC}"
    curl -fsSL --max-time 8 https://api.telegram.org >/dev/null 2>&1 && kv "Telegram API" "$(dot ok) ${GREEN}reachable${NC}" || { kv "Telegram API" "$(dot bad) ${RED}unreachable${NC}"; ok=0; }
    curl -fsSL --max-time 8 https://github.com >/dev/null 2>&1 && kv "GitHub" "$(dot ok) ${GREEN}reachable${NC}" || kv "GitHub" "$(dot warn) ${YELLOW}unreachable${NC}"
    free_mb=$(df -Pm / 2>/dev/null | awk 'NR==2{print $4}')
    [ "${free_mb:-0}" -ge 1024 ] 2>/dev/null && kv "Disk free" "$(dot ok) ${GREEN}${free_mb} MB${NC}" || kv "Disk free" "$(dot warn) ${YELLOW}${free_mb:-0} MB${NC}"
    if [ -f "$BASE_INFO" ]; then
        token=$(php_var botToken)
        bot_url=$(php_var botUrl)
        dom=$(current_domain)
        [ -n "$token" ] && validate_token "$token" && kv "Bot token" "$(dot ok) ${GREEN}valid${NC}" || kv "Bot token" "$(dot bad) ${RED}invalid/unreachable${NC}"
        [ -n "$dom" ] && validate_domain "$dom" && kv "Domain" "$(dot ok) ${GREEN}${dom}${NC}" || kv "Domain" "$(dot warn) ${YELLOW}${dom:-not detected}${NC}"
        if [ -n "$dom" ]; then
            domain_points_here "$dom"
            case $? in
                0) kv "Domain DNS" "$(dot ok) ${GREEN}points to this server${NC}" ;;
                1) kv "Domain DNS" "$(dot warn) ${YELLOW}does not point to this server ($(get_server_ip))${NC}" ;;
                2) kv "Domain DNS" "$(dot bad) ${RED}cannot resolve${NC}" ;;
            esac
        fi
        [ -n "$bot_url" ] && kv "Webhook target" "${DIM}${bot_url%/}/bot.php${NC}"
    fi
    echo
    [ "$ok" -eq 1 ] && success "Diagnostics finished." || warning "Diagnostics found issues. Use Quick repair from the menu."
}

obtain_ssl_for_domain() {
    local domain="$1"
    [ -z "$domain" ] && return 1
    install_ssl_packages
    ufw allow 80 >/dev/null 2>&1 || true
    ufw allow 443 >/dev/null 2>&1 || true
    systemctl enable certbot.timer >/dev/null 2>&1 || true
    run_step "Issuing/repairing SSL certificate" "certbot --apache --non-interactive --agree-tos --register-unsafely-without-email -d '$domain'" || return 1
    systemctl reload apache2 >/dev/null 2>&1 || true
}

change_bot_domain() {
    need_installation || return 1
    local old_domain current_url new_domain token admin bot_url
    current_url=$(php_var botUrl)
    old_domain=$(current_domain)
    echo -e "Current bot URL: ${YELLOW}${current_url}${NC}"
    read -rp "Enter new domain (example.com): " new_domain
    new_domain=$(normalize_domain "$new_domain")
    validate_domain "$new_domain" || { error "Invalid domain format."; return 1; }
    domain_points_here "$new_domain"
    case $? in
        1) warning "This domain does not point to this server IP ($(get_server_ip))." ;;
        2) warning "This domain could not be resolved yet." ;;
    esac
    confirm "Continue, repair SSL, update crons and reset webhook?" || return 0
    obtain_ssl_for_domain "$new_domain" || warning "SSL setup failed or was skipped. Continuing with URL update."
    backup_base_info
    bot_url=$(bot_url_for_domain "$new_domain")
    set_php_string_var botUrl "$bot_url"
    update_crons_for_domain "$new_domain"
    token=$(php_var botToken)
    admin=$(php_var admin)
    set_bot_webhook "$token" "$bot_url" || true
    telegram_send_message "$token" "$admin" "✅ دامنه ${BRAND_NAME} تغییر کرد.\n\nقبلی: ${old_domain}\nجدید: ${new_domain}" || true
    success "Domain changed successfully: ${bot_url}"
}

change_bot_token() {
    need_installation || return 1
    local old_token new_token bot_url admin username
    old_token=$(php_var botToken)
    bot_url=$(php_var botUrl)
    admin=$(php_var admin)
    read -rp "Enter new bot token: " new_token
    validate_token "$new_token" || { error "The token is invalid or Telegram API is unreachable."; return 1; }
    username=$(curl -fsS "https://api.telegram.org/bot${new_token}/getMe" 2>/dev/null | sed -n 's/.*"username":"\([^"]*\)".*/\1/p')
    [ -n "$username" ] && echo -e "New bot username: ${YELLOW}@${username}${NC}"
    confirm "Change token, delete old webhook and set new webhook?" || return 0
    backup_base_info
    [ -n "$old_token" ] && curl -fsS "https://api.telegram.org/bot${old_token}/deleteWebhook" >/dev/null 2>&1 || true
    set_php_string_var botToken "$new_token"
    set_bot_webhook "$new_token" "$bot_url" || return 1
    telegram_send_message "$new_token" "$admin" "✅ توکن ربات با موفقیت تغییر کرد." || true
    success "Bot token changed successfully."
}

enable_auto_ssl_renew() {
    install_ssl_packages
    systemctl enable certbot.timer >/dev/null 2>&1 || true
    systemctl start certbot.timer >/dev/null 2>&1 || true
    (crontab -l 2>/dev/null | grep -v "certbot renew"; echo "0 4 * * * certbot renew --quiet --deploy-hook 'systemctl reload apache2' >/dev/null 2>&1") | crontab -
    success "Automatic SSL renewal enabled."
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
                *) error "Invalid option." ;;
            esac
        done
    done
}

repair_permissions() {
    [ -d "$BOT_DIR" ] && chown -R www-data:www-data "$BOT_DIR" && chmod -R 755 "$BOT_DIR" 2>/dev/null || true
    [ -d "$PANEL_DIR" ] && chown -R www-data:www-data "$PANEL_DIR" && chmod -R 755 "$PANEL_DIR" 2>/dev/null || true
    success "Permissions repaired."
}

repair_services() {
    apt_recover
    systemctl restart apache2 >/dev/null 2>&1 || true
    systemctl restart mysql >/dev/null 2>&1 || systemctl restart mariadb >/dev/null 2>&1 || true
    success "Apache/MySQL restart attempted."
}

repair_crons() {
    need_installation || return 1
    local dom
    dom=$(current_domain)
    [ -z "$dom" ] && { error "Domain not detected."; return 1; }
    update_crons_for_domain "$dom"
    success "Cron jobs repaired."
}

repair_dns() {
    cp -a /etc/resolv.conf /etc/resolv.conf.v2raystore.bak 2>/dev/null || true
    cat > /etc/resolv.conf <<EOF
nameserver 1.1.1.1
nameserver 8.8.8.8
nameserver 9.9.9.9
EOF
    success "DNS resolvers reset."
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
                *) error "Invalid option." ;;
            esac
        done
    done
}

run_backup_setup() {
    install_basic_packages
    mkdir -p /root
    (crontab -l 2>/dev/null | grep -v "dbbackupv2raystore.sh"; echo "0 * * * * /root/dbbackupv2raystore.sh >/dev/null 2>&1") | sort -u | crontab -
    if [ -f "$BOT_DIR/dbbackupv2raystore.sh" ]; then
        cp -a "$BOT_DIR/dbbackupv2raystore.sh" /root/dbbackupv2raystore.sh
    else
        wget -q -O /root/dbbackupv2raystore.sh "https://raw.githubusercontent.com/0fariid0/v2ray-store/main/dbbackupv2raystore.sh" || true
    fi
    chmod +x /root/dbbackupv2raystore.sh 2>/dev/null || true
    /root/dbbackupv2raystore.sh || true
    success "Database backup cron installed."
}

run_delete() {
    confirm "Delete ${BRAND_NAME} files, panel, crons and database users?" || { warning "Delete canceled."; return 0; }
    local root_user root_pass dbuser dbname
    root_user=$(root_db_user); root_pass=$(root_db_pass); dbuser=$(php_var dbUserName); dbname=$(php_var dbName)
    backup_path "$BOT_DIR" "delete-bot"
    backup_path "$PANEL_DIR" "delete-panel"
    if [ -n "$root_user" ] && [ -n "$root_pass" ] && [ -n "$dbname" ] && valid_mysql_identifier "$dbname"; then
        MYSQL_PWD="$root_pass" mysql -u "$root_user" -e "DROP DATABASE IF EXISTS ${dbname}; DROP USER IF EXISTS '${dbuser}'@'localhost'; DROP USER IF EXISTS '${dbuser}'@'%'; FLUSH PRIVILEGES;" 2>/dev/null || true
    fi
    rm -rf "$BOT_DIR" "$PANEL_DIR" "$CONFIG_DIR" 2>/dev/null || true
    (crontab -l 2>/dev/null | grep -v "$BOT_SLUG" | grep -v "$PANEL_SLUG" | grep -v "v2raystore") | crontab - 2>/dev/null || true
    success "Removed successfully. Backups are in ${BACKUP_DIR}."
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
    fix_panel_entrypoint
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
    clean_bot_after_update
    chown -R www-data:www-data "$BOT_DIR/" 2>/dev/null || true
    chmod -R 755 "$BOT_DIR/" 2>/dev/null || true
}

valid_mysql_identifier() {
    [[ "$1" =~ ^[A-Za-z0-9_]+$ ]]
}

sql_escape_string() {
    printf "%s" "$1" | sed "s/'/''/g"
}

mysql_root_run_file() {
    local sql_file="$1" root_user root_pass
    root_user=$(root_db_user)
    root_pass=$(root_db_pass)
    if [ -n "$root_user" ] && MYSQL_PWD="$root_pass" mysql -u "$root_user" < "$sql_file"; then
        return 0
    fi
    mysql -u root < "$sql_file"
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

    if ! valid_mysql_identifier "$dbname"; then
        error "Database name can only contain English letters, numbers and underscore."
        return 1
    fi
    if ! valid_mysql_identifier "$dbuser"; then
        error "Database username can only contain English letters, numbers and underscore."
        return 1
    fi

    local dbpass_sql sql_file
    dbpass_sql=$(sql_escape_string "$dbpass")
    sql_file=$(mktemp /tmp/v2raystore-db.XXXXXX.sql)
    cat > "$sql_file" <<SQL
CREATE DATABASE IF NOT EXISTS ${dbname} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${dbuser}'@'localhost' IDENTIFIED BY '${dbpass_sql}';
ALTER USER '${dbuser}'@'localhost' IDENTIFIED BY '${dbpass_sql}';
GRANT ALL PRIVILEGES ON ${dbname}.* TO '${dbuser}'@'localhost';
FLUSH PRIVILEGES;
SQL
    if ! mysql_root_run_file "$sql_file"; then
        rm -f "$sql_file"
        error "Database setup failed. MySQL is installed, but root access failed or SQL was rejected."
        return 1
    fi
    rm -f "$sql_file"

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
    chown www-data:www-data "$BASE_INFO" 2>/dev/null || true
    chmod 644 "$BASE_INFO" 2>/dev/null || true

    php "${BOT_DIR}/createDB.php" >/dev/null 2>&1 || curl -fsS "${bot_url}createDB.php" >/dev/null 2>&1 || true

    if ! obtain_ssl_for_domain "$domain_clean"; then
        error "SSL certificate could not be issued for ${domain_clean}. Make sure DNS points to this server and ports 80/443 are open."
        warning "Installation files and database were created, but webhook cannot work without a valid HTTPS certificate."
        return 1
    fi
    enable_auto_ssl_renew >/dev/null 2>&1 || true

    update_crons_for_domain "$domain_clean"
    set_bot_webhook "$token" "$bot_url" || warning "Webhook could not be set automatically. You can repair it from the menu."
    success "Database, baseInfo.php, SSL and webhook were configured."
}

find_panel_package_dir() {
    local root="$1" candidate
    for candidate in \
        "$root" \
        "$root/${PANEL_SLUG}" \
        "$root/panel" \
        "$root/v2raystore-panel" \
        "$root/v2raystore_panel" \
        "$root"/*; do
        [ -d "$candidate" ] || continue
        if [ -f "$candidate/login.php" ] && [ -f "$candidate/includ/db.php" ]; then
            echo "$candidate"
            return 0
        fi
    done
    return 1
}

download_panel_package() {
    local output="$1" url
    for url in "$PANEL_ZIP_URL" "$PANEL_RELEASE_ZIP_URL"; do
        [ -n "$url" ] || continue
        rm -f "$output"
        : > "$LOG_FILE"
        echo -ne " ${YELLOW}⏳${NC} Downloading panel package ..."
        if wget -q -O "$output" "$url" >> "$LOG_FILE" 2>&1 && unzip -tq "$output" >/dev/null 2>> "$LOG_FILE"; then
            echo -e "\r ${GREEN}✔${NC} Downloading panel package"
            return 0
        fi
        echo -e "\r ${RED}✘${NC} Downloading panel package from current source"
        tail -n 10 "$LOG_FILE" 2>/dev/null
    done
    rm -f "$output"
    return 1
}

install_or_update_panel() {
    install_packages
    local tmp_zip tmp_extract panel_source
    tmp_zip="/tmp/v2raystore-panel.$$.zip"
    tmp_extract="/tmp/v2raystore-panel.$$"
    rm -rf "$tmp_zip" "$tmp_extract"

    download_panel_package "$tmp_zip" || {
        error "Panel package was not found. Current panel was kept unchanged."
        warning "Put v2raystore-panel.zip in the repository root or upload it as a release asset with the same name."
        return 1
    }

    mkdir -p "$tmp_extract"
    run_step "Extracting panel" "unzip -oq '$tmp_zip' -d '$tmp_extract'" || { rm -rf "$tmp_zip" "$tmp_extract"; return 1; }
    panel_source=$(find_panel_package_dir "$tmp_extract") || {
        rm -rf "$tmp_zip" "$tmp_extract"
        error "Downloaded panel package is not valid. Current panel was kept unchanged."
        return 1
    }

    backup_path "$PANEL_DIR" "panel"
    rm -rf "$PANEL_DIR"
    mkdir -p "$PANEL_DIR"
    cp -a "$panel_source/." "$PANEL_DIR/" || { rm -rf "$tmp_zip" "$tmp_extract"; return 1; }
    rm -rf "$tmp_zip" "$tmp_extract"

    update_panel_db_include
    fix_panel_entrypoint
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
    local dbuser dbpass dbname new_user new_pass root_user root_pass legacy_root_user legacy_root_pass
    local user_b64 pass_b64 sql_file err_file ok

    dbuser=$(php_var dbUserName)
    dbpass=$(php_var dbPassword)
    dbname=$(php_var dbName)
    [ -z "$dbname" ] && { error "Database name was not found in baseInfo.php."; return 1; }

    read -rp "New panel username [admin]: " new_user
    new_user="${new_user:-admin}"
    read -rsp "New panel password: " new_pass
    echo
    [ -z "$new_pass" ] && { error "Password cannot be empty."; return 1; }

    user_b64=$(printf '%s' "$new_user" | base64 -w0 2>/dev/null || printf '%s' "$new_user" | base64 | tr -d '\n')
    pass_b64=$(printf '%s' "$new_pass" | base64 -w0 2>/dev/null || printf '%s' "$new_pass" | base64 | tr -d '\n')
    sql_file=$(mktemp)
    err_file=$(mktemp)

    cat > "$sql_file" <<SQL
SET @new_user = CONVERT(FROM_BASE64('${user_b64}') USING utf8mb4);
SET @new_pass = CONVERT(FROM_BASE64('${pass_b64}') USING utf8mb4);

CREATE TABLE IF NOT EXISTS \`admins\` (
  \`id\` int(10) NOT NULL AUTO_INCREMENT,
  \`username\` varchar(200) NOT NULL DEFAULT '',
  \`password\` varchar(200) NOT NULL DEFAULT '',
  \`backupchannel\` varchar(200) CHARACTER SET utf8 NOT NULL DEFAULT '',
  \`lang\` varchar(10) CHARACTER SET utf8 NOT NULL DEFAULT 'fa',
  PRIMARY KEY (\`id\`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

SET @missing_id = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'id');
SET @sql = IF(@missing_id, 'ALTER TABLE \`admins\` ADD COLUMN \`id\` int(10) NOT NULL DEFAULT 1 FIRST', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @missing_username = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'username');
SET @sql = IF(@missing_username, CONCAT('ALTER TABLE \`admins\` ADD COLUMN \`username\` varchar(200) NOT NULL DEFAULT ', CHAR(39), CHAR(39)), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @missing_password = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'password');
SET @sql = IF(@missing_password, CONCAT('ALTER TABLE \`admins\` ADD COLUMN \`password\` varchar(200) NOT NULL DEFAULT ', CHAR(39), CHAR(39)), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @missing_backupchannel = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'backupchannel');
SET @sql = IF(@missing_backupchannel, CONCAT('ALTER TABLE \`admins\` ADD COLUMN \`backupchannel\` varchar(200) CHARACTER SET utf8 NOT NULL DEFAULT ', CHAR(39), CHAR(39)), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @missing_lang = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'lang');
SET @sql = IF(@missing_lang, CONCAT('ALTER TABLE \`admins\` ADD COLUMN \`lang\` varchar(10) CHARACTER SET utf8 NOT NULL DEFAULT ', CHAR(39), 'fa', CHAR(39)), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE \`admins\` SET \`username\` = @new_user, \`password\` = @new_pass WHERE \`id\` = 1;
INSERT INTO \`admins\` (\`id\`, \`username\`, \`password\`, \`backupchannel\`, \`lang\`)
SELECT 1, @new_user, @new_pass, '', 'fa'
WHERE NOT EXISTS (SELECT 1 FROM \`admins\` WHERE \`id\` = 1);
SQL

    root_user=$(root_db_user)
    root_pass=$(root_db_pass)
    legacy_root_user=$(read_legacy_config_value user)
    legacy_root_pass=$(read_legacy_config_value pass)
    ok=0

    run_mysql_update() {
        local label="$1" user="$2" pass="$3"
        [ -z "$user" ] && return 1
        if [ -n "$pass" ]; then
            mysql --default-character-set=utf8mb4 -u "$user" -p"$pass" "$dbname" < "$sql_file" > /dev/null 2> "$err_file"
        else
            mysql --default-character-set=utf8mb4 -u "$user" "$dbname" < "$sql_file" > /dev/null 2> "$err_file"
        fi
    }

    if run_mysql_update "baseInfo" "$dbuser" "$dbpass"; then ok=1; fi
    if [ "$ok" -ne 1 ] && run_mysql_update "root-config" "$root_user" "$root_pass"; then ok=1; fi
    if [ "$ok" -ne 1 ] && run_mysql_update "legacy-root-config" "$legacy_root_user" "$legacy_root_pass"; then ok=1; fi
    if [ "$ok" -ne 1 ] && mysql --default-character-set=utf8mb4 -u root "$dbname" < "$sql_file" > /dev/null 2> "$err_file"; then ok=1; fi

    if [ "$ok" -eq 1 ]; then
        rm -f "$sql_file" "$err_file"
        success "Panel username/password changed."
        return 0
    fi

    error "Could not change panel username/password. Last database error:"
    sed -n '1,8p' "$err_file" 2>/dev/null
    warning "No data was deleted. Check MySQL access in ${BASE_INFO} and ${CONFIG_FILE}."
    rm -f "$sql_file" "$err_file"
    return 1
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
    local installed token bot_url dom phpv apache_s mysql_s crons days version
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
        kv "Bot path" "${DIM}${BOT_DIR}${NC}"
        kv "Panel path" "${DIM}${PANEL_DIR}${NC}"
        kv "Bot URL" "${DIM}${bot_url:-not set}${NC}"
        kv "Database" "${DIM}$(php_var dbName)${NC}"
        [ -n "$version" ] && kv "Bot version" "${DIM}${version}${NC}"
    elif legacy_baseinfo_exists || legacy_installation_exists; then
        kv "State" "$(dot warn) ${YELLOW}legacy install detected${NC}"
        kv "Action" "Choose ${GREEN}Install / Update${NC} to migrate without deleting data."
    else
        kv "State" "$(dot bad) ${RED}not installed${NC}"
        kv "Bot path" "${DIM}${BOT_DIR}${NC}"
    fi

    section "Services"
    phpv=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo n/a)
    apache_s=$(systemctl is-active apache2 2>/dev/null || echo inactive)
    mysql_s=$(systemctl is-active mysql 2>/dev/null || systemctl is-active mariadb 2>/dev/null || echo inactive)
    kv "PHP" "${DIM}${phpv}${NC}"
    kv "PHP upload" "${DIM}$(php -r 'echo ini_get("upload_max_filesize") . " / " . ini_get("post_max_size");' 2>/dev/null || echo n/a)${NC}"
    [ "$apache_s" = active ] && kv "Apache" "$(dot ok) ${GREEN}active${NC}" || kv "Apache" "$(dot bad) ${RED}${apache_s}${NC}"
    [ "$mysql_s" = active ] && kv "MySQL/MariaDB" "$(dot ok) ${GREEN}active${NC}" || kv "MySQL/MariaDB" "$(dot bad) ${RED}${mysql_s}${NC}"

    section "SSL / Cron / Webhook"
    if [ "$installed" = "yes" ] && [ -n "$dom" ]; then
        days=$(ssl_days_left "$dom" 2>/dev/null || true)
        if [ -n "$days" ]; then
            if [ "$days" -gt 14 ]; then kv "SSL" "$(dot ok) ${GREEN}${days} days left${NC}"
            elif [ "$days" -gt 0 ]; then kv "SSL" "$(dot warn) ${YELLOW}${days} days left${NC}"
            else kv "SSL" "$(dot bad) ${RED}expired${NC}"; fi
        else
            kv "SSL" "$(dot warn) ${YELLOW}certificate not found${NC}"
        fi
        crons=$(cron_count)
        [ "${crons:-0}" -gt 0 ] 2>/dev/null && kv "Cron jobs" "$(dot ok) ${GREEN}${crons} found${NC}" || kv "Cron jobs" "$(dot warn) ${YELLOW}not found${NC}"
        if [ -n "$token" ]; then
            local info ok url pending err
            info=$(curl -fsSL --max-time 8 "https://api.telegram.org/bot${token}/getWebhookInfo" 2>/dev/null || true)
            ok=$(json_value "$info" "ok")
            url=$(json_value "$info" "result.url")
            pending=$(json_value "$info" "result.pending_update_count")
            err=$(json_value "$info" "result.last_error_message")
            if [ "$ok" = "true" ] && [ -n "$url" ]; then
                kv "Webhook" "$(dot ok) ${GREEN}set${NC} ${DIM}(${pending:-0} pending)${NC}"
                [ -n "$err" ] && kv "Webhook error" "$(dot bad) ${RED}${err}${NC}"
            else
                kv "Webhook" "$(dot bad) ${RED}not set / unreachable${NC}"
            fi
        fi
    else
        kv "SSL" "$(dot warn) ${DIM}n/a${NC}"
        kv "Cron jobs" "$(dot warn) ${DIM}n/a${NC}"
        kv "Webhook" "$(dot warn) ${DIM}n/a${NC}"
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
        curl -fsS "$(php_var botUrl)install/update.php" >/dev/null 2>&1 || php "${BOT_DIR}/install/update.php" >/dev/null 2>&1 || true
        migrate_legacy_installation || true
        local panel_failed=0
        install_or_update_panel || panel_failed=1
        local dom token url
        dom=$(current_domain)
        url=$(php_var botUrl)
        token=$(php_var botToken)
        [ -n "$dom" ] && update_crons_for_domain "$dom"
        [ -n "$token" ] && [ -n "$url" ] && set_bot_webhook "$token" "$url" || true
        if [ "$panel_failed" -eq 1 ]; then
            warning "Bot update/migration finished, but panel update failed and the current panel was kept unchanged."
            warning "Add v2raystore-panel.zip to your repository root, then run Install / Update again."
            return 1
        fi
        send_admin_message "✅ ${BRAND_NAME} با موفقیت آپدیت و منتقل شد."
        success "Update/migration finished. Your database, baseInfo.php and panel settings were preserved."
    else
        confirm "No installation found. Install ${BRAND_NAME} now?" || return 0
        install_or_update_bot_files || return 1
        create_database_and_baseinfo || return 1
        curl -fsS "$(php_var botUrl)install/update.php" >/dev/null 2>&1 || php "${BOT_DIR}/install/update.php" >/dev/null 2>&1 || true
        install_or_update_panel || return 1
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
            "Backup"
            "Status / Diagnostics"
            "Quick repair"
            "Change bot token"
            "Change bot domain"
            "Change panel username/password"
            "Repair webhook"
            "SSL tools"
            "Optimize PHP/upload settings"
            "Install local command"
            "Delete"
            "Exit"
        )
        PS3="Please select action: "
        select opt in "${options[@]}"; do
            case "$opt" in
                "Install / Update") full_install_or_update; pause_screen; break ;;
                "Update panel") install_or_update_panel; pause_screen; break ;;
                "Backup") run_backup_setup; pause_screen; break ;;
                "Status / Diagnostics") run_diagnostics; pause_screen; break ;;
                "Quick repair") quick_repair_menu; break ;;
                "Change bot token") change_bot_token; pause_screen; break ;;
                "Change bot domain") change_bot_domain; pause_screen; break ;;
                "Change panel username/password") change_panel_password; pause_screen; break ;;
                "Repair webhook") repair_webhook; pause_screen; break ;;
                "SSL tools") ssl_menu; break ;;
                "Optimize PHP/upload settings") configure_php_performance; pause_screen; break ;;
                "Install local command") install_local_command; pause_screen; break ;;
                "Delete") run_delete; pause_screen; break ;;
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
    backup) run_backup_setup ;;
    diagnostics|diag) run_diagnostics ;;
    repair) quick_repair_menu ;;
    token) change_bot_token ;;
    domain) change_bot_domain ;;
    password|panel-password) change_panel_password ;;
    webhook) repair_webhook ;;
    ssl) ssl_menu ;;
    php|php-tune|optimize) configure_php_performance ;;
    delete|remove) run_delete ;;
    status) show_status ;;
    help|-h|--help)
        echo "${BRAND_NAME}"
        echo "Install/update command: bash <(curl -s ${RAW_INSTALL_URL})"
        echo "Commands: status, diagnostics, repair, panel, backup, token, domain, webhook, ssl, password, php-tune, delete"
        ;;
    *) main_menu ;;
esac
