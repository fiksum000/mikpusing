#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${APP_DIR:-/var/www/mikhmon}"
BACKUP_DIR="${BACKUP_DIR:-${APP_DIR}/storage/backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "${BACKUP_DIR}"
tar --exclude='storage/backups' -czf "${BACKUP_DIR}/mikhmon-files-${STAMP}.tar.gz" -C "$(dirname "${APP_DIR}")" "$(basename "${APP_DIR}")"
if command -v mysqldump >/dev/null 2>&1; then
  if [[ -n "${MARIADB_DATABASE:-}" && -n "${MARIADB_USER:-}" ]]; then
    mysqldump --single-transaction --routines --triggers "${MARIADB_DATABASE}" > "${BACKUP_DIR}/mariadb-${STAMP}.sql"
  fi
fi
find "${BACKUP_DIR}" -type f -mtime +30 -delete
echo "Backup selesai: ${BACKUP_DIR}"
