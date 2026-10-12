# Bukti validasi rilis Full RADIUS

Pada 13 Oktober 2026 (UTC+7), CI commit `650d92c760466e0b5896609a82e157c66353a9c5` lulus seluruh job:
https://github.com/fiksum000/mikpusing/actions/runs/38315932485

- Semua PHP, shell installer/backup, dan JavaScript generator lolos validasi sintaks.
- Empat proses bersamaan untuk 1000 pelanggan menghasilkan tepat 1000 invoice, tanpa duplikasi. Ini uji billing, bukan 1000 sesi router simultan.
- Callback HTTP: signature/reference/nominal/status salah ditolak; duplicate PAID, EXPIRED sesudah PAID, refund/stale PAID, WA gagal dan retry diperiksa dengan transport gateway tiruan.
- Ubuntu 22.04 VM: install Apache/PHP/MariaDB/FreeRADIUS/cron dan portal, preflight, daemon UDP Access-Accept/Reject dengan rate dari SQL, accounting Start/Interim/Stop masuk radacct.
- Counter SQL di atas 4 GiB, repeated sampling, delta, sesi berhenti lama, dan state korup diperiksa.
- Impor 1000 pelanggan, impor ulang tanpa duplikasi, serta penolakan CSV invalid menggunakan MariaDB nyata.
- DM ACK/NAK/no-session menggunakan transport tiruan. UDP 1700 ke MikroTik belum diuji.
- Login HTTP, halaman operasional, menu luar/dalam, dan CSRF admin diperiksa.
- Reinstall mempertahankan secret; backup lengkap dipulihkan; seluruh layanan direstart lalu preflight lulus.

Review browser lokal berikutnya menemukan skrip UI lama bergantung pada elemen yang sudah dihapus. Referensinya dihapus, form admin dirapikan, password tersimpan tidak dirender kembali, dan opsi Quick Print yang tidak digunakan dilepas. Periksa checks PR untuk commit terbaru setelah perubahan tampilan ini.

## Batas bukti

VM CI bukan LXC Proxmox. Belum ada bukti PPPoE/Hotspot RouterOS v7 nyata, enforcement rate/FUP, firewall isolir, Disconnect-Message nyata, checkout TriPay, delivery Fonnte ke HP, reboot CT, maupun 1000 sesi simultan. Jangan menganggap PASS di CI sebagai sertifikasi produksi atau jaminan tanpa bug. Jalankan [matriks penerimaan](PREFLIGHT-MATRIX.md) pada akun tes sebelum migrasi pelanggan.
