#!/usr/bin/env bash
set -Eeuo pipefail
umask 027
[[ "$EUID" -eq 0 ]] || { echo "Jalankan sebagai root." >&2; exit 1; }
export APP_DIR="${APP_DIR:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)}"
. /etc/os-release
[[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 22.04 ]] || { echo "Perlu Ubuntu 22.04 LTS." >&2; exit 1; }
[[ "$APP_DIR" =~ ^/[A-Za-z0-9_./-]+$ ]] || { echo "APP_DIR tidak valid." >&2; exit 1; }
if [[ ! -f /etc/mikhmon/radius.php ]]; then
  export RADIUS_SERVER_IP="${RADIUS_SERVER_IP:-}"
  export RADIUS_NAS_IP="${RADIUS_NAS_IP:-}"
  [[ -n "$RADIUS_SERVER_IP" ]] || read -r -p "IPv4 LAN CT: " RADIUS_SERVER_IP
  [[ -n "$RADIUS_NAS_IP" ]] || read -r -p "IPv4 sumber MikroTik: " RADIUS_NAS_IP
  python3 - "$RADIUS_SERVER_IP" "$RADIUS_NAS_IP" <<'PY'
import ipaddress, sys
for value in sys.argv[1:]:
    ipaddress.IPv4Address(value)
PY
else
  APP_DIR="$APP_DIR" bash "$APP_DIR/deploy/backup-full-radius.sh"
fi
bash "$APP_DIR/deploy/install-ubuntu22.sh"
bash "$APP_DIR/deploy/install-freeradius.sh"
bash "$APP_DIR/deploy/preflight.sh"
echo "Instalasi selesai. Buka http://IP-CT/ -> Add Router; terapkan konfigurasi RouterOS v7, lalu Open."
echo "Lanjutkan deploy/PREFLIGHT-MATRIX.md untuk uji router, TriPay, dan WhatsApp."
