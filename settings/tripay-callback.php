<?php
include_once('./include/billing.php');
include_once('./include/tripay.php');
include_once('./include/fonnte_log.php');
include_once('./include/wa_templates.php');

header('Content-Type: application/json; charset=utf-8');
$configuration = tripayConfig();
$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
$signature = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? (string)$_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : '';
$expected = hash_hmac('sha256', (string)$raw, (string)$configuration['private_key']);

function tripayCallbackRespond($code, $body) {
  http_response_code($code);
  echo json_encode($body, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
  tripayCallbackRespond(400, array('success' => false, 'message' => 'Invalid JSON payload'));
}
if ($configuration['private_key'] === '' || $signature === '' || !hash_equals($expected, $signature)) {
  tripayCallbackRespond(403, array('success' => false, 'message' => 'Invalid callback signature'));
}
$event = (string)($_SERVER['HTTP_X_CALLBACK_EVENT'] ?? '');
if ($event !== '' && $event !== 'payment_status') {
  tripayCallbackRespond(400, array('success' => false, 'message' => 'Unsupported callback event'));
}
$merchantRef = trim((string)($payload['merchant_ref'] ?? ''));
$status = strtoupper(trim((string)($payload['status'] ?? '')));
if (!in_array($status, array('UNPAID', 'PAID', 'FAILED', 'EXPIRED', 'CANCELED', 'CANCELLED', 'REFUND', 'REFUNDED'), true)) tripayCallbackRespond(400, array('success' => false, 'message' => 'Unsupported payment status'));
$reference = trim((string)($payload['reference'] ?? ''));
if ($merchantRef === '' || $reference === '' || $status === '') {
  tripayCallbackRespond(400, array('success' => false, 'message' => 'Missing transaction fields'));
}
// Share the same per-invoice lock as checkout creation and WhatsApp delivery.
$callbackLock = billingAcquireInvoiceLock($merchantRef, true);
if (!$callbackLock) {
  tripayCallbackRespond(503, array('success' => false, 'message' => 'Another operation for this invoice is processing; retry callback'));
}
register_shutdown_function(function() use ($callbackLock) {
  billingReleaseInvoiceLock($callbackLock);
});

$invoice = billingFindInvoice($merchantRef);
if (!$invoice) {
  tripayCallbackRespond(404, array('success' => false, 'message' => 'Invoice not found'));
}
$expectedReference = trim((string)($invoice['tripay_reference'] ?? ''));
if ($expectedReference === '') {
  // Never mark an invoice paid from a callback unless a transaction reference
  // was persisted when this invoice's checkout was created.
  tripayCallbackRespond(409, array('success' => false, 'message' => 'No registered TriPay transaction for this invoice'));
}
if (!hash_equals($expectedReference, $reference)) {
  tripayCallbackRespond(403, array('success' => false, 'message' => 'Transaction reference does not match this invoice'));
}
$invoiceAmount = (int)($invoice['amount'] ?? -1);
$customerFee = isset($payload['fee_customer']) && is_numeric($payload['fee_customer']) ? (int)$payload['fee_customer'] : 0;
$expectedTotal = $invoiceAmount + max(0, $customerFee);
if (!isset($payload['total_amount']) || !is_numeric($payload['total_amount']) || $invoiceAmount < 0 || (int)$payload['total_amount'] !== $expectedTotal) {
  tripayCallbackRespond(400, array('success' => false, 'message' => 'Transaction amount does not match invoice plus customer fee'));
}

if ($status === 'PAID') {
  if (strtolower((string)($invoice['status'] ?? '')) === 'refunded') {
    tripayCallbackRespond(409, array('success' => false, 'message' => 'This transaction was refunded; stale PAID callback ignored'));
  }
  if (strtolower((string)($invoice['status'] ?? '')) !== 'paid') {
    $updated = billingUpdateInvoice($merchantRef, array(
      'status' => 'paid',
      'tripay_reference' => $reference,
      'paid_at' => !empty($payload['paid_at']) && is_numeric($payload['paid_at']) ? date('Y-m-d H:i:s', (int)$payload['paid_at']) : date('Y-m-d H:i:s')
    ));
    if (!$updated) tripayCallbackRespond(500, array('success' => false, 'message' => 'Could not persist paid status; retry callback'));
    $invoice = billingFindInvoice($merchantRef);
    if (!$invoice) tripayCallbackRespond(500, array('success' => false, 'message' => 'Paid invoice could not be reloaded; retry callback'));
  }

  // Payment callbacks are often retried. Only send once the success timestamp
  // is persisted; a failed WhatsApp send remains eligible for a later callback.
  if (!empty($invoice['customer_phone']) && empty($invoice['payment_notification_sent_at']) && function_exists('fonnteSendMessage')) {
    $messageData = array(
      'nama' => (string)($invoice['customer_name'] ?? $invoice['username'] ?? ''),
      'invoice' => $merchantRef,
      'product' => (string)($invoice['product'] ?? 'Paket Internet'),
      'device' => (string)($invoice['device'] ?? $invoice['username'] ?? ''),
      'total' => 'Rp' . number_format((int)$invoice['amount'], 0, ',', '.'),
      'tanggal_bayar' => (string)($invoice['paid_at'] ?? date('Y-m-d H:i:s')),
      'bukti_pembayaran' => !empty($invoice['proof_url']) ? $invoice['proof_url'] : $reference,
      'referensi' => $reference,
      'kontak_admin' => (string)(getenv('MIKHMON_SUPPORT_PHONE') ?: ''),
      'nama_perusahaan' => (string)(getenv('MIKHMON_COMPANY_NAME') ?: 'Administrator Internet')
    );
    $sendResult = fonnteSendMessage((string)$invoice['customer_phone'], waTemplateRender('payment', $messageData), array('event' => 'payment', 'invoice' => $merchantRef));
    if (!empty($sendResult['status'])) {
      if (!billingUpdateInvoice($merchantRef, array('payment_notification_sent_at' => date('Y-m-d H:i:s')))) {
        tripayCallbackRespond(500, array('success' => false, 'message' => 'Payment saved but notification status could not be persisted; retry callback'));
      }
    } else {
      tripayCallbackRespond(503, array('success' => false, 'message' => 'Payment saved; WhatsApp notification failed and callback should be retried'));
    }
  } elseif (!empty($invoice['customer_phone']) && empty($invoice['payment_notification_sent_at']) && !function_exists('fonnteSendMessage')) {
    tripayCallbackRespond(503, array('success' => false, 'message' => 'Payment saved; WhatsApp sender is unavailable'));
  }
} elseif (in_array($status, array('REFUND', 'REFUNDED'), true)) {
  // Refund is a terminal financial state, even though the invoice had previously
  // been paid. The billing scheduler will apply normal delinquency rules again.
  if (!billingUpdateInvoice($merchantRef, array('status' => 'refunded', 'tripay_reference' => $reference, 'payment_url' => ''))) {
    tripayCallbackRespond(500, array('success' => false, 'message' => 'Could not persist refunded status; retry callback'));
  }
} elseif (in_array($status, array('FAILED', 'EXPIRED', 'CANCELED', 'CANCELLED'), true)
    && !in_array(strtolower((string)($invoice['status'] ?? '')), array('paid', 'refunded'), true)) {
  $mappedStatus = ($status === 'EXPIRED') ? 'expired' : 'failed';
  if (!billingUpdateInvoice($merchantRef, array('status' => $mappedStatus, 'tripay_reference' => $reference, 'payment_url' => ''))) {
    tripayCallbackRespond(500, array('success' => false, 'message' => 'Could not persist transaction status; retry callback'));
  }
}
tripayCallbackRespond(200, array('success' => true, 'message' => 'Callback processed', 'invoice' => $merchantRef, 'status' => $status));
?>
