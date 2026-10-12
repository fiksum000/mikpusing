<?php
error_reporting(0);
if (!function_exists('decrypt')) include_once('./lib/decrypt_helper.php');
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/billing.php');
include_once('./include/customers.php');
include_once('./include/tripay.php');
include_once('./include/wa_templates.php');
include_once('./include/fonnte_log.php');
include_once('./lib/free_radius_client.php');

$billingMessage = '';
$billingType = 'info';
$period = isset($_POST['period']) ? preg_replace('/[^0-9-]/', '', $_POST['period']) : date('Y-m');
$defaultAmount = isset($_POST['default_amount']) ? (int)$_POST['default_amount'] : 0;
$secrets = array();
$billingSession = (string)($session ?? '');
$billingIdentity = (string)($identity ?? '-');
$radiusConfig = mikhmonRadiusConfig();
$isRadiusMode = (($radiusConfig['ppp_auth_backend'] ?? '') === 'radius');
$customerTypeLabel = $isRadiusMode ? 'pelanggan RADIUS PPPoE/Hotspot' : 'pelanggan PPPoE';
$usernameColumnLabel = $isRadiusMode ? 'Username PPPoE / Hotspot' : 'Username PPPoE';
$generateButtonLabel = $isRadiusMode ? 'Generate Invoice PPPoE / Hotspot' : 'Generate Invoice PPPoE';

