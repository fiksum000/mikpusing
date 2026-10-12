#!/usr/bin/env bash
set -Eeuo pipefail
umask 027
. /etc/os-release
if [[ "${ID:-}" != ubuntu || "${VERSION_ID:-}" != 22.04 ]]; then
  echo "Gunakan CT Ubuntu 22.04 LTS." >&2; exit 1
fi
APP_DIR="${APP_DIR:-/var/www/mikhmon}"
WEB_USER="www-data"
PHP_VERSION="8.2"

if [[ "$(id -u)" -ne 0 ]]; then echo "Jalankan sebagai root." >&2; exit 1; fi
if [[ ! -f "${APP_DIR}/admin.php" ]]; then echo "admin.php tidak ditemukan di ${APP_DIR}. Clone source ke APP_DIR dahulu." >&2; exit 1; fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl software-properties-common unzip rsync apache2 mariadb-server cron logrotate openssl python3 util-linux
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y "php${PHP_VERSION}" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-mysql" "libapache2-mod-php${PHP_VERSION}"

# Buat konfigurasi runtime baru setelah PHP tersedia. Password disimpan memakai
# format encrypt() bawaan Mikhmon dan tidak pernah masuk ke Git.
CONFIG_FILE="${APP_DIR}/include/config.php"
if [[ ! -s "${CONFIG_FILE}" ]]; then
  INITIAL_ADMIN_PASSWORD="$(openssl rand -hex 18)"
  INITIAL_ADMIN_PASSWORD_ENC="$(php -r 'require $argv[1]; echo encrypt($argv[2]);' "${APP_DIR}/lib/decrypt_helper.php" "${INITIAL_ADMIN_PASSWORD}")"
  cat > "${CONFIG_FILE}" <<EOF
<?php
\$data['mikhmon'] = array ('1' => 'mikhmon<|<admin', '2' => 'mikhmon>|>${INITIAL_ADMIN_PASSWORD_ENC}');
EOF
  chown root:root "${CONFIG_FILE}"
  chmod 0640 "${CONFIG_FILE}"
  cat > /root/mikhmon-initial-credentials.txt <<EOF
Mikhmon initial login
Username: admin
Password: ${INITIAL_ADMIN_PASSWORD}
Change or rotate this password after the first login.
EOF
  chmod 0600 /root/mikhmon-initial-credentials.txt
  echo "Password login awal dibuat acak dan disimpan di /root/mikhmon-initial-credentials.txt"
fi

a2enmod rewrite headers expires
a2dissite 000-default.conf || true
cat >/etc/apache2/sites-available/mikhmon.conf <<EOF
<VirtualHost *:80>
    ServerName _
    DocumentRoot ${APP_DIR}
    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory ${APP_DIR}/storage>
        Require all denied
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/mikhmon-error.log
    CustomLog \${APACHE_LOG_DIR}/mikhmon-access.log combined
</VirtualHost>
EOF
a2ensite mikhmon.conf

mkdir -p "${APP_DIR}/storage" "${APP_DIR}/storage/backups"
chown -R "${WEB_USER}:${WEB_USER}" "${APP_DIR}/storage"
find "${APP_DIR}" -type d -exec chmod 0755 {} \;
find "${APP_DIR}" -type f -exec chmod 0644 {} \;
chmod -R 0750 "${APP_DIR}/storage"
# File konfigurasi runtime harus bisa dibaca dan diperbarui oleh panel PHP.
chown "${WEB_USER}:${WEB_USER}" "${CONFIG_FILE}" "${APP_DIR}/include/quickbt.php"
chmod 0640 "${CONFIG_FILE}"
chmod +x "${APP_DIR}/cron/pppoe_profile_automation.php" "${APP_DIR}/cron/fonnte_log_cleanup.php" || true

cat >/etc/cron.d/mikhmon <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * ${WEB_USER} cd ${APP_DIR} && /usr/bin/php cron/pppoe_profile_automation.php --apply >> storage/pppoe-automation.log 2>&1
5 0 * * * ${WEB_USER} cd ${APP_DIR} && /usr/bin/php cron/generate-invoices.php >> storage/invoice-generation.log 2>&1
0 9 * * * ${WEB_USER} cd ${APP_DIR} && /usr/bin/php cron/notify_overdue.php >> storage/notify-overdue.log 2>&1
10 2 * * * ${WEB_USER} cd ${APP_DIR} && /usr/bin/php cron/fonnte_log_cleanup.php >> storage/fonnte-cleanup.log 2>&1
EOF
chmod 0644 /etc/cron.d/mikhmon
systemctl enable --now apache2 mariadb cron
apache2ctl configtest
systemctl reload apache2
APP_DIR="${APP_DIR}" PHP_VERSION="${PHP_VERSION}" bash "${APP_DIR}/deploy/install-isolir-portal.sh"
php -v | head -1
echo "Mikhmon terpasang di ${APP_DIR}. Buka http://IP-CT/ dan login dengan kredensial yang Anda tetapkan."
# FreeRADIUS + MySQL RADIUS (opsional — untuk mode RADIUS)
# apt-get install -y freeradius freeradius-mysql freeradius-utils mysql-server
# mysql -u root < db/radius.sql
