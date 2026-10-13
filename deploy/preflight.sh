#!/usr/bin/env bash
set -uo pipefail
APP_DIR="${APP_DIR:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)}"
[[ "$EUID" -eq 0 ]] || { echo "Jalankan sebagai root." >&2; exit 1; }
failed=0
check() { if "$@"; then echo "PASS $*"; else echo "FAIL $*" >&2; failed=1; fi; }
check apache2ctl configtest
check freeradius -XC
for service in apache2 mariadb freeradius cron; do
  check systemctl is-active --quiet "$service"
  check systemctl is-enabled --quiet "$service"
done
check runuser -u www-data -- php "$APP_DIR/deploy/preflight.php"
check test -s /etc/cron.d/mikhmon
check test -s /etc/cron.d/mikhmon-radius
check grep -q 'pppoe_profile_automation.php --apply' /etc/cron.d/mikhmon
check grep -q 'radius-accounting-snapshot.php' /etc/cron.d/mikhmon-radius
check grep -q "DocumentRoot ${APP_DIR}" /etc/apache2/sites-enabled/mikhmon.conf
code=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1/)
[[ "$code" == 302 ]] || { echo "FAIL root HTTP harus redirect ke login, didapat $code"; failed=1; }
for path in storage/ include/config.php lib/free_radius_client.php deploy/preflight.php cron/generate-invoices.php; do
  code=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1/$path")
  [[ "$code" == 403 ]] || { echo "FAIL HTTP protection $path ($code)"; failed=1; }
done
check curl --fail --silent --output /dev/null http://127.0.0.1:8081/
echo "Preflight lokal selesai. Autentikasi router, accounting, DM, pembayaran, WA, dan isolir perlu bukti live."
exit "$failed"
