> **Instalasi Full RADIUS terbaru:** ikuti [deploy/INSTALL-FULL-RADIUS.md](deploy/INSTALL-FULL-RADIUS.md). Gunakan branch `fix/full-radius-defaults-and-preflight`; halaman luar hanya Admin Settings/Add Router, menu operasional dibuka melalui Open.

# mikpusing — Mikhmon untuk Proxmox CT/LXC

Repositori source Mikhmon beserta skrip instalasi untuk **Ubuntu Server 22.04 LXC/CT di Proxmox**.

## Clone dan instal di CT Proxmox

Jalankan sebagai `root` di CT Ubuntu 22.04 yang memiliki akses internet:

```bash
apt update && apt install -y git
git clone --branch fix/full-radius-defaults-and-preflight --single-branch https://github.com/fiksum000/mikpusing.git /var/www/mikhmon
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

Untuk mode **Full RADIUS tanpa RouterOS API pada scheduler billing**—autentikasi PPPoE + Hotspot, accounting, group paket, FUP, halaman isolir, dan pemulihan setelah pembayaran melalui RADIUS Disconnect-Message di RouterOS v7—ikuti panduan khusus:

[**Panduan MikroTik RADIUS + halaman isolir**](deploy/README-RADIUS.md)

Alur isolir menggunakan group RADIUS `ISOLIR` dengan address-list `MIKPUSING-ISOLIR`. Dalam mode Full RADIUS, konfigurasi RADIUS incoming dan rule firewall portal dilakukan sekali dari halaman **Add Router — RADIUS NAS** lalu ditempel langsung ke Terminal RouterOS v7. Billing dan scheduler tidak memakai RouterOS API.

Scheduler memeriksa status setiap 5 menit dan mengirim Disconnect-Message ke port RouterOS UDP 1700. Pemulihan setelah pembayaran terkonfirmasi ditargetkan sekitar satu siklus scheduler (hingga 5 menit) plus waktu reconnect jika callback diterima, FreeRADIUS/accounting aktif, dan RouterOS mengirim ACK DM. Redirect halaman isolir berlaku untuk HTTP; HTTPS tidak dapat dialihkan transparan ke halaman HTTP tanpa error TLS. Untuk pelanggan PPPoE dan Hotspot, tambahkan semua group normal/FUP/ISOLIR di menu `Profile PPPoE & FUP`.

Untuk menambahkan router tanpa RouterOS API, buka **Admin Settings → Add Router — RADIUS NAS**. Isi IP LAN CT, IP sumber NAS MikroTik, dan IP portal isolir. Jalankan perintah CT yang dihasilkan (instal FreeRADIUS untuk NAS pertama, atau daftar ulang NAS jika RADIUS sudah dipasang), lalu salin perintah RADIUS yang dihasilkan ke Terminal MikroTik v7. Shared secret tidak disimpan di web; baca dari file privat `/root/mikpusing-radius-credentials.txt` di Console CT. Uji satu akun PPPoE dan satu akun Hotspot dulu sebelum memindahkan pelanggan.

## Keamanan

- `include/config.php` adalah konfigurasi runtime dan sengaja **tidak disimpan di Git**. Installer membuatnya dengan password admin acak pada CT baru.
- File lokal `storage/*.json`, log, backup, dan konfigurasi rahasia diabaikan oleh `.gitignore`.
- Batasi akses panel menggunakan firewall/VPN atau reverse proxy HTTPS sebelum dipakai untuk produksi.
- Jangan commit password router, token TriPay/Fonnte, data pelanggan, invoice, atau file backup.

## Backup

Skrip tersedia di `deploy/backup-mikhmon.sh` dan `deploy/restore-mikhmon.sh`. Simpan backup di tempat lain dan perlakukan sebagai data rahasia karena bisa berisi konfigurasi dan data pelanggan.
