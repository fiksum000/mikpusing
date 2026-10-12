#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${APP_DIR:-/var/www/mikhmon}"
PHP_VERSION="${PHP_VERSION:-8.2}"
if [[ "${EUID}" -ne 0 ]]; then echo "Jalankan sebagai root." >&2; exit 1; fi
if [[ ! -f "${APP_DIR}/isolir/index.php" ]]; then echo "Halaman isolir belum ada di ${APP_DIR}/isolir/index.php. Pull source terbaru dulu." >&2; exit 1; fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y apache2 "libapache2-mod-php${PHP_VERSION}" "php${PHP_VERSION}-cli"
a2enmod php${PHP_VERSION} headers
if ! grep -Eq '^[[:space:]]*Listen[[:space:]]+8081([[:space:]]|$)' /etc/apache2/ports.conf; then
  printf '\nListen 8081\n' >> /etc/apache2/ports.conf
fi

cat >/etc/apache2/sites-available/mikhmon-isolir.conf <<EOF
<VirtualHost *:8081>
    ServerName mikpusing-isolir.local
    DocumentRoot ${APP_DIR}/isolir
    <Directory ${APP_DIR}/isolir>
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>
    <FilesMatch "\\.php$">
        SetHandler application/x-httpd-php
    </FilesMatch>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "no-referrer"
    ErrorLog \${APACHE_LOG_DIR}/mikhmon-isolir-error.log
    CustomLog \${APACHE_LOG_DIR}/mikhmon-isolir-access.log combined
</VirtualHost>
EOF
a2ensite mikhmon-isolir.conf
apache2ctl configtest
systemctl enable apache2
systemctl restart apache2
echo "Portal isolir aktif di port 8081."
echo "Pastikan port 8081 hanya dapat dijangkau oleh jaringan pelanggan/router."
echo "Buka Add Router, lalu terapkan aturan isolir yang ditampilkan melalui Terminal RouterOS v7."
