<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/tripay.php');
$tripayMessage = '';
$tripayMessageType = 'info';
$current = tripayConfig();
if (isset($_POST['save_tripay'])) {
  $apiKey = trim($_POST['api_key']); $privateKey = trim($_POST['private_key']); $merchant = trim($_POST['merchant_code']); $mode = $_POST['mode'] === 'production' ? 'production' : 'sandbox';
  if ($apiKey === '' || $privateKey === '' || $merchant === '') { $tripayMessage = 'Semua kredensial TriPay wajib diisi.'; $tripayMessageType = 'danger'; }
  else {
    $configPath = './include/config.php'; $config = file_get_contents($configPath);
    $values = array('$tripayApiKey' => '$tripayApiKey = \'' . addslashes(encrypt($apiKey)) . '\';', '$tripayPrivateKey' => '$tripayPrivateKey = \'' . addslashes(encrypt($privateKey)) . '\';', '$tripayMerchantCode' => '$tripayMerchantCode = \'' . addslashes($merchant) . '\';', '$tripayMode' => '$tripayMode = \'' . $mode . '\';');
    foreach ($values as $variable => $line) { $pattern = '/' . preg_quote($variable, '/') . '\s*=.*?;/s'; if (preg_match($pattern, $config)) $config = preg_replace($pattern, $line, $config, 1); else $config .= "\n" . $line . "\n"; }
    if (file_put_contents($configPath, $config, LOCK_EX) === false) { $tripayMessage = 'Konfigurasi tidak dapat ditulis. Periksa permission include/config.php.'; $tripayMessageType = 'danger'; }
    else { $current = array('api_key' => $apiKey, 'private_key' => $privateKey, 'merchant_code' => $merchant, 'mode' => $mode); $tripayMessage = 'Konfigurasi TriPay berhasil disimpan.'; $tripayMessageType = 'success'; }
  }
}
?>
<div class="row"><div class="col-12 col-md-8"><div class="card"><div class="card-header"><h3 class="card-title"><i class="fa fa-credit-card"></i> Pengaturan Payment Gateway TriPay</h3></div><div class="card-body">
<?php if ($tripayMessage !== '') { ?><div class="bg-<?= $tripayMessageType; ?> pd-10 radius-3 mb-3"><?= $tripayMessage; ?></div><?php } ?>
<form autocomplete="off" method="post" action="./admin.php?id=tripay-settings"><table class="table table-sm"><tr><td style="width:35%;">Mode</td><td><select class="form-control" name="mode"><option value="sandbox" <?= $current['mode'] === 'sandbox' ? 'selected' : ''; ?>>Sandbox</option><option value="production" <?= $current['mode'] === 'production' ? 'selected' : ''; ?>>Production</option></select></td></tr><tr><td>API Key</td><td><input class="form-control" type="password" name="api_key" placeholder="Tripay API Key" required="1"/></td></tr><tr><td>Private Key</td><td><input class="form-control" type="password" name="private_key" placeholder="Tripay Private Key" required="1"/></td></tr><tr><td>Merchant Code</td><td><input class="form-control" type="text" name="merchant_code" value="<?= htmlspecialchars($current['merchant_code'], ENT_QUOTES, 'UTF-8'); ?>" required="1"/></td></tr><tr><td></td><td><input class="group-item group-item-md" type="submit" name="save_tripay" value="Simpan Konfigurasi"/></td></tr></table></form>
<p class="text-muted">Kredensial rahasia API Key dan Private Key disimpan terenkripsi menggunakan enkripsi bawaan Mikhmon. Callback TriPay diarahkan ke <code>/admin.php?id=tripay-callback</code>.</p>
</div></div></div></div>
