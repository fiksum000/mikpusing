<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/pppoe_packages.php');
$config = pppoePackageRead(); $message = '';
if (isset($_POST['save_packages'])) {
  $config['isolir_profile'] = trim($_POST['isolir_profile']); $config['packages'] = array();
  $names = isset($_POST['package_name']) ? $_POST['package_name'] : array();
  foreach ($names as $i => $name) if (trim($name) !== '') $config['packages'][] = array('name' => trim($name), 'normal_profile' => trim($_POST['normal_profile'][$i]), 'fup_profile' => trim($_POST['fup_profile'][$i]), 'quota_gb' => max(0, (float)$_POST['quota_gb'][$i]));
  if (pppoePackageWrite($config)) $message = 'Mapping profile PPPoE berhasil disimpan.';
}
if (!$config['packages']) $config['packages'] = array(array('name' => 'Paket 1', 'normal_profile' => 'PAKET120', 'fup_profile' => 'PAKET120-FUP', 'quota_gb' => 300));
?>
<div class="row"><div class="col-12"><div class="card"><div class="card-header"><h3 class="card-title"><i class="fa fa-sliders"></i> Profile PPPoE, Isolir & FUP</h3></div><div class="card-body"><p class="text-muted">Scheduler mengganti profile PPP Secret: normal → FUP saat kuota tercapai → ISOLIR setelah jatuh tempo dan masa tenggang. Setelah invoice lunas, profile kembali normal.</p><?php if ($message) { ?><div class="bg-success pd-10 mb-3"><?= $message; ?></div><?php } ?><form method="post"><div class="row mb-3"><div class="col-4"><label>Profile isolir global</label><input class="form-control" name="isolir_profile" value="<?= htmlspecialchars($config['isolir_profile'], ENT_QUOTES, 'UTF-8'); ?>" required="1"/></div></div><table class="table table-sm"><thead><tr><th>Nama paket</th><th>Profile normal</th><th>Profile FUP</th><th>Kuota FUP (GB)</th></tr></thead><tbody><?php foreach ($config['packages'] as $package) { ?><tr><td><input class="form-control" name="package_name[]" value="<?= htmlspecialchars($package['name'], ENT_QUOTES, 'UTF-8'); ?>"/></td><td><input class="form-control" name="normal_profile[]" value="<?= htmlspecialchars($package['normal_profile'], ENT_QUOTES, 'UTF-8'); ?>"/></td><td><input class="form-control" name="fup_profile[]" value="<?= htmlspecialchars($package['fup_profile'], ENT_QUOTES, 'UTF-8'); ?>"/></td><td><input class="form-control" type="number" step="0.01" name="quota_gb[]" value="<?= htmlspecialchars($package['quota_gb'], ENT_QUOTES, 'UTF-8'); ?>"/></td></tr><?php } ?></tbody></table><input class="group-item group-item-md" type="submit" name="save_packages" value="Simpan Mapping Profile"/></form><p class="text-muted mt-3">Jadwalkan <code>php cron/pppoe_profile_automation.php</code> setiap 5–15 menit. Pastikan profile normal, FUP, dan ISOLIR sudah ada di setiap router.</p></div></div></div></div>
