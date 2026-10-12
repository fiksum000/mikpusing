<?php
include_once(__DIR__ . '/wa_templates.php');
if (!function_exists('billingPath')) {
  function billingPath() { return __DIR__ . '/../storage/invoices.json'; }
  function billingEnsureStorage() {
    $dir = dirname(billingPath());
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!file_exists(billingPath())) @file_put_contents(billingPath(), '[]', LOCK_EX);
  }
  function billingReadInvoices() {
    billingEnsureStorage();
    $rows = json_decode(@file_get_contents(billingPath()) ?: '[]', true);
    return is_array($rows) ? $rows : array();
  }
  function billingWriteInvoices($rows) {
    billingEnsureStorage();
    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return $json !== false && @file_put_contents(billingPath(), $json, LOCK_EX) !== false;
  }
  function billingValidPeriod($period) {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$period)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    return $date && $date->format('Y-m') === $period;
  }
  /**
   * Stable, collision-resistant invoice identifier. Router session is included
   * to keep same usernames on multiple local routers separate.
   */
  function billingInvoiceNo($customer, $period, $routerSession = '') {
    if (!billingValidPeriod($period)) throw new InvalidArgumentException('Periode invoice harus berformat YYYY-MM.');
    $identity = (string)$routerSession . '|' . (string)$customer;
    $hash = strtoupper(substr(hash('sha256', $identity), 0, 12));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    return 'INV-' . $date->format('Ym') . '-' . $hash;
  }
  function billingDueDate($period, $dueDay) {
    if (!billingValidPeriod($period)) throw new InvalidArgumentException('Periode invoice harus berformat YYYY-MM.');
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    $day = min((int)$monthStart->format('t'), max(1, (int)$dueDay));
    return $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('m'), $day)->format('Y-m-d');
  }
  function billingLegacyInvoiceNo($customer, $period) {
    if (!billingValidPeriod($period)) return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    $legacy = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$customer), 0, 12));
    return 'INV-' . $date->format('Ym') . '-' . $legacy;
  }
  function billingCreateForCustomers($customers, $period, $defaultAmount = 0) {
    if (!billingValidPeriod($period)) throw new InvalidArgumentException('Periode invoice harus berformat YYYY-MM.');
    $invoices = billingReadInvoices(); $created = 0; $existing = array();
    foreach ($invoices as $invoice) if (!empty($invoice['invoice_no'])) $existing[$invoice['invoice_no']] = $invoice;
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    $daysInMonth = (int)$monthStart->format('t');
    foreach ($customers as $customer) {
      $name = trim((string)($customer['name'] ?? ''));
      $username = trim((string)($customer['username'] ?? ''));
      $routerSession = trim((string)($customer['router_session'] ?? ''));
      if ($username === '') continue;
      $invoiceNo = billingInvoiceNo($username, $period, $routerSession);
      $legacyInvoiceNo = billingLegacyInvoiceNo($username, $period);
      if (isset($existing[$invoiceNo])) continue;
      if ($legacyInvoiceNo !== '' && isset($existing[$legacyInvoiceNo])) {
        $legacySession = (string)($existing[$legacyInvoiceNo]['router_session'] ?? '');
        if ($legacySession === $routerSession) continue;
      }
      $amount = isset($customer['amount']) && (int)$customer['amount'] > 0 ? (int)$customer['amount'] : (int)$defaultAmount;
      // Never create a zero-value invoice that could accidentally trigger isolation.
      if ($amount <= 0) continue;
      $dueDay = min($daysInMonth, max(1, (int)($customer['due_day'] ?? 20)));
      $dueDate = billingDueDate($period, $dueDay);
      $newInvoice = array(
        'invoice_no' => $invoiceNo, 'period' => $period, 'router_session' => $routerSession,
        'customer_name' => $name !== '' ? $name : $username, 'username' => $username,
        'product' => !empty($customer['profile']) ? (string)$customer['profile'] : 'Paket PPPoE',
        'device' => !empty($customer['device']) ? (string)$customer['device'] : $username,
        'customer_phone' => (string)($customer['phone'] ?? ''),
        'customer_email' => (string)($customer['email'] ?? ''),
        'amount' => $amount, 'due_date' => $dueDate, 'status' => 'unpaid',
        'payment_method' => '', 'tripay_reference' => '', 'payment_url' => '',
        'proof_url' => '', 'created_at' => date('Y-m-d H:i:s'), 'paid_at' => ''
      );
      $invoices[] = $newInvoice;
      if ($newInvoice['customer_phone'] !== '' && function_exists('fonnteSendMessage')) {
        $message = waTemplateRender('invoice', array(
          'nama' => $newInvoice['customer_name'], 'invoice' => $invoiceNo,
          'periode' => $period, 'total' => 'Rp' . number_format($amount, 0, ',', '.'),
          'jatuh_tempo' => $dueDate, 'link_bayar' => 'Hubungi admin untuk tautan pembayaran.'
        ));
        fonnteSendMessage($newInvoice['customer_phone'], $message, array('event' => 'invoice', 'invoice' => $invoiceNo));
      }
      $existing[$invoiceNo] = $newInvoice; $created++;
    }
    if (!billingWriteInvoices($invoices)) throw new RuntimeException('Gagal menulis storage/invoices.json.');
    return $created;
  }
  function billingFindInvoice($invoiceNo) {
    foreach (billingReadInvoices() as $invoice) if (isset($invoice['invoice_no']) && $invoice['invoice_no'] === $invoiceNo) return $invoice;
    return null;
  }
  function billingUpdateInvoice($invoiceNo, $values) {
    $rows = billingReadInvoices(); $updated = false;
    foreach ($rows as &$row) {
      if (isset($row['invoice_no']) && $row['invoice_no'] === $invoiceNo) {
        $row = array_merge($row, $values); $updated = true; break;
      }
    }
    unset($row);
    if ($updated) return billingWriteInvoices($rows);
    return false;
  }
}
?>
