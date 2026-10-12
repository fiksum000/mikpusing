# Mikhmon v3 Performance (1000 pelanggan PPPOE/Hotspot)
- Database: MariaDB dengan index pada customers.username, invoices.period, pppoe_usage.key_token
- JSON fallback: hanya untuk sementara sebelum migrasi penuh
- Automation cron: 15 menit per router; batasi koneksi per sesi
- Storage: chmod 0750, Apache deny pada storage/
- Backup: harian, hanya file penting (tanpa log besar), retensi 7 hari
- Cache: pertimbangkan Redis/Memcached jika load > 500 concurrent