if ($isRadiusMode) {
  $radius = new RadiusClient();
  try {
    foreach ($radius->listUsers() as $radiusUser) {
      $secrets[] = array('name' => (string)$radiusUser['username'], 'profile' => (string)$radiusUser['profile'], 'comment' => '');
    }
  } catch (Throwable $e) {
    $billingMessage = 'Gagal membaca user RADIUS: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $billingType = 'danger';
  } finally {
    if (isset($radius) && $radius instanceof RadiusClient) $radius->disconnect();
  }
  $billingSession = 'RADIUS';
  $billingIdentity = 'FreeRADIUS';
} else {
  $API = new RouterosAPI();
  $API->debug = false;
  if (!empty($billingSession) && @$API->connect($iphost, $userhost, decrypt($passwdhost))) {
    $response = $API->comm('/ppp/secret/print');
    if (is_array($response) && !isset($response['!trap'])) $secrets = $response;
    $API->disconnect();
  }
}
$allCustomers = customerSyncSecrets($secrets, $billingSession);
$customers = array();
foreach (customerReadAll() as $customer) {
  if ((string)($customer['router_session'] ?? '') === $billingSession) $customers[] = $customer;
}
if (isset($_POST['generate_billing'])) {
  try {
    $created = billingCreateForCustomers($customers, $period, $defaultAmount);
    $billingMessage = $created . ' invoice baru dibuat untuk periode ' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . '.';
    $billingType = 'success';
  } catch (Throwable $e) {
    $billingMessage = 'Gagal membuat invoice: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $billingType = 'danger';
  }
}
if (isset($_POST['create_tripay'])) {
  $invoiceNo = trim((string)($_POST['invoice_no'] ?? ''));
  $invoice = billingFindInvoice($invoiceNo);
  if (!$invoice) { $billingMessage = 'Invoice tidak ditemukan.'; $billingType = 'danger'; }
  elseif (($invoice['status'] ?? '') === 'paid') { $billingMessage = 'Invoice sudah lunas; transaksi baru tidak dibuat.'; $billingType = 'warning'; }
  else {
    $invoice['payment_method'] = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)($_POST['payment_method'] ?? '')));
    if ($invoice['payment_method'] === '') {
      $billingMessage = 'Pilih metode pembayaran terlebih dahulu.'; $billingType = 'danger';
    } else {
      $result = tripayCreateTransaction($invoice);
      if (!empty($result['success'])) {
        $reference = isset($result['data']['reference']) ? (string)$result['data']['reference'] : '';
        $checkout = isset($result['data']['checkout_url']) ? (string)$result['data']['checkout_url'] : '';
        billingUpdateInvoice($invoice['invoice_no'], array(
          'payment_method' => $invoice['payment_method'], 'tripay_reference' => $reference,
          'payment_url' => $checkout, 'status' => 'pending'
        ));
        if ($checkout !== '' && !empty($invoice['customer_phone']) && function_exists('fonnteSendMessage')) {
          $message = waTemplateRender('invoice', array(
            'nama' => (string)($invoice['customer_name'] ?? $invoice['username'] ?? ''),
            'invoice' => (string)$invoice['invoice_no'],
            'periode' => (string)($invoice['period'] ?? ''),
            'total' => 'Rp' . number_format((int)($invoice['amount'] ?? 0), 0, ',', '.'),
            'jatuh_tempo' => (string)($invoice['due_date'] ?? ''),
            'link_bayar' => $checkout
          ));
          fonnteSendMessage($invoice['customer_phone'], $message, array('event' => 'invoice', 'invoice' => $invoice['invoice_no']));
        }
        $billingMessage = 'Transaksi TriPay berhasil dibuat.' . ($reference !== '' ? ' Reference: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') : '');
        $billingType = 'success';
      } else { $billingMessage = 'Gagal membuat transaksi TriPay: ' . htmlspecialchars((string)($result['message'] ?? 'Unknown error'), ENT_QUOTES, 'UTF-8'); $billingType = 'danger'; }
    }
  }
}
if (isset($_POST['update_customer'])) {
  $username = trim((string)($_POST['username'] ?? ''));
  $updated = customerUpdate($username, array(
    'name' => trim((string)($_POST['customer_name'] ?? '')),
    'phone' => trim((string)($_POST['customer_phone'] ?? '')),
    'email' => trim((string)($_POST['customer_email'] ?? '')),
    'amount' => max(0, (int)($_POST['customer_amount'] ?? 0)),
    'due_day' => min(31, max(1, (int)($_POST['due_day'] ?? 20)))
  ), $billingSession);
  $billingMessage = $updated ? 'Data pelanggan ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ' berhasil diperbarui.' : 'Pelanggan tidak ditemukan untuk router/backend ini.';
  $billingType = $updated ? 'success' : 'danger';
  $customers = array();
  foreach (customerReadAll() as $customer) if ((string)($customer['router_session'] ?? '') === $billingSession) $customers[] = $customer;
}
$invoices = array_reverse(billingReadInvoices());
$methods = array('QRIS' => 'QRIS', 'DANA' => 'DANA', 'OVO' => 'OVO', 'SHOPEEPAY' => 'ShopeePay', 'BCAVA' => 'VA BCA', 'BRIVA' => 'VA BRI', 'MANDIRIVA' => 'VA Mandiri', 'ALFAMART' => 'Alfamart', 'INDOMARET' => 'Indomaret');
?>
<div class="row">
  <div class="col-12">
    <div class="card"><div class="card-header"><h3 class="card-title"><i class="fa fa-money"></i> Billing Bulanan & TriPay</h3></div><div class="card-body">
      <div class="mb-3"><a href="./admin.php?id=billing-integrations" class="group-item group-item-md"><i class="fa fa-plug"></i> Pengaturan Integrasi MikroTik / RADIUS & Perintah Copy-Paste</a></div>
      <?php if ($billingMessage !== '') { ?><div class="bg-<?= $billingType; ?> pd-10 radius-3 mb-3"><?= $billingMessage; ?></div><?php } ?>
      <form method="post" class="row mb-4"><div class="col-3"><label>Periode</label><input class="form-control" type="month" name="period" value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8'); ?>" required="1"/></div><div class="col-3"><label>Harga default</label><input class="form-control" type="number" name="default_amount" min="0" value="<?= $defaultAmount; ?>" required="1"/></div><div class="col-4 align-self-end"><input class="group-item group-item-md" type="submit" name="generate_billing" value="<?= htmlspecialchars($generateButtonLabel, ENT_QUOTES, 'UTF-8'); ?>"/></div></form>
      <p class="text-muted">Ditemukan <b><?= count($customers); ?></b> <?= htmlspecialchars($customerTypeLabel, ENT_QUOTES, 'UTF-8'); ?> pada router/backend <b><?= htmlspecialchars($billingIdentity, ENT_QUOTES, 'UTF-8'); ?></b>. Data kontak dan harga disimpan per username. Invoice duplikat untuk periode dan username yang sama akan dilewati.</p>
      <div class="table-responsive mb-4"><table class="table table-sm"><thead><tr><th><?= htmlspecialchars($usernameColumnLabel, ENT_QUOTES, 'UTF-8'); ?></th><th>Nama</th><th>WhatsApp</th><th>Harga</th><th>Jatuh tempo</th><th>Update</th></tr></thead><tbody><?php foreach ($customers as $customer) { ?><tr><form method="post"><td><code><?= htmlspecialchars($customer['username'], ENT_QUOTES, 'UTF-8'); ?></code><input type="hidden" name="username" value="<?= htmlspecialchars($customer['username'], ENT_QUOTES, 'UTF-8'); ?>"/></td><td><input class="form-control" name="customer_name" value="<?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?>"/></td><td><input class="form-control" name="customer_phone" value="<?= htmlspecialchars($customer['phone'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="62812..."/></td><td><input class="form-control" type="number" name="customer_amount" value="<?= (int)$customer['amount']; ?>" min="0"/></td><td><input class="form-control" type="number" name="due_day" value="<?= (int)$customer['due_day']; ?>" min="1" max="31"/></td><td><input class="group-item group-item-sm" type="submit" name="update_customer" value="Simpan"/></td></form></tr><?php } ?></tbody></table></div>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Invoice</th><th>Pelanggan</th><th>Periode</th><th>Total</th><th>Status</th><th>Metode TriPay</th><th>Aksi</th></tr></thead><tbody>
      <?php if (!$invoices) { ?><tr><td colspan="7" class="text-center text-muted">Belum ada invoice.</td></tr><?php } else { foreach ($invoices as $invoice) { ?><tr><td><?= htmlspecialchars($invoice['invoice_no'], ENT_QUOTES, 'UTF-8'); ?></td><td><?= htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8'); ?><br><small><?= htmlspecialchars($invoice['username'], ENT_QUOTES, 'UTF-8'); ?></small></td><td><?= htmlspecialchars($invoice['period'], ENT_QUOTES, 'UTF-8'); ?></td><td><?= number_format((int)$invoice['amount'], 0, ',', '.'); ?></td><td><?= htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8'); ?></td><td><form method="post"><input type="hidden" name="invoice_no" value="<?= htmlspecialchars($invoice['invoice_no'], ENT_QUOTES, 'UTF-8'); ?>"/><select name="payment_method" class="form-control"><option value="">Pilih metode</option><?php foreach ($methods as $key => $label) { ?><option value="<?= $key; ?>" <?= isset($invoice['payment_method']) && $invoice['payment_method'] === $key ? 'selected' : ''; ?>><?= $label; ?></option><?php } ?></select></td><td><input class="group-item group-item-sm" type="submit" name="create_tripay" value="Bayar TriPay"/></form></td></tr><?php }} ?>
      </tbody></table></div>
    </div></div>
  </div>
</div>
