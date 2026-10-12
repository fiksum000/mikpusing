#!/bin/bash
# Backup otomatis harian untuk produksi 1000 pelanggan
APP_DIR="/var/www/mikhmon"
BACKUP_DIR="${APP_DIR}/storage/backups"
mkdir -p "${BACKUP_DIR}"
STAMP=$(date +%Y%m%d-%H%M%S)
# Hanya data penting (tanpa log besar)
tar -czf "${BACKUP_DIR}/mikhmon-db-${STAMP}.tar.gz" -C "${APP_DIR}" include/config.php storage/customers.json storage/invoices.json storage/pppoe_packages.json storage/pppoe_usage.json 2>/dev/null || true
# Simpan hanya 7 hari terakhir
find "${BACKUP_DIR}" -name 'mikhmon-db-*.tar.gz' -mtime +7 -delete
echo "$(date): Backup otomatis ${STAMP} selesai"
