#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Jalankan sebagai root di CT Ubuntu 22.04." >&2
  exit 1
fi

NAS_IP="${1:-}"
python3 - "${NAS_IP}" <<'PY'
import ipaddress, sys
try:
    ipaddress.IPv4Address(sys.argv[1])
except (ValueError, IndexError):
    raise SystemExit("Pemakaian: deploy/register-radius-nas.sh IP_NAS")
PY

APP_DIR="${APP_DIR:-/var/www/mikhmon}"
CREDENTIALS=/root/mikpusing-radius-credentials.txt
RADIUS_CONFIG=/etc/mikhmon/radius.php
CLIENTS_CONF=/etc/freeradius/3.0/clients.conf
SETTINGS="${APP_DIR}/storage/integration_settings.json"

[[ -f "${RADIUS_CONFIG}" && -r "${CREDENTIALS}" && -f "${CLIENTS_CONF}" ]] || {
  echo "FreeRADIUS belum terpasang lengkap. Jalankan installer awal dari halaman Add Router." >&2
  exit 1
}

SECRET="$(sed -n 's/^RADIUS_CLIENT_SECRET=//p' "${CREDENTIALS}" | head -n1)"
SERVER_IP="$(sed -n 's/^RADIUS_SERVER_IP=//p' "${CREDENTIALS}" | head -n1)"
[[ "${SECRET}" =~ ^[A-Za-z0-9._-]{24,128}$ ]] || {
  echo "Shared secret tidak valid atau tidak ditemukan dalam file kredensial root." >&2
  exit 1
}
python3 - "${SERVER_IP}" <<'PY'
import ipaddress, sys
try:
    ipaddress.IPv4Address(sys.argv[1])
except (ValueError, IndexError):
    raise SystemExit("RADIUS_SERVER_IP tidak valid pada file kredensial.")
PY

STAMP="$(date +%Y%m%d-%H%M%S)"
cp -a "${CLIENTS_CONF}" "${CLIENTS_CONF}.bak.${STAMP}"
cp -a "${RADIUS_CONFIG}" "${RADIUS_CONFIG}.bak.${STAMP}"
if [[ -f "${SETTINGS}" ]]; then cp -a "${SETTINGS}" "${SETTINGS}.bak.${STAMP}"; fi
rollback() {
  cp -a "${CLIENTS_CONF}.bak.${STAMP}" "${CLIENTS_CONF}"
  cp -a "${RADIUS_CONFIG}.bak.${STAMP}" "${RADIUS_CONFIG}"
  if [[ -f "${SETTINGS}.bak.${STAMP}" ]]; then cp -a "${SETTINGS}.bak.${STAMP}" "${SETTINGS}"; fi
}
trap rollback ERR

export NAS_IP SECRET SERVER_IP SETTINGS RADIUS_CONFIG CLIENTS_CONF
python3 <<'PY'
import json, os, re
from pathlib import Path

clients = Path(os.environ["CLIENTS_CONF"])
text = clients.read_text()
begin, end = "# BEGIN MIKPUSING CLIENT", "# END MIKPUSING CLIENT"
block = (
    begin + "\n"
    "client mikpusing_router {\n"
    "    ipaddr = " + os.environ["NAS_IP"] + "\n"
    "    secret = " + os.environ["SECRET"] + "\n"
    "    shortname = mikpusing-router\n"
    "    nas_type = mikrotik\n"
    "}\n"
    + end
)
if begin in text and end in text:
    text = re.sub(re.escape(begin) + r".*?" + re.escape(end), block, text, flags=re.S)
else:
    text = text.rstrip() + "\n\n" + block + "\n"
clients.write_text(text)

config_path = Path(os.environ["RADIUS_CONFIG"])
php = config_path.read_text()
values = {
    "ppp_auth_backend": ("'radius'", "'routeros'"),
    "disconnect_mode": ("'radius-dm'", "'routeros-api'"),
    "radius_nas_ip": ("'" + os.environ["NAS_IP"] + "'", "''"),
    "radius_client_secret": ("'" + os.environ["SECRET"] + "'", "''"),
    "radius_disconnect_port": ("1700", "3799")
}
for key, (value, _default) in values.items():
    pattern = re.compile(r"(['\"]" + re.escape(key) + r"['\"]\s*=>\s*)(?:'[^']*'|\"[^\"]*\"|\d+)")
    if pattern.search(php):
        php = pattern.sub(lambda m: m.group(1) + value, php, count=1)
    else:
        closing = re.search(r"\n\s*\);", php)
        if not closing:
            raise SystemExit("Format /etc/mikhmon/radius.php tidak dikenali; tidak diubah lebih lanjut.")
        php = php[:closing.start()] + "\n  '" + key + "' => " + value + "," + php[closing.start():]
config_path.write_text(php)

settings_path = Path(os.environ["SETTINGS"])
settings_path.parent.mkdir(parents=True, exist_ok=True)
try:
    settings = json.loads(settings_path.read_text()) if settings_path.exists() else {}
except Exception:
    settings = {}
if not isinstance(settings, dict):
    settings = {}
settings.update({
    "ppp_auth_backend": "radius",
    "radius_server_ip": os.environ["SERVER_IP"],
    "radius_nas_ip": os.environ["NAS_IP"],
    "updated_at": __import__("datetime").datetime.now().strftime("%Y-%m-%d %H:%M:%S")
})
settings.setdefault("portal_ip", os.environ["SERVER_IP"])
tmp = settings_path.with_suffix(".json.tmp")
tmp.write_text(json.dumps(settings, indent=2, ensure_ascii=False) + "\n")
os.chmod(tmp, 0o640)
tmp.replace(settings_path)
PY

freeradius -XC
systemctl restart freeradius
systemctl is-active --quiet freeradius
chown root:www-data "${RADIUS_CONFIG}"
chmod 0640 "${RADIUS_CONFIG}"
if [[ -f "${SETTINGS}" ]]; then chown www-data:www-data "${SETTINGS}"; chmod 0640 "${SETTINGS}"; fi
trap - ERR

echo "NAS MikroTik ${NAS_IP} terdaftar di FreeRADIUS."
echo "RADIUS server: ${SERVER_IP}"
echo "Mode disconnect: RADIUS Disconnect-Message UDP/1700."
echo "Kredensial tetap privat: ${CREDENTIALS}"
echo "Selanjutnya tempel konfigurasi RouterOS dari panel Add Router; uji satu akun sebelum migrasi pelanggan."
