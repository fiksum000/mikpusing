<?php
if (!function_exists('customerPath')) {
  function customerPath() { return __DIR__ . '/../storage/customers.json'; }
  function customerEnsureStorage() { $dir = dirname(customerPath()); if (!is_dir($dir)) @mkdir($dir, 0750, true); if (!file_exists(customerPath())) @file_put_contents(customerPath(), '[]', LOCK_EX); }
  function customerReadAll() { customerEnsureStorage(); $rows = json_decode(@file_get_contents(customerPath()) ?: '[]', true); return is_array($rows) ? $rows : array(); }
  function customerWriteAll($rows) { customerEnsureStorage(); @file_put_contents(customerPath(), json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX); }
  function customerFind($username) { foreach (customerReadAll() as $row) if (isset($row['username']) && $row['username'] === $username) return $row; return null; }
  function customerUpsertFromSecret($secret, $routerSession = '') {
    if (empty($secret['name'])) return;
    $rows = customerReadAll(); $found = false;
    foreach ($rows as &$row) {
      if (isset($row['username']) && $row['username'] === $secret['name']) {
        $row['router_session'] = $routerSession; $row['mikrotik_comment'] = isset($secret['comment']) ? $secret['comment'] : ''; if (isset($secret['profile']) && strtoupper($secret['profile']) !== 'ISOLIR') { $row['profile'] = $secret['profile']; $row['normal_profile'] = $secret['profile']; } $row['last_sync'] = date('Y-m-d H:i:s'); $found = true; break;
      }
    }
    if (!$found) $rows[] = array('username' => $secret['name'], 'name' => isset($secret['comment']) && $secret['comment'] !== '' ? $secret['comment'] : $secret['name'], 'phone' => '', 'email' => '', 'amount' => 0, 'due_day' => 20, 'router_session' => $routerSession, 'profile' => isset($secret['profile']) ? $secret['profile'] : '', 'normal_profile' => isset($secret['profile']) ? $secret['profile'] : '', 'status' => isset($secret['disabled']) && $secret['disabled'] === 'true' ? 'disabled' : 'active', 'mikrotik_comment' => isset($secret['comment']) ? $secret['comment'] : '', 'last_sync' => date('Y-m-d H:i:s'));
    customerWriteAll($rows);
  }
  function customerSyncSecrets($secrets, $routerSession = '') { foreach ((array)$secrets as $secret) customerUpsertFromSecret($secret, $routerSession); return customerReadAll(); }
  function customerUpdate($username, $values) { $rows = customerReadAll(); foreach ($rows as &$row) if (isset($row['username']) && $row['username'] === $username) $row = array_merge($row, $values); customerWriteAll($rows); }
}
?>
