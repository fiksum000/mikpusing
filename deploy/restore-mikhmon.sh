#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${APP_DIR:-/var/www/mikhmon}"
ARCHIVE="${1:-}"
if [[ -z "${ARCHIVE}" || ! -f "${ARCHIVE}" ]]; then echo "Usage: $0 /path/mikhmon-files-YYYYmmdd-HHMMSS.tar.gz" >&2; exit 1; fi
if [[ "$(id -u)" -ne 0 ]]; then echo "Jalankan sebagai root." >&2; exit 1; fi
mkdir -p "$(dirname "${APP_DIR}")"
tar -xzf "${ARCHIVE}" -C "$(dirname "${APP_DIR}")"
chown -R www-data:www-data "${APP_DIR}/storage" 2>/dev/null || true
find "${APP_DIR}" -type d -exec chmod 0755 {} \;
find "${APP_DIR}" -type f -exec chmod 0644 {} \;
chmod -R 0750 "${APP_DIR}/storage" 2>/dev/null || true
systemctl reload apache2 2>/dev/null || true
echo "Restore selesai ke ${APP_DIR}."
