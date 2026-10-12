# Instal Full RADIUS di CT Proxmox

Target: CT Ubuntu 22.04 LTS, IP LAN tetap, RouterOS v7, satu NAS per CT. Gunakan minimal 2 vCPU/2 GB RAM dan cadangan disk; 4 GB lebih nyaman. App dan FreeRADIUS/MariaDB dipasang pada CT yang sama. RouterOS API tidak dipakai.

## Fresh install

Jalankan sebagai root pada CT baru:

```bash
apt update && apt install -y git python3
git clone --branch fix/full-radius-defaults-and-preflight --single-branch https://github.com/fiksum000/mikpusing.git /var/www/mikhmon
cd /var/www/mikhmon
bash deploy/install-full-radius.sh
```

Installer meminta IP CT dan IP sumber NAS. Untuk noninteraktif:

```bash
RADIUS_SERVER_IP=192.168.200.179 RADIUS_NAS_IP=192.168.200.1 bash deploy/install-full-radius.sh
```

Ganti IP NAS contoh dengan IP router sebenarnya. Installer memasang Apache/PHP8.2, MariaDB/FreeRADIUS, portal8081 dan cron. Password admin acak ada di `/root/mikhmon-initial-credentials.txt`; login `admin`. Kredensial RADIUS privat ada di `/root/mikpusing-radius-credentials.txt`.

1. Buka `http://IP-CT/` -> Admin Settings -> Add Router. Masukkan IP dan pilih hanya profil Hotspot yang memang akan memakai RADIUS.
2. Bila konfigurasi IP NAS berubah, jalankan perintah registrasi CT yang ditampilkan. Registrasi menggunakan `clients.conf` (`read_clients=no`); tabel SQL `nas` bukan sumber client pada konfigurasi ini.
3. Salin shared secret privat ke field generator lokal di browser; tempel perintah yang dihasilkan ke Terminal RouterOS v7. Password API tidak diperlukan.
4. Kembali ke Admin Settings -> **Open**. Semua menu pelanggan, accounting, paket/FUP, invoice, payment gateway, WhatsApp, integrasi/isolir berada di dalam dashboard.
5. Buat paket/group dan akun tes melalui dashboard. Akun lokal router lama harus dimigrasikan terencana; tidak diimpor otomatis. Gunakan username tes yang tidak ada secara lokal.
6. Atur harga pelanggan, tanggal jatuh tempo, nomor WA, metode TriPay, kredensial sandbox, dan URL HTTPS publik callback. URL callback: `/admin.php?id=tripay-callback`.
7. Jalankan [matriks penerimaan](PREFLIGHT-MATRIX.md). Jangan migrasikan seluruh pelanggan sebelum tes router lulus.

Firewall: UDP1812/1813 hanya dari NAS ke CT; UDP1700 hanya dari CT ke MikroTik; TCP8081 dari jaringan pelanggan ke portal. Terapkan juga firewall Proxmox. Pastikan jam/NTP CT dan router konsisten, misalnya Asia/Jakarta.

Isolir: generator memasang redirect HTTP, allow portal, lalu blok forward lainnya untuk address-list isolir. Tempatkan aturan di atas FastTrack/accept established. Koneksi lama yang sudah FastTrack perlu diputus; verifikasi dengan akun tes. HTTPS tidak bisa di-redirect transparan. Pelanggan terisolir dapat membayar melalui jaringan seluler; akses payment gateway dari jaringan isolir memerlukan allowlist khusus yang dikelola operator.

## Upgrade

Snapshot CT dahulu. Installer bukan migrasi otomatis dari backend API; cek username, paket dan metadata pelanggan lama sebelum menjalankan scheduler Full RADIUS.

```bash
cd /var/www/mikhmon
bash deploy/backup-full-radius.sh
git status --short
git pull --ff-only origin fix/full-radius-defaults-and-preflight
bash deploy/install-full-radius.sh
bash deploy/preflight.sh
```

Jika ada perubahan source lokal, selesaikan terlebih dahulu sebelum pull. `include/config.php`, storage dan `/etc/mikhmon/radius.php` adalah data runtime. Installer ulang tidak merotasi secret atau mengimpor ulang skema DB. Konfigurasi parsial/database lama tanpa radius.php ditolak; pulihkan backup sebelum mencoba lagi. Backup melakukan jeda singkat Apache/FreeRADIUS/cron; jadwalkan maintenance.

## Backup dan pemulihan

`bash deploy/backup-full-radius.sh` menghasilkan folder privat di `/var/backups/mikpusing`: SQL, aplikasi/storage, konfigurasi layanan/secret dan checksum. Salin ke penyimpanan lain. Tidak ada retensi otomatis yang menghapus backup.

Untuk latihan pemulihan pada CT clone dengan stack sudah terpasang dan path aplikasi sama:

```bash
bash deploy/restore-full-radius.sh /var/backups/mikpusing/NAMA-BACKUP --confirm-restore
```

Restore mengganti data DB dan konfigurasi dengan snapshot tersebut; bila validasi gagal, layanan tetap berhenti supaya masalah diperiksa. Ini bukan instalasi bare-metal dan tidak memulihkan firewall/network Proxmox. Reboot CT dan jalankan preflight serta uji autentikasi setelah restore.

## Respons pembayaran ambigu

Sebelum request checkout, invoice ditandai pending. Jika request timeout, crash, atau balasan tanpa reference/URL, checkout tidak diulang otomatis untuk mencegah transaksi ganda. Cocokkan merchant_ref di dashboard TriPay. Operator perlu merekonsiliasi reference/URL dan status invoice dari data provider; jangan mengubah menjadi unpaid sebelum memastikan transaksi memang tidak terbentuk.

WhatsApp memakai marker terpisah untuk invoice dan kuitansi. Kegagalan callback WA mengembalikan 503 agar callback dapat dicoba lagi. Jika gateway sudah menerima pesan tetapi proses berhenti sebelum marker tersimpan, duplikasi pesan masih mungkin; tidak ada jaminan exactly-once dari API gateway. Buktikan delivery di HP, bukan hanya respons HTTP.

## Cakupan pengujian

CI memeriksa PHP/shell/JS, logika invoice/FUP, callback HTTP dengan gateway tiruan, instalasi Ubuntu22.04, SQL accounting64-bit, reinstall dan restart. Ini bukan uji LXC Proxmox atau perangkat MikroTik. Satu NAS per CT adalah batas versi ini; jangan gunakan form untuk mengganti NAS saat pelanggan router lain masih aktif.
