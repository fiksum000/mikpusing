<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/pppoe_packages.php');
include_once('./lib/free_radius_client.php');
$config = pppoePackageRead(); $message = ''; $messageType = 'success';
$integrationConfig = mikhmonRadiusConfig();
$isRadiusMode = (($integrationConfig['ppp_auth_backend'] ?? 'routeros') === 'radius');
$displayIsolirProfile = $isRadiusMode ? 'ISOLIR' : (string)($config['isolir_profile'] ?? 'ISOLIR');
if (isset($_POST['save_packages'])) {
  $isolir = trim((string)($_POST['isolir_profile'] ?? 'ISOLIR'));
  if ($isRadiusMode) $isolir = 'ISOLIR';
  $names = $_POST['package_name'] ?? array();
  $normalProfiles = $_POST['normal_profile'] ?? array();
  $fupProfiles = $_POST['fup_profile'] ?? array();
  $quotas = $_POST['quota_gb'] ?? array();
  $packages = array(); $seen = array();
  if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $isolir)) {
    $message = 'Nama profile isolir hanya boleh memakai huruf, angka, titik, garis bawah, @, atau minus (maks. 64 karakter).'; $messageType = 'danger';
  } else {
    foreach ($names as $i => $name) {
      $displayName = trim((string)$name);
      $normal = trim((string)($normalProfiles[$i] ?? ''));
      $fup = trim((string)($fupProfiles[$i] ?? ''));
      $quota = max(0, (float)($quotas[$i] ?? 0));
      if ($displayName === '' && $normal === '' && $fup === '') continue;
      if ($displayName === '') $displayName = $normal;
      if ($normal === '') { $message = 'Setiap paket harus punya group normal.'; $messageType = 'danger'; break; }
      if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $normal) || ($fup !== '' && !preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $fup))) { $message = 'Nama group normal/FUP hanya boleh berisi huruf, angka, titik, garis bawah, @, atau minus (maks. 64 karakter).'; $messageType = 'danger'; break; }
      if ($normal === $isolir || $fup === $isolir) { $message = 'Group ISOLIR tidak boleh dipakai sebagai group normal/FUP.'; $messageType = 'danger'; break; }
      if (isset($seen[$normal])) { $message = 'Group normal tidak boleh duplikat: ' . htmlspecialchars($normal, ENT_QUOTES, 'UTF-8'); $messageType = 'danger'; break; }
      $seen[$normal] = true;
      if ($fup !== '') {
        if (isset($seen[$fup])) { $message = 'Group FUP/normal tidak boleh duplikat: ' . htmlspecialchars($fup, ENT_QUOTES, 'UTF-8'); $messageType = 'danger'; break; }
        $seen[$fup] = true;
      }
      $packages[] = array('name' => $displayName, 'normal_profile' => $normal, 'fup_profile' => $fup, 'quota_gb' => $quota);
    }
    if ($messageType !== 'danger') {
      $config = array('isolir_profile' => $isolir, 'packages' => $packages);
      if (pppoePackageWrite($config)) $message = 'Kebijakan RADIUS berhasil disimpan.';
      else { $message = 'Tidak bisa menyimpan konfigurasi. Periksa hak akses folder storage.'; $messageType = 'danger'; }
    }
  }
}
if (empty($config['packages'])) {
  $config['packages'] = array(array('name' => 'Contoh PPPoE 10M', 'normal_profile' => 'PPPOE-10M', 'fup_profile' => 'PPPOE-10M-FUP', 'quota_gb' => 300));
}
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3 class="card-title"><i class="fa fa-sliders"></i> <?= $isRadiusMode ? 'Kebijakan RADIUS PPPoE / Hotspot, FUP & Isolir' : 'Kebijakan MikroTik PPPoE, FUP & Isolir'; ?></h3></div>
  <div class="card-body">
    <p class="text-muted">Tambahkan satu baris untuk setiap group layanan, baik PPPoE maupun Hotspot. Nama group harus sama persis dengan <code>radusergroup.groupname</code> dan dibuat di <code>radgroupreply</code>. Semua group normal/FUP yang terdaftar dapat dipulihkan otomatis setelah tagihan terkonfirmasi lunas.</p>
    <?php if ($message !== '') { ?><div class="bg-<?= $messageType; ?> pd-10 mb-3"><?= $message; ?></div><?php } ?>
    <form method="post" action="./admin.php?id=pppoe-packages">
      <div class="row mb-3"><div class="col-md-5">
        <label><?= $isRadiusMode ? 'Nama group RADIUS isolir' : 'Nama profile isolir MikroTik'; ?></label>
        <input class="form-control" name="isolir_profile" value="<?= htmlspecialchars($displayIsolirProfile, ENT_QUOTES, 'UTF-8'); ?>" <?= $isRadiusMode ? 'readonly' : ''; ?> required/>
        <small class="text-muted"><?php if ($isRadiusMode) { ?>Mode RADIUS mengunci group isolir menjadi <code>ISOLIR</code> agar cocok dengan policy FreeRADIUS/MariaDB dan address-list <code>MIKPUSING-ISOLIR</code>.<?php } else { ?>Nama profile isolir pada MikroTik lokal. Profile ini menggunakan address-list <code>MIKPUSING-ISOLIR</code> untuk portal HTTP.<?php } ?></small>
      </div></div>
      <div class="table-responsive"><table class="table table-sm table-striped">
        <thead><tr><th>Nama paket</th><th>Group normal</th><th>Group FUP (opsional)</th><th>Kuota FUP (GiB)</th><th></th></tr></thead>
        <tbody id="packageRows">
        <?php foreach ($config['packages'] as $package) { ?>
          <tr>
            <td><input class="form-control" name="package_name[]" value="<?= htmlspecialchars((string)($package['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required/></td>
            <td><input class="form-control" name="normal_profile[]" value="<?= htmlspecialchars((string)($package['normal_profile'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required/></td>
            <td><input class="form-control" name="fup_profile[]" value="<?= htmlspecialchars((string)($package['fup_profile'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"/></td>
            <td><input class="form-control" type="number" min="0" step="0.01" name="quota_gb[]" value="<?= htmlspecialchars((string)($package['quota_gb'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"/></td>
            <td><button class="group-item group-item-sm" type="button" onclick="this.closest('tr').remove()">Hapus</button></td>
          </tr>
        <?php } ?>
        </tbody>
      </table></div>
      <button class="group-item group-item-md" type="button" id="addPackage">Tambah group PPPoE / Hotspot</button>
      <button class="group-item group-item-md" type="submit" name="save_packages">Simpan kebijakan</button>
    </form>
    <div class="alert bg-info mt-3">
      <strong>Otomatisasi:</strong> profil diperiksa setiap 5 menit. Setelah pembayaran dikonfirmasi, sistem mengubah group kembali dan memutus sesi aktif agar login ulang menggunakan kebijakan baru. Untuk redirect halaman isolir, install portal lalu jalankan <code>php deploy/configure-isolir-routeros.php --portal-ip IP_CT --apply</code> di CT setelah router terdaftar di Mikhmon.
    </div>
  </div>
</div></div></div>
<template id="packageRowTemplate"><tr>
  <td><input class="form-control" name="package_name[]" required/></td>
  <td><input class="form-control" name="normal_profile[]" required/></td>
  <td><input class="form-control" name="fup_profile[]"/></td>
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
