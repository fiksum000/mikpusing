<?php
include_once('./include/billing.php');
include_once('./include/tripay.php');
include_once('./include/fonnte_log.php');
include_once('./include/wa_templates.php');
$config = tripayConfig();
$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
$signature = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : (isset($_POST['signature']) ? $_POST['signature'] : '');
$expected = hash_hmac('sha256', $raw, $config['private_key']);
header('Content-Type: application/json; charset=utf-8');
if ($config['private_key'] === '' || $signature === '' || !hash_equals($expected, $signature)) { http_response_code(403); echo json_encode(array('success' => false, 'message' => 'Invalid callback signature')); exit; }
$merchantRef = isset($payload['merchant_ref']) ? trim($payload['merchant_ref']) : '';
$status = strtoupper(isset($payload['status']) ? $payload['status'] : '');
$invoice = billingFindInvoice($merchantRef);
if (!$invoice) { http_response_code(404); echo json_encode(array('success' => false, 'message' => 'Invoice not found')); exit; }
if ($status === 'PAID' && $invoice['status'] !== 'paid') {
  billingUpdateInvoice($merchantRef, array('status' => 'paid', 'tripay_reference' => isset($payload['reference']) ? $payload['reference'] : $invoice['tripay_reference'], 'paid_at' => date('Y-m-d H:i:s')));
  if (!empty($invoice['customer_phone']) && function_exists('fonnteSendMessage')) fonnteSendMessage($invoice['customer_phone'], waTemplateRender('payment', array('nama' => $invoice['customer_name'], 'invoice' => $merchantRef, 'product' => isset($invoice['product']) ? $invoice['product'] : 'Paket PPPoE', 'device' => isset($invoice['device']) ? $invoice['device'] : $invoice['username'], 'total' => 'Rp' . number_format((int)$invoice['amount'], 0, ',', '.'), 'tanggal_bayar' => date('Y-m-d H:i:s'), 'bukti_pembayaran' => !empty($invoice['proof_url']) ? $invoice['proof_url'] : (isset($payload['payment_url']) ? $payload['payment_url'] : '-'), 'referensi' => isset($payload['reference']) ? $payload['reference'] : '', 'kontak_admin' => '0859102797611', 'nama_perusahaan' => 'PT Usaha Adi Sanggoro')), array('event' => 'payment', 'invoice' => $merchantRef));
}
if ($status === 'FAILED' && $invoice['status'] !== 'paid') billingUpdateInvoice($merchantRef, array('status' => 'failed'));
echo json_encode(array('success' => true, 'message' => 'Callback processed', 'invoice' => $merchantRef, 'status' => $status));
exit;
?>
