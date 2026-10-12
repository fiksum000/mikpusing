> **Instalasi Full RADIUS terbaru:** ikuti [INSTALL-FULL-RADIUS.md](INSTALL-FULL-RADIUS.md). Gunakan branch `fix/full-radius-defaults-and-preflight`; halaman luar hanya Admin Settings/Add Router, menu operasional dibuka melalui Open.

# Deployment Mikhmon pada Ubuntu 22.04 LXC/CT Proxmox

## Rekomendasi CT

- Ubuntu Server 22.04 LTS
- 2 vCPU minimum, 4 vCPU lebih nyaman
- RAM 2 GB minimum, 4 GB untuk monitoring lebih banyak router
- Disk 20 GB minimum, gunakan SSD
- Network bridge `vmbr0`
- IP statis atau DHCP reservation
- NTP/timezone `Asia/Jakarta`
- Akses keluar HTTPS ke TriPay, Fonnte, dan RouterOS

## Cloning CT

1. Buat satu CT dasar Ubuntu 22.04.
2. Set hostname dan IP template.
3. Upload atau clone source ke `/var/www/mikhmon`.
4. Jalankan installer sebagai root:

```bash
cd /var/www/mikhmon/deploy
chmod +x install-ubuntu22.sh backup-mikhmon.sh restore-mikhmon.sh
APP_DIR=/var/www/mikhmon ./install-ubuntu22.sh
```

5. Password admin awal dibuat acak oleh installer dan dicatat di `/root/mikhmon-initial-credentials.txt` (hanya bisa dibaca root). Baca file itu untuk login pertama. Tambahkan session router melalui UI.
6. Login admin dan isi konfigurasi TriPay, Fonnte, mapping profile PPPoE/FUP, dan data pelanggan.

## Clone CT untuk cabang lain

Sebelum clone:

- Jalankan backup.
- Hapus kredensial API production jika template akan dibagikan.
- Jangan menyalin `storage/*.json` jika ingin CT baru tanpa pelanggan/invoice lama.
- Ubah hostname, IP, nama perusahaan, dan nomor admin.
- Buat database/credential terpisah bila modul MariaDB sudah dipakai.

Di Proxmox: **Stop CT → Backup → Restore/Clone → ubah hostname/IP → start CT**.

## Scheduler

Installer membuat:

```text
/etc/cron.d/mikhmon
```

- Profile automation setiap 15 menit.
- Cleanup log Fonnte setiap hari pukul 02:10.

Uji tanpa mengubah RouterOS:

```bash
cd /var/www/mikhmon
php cron/pppoe_profile_automation.php --dry-run
```

## Backup

```bash
APP_DIR=/var/www/mikhmon /var/www/mikhmon/deploy/backup-mikhmon.sh
```

Simpan arsip ke storage lain, bukan hanya disk CT. Backup file saat ini mencakup `include/config.php` dan `storage/*.json`, sehingga perlakukan hasil backup sebagai rahasia.

## Restore

```bash
APP_DIR=/var/www/mikhmon /var/www/mikhmon/deploy/restore-mikhmon.sh /mnt/backup/mikhmon-files-YYYYmmdd-HHMMSS.tar.gz
```

## Keamanan sebelum produksi

- Gunakan password admin acak yang dibuat installer dan simpan secara aman. Setelah dicatat, hapus `/root/mikhmon-initial-credentials.txt`.
- Batasi akses panel dengan firewall/VPN atau reverse proxy HTTPS.
- Jangan commit `include/config.php`, `storage/*.json`, token TriPay, dan token Fonnte ke GitHub.
- Uji TriPay di sandbox terlebih dahulu.
- Pastikan profile `ISOLIR`, profile normal, dan profile FUP tersedia di semua MikroTik.
- Pastikan Simple Queue per pelanggan memiliki nama/target yang cocok dengan username PPPoE.
- Periksa log `storage/pppoe-automation.log` setelah scheduler berjalan.

## Catatan arsitektur saat ini

Source Mikhmon asli tetap dipertahankan. Modul billing, invoice, konfigurasi TriPay, Fonnte, template WhatsApp, usage FUP, dan status pelanggan yang dibuat pada tahap ini menggunakan file JSON di `storage/` agar mudah diclone. MariaDB dapat dipasang oleh installer untuk tahap migrasi data berikutnya; jangan menganggap tabel MariaDB sudah menjadi sumber data utama sebelum migrasi database selesai.

---
## Fitur Pendukung (versi ini)
- DB Schema MariaDB (mikhmon_db) + migrasi JSON
- Bulk Import CSV + Monitor Dashboard
- Auto-notif WA + Backup harian + Firewall Proxmox
- Fix decrypt/encrypt + config + automation
- Semua siap untuk 1000 pelanggan setelah MariaDB aktif
