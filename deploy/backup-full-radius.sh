#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ "$EUID" -eq 0 ]] || { echo "Jalankan sebagai root." >&2; exit 1; }
APP_DIR="${APP_DIR:-/var/www/mikhmon}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/mikpusing}"
DEST="$BACKUP_ROOT/$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$DEST"
DB=$(php -r '$c=require "/etc/mikhmon/radius.php"; echo $c["db_name"];')
[[ "$DB" =~ ^[A-Za-z][A-Za-z0-9_]{0,30}$ ]] || exit 1
# Use the same locks as application writers; cron is stopped during the snapshot.
was_active=0
if systemctl is-active --quiet cron; then was_active=1; systemctl stop cron; fi
resume() { if [[ "$was_active" == 1 ]]; then systemctl start cron; fi; }
trap resume EXIT
# Put web behind maintenance externally before backup if callbacks/admin writes are active.
mysqldump --single-transaction --routines --triggers --databases "$DB" > "$DEST/radius.sql"
tar --exclude='./.git' --exclude='./storage/backups' -czf "$DEST/app.tar.gz" -C "$APP_DIR" .
tar -czf "$DEST/system.tar.gz" -C / etc/mikhmon etc/freeradius/3.0 etc/cron.d/mikhmon etc/cron.d/mikhmon-radius root/mikpusing-radius-credentials.txt
(cd "$DEST" && sha256sum radius.sql app.tar.gz system.tar.gz > SHA256SUMS)
echo "Backup lengkap: $DEST (privat; salin ke penyimpanan lain)."
