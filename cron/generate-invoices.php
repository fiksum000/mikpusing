<?php
/**
 * Create monthly invoices idempotently. Run daily from cron after configuring
 * non-zero customer prices (or MIKHMON_DEFAULT_MONTHLY_AMOUNT).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');
require_once __DIR__ . '/../lib/free_radius_client.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/customers.php';
require_once __DIR__ . '/../include/billing.php';
require_once __DIR__ . '/../include/tripay.php';
require_once __DIR__ . '/../include/wa_templates.php';
require_once __DIR__ . '/../include/fonnte_log.php';

$period = date('Y-m');
$integrationSettings = mikhmonIntegrationSettings();
$defaultAmountEnv = getenv('MIKHMON_DEFAULT_MONTHLY_AMOUNT');
$defaultAmount = max(0, (int)($defaultAmountEnv !== false && $defaultAmountEnv !== '' ? $defaultAmountEnv : ($integrationSettings['default_monthly_amount'] ?? 0)));
$backendConfig = mikhmonRadiusConfig();
$backend = 'radius'; // Full RADIUS branch: legacy environment overrides are intentionally ignored.
$totalCreated = 0; $totalCustomers = 0; $errors = 0;
$logPath = __DIR__ . '/../storage/invoice-generation.log';
function mikhmonInvoiceLog($message) {
  global $logPath;
  $dir = dirname($logPath);
  if (!is_dir($dir)) @mkdir($dir, 0750, true);
  @file_put_contents($logPath, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

try {
  $radius = new RadiusClient();
  $secrets = array();
  foreach ($radius->listUsers() as $user) {
    $secrets[] = array('name' => (string)($user['username'] ?? ''), 'profile' => (string)($user['profile'] ?? ''), 'comment' => '');
  }
  customerSyncSecrets($secrets, 'RADIUS');
  $customers = array_values(array_filter(customerReadAll(), function($row) {
    return (string)($row['router_session'] ?? '') === 'RADIUS';
  }));
  $totalCustomers = count($customers);
  $created = billingCreateForCustomers($customers, $period, $defaultAmount);
  $totalCreated += $created;
  mikhmonInvoiceLog("backend=radius period={$period} customers={$totalCustomers} created={$created}");
} catch (Throwable $e) {
  $errors++;
  mikhmonInvoiceLog("ERROR backend=radius period={$period} message=" . preg_replace('/\\s+/', ' ', $e->getMessage()));
  fwrite(STDERR, "Gagal membuat invoice RADIUS: " . $e->getMessage() . PHP_EOL);
} finally {
  if (isset($radius) && $radius instanceof RadiusClient) $radius->disconnect();
}

// Automatically create checkout links and retry WhatsApp delivery without creating duplicate transactions.
$automaticLinksCreated = 0;
$automaticLinksFailed = 0;
$autoMethod = strtoupper(trim((string)($integrationSettings['default_payment_method'] ?? '')));
$tripaySettings = tripayConfig();
$autoCheckoutReady = $autoMethod !== '' && !empty($tripaySettings['api_key']) && !empty($tripaySettings['private_key']) && !empty($tripaySettings['merchant_code']) && tripayPublicBaseUrl() !== '';
if ($autoMethod !== '' && !$autoCheckoutReady) {
  mikhmonInvoiceLog("WARN auto_checkout disabled: check TriPay credentials and public HTTPS URL in Billing > Integrations");
}
if ($autoCheckoutReady) {
  foreach (billingReadInvoices() as $listedInvoice) {
    $invoiceNo = trim((string)($listedInvoice['invoice_no'] ?? ''));
    if ($invoiceNo === '') continue;

    // Serialize this invoice's payment creation and invoice-link WhatsApp delivery.
    $invoiceLock = billingAcquireInvoiceLock($invoiceNo);
    if (!$invoiceLock) {
      mikhmonInvoiceLog("INFO auto_checkout invoice={$invoiceNo} skipped: invoice operation is already running");
      continue;
    }

    try {
      $invoice = billingFindInvoice($invoiceNo);
      if (!$invoice || (string)($invoice['period'] ?? '') !== $period) continue;
      $status = strtolower((string)($invoice['status'] ?? 'unpaid'));
      if ($status === 'paid' || (int)($invoice['amount'] ?? 0) <= 0) continue;

      $phone = trim((string)($invoice['customer_phone'] ?? ''));
      if ($phone === '') {
        mikhmonInvoiceLog("WARN auto_checkout invoice={$invoiceNo} skipped_no_customer_phone");
        continue;
      }

      $checkout = trim((string)($invoice['payment_url'] ?? ''));
      $reference = trim((string)($invoice['tripay_reference'] ?? ''));
      if ($checkout === '') {
        // Pending with an unknown checkout state is ambiguous: never create another transaction.
        if ($status === 'pending') {
          mikhmonInvoiceLog("WARN auto_checkout invoice={$invoiceNo} pending_without_checkout_url; investigate TriPay dashboard");
          continue;
        }
        if (!in_array($status, array('unpaid','failed','expired'), true)) continue;

        $invoice['payment_method'] = $autoMethod;
        try {
          // Persist intent before the remote call: timeout/crash must not create a second transaction.
          if (!billingUpdateInvoice($invoiceNo, array('status' => 'pending', 'payment_method' => $autoMethod, 'tripay_reference' => '', 'payment_url' => ''))) throw new RuntimeException('Tidak dapat menyimpan status checkout.');
          $result = tripayCreateTransaction($invoice);
          $checkout = !empty($result['data']['checkout_url']) ? trim((string)$result['data']['checkout_url']) : '';
          $reference = !empty($result['data']['reference']) ? trim((string)$result['data']['reference']) : '';
          if (empty($result['success']) || $checkout === '' || $reference === '') {
            $automaticLinksFailed++;
            mikhmonInvoiceLog("ERROR auto_checkout invoice={$invoiceNo} method={$autoMethod} message=" . preg_replace('/\\s+/', ' ', (string)($result['message'] ?? 'No checkout URL/reference')));
            continue;
          }
          $updated = billingUpdateInvoice($invoiceNo, array(
            'payment_method' => $autoMethod,
            'tripay_reference' => $reference,
            'payment_url' => $checkout,
            'status' => 'pending',
            'invoice_notification_sent_at' => ''
          ));
          if (!$updated) {
            $automaticLinksFailed++;
            mikhmonInvoiceLog("ERROR auto_checkout invoice={$invoiceNo} transaction_created_but_invoice_write_failed; check TriPay dashboard before retry");
            continue;
          }
        } catch (Throwable $e) {
          $automaticLinksFailed++;
          mikhmonInvoiceLog("ERROR auto_checkout invoice={$invoiceNo} message=" . preg_replace('/\\s+/', ' ', $e->getMessage()));
          continue;
        }
      } elseif ($status !== 'pending') {
        continue;
      }

      // Reload after checkout creation and before delivery so we never notify a
      // now-paid invoice with a stale payment link.
      $invoice = billingFindInvoice($invoiceNo);
      if (!$invoice || strtolower((string)($invoice['status'] ?? '')) === 'paid') continue;
      $checkout = trim((string)($invoice['payment_url'] ?? ''));
      $reference = trim((string)($invoice['tripay_reference'] ?? $reference));
      if ($checkout === '') {
        mikhmonInvoiceLog("WARN auto_checkout invoice={$invoiceNo} has no saved checkout URL; notification skipped");
        continue;
      }
      if (!empty($invoice['invoice_notification_sent_at'])) continue;

      $message = waTemplateRender('invoice', array(
        'nama' => (string)($invoice['customer_name'] ?? $invoice['username'] ?? ''),
        'invoice' => $invoiceNo,
        'periode' => (string)($invoice['period'] ?? ''),
        'total' => 'Rp' . number_format((int)$invoice['amount'], 0, ',', '.'),
        'jatuh_tempo' => (string)($invoice['due_date'] ?? ''),
        'link_bayar' => $checkout
      ));
      $sentResult = fonnteSendMessage($phone, $message, array('event' => 'invoice', 'invoice' => $invoiceNo));
      if (empty($sentResult['status'])) {
        $automaticLinksFailed++;
        mikhmonInvoiceLog("WARN auto_checkout invoice={$invoiceNo} checkout_saved_but_whatsapp_failed reason=" . preg_replace('/\\s+/', ' ', (string)($sentResult['reason'] ?? 'unknown')));
        continue;
      }
      if (!billingUpdateInvoice($invoiceNo, array('invoice_notification_sent_at' => date('Y-m-d H:i:s')))) {
        $automaticLinksFailed++;
        mikhmonInvoiceLog("WARN auto_checkout invoice={$invoiceNo} whatsapp_sent_but_sent_marker_write_failed; duplicate notification possible on next run");
        continue;
      }
      $automaticLinksCreated++;
      mikhmonInvoiceLog("AUTO_CHECKOUT invoice={$invoiceNo} method={$autoMethod} reference={$reference} whatsapp=sent");
    } catch (Throwable $e) {
      $automaticLinksFailed++;
      mikhmonInvoiceLog("ERROR auto_checkout invoice={$invoiceNo} message=" . preg_replace('/\\s+/', ' ', $e->getMessage()));
    } finally {
      billingReleaseInvoiceLock($invoiceLock);
    }
  }
}
$summary = "Invoice generation period={$period} backend={$backend} customers={$totalCustomers} created={$totalCreated} checkout_links={$automaticLinksCreated} checkout_failures={$automaticLinksFailed} errors={$errors} default_amount={$defaultAmount}";
echo date('c') . ' ' . $summary . PHP_EOL;
mikhmonInvoiceLog($summary);
if ($errors > 0) exit(1);
?>
