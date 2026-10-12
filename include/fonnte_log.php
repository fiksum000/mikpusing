<?php
if (!function_exists('fonnteLogPath')) {
if (!function_exists("decrypt")) require_once __DIR__ . '/../lib/decrypt_helper.php';
  function fonnteLogPath() {
    return __DIR__ . '/../storage/fonnte_logs.json';
  }
  function fonnteEnsureLogStorage() {
    $dir = dirname(fonnteLogPath());
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!file_exists(fonnteLogPath())) @file_put_contents(fonnteLogPath(), "[]", LOCK_EX);
  }
  function fonntePurgeOldLogs($retentionDays = 30) {
    fonnteEnsureLogStorage();
    $raw = @file_get_contents(fonnteLogPath());
    $logs = json_decode($raw ?: '[]', true);
    if (!is_array($logs)) $logs = array();
    $cutoff = time() - (max(1, (int)$retentionDays) * 86400);
    $kept = array();
    $removed = 0;
    foreach ($logs as $log) {
      $timestamp = isset($log['time']) ? strtotime($log['time']) : false;
      if ($timestamp !== false && $timestamp < $cutoff) { $removed++; continue; }
      $kept[] = $log;
    }
    if ($removed > 0) @file_put_contents(fonnteLogPath(), json_encode($kept, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $removed;
  }
  function fonnteMaskTarget($target) {
    $digits = preg_replace('/[^0-9]/', '', (string)$target);
    if (strlen($digits) <= 4) return $digits;
    return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
  }
  function fonnteReadLogs() {
    fonnteEnsureLogStorage();
    fonntePurgeOldLogs(30);
    $raw = @file_get_contents(fonnteLogPath());
    $logs = json_decode($raw ?: '[]', true);
    return is_array($logs) ? $logs : array();
  }
  function fonnteWriteLog($entry) {
    fonnteEnsureLogStorage();
    fonntePurgeOldLogs(30);
    $raw = @file_get_contents(fonnteLogPath());
    $logs = json_decode($raw ?: '[]', true);
    if (!is_array($logs)) $logs = array();
    array_unshift($logs, $entry);
    $logs = array_slice($logs, 0, 500);
    @file_put_contents(fonnteLogPath(), json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
  }
  function fonnteLogMessage($target, $message, $status, $detail = '', $meta = array()) {
    fonnteWriteLog(array(
      'time' => date('Y-m-d H:i:s'),
      'status' => $status,
      'target' => fonnteMaskTarget($target),
      'event' => isset($meta['event']) ? $meta['event'] : 'manual',
      'invoice' => isset($meta['invoice']) ? (string)$meta['invoice'] : '',
      'http_code' => isset($meta['http_code']) ? (int)$meta['http_code'] : 0,
      'message' => substr(preg_replace('/\s+/', ' ', (string)$message), 0, 160),
      'detail' => substr(preg_replace('/\s+/', ' ', (string)$detail), 0, 500),
    ));
  }
  function fonnteSendMessage($target, $message, $meta = array()) {
    global $fonnteToken;
    if (empty($fonnteToken)) {
      fonnteLogMessage($target, $message, 'failed', 'Token Fonnte belum dikonfigurasi.', $meta);
      return array('status' => false, 'reason' => 'Token Fonnte belum dikonfigurasi.');
    }
    if (!function_exists('curl_init')) {
      fonnteLogMessage($target, $message, 'failed', 'PHP cURL belum aktif.', $meta);
      return array('status' => false, 'reason' => 'PHP cURL belum aktif.');
    }
    $token = decrypt($fonnteToken);
    $phone = preg_replace('/[^0-9]/', '', (string)$target);
    if (substr($phone, 0, 1) === '0') $phone = '62' . substr($phone, 1);
    $fields = http_build_query(array('target' => $phone, 'message' => $message, 'countryCode' => '62', 'preview' => 'false'));
    $ch = curl_init('https://api.fonnte.com/send');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_HTTPHEADER => array('Authorization: ' . $token, 'Content-Type: application/x-www-form-urlencoded'), CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8));
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $body = json_decode($raw ?: '{}', true);
    $sent = ($error === '' && $http >= 200 && $http < 300 && !empty($body['status']));
    $detail = $error !== '' ? $error : (!empty($body['reason']) ? $body['reason'] : ($raw ?: 'Tidak ada respons Fonnte.'));
    $status = $sent ? 'sent' : 'failed';
    $meta['http_code'] = $http;
    fonnteLogMessage($phone, $message, $status, $detail, $meta);
    return is_array($body) ? $body : array('status' => false, 'reason' => $detail);
  }
}
?>
