#!/usr/bin/env bash
# V2Ray Store installer bootstrap
# Loads the last known-good installer revision, applies the installer/phpMyAdmin fix,
# validates the result, and then runs the complete installer.

set -Eeuo pipefail

BASE_COMMIT="b3d49984c86a66d9fa36bc6210e7a5a35e7a38f0"
BASE_URL="https://raw.githubusercontent.com/0fariid0/v2ray-store/${BASE_COMMIT}/v2raystore.sh"
WORK_DIR="$(mktemp -d /tmp/v2raystore-installer.XXXXXX)"
BASE_FILE="${WORK_DIR}/v2raystore.sh"
PATCH_FILE="${WORK_DIR}/v2raystore-install-fix.patch"
LOG_FILE="${WORK_DIR}/bootstrap.log"

cleanup() {
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

fail() {
    printf '\033[0;31mERROR:\033[0m %s\n' "$*" >&2
    [ -s "$LOG_FILE" ] && tail -n 40 "$LOG_FILE" >&2 || true
    exit 1
}

if [ "$(id -u)" -ne 0 ]; then
    fail "Please run this installer as root."
fi

export DEBIAN_FRONTEND=noninteractive

need_bootstrap=0
command -v curl >/dev/null 2>&1 || command -v wget >/dev/null 2>&1 || need_bootstrap=1
command -v patch >/dev/null 2>&1 || need_bootstrap=1

if [ "$need_bootstrap" -eq 1 ]; then
    command -v apt-get >/dev/null 2>&1 || fail "apt-get is required to install bootstrap dependencies."
    apt-get update -y >"$LOG_FILE" 2>&1 || fail "APT package lists could not be updated."
    apt-get install -y ca-certificates curl patch >>"$LOG_FILE" 2>&1 || fail "Could not install curl/patch."
fi

if command -v curl >/dev/null 2>&1; then
    curl -fL --connect-timeout 20 --retry 3 --retry-delay 2 \
        "$BASE_URL" -o "$BASE_FILE" >"$LOG_FILE" 2>&1 || fail "Could not download the base installer from GitHub."
else
    wget -O "$BASE_FILE" "$BASE_URL" >"$LOG_FILE" 2>&1 || fail "Could not download the base installer from GitHub."
fi

[ -s "$BASE_FILE" ] || fail "The downloaded base installer is empty."
grep -q '^#!/bin/bash' "$BASE_FILE" || fail "The downloaded file is not a valid V2Ray Store installer."

cat > "$PATCH_FILE" <<'V2RAYSTORE_INSTALL_FIX_PATCH'
--- a/v2raystore.sh
+++ b/v2raystore.sh
@@ -16,6 +16,7 @@
 CONFIG_FILE="${CONFIG_DIR}/dbrootv2raystore.txt"
 LOCAL_CMD="/usr/local/bin/v2ray-store"
 LOG_FILE="/tmp/v2raystore_update.log"
+INSTALL_INFO_FILE="/root/v2raystore-install-info.txt"
 DEFAULT_DB_NAME="v2raystore"
 PHP_UPLOAD_LIMIT="1024M"
 PHP_POST_LIMIT="1024M"
@@ -92,32 +93,164 @@
     dpkg --configure -a >/dev/null 2>&1 || true
     apt-get install -f -y >/dev/null 2>&1 || true
 }
