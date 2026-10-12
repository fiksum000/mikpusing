<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
require_once('./lib/free_radius_client.php');

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
  'default_monthly_amount' => 0
), $settings);
if (empty($_SESSION['billing_integrations_csrf'])) $_SESSION['billing_integrations_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['billing_integrations_csrf'];
$message = ''; $messageType = 'info';
$radiusConfigPath = getenv('MIKHMON_RADIUS_CONFIG') ?: '/etc/mikhmon/radius.php';
$radiusReady = is_file($radiusConfigPath) && is_readable($radiusConfigPath) && !empty($baseRadius['db_password']);
$secretShapeValid = true;

function billingIntegrationValidIpv4($value) {
  return $value === '' || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
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
    if (!in_array($backend, array('routeros','radius'), true)) {
      $message = 'Pilih backend RouterOS lokal atau FreeRADIUS.'; $messageType = 'danger';
    } elseif (!billingIntegrationValidIpv4($radiusServerIp) || !billingIntegrationValidIpv4($radiusNasIp) || !billingIntegrationValidIpv4($portalIp)) {
      $message = 'Alamat IP harus IPv4 yang valid. Kosongkan jika belum diketahui.'; $messageType = 'danger';
    } elseif ($backend === 'radius' && ($radiusServerIp === '' || $radiusNasIp === '')) {
      $message = 'Mode RADIUS memerlukan IP LAN CT dan IP sumber MikroTik (NAS).'; $messageType = 'danger';
    } elseif ($backend === 'radius' && !$radiusReady) {
      $message = 'FreeRADIUS belum dikonfigurasi. Jalankan installer RADIUS satu kali di CT terlebih dahulu; mode tidak diubah.'; $messageType = 'danger';
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
      <p class="text-muted">Pilih backend yang dipakai scheduler Billing. IP di bawah disimpan lokal; password database dan shared secret RADIUS tidak disimpan di sini. Perintah router dibuat untuk disalin dan diperiksa, bukan langsung dijalankan oleh halaman web.</p>
      <div class="mb-3">
        <span class="badge <?= $currentBackend === 'radius' ? 'bg-success' : 'bg-info'; ?>">Backend saat ini: <?= strtoupper(htmlspecialchars($currentBackend, ENT_QUOTES, 'UTF-8')); ?></span>
        <span class="badge <?= $radiusReady ? 'bg-success' : 'bg-warning'; ?>">FreeRADIUS <?= $radiusReady ? 'terkonfigurasi' : 'belum terpasang/konfigurasi'; ?></span>
      </div>
      <form method="post" action="./admin.php?id=billing-integrations" class="mb-4">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"/>
        <div class="row">
          <div class="col-md-4 mb-3"><label>Backend autentikasi</label>
            <select class="form-control" name="ppp_auth_backend" id="backendSelect">
              <option value="routeros" <?= $currentBackend === 'routeros' ? 'selected' : ''; ?>>MikroTik lokal (PPPoE)</option>
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
        </div>
        <button class="group-item group-item-md" type="submit" name="save_integration_settings">Simpan pengaturan integrasi</button>
        <a class="group-item group-item-md" href="./admin.php?id=billing&session=<?= rawurlencode((string)($session ?? '')); ?>">Kembali ke Billing</a>
      </form>
    </div>
  </div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">1. Perintah di CT Proxmox</h3></div><div class="card-body">
    <p>Jalankan sebagai root di CT Ubuntu 22.04. Installer RADIUS ditujukan untuk instalasi baru dan **jangan dijalankan ulang pada database RADIUS produksi**, karena setup awal membuat skema.</p>
    <pre id="ctInstallCode" style="white-space:pre-wrap;word-break:break-word;">Memerlukan IP CT dan IP sumber MikroTik.</pre>
    <button type="button" class="group-item group-item-md" onclick="copyIntegrationCode('ctInstallCode')">Salin perintah install RADIUS</button>
    <pre id="isolirDryCode" style="white-space:pre-wrap;word-break:break-word;">Memerlukan IP CT untuk portal isolir.</pre>
    <button type="button" class="group-item group-item-md" onclick="copyIntegrationCode('isolirDryCode')">Salin dry-run rule isolir</button>
    <pre id="isolirApplyCode" style="white-space:pre-wrap;word-break:break-word;"></pre>
    <button type="button" class="group-item group-item-md" onclick="copyIntegrationCode('isolirApplyCode')">Salin perintah apply isolir</button>
  </div></div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">2. Perintah RouterOS untuk PPPoE / Hotspot</h3></div><div class="card-body">
    <p>Secret RADIUS dibuat otomatis oleh installer dan disimpan privat di <code>/root/mikpusing-radius-credentials.txt</code>. Salin nilainya ke kolom secret sebelum menyalin perintah. Secret hanya dipakai di browser dan tidak disimpan ke file konfigurasi.</p>
    <div class="row"><div class="col-md-7 mb-3"><label>RADIUS shared secret dari file kredensial privat</label>
      <input type="password" class="form-control" id="radiusSharedSecret" autocomplete="off" placeholder="Tempel nilai RADIUS_CLIENT_SECRET (hex acak)"/>
    </div><div class="col-md-5 mb-3 align-self-end"><button type="button" class="group-item group-item-md" id="toggleSecret">Tampilkan/sembunyikan</button></div></div>
    <pre id="routerRadiusCode" style="white-space:pre-wrap;word-break:break-word;">Isi IP CT, IP NAS, dan shared secret yang valid untuk membuat perintah.</pre>
    <button type="button" class="group-item group-item-md" onclick="copyIntegrationCode('routerRadiusCode')">Salin konfigurasi RADIUS ke MikroTik</button>
    <pre id="routerLocalCode" style="white-space:pre-wrap;word-break:break-word;">Isi IP portal isolir untuk membuat perintah profile lokal.</pre>
    <button type="button" class="group-item group-item-md" onclick="copyIntegrationCode('routerLocalCode')">Salin profile isolir lokal PPPoE</button>
    <div class="alert bg-warning mt-3"><strong>Jangan langsung tempel ke semua router.</strong> Pertahankan akun admin lokal dan satu akun uji. Uji autentikasi, accounting, rule firewall dan pembayaran pada satu router lebih dahulu. Mengaktifkan RADIUS sebelum user dan group diuji dapat memutus akses pelanggan.</div>
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
  function render(){
    var server=el('radiusServerIp').value.trim(), nas=el('radiusNasIp').value.trim(), portal=el('portalIp').value.trim();
    var secret=el('radiusSharedSecret').value.trim(), hs=profiles();
    var ipsOk=validIpv4(server)&&validIpv4(nas);
    if(ipsOk){
      setText('ctInstallCode','cd /var/www/mikhmon && git pull --ff-only && if [ -f /etc/mikhmon/radius.php ]; then echo "RADIUS sudah terkonfigurasi; installer tidak dijalankan ulang agar secret tidak berubah."; systemctl status freeradius --no-pager; else APP_DIR=/var/www/mikhmon RADIUS_NAS_IP='+nas+' RADIUS_SERVER_IP='+server+' bash deploy/install-freeradius.sh; fi');
    } else {
      setText('ctInstallCode','# Isi IP LAN CT dan IP sumber MikroTik, lalu simpan pengaturan.\n# IP CT dan IP NAS harus berupa IPv4 yang benar.');
    }
    if(validIpv4(portal)){
      setText('isolirDryCode','cd /var/www/mikhmon && php deploy/configure-isolir-routeros.php --portal-ip '+portal);
      setText('isolirApplyCode','cd /var/www/mikhmon && php deploy/configure-isolir-routeros.php --portal-ip '+portal+' --apply');
      setText('routerLocalCode',':local isolirProfile [/ppp profile find where name="ISOLIR"]\n:if ([:len $isolirProfile] = 0) do={ /ppp profile add name="ISOLIR" rate-limit=64k/64k address-list=MIKPUSING-ISOLIR } else={ /ppp profile set $isolirProfile rate-limit=64k/64k address-list=MIKPUSING-ISOLIR }\n# Setelah rule diverifikasi, pasang redirect HTTP di CT dengan perintah apply isolir di atas.');
    } else {
      setText('isolirDryCode','# Isi IP LAN CT untuk halaman isolir.');
      setText('isolirApplyCode','# Isi IP LAN CT untuk halaman isolir.');
      setText('routerLocalCode','# Isi IP LAN CT untuk portal dan simpan pengaturan.');
    }
    if(ipsOk&&safeSecret(secret)){
      var r=[':local existingRadius [/radius find where comment="mikpusing-radius"]',
        ':if ([:len $existingRadius] > 0) do={ /radius set $existingRadius address='+server+' src-address='+nas+' service=ppp,hotspot secret='+secret+' authentication-port=1812 accounting-port=1813 timeout=1s } else={ /radius add address='+server+' src-address='+nas+' service=ppp,hotspot secret='+secret+' authentication-port=1812 accounting-port=1813 timeout=1s comment="mikpusing-radius" }',
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
