<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) {
  header('Location:../admin.php?id=login');
  exit;
}
require_once('./lib/free_radius_client.php');

$radiusUiConfig = mikhmonRadiusBaseConfig();
$radiusUiSettings = mikhmonIntegrationSettings();
$radiusUiNasIp = trim((string)($radiusUiConfig['radius_nas_ip'] ?? ''));
$radiusUiServerIp = trim((string)($radiusUiSettings['radius_server_ip'] ?? '192.168.200.179'));
$radiusUiConfigured = is_file(getenv('MIKHMON_RADIUS_CONFIG') ?: '/etc/mikhmon/radius.php') && !empty($radiusUiConfig['db_password']);
$adminSettingMessage = '';
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$adminSettingType = 'info';

if (isset($_POST['save'])) {
  $newUser = trim((string)($_POST['useradm'] ?? ''));
  $newPassword = (string)($_POST['passadm'] ?? '');
  $newQr = strtolower(trim((string)($_POST['qrbt'] ?? 'disable')));
  if (!hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $adminSettingMessage = 'Token keamanan tidak cocok. Muat ulang halaman.';
    $adminSettingType = 'danger';
  } elseif (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $newUser)) {
    $adminSettingMessage = 'Username admin hanya boleh berisi huruf, angka, titik, garis bawah, @, dan minus (maks. 64 karakter).';
    $adminSettingType = 'danger';
  } elseif (strlen($newPassword) < 10) {
    $adminSettingMessage = 'Password admin minimal 10 karakter.';
    $adminSettingType = 'danger';
  } elseif (!in_array($newQr, array('enable', 'disable'), true)) {
    $adminSettingMessage = 'Pilihan QR tidak valid.';
    $adminSettingType = 'danger';
  } else {
    $configPath = './include/config.php';
    $configText = @file_get_contents($configPath);
    if ($configText === false) {
      $adminSettingMessage = 'Konfigurasi Mikhmon tidak bisa dibaca.';
      $adminSettingType = 'danger';
    } else {
      $encodedPassword = encrypt($newPassword);
      $userPattern = '/(mikhmon<\\|<)[^\']*/';
      $passwordPattern = '/(mikhmon>\\|>)[^\']*/';
      $updatedText = preg_replace_callback($userPattern, function($m) use ($newUser) { return $m[1] . $newUser; }, $configText, 1, $userCount);
      $updatedText = preg_replace_callback($passwordPattern, function($m) use ($encodedPassword) { return $m[1] . $encodedPassword; }, $updatedText, 1, $passwordCount);
      if ($userCount !== 1 || $passwordCount !== 1) {
        $adminSettingMessage = 'Format data login tidak sesuai. File konfigurasi tidak diubah.';
        $adminSettingType = 'danger';
      } elseif (@file_put_contents($configPath, $updatedText, LOCK_EX) === false) {
        $adminSettingMessage = 'Gagal menyimpan konfigurasi admin. Periksa permission include/config.php.';
        $adminSettingType = 'danger';
      } else {
        $quickPrint = '<?php $qrbt="' . $newQr . '"; ?>';
        if (@file_put_contents('./include/quickbt.php', $quickPrint, LOCK_EX) === false) {
          $adminSettingMessage = 'Login admin diperbarui, tetapi pengaturan Quick Print QR gagal disimpan.';
          $adminSettingType = 'warning';
        } else {
          $adminSettingMessage = 'Pengaturan admin berhasil disimpan. Login ulang menggunakan kredensial baru jika diminta.';
          $adminSettingType = 'success';
          $useradm = $newUser;
          $passadm = $encodedPassword;
          $qrbt = $newQr;
        }
      }
    }
  }
}
?>
<script>
function Pass(id) {
  var input = document.getElementById(id);
  if (input) input.type = input.type === 'password' ? 'text' : 'password';
}
</script>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fa fa-gear"></i> Admin Settings</h3></div>
      <div class="card-body">
        <?php if ($adminSettingMessage !== '') { ?><div class="bg-<?= $adminSettingType; ?> pd-10 mb-3"><?= htmlspecialchars($adminSettingMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <div class="row">
          <div class="col-md-6">
            <div class="card">
              <div class="card-header"><h3 class="card-title"><i class="fa fa-server"></i> Router RADIUS (NAS)</h3></div>
              <div class="card-body">
                <p>Di mode Full RADIUS, MikroTik didaftarkan sebagai NAS ke server FreeRADIUS. Username/password RouterOS API tidak diperlukan untuk scheduler billing.</p>
                <table class="table table-sm">
                  <tr><td>RADIUS server / CT</td><td><code><?= htmlspecialchars($radiusUiServerIp, ENT_QUOTES, 'UTF-8'); ?></code></td></tr>
                  <tr><td>IP MikroTik / NAS</td><td><?= $radiusUiNasIp !== '' ? '<code>' . htmlspecialchars($radiusUiNasIp, ENT_QUOTES, 'UTF-8') . '</code>' : '<span class="text-muted">Belum ditambahkan</span>'; ?></td></tr>
                  <tr><td>Konfigurasi server</td><td><?= $radiusUiConfigured ? '<span class="text-green">Ditemukan</span>' : '<span class="text-warning">Belum disiapkan</span>'; ?></td></tr>
                </table>
                <?php if ($radiusUiConfigured) { ?><a href="./admin.php?id=radius-dashboard" class="group-item group-item-md"><i class="fa fa-external-link"></i> Open</a><?php } ?>
                <a href="./admin.php?id=radius-nas" class="group-item group-item-md"><i class="fa fa-plus"></i> Add Router — RADIUS NAS</a>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <form autocomplete="off" method="post" action="./admin.php?id=sessions">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8'); ?>"/>
              <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="fa fa-user-circle"></i> Admin Setting</h3></div>
                <div class="card-body">
                  <table class="table table-sm">
                    <tr>
                      <td class="align-middle"><?= $_user_name ?></td>
                      <td><input class="form-control" id="useradm" type="text" maxlength="64" name="useradm" title="User Admin" value="<?= htmlspecialchars((string)$useradm, ENT_QUOTES, 'UTF-8'); ?>" required/></td>
                    </tr>
                    <tr>
                      <td class="align-middle"><?= $_password ?></td>
                      <td>
                        <div class="input-group">
                          <div class="input-group-11 col-box-10">
                            <input class="group-item group-item-l" id="passadm" type="password" name="passadm" title="Password Admin" value="<?= htmlspecialchars((string)decrypt($passadm), ENT_QUOTES, 'UTF-8'); ?>" minlength="10" required/>
                          </div>
                          <div class="input-group-1 col-box-2">
                            <div class="group-item group-item-r pd-2p5 text-center align-middle">
                              <input title="Show/Hide Password" type="checkbox" onclick="Pass('passadm')"/>
                            </div>
                          </div>
                        </div>
                        <small class="text-muted">Gunakan password admin minimal 10 karakter.</small>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle"><?= $_quick_print ?> QR</td>
                      <td><select class="form-control" name="qrbt"><option value="enable" <?= (string)$qrbt === 'enable' ? 'selected' : ''; ?>>enable</option><option value="disable" <?= (string)$qrbt === 'disable' ? 'selected' : ''; ?>>disable</option></select></td>
                    </tr>
                    <tr><td></td><td class="text-right"><button class="group-item group-item-md" type="submit" name="save" value="1"><?= $_save ?></button></td></tr>
                  </table>
                  <div id="loadV">v<?= htmlspecialchars((string)($_SESSION['v'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
              </div>
            </form>
          </div>
        </div>
        <p class="text-muted mb-0">Klik Open pada router untuk mengelola PPPoE/Hotspot, billing, TriPay, WhatsApp, paket/FUP, dan isolir. Jalur billing Full RADIUS tidak menggunakan RouterOS API.</p>
      </div>
    </div>
  </div>
</div>