+
+enable_ubuntu_universe() {
+    [ -f /etc/os-release ] || return 0
+    # shellcheck disable=SC1091
+    . /etc/os-release
+    [ "${ID:-}" = "ubuntu" ] || return 0
+    DEBIAN_FRONTEND=noninteractive apt-get install -y software-properties-common >/dev/null 2>&1 || true
+    if command -v add-apt-repository >/dev/null 2>&1; then
+        add-apt-repository -y universe >/dev/null 2>&1 || true
+    fi
+}
+
+install_apt_package() {
+    local package="$1" required="${2:-yes}"
+    if dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q 'install ok installed'; then
+        return 0
+    fi
+
+    if ! apt-cache show "$package" >/dev/null 2>&1; then
+        if [ "$required" = "yes" ]; then
+            error "Required package is not available: ${package}"
+            return 1
+        fi
+        warning "Optional package is not available: ${package}"
+        return 0
+    fi
+
+    : > "$LOG_FILE"
+    echo -ne " ${YELLOW}⏳${NC} Installing ${package} ..."
+    if DEBIAN_FRONTEND=noninteractive apt-get install -y "$package" >> "$LOG_FILE" 2>&1; then
+        echo -e "\r ${GREEN}✔${NC} Installing ${package}"
+        return 0
+    fi
+
+    echo -e "\r ${RED}✘${NC} Installing ${package}"
+    tail -n 30 "$LOG_FILE" 2>/dev/null
+    if [ "$required" = "yes" ]; then
+        return 1
+    fi
+    warning "Continuing without optional package: ${package}"
+    return 0
+}
+
+install_mysql_server() {
+    command -v mysql >/dev/null 2>&1 && return 0
+
+    local packages
+    for packages in \
+        "mysql-server mysql-client" \
+        "default-mysql-server default-mysql-client" \
+        "mariadb-server mariadb-client"; do
+        : > "$LOG_FILE"
+        echo -ne " ${YELLOW}⏳${NC} Installing database server ..."
+        if DEBIAN_FRONTEND=noninteractive apt-get install -y $packages >> "$LOG_FILE" 2>&1; then
+            echo -e "\r ${GREEN}✔${NC} Installing database server"
+            break
+        fi
+        echo -e "\r ${YELLOW}↻${NC} Trying another database package ..."
+    done
+
+    if ! command -v mysql >/dev/null 2>&1; then
+        error "MySQL/MariaDB was not installed."
+        tail -n 30 "$LOG_FILE" 2>/dev/null
+        return 1
+    fi
+}
+
+install_phpmyadmin() {
+    export DEBIAN_FRONTEND=noninteractive
+
+    if [ ! -d /usr/share/phpmyadmin ]; then
+        # We do not let the phpMyAdmin package create its own database.
+        # V2Ray Store already creates and manages the required application DB.
+        echo 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2' | debconf-set-selections
+        echo 'phpmyadmin phpmyadmin/dbconfig-install boolean false' | debconf-set-selections
+        install_apt_package phpmyadmin yes || return 1
+    fi
+
+    if [ -f /etc/phpmyadmin/apache.conf ]; then
+        ln -sfn /etc/phpmyadmin/apache.conf /etc/apache2/conf-available/phpmyadmin.conf
+    elif [ -d /usr/share/phpmyadmin ]; then
+        cat > /etc/apache2/conf-available/phpmyadmin.conf <<'APACHECONF'
+Alias /phpmyadmin /usr/share/phpmyadmin
+
+<Directory /usr/share/phpmyadmin>
+    DirectoryIndex index.php
+    Options FollowSymLinks
+    AllowOverride All
+    Require all granted
+</Directory>
+APACHECONF
+    else
+        error "phpMyAdmin files were not found after installation."
+        return 1
+    fi
+
+    a2enconf phpmyadmin >/dev/null 2>&1 || a2enconf phpmyadmin.conf >/dev/null 2>&1 || true
+    if ! apache2ctl configtest >/dev/null 2>&1; then
+        error "Apache configuration is invalid after enabling phpMyAdmin."
+        apache2ctl configtest 2>&1 | tail -n 20
+        return 1
+    fi
+    systemctl reload apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1 || return 1
+
+    if [ ! -f /etc/apache2/conf-enabled/phpmyadmin.conf ] && [ ! -L /etc/apache2/conf-enabled/phpmyadmin.conf ]; then
+        error "phpMyAdmin Apache configuration was not enabled."
+        return 1
+    fi
+    return 0
+}
+
 install_packages() {
     apt_recover
-    apt-get update -y >/dev/null 2>&1 || true
-
-    # Install web/PHP packages separately so a MySQL package-name mismatch
-    # cannot cancel the whole installation on Debian/Ubuntu variants.
-    apt-get install -y apache2 php libapache2-mod-php php-mysql php-mbstring php-zip php-gd php-json php-curl php-soap php-ssh2 php-opcache php-xml php-intl php-bcmath git wget curl unzip openssl ca-certificates certbot python3-certbot-apache >/dev/null 2>&1 || true
-    if ! command -v mysql >/dev/null 2>&1; then
-        apt-get install -y mysql-server mysql-client >/dev/null 2>&1 || true
-    fi
-    if ! command -v mysql >/dev/null 2>&1; then
-        apt-get install -y default-mysql-server default-mysql-client >/dev/null 2>&1 || true
-    fi
-    if ! command -v mysql >/dev/null 2>&1; then
-        apt-get install -y mariadb-server mariadb-client >/dev/null 2>&1 || true
-    fi
-    if ! command -v mysql >/dev/null 2>&1; then
-        error "MySQL/MariaDB client was not installed. Run: apt update && apt install -y default-mysql-server default-mysql-client"
-        return 1
-    fi
+    export DEBIAN_FRONTEND=noninteractive
+    if ! apt-get update -y > "$LOG_FILE" 2>&1; then
+        error "APT package lists could not be updated."
+        tail -n 30 "$LOG_FILE" 2>/dev/null
+        return 1
+    fi
+
+    enable_ubuntu_universe
+    apt-get update -y >/dev/null 2>&1 || true
+
+    local package
+    local required_packages=(
+        apache2 php libapache2-mod-php php-mysql php-mbstring php-zip php-gd
+        php-curl php-soap php-opcache php-xml php-intl php-bcmath
+        git wget curl unzip openssl ca-certificates certbot python3-certbot-apache
+        python3 cron
+    )
+    local optional_packages=(php-ssh2 libssh2-1 libssh2-1-dev)
+
+    for package in "${required_packages[@]}"; do
+        install_apt_package "$package" yes || return 1
+    done
+    for package in "${optional_packages[@]}"; do
+        install_apt_package "$package" no || true
+    done
+
+    install_mysql_server || return 1
+    install_phpmyadmin || return 1
+
     systemctl enable mysql.service >/dev/null 2>&1 || systemctl enable mariadb >/dev/null 2>&1 || true
     systemctl start mysql.service >/dev/null 2>&1 || systemctl start mariadb >/dev/null 2>&1 || true
     systemctl enable apache2 >/dev/null 2>&1 || true
+    systemctl start apache2 >/dev/null 2>&1 || true
     configure_php_performance --quiet
-    systemctl restart apache2 >/dev/null 2>&1 || true
+    systemctl restart apache2 >/dev/null 2>&1 || return 1
     ufw allow 80 >/dev/null 2>&1 || true
     ufw allow 443 >/dev/null 2>&1 || true
+
+    command -v php >/dev/null 2>&1 || { error "PHP command is missing after installation."; return 1; }
+    command -v mysql >/dev/null 2>&1 || { error "MySQL command is missing after installation."; return 1; }
+    php -m 2>/dev/null | grep -qiE '^mysqli$|^pdo_mysql$' || {
+        error "PHP MySQL extension is not enabled."
+        return 1
+    }
+
+    success "Apache, PHP, MySQL/MariaDB and phpMyAdmin are ready."
 }
 install_basic_packages() {
@@ -763,7 +878,7 @@
     return 0
 }
 install_or_update_bot_files() {
-    install_packages
+    install_packages || return 1
     mkdir -p /var/www/html
     backup_path "$BOT_DIR" "bot"
     local tmp_dir
@@ -902,7 +1017,7 @@
     return 1
 }
 install_or_update_panel() {
-    install_packages
+    install_packages || return 1
     local tmp_zip tmp_extract panel_source
     tmp_zip="/tmp/v2raystore-panel.$$.zip"
     tmp_extract="/tmp/v2raystore-panel.$$"
@@ -1103,6 +1218,70 @@
         kv "Webhook" "$(dot warn) ${DIM}n/a${NC}"
     fi
 }
