> **Instalasi Full RADIUS terbaru:** ikuti [INSTALL-FULL-RADIUS.md](INSTALL-FULL-RADIUS.md). Gunakan branch `fix/full-radius-defaults-and-preflight`; halaman luar hanya Admin Settings/Add Router, menu operasional dibuka melalui Open.

# MikroTik RADIUS, Billing, dan Halaman Isolir di Proxmox

Panduan ini menyiapkan Mikhmon + FreeRADIUS + MariaDB untuk pelanggan PPPoE dan Hotspot. Dalam **mode Full RADIUS**, autentikasi, group paket, accounting, perubahan group billing, halaman isolir, dan pemutusan sesi memakai RADIUS Disconnect-Message (DM). Alur setup router dan scheduler billing tidak membutuhkan username/password RouterOS API.

## Target instalasi

- Ubuntu Server 22.04 LTS CT dengan IP LAN tetap dan internet aktif.
- Mulai dari satu router dan akun tes. Jangan pindahkan seluruh pelanggan langsung.
- Untuk sekitar 1.000 pelanggan, mulai dengan 2–4 vCPU, RAM 4 GB, dan SSD; kapasitas nyata bergantung pada jumlah sesi aktif, frekuensi accounting, serta jumlah router.
- Izinkan UDP 1812/1813 hanya dari IP MikroTik yang terdaftar; batasi lewat firewall CT dan Proxmox.

## 1. Instal Mikhmon

~~~bash
apt update
apt install -y git
git clone --branch fix/full-radius-defaults-and-preflight --single-branch https://github.com/fiksum000/mikpusing.git /var/www/mikhmon
cd /var/www/mikhmon
chmod +x deploy/install-ubuntu22.sh
./deploy/install-ubuntu22.sh
~~~

Installer memasang Apache/PHP, membuat password admin Mikhmon secara acak, memasang halaman isolir pada port 8081, membuat cron profil setiap 5 menit, dan generator invoice harian. Catat password awal dari `/root/mikhmon-initial-credentials.txt`.

Di panel Mikhmon, buka **Admin Settings → Add Router — RADIUS NAS**. Masukkan IP LAN CT (`192.168.200.179` pada CT pengguna saat ini), IP sumber MikroTik (NAS), dan IP portal isolir. Halaman menyediakan perintah install FreeRADIUS untuk NAS pertama atau perintah untuk mendaftarkan ulang NAS bila FreeRADIUS sudah dipasang. Mode Full RADIUS memakai `radius-dm` untuk meminta pemutusan sesi melalui UDP 1700, tanpa RouterOS API pada scheduler billing. Shared secret tidak disimpan oleh halaman web.

## 2. Instal FreeRADIUS

Jalankan di CT yang sama sebagai root. Ganti IP contoh di bawah sesuai jaringanmu: `RADIUS_NAS_IP` adalah IP sumber MikroTik ke RADIUS, sedangkan `RADIUS_SERVER_IP` adalah IP LAN CT.

~~~bash
cd /var/www/mikhmon
chmod +x deploy/install-freeradius.sh
RADIUS_NAS_IP=192.0.2.2 RADIUS_SERVER_IP=192.0.2.10 ./deploy/install-freeradius.sh
~~~

Installer membuat password MariaDB dan shared secret RADIUS yang acak, mengimpor schema FreeRADIUS paket Ubuntu, menyiapkan group contoh, serta menaruh kredensial di `/root/mikpusing-radius-credentials.txt` dengan mode 0600. File terlindungi `/etc/mikhmon/radius.php` menyimpan konfigurasi DM untuk scheduler. Jika file itu sudah ada, installer berhenti tanpa mengganti secret; ini mencegah koneksi putus jika perintah ditempel dua kali.

Setelah instalasi RADIUS berhasil, buka **Billing & Integrasi** untuk memeriksa status FreeRADIUS dan simpan IP server, IP sumber NAS, IP portal, harga default, URL publik, serta metode TriPay jika diperlukan. Backend tidak perlu dipilih: alur billing dan scheduler pada branch Full RADIUS ditetapkan ke RADIUS dan tidak menyediakan fallback RouterOS API.

**Jangan matikan atau hapus akun lokal router.** Import user lokal PPPoE/Hotspot ke RADIUS harus dilakukan secara terencana. Dalam mode Full RADIUS, kamu tidak perlu menambahkan router melalui halaman API Mikhmon untuk menjalankan scheduler billing; konfigurasi NAS dan firewall dilakukan sekali melalui Terminal RouterOS.

## 3. Atur group paket PPPoE dan Hotspot

Installer membuat group contoh:
- `PPPOE-10M` — 10M/10M
- `PPPOE-10M-FUP` — 2M/2M
- `HOTSPOT-5M` dan `HOTSPOT-10M`
- `ISOLIR` — rate rendah dan `Mikrotik-Address-List = MIKPUSING-ISOLIR`

Nilai kecepatan di atas hanya contoh. Sesuaikan dengan paket sebenarnya. Buat user melalui menu **User RADIUS PPPoE/Hotspot**.

Di menu **Profile PPPoE & FUP**, klik **Tambah group PPPoE / Hotspot** untuk setiap paket. Nama group normal dan FUP harus sama persis dengan nama group di RADIUS. Paket tanpa FUP boleh mengosongkan group FUP dan mengatur kuota ke 0. Tambahkan group Hotspot di halaman yang sama juga; automasi menggunakan mapping itu untuk kedua jenis layanan.

