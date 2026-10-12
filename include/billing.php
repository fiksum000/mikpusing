<?php
include_once(__DIR__ . '/wa_templates.php');
if (!function_exists('billingPath')) {
  function billingPath() { return __DIR__ . '/../storage/invoices.json'; }
  function billingEnsureStorage() { $dir = dirname(billingPath()); if (!is_dir($dir)) @mkdir($dir, 0750, true); if (!file_exists(billingPath())) @file_put_contents(billingPath(), '[]', LOCK_EX); }
  function billingReadInvoices() { billingEnsureStorage(); $rows = json_decode(@file_get_contents(billingPath()) ?: '[]', true); return is_array($rows) ? $rows : array(); }
  function billingWriteInvoices($rows) { billingEnsureStorage(); @file_put_contents(billingPath(), json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX); }
  function billingInvoiceNo($customer, $period) { return 'INV-' . date('Ym', strtotime($period . '-01')) . '-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $customer), 0, 12)); }
  function billingCreateForCustomers($customers, $period, $defaultAmount = 0) {
    $invoices = billingReadInvoices(); $created = 0; $existing = array();
    foreach ($invoices as $invoice) $existing[$invoice['invoice_no']] = true;
    foreach ($customers as $customer) {
      $name = isset($customer['name']) ? trim($customer['name']) : '';
      $username = isset($customer['username']) ? trim($customer['username']) : '';
      if ($username === '') continue;
      $invoiceNo = billingInvoiceNo($username, $period);
      if (isset($existing[$invoiceNo])) continue;
      $amount = isset($customer['amount']) && (int)$customer['amount'] > 0 ? (int)$customer['amount'] : (int)$defaultAmount;
      $dueDay = isset($customer['due_day']) ? min(31, max(1, (int)$customer['due_day'])) : 20;
      $newInvoice = array('invoice_no' => $invoiceNo, 'period' => $period, 'customer_name' => $name ?: $username, 'username' => $username, 'product' => isset($customer['profile']) && $customer['profile'] !== '' ? $customer['profile'] : 'Paket PPPoE', 'device' => isset($customer['device']) && $customer['device'] !== '' ? $customer['device'] : $username, 'customer_phone' => isset($customer['phone']) ? $customer['phone'] : '', 'customer_email' => isset($customer['email']) ? $customer['email'] : '', 'amount' => $amount, 'due_date' => date('Y-m-d', strtotime($period . '-' . str_pad($dueDay, 2, '0', STR_PAD_LEFT))), 'status' => 'unpaid', 'payment_method' => '', 'tripay_reference' => '', 'proof_url' => '', 'created_at' => date('Y-m-d H:i:s'), 'paid_at' => '');
      $invoices[] = $newInvoice;
      if (!empty($newInvoice['customer_phone']) && function_exists('fonnteSendMessage')) fonnteSendMessage($newInvoice['customer_phone'], waTemplateRender('invoice', array('nama' => $newInvoice['customer_name'], 'invoice' => $invoiceNo, 'periode' => $period, 'total' => 'Rp' . number_format($amount, 0, ',', '.'), 'jatuh_tempo' => $newInvoice['due_date'], 'link_bayar' => 'Link pembayaran menyusul setelah metode dipilih.')), array('event' => 'invoice', 'invoice' => $invoiceNo));
      $existing[$invoiceNo] = true; $created++;
    }
    billingWriteInvoices($invoices); return $created;
  }
  function billingFindInvoice($invoiceNo) { foreach (billingReadInvoices() as $invoice) if (isset($invoice['invoice_no']) && $invoice['invoice_no'] === $invoiceNo) return $invoice; return null; }
  function billingUpdateInvoice($invoiceNo, $values) { $rows = billingReadInvoices(); foreach ($rows as &$row) if (isset($row['invoice_no']) && $row['invoice_no'] === $invoiceNo) $row = array_merge($row, $values); billingWriteInvoices($rows); }
}
?>