+
+show_install_summary() {
+    [ -f "$BASE_INFO" ] || { error "baseInfo.php was not found."; return 1; }
+
+    local bot_url dom dbname dbuser dbpass token bot_username
+    local panel_url phpmyadmin_url webhook_url apache_state mysql_state phpmyadmin_state
+    bot_url=$(php_var botUrl)
+    dom=$(current_domain)
+    dbname=$(php_var dbName)
+    dbuser=$(php_var dbUserName)
+    dbpass=$(php_var dbPassword)
+    token=$(php_var botToken)
+
+    if [ -n "$dom" ]; then
+        panel_url="https://${dom}/${PANEL_SLUG}/login.php"
+        phpmyadmin_url="https://${dom}/phpmyadmin/"
+    else
+        panel_url="not available"
+        phpmyadmin_url="not available"
+    fi
+    webhook_url="${bot_url%/}/bot.php"
+    bot_username=$(curl -fsSL --max-time 8 "https://api.telegram.org/bot${token}/getMe" 2>/dev/null | sed -n 's/.*"username":"\([^"]*\)".*/\1/p')
+
+    apache_state=$(systemctl is-active apache2 2>/dev/null || echo inactive)
+    mysql_state=$(systemctl is-active mysql 2>/dev/null || systemctl is-active mariadb 2>/dev/null || echo inactive)
+    if [ -d /usr/share/phpmyadmin ] && { [ -e /etc/apache2/conf-enabled/phpmyadmin.conf ] || [ -L /etc/apache2/conf-enabled/phpmyadmin.conf ]; }; then
+        phpmyadmin_state="installed"
+    else
+        phpmyadmin_state="not installed"
+    fi
+
+    banner
+    section "Installation / Access Information"
+    [ -n "$bot_username" ] && kv "Telegram bot" "${GREEN}@${bot_username}${NC}"
+    kv "Bot address" "${CYAN}${bot_url}${NC}"
+    kv "Webhook" "${DIM}${webhook_url}${NC}"
+    kv "Admin panel" "${CYAN}${panel_url}${NC}"
+    kv "phpMyAdmin" "${CYAN}${phpmyadmin_url}${NC}"
+
+    section "Database Information"
+    kv "Database name" "${DIM}${dbname}${NC}"
+    kv "Database username" "${DIM}${dbuser}${NC}"
+    kv "Database password" "${YELLOW}${dbpass}${NC}"
+
+    section "Service Check"
+    [ "$apache_state" = "active" ] && kv "Apache" "$(dot ok) ${GREEN}active${NC}" || kv "Apache" "$(dot bad) ${RED}${apache_state}${NC}"
+    [ "$mysql_state" = "active" ] && kv "MySQL/MariaDB" "$(dot ok) ${GREEN}active${NC}" || kv "MySQL/MariaDB" "$(dot bad) ${RED}${mysql_state}${NC}"
+    [ "$phpmyadmin_state" = "installed" ] && kv "phpMyAdmin" "$(dot ok) ${GREEN}installed${NC}" || kv "phpMyAdmin" "$(dot bad) ${RED}not installed${NC}"
+
+    {
+        echo "${BRAND_NAME} installation information"
+        echo "Bot: ${bot_url}"
+        [ -n "$bot_username" ] && echo "Telegram: @${bot_username}"
+        echo "Webhook: ${webhook_url}"
+        echo "Admin panel: ${panel_url}"
+        echo "phpMyAdmin: ${phpmyadmin_url}"
+        echo "Database name: ${dbname}"
+        echo "Database username: ${dbuser}"
+        echo "Database password: ${dbpass}"
+    } > "$INSTALL_INFO_FILE"
+    chmod 600 "$INSTALL_INFO_FILE" 2>/dev/null || true
+    warning "This information was also saved securely in: ${INSTALL_INFO_FILE}"
+}
+
 full_install_or_update() {
     banner
     local had_legacy=0
@@ -1137,6 +1311,7 @@
         fi
         send_admin_message "✅ ${BRAND_NAME} با موفقیت آپدیت و منتقل شد."
         success "Update/migration finished. Your database, baseInfo.php and panel settings were preserved."
+        show_install_summary
     else
         confirm "No installation found. Install ${BRAND_NAME} now?" || return 0
         install_or_update_bot_files || return 1
@@ -1146,6 +1321,7 @@
         install_or_update_panel || return 1
         send_admin_message "✅ ${BRAND_NAME} با موفقیت نصب شد."
         success "Installation finished."
+        show_install_summary
     fi
 }
 main_menu() {
@@ -1157,6 +1333,7 @@
             "Update panel"
             "Backup"
             "Status / Diagnostics"
+            "Show access information"
             "Quick repair"
             "Change bot token"
             "Change bot domain"
@@ -1175,6 +1352,7 @@
                 "Update panel") install_or_update_panel; pause_screen; break ;;
                 "Backup") run_backup_setup; pause_screen; break ;;
                 "Status / Diagnostics") run_diagnostics; pause_screen; break ;;