## 4. Pasang NAT redirect untuk halaman isolir tanpa API

Untuk mode Full RADIUS tanpa API, jalankan aturan berikut sekali di **Terminal MikroTik RouterOS v7** setelah FreeRADIUS aktif dan group `ISOLIR` mengembalikan atribut `Mikrotik-Address-List = MIKPUSING-ISOLIR`. Ganti IP portal jika IP CT berubah.

~~~routeros
/ip firewall nat add chain=dstnat src-address-list=MIKPUSING-ISOLIR protocol=tcp dst-port=80 dst-address-type=!local action=dst-nat to-addresses=192.168.200.179 to-ports=8081 place-before=0 comment="MIKPUSING-ISOLIR-HTTP"
/ip firewall filter add chain=forward action=accept src-address-list=MIKPUSING-ISOLIR dst-address=192.168.200.179 protocol=tcp dst-port=8081 place-before=0 comment="MIKPUSING-ISOLIR-PORTAL"
~~~

Perintah ini dijalankan langsung di Terminal MikroTik, bukan melalui API. Rule menerima hanya sumber pada address-list isolir dan mengalihkan HTTP TCP/80 ke portal CT TCP/8081. Periksa firewall forward dan urutan rule sebelum menerapkan. Hindari menjalankannya berkali-kali karena dapat membuat duplikasi rule. Redirect transparan hanya berlaku untuk **HTTP**; HTTPS tidak dapat dialihkan ke halaman HTTP tanpa error TLS.

## 5. Aktifkan autentikasi dan accounting di MikroTik

Gunakan nilai persis dari `/root/mikpusing-radius-credentials.txt`. Periksa setiap perintah sebelum ditempel di terminal RouterOS:

~~~routeros
/radius add address=<RADIUS_SERVER_IP> service=ppp,hotspot secret=<RADIUS_CLIENT_SECRET> authentication-port=1812 accounting-port=1813 timeout=1s
/radius incoming set accept=yes port=1700
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
/ip hotspot profile set [find] use-radius=yes radius-accounting=yes radius-interim-update=5m
/ip firewall filter add chain=input action=accept src-address=<RADIUS_SERVER_IP> protocol=udp dst-port=1700 place-before=0 comment="MIKPUSING-RADIUS-DM"
~~~

Pada firewall RouterOS, rule input UDP 1700 hanya mengizinkan DM dari IP CT. Di firewall Proxmox/CT, izinkan UDP 1812 dan 1813 hanya dari IP NAS. Alur Add Router saat ini dikerjakan satu NAS per CT; jangan mendaftarkan router kedua memakai form yang sama sebelum dukungan multi-NAS ditambahkan. RouterOS dapat mengecek user lokal terlebih dahulu, jadi untuk tes gunakan username yang belum ada pada user lokal router. Pertahankan akun lokal untuk akses darurat dan jangan arahkan semua pelanggan sebelum uji berhasil.

## 6. Billing, isolir, dan pemulihan otomatis

- Atur harga pelanggan di menu **Billing & TriPay** atau set environment `MIKHMON_DEFAULT_MONTHLY_AMOUNT` bila memang ingin nilai default. Harga 0 tidak membuat invoice dan tidak memicu isolir.
- Cron membuat invoice bulanan satu kali per pelanggan setiap hari; duplikat periode dilewati.
- Cron profil berjalan setiap **5 menit**. Pada mode RADIUS, invoice yang sudah lunas mengembalikan group pelanggan ke group normal, lalu sesi aktif PPPoE/Hotspot diputus agar autentikasi ulang mengambil kebijakan terbaru.
- Setelah callback pembayaran valid masuk, pemulihan ditargetkan maksimal 5 menit hingga scheduler mengembalikan group, ditambah waktu reconnect. Ini mensyaratkan callback diterima, file invoice/storage bisa ditulis, FreeRADIUS dan accounting aktif, serta RouterOS v7 mengizinkan RADIUS DM melalui UDP 1700 dari CT. Jika DM tidak mendapatkan ACK, scheduler mencatatnya dan mencoba ulang tiap 5 menit. Tidak ada jaminan selama jaringan atau service mati.
- Pemakaian bulanan FUP dicatat oleh `cron/radius-accounting-snapshot.php` setiap 5 menit. Jika accounting/interim update tidak masuk, pengguna yang pemakaiannya tidak diketahui tidak otomatis dikenai FUP berdasarkan tebakan.

Lihat log di `storage/pppoe-automation.log`, `storage/invoice-generation.log`, `storage/radius-accounting.log`, dan log Apache. Periksa juga `systemctl status freeradius` dan `journalctl -u freeradius`.

## Keamanan dan migrasi

- Jangan buka UDP 1812/1813 ke Internet publik; whitelist IP router. Batasi UDP 1700 pada firewall MikroTik agar hanya menerima dari IP CT RADIUS.
- Simpan `/etc/mikhmon/radius.php` serta `/root/mikpusing-radius-credentials.txt` sebagai file privat.
- Migrasi user lokal PPPoE/Hotspot terlebih dahulu dengan backup; installer tidak mengubah seluruh pelanggan secara otomatis.
- Backup MariaDB RADIUS dan folder `storage/` secara terpisah. Keduanya dapat berisi password autentikasi, data pelanggan, invoice dan accounting.
