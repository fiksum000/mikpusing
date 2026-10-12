# Penerimaan Full RADIUS — Proxmox CT / RouterOS v7

Status uji live awal: BELUM DIJALANKAN. PASS CI tidak menggantikan bukti CT/router/provider.
Catat untuk setiap ID: commit (`git rev-parse HEAD`), tanggal/zona waktu, versi CT/PHP/MariaDB/FreeRADIUS/ROS, operator, PASS/FAIL/BLOCKED, dan tautan bukti. Hapus password, shared secret, token dan data pelanggan dari bukti publik.

Gunakan satu NAS, satu akun PPPoE dan satu akun Hotspot khusus tes (username tidak boleh berbenturan dengan akun lokal MikroTik). Snapshot CT sebelum upgrade. Gunakan TriPay sandbox dan nomor WhatsApp penguji. Urutan P0 -> P1 -> P2; kegagalan dependensi membuat uji berikutnya BLOCKED.

| ID/prioritas | Langkah yang dijalankan | Bukti | Lulus / gagal | Otomatis / tetap live |
|---|---|---|---|---|
| 01 P0 instalasi | Clone branch rilis; jalankan `bash deploy/install-full-radius.sh`, lalu `bash deploy/preflight.sh` sebagai root | exit code, output preflight, `apache2ctl -S`, commit terpasang | Semua check PASS; root membuka login aplikasi, bukan Apache default. FAIL jika ada layanan/SQL/cron gagal | CI Ubuntu 22.04 fresh-install; ulang di CT Proxmox |
| 02 P0 upgrade | Backup; checkout commit baru, jalankan installer ulang; bandingkan hash radius.php, invoice, pelanggan, dan kredensial | path backup, hash sebelum/sesudah, jumlah baris DB | Secret dan data tetap; konfigurasi/cron tidak ganda. FAIL jika hilang/berubah tak direncanakan | CI reinstall + hash; upgrade dari versi CT lama tetap live |
| 03 P0 navigasi | Login -> Admin Settings -> Open. Buka tiap menu di dashboard | screenshot luar/dalam; status HTTP | Luar hanya Admin Settings/Add Router; operasional berada di dalam; tidak ada permintaan RouterOS API | Smoke navigasi + HTTP CI; tampilan browser CT diperiksa |
| 04 P0 NAS | Simpan Add Router; jalankan registrasi CT dan perintah ROS. Cocokkan IP sumber yang terlihat di capture dengan clients.conf dan radius.php | konfigurasi disensor, `freeradius -XC`, `/radius print detail` (secret disensor) | IP dan secret sama; satu client aktif; repeat tidak mengganti secret | Konfigurasi lokal CI; NAS dan firewall live |
| 05 P0 auth | Login PPPoE lalu Hotspot memakai user RADIUS; ulang dengan password salah; biarkan akun lokal darurat tersedia | Access-Accept/Reject, log ROS, atribut reply | Password benar diterima, salah ditolak, paket sesuai; tidak memakai akun lokal/API | SQL CRUD CI; PPPoE/Hotspot ROS live |
| 06 P0 accounting | Login, transfer >4 GiB bila memungkinkan, tunggu interim 5 menit, putus sesi; jalankan sampler dua kali | Start/Interim/Stop di radacct, total byte, radius_accounting.json | Byte bertambah satu kali, Stop terisi, tidak reset/overflow di 4 GiB. FAIL bila SQL error/duplikasi | Skema DB + delta >4 GiB CI; paket accounting ROS live |
| 07 P0 DM | Ubah paket akun aktif; jalankan scheduler `--apply`. Blok UDP1700 sementara pada NAS tes, ulang lalu buka kembali | capture UDP1700, ACK/NAK, pending queue, log reauth | ACK + sesi lama berakhir + login baru memakai atribut terbaru. Saat timeout antrean tetap, retry berikutnya pulih | Logika/persistensi kode; ACK dan dampak router wajib live |
| 08 P1 paket/FUP | Paket normal, tepat ambang kuota, di atas ambang, usage belum diketahui, pergantian bulan; dry-run dahulu lalu apply | radusergroup/radgroupreply, hasil sampler, log scheduler, throughput | Group dan rate benar; tanpa usage tidak menebak FUP; mapping normal tetap tersimpan. FAIL jika kuota dihitung dua kali/salah paket | Mapping smoke + counter DB CI; rate ROS dan batas bulan live |
| 09 P1 invoice | Buat untuk periode sama dua kali dan dua proses bersamaan; bulan pendek/tahun kabisat; pelanggan harga 0 | jumlah/no invoice, tanggal, log generation | Satu invoice per pelanggan/periode, nominal/tanggal tepat, harga0 tidak ditagih | Smoke idempotensi sequential + locking; konkurensi cron CT diuji |
| 10 P1 TriPay | Checkout sandbox; callback valid, signature salah, reference salah, total salah, duplicate PAID, EXPIRED setelah PAID, refund lalu stale PAID | HTTP code, payload disensor, invoice sebelum/sesudah, dashboard provider | Valid memperbarui; invalid 4xx tanpa perubahan; duplicate tidak menagih/mengirim kuitansi lagi; refund tidak dibatalkan callback lama | HTTP callback fixture CI; checkout/provider HTTPS live |
| 11 P1 WA | Kirim invoice; gagalkan gateway; pulihkan; ulang callback pembayaran | log gateway, timestamp kedua marker, pesan di HP | Gagal tidak diberi marker sukses; retry terkirim; invoice dan kuitansi terpisah. Respons accepted harus dibuktikan sampai HP | Retry callback mock CI; Fonnte dan delivery HP live |
| 12 P1 isolir | Siapkan invoice lewat due+5 hari; bandingkan sebelum batas, paid, harga0, dan tunggakan bulan lama. Apply lalu reconnect | log scheduler, group ISOLIR, address-list, HTTP portal dan percobaan HTTPS/traffic lain | Hanya pelanggan yang memenuhi syarat dibatasi; portal dapat dibuka; akses lain diblok sesuai rule. HTTPS tidak diarahkan ke HTTP | Grace period smoke; firewall/portal ROS live |
| 13 P1 restore layanan pelanggan | Bayar semua tunggakan lewat grace; biarkan invoice baru yang belum overdue; tunggu scheduler | callback timestamp, perubahan group, ACK, sesi baru | Pulih <= satu siklus 5 menit + reconnect saat sehat; FUP tetap berlaku bila kuota tercapai. Bayar bulan ini saja tidak melunasi tunggakan lama | Logika grace/mapping otomatis; rantai lengkap live |
| 14 P2 restart/recovery | Restart Apache/MariaDB/FreeRADIUS/cron, reboot CT, lalu login dan tunggu dua siklus cron; latihan restore backup pada clone CT | preflight, waktu log baru, hash data, hasil login | Layanan enabled/active, cron lanjut, data/secret utuh; antrean DM dapat retry | Restart/reinstall CI; reboot Proxmox dan restore clone live |

