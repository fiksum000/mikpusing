<?php include('./include/config.php'); include('./include/customers.php');
$count = count(customerReadAll()); $unpaid = 0; $isolir = 0;
foreach (customerReadAll() as $c) {
  if (($c['status'] ?? 'active') === 'disabled') $isolir++;
}
echo '<div class="card"><h4>Monitor 1000 Pelanggan</h4><p>Total: '.$count.' | Isolir: '.$isolir.'</p></div>';
