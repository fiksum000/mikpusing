# Mikpusing — Full RADIUS Billing

Pengelolaan PPPoE/Hotspot RouterOS v7 melalui FreeRADIUS dan MariaDB, dengan invoice, TriPay, WhatsApp, FUP, isolir dan Disconnect-Message UDP1700. Target CT Ubuntu22.04 di Proxmox, satu NAS per CT, sekitar1000 pelanggan.

```bash
apt update && apt install -y git python3
git clone --branch fix/full-radius-defaults-and-preflight --single-branch https://github.com/fiksum000/mikpusing.git /var/www/mikhmon
cd /var/www/mikhmon
bash deploy/install-full-radius.sh
```

Installer meminta IPv4 CT dan IPv4 sumber MikroTik. Login awal: `admin`, password acak di `/root/mikhmon-initial-credentials.txt`.

**Admin Settings -> Add Router -> terapkan konfigurasi RADIUS di terminal MikroTik -> Open.** Di dashboard tersedia pelanggan PPPoE/Hotspot, impor CSV, sesi/accounting, paket/FUP, billing, TriPay, WhatsApp dan isolir. Tidak memakai RouterOS API.

- [Panduan instalasi, upgrade, dan backup/restore](deploy/INSTALL-FULL-RADIUS.md)
- [Matriks uji penerimaan CT, RouterOS, dan provider](deploy/PREFLIGHT-MATRIX.md)
- [Panduan operasi 1000 pelanggan](deploy/OPERATIONS.md)

Tes otomatis mencakup logika invoice dan callback, empat generator bersamaan untuk1000 pelanggan, instalasi Ubuntu, skema accounting, reinstall dan restart. Hasil CI harus diperiksa pada commit yang dipasang. Uji nyata Proxmox, PPPoE/Hotspot MikroTik, UDP1700, TriPay dan WhatsApp tetap diperlukan sebelum memindahkan pelanggan produksi.

Source lama yang khusus RouterOS API dihapus. Data runtime `storage/`, `include/config.php`, dan `/etc/mikhmon/radius.php` tidak disimpan di Git. Jangan menambahkan secret atau backup pelanggan ke repositori.
