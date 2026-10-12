<?php
error_reporting(0);
include_once("./include/decrypt_helper.php");
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/billing.php');
include_once('./include/customers.php');
include_once('./include/tripay.php');
$billingMessage = '';
$billingType = 'info';
$period = isset($_POST['period']) ? preg_replace('/[^0-9-]/', '', $_POST['period']) : date('Y-m');
$defaultAmount = isset($_POST['default_amount']) ? (int)$_POST['default_amount'] : 0;
$secrets = array();
$API = new RouterosAPI();
$API->debug = false;
if (!empty($session)) {
  if (@$API->connect($iphost, $userhost, decrypt($passwdhost))) $secrets = $API->comm('/ppp/secret/print');
}
$allCustomers = customerSyncSecrets($secrets, $session);
$customers = array();
foreach ($allCustomers as $customer) if (empty($session) || !isset($customer['router_session']) || $customer['router_session'] === $session) $customers[] = $customer;
if (isset($_POST['generate_billing'])) {
  $created = billingCreateForCustomers($customers, $period, $defaultAmount);
  $billingMessage = $created . ' invoice baru dibuat untuk periode ' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . '.';
  $billingType = 'success';
}
if (isset($_POST['create_tripay'])) {
  $invoice = billingFindInvoice(trim($_POST['invoice_no']));
  if (!$invoice) { $billingMessage = 'Invoice tidak ditemukan.'; $billingType = 'danger'; }
  else {
    $invoice['payment_method'] = preg_replace('/[^A-Z0-9_]/', '', strtoupper($_POST['payment_method']));
    $result = tripayCreateTransaction($invoice);
    if (!empty($result['success'])) {
      $reference = isset($result['data']['reference']) ? $result['data']['reference'] : '';
      billingUpdateInvoice($invoice['invoice_no'], array('payment_method' => $invoice['payment_method'], 'tripay_reference' => $reference, 'status' => 'pending'));
      $checkout = isset($result['data']['checkout_url']) ? $result['data']['checkout_url'] : '';
      if ($checkout) echo '<script>window.open(' . json_encode($checkout) . ', "_blank");</script>';
      $billingMessage = 'Transaksi TriPay berhasil dibuat. Reference: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); $billingType = 'success';
    } else { $billingMessage = 'Gagal membuat transaksi TriPay: ' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'); $billingType = 'danger'; }
  }
}
if (isset($_POST['update_customer'])) {
  $username = trim($_POST['username']);
  customerUpdate($username, array('name' => trim($_POST['customer_name']), 'phone' => trim($_POST['customer_phone']), 'email' => trim($_POST['customer_email']), 'amount' => max(0, (int)$_POST['customer_amount']), 'due_day' => min(31, max(1, (int)$_POST['due_day']))));
  $billingMessage = 'Data pelanggan PPPoE ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ' berhasil diperbarui.'; $billingType = 'success';
  $customers = array();
  foreach (customerReadAll() as $customer) if (empty($session) || !isset($customer['router_session']) || $customer['router_session'] === $session) $customers[] = $customer;
}
$invoices = array_reverse(billingReadInvoices());
$methods = array('QRIS' => 'QRIS', 'DANA' => 'DANA', 'OVO' => 'OVO', 'SHOPEEPAY' => 'ShopeePay', 'BCAVA' => 'VA BCA', 'BRIVA' => 'VA BRI', 'MANDIRIVA' => 'VA Mandiri', 'ALFAMART' => 'Alfamart', 'INDOMARET' => 'Indomaret');
?>
<div class="row">
  <div class="col-12">
    <div class="card"><div class="card-header"><h3 class="card-title"><i class="fa fa-money"></i> Billing Bulanan & TriPay</h3></div><div class="card-body">
      <?php if ($billingMessage !== '') { ?><div class="bg-<?= $billingType; ?> pd-10 radius-3 mb-3"><?= $billingMessage; ?></div><?php } ?>
      <form method="post" class="row mb-4"><div class="col-3"><label>Periode</label><input class="form-control" type="month" name="period" value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8'); ?>" required="1"/></div><div class="col-3"><label>Harga default</label><input class="form-control" type="number" name="default_amount" min="0" value="<?= $defaultAmount; ?>" required="1"/></div><div class="col-4 align-self-end"><input class="group-item group-item-md" type="submit" name="generate_billing" value="Generate Invoice PPPoE"/></div></form>
      <p class="text-muted">Ditemukan <b><?= count($customers); ?></b> pelanggan PPPoE pada router <b><?= htmlspecialchars(isset($identity) ? $identity : '-', ENT_QUOTES, 'UTF-8'); ?></b>. Data kontak dan harga disimpan per username PPP Secret. Invoice duplikat untuk periode dan username yang sama akan dilewati.</p>
      <div class="table-responsive mb-4"><table class="table table-sm"><thead><tr><th>Username PPPoE</th><th>Nama</th><th>WhatsApp</th><th>Harga</th><th>Jatuh tempo</th><th>Update</th></tr></thead><tbody><?php foreach ($customers as $customer) { ?><tr><form method="post"><td><code><?= htmlspecialchars($customer['username'], ENT_QUOTES, 'UTF-8'); ?></code><input type="hidden" name="username" value="<?= htmlspecialchars($customer['username'], ENT_QUOTES, 'UTF-8'); ?>"/></td><td><input class="form-control" name="customer_name" value="<?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?>"/></td><td><input class="form-control" name="customer_phone" value="<?= htmlspecialchars($customer['phone'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="62812..."/></td><td><input class="form-control" type="number" name="customer_amount" value="<?= (int)$customer['amount']; ?>" min="0"/></td><td><input class="form-control" type="number" name="due_day" value="<?= (int)$customer['due_day']; ?>" min="1" max="31"/></td><td><input class="group-item group-item-sm" type="submit" name="update_customer" value="Simpan"/></td></form></tr><?php } ?></tbody></table></div>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Invoice</th><th>Pelanggan</th><th>Periode</th><th>Total</th><th>Status</th><th>Metode TriPay</th><th>Aksi</th></tr></thead><tbody>
      <?php if (!$invoices) { ?><tr><td colspan="7" class="text-center text-muted">Belum ada invoice.</td></tr><?php } else { foreach ($invoices as $invoice) { ?><tr><td><?= htmlspecialchars($invoice['invoice_no'], ENT_QUOTES, 'UTF-8'); ?></td><td><?= htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8'); ?><br><small><?= htmlspecialchars($invoice['username'], ENT_QUOTES, 'UTF-8'); ?></small></td><td><?= htmlspecialchars($invoice['period'], ENT_QUOTES, 'UTF-8'); ?></td><td><?= number_format((int)$invoice['amount'], 0, ',', '.'); ?></td><td><?= htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8'); ?></td><td><form method="post"><input type="hidden" name="invoice_no" value="<?= htmlspecialchars($invoice['invoice_no'], ENT_QUOTES, 'UTF-8'); ?>"/><select name="payment_method" class="form-control"><option value="">Pilih metode</option><?php foreach ($methods as $key => $label) { ?><option value="<?= $key; ?>" <?= isset($invoice['payment_method']) && $invoice['payment_method'] === $key ? 'selected' : ''; ?>><?= $label; ?></option><?php } ?></select></td><td><input class="group-item group-item-sm" type="submit" name="create_tripay" value="Bayar TriPay"/></form></td></tr><?php }} ?>
      </tbody></table></div>
    </div></div>
  </div>
</div>
