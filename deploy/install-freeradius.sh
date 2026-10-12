#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then echo "Jalankan sebagai root di CT Ubuntu 22.04." >&2; exit 1; fi
if [[ -r /etc/os-release ]]; then . /etc/os-release; else echo "OS tidak dikenali." >&2; exit 1; fi
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "22.04" ]]; then
  echo "Skrip ini ditargetkan untuk Ubuntu Server 22.04 LTS." >&2; exit 1
fi

APP_DIR="${APP_DIR:-/var/www/mikhmon}"
if [[ ! -f "${APP_DIR}/cron/radius-accounting-snapshot.php" ]]; then echo "Source Mikhmon tidak ditemukan di ${APP_DIR}; clone repo dahulu." >&2; exit 1; fi

RADIUS_NAS_IP="${RADIUS_NAS_IP:-}"
if [[ -z "${RADIUS_NAS_IP}" ]]; then
  read -r -p "IP sumber MikroTik yang akan mengirim request RADIUS (RADIUS_NAS_IP): " RADIUS_NAS_IP
fi
python3 - "${RADIUS_NAS_IP}" <<'PY'
import ipaddress, sys
try: ipaddress.ip_address(sys.argv[1])
except ValueError: raise SystemExit("RADIUS_NAS_IP harus berupa satu alamat IP router yang valid.")
PY

RADIUS_SERVER_IP="${RADIUS_SERVER_IP:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
RADIUS_DB_NAME="${RADIUS_DB_NAME:-radius}"
RADIUS_DB_USER="${RADIUS_DB_USER:-radius}"
RADIUS_DB_PASSWORD="${RADIUS_DB_PASSWORD:-$(openssl rand -hex 24)}"
RADIUS_CLIENT_SECRET="${RADIUS_CLIENT_SECRET:-$(openssl rand -hex 24)}"
for var in RADIUS_DB_NAME RADIUS_DB_USER; do
  val="${!var}"
  [[ "${val}" =~ ^[A-Za-z][A-Za-z0-9_]{0,30}$ ]] || { echo "$var tidak valid." >&2; exit 1; }
done
for var in RADIUS_DB_PASSWORD RADIUS_CLIENT_SECRET; do
  val="${!var}"
  [[ "${val}" =~ ^[A-Za-z0-9._-]{24,128}$ ]] || { echo "$var harus 24-128 karakter dari huruf, angka, titik, garis bawah atau minus." >&2; exit 1; }
done
export RADIUS_DB_NAME RADIUS_DB_USER RADIUS_DB_PASSWORD RADIUS_CLIENT_SECRET RADIUS_NAS_IP

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y freeradius freeradius-mysql freeradius-utils mariadb-server php8.2-mysql openssl python3

SCHEMA="/etc/freeradius/3.0/mods-config/sql/main/mysql/schema.sql"
SQLCONF="/etc/freeradius/3.0/mods-available/sql"
CLIENTSCONF="/etc/freeradius/3.0/clients.conf"
[[ -r "$SCHEMA" && -r "$SQLCONF" && -r "$CLIENTSCONF" ]] || {
  echo "File konfigurasi/schema FreeRADIUS tidak ditemukan pada lokasi Ubuntu 22.04." >&2; exit 1;
}

