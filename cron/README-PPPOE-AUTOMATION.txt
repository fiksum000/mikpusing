AUTOMASI FULL RADIUS PPPoE / HOTSPOT

Atur group normal/FUP dan kuota GiB melalui Paket & FUP. Policy disimpan di MariaDB, bukan PPP secret/API router. Group ISOLIR menggunakan Mikrotik-Address-List.

Accounting dan automasi berjalan setiap 5 menit. Isolir setelah invoice melewati jatuh tempo + 5 hari, termasuk tunggakan lama. Pembayaran memulihkan group sesuai FUP. Sesi aktif disambungkan ulang dengan Disconnect-Message UDP 1700 (maksimal 50 paket/120 detik per siklus). Timeout/NAK tetap diantrekan.

Jalankan dry-run dengan php cron/pppoe_profile_automation.php; --apply menerapkan perubahan. Jangan jalankan uji data buatan di produksi. Panduan: deploy/INSTALL-FULL-RADIUS.md, deploy/PREFLIGHT-MATRIX.md, deploy/OPERATIONS.md.
