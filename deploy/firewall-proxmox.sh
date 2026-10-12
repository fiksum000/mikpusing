#!/bin/bash
echo "# Proxmox firewall guidance for Mikhmon:"
echo "# - Block 22/22.22 dari luar (SSH hanya dari IP admin)"
echo "# - Block 80/443 hanya dari jaringan pelanggan + admin"
echo "# - Izinkan 443 https ke router MikroTik API (TCP 8728/8729 jika API aktif)"
echo "# - Gunakan fail2ban jika tersedia"
echo "# - Jangan expose storage/ via web (sudah di Apache deny)"
