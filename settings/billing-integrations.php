<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
require_once('./lib/free_radius_client.php');
require_once('./include/pppoe_packages.php');
require_once('./include/tripay.php');
$settingsPath = __DIR__ . '/../storage/integration_settings.json';
$settings = mikhmonIntegrationSettings();
$baseRadius = mikhmonRadiusBaseConfig();
$currentBackend = strtolower((string)($settings['ppp_auth_backend'] ?? ($baseRadius['ppp_auth_backend'] ?? 'routeros')));
if (!in_array($currentBackend, array('routeros','radius'), true)) $currentBackend = 'routeros';
$settings = array_merge(array(
  'ppp_auth_backend' => $currentBackend,
  'radius_server_ip' => '',
  'radius_nas_ip' => '',
  'portal_ip' => '',
  'hotspot_profiles' => '',
  'default_monthly_amount' => 0,
  'public_base_url' => '',
  'default_payment_method' => ''
), $settings);
if (empty($_SESSION['billing_integrations_csrf'])) $_SESSION['billing_integrations_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['billing_integrations_csrf'];
$message = ''; $messageType = 'info';
$radiusConfigPath = getenv('MIKHMON_RADIUS_CONFIG') ?: '/etc/mikhmon/radius.php';
$radiusReady = is_file($radiusConfigPath) && is_readable($radiusConfigPath) && !empty($baseRadius['db_password']);
$tripayStatus = tripayConfig();
$tripayReady = !empty($tripayStatus['api_key']) && !empty($tripayStatus['private_key']) && !empty($tripayStatus['merchant_code']);
$secretShapeValid = true;

function billingIntegrationValidIpv4($value) {
  return $value === '' || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}
function billingIntegrationValidPublicBaseUrl($value) {
  if ($value === '') return true;
  $parts = parse_url($value);
  return is_array($parts) && !empty($parts['scheme']) && !empty($parts['host']) && in_array(strtolower((string)$parts['scheme']), array('http','https'), true) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
}
function billingIntegrationSaveSettings($path, $data) {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;
  $tmp = $path . '.tmp';
  if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  @chmod($tmp, 0640);
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_integration_settings'])) {
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $message = 'Permintaan ditolak karena token keamanan tidak cocok. Muat ulang halaman.'; $messageType = 'danger';
  } else {
    $backend = strtolower(trim((string)($_POST['ppp_auth_backend'] ?? 'routeros')));
    $radiusServerIp = trim((string)($_POST['radius_server_ip'] ?? ''));
    $radiusNasIp = trim((string)($_POST['radius_nas_ip'] ?? ''));
    $portalIp = trim((string)($_POST['portal_ip'] ?? ''));
    $hotspotProfiles = trim((string)($_POST['hotspot_profiles'] ?? ''));
    $defaultMonthlyAmount = max(0, (int)($_POST['default_monthly_amount'] ?? 0));
    $publicBaseUrl = rtrim(trim((string)($_POST['public_base_url'] ?? '')), '/');
    $defaultPaymentMethod = strtoupper(trim((string)($_POST['default_payment_method'] ?? '')));
    $validPaymentMethods = array('', 'QRIS', 'DANA', 'OVO', 'SHOPEEPAY', 'BCAVA', 'BRIVA', 'MANDIRIVA', 'ALFAMART', 'INDOMARET');
    if (!in_array($backend, array('routeros','radius'), true)) {
      $message = 'Pilih backend RouterOS lokal atau FreeRADIUS.'; $messageType = 'danger';
    } elseif (!billingIntegrationValidIpv4($radiusServerIp) || !billingIntegrationValidIpv4($radiusNasIp) || !billingIntegrationValidIpv4($portalIp)) {
      $message = 'Alamat IP harus IPv4 yang valid. Kosongkan jika belum diketahui.'; $messageType = 'danger';
    } elseif ($backend === 'radius' && ($radiusServerIp === '' || $radiusNasIp === '')) {
      $message = 'Mode RADIUS memerlukan IP LAN CT dan IP sumber MikroTik (NAS).'; $messageType = 'danger';
    } elseif ($backend === 'radius' && !$radiusReady) {
      $message = 'FreeRADIUS belum dikonfigurasi. Jalankan installer RADIUS satu kali di CT terlebih dahulu; mode tidak diubah.'; $messageType = 'danger';
    } elseif (!billingIntegrationValidPublicBaseUrl($publicBaseUrl)) {
      $message = 'URL publik harus berupa http:// atau https:// tanpa username/password, query, atau fragment.'; $messageType = 'danger';
    } elseif (!in_array($defaultPaymentMethod, $validPaymentMethods, true)) {
      $message = 'Metode pembayaran default tidak valid.'; $messageType = 'danger';
    } elseif ($defaultPaymentMethod !== '' && ($publicBaseUrl === '' || !$tripayReady)) {
      $message = 'Untuk checkout otomatis, atur URL publik di sini dan konfigurasi kredensial TriPay di Pengaturan TriPay dahulu.'; $messageType = 'danger';
    } elseif ($hotspotProfiles !== '' && !preg_match('/^[A-Za-z0-9_. ,@-]{1,500}$/', $hotspotProfiles)) {
      $message = 'Nama profile Hotspot hanya boleh berisi huruf, angka, titik, garis bawah, spasi, koma, @, dan minus.'; $messageType = 'danger';
    } else {
      $settings = array(
        'ppp_auth_backend' => $backend,
        'radius_server_ip' => $radiusServerIp,
        'radius_nas_ip' => $radiusNasIp,
        'portal_ip' => $portalIp,
        'hotspot_profiles' => $hotspotProfiles,
        'default_monthly_amount' => $defaultMonthlyAmount,
        'public_base_url' => $publicBaseUrl,
        'default_payment_method' => $defaultPaymentMethod,
        'updated_at' => date('Y-m-d H:i:s')
      );
      if (billingIntegrationSaveSettings($settingsPath, $settings)) {
        $currentBackend = $backend;
        $message = 'Pengaturan integrasi tersimpan. Scheduler membaca pilihan backend ini pada eksekusi berikutnya. Secret RADIUS tidak disimpan oleh halaman ini.';
        $messageType = 'success';
      } else {
        $message = 'Tidak bisa menyimpan pengaturan. Periksa kepemilikan/hak akses folder storage.'; $messageType = 'danger';
      }
    }
  }
}

