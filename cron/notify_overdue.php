<?php
/**
 * Send due/overdue reminders once per invoice per calendar day.
 * Run daily from cron. This sends real WhatsApp messages when Fonnte is configured.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../lib/decrypt_helper.php'; // token codec only; do not load or connect RouterOS API
require_once __DIR__ . '/../include/billing.php';
require_once __DIR__ . '/../include/customers.php';
require_once __DIR__ . '/../include/fonnte_log.php';
require_once __DIR__ . '/../include/wa_templates.php';

$statePath = __DIR__ . '/../storage/reminder_state.json';
$stateDir = dirname($statePath);
if (!is_dir($stateDir) && !@mkdir($stateDir, 0750, true) && !is_dir($stateDir)) {
    fwrite(STDERR, "Tidak bisa membuat direktori storage.\n"); exit(1);
}
$state = array();
if (is_file($statePath)) {
    $parsed = json_decode(@file_get_contents($statePath) ?: '{}', true);
    if (is_array($parsed)) $state = $parsed;
}
$today = date('Y-m-d');
$todayTime = strtotime($today);
$sent = 0; $skipped = 0; $failed = 0;

foreach (billingReadInvoices() as $invoice) {
    $status = strtolower((string)($invoice['status'] ?? 'unpaid'));
    if (!in_array($status, array('unpaid', 'pending', 'failed'), true)) { $skipped++; continue; }
    $dueDate = (string)($invoice['due_date'] ?? '');
    $dueTime = $dueDate !== '' ? strtotime($dueDate) : false;
    if ($dueTime === false) { $skipped++; continue; }
    $daysUntilDue = (int)floor(($dueTime - $todayTime) / 86400);
    if ($daysUntilDue > 3) { $skipped++; continue; }
    $phone = trim((string)($invoice['customer_phone'] ?? ''));
    if ($phone === '') { $skipped++; continue; }
    $invoiceNo = (string)($invoice['invoice_no'] ?? '');
    if ($invoiceNo === '') { $skipped++; continue; }
    $kind = ($daysUntilDue < 0) ? 'overdue' : 'reminder';
    $stateKey = $invoiceNo . '|' . $kind;
    $lastSentAt = !empty($state[$stateKey]) ? strtotime((string)$state[$stateKey]) : false;
    if ($lastSentAt !== false) {
      if ($kind === 'reminder' && date('Y-m-d', $lastSentAt) === $today) { $skipped++; continue; }
      if ($kind === 'overdue' && $lastSentAt > time() - 3 * 86400) { $skipped++; continue; }
    }

    $data = array(
      'nama' => (string)($invoice['customer_name'] ?? $invoice['username'] ?? ''),
      'invoice' => $invoiceNo,
      'periode' => (string)($invoice['period'] ?? ''),
      'total' => 'Rp' . number_format((int)($invoice['amount'] ?? 0), 0, ',', '.'),
      'jatuh_tempo' => $dueDate,
      'link_bayar' => !empty($invoice['payment_url']) ? (string)$invoice['payment_url'] : '-',
      'product' => (string)($invoice['product'] ?? 'Paket Internet'),
      'device' => (string)($invoice['device'] ?? $invoice['username'] ?? ''),
      'nama_perusahaan' => (string)(getenv('MIKHMON_COMPANY_NAME') ?: 'Administrator Internet'),
      'kontak_admin' => (string)(getenv('MIKHMON_SUPPORT_PHONE') ?: ''),
    );
    $message = waTemplateRender($kind, $data);
    $result = fonnteSendMessage($phone, $message, array('event' => $kind, 'invoice' => $invoiceNo));
    if (!empty($result['status'])) {
        $state[$stateKey] = date('c');
        $sent++;
        echo "SENT invoice={$invoiceNo} type={$kind}\n";
    } else {
        $failed++;
        echo "FAILED invoice={$invoiceNo} type={$kind} reason=" . preg_replace('/\s+/', ' ', (string)($result['reason'] ?? 'unknown')) . "\n";
    }
}

$json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($json === false || @file_put_contents($statePath, $json, LOCK_EX) === false) {
    fwrite(STDERR, "Gagal menyimpan reminder state; pesan bisa terkirim ulang pada run berikutnya.\n");
    exit(1);
}
echo date('c') . " Reminder summary sent={$sent} failed={$failed} skipped={$skipped}\n";
if ($failed > 0) exit(1);
?>
