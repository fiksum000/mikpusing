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
require_once __DIR__ . '/../include/job_runtime.php';
$batchStarted = microtime(true); $batchAttempted = 0;

$runLockPath = __DIR__ . '/../storage/notify-overdue.lock';
$runLock = @fopen($runLockPath, 'c');
if (!$runLock || !flock($runLock, LOCK_EX | LOCK_NB)) {
    if (is_resource($runLock)) fclose($runLock);
    echo date('c') . " Reminder run skipped: another run is active.\\n";
    exit(0);
}
register_shutdown_function(function() use ($runLock) {
    if (is_resource($runLock)) {
        flock($runLock, LOCK_UN);
        fclose($runLock);
    }
});

$statePath = __DIR__ . '/../storage/reminder_state.json';
$stateDir = dirname($statePath);
if (!is_dir($stateDir) && !@mkdir($stateDir, 0750, true) && !is_dir($stateDir)) {
    fwrite(STDERR, "Tidak bisa membuat direktori storage.\n"); exit(1);
}
$state = array();
if (is_file($statePath)) {
    $parsed = json_decode(@file_get_contents($statePath) ?: '{}', true);
    if (!is_array($parsed)) throw new RuntimeException('Reminder JSON rusak; pulihkan backup.');
    $state = $parsed;
}
$today = date('Y-m-d');
$todayTime = strtotime($today);
$sent = 0; $skipped = 0; $failed = 0;

$reminderQueue = billingReadInvoices();
usort($reminderQueue, function($a, $b) use ($state) { return strcmp($state['attempt:' . ($a['invoice_no'] ?? '')] ?? '', $state['attempt:' . ($b['invoice_no'] ?? '')] ?? ''); });
foreach ($reminderQueue as $listedInvoice) {
    if (mikhmonBatchExpired($batchStarted, $batchAttempted)) break;
    $invoiceNo = trim((string)($listedInvoice['invoice_no'] ?? ''));
    if ($invoiceNo === '') { $skipped++; continue; }
    $invoiceLock = billingAcquireInvoiceLock($invoiceNo);
    if (!$invoiceLock) { $skipped++; continue; }
    try {
    // Reload after locking so a concurrent PAID callback cannot leave a stale
    // unpaid snapshot causing a reminder to be sent after the customer paid.
    $invoice = billingFindInvoice($invoiceNo);
    if (!is_array($invoice)) { $skipped++; continue; }
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
    $batchAttempted++;
    $state['attempt:' . $invoiceNo] = date('c');
    $result = fonnteSendMessage($phone, $message, array('event' => $kind, 'invoice' => $invoiceNo));
    if (!empty($result['status'])) {
        $state[$stateKey] = date('c');
        $sent++;
        echo "SENT invoice={$invoiceNo} type={$kind}\n";
    } else {
        $failed++;
        echo "FAILED invoice={$invoiceNo} type={$kind} reason=" . preg_replace('/\s+/', ' ', (string)($result['reason'] ?? 'unknown')) . "\n";
    }
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($statePath . '.tmp', $json, LOCK_EX) === false || !rename($statePath . '.tmp', $statePath)) throw new RuntimeException('Gagal menyimpan reminder state');
    } finally {
        billingReleaseInvoiceLock($invoiceLock);
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