## Perintah observasi di CT

```bash
cd /var/www/mikhmon
git rev-parse HEAD
bash deploy/preflight.sh
systemctl status apache2 mariadb freeradius cron --no-pager
apache2ctl -S
sudo -u www-data php cron/radius-accounting-snapshot.php
sudo -u www-data php cron/pppoe_profile_automation.php
# Terapkan hanya setelah hasil dry-run diperiksa pada data/akun tes:
sudo -u www-data php cron/pppoe_profile_automation.php --apply
tail -n 80 storage/pppoe-automation.log storage/invoice-generation.log storage/radius-accounting.log
# Pasang tcpdump jika diperlukan; capture memuat identitas pelanggan, simpan privat.
tcpdump -ni any 'udp port 1812 or udp port 1813 or udp port 1700'
```

CLI scheduler memproses seluruh pelanggan yang dipetakan pada CT tersebut. Gunakan clone CT untuk skenario destruktif, tanggal/kuota buatan, dan kegagalan database. Jangan ubah jam CT produksi untuk tes batas tanggal.

## Keputusan rilis

Siap instalasi uji bila CI head lulus dan 01–04 PASS di CT. Siap memindahkan pelanggan bila semua ID PASS dengan bukti live; FAIL/BLOCKED berarti belum boleh dinyatakan siap produksi. PR tetap draft sampai verifikasi nyata selesai.
