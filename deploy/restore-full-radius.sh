#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ "$EUID" -eq 0 ]] || { echo "Jalankan sebagai root." >&2; exit 1; }
SOURCE="${1:-}"
APP_DIR="${APP_DIR:-/var/www/mikhmon}"
[[ "${2:-}" == --confirm-restore && -d "$SOURCE" ]] || { echo "Usage: bash deploy/restore-full-radius.sh BACKUP_DIR --confirm-restore" >&2; exit 1; }
SOURCE=$(realpath "$SOURCE")
[[ "$APP_DIR" =~ ^/[A-Za-z0-9_./-]+$ && "$APP_DIR" != / ]] || exit 1
(cd "$SOURCE" && sha256sum --check SHA256SUMS)
# Consume only archives made by backup-full-radius.sh; reject traversal and links before extraction.
python3 - "$SOURCE" <<'PY'
import pathlib, sys, tarfile
root=pathlib.Path(sys.argv[1])
for name in ('app.tar.gz','system.tar.gz'):
    with tarfile.open(root/name) as archive:
        for entry in archive:
            path=pathlib.PurePosixPath(entry.name)
            if path.is_absolute() or '..' in path.parts or entry.islnk() or not (entry.isfile() or entry.isdir() or entry.issym()):
                raise SystemExit('Unsafe archive member: '+entry.name)
            if entry.issym():
                target = pathlib.PurePosixPath(entry.linkname)
                if name != 'system.tar.gz' or target.is_absolute() or not str(path).startswith('etc/freeradius/3.0/'):
                    raise SystemExit('Unsafe symlink: '+entry.name)
                import posixpath
                resolved=posixpath.normpath(str(path.parent/target))
                if not resolved.startswith('etc/freeradius/3.0/'):
                    raise SystemExit('Symlink escapes FreeRADIUS: '+entry.name)
PY
systemctl stop cron apache2 freeradius
mkdir -p "$APP_DIR"
tar -xzf "$SOURCE/app.tar.gz" -C "$APP_DIR"
tar -xzf "$SOURCE/system.tar.gz" -C /
mysql --protocol=socket < "$SOURCE/radius.sql"
# Restore SQL service account credentials too (system database is deliberately not backed up).
php "$APP_DIR/deploy/restore-db-user.php"
chown -R www-data:www-data "$APP_DIR/storage"
chown www-data:www-data "$APP_DIR/include/config.php" "$APP_DIR/include/quickbt.php"
chmod 0640 "$APP_DIR/include/config.php"
chown root:www-data /etc/mikhmon/radius.php
chmod 0640 /etc/mikhmon/radius.php
apache2ctl configtest
freeradius -XC
systemctl start freeradius apache2 cron
APP_DIR="$APP_DIR" bash "$APP_DIR/deploy/preflight.sh"
