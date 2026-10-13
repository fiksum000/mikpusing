<?php
/* Fonnte settings for the original Mikhmon PHP application. */
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

function fonnteValidateToken($token, &$reason = '') {
  if ($token === '') { $reason = 'Token masih kosong.'; return false; }
  if (!function_exists('curl_init')) { $reason = 'PHP cURL belum aktif.'; return false; }
  $ch = curl_init('https://api.fonnte.com/get-devices');
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '',
    CURLOPT_HTTPHEADER => array('Authorization: ' . $token),
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
  ));
  $raw = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  if ($raw === false || $error !== '') { $reason = 'Fonnte tidak dapat dihubungi: ' . $error; return false; }
  $body = json_decode($raw, true);
  if ($http >= 200 && $http < 300 && !empty($body['status'])) return true;
  $reason = !empty($body['reason']) ? $body['reason'] : 'Token Fonnte tidak valid (HTTP ' . $http . ').';
  return false;
}

function fonnteMaskToken($token) {
  $length = strlen($token);
  if ($length < 8) return $token === '' ? '-' : str_repeat('*', $length);
  return substr($token, 0, 4) . '********' . substr($token, -4);
}

if (empty($_SESSION['fonnte_csrf'])) $_SESSION['fonnte_csrf'] = bin2hex(random_bytes(32));
$fonnteCsrf = (string)$_SESSION['fonnte_csrf'];
$fonntePostValid = $_SERVER['REQUEST_METHOD'] !== 'POST' || hash_equals($fonnteCsrf, (string)($_POST['csrf'] ?? ''));
$fonnteMessage = '';
$fonnteMessageType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$fonntePostValid) {
  $fonnteMessage = 'Permintaan ditolak: token keamanan tidak cocok. Muat ulang halaman.';
  $fonnteMessageType = 'danger';
}
$currentFonnteToken = isset($fonnteToken) ? decrypt($fonnteToken) : '';

if ($fonntePostValid && isset($_POST['test_fonnte'])) {
  $candidate = trim($_POST['fonnte_token']);
  $reason = '';
  if (fonnteValidateToken($candidate, $reason)) {
    $fonnteMessage = 'Token Fonnte valid. Perangkat dapat digunakan untuk mengirim notifikasi.';
    $fonnteMessageType = 'success';
  } else {
    $fonnteMessage = 'Validasi gagal: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    $fonnteMessageType = 'danger';
  }
}

if ($fonntePostValid && isset($_POST['save_fonnte'])) {
  $candidate = trim($_POST['fonnte_token']);
  $reason = '';
  if (!fonnteValidateToken($candidate, $reason)) {
    $fonnteMessage = 'Token tidak disimpan: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    $fonnteMessageType = 'danger';
  } else {
    $encoded = addslashes(encrypt($candidate));
    $configPath = './include/config.php';
    $config = file_get_contents($configPath);
    $line = '$fonnteToken = \'' . $encoded . '\';';
    if (preg_match('/\$fonnteToken\s*=.*?;/s', $config)) {
      $config = preg_replace('/\$fonnteToken\s*=.*?;/s', $line, $config, 1);
    } else {
      $config .= "\n" . $line . "\n";
    }
    if (file_put_contents($configPath, $config, LOCK_EX) === false) {
      $fonnteMessage = 'Token valid tetapi file konfigurasi tidak dapat ditulis. Periksa permission folder include.';
      $fonnteMessageType = 'danger';
    } else {
      $currentFonnteToken = $candidate;
      $fonnteMessage = 'Token Fonnte berhasil disimpan dan siap dipakai oleh notifikasi billing.';
      $fonnteMessageType = 'success';
    }
  }
}
?>
<div class="row">
  <div class="col-12 col-md-8">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fa fa-whatsapp"></i> Pengaturan WhatsApp Fonnte</h3></div>
      <div class="card-body">
        <?php if ($fonnteMessage !== '') { ?><div class="bg-<?= $fonnteMessageType; ?> pd-10 radius-3 mb-3"><?= $fonnteMessage; ?></div><?php } ?>
        <form autocomplete="off" method="post" action="./admin.php?id=fonnte"><input type="hidden" name="csrf" value="<?= htmlspecialchars($fonnteCsrf, ENT_QUOTES, 'UTF-8'); ?>"/>
          <table class="table table-sm">
            <tr><td class="align-middle" style="width:35%;">Provider</td><td><span class="badge bg-success">Fonnte</span><div class="text-muted mt-1">Provider default untuk notifikasi invoice dan pembayaran.</div></td></tr>
            <tr><td class="align-middle">Status token</td><td><?php if ($currentFonnteToken !== '') { ?><span class="text-green"><i class="fa fa-check-circle"></i> Tersimpan: <?= htmlspecialchars(fonnteMaskToken($currentFonnteToken), ENT_QUOTES, 'UTF-8'); ?></span><?php } else { ?><span class="text-red"><i class="fa fa-warning"></i> Belum dikonfigurasi</span><?php } ?></td></tr>
            <tr><td class="align-middle">Account Token Fonnte</td><td><input class="form-control" type="password" name="fonnte_token" minlength="8" placeholder="Tempel account token Fonnte" required="1"/><small class="text-muted">Gunakan account token dari menu API Fonnte, bukan device token.</small></td></tr>
            <tr><td></td><td><input class="group-item group-item-md" type="submit" name="test_fonnte" value="Test Token"/> <input class="group-item group-item-md" type="submit" name="save_fonnte" value="Simpan Token"/></td></tr>
          </table>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fa fa-info-circle"></i> Cara mendapatkan token</h3></div>
      <div class="card-body">
        <ol class="text-muted">
          <li>Masuk ke dashboard Fonnte.</li>
          <li>Buka menu API / Token.</li>
          <li>Salin <b>account token</b>.</li>
          <li>Tempel di form lalu klik Test Token.</li>
          <li>Klik Simpan Token jika valid.</li>
        </ol>
        <p class="text-muted mb-0">Token disimpan terenkripsi menggunakan mekanisme enkripsi yang sudah dipakai Mikhmon untuk password router.</p>
      </div>
    </div>
  </div>
</div>