+                "Show access information") show_install_summary; pause_screen; break ;;
                 "Quick repair") quick_repair_menu; break ;;
                 "Change bot token") change_bot_token; pause_screen; break ;;
                 "Change bot domain") change_bot_domain; pause_screen; break ;;
@@ -1197,6 +1375,7 @@
     panel) install_or_update_panel ;;
     backup) run_backup_setup ;;
     diagnostics|diag) run_diagnostics ;;
+    info|access) show_install_summary ;;
     repair) quick_repair_menu ;;
     token) change_bot_token ;;
     domain) change_bot_domain ;;
@@ -1209,7 +1388,7 @@
     help|-h|--help)
         echo "${BRAND_NAME}"
         echo "Install/update command: bash <(curl -s ${RAW_INSTALL_URL})"
-        echo "Commands: status, diagnostics, repair, panel, backup, token, domain, webhook, ssl, password, php-tune, delete"
+        echo "Commands: status, info, diagnostics, repair, panel, backup, token, domain, webhook, ssl, password, php-tune, delete"
         ;;
     *) main_menu ;;
 esac
V2RAYSTORE_INSTALL_FIX_PATCH

if ! patch --batch --forward -p1 -d "$WORK_DIR" < "$PATCH_FILE" >"$LOG_FILE" 2>&1; then
    fail "The installer fix could not be applied to the pinned installer revision."
fi

bash -n "$BASE_FILE" || fail "The patched installer failed Bash syntax validation."
chmod +x "$BASE_FILE"

trap - EXIT
exec bash "$BASE_FILE" "$@"
