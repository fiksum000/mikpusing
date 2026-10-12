<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
require_once './include/pppoe_packages.php';
require_once './lib/free_radius_client.php';

$config = pppoePackageRead();
$message = '';
$messageType = 'info';
$radius = null;
$radiusError = '';
$groupAttributes = array();
try {
  $radius = new RadiusClient();
  $groupAttributes = $radius->getGroupAttributes();
} catch (Throwable $e) {
  $radiusError = $e->getMessage();
}

if (empty($_SESSION['radius_packages_csrf'])) $_SESSION['radius_packages_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['radius_packages_csrf'];

if (isset($_POST['save_packages'])) {
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $message = 'Permintaan ditolak: token keamanan tidak cocok. Muat ulang halaman.';
    $messageType = 'danger';
  } elseif (!($radius instanceof RadiusClient)) {
    $message = 'Database RADIUS tidak bisa diakses: ' . htmlspecialchars($radiusError, ENT_QUOTES, 'UTF-8');
    $messageType = 'danger';
  } else {
    $names = $_POST['package_name'] ?? array();
    $normalProfiles = $_POST['normal_profile'] ?? array();
    $fupProfiles = $_POST['fup_profile'] ?? array();
    $normalRates = $_POST['normal_rate'] ?? array();
    $fupRates = $_POST['fup_rate'] ?? array();
    $quotas = $_POST['quota_gb'] ?? array();
    $packages = array();
    $seen = array();
    $attributesToSync = array(
      'ISOLIR' => array(
        'Mikrotik-Rate-Limit' => '64k/64k',
        'Mikrotik-Address-List' => 'MIKPUSING-ISOLIR'
      )
    );
    $validationError = '';
    foreach ((array)$names as $i => $name) {
      $displayName = trim((string)$name);
      $normal = trim((string)($normalProfiles[$i] ?? ''));
      $fup = trim((string)($fupProfiles[$i] ?? ''));
      $normalRate = trim((string)($normalRates[$i] ?? ''));
      $fupRate = trim((string)($fupRates[$i] ?? ''));
      $quota = max(0.0, (float)($quotas[$i] ?? 0));
      if ($displayName === '' && $normal === '' && $fup === '') continue;
      if ($displayName === '') $displayName = $normal;
      if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $normal)) {
        $validationError = 'Nama group normal wajib diisi dan hanya boleh berisi huruf, angka, titik, garis bawah, @, atau minus (maks. 64 karakter).';
        break;
      }
      if ($fup !== '' && !preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $fup)) {
        $validationError = 'Nama group FUP tidak valid: ' . htmlspecialchars($fup, ENT_QUOTES, 'UTF-8');
        break;
      }
      if ($normal === 'ISOLIR' || $fup === 'ISOLIR') {
        $validationError = 'Group ISOLIR dikelola otomatis dan tidak boleh dipakai sebagai group normal/FUP.';
        break;
      }
      if (isset($seen[$normal])) {
        $validationError = 'Group RADIUS duplikat: ' . htmlspecialchars($normal, ENT_QUOTES, 'UTF-8');
        break;
      }
      $seen[$normal] = true;
      if ($fup !== '') {
        if (isset($seen[$fup])) {
          $validationError = 'Group RADIUS duplikat: ' . htmlspecialchars($fup, ENT_QUOTES, 'UTF-8');
          break;
        }
        $seen[$fup] = true;
      }
      $validRate = function($rate) {
        return strlen($rate) <= 120
          && preg_match('/^[0-9A-Za-z.\/: -]+$/', $rate) === 1
          && strpos($rate, '/') !== false
          && preg_match('/\d/', $rate) === 1;
      };
      if (!$validRate($normalRate)) {
        $validationError = 'Batas kecepatan group normal ' . htmlspecialchars($normal, ENT_QUOTES, 'UTF-8') . ' wajib diisi, contoh 10M/10M.';
        break;
      }
      if ($fup !== '' && !$validRate($fupRate)) {
        $validationError = 'Batas kecepatan group FUP ' . htmlspecialchars($fup, ENT_QUOTES, 'UTF-8') . ' wajib diisi, contoh 2M/2M.';
        break;
      }
      $packages[] = array(
        'name' => $displayName, 'normal_profile' => $normal, 'fup_profile' => $fup,
        'quota_gb' => $quota, 'normal_rate' => $normalRate, 'fup_rate' => $fup === '' ? '' : $fupRate
      );
      $attributesToSync[$normal] = array('Mikrotik-Rate-Limit' => $normalRate);
      if ($fup !== '') $attributesToSync[$fup] = array('Mikrotik-Rate-Limit' => $fupRate);
    }
    if ($validationError === '' && !$packages) $validationError = 'Tambahkan setidaknya satu paket RADIUS.';
    if ($validationError !== '') {
      $message = $validationError;
      $messageType = 'danger';
    } else {
      try {
        // Sync group policy in MariaDB in one transaction; the editor does not
        // merely store labels which FreeRADIUS would ignore at authentication.
        $radius->upsertGroupsAttributes($attributesToSync);
        $newConfig = array('isolir_profile' => 'ISOLIR', 'packages' => $packages);
        if (pppoePackageWrite($newConfig)) {
          $config = $newConfig;
          $groupAttributes = $radius->getGroupAttributes();
          $message = 'Paket dan atribut kecepatan berhasil disimpan ke konfigurasi Mikhmon serta MariaDB RADIUS.';
          $messageType = 'success';
        } else {
          $message = 'Atribut group sudah disimpan di RADIUS, tetapi mapping paket gagal ditulis. Periksa permission storage lalu simpan ulang.';
          $messageType = 'danger';
        }
      } catch (Throwable $e) {
        $message = 'Gagal menyimpan group RADIUS: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $messageType = 'danger';
      }
    }
  }
}

