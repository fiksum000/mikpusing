<?php
error_reporting(0); if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/decrypt_helper.php');
$msg = '';
if (isset($_FILES['csv_file']) && $_FILES['csv_file']['tmp_name']) {
  $import = include('../lib/bulk_import.php');
  $res = importCustomersFromCsv($_FILES['csv_file']['tmp_name']);
  $msg = $res['imported'] . ' pelanggan diimpor. Error: ' . $res['errors'];
}
?>
<div class="card"><div class="card-header"><h3>Import Pelanggan Massal (CSV)</h3></div>
<div class="card-body"><form method="post" enctype="multipart/form-data"><input type="file" name="csv_file" accept=".csv"/><button class="group-item">Import</button></form>
<?php if($msg) echo '<div class="bg-success">'.$msg.'</div>'; ?><p>Format: username,name,phone,email,profile,amount,due_day,status</p></div></div>