systemctl enable --now mariadb
mysql --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`${RADIUS_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${RADIUS_DB_USER}'@'localhost' IDENTIFIED BY '${RADIUS_DB_PASSWORD}';
ALTER USER '${RADIUS_DB_USER}'@'localhost' IDENTIFIED BY '${RADIUS_DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON \`${RADIUS_DB_NAME}\`.* TO '${RADIUS_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

mysql --protocol=socket "${RADIUS_DB_NAME}" < "$SCHEMA"

cp -a "$SQLCONF" "${SQLCONF}.bak.$(date +%Y%m%d%H%M%S)"
export SQLCONF
python3 <<'PY'
import os, re
path = os.environ['SQLCONF']
values = {
    'dialect': '"mysql"',
    'server': '"127.0.0.1"',
    'port': '3306',
    'login': '"' + os.environ['RADIUS_DB_USER'] + '"',
    'password': '"' + os.environ['RADIUS_DB_PASSWORD'] + '"',
    'radius_db': '"' + os.environ['RADIUS_DB_NAME'] + '"',
    'read_clients': 'no',
}
with open(path, encoding='utf-8') as f: lines = f.readlines()
seen = set()
for i, line in enumerate(lines):
    m = re.match(r'^(\s*)(dialect|server|port|login|password|radius_db|read_clients)\s*=.*$', line)
    if m and not line.lstrip().startswith('#'):
        key = m.group(2)
        if key in values and key not in seen:
            lines[i] = f'{m.group(1)}{key} = {values[key]}\n'
            seen.add(key)
if 'dialect' not in seen:
    raise SystemExit('Setting dialect aktif tidak ditemukan di modul SQL.')
if 'login' not in seen or 'password' not in seen or 'radius_db' not in seen:
    raise SystemExit('Konfigurasi kredensial SQL FreeRADIUS tidak lengkap; tidak akan restart service.')
with open(path, 'w', encoding='utf-8') as f: f.writelines(lines)
PY

ln -sfn ../mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
cp -a "$CLIENTSCONF" "${CLIENTSCONF}.bak.$(date +%Y%m%d%H%M%S)"
export CLIENTSCONF
python3 <<'PY'
import os, re
path = os.environ['CLIENTSCONF']
with open(path, encoding='utf-8') as f: data = f.read()
begin, end = '# BEGIN MIKPUSING CLIENT', '# END MIKPUSING CLIENT'
block = f"""# BEGIN MIKPUSING CLIENT
client mikpusing_router {{
    ipaddr = {os.environ['RADIUS_NAS_IP']}
    secret = {os.environ['RADIUS_CLIENT_SECRET']}
    shortname = mikpusing-router
    nas_type = mikrotik
}}
# END MIKPUSING CLIENT
"""
if begin in data and end in data:
    data = re.sub(re.escape(begin) + r'.*?' + re.escape(end), block.rstrip(), data, flags=re.S)
else:
    data = data.rstrip() + '\n\n' + block
with open(path, 'w', encoding='utf-8') as f: f.write(data)
PY

install -d -o root -g www-data -m 0750 /etc/mikhmon
cat > /etc/mikhmon/radius.php <<EOF
<?php
// Protected local config; permissions root:www-data 0640. Do not commit this file.
return array(
  'db_host' => '127.0.0.1',
  'db_user' => '${RADIUS_DB_USER}',
  'db_password' => '${RADIUS_DB_PASSWORD}',
  'db_name' => '${RADIUS_DB_NAME}',
  'ppp_auth_backend' => 'radius',
);
EOF
chown root:www-data /etc/mikhmon/radius.php
chmod 0640 /etc/mikhmon/radius.php

freeradius -XC
systemctl enable --now freeradius
systemctl restart freeradius
if ! systemctl is-active --quiet freeradius; then
  journalctl -u freeradius -n 80 --no-pager >&2
  echo "FreeRADIUS gagal aktif; jangan arahkan pelanggan ke server sebelum diperbaiki." >&2
  exit 1
fi

cat > /root/mikpusing-radius-credentials.txt <<EOF
FreeRADIUS / MariaDB credentials (keep secret)
RADIUS_DB_HOST=127.0.0.1
RADIUS_DB_NAME=${RADIUS_DB_NAME}
RADIUS_DB_USER=${RADIUS_DB_USER}
RADIUS_DB_PASSWORD=${RADIUS_DB_PASSWORD}
RADIUS_NAS_IP=${RADIUS_NAS_IP}
RADIUS_SERVER_IP=${RADIUS_SERVER_IP}
RADIUS_CLIENT_SECRET=${RADIUS_CLIENT_SECRET}

RouterOS commands (review address/service before applying):
/radius add address=${RADIUS_SERVER_IP} service=ppp,hotspot secret=${RADIUS_CLIENT_SECRET} authentication-port=1812 accounting-port=1813 timeout=1s
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
/ip hotspot profile set [find] use-radius=yes radius-accounting=yes radius-interim-update=5m

IMPORTANT:
- Restrict UDP 1812/1813 to router IP ${RADIUS_NAS_IP} at host and Proxmox firewalls.
- Keep an existing local break-glass admin user; do not remove local router users until a RADIUS test succeeds.
- Import example groups from db/radius-mikrotik-groups.example.sql and create users in Mikhmon > User RADIUS.
- Existing RouterOS local secrets/hotspot users are not automatically migrated into SQL.
EOF
chmod 0600 /root/mikpusing-radius-credentials.txt

cat > /etc/cron.d/mikhmon-radius <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * www-data cd ${APP_DIR} && /usr/bin/php cron/radius-accounting-snapshot.php >> storage/radius-accounting.log 2>&1
EOF
chmod 0644 /etc/cron.d/mikhmon-radius

cat <<EOF
FreeRADIUS aktif dan konfigurasi database dibuat.
Kredensial privat: /root/mikpusing-radius-credentials.txt
RADIUS host: ${RADIUS_SERVER_IP}
Router NAS IP yang diizinkan: ${RADIUS_NAS_IP}
Group contoh perlu diimpor, lalu buat user tes sebelum mengaktifkan RADIUS produksi.
Jangan ubah semua router/user sekaligus tanpa tes login lokal + RADIUS.
EOF
