<?php
if (!function_exists('customerPath')) {
  function customerPath() {
    $override = getenv('MIKHMON_CUSTOMERS_FILE');
    return ($override !== false && trim($override) !== '') ? $override : (__DIR__ . '/../storage/customers.json');
  }

  function customerEnsureStorage() {
    $path = customerPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
      throw new RuntimeException('Direktori storage pelanggan tidak bisa dibuat.');
    }
    if (!file_exists($path)) {
      $handle = @fopen($path, 'x');
      if (is_resource($handle)) {
        $written = fwrite($handle, '[]');
        fclose($handle);
        if ($written === false) throw new RuntimeException('File pelanggan tidak bisa diinisialisasi.');
      } elseif (!file_exists($path)) {
        throw new RuntimeException('File pelanggan tidak bisa dibuat.');
      }
    }
  }

  function customerReadAll() {
    customerEnsureStorage();
    $raw = @file_get_contents(customerPath());
    if ($raw === false) throw new RuntimeException('File pelanggan tidak bisa dibaca.');
    $rows = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($rows)) {
      throw new RuntimeException('storage/customers.json rusak atau bukan array JSON; perubahan dihentikan agar data pelanggan tidak tertimpa.');
    }
    return $rows;
  }

  /** Write an already-locked snapshot to a temp file, then atomically replace it. */
  function customerAtomicWriteUnlocked($rows) {
    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $path = customerPath();
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
      @unlink($tmp);
      return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
      @unlink($tmp);
      return false;
    }
    return true;
  }

  /** Serialize customer read-modify-write operations across cron and the web UI. */
  function customerWithLock($mutator) {
    customerEnsureStorage();
    $lock = @fopen(customerPath() . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
      if (is_resource($lock)) fclose($lock);
      throw new RuntimeException('Tidak bisa mengunci storage pelanggan.');
    }
    try {
      $raw = @file_get_contents(customerPath());
      if ($raw === false) throw new RuntimeException('File pelanggan tidak bisa dibaca saat terkunci.');
      $rows = json_decode($raw, true);
      if (json_last_error() !== JSON_ERROR_NONE || !is_array($rows)) {
        throw new RuntimeException('storage/customers.json rusak; perubahan pelanggan dibatalkan.');
      }
      $outcome = $mutator($rows);
      if (is_array($outcome) && !empty($outcome['__customer_write'])) {
        if (!customerAtomicWriteUnlocked($rows)) throw new RuntimeException('Gagal menyimpan data pelanggan secara atomik.');
        return $outcome['value'] ?? true;
      }
      return is_array($outcome) && array_key_exists('value', $outcome) ? $outcome['value'] : $outcome;
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }
  }

  function customerWriteAll($rows) {
    try {
      return customerWithLock(function (&$current) use ($rows) {
        $current = array_values($rows);
        return array('__customer_write' => true, 'value' => true);
      });
    } catch (Throwable $e) {
      error_log('Mikhmon customerWriteAll: ' . $e->getMessage());
      return false;
    }
  }

  /**
   * Keep the intended normal group when the current RADIUS group is a mapped
   * FUP group. This lets a paid subscriber return to the right package later.
   */
  function customerNormalProfileForSync($profile) {
    $profile = trim((string)$profile);
    if ($profile === '' || strtoupper($profile) === 'ISOLIR') return $profile;
    if (!function_exists('pppoePackageForProfile')) require_once __DIR__ . '/pppoe_packages.php';
    if (function_exists('pppoePackageForProfile')) {
      $package = pppoePackageForProfile($profile);
      if (is_array($package)
          && trim((string)($package['fup_profile'] ?? '')) === $profile
          && trim((string)($package['normal_profile'] ?? '')) !== '') {
        return trim((string)$package['normal_profile']);
      }
    }
    return $profile;
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
    return customerWithLock(function (&$rows) use ($secret, $routerSession) {
      $found = false;
      $username = (string)$secret['name'];
      $profile = trim((string)($secret['profile'] ?? ''));
      foreach ($rows as &$row) {
        if (($row['username'] ?? '') !== $username
            || (string)($row['router_session'] ?? '') !== (string)$routerSession) continue;
        $row['router_session'] = (string)$routerSession;
        $row['mikrotik_comment'] = (string)($secret['comment'] ?? '');
        if ($profile !== '' && strtoupper($profile) !== 'ISOLIR') {
          $row['profile'] = $profile;
          $row['normal_profile'] = customerNormalProfileForSync($profile);
        }
        $row['status'] = (($secret['disabled'] ?? '') === 'true') ? 'disabled' : 'active';
        $row['last_sync'] = date('Y-m-d H:i:s');
        $found = true;
        break;
      }
      unset($row);
      if (!$found) {
        $normal = $profile !== '' && strtoupper($profile) !== 'ISOLIR' ? customerNormalProfileForSync($profile) : '';
        $rows[] = array(
          'username' => $username,
          'name' => !empty($secret['comment']) ? (string)$secret['comment'] : $username,
          'phone' => '', 'email' => '', 'amount' => 0, 'due_day' => 20,
          'router_session' => (string)$routerSession,
          'profile' => $profile, 'normal_profile' => $normal,
          'status' => (($secret['disabled'] ?? '') === 'true') ? 'disabled' : 'active',
          'mikrotik_comment' => (string)($secret['comment'] ?? ''),
          'last_sync' => date('Y-m-d H:i:s')
        );
      }
      return array('__customer_write' => true, 'value' => true);
    });
  }

  function customerSyncSecrets($secrets, $routerSession = '') {
    return customerWithLock(function (&$rows) use ($secrets, $routerSession) {
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
        $profile = trim((string)($secret['profile'] ?? ''));
        $normalProfile = $profile !== '' && strtoupper($profile) !== 'ISOLIR'
          ? customerNormalProfileForSync($profile) : '';
        $commentDueDay = null;
        if (preg_match('/BILLING\\s*=\\s*(\\d{1,2})/i', $comment, $dueMatch)) {
          $commentDueDay = min(31, max(1, (int)$dueMatch[1]));
        }
        $status = (($secret['disabled'] ?? '') === 'true') ? 'disabled' : 'active';
        if (isset($index[$key])) {
          $i = $index[$key];
          $rows[$i]['router_session'] = (string)$routerSession;
          $rows[$i]['mikrotik_comment'] = $comment;
          if ($commentDueDay !== null) $rows[$i]['due_day'] = $commentDueDay;
          if ($profile !== '' && strtoupper($profile) !== 'ISOLIR') {
            $rows[$i]['profile'] = $profile;
            $rows[$i]['normal_profile'] = $normalProfile;
          }
          $rows[$i]['status'] = $status;
          $rows[$i]['last_sync'] = $now;
        } else {
          $index[$key] = count($rows);
          $rows[] = array(
            'username' => $username,
            'name' => $comment !== '' ? $comment : $username,
            'phone' => '', 'email' => '', 'amount' => 0,
            'due_day' => $commentDueDay === null ? 20 : $commentDueDay,
            'router_session' => (string)$routerSession,
            'profile' => $profile, 'normal_profile' => $normalProfile,
            'status' => $status, 'mikrotik_comment' => $comment, 'last_sync' => $now
          );
        }
        $changed = true;
      }
      $filtered = array_values(array_filter($rows, function($row) use ($routerSession) {
        return (string)($row['router_session'] ?? '') === (string)$routerSession;
      }));
      return array('__customer_write' => $changed, 'value' => $filtered);
    });
  }

  function customerUpdate($username, $values, $routerSession = null) {
    return customerWithLock(function (&$rows) use ($username, $values, $routerSession) {
      $updated = false;
      foreach ($rows as &$row) {
        if (($row['username'] ?? '') !== $username) continue;
        if ($routerSession !== null && (string)($row['router_session'] ?? '') !== (string)$routerSession) continue;
        $row = array_merge($row, $values);
        $updated = true;
        break;
      }
      unset($row);
      return $updated
        ? array('__customer_write' => true, 'value' => true)
        : false;
    });
  }
}
?>