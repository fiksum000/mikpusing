# mikpusing — Mikhmon untuk Proxmox CT/LXC

Repositori source Mikhmon beserta skrip instalasi untuk **Ubuntu Server 22.04 LXC/CT di Proxmox**.

## Clone dan instal di CT Proxmox

Jalankan sebagai `root` di CT Ubuntu 22.04 yang memiliki akses internet:

```bash
apt update && apt install -y git
git clone https://github.com/fiksum000/mikpusing.git /var/www/mikhmon
cd /var/www/mikhmon
chmod +x deploy/install-ubuntu22.sh
APP_DIR=/var/www/mikhmon ./deploy/install-ubuntu22.sh
```

Setelah installer selesai, lihat password awal yang dibuat otomatis:

```bash
cat /root/mikhmon-initial-credentials.txt
```

Buka `http://IP-CT/`, lalu login dengan username `admin` dan password dari file tersebut. Simpan password dengan aman dan hapus file kredensial setelah dicatat.

## Kebutuhan CT yang disarankan

- Ubuntu Server 22.04 LTS
- 2 vCPU, RAM 2 GB atau lebih, disk 20 GB atau lebih
- Network bridge Proxmox seperti `vmbr0` dan IP yang bisa dijangkau dari LAN
- Koneksi keluar internet untuk pemasangan paket

Installer memasang Apache, PHP 8.2, MariaDB, cron, halaman isolir HTTP pada port 8081, serta penjadwalan profil setiap 5 menit. Tambahkan koneksi MikroTik dari panel setelah login.

## FreeRADIUS, billing otomatis, dan isolir

Untuk autentikasi terpusat PPPoE + Hotspot, accounting, group paket, FUP, halaman isolir, serta pemulihan otomatis setelah pembayaran, ikuti panduan khusus:

[**Panduan MikroTik RADIUS + halaman isolir**](deploy/README-RADIUS.md)

Alur isolir menggunakan group RADIUS `ISOLIR` dengan address-list `MIKPUSING-ISOLIR`. Setelah memasukkan router di Mikhmon, dry-run dahulu konfigurasi redirect, kemudian terapkan dengan IP CT yang benar:

```bash
php deploy/configure-isolir-routeros.php --portal-ip IP_LAN_CT
php deploy/configure-isolir-routeros.php --portal-ip IP_LAN_CT --apply
```

Profil diperiksa setiap 5 menit. Pemulihan setelah pembayaran terkonfirmasi ditargetkan jauh di bawah 20 menit selama callback diterima, FreeRADIUS aktif, serta RouterOS API dapat dijangkau. Redirect halaman berlaku untuk HTTP; HTTPS tidak dapat dialihkan transparan ke halaman HTTP tanpa error TLS. Untuk pelanggan PPPoE dan Hotspot, tambahkan seluruh group normal/FUP dalam menu `Profile PPPoE & FUP`.

## Keamanan

- `include/config.php` adalah konfigurasi runtime dan sengaja **tidak disimpan di Git**. Installer membuatnya dengan password admin acak pada CT baru.
- File lokal `storage/*.json`, log, backup, dan konfigurasi rahasia diabaikan oleh `.gitignore`.
- Batasi akses panel menggunakan firewall/VPN atau reverse proxy HTTPS sebelum dipakai untuk produksi.
- Jangan commit password router, token TriPay/Fonnte, data pelanggan, invoice, atau file backup.

## Backup

Skrip tersedia di `deploy/backup-mikhmon.sh` dan `deploy/restore-mikhmon.sh`. Simpan backup di tempat lain dan perlakukan sebagai data rahasia karena bisa berisi konfigurasi dan data pelanggan.
