<?php
if (!function_exists('tripayConfig')) {
if (!function_exists("decrypt")) require_once __DIR__ . '/../lib/decrypt_helper.php';
  function tripayConfig() {
    global $tripayApiKey, $tripayPrivateKey, $tripayMerchantCode, $tripayMode;
    return array(
      'api_key' => isset($tripayApiKey) ? decrypt($tripayApiKey) : '',
      'private_key' => isset($tripayPrivateKey) ? decrypt($tripayPrivateKey) : '',
      'merchant_code' => isset($tripayMerchantCode) ? $tripayMerchantCode : '',
      'mode' => isset($tripayMode) && $tripayMode === 'production' ? 'production' : 'sandbox',
    );
  }
  function tripayBaseUrl() { return tripayConfig()['mode'] === 'production' ? 'https://tripay.co.id/api' : 'https://tripay.co.id/api-sandbox'; }
  function tripayRequest($path, $fields = array()) {
    $config = tripayConfig();
    if ($config['api_key'] === '' || $config['private_key'] === '' || $config['merchant_code'] === '') return array('success' => false, 'message' => 'Konfigurasi TriPay belum lengkap.');
    if (!function_exists('curl_init')) return array('success' => false, 'message' => 'PHP cURL belum aktif.');
    $ch = curl_init(tripayBaseUrl() . $path);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $config['api_key'], 'Content-Type: application/x-www-form-urlencoded'), CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8));
    $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    $body = json_decode($raw ?: '{}', true);
    if ($error !== '') return array('success' => false, 'message' => $error, 'http_code' => $http);
    if ($http < 200 || $http >= 300 || empty($body['success'])) return array('success' => false, 'message' => !empty($body['message']) ? $body['message'] : 'TriPay menolak request.', 'http_code' => $http, 'raw' => $body);
    return array('success' => true, 'data' => isset($body['data']) ? $body['data'] : array(), 'message' => isset($body['message']) ? $body['message'] : 'OK');
  }
  function tripayCreateTransaction($invoice) {
    $config = tripayConfig();
    $merchantRef = $invoice['invoice_no']; $amount = (int)$invoice['amount'];
    $signature = hash_hmac('sha256', $config['merchant_code'] . $merchantRef . $amount, $config['private_key']);
    if (empty($_SERVER['HTTP_HOST'])) return array('success' => false, 'message' => 'HTTP_HOST tidak tersedia; periksa virtual host.');
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $returnUrl = $base . '/admin.php?id=billing&paid=1';
    $routerSession = trim((string)($invoice['router_session'] ?? ''));
    if ($routerSession !== '' && strtoupper($routerSession) !== 'RADIUS') $returnUrl .= '&session=' . rawurlencode($routerSession);
    return tripayRequest('/transaction/create', array('method' => $invoice['payment_method'], 'merchant_ref' => $merchantRef, 'amount' => $amount, 'customer_name' => $invoice['customer_name'], 'customer_email' => $invoice['customer_email'] ?: 'billing@example.com', 'customer_phone' => $invoice['customer_phone'], 'order_items[0][name]' => 'Tagihan Internet ' . $invoice['invoice_no'], 'order_items[0][price]' => $amount, 'order_items[0][quantity]' => 1, 'callback_url' => $base . '/admin.php?id=tripay-callback', 'return_url' => $returnUrl, 'expired_time' => time() + (24 * 60 * 60), 'signature' => $signature));
  }
}
?>
