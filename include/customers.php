<?php
if (!function_exists('customerPath')) {
  function customerPath() { return __DIR__ . '/../storage/customers.json'; }
  function customerEnsureStorage() {
    $dir = dirname(customerPath());
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!file_exists(customerPath())) @file_put_contents(customerPath(), '[]', LOCK_EX);
  }
  function customerReadAll() {
    customerEnsureStorage();
    $rows = json_decode(@file_get_contents(customerPath()) ?: '[]', true);
    return is_array($rows) ? $rows : array();
  }
  function customerWriteAll($rows) {
    customerEnsureStorage();
    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return $json !== false && @file_put_contents(customerPath(), $json, LOCK_EX) !== false;
  }
  function customerFind($username, $routerSession = null) {
    foreach (customerReadAll() as $row) {
      if (!isset($row['username']) || $row['username'] !== $username) continue;
      if ($routerSession !== null && (string)($row['router_session'] ?? '') !== (string)$routerSession) continue;
      return $row;
    }
    return null;
  }
  function customerUpsertFromSecret($secret, $routerSession = '') {
    if (empty($secret['name'])) return false;
    $rows = customerReadAll(); $found = false;
    foreach ($rows as &$row) {
      if (($row['username'] ?? '') === $secret['name'] && (string)($row['router_session'] ?? '') === (string)$routerSession) {
        $row['router_session'] = $routerSession;
        $row['mikrotik_comment'] = (string)($secret['comment'] ?? '');
        if (isset($secret['profile']) && strtoupper((string)$secret['profile']) !== 'ISOLIR') {
          $row['profile'] = $secret['profile']; $row['normal_profile'] = $secret['profile'];
        }
        $row['status'] = (($secret['disabled'] ?? '') === 'true') ? 'disabled' : 'active';
        $row['last_sync'] = date('Y-m-d H:i:s');
        $found = true; break;
      }
    }
    unset($row);
    if (!$found) {
      $rows[] = array(
        'username' => (string)$secret['name'],
        'name' => !empty($secret['comment']) ? (string)$secret['comment'] : (string)$secret['name'],
        'phone' => '', 'email' => '', 'amount' => 0, 'due_day' => 20,
        'router_session' => (string)$routerSession,
        'profile' => (string)($secret['profile'] ?? ''),
        'normal_profile' => (string)($secret['profile'] ?? ''),
        'status' => (($secret['disabled'] ?? '') === 'true') ? 'disabled' : 'active',
        'mikrotik_comment' => (string)($secret['comment'] ?? ''),
        'last_sync' => date('Y-m-d H:i:s')
      );
    }
    return customerWriteAll($rows);
  }
  function customerSyncSecrets($secrets, $routerSession = '') {
    // Batch upsert: one read and one write per router, not one disk write per subscriber.
    $rows = customerReadAll();
    $index = array();
    foreach ($rows as $i => $row) {
      $key = (string)($row['router_session'] ?? '') . '|' . (string)($row['username'] ?? '');
      $index[$key] = $i;
    }
    $now = date('Y-m-d H:i:s');
    $changed = false;
    foreach ((array)$secrets as $secret) {
      if (empty($secret['name'])) continue;
      $username = (string)$secret['name'];
      $key = (string)$routerSession . '|' . $username;
      $comment = (string)($secret['comment'] ?? '');
      $profile = (string)($secret['profile'] ?? '');
      $commentDueDay = null;
      if (preg_match('/BILLING\\s*=\\s*(\\d{1,2})/i', $comment, $dueMatch)) $commentDueDay = min(31, max(1, (int)$dueMatch[1]));
      $status = (($secret['disabled'] ?? '') === 'true') ? 'disabled' : 'active';
      if (isset($index[$key])) {
        $i = $index[$key];
        $rows[$i]['router_session'] = (string)$routerSession;
        $rows[$i]['mikrotik_comment'] = $comment;
        if ($commentDueDay !== null) $rows[$i]['due_day'] = $commentDueDay;
        if ($profile !== '' && strtoupper($profile) !== 'ISOLIR') {
          $rows[$i]['profile'] = $profile;
          $rows[$i]['normal_profile'] = $profile;
        }
        $rows[$i]['status'] = $status;
        $rows[$i]['last_sync'] = $now;
      } else {
        $index[$key] = count($rows);
        $rows[] = array(
          'username' => $username,
          'name' => $comment !== '' ? $comment : $username,
          'phone' => '', 'email' => '', 'amount' => 0, 'due_day' => $commentDueDay === null ? 20 : $commentDueDay,
          'router_session' => (string)$routerSession,
          'profile' => $profile, 'normal_profile' => $profile,
          'status' => $status, 'mikrotik_comment' => $comment, 'last_sync' => $now
        );
      }
      $changed = true;
    }
    if ($changed && !customerWriteAll($rows)) throw new RuntimeException('Gagal menyimpan sinkronisasi pelanggan.');
    return array_values(array_filter($rows, function($row) use ($routerSession) {
      return (string)($row['router_session'] ?? '') === (string)$routerSession;
    }));
  }
  function customerUpdate($username, $values, $routerSession = null) {
    $rows = customerReadAll(); $updated = false;
    foreach ($rows as &$row) {
      if (($row['username'] ?? '') !== $username) continue;
      if ($routerSession !== null && (string)($row['router_session'] ?? '') !== (string)$routerSession) continue;
      $row = array_merge($row, $values); $updated = true; break;
    }
    unset($row);
    return $updated ? customerWriteAll($rows) : false;
  }
}
?>
