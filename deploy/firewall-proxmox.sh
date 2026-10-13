#!/usr/bin/env bash
# Guidance only; does not modify host/CT firewall rules.
cat <<'GUIDE'
Izinkan UDP 1812/1813 dari IP NAS terdaftar ke CT RADIUS.
Izinkan UDP 1700 dari CT ke router MikroTik.
Izinkan TCP 8081 dari jaringan pelanggan ke portal isolir.
Batasi SSH dan panel admin ke jaringan administrator/VPN.
Callback TriPay perlu HTTPS publik pada /admin.php?id=tripay-callback.
RouterOS API 8728/8729 tidak diperlukan.
Terapkan pembatasan juga pada firewall Proxmox dan router.
GUIDE
