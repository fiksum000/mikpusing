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
# Brief maintenance window: stop inbound writes and schedulers before both snapshots.
active_services=()
resume() { for service in "${active_services[@]}"; do systemctl start "$service"; done; }
trap resume EXIT
for service in cron apache2 freeradius; do
  if systemctl is-active --quiet "$service"; then
    active_services+=("$service")
    systemctl stop "$service"
  fi
done
# Drain an automation already started before cron was stopped.
exec 9>"$APP_DIR/storage/pppoe-profile-automation.lock"
flock -w 300 9
exec 8>"$APP_DIR/storage/generate-invoices.lock"
flock -w 300 8
exec 7>"$APP_DIR/storage/notify-overdue.lock"
flock -w 300 7
exec 6>"$APP_DIR/storage/radius_accounting.json.lock"
flock -w 300 6
mysqldump --single-transaction --routines --triggers --databases "$DB" > "$DEST/radius.sql"
tar --exclude='./.git' --exclude='./storage/backups' -czf "$DEST/app.tar.gz" -C "$APP_DIR" .
service_config=()
[[ ! -d /etc/systemd/system/freeradius.service.d ]] || service_config+=(etc/systemd/system/freeradius.service.d)
tar -czf "$DEST/system.tar.gz" -C / "${service_config[@]}" etc/mikhmon etc/freeradius/3.0 etc/cron.d/mikhmon etc/cron.d/mikhmon-radius etc/apache2/sites-available/mikhmon.conf etc/apache2/sites-available/mikhmon-isolir.conf root/mikpusing-radius-credentials.txt
(cd "$DEST" && sha256sum radius.sql app.tar.gz system.tar.gz > SHA256SUMS)
echo "Backup lengkap: $DEST (privat; salin ke penyimpanan lain)."
