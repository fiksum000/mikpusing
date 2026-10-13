<?php
if (!function_exists('billingPath')) {
  function billingPath() {
    $override = getenv('MIKHMON_BILLING_FILE');
    return ($override !== false && trim($override) !== '') ? $override : (__DIR__ . '/../storage/invoices.json');
  }
  function billingEnsureStorage() {
    $path = billingPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
      throw new RuntimeException('Direktori storage invoice tidak bisa dibuat.');
    }
    if (!file_exists($path)) {
      // Exclusive create avoids clobbering an invoice file another process
      // created between file_exists() and initialization.
      $handle = @fopen($path, 'x');
      if (is_resource($handle)) {
        $written = fwrite($handle, '[]');
        fclose($handle);
        if ($written === false) throw new RuntimeException('File invoice tidak bisa diinisialisasi.');
      } elseif (!file_exists($path)) {
        throw new RuntimeException('File invoice tidak bisa dibuat.');
      }
    }
  }
  function billingReadInvoices() {
    billingEnsureStorage();
    $raw = @file_get_contents(billingPath());
    if ($raw === false) throw new RuntimeException('File invoice tidak bisa dibaca.');
    $rows = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($rows)) {
      throw new RuntimeException('storage/invoices.json rusak atau bukan array JSON; penulisan dihentikan agar data tagihan tidak tertimpa.');
    }
    return $rows;
  }

  /** Write atomically while the caller holds the invoice lock. */
  function billingAtomicWriteUnlocked($rows) {
    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $path = billingPath();
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

  /**
   * Serialize every invoice read-modify-write operation across cron, callbacks
   * and the web UI. Atomic rename prevents readers from observing partial JSON.
   */
  function billingWithInvoicesLock($mutator) {
    billingEnsureStorage();
    $lockPath = billingPath() . '.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
      if (is_resource($lock)) fclose($lock);
      throw new RuntimeException('Tidak bisa mengunci storage invoice.');
    }
    try {
      $raw = @file_get_contents(billingPath());
      if ($raw === false) throw new RuntimeException('File invoice tidak bisa dibaca saat terkunci.');
      $rows = json_decode($raw, true);
      if (json_last_error() !== JSON_ERROR_NONE || !is_array($rows)) {
        throw new RuntimeException('storage/invoices.json rusak; perubahan invoice dibatalkan untuk melindungi data.');
      }
      $outcome = $mutator($rows);
      if (is_array($outcome) && !empty($outcome['__billing_write'])) {
        if (!billingAtomicWriteUnlocked($rows)) throw new RuntimeException('Gagal menyimpan file invoice secara atomik.');
        return $outcome['value'] ?? true;
      }
      return is_array($outcome) && array_key_exists('value', $outcome) ? $outcome['value'] : $outcome;
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }
  }

  function billingWriteInvoices($rows) {
    try {
      return billingWithInvoicesLock(function (&$current) use ($rows) {
        $current = array_values($rows);
        return array('__billing_write' => true, 'value' => true);
      });
    } catch (Throwable $e) {
      error_log('Mikhmon billingWriteInvoices: ' . $e->getMessage());
      return false;
    }
  }
  /** Acquire a non-blocking per-invoice lock across checkout creation, callbacks, and notifications. */
  function billingAcquireInvoiceLock($invoiceNo, $wait = false) {
    $invoiceNo = trim((string)$invoiceNo);
    if ($invoiceNo === '') return false;
    $dir = dirname(billingPath());
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
    $path = $dir . '/tripay-create-' . hash('sha256', $invoiceNo) . '.lock';
    $handle = @fopen($path, 'c');
    if (!$handle) return false;
    if (!flock($handle, $wait ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
      fclose($handle);
      return false;
    }
    return $handle;
  }
  function billingReleaseInvoiceLock($handle) {
    if (is_resource($handle)) {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }
  function billingValidPeriod($period) {
    if (!preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', (string)$period)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    return $date && $date->format('Y-m') === $period;
  }
  /**
   * True only for a positive-value, unpaid invoice whose due date plus grace
   * period has elapsed. Invalid due dates never trigger isolation.
   */
  function billingInvoiceIsPastGracePeriod($invoice, $now = null, $graceDays = 5) {
    if (!is_array($invoice)) return false;
    if (strtolower(trim((string)($invoice['status'] ?? 'unpaid'))) === 'paid') return false;
    if ((int)($invoice['amount'] ?? 0) <= 0) return false;
    $dueDate = trim((string)($invoice['due_date'] ?? ''));
    if ($dueDate === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dueDate)) return false;
    $dueTimestamp = strtotime($dueDate . ' 00:00:00');
    if ($dueTimestamp === false || date('Y-m-d', $dueTimestamp) !== $dueDate) return false;
    $graceDays = max(0, (int)$graceDays);
    $isolationTimestamp = strtotime('+' . $graceDays . ' days', $dueTimestamp);
    if ($isolationTimestamp === false) return false;
    return ($now === null ? time() : (int)$now) >= $isolationTimestamp;
  }
  function billingCustomerHasPastDueInvoice($invoices, $now = null, $graceDays = 5) {
    foreach ((array)$invoices as $invoice) {
      if (billingInvoiceIsPastGracePeriod($invoice, $now, $graceDays)) return true;
    }
    return false;
  }

  /** Stable invoice identifier; router session separates duplicate usernames. */
  function billingInvoiceNo($customer, $period, $routerSession = '') {
    if (!billingValidPeriod($period)) throw new InvalidArgumentException('Periode invoice harus berformat YYYY-MM.');
    $identity = (string)$routerSession . '|' . (string)$customer;
    $hash = strtoupper(substr(hash('sha256', $identity), 0, 12));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    return 'INV-' . $date->format('Ym') . '-' . $hash;
  }
  function billingDueDate($period, $dueDay) {
    if (!billingValidPeriod($period)) throw new InvalidArgumentException('Periode invoice harus berformat YYYY-MM.');
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    $day = min((int)$monthStart->format('t'), max(1, (int)$dueDay));
    return $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('m'), $day)->format('Y-m-d');
  }
  function billingLegacyInvoiceNo($customer, $period) {
    if (!billingValidPeriod($period)) return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    $legacy = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$customer), 0, 12));
    return 'INV-' . $date->format('Ym') . '-' . $legacy;
  }
  function billingCreateForCustomers($customers, $period, $defaultAmount = 0) {
    if (!billingValidPeriod($period)) throw new InvalidArgumentException('Periode invoice harus berformat YYYY-MM.');
    return billingWithInvoicesLock(function (&$invoices) use ($customers, $period, $defaultAmount) {
      $created = 0;
      $existing = array();
      foreach ($invoices as $invoice) if (!empty($invoice['invoice_no'])) $existing[$invoice['invoice_no']] = $invoice;
      $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
      $daysInMonth = (int)$monthStart->format('t');
      foreach ($customers as $customer) {
        $name = trim((string)($customer['name'] ?? ''));
        $username = trim((string)($customer['username'] ?? ''));
        $routerSession = trim((string)($customer['router_session'] ?? ''));
        if ($username === '') continue;
        $invoiceNo = billingInvoiceNo($username, $period, $routerSession);
        $legacyInvoiceNo = billingLegacyInvoiceNo($username, $period);
        if (isset($existing[$invoiceNo])) continue;
        if ($legacyInvoiceNo !== '' && isset($existing[$legacyInvoiceNo])) {
          $legacyInvoice = $existing[$legacyInvoiceNo];
          if ((string)($legacyInvoice['username'] ?? '') === $username
              && (string)($legacyInvoice['router_session'] ?? '') === $routerSession) continue;
        }
        $amount = isset($customer['amount']) && (int)$customer['amount'] > 0 ? (int)$customer['amount'] : (int)$defaultAmount;
        if ($amount <= 0) continue;
        $dueDay = min($daysInMonth, max(1, (int)($customer['due_day'] ?? 20)));
        $invoices[] = array(
          'invoice_no' => $invoiceNo, 'period' => $period, 'router_session' => $routerSession,
          'customer_name' => $name !== '' ? $name : $username, 'username' => $username,
          'product' => !empty($customer['profile']) ? (string)$customer['profile'] : 'Paket Internet',
          'device' => !empty($customer['device']) ? (string)$customer['device'] : $username,
          'customer_phone' => (string)($customer['phone'] ?? ''),
          'customer_email' => (string)($customer['email'] ?? ''),
          'amount' => $amount, 'due_date' => billingDueDate($period, $dueDay), 'status' => 'unpaid',
          'payment_method' => '', 'tripay_reference' => '', 'payment_url' => '',
          'proof_url' => '', 'created_at' => date('Y-m-d H:i:s'), 'paid_at' => '',
          'invoice_notification_sent_at' => '', 'payment_notification_sent_at' => ''
        );
        $existing[$invoiceNo] = true;
        $created++;
      }
      return array('__billing_write' => $created > 0, 'value' => $created);
    });
  }
  function billingFindInvoice($invoiceNo) {
    foreach (billingReadInvoices() as $invoice) {
      if (isset($invoice['invoice_no']) && $invoice['invoice_no'] === $invoiceNo) return $invoice;
    }
    return null;
  }
  function billingUpdateInvoice($invoiceNo, $values) {
    return billingWithInvoicesLock(function (&$rows) use ($invoiceNo, $values) {
      foreach ($rows as &$row) {
        if (isset($row['invoice_no']) && $row['invoice_no'] === $invoiceNo) {
          $row = array_merge($row, $values);
          unset($row);
          return array('__billing_write' => true, 'value' => true);
        }
      }
      unset($row);
      return false;
    });
  }
}
?>