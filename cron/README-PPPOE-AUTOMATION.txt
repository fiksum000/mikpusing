AUTOMASI PROFILE PPPoE, ISOLIR, DAN FUP

Profile yang harus tersedia di setiap MikroTik:
- Profile normal per paket, misalnya PAKET120
- Profile FUP per paket, misalnya PAKET120-FUP
- Profile isolir global, default ISOLIR

Konfigurasi:
1. Login admin Mikhmon.
2. Buka menu Profile PPPoE & FUP.
3. Isi profile normal, profile FUP, dan kuota (GB) per paket.
4. Atur profile isolir global.
5. Pada pelanggan, due_day dapat disimpan melalui halaman Billing.
6. Jika ingin override per user, tambahkan pada comment PPP Secret:
   BILLING=10;ISOLIR=15

Perilaku:
- Invoice paid: profile kembali ke profile normal paket.
- Invoice belum paid dan tanggal isolir tercapai: profile menjadi ISOLIR.
- Belum isolir dan pemakaian queue mencapai kuota: profile menjadi profile FUP paket.
- Pemakaian queue disimpan per router, username, dan periode pada storage/pppoe_usage.json.

Uji tanpa mengubah router:
php cron/pppoe_profile_automation.php --dry-run

Jalankan produksi setiap 15 menit:
*/15 * * * * cd /var/www/mikhmon && /usr/bin/php cron/pppoe_profile_automation.php >> storage/pppoe-automation.log 2>&1

Catatan:
- FUP memakai counter Simple Queue yang nama atau targetnya mengandung username PPPoE.
- Pastikan queue dibuat per pelanggan dan counter RouterOS tersedia.
- Jalankan cron dengan user web server agar storage dapat ditulis.
- Scheduler tidak men-disable PPP Secret; hanya mengganti profile.
