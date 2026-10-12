# Operasi sekitar 1000 pelanggan

Gunakan CT Ubuntu 22.04, mulai 4 vCPU/4 GB RAM, SSD dan ruang backup terpisah. Angka kapasitas adalah target operasional; ukur pada hardware sebenarnya dengan jumlah sesi dan frekuensi accounting yang dipakai. Bukti benchmark invoice 1000 pelanggan tidak membuktikan 1000 sesi PPPoE simultan.

## Penjadwalan

- Accounting dan automasi paket/isolir: setiap 5 menit.
- Invoice idempotent dan antrean checkout/WA: setiap 5 menit, maksimal 25 invoice atau 180 detik per run (ditambah request terakhir yang sedang berjalan).
- Reminder: setiap 5 menit pukul 09–17 waktu CT; maksimal 25 pengiriman/run. Marker menahan reminder yang sudah sukses hari itu; overdue memakai jeda 3 hari.
- Generator, reminder, dan automasi memiliki lock proses. Pekerjaan baru melewati run yang masih aktif. Pengiriman mengurutkan percobaan paling lama agar pelanggan belakang antrean tetap mendapat giliran.
- Dengan batch 25, 1000 pesan memerlukan setidaknya 40 run (~200 menit) bila semua request lancar. Sesuaikan kapasitas/rate limit gateway, jangan menjanjikan pengiriman 1000 pesan seketika.

Atur `MIKHMON_DELIVERY_BATCH` di `/etc/cron.d/mikhmon` bila perlu (1–100). Pembayaran callback diproses segera; restore layanan mengikuti scheduler 5 menit dan ACK/reconnect.

## Impor dan pencarian

Menu Impor Pelanggan CSV menerima maksimal 1000 baris/2 MB. Header:
`username,password,name,phone,email,profile,amount,due_day`.
Paket wajib ada dahulu. Password kosong hanya untuk user yang sudah terdaftar; kolom metadata memperbarui data lama. Validasi seluruh baris dilakukan sebelum menulis; gangguan database saat eksekusi dilaporkan sebagai impor sebagian. CSV berisi password dan tidak boleh dikomit.

Daftar pelanggan dan billing menampilkan 50 baris per halaman dengan pencarian. Dashboard accounting dibatasi 100 catatan terbaru. Data asli tetap disimpan.

## Pemeriksaan rutin

Jalankan `bash deploy/preflight.sh` setelah deploy/restart. Periksa timestamp accounting dan log cron; lakukan uji login serta DM pada satu akun tes. Simpan backup harian pada lokasi terpisah; jalankan `backup-full-radius.sh` dalam maintenance window karena menghentikan layanan sebentar untuk snapshot konsisten. Uji restore berkala pada clone CT.

Pantau disk, ukuran tabel radacct, waktu query/sampler, RAM, antrean DM, pending checkout tanpa URL, dan kegagalan WA. Jangan menghapus radacct bulan berjalan atau state accounting untuk mengecilkan disk: FUP dapat salah. Rencanakan arsip accounting lama sesuai retensi dan kebutuhan audit, dengan backup sebelum perubahan.

## Batas penting

Billing pelanggan dan marker notifikasi masih memakai JSON dengan file lock dan atomic rename; autentikasi/accounting memakai MariaDB. Jalankan satu instance aplikasi per CT dengan disk lokal. Banyak penulis/riwayat invoice bertahun-tahun perlu benchmark ulang; jangan menaruh storage di NFS atau menjalankan beberapa replika.

Accounting lintas bulan mengikuti delta per 5 menit; pemakaian di sekitar batas bulan tidak memiliki ketelitian perdetik. Invoice lama yang belum lunas tetap diperhitungkan untuk isolir. Paket gratis harus memakai nominal 0 dan default nominal 0; default nominal global mengisi pelanggan yang belum berharga.

Gangguan gateway dapat meninggalkan checkout ambigu. Status tersebut berhenti untuk rekonsiliasi operator, tidak dibuat ulang otomatis. Tidak ada jaminan exactly-once untuk pesan yang sudah diterima gateway tetapi marker lokal gagal tersimpan.

Antrean DM dibatasi 50 paket atau 120 detik per siklus; timeout/NAK tetap diantrekan. Perubahan masal 1000 sesi memerlukan beberapa siklus, jadi target pulih satu siklus hanya berlaku bila antrean tidak penuh.
