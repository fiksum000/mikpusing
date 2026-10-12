# MikroTik RADIUS, Billing, dan Halaman Isolir di Proxmox

Panduan ini menyiapkan Mikhmon + FreeRADIUS + MariaDB untuk pelanggan PPPoE dan Hotspot. RADIUS menangani autentikasi, group paket, serta accounting. Mikhmon masih memakai RouterOS API untuk administrasi router dan untuk memutus sesi setelah group pelanggan berubah.

## Target instalasi

- Ubuntu Server 22.04 LTS CT dengan IP LAN tetap dan internet aktif.
- Mulai dari satu router dan akun tes. Jangan pindahkan seluruh pelanggan langsung.
- Untuk sekitar 1.000 pelanggan, mulai dengan 2–4 vCPU, RAM 4 GB, dan SSD; kapasitas nyata bergantung pada jumlah sesi aktif, frekuensi accounting, serta jumlah router.
- Izinkan UDP 1812/1813 hanya dari IP MikroTik yang terdaftar; batasi lewat firewall CT dan Proxmox.

## 1. Instal Mikhmon

~~~bash
apt update
apt install -y git
git clone https://github.com/fiksum000/mikpusing.git /var/www/mikhmon
cd /var/www/mikhmon
chmod +x deploy/install-ubuntu22.sh
./deploy/install-ubuntu22.sh
~~~

Installer memasang Apache/PHP, membuat password admin Mikhmon secara acak, memasang halaman isolir pada port 8081, membuat cron profil setiap 5 menit, dan generator invoice harian. Catat password awal dari `/root/mikhmon-initial-credentials.txt`.

Di panel Mikhmon, buka **Billing & TriPay → Integrasi Billing / MikroTik / RADIUS**. Masukkan IP LAN CT, IP sumber router (NAS), IP portal isolir, dan **Harga default bulanan**. Harga 0 sengaja tidak membuat invoice otomatis. Halaman ini membuat perintah siap salin untuk pemasangan RADIUS, pengaktifan pada RouterOS, isolir, dan tes service. Jangan menempelkan secret ke chat atau source code.

## 2. Instal FreeRADIUS

Jalankan di CT yang sama sebagai root. Ganti IP contoh di bawah sesuai jaringanmu: `RADIUS_NAS_IP` adalah IP sumber MikroTik ke RADIUS, sedangkan `RADIUS_SERVER_IP` adalah IP LAN CT.

~~~bash
cd /var/www/mikhmon
chmod +x deploy/install-freeradius.sh
RADIUS_NAS_IP=192.0.2.2 RADIUS_SERVER_IP=192.0.2.10 ./deploy/install-freeradius.sh
~~~

Installer membuat password MariaDB dan shared secret RADIUS yang acak, mengimpor schema FreeRADIUS paket Ubuntu, menyiapkan group contoh, serta menaruh kredensial di `/root/mikpusing-radius-credentials.txt` dengan mode 0600. Jika file `/etc/mikhmon/radius.php` sudah ada, installer berhenti tanpa mengganti secret; ini mencegah koneksi putus jika perintah ditempel dua kali.

Setelah instalasi RADIUS berhasil, kembali ke **Billing & Integrasi**, pilih backend **FreeRADIUS + MariaDB**, lalu klik **Simpan pengaturan integrasi**. Pilihan ini membuat scheduler Billing menggunakan group RADIUS; memasang service saja tidak otomatis mengganti mode aplikasi.

**Jangan matikan akun lokal router.** Import user lokal ke RADIUS harus dilakukan secara terencana. Tambah router di Mikhmon menggunakan RouterOS API yang hanya dapat diakses dari CT/admin.

## 3. Atur group paket PPPoE dan Hotspot

Installer membuat group contoh:
- `PPPOE-10M` — 10M/10M
- `PPPOE-10M-FUP` — 2M/2M
- `HOTSPOT-5M` dan `HOTSPOT-10M`
- `ISOLIR` — rate rendah dan `Mikrotik-Address-List = MIKPUSING-ISOLIR`

Nilai kecepatan di atas hanya contoh. Sesuaikan dengan paket sebenarnya. Buat user melalui menu **User RADIUS PPPoE/Hotspot**.

Di menu **Profile PPPoE & FUP**, klik **Tambah group PPPoE / Hotspot** untuk setiap paket. Nama group normal dan FUP harus sama persis dengan nama group di RADIUS. Paket tanpa FUP boleh mengosongkan group FUP dan mengatur kuota ke 0. Tambahkan group Hotspot di halaman yang sama juga; automasi menggunakan mapping itu untuk kedua jenis layanan.