$radiusSecretFromPost = ''; // Never persist shared secrets in the web configuration.
?>
<div class="row"><div class="col-12">
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa fa-plug"></i> Billing & Integrasi MikroTik / RADIUS</h3></div>
    <div class="card-body">
      <?php if ($message !== '') { ?><div class="bg-<?= $messageType; ?> pd-10 mb-3"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
      <p class="text-muted">Pilih backend yang dipakai scheduler Billing. IP di bawah disimpan lokal; password database dan shared secret RADIUS tidak disimpan di pengaturan web. Dalam mode Full RADIUS, scheduler tidak memerlukan RouterOS API; perintah RouterOS disalin lalu dijalankan langsung di terminal router.</p>
      <p><a href="./admin.php?id=pppoe-packages" class="group-item group-item-md">Mapping paket PPPoE / Hotspot / FUP / Isolir</a></p>
      <small class="text-muted">Jalur billing Full RADIUS tidak menggunakan RouterOS API. Add Router — RADIUS NAS digunakan untuk menyiapkan MikroTik sebagai client FreeRADIUS; secret tidak disimpan di source code atau pengaturan web.</small>
      <div class="mb-3">
        <span class="badge <?= $currentBackend === 'radius' ? 'bg-success' : 'bg-info'; ?>">Backend saat ini: <?= strtoupper(htmlspecialchars($currentBackend, ENT_QUOTES, 'UTF-8')); ?></span>
        <span class="badge <?= $radiusReady ? 'bg-success' : 'bg-warning'; ?>">FreeRADIUS <?= $radiusReady ? 'terkonfigurasi' : 'belum terpasang/konfigurasi'; ?></span>
        <span class="badge <?= $tripayReady ? 'bg-success' : 'bg-warning'; ?>">TriPay <?= $tripayReady ? 'terkonfigurasi' : 'belum terkonfigurasi'; ?></span>
      </div>
      <form method="post" action="./admin.php?id=billing-integrations" class="mb-4">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"/>
        <div class="row">
          <div class="col-md-4 mb-3"><label>Backend autentikasi</label>
            <select class="form-control" name="ppp_auth_backend" id="backendSelect">
              <option value="routeros" <?= $currentBackend === 'routeros' ? 'selected' : ''; ?>>MikroTik lokal (PPPoE saja)</option>
              <option value="radius" <?= $currentBackend === 'radius' ? 'selected' : ''; ?> <?= !$radiusReady ? 'disabled' : ''; ?>>FreeRADIUS + MariaDB (PPPoE/Hotspot)</option>
            </select>
          </div>
          <div class="col-md-4 mb-3"><label>IP LAN CT Mikhmon / RADIUS</label>
            <input class="form-control" id="radiusServerIp" name="radius_server_ip" inputmode="decimal" placeholder="contoh 192.168.88.10" value="<?= htmlspecialchars((string)$settings['radius_server_ip'], ENT_QUOTES, 'UTF-8'); ?>"/>
          </div>
          <div class="col-md-4 mb-3"><label>IP sumber MikroTik (NAS)</label>
            <input class="form-control" id="radiusNasIp" name="radius_nas_ip" inputmode="decimal" placeholder="contoh 192.168.88.1" value="<?= htmlspecialchars((string)$settings['radius_nas_ip'], ENT_QUOTES, 'UTF-8'); ?>"/>
          </div>
          <div class="col-md-6 mb-3"><label>IP LAN CT untuk halaman isolir</label>
            <input class="form-control" id="portalIp" name="portal_ip" inputmode="decimal" placeholder="contoh 192.168.88.10" value="<?= htmlspecialchars((string)$settings['portal_ip'], ENT_QUOTES, 'UTF-8'); ?>"/>
            <small class="text-muted">Port halaman: TCP 8081; jangan dibuka ke Internet publik.</small>
          </div>
          <div class="col-md-6 mb-3"><label>Nama profile Hotspot (pisahkan koma)</label>
            <input class="form-control" id="hotspotProfiles" name="hotspot_profiles" placeholder="contoh hsprof1, hotspot-office" value="<?= htmlspecialchars((string)$settings['hotspot_profiles'], ENT_QUOTES, 'UTF-8'); ?>"/>
            <small class="text-muted">Digunakan untuk membentuk perintah RouterOS yang spesifik; tidak mengubah profile yang tidak tercantum.</small>
          </div>
          <div class="col-md-6 mb-3"><label>Harga default bulanan (Rp)</label>
            <input class="form-control" type="number" min="0" step="1000" name="default_monthly_amount" value="<?= (int)($settings['default_monthly_amount'] ?? 0); ?>"/>
            <small class="text-muted">Dipakai scheduler invoice harian sebagai harga cadangan. Nilai 0 berarti invoice tidak dibuat otomatis dan pelanggan tidak diisolir karena invoice yang tidak ada.</small>
          </div>
          <div class="col-md-6 mb-3"><label>URL publik Billing (wajib untuk callback otomatis)</label>
            <input class="form-control" type="url" name="public_base_url" placeholder="https://billing.domainkamu.id" value="<?= htmlspecialchars((string)($settings['public_base_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"/>
            <small class="text-muted">Gunakan URL yang bisa diakses TriPay dari internet, idealnya HTTPS. Jangan gunakan IP privat/LAN untuk callback produksi.</small>
          </div>
          <div class="col-md-6 mb-3"><label>Metode pembayaran otomatis (TriPay)</label>
            <select class="form-control" name="default_payment_method">
              <?php $paymentMethods = array('' => 'Nonaktif — buat link manual dari Billing', 'QRIS' => 'QRIS', 'DANA' => 'DANA', 'OVO' => 'OVO', 'SHOPEEPAY' => 'ShopeePay', 'BCAVA' => 'VA BCA', 'BRIVA' => 'VA BRI', 'MANDIRIVA' => 'VA Mandiri', 'ALFAMART' => 'Alfamart', 'INDOMARET' => 'Indomaret'); foreach ($paymentMethods as $code => $label) { ?>
              <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?= (string)($settings['default_payment_method'] ?? '') === $code ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php } ?>
            </select>
            <small class="text-muted">Pilih metode yang aktif pada akun TriPay kamu. Cron akan membuat checkout dan mengirim link melalui WhatsApp setelah invoice dibuat.</small>
          </div>
        </div>
        <button class="group-item group-item-md" type="submit" name="save_integration_settings">Simpan pengaturan integrasi</button>
        <a class="group-item group-item-md" href="./admin.php?id=billing&session=<?= rawurlencode((string)($session ?? '')); ?>">Kembali ke Billing</a>
      </form>
    </div>
  </div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">1. Perintah di CT Proxmox</h3></div><div class="card-body">
    <p>Jalankan sebagai root di CT Ubuntu 22.04. Installer RADIUS ditujukan untuk instalasi baru dan <strong>jangan dijalankan ulang pada database RADIUS produksi</strong>, karena setup awal membuat skema. Mode Full RADIUS menonaktifkan kebutuhan RouterOS API untuk scheduler billing: setelah mode ini disiapkan, pemutusan sesi dikirim sebagai RADIUS Disconnect-Message ke RouterOS v7.</p>
    <pre id="ctInstallCode" style="white-space:pre-wrap;word-break:break-word;">Memerlukan IP CT dan IP sumber MikroTik.</pre>
    <button type="button" class="group-item group-item-md" onclick="copyIntegrationCode('ctInstallCode')">Salin perintah install RADIUS</button>
      </div></div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">2. Perintah RouterOS untuk PPPoE / Hotspot</h3></div><div class="card-body">
    <p>Secret RADIUS dibuat otomatis oleh installer dan disimpan privat di <code>/root/mikpusing-radius-credentials.txt</code>. Salin nilainya ke kolom secret sebelum menyalin perintah. Secret hanya dipakai di browser dan tidak disimpan ke file konfigurasi.</p>
    <div class="row"><div class="col-md-7 mb-3"><label>RADIUS shared secret dari file kredensial privat</label>
      <input type="password" class="form-control" id="radiusSharedSecret" autocomplete="off" placeholder="Tempel nilai RADIUS_CLIENT_SECRET (hex acak)"/>
    </div><div class="col-md-5 mb-3 align-self-end"><button type="button" class="group-item group-item-md" id="toggleSecret">Tampilkan/sembunyikan</button></div></div>
    <pre id="routerRadiusCode" style="white-space:pre-wrap;word-break:break-word;">Isi IP CT, IP NAS, dan shared secret yang valid untuk membuat perintah.</pre>
    <button type="button" class="group-item group-item-md" onclick="copyIntegrationCode('routerRadiusCode')">Salin konfigurasi RADIUS ke MikroTik</button>
    
    <div class="alert bg-warning mt-3"><strong>Jangan langsung tempel ke semua router.</strong> Pertahankan akun admin lokal dan satu akun uji. Uji autentikasi, accounting, rule firewall dan pembayaran pada satu router lebih dahulu. Mengaktifkan RADIUS sebelum user dan group diuji dapat memutus akses pelanggan.</div>
  </div></div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">2A. Rule halaman isolir RouterOS v7 tanpa API</h3></div><div class="card-body">
    <p>Jalankan kode ini sekali di Terminal MikroTik setelah FreeRADIUS aktif dan group <code>ISOLIR</code> sudah mengembalikan atribut <code>Mikrotik-Address-List=MIKPUSING-ISOLIR</code>. Kode membuat atau memperbarui rule berdasarkan komentar khusus; tidak memakai RouterOS API.</p>
    <pre id="routerNoApiIsolirCode" style="white-space:pre-wrap;word-break:break-word;">Isi IP LAN CT untuk menghasilkan rule RouterOS.</pre>
    <button type="button" class="group-item group-item-md" onclick="copyIntegrationCode('routerNoApiIsolirCode')">Salin rule isolir tanpa API</button>
    <div class="alert bg-warning mt-3">Periksa urutan dan firewall aktif sebelum menempelkan rule. Redirect transparan hanya untuk HTTP, bukan HTTPS.</div>
  </div></div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">3. Alur otomatis setelah integrasi aktif</h3></div><div class="card-body">
    <ol>
      <li>Invoice dibuat manual dari Billing atau otomatis sekali per pelanggan/per periode oleh cron harian. Harga pelanggan harus lebih dari 0 agar invoice dibuat.</li>
      <li>Scheduler memeriksa profil setiap 5 menit dan menerapkan isolir setelah jatuh tempo + masa tenggang 5 hari.</li>
      <li>Callback pembayaran TriPay yang tanda tangannya valid menandai invoice lunas. Scheduler memulihkan group/profile dan memutus sesi agar login ulang mengambil kecepatan normal.</li>
      <li>Halaman isolir memeriksa ulang tiap 45 detik. Redirect NAT hanya dapat menangani HTTP; HTTPS tidak dapat dialihkan transparan ke halaman HTTP tanpa peringatan sertifikat.</li>
    </ol>
    <p class="text-muted">Mode MikroTik lokal saat ini mengotomatisasi PPPoE lokal. Untuk otomasi PPPoE dan Hotspot yang sama-sama berbasis group, gunakan FreeRADIUS dan daftarkan kedua jenis group di menu Profile PPPoE & FUP.</p>
    <a href="./admin.php?id=pppoe-packages" class="group-item group-item-md">Atur group paket / FUP / ISOLIR</a>
    <a href="./admin.php?id=radius-users" class="group-item group-item-md">Kelola user RADIUS</a>
    <a href="./admin.php?id=tripay-settings" class="group-item group-item-md">Pengaturan TriPay</a>
    <a href="./admin.php?id=fonnte" class="group-item group-item-md">Pengaturan WhatsApp / Fonnte</a>
    <a href="./admin.php?id=wa-templates" class="group-item group-item-md">Template pesan invoice & isolir</a>
  </div></div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">4. Pemeriksaan otomatis setelah setup</h3></div><div class="card-body">
    <p>Salin perintah ini ke terminal CT untuk memeriksa service, sintaks, cron, dan log terbaru. Perintah hanya membaca status/log; tidak mengubah router atau pelanggan.</p>
    <pre id="integrationStatusCode" style="white-space:pre-wrap;word-break:break-word;">systemctl --no-pager --full status apache2 mariadb cron freeradius
php -v
php -l /var/www/mikhmon/cron/pppoe_profile_automation.php
php -l /var/www/mikhmon/cron/radius_profile_automation.php
php /var/www/mikhmon/tests/smoke.php
tail -n 80 /var/www/mikhmon/storage/pppoe-automation.log
tail -n 80 /var/www/mikhmon/storage/invoice-generation.log
tail -n 80 /var/www/mikhmon/storage/radius-accounting.log</pre>
    <button type="button" class="group-item group-item-md" onclick="copyIntegrationCode('integrationStatusCode')">Salin perintah pemeriksaan</button>
    <div class="alert bg-warning mt-3"><strong>Catatan mode:</strong> RouterOS lokal di menu ini mengotomatisasi PPPoE lokal. Agar PPPoE dan Hotspot memakai autentikasi, accounting, group isolir/FUP, serta pemulihan yang sama dari satu database, pilih FreeRADIUS + MariaDB. Pada RADIUS, nama user harus unik antara PPPoE dan Hotspot jika keduanya ditagih terpisah.</div>
  </div></div>
</div></div>

<script>
(function(){
  function el(id){ return document.getElementById(id); }
  function validIpv4(s){
    var parts=String(s||'').trim().split('.');
    if(parts.length!==4) return false;
    return parts.every(function(p){return /^\d{1,3}$/.test(p)&&Number(p)>=0&&Number(p)<=255;});
  }
  function safeSecret(s){ return /^[A-Za-z0-9._-]{24,128}$/.test(s); }
  function profiles(){
    return String(el('hotspotProfiles').value||'').split(',').map(function(s){return s.trim();}).filter(Boolean).filter(function(s){return /^[A-Za-z0-9_.@ -]{1,64}$/.test(s);});
  }
  function setText(id, text){el(id).textContent=text;}
  var localIsolirName = <?= is_string($localIsolirProfileJs) ? $localIsolirProfileJs : '"ISOLIR"'; ?>;
  function render(){
    var server=el('radiusServerIp').value.trim(), nas=el('radiusNasIp').value.trim(), portal=el('portalIp').value.trim();
    var secret=el('radiusSharedSecret').value.trim(), hs=profiles();
    var ipsOk=validIpv4(server)&&validIpv4(nas);
    if(ipsOk){
      setText('ctInstallCode','cd /var/www/mikhmon && git pull --ff-only && if [ -f /etc/mikhmon/radius.php ]; then bash deploy/register-radius-nas.sh '+nas+'; else APP_DIR=/var/www/mikhmon RADIUS_NAS_IP='+nas+' RADIUS_SERVER_IP='+server+' bash deploy/install-freeradius.sh; fi');
    } else {
      setText('ctInstallCode','# Isi IP LAN CT dan IP sumber MikroTik, lalu simpan pengaturan.\n# IP CT dan IP NAS harus berupa IPv4 yang benar.');
    }
    if(validIpv4(portal)){
      setText('routerNoApiIsolirCode',
        ':if ([:len [/ip firewall nat find where comment="MIKPUSING-ISOLIR-HTTP"]] = 0) do={ /ip firewall nat add chain=dstnat src-address-list=MIKPUSING-ISOLIR protocol=tcp dst-port=80 dst-address-type=!local action=dst-nat to-addresses='+portal+' to-ports=8081 place-before=0 comment="MIKPUSING-ISOLIR-HTTP" } else={ /ip firewall nat set [find where comment="MIKPUSING-ISOLIR-HTTP"] chain=dstnat src-address-list=MIKPUSING-ISOLIR protocol=tcp dst-port=80 dst-address-type=!local action=dst-nat to-addresses='+portal+' to-ports=8081 }\n'+
        ':if ([:len [/ip firewall filter find where comment="MIKPUSING-ISOLIR-PORTAL"]] = 0) do={ /ip firewall filter add chain=forward action=accept src-address-list=MIKPUSING-ISOLIR dst-address='+portal+' protocol=tcp dst-port=8081 place-before=0 comment="MIKPUSING-ISOLIR-PORTAL" } else={ /ip firewall filter set [find where comment="MIKPUSING-ISOLIR-PORTAL"] chain=forward action=accept src-address-list=MIKPUSING-ISOLIR dst-address='+portal+' protocol=tcp dst-port=8081 }'
      );
    } else {
      setText('routerNoApiIsolirCode','# Isi IP LAN CT untuk menghasilkan rule RouterOS tanpa API.');
    }
    if(ipsOk&&safeSecret(secret)){
      var r=[':if ([:len [/radius find where comment="mikpusing-radius"]] > 0) do={ /radius set [find where comment="mikpusing-radius"] address='+server+' src-address='+nas+' service=ppp,hotspot secret='+secret+' authentication-port=1812 accounting-port=1813 timeout=1s } else={ /radius add address='+server+' src-address='+nas+' service=ppp,hotspot secret='+secret+' authentication-port=1812 accounting-port=1813 timeout=1s comment="mikpusing-radius" }',
        '/radius incoming set accept=yes port=1700',
        ':if ([:len [/ip firewall filter find where comment="MIKPUSING-RADIUS-DM"]] = 0) do={ /ip firewall filter add chain=input action=accept src-address='+server+' protocol=udp dst-port=1700 place-before=0 comment="MIKPUSING-RADIUS-DM" } else={ /ip firewall filter set [find where comment="MIKPUSING-RADIUS-DM"] chain=input action=accept src-address='+server+' protocol=udp dst-port=1700 }',
        '/ppp aaa set use-radius=yes accounting=yes interim-update=5m'];
      if(hs.length){
        hs.forEach(function(p){r.push('/ip hotspot profile set [find where name="'+p+'"] use-radius=yes radius-accounting=yes radius-interim-update=5m');});
      } else {
        r.push('# Jika memakai Hotspot: masukkan nama profile Hotspot di pengaturan Billing, lalu salin kembali.');
      }
      setText('routerRadiusCode',r.join('\n'));
    } else {
      setText('routerRadiusCode','# Lengkapi IP CT, IP NAS, dan shared secret RADIUS (24-128 huruf/angka/titik/_/-) agar perintah siap disalin.\n# Secret ada di /root/mikpusing-radius-credentials.txt setelah installer dijalankan.');
    }
  }
  ['radiusServerIp','radiusNasIp','portalIp','hotspotProfiles','radiusSharedSecret'].forEach(function(id){
    var node=el(id); if(node) node.addEventListener('input',render);
  });
  var toggle=el('toggleSecret');
  if(toggle) toggle.addEventListener('click',function(){var input=el('radiusSharedSecret');input.type=input.type==='password'?'text':'password';});
  window.copyIntegrationCode=function(id){
    var content=el(id).textContent;
    if(!content || content.trim()==='') return;
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(content).then(function(){alert('Perintah disalin. Periksa lagi IP/secret sebelum ditempel.');}).catch(function(){fallbackCopy(content);});
    } else fallbackCopy(content);
  };
  function fallbackCopy(text){
    var box=document.createElement('textarea');box.value=text;box.setAttribute('readonly','readonly');
    box.style.position='fixed';box.style.opacity='0';document.body.appendChild(box);box.select();
    try{document.execCommand('copy');alert('Perintah disalin. Periksa lagi sebelum ditempel.');}catch(e){alert('Gagal menyalin otomatis; pilih teks pada kotak lalu salin manual.');}
    document.body.removeChild(box);
  }
  render();
})();
</script>
