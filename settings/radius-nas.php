<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) {
    header('Location:../admin.php?id=login');
    exit;
}
require_once('./lib/free_radius_client.php');

$settingsPath = __DIR__ . '/../storage/integration_settings.json';
$baseRadius = mikhmonRadiusBaseConfig();
$settings = mikhmonIntegrationSettings();
$radiusReady = is_file(getenv('MIKHMON_RADIUS_CONFIG') ?: '/etc/mikhmon/radius.php')
    && !empty($baseRadius['db_password']);
$serverIp = trim((string)($settings['radius_server_ip'] ?? '192.168.200.179'));
$nasIp = trim((string)($settings['radius_nas_ip'] ?? ($baseRadius['radius_nas_ip'] ?? '')));
$portalIp = trim((string)($settings['portal_ip'] ?? $serverIp));
$hotspotProfiles = trim((string)($settings['hotspot_profiles'] ?? ''));
$message = '';
$messageType = 'info';

if (empty($_SESSION['radius_nas_csrf'])) $_SESSION['radius_nas_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['radius_nas_csrf'];

function radiusNasSaveSettings($path, $data) {
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
function radiusNasValidIp($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_radius_nas'])) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $message = 'Token keamanan tidak cocok. Muat ulang halaman dan coba lagi.';
        $messageType = 'danger';
    } else {
        $serverIp = trim((string)($_POST['radius_server_ip'] ?? ''));
        $nasIp = trim((string)($_POST['radius_nas_ip'] ?? ''));
        $portalIp = trim((string)($_POST['portal_ip'] ?? ''));
        $hotspotProfiles = trim((string)($_POST['hotspot_profiles'] ?? ''));
        if (!radiusNasValidIp($serverIp) || !radiusNasValidIp($nasIp) || !radiusNasValidIp($portalIp)) {
            $message = 'IP server, IP NAS, dan IP portal harus berupa IPv4 yang valid.';
            $messageType = 'danger';
        } elseif ($hotspotProfiles !== '' && !preg_match('/^[A-Za-z0-9_. ,@-]{1,500}$/', $hotspotProfiles)) {
            $message = 'Nama profile Hotspot berisi karakter yang tidak didukung.';
            $messageType = 'danger';
        } else {
            $settings = array_merge($settings, array(
                'radius_server_ip' => $serverIp,
                'radius_nas_ip' => $nasIp,
                'portal_ip' => $portalIp,
                'hotspot_profiles' => $hotspotProfiles,
                'updated_at' => date('Y-m-d H:i:s')
            ));
            if (radiusNasSaveSettings($settingsPath, $settings)) {
                $message = 'Alamat NAS berhasil disimpan. Perubahan di server RADIUS berlaku setelah perintah registrasi dijalankan di CT.';
                $messageType = 'success';
            } else {
                $message = 'Gagal menyimpan pengaturan. Periksa hak akses folder storage.';
                $messageType = 'danger';
            }
        }
    }
}
$radiusReady = is_file(getenv('MIKHMON_RADIUS_CONFIG') ?: '/etc/mikhmon/radius.php')
    && !empty($baseRadius['db_password']);
?>
<div class="row"><div class="col-12">
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa fa-server"></i> Add Router — RouterOS v7 ke FreeRADIUS</h3></div>
    <div class="card-body">
      <?php if ($message !== '') { ?><div class="bg-<?= $messageType; ?> pd-10 mb-3"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
      <p class="text-muted">Di mode Full RADIUS, halaman ini mendaftarkan IP MikroTik sebagai NAS ke server FreeRADIUS. Tidak perlu username/password RouterOS API. Pakai IP sumber yang benar-benar dipakai MikroTik saat mengirim paket RADIUS ke CT.</p>
      <div class="mb-3">
        <span class="badge <?= $radiusReady ? 'bg-success' : 'bg-warning'; ?>">FreeRADIUS: <?= $radiusReady ? 'konfigurasi ditemukan' : 'belum dikonfigurasi'; ?></span>
        <span class="badge bg-info">RADIUS server: <?= htmlspecialchars($serverIp, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <form method="post" action="./admin.php?id=radius-nas" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"/>
        <div class="row">
          <div class="col-md-4 mb-3">
            <label>IP Server RADIUS / CT Proxmox</label>
            <input class="form-control" name="radius_server_ip" value="<?= htmlspecialchars($serverIp, ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal" required/>
            <small class="text-muted">IP CT kamu saat ini: 192.168.200.179.</small>
          </div>
          <div class="col-md-4 mb-3">
            <label>IP MikroTik (NAS/source IP)</label>
            <input class="form-control" name="radius_nas_ip" value="<?= htmlspecialchars($nasIp, ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal" placeholder="IP router yang mengirim RADIUS" required/>
            <small class="text-muted">Bukan IP pelanggan. Harus stabil dan dapat dijangkau CT.</small>
          </div>
          <div class="col-md-4 mb-3">
            <label>IP portal isolir (CT)</label>
            <input class="form-control" name="portal_ip" value="<?= htmlspecialchars($portalIp, ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal" required/>
            <small class="text-muted">Portal berjalan pada TCP port 8081.</small>
          </div>
          <div class="col-md-8 mb-3">
            <label>Nama profile Hotspot (opsional)</label>
            <input class="form-control" name="hotspot_profiles" value="<?= htmlspecialchars($hotspotProfiles, ENT_QUOTES, 'UTF-8'); ?>" placeholder="hsprof1, hotspot-office"/>
          </div>
        </div>
        <button class="group-item group-item-md" type="submit" name="save_radius_nas">Simpan alamat NAS</button>
      </form>
    </div>
  </div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">1. Perintah untuk CT Proxmox</h3></div><div class="card-body">
    <?php if (!$radiusReady) { ?>
      <p>Jalankan perintah ini di Console CT sebagai root untuk memasang FreeRADIUS + MariaDB dan mendaftarkan NAS pertama. Jalankan setelah menyimpan alamat yang benar.</p>
      <pre id="radiusNasCtCommand" style="white-space:pre-wrap;word-break:break-word;">Simpan IP server dan NAS dahulu.</pre>
    <?php } else { ?>
      <p>FreeRADIUS sudah memiliki konfigurasi. Perintah di bawah memperbarui client NAS tanpa menjalankan ulang installer database.</p>
      <pre id="radiusNasCtCommand" style="white-space:pre-wrap;word-break:break-word;">Simpan IP NAS dahulu.</pre>
    <?php } ?>
    <button type="button" class="group-item group-item-md" onclick="copyRadiusNasCommand('radiusNasCtCommand')">Salin perintah CT</button>
  </div></div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">2. Perintah MikroTik RouterOS v7 — tanpa API</h3></div><div class="card-body">
    <p>Sesudah FreeRADIUS aktif, ambil <code>RADIUS_CLIENT_SECRET</code> dari file privat di CT. Tempel hanya ke kotak berikut; secret tidak disimpan oleh halaman ini.</p>
    <div class="row">
      <div class="col-md-8 mb-3"><label>RADIUS shared secret</label><input type="password" id="radiusNasSecret" class="form-control" autocomplete="off" placeholder="Tempel RADIUS_CLIENT_SECRET dari file kredensial"/></div>
      <div class="col-md-4 mb-3 align-self-end"><button type="button" id="toggleRadiusNasSecret" class="group-item group-item-md">Tampilkan/sembunyikan</button></div>
    </div>
    <pre id="radiusNasRouterCommand" style="white-space:pre-wrap;word-break:break-word;">Isi IP server, IP NAS, lalu tempel shared secret.</pre>
    <button type="button" class="group-item group-item-md" onclick="copyRadiusNasCommand('radiusNasRouterCommand')">Salin konfigurasi RADIUS MikroTik</button>
    <div class="alert bg-warning mt-3">Pertahankan login admin lokal MikroTik untuk keadaan darurat. Perintah mengaktifkan RADIUS PPPoE dan menyiapkan Incoming Disconnect Message pada UDP 1700. Uji satu akun dulu sebelum migrasi pelanggan.</div>
  </div></div>

  <div class="card mt-3"><div class="card-header"><h3 class="card-title">3. Halaman isolir HTTP tanpa API</h3></div><div class="card-body">
    <p>Setelah group RADIUS <code>ISOLIR</code> memberi atribut <code>Mikrotik-Address-List=MIKPUSING-ISOLIR</code>, jalankan rule portal ini sekali dari Terminal MikroTik.</p>
    <pre id="radiusNasPortalCommand" style="white-space:pre-wrap;word-break:break-word;">Isi IP portal di atas untuk menghasilkan rule.</pre>
    <button type="button" class="group-item group-item-md" onclick="copyRadiusNasCommand('radiusNasPortalCommand')">Salin rule portal isolir</button>
    <div class="alert bg-warning mt-3">Redirect otomatis hanya untuk HTTP/TCP 80; HTTPS tidak bisa dialihkan transparan ke portal HTTP. Pastikan urutan firewall dan jaringan pelanggan diuji dulu.</div>
  </div></div>
</div></div>

<script>
(function(){
  function el(id){return document.getElementById(id);}
  function validIp(s){
    var p=String(s||'').trim().split('.');
    return p.length===4 && p.every(function(v){return /^\\d{1,3}$/.test(v) && Number(v)>=0 && Number(v)<=255;});
  }
  function safeSecret(s){return /^[A-Za-z0-9._-]{24,128}$/.test(s);}
  function profiles(){return String(document.querySelector('[name="hotspot_profiles"]').value||'').split(',').map(function(v){return v.trim();}).filter(function(v){return /^[A-Za-z0-9_.@ -]{1,64}$/.test(v);});}
  function setText(id,t){el(id).textContent=t;}
  var server=<?= json_encode($serverIp); ?>;
  var nas=<?= json_encode($nasIp); ?>;
  var portal=<?= json_encode($portalIp); ?>;
  var radiusReady=<?= $radiusReady ? 'true' : 'false'; ?>;
  function render(){
    if(validIp(server) && validIp(nas)){
      var cmd;
      if(radiusReady){
        cmd='cd /var/www/mikhmon && git pull --ff-only && bash deploy/register-radius-nas.sh '+nas;
      } else {
        cmd='cd /var/www/mikhmon && git pull --ff-only && APP_DIR=/var/www/mikhmon RADIUS_NAS_IP='+nas+' RADIUS_SERVER_IP='+server+' bash deploy/install-freeradius.sh';
      }
      setText('radiusNasCtCommand',cmd);
    } else setText('radiusNasCtCommand','# Simpan IPv4 server dan IP NAS yang valid dahulu.');
    var secret=el('radiusNasSecret').value.trim();
    if(validIp(server)&&validIp(nas)&&safeSecret(secret)){
      var r=[
        ':if ([:len [/radius find where comment="MIKPUSING-RADIUS"]] = 0) do={ /radius add address='+server+' src-address='+nas+' service=ppp,hotspot secret='+secret+' authentication-port=1812 accounting-port=1813 timeout=1s comment="MIKPUSING-RADIUS" } else={ /radius set [find where comment="MIKPUSING-RADIUS"] address='+server+' src-address='+nas+' service=ppp,hotspot secret='+secret+' authentication-port=1812 accounting-port=1813 timeout=1s }',
        '/radius incoming set accept=yes port=1700',
        ':if ([:len [/ip firewall filter find where comment="MIKPUSING-RADIUS-DM"]] = 0) do={ /ip firewall filter add chain=input action=accept src-address='+server+' protocol=udp dst-port=1700 place-before=0 comment="MIKPUSING-RADIUS-DM" } else={ /ip firewall filter set [find where comment="MIKPUSING-RADIUS-DM"] chain=input action=accept src-address='+server+' protocol=udp dst-port=1700 }',
        '/ppp aaa set use-radius=yes accounting=yes interim-update=5m'
      ];
      var hs=profiles();
      if(hs.length){
        hs.forEach(function(p){r.push('/ip hotspot profile set [find where name="'+p+'"] use-radius=yes radius-accounting=yes radius-interim-update=5m');});
      } else {
        r.push('# Jika memakai Hotspot, isi nama profile di atas lalu simpan dan salin kembali.');
      }
      setText('radiusNasRouterCommand',r.join('\n'));
    } else setText('radiusNasRouterCommand','# Isi IP server/NAS yang valid dan tempel shared secret 24-128 karakter.');
    if(validIp(portal)){
      setText('radiusNasPortalCommand',
        ':if ([:len [/ip firewall nat find where comment="MIKPUSING-ISOLIR-HTTP"]] = 0) do={ /ip firewall nat add chain=dstnat src-address-list=MIKPUSING-ISOLIR protocol=tcp dst-port=80 dst-address-type=!local action=dst-nat to-addresses='+portal+' to-ports=8081 place-before=0 comment="MIKPUSING-ISOLIR-HTTP" } else={ /ip firewall nat set [find where comment="MIKPUSING-ISOLIR-HTTP"] chain=dstnat src-address-list=MIKPUSING-ISOLIR protocol=tcp dst-port=80 dst-address-type=!local action=dst-nat to-addresses='+portal+' to-ports=8081 }\n'+
        ':if ([:len [/ip firewall filter find where comment="MIKPUSING-ISOLIR-PORTAL"]] = 0) do={ /ip firewall filter add chain=forward action=accept src-address-list=MIKPUSING-ISOLIR dst-address='+portal+' protocol=tcp dst-port=8081 place-before=0 comment="MIKPUSING-ISOLIR-PORTAL" } else={ /ip firewall filter set [find where comment="MIKPUSING-ISOLIR-PORTAL"] chain=forward action=accept src-address-list=MIKPUSING-ISOLIR dst-address='+portal+' protocol=tcp dst-port=8081 }'
      );
    } else setText('radiusNasPortalCommand','# Isi IPv4 portal isolir lalu simpan.');
  }
  ['radiusNasSecret'].forEach(function(id){var n=el(id);if(n)n.addEventListener('input',render);});
  ['radius_server_ip','radius_nas_ip','portal_ip','hotspot_profiles'].forEach(function(name){
    var n=document.querySelector('[name="'+name+'"]'); if(n)n.addEventListener('input',function(){
      server=String(document.querySelector('[name="radius_server_ip"]').value||'').trim();
      nas=String(document.querySelector('[name="radius_nas_ip"]').value||'').trim();
      portal=String(document.querySelector('[name="portal_ip"]').value||'').trim();
      render();
    });
  });
  var toggle=el('toggleRadiusNasSecret');
  if(toggle)toggle.addEventListener('click',function(){var x=el('radiusNasSecret');x.type=x.type==='password'?'text':'password';});
  window.copyRadiusNasCommand=function(id){
    var t=el(id).textContent||'';
    if(navigator.clipboard&&navigator.clipboard.writeText)navigator.clipboard.writeText(t).then(function(){alert('Perintah disalin. Tinjau IP dan secret sebelum ditempel.');}).catch(function(){manualCopy(t);});
    else manualCopy(t);
  };
  function manualCopy(t){var x=document.createElement('textarea');x.value=t;x.setAttribute('readonly','readonly');x.style.position='fixed';x.style.opacity='0';document.body.appendChild(x);x.select();try{document.execCommand('copy');alert('Perintah disalin. Tinjau kembali sebelum ditempel.');}catch(e){alert('Gagal menyalin otomatis. Pilih teks dan salin manual.');}document.body.removeChild(x);}
  render();
})();
</script>