## 4. Pasang NAT redirect untuk halaman isolir

Setelah menambahkan router ke daftar Mikhmon dan memastikan koneksi RouterOS API berhasil, jalankan perintah ini dari CT. Gunakan IP LAN CT yang bisa dijangkau pelanggan/router.

~~~bash
cd /var/www/mikhmon
php deploy/configure-isolir-routeros.php --portal-ip 192.0.2.10
~~~

Perintah di atas hanya **dry-run** dan tidak mengubah router. Jika ringkasan yang ditampilkan benar, terapkan:

~~~bash
php deploy/configure-isolir-routeros.php --portal-ip 192.0.2.10 --apply
~~~

Ganti `192.0.2.10` dengan IP CT asli. Script membuat/memperbarui rule dst-nat port TCP 80 untuk sumber pada address-list `MIKPUSING-ISOLIR` menuju port 8081, serta rule allow khusus ke portal. Group `ISOLIR` harus memberikan attribute `Mikrotik-Address-List` agar RouterOS memasukkan alamat sesi aktif ke list tersebut.

Halaman `http://... ` memeriksa ulang otomatis setiap 45 detik. Redirect transparan hanya untuk **HTTP**. Browser tidak dapat dialihkan secara aman dari situs HTTPS ke halaman HTTP tanpa peringatan TLS; saat isolir, sebagian situs HTTPS hanya akan gagal dimuat. Ini batasan cara kerja TLS, bukan bug halaman isolir.

## 5. Aktifkan autentikasi dan accounting di MikroTik

Gunakan nilai persis dari `/root/mikpusing-radius-credentials.txt`. Periksa setiap perintah sebelum ditempel di terminal RouterOS:

~~~routeros
/radius add address=<RADIUS_SERVER_IP> service=ppp,hotspot secret=<RADIUS_CLIENT_SECRET> authentication-port=1812 accounting-port=1813 timeout=1s
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
/ip hotspot profile set [find] use-radius=yes radius-accounting=yes radius-interim-update=5m
~~~

Jika ada beberapa router, setiap IP sumber NAS harus ditambahkan sebagai client RADIUS yang sah. Script installer awal hanya mendaftarkan satu IP NAS. Jangan tutup akses admin lokal sebelum satu akun tes PPPoE dan satu akun tes Hotspot berhasil autentikasi serta mencatat accounting di `radacct`.

## 6. Billing, isolir, dan pemulihan otomatis

- Atur harga pelanggan di menu **Billing & TriPay** atau set environment `MIKHMON_DEFAULT_MONTHLY_AMOUNT` bila memang ingin nilai default. Harga 0 tidak membuat invoice dan tidak memicu isolir.
- Cron membuat invoice bulanan satu kali per pelanggan setiap hari; duplikat periode dilewati.
- Cron profil berjalan setiap **5 menit**. Pada mode RADIUS, invoice yang sudah lunas mengembalikan group pelanggan ke group normal, lalu sesi aktif PPPoE/Hotspot diputus agar autentikasi ulang mengambil kebijakan terbaru.
- Setelah callback pembayaran valid masuk, pemulihan ditargetkan maksimal 5 menit plus waktu reconnect, jauh di bawah 20 menit. Ini mensyaratkan callback pembayaran diterima, file invoice/storage bisa ditulis, RADIUS hidup, dan setiap router terkait terdaftar serta dapat dijangkau lewat API. Kegagalan koneksi dicatat dan proses kick dicoba ulang.
- Pemakaian bulanan FUP dicatat oleh `cron/radius-accounting-snapshot.php` setiap 5 menit. Jika accounting/interim update tidak masuk, pengguna yang pemakaiannya tidak diketahui tidak otomatis dikenai FUP berdasarkan tebakan.

Lihat log di `storage/pppoe-automation.log`, `storage/invoice-generation.log`, `storage/radius-accounting.log`, dan log Apache. Periksa juga `systemctl status freeradius` dan `journalctl -u freeradius`.

## Keamanan dan migrasi

- Jangan buka UDP 1812/1813 ke Internet publik; whitelist IP router.
- Simpan `/etc/mikhmon/radius.php` serta `/root/mikpusing-radius-credentials.txt` sebagai file privat.
- Migrasi user lokal PPPoE/Hotspot terlebih dahulu dengan backup; installer tidak mengubah seluruh pelanggan secara otomatis.
- Backup MariaDB RADIUS dan folder `storage/` secara terpisah. Keduanya dapat berisi password autentikasi, data pelanggan, invoice dan accounting.