if (empty($config['packages'])) {
  $config['packages'] = array(array(
    'name' => 'Contoh PPPoE 10M', 'normal_profile' => 'PPPOE-10M',
    'fup_profile' => 'PPPOE-10M-FUP', 'quota_gb' => 300,
    'normal_rate' => '10M/10M', 'fup_rate' => '2M/2M'
  ));
}
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3 class="card-title"><i class="fa fa-sliders"></i> Paket RADIUS PPPoE / Hotspot, FUP &amp; Isolir</h3></div>
  <div class="card-body">
    <p class="text-muted">Editor ini menyimpan mapping paket dan benar-benar membuat/memperbarui atribut <code>Mikrotik-Rate-Limit</code> di <code>radgroupreply</code>. Group normal/FUP harus memakai nama yang sama seperti di <code>radusergroup</code>. Group ISOLIR otomatis diberi rate <code>64k/64k</code> dan address-list <code>MIKPUSING-ISOLIR</code>.</p>
    <?php if ($message !== '') { ?><div class="bg-<?= $messageType; ?> pd-10 mb-3"><?= $message; ?></div><?php } ?>
    <?php if (!($radius instanceof RadiusClient)) { ?><div class="bg-warning pd-10 mb-3">Koneksi database RADIUS belum tersedia. Perbaiki konfigurasi FreeRADIUS/MariaDB sebelum menyimpan paket.</div><?php } ?>
    <form method="post" action="./admin.php?id=pppoe-packages">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"/>
      <div class="table-responsive"><table class="table table-sm table-striped">
        <thead><tr><th>Nama paket</th><th>Group normal</th><th>Kecepatan normal</th><th>Group FUP</th><th>Kecepatan FUP</th><th>Kuota FUP (GiB)</th><th></th></tr></thead>
        <tbody id="packageRows">
        <?php foreach ($config['packages'] as $package) {
          $normalGroup = (string)($package['normal_profile'] ?? '');
          $fupGroup = (string)($package['fup_profile'] ?? '');
          $normalRate = (string)($package['normal_rate'] ?? ($groupAttributes[$normalGroup]['Mikrotik-Rate-Limit'] ?? ($normalGroup === 'PPPOE-10M' ? '10M/10M' : '')));
          $fupRate = (string)($package['fup_rate'] ?? ($groupAttributes[$fupGroup]['Mikrotik-Rate-Limit'] ?? ($fupGroup === 'PPPOE-10M-FUP' ? '2M/2M' : '')));
        ?>
          <tr>
            <td><input class="form-control" name="package_name[]" value="<?= htmlspecialchars((string)($package['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required/></td>
            <td><input class="form-control" name="normal_profile[]" value="<?= htmlspecialchars($normalGroup, ENT_QUOTES, 'UTF-8'); ?>" required/></td>
            <td><input class="form-control" name="normal_rate[]" value="<?= htmlspecialchars($normalRate, ENT_QUOTES, 'UTF-8'); ?>" placeholder="10M/10M" required/></td>
            <td><input class="form-control" name="fup_profile[]" value="<?= htmlspecialchars($fupGroup, ENT_QUOTES, 'UTF-8'); ?>"/></td>
            <td><input class="form-control" name="fup_rate[]" value="<?= htmlspecialchars($fupRate, ENT_QUOTES, 'UTF-8'); ?>" placeholder="2M/2M"/></td>
            <td><input class="form-control" type="number" min="0" step="0.01" name="quota_gb[]" value="<?= htmlspecialchars((string)($package['quota_gb'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"/></td>
            <td><button class="group-item group-item-sm" type="button" onclick="this.closest('tr').remove()">Hapus</button></td>
          </tr>
        <?php } ?>
        </tbody>
      </table></div>
      <button class="group-item group-item-md" type="button" id="addPackage" <?= !($radius instanceof RadiusClient) ? 'disabled' : ''; ?>>Tambah group PPPoE / Hotspot</button>
      <button class="group-item group-item-md" type="submit" name="save_packages" value="1" <?= !($radius instanceof RadiusClient) ? 'disabled' : ''; ?>>Simpan paket dan batas kecepatan RADIUS</button>
    </form>
    <div class="alert bg-info mt-3">
      <strong>Otomatisasi:</strong> scheduler memeriksa profile setiap 5 menit. User yang mencapai kuota beralih ke group FUP; jika tunggakan tagihan melewati grace period, group berubah menjadi ISOLIR. Setelah tunggakan dibayar atau tidak lagi lewat tenggang, group normal dipulihkan dan sesi aktif diputus melalui RADIUS Disconnect-Message agar kebijakan baru digunakan.
    </div>
  </div>
</div></div></div>
<template id="packageRowTemplate"><tr>
  <td><input class="form-control" name="package_name[]" required/></td>
  <td><input class="form-control" name="normal_profile[]" required/></td>
  <td><input class="form-control" name="normal_rate[]" placeholder="10M/10M" required/></td>
  <td><input class="form-control" name="fup_profile[]"/></td>
  <td><input class="form-control" name="fup_rate[]" placeholder="2M/2M"/></td>
  <td><input class="form-control" type="number" min="0" step="0.01" name="quota_gb[]" value="0"/></td>
  <td><button class="group-item group-item-sm" type="button" onclick="this.closest('tr').remove()">Hapus</button></td>
</tr></template>
<script>
(function(){
  var add = document.getElementById('addPackage');
  var rows = document.getElementById('packageRows');
  var template = document.getElementById('packageRowTemplate');
  if (add && rows && template) add.addEventListener('click', function(){
    rows.appendChild(template.content.cloneNode(true));
  });
})();
</script>
<?php
if ($radius instanceof RadiusClient) $radius->disconnect();
?>
