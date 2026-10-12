<?php
/**
 * One-way import of Mikhmon JSON billing/customer data into MariaDB.
 * Configure MIKHMON_DB_HOST, MIKHMON_DB_USER, MIKHMON_DB_PASSWORD and
 * MIKHMON_DB_NAME in the protected environment before running this CLI script.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('MIKHMON_DB_HOST') ?: '127.0.0.1';
$user = getenv('MIKHMON_DB_USER') ?: '';
$password = getenv('MIKHMON_DB_PASSWORD') ?: '';
$database = getenv('MIKHMON_DB_NAME') ?: 'mikhmon_db';
if ($user === '' || $password === '') {
  fwrite(STDERR, "Set MIKHMON_DB_USER and MIKHMON_DB_PASSWORD in a protected environment.\n");
  exit(2);
}
$db = new mysqli($host, $user, $password, $database);
$db->set_charset('utf8mb4');
$base = __DIR__ . '/../storage/';
$counts = array('customers' => 0, 'invoices' => 0);

$customerFile = $base . 'customers.json';
if (is_file($customerFile)) {
  $rows = json_decode(file_get_contents($customerFile), true);
  if (!is_array($rows)) throw new RuntimeException('customers.json bukan JSON array valid.');
  $sql = "INSERT INTO customers (router_session,username,name,phone,email,amount,due_day,profile,normal_profile,status,mikrotik_comment,last_sync)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone), email=VALUES(email),
          amount=VALUES(amount), due_day=VALUES(due_day), profile=VALUES(profile),
          normal_profile=VALUES(normal_profile), status=VALUES(status),
          mikrotik_comment=VALUES(mikrotik_comment), last_sync=VALUES(last_sync)";
  $stmt = $db->prepare($sql);
  foreach ($rows as $row) {
    if (!is_array($row) || trim((string)($row['username'] ?? '')) === '') continue;
    $routerSession = (string)($row['router_session'] ?? '');
    $username = (string)$row['username'];
    $name = (string)($row['name'] ?? '');
    $phone = (string)($row['phone'] ?? '');
    $email = (string)($row['email'] ?? '');
    $amount = max(0, (int)($row['amount'] ?? 0));
    $dueDay = min(31, max(1, (int)($row['due_day'] ?? 20)));
    $profile = (string)($row['profile'] ?? '');
    $normalProfile = (string)($row['normal_profile'] ?? '');
    $status = in_array(($row['status'] ?? 'active'), array('active','disabled'), true) ? $row['status'] : 'active';
    $comment = (string)($row['mikrotik_comment'] ?? '');
    $lastSync = (string)($row['last_sync'] ?? date('Y-m-d H:i:s'));
    $stmt->bind_param('sssssiisssss', $routerSession, $username, $name, $phone, $email, $amount, $dueDay, $profile, $normalProfile, $status, $comment, $lastSync);
    $stmt->execute();
    $counts['customers']++;
  }
  $stmt->close();
}

$invoiceFile = $base . 'invoices.json';
if (is_file($invoiceFile)) {
  $rows = json_decode(file_get_contents($invoiceFile), true);
  if (!is_array($rows)) throw new RuntimeException('invoices.json bukan JSON array valid.');
  $sql = "INSERT INTO invoices (invoice_no,period,router_session,customer_name,username,product,device,customer_phone,customer_email,amount,due_date,status,payment_method,tripay_reference,proof_url,created_at,paid_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE period=VALUES(period), router_session=VALUES(router_session),
          customer_name=VALUES(customer_name), username=VALUES(username), product=VALUES(product),
          device=VALUES(device), customer_phone=VALUES(customer_phone), customer_email=VALUES(customer_email),
          amount=VALUES(amount), due_date=VALUES(due_date), status=VALUES(status),
          payment_method=VALUES(payment_method), tripay_reference=VALUES(tripay_reference),
          proof_url=VALUES(proof_url), created_at=VALUES(created_at), paid_at=VALUES(paid_at)";
  $stmt = $db->prepare($sql);
  foreach ($rows as $row) {
    if (!is_array($row) || trim((string)($row['invoice_no'] ?? '')) === '') continue;
    $invoiceNo = (string)$row['invoice_no'];
    $period = (string)($row['period'] ?? date('Y-m'));
    $routerSession = (string)($row['router_session'] ?? '');
    $customerName = (string)($row['customer_name'] ?? '');
    $username = (string)($row['username'] ?? '');
    $product = (string)($row['product'] ?? '');
    $device = (string)($row['device'] ?? '');
    $phone = (string)($row['customer_phone'] ?? '');
    $email = (string)($row['customer_email'] ?? '');
    $amount = max(0, (int)($row['amount'] ?? 0));
    $dueDate = (string)($row['due_date'] ?? date('Y-m-d'));
    $status = in_array(($row['status'] ?? 'unpaid'), array('unpaid','paid','pending','failed','expired'), true) ? $row['status'] : 'unpaid';
    $paymentMethod = (string)($row['payment_method'] ?? '');
    $reference = (string)($row['tripay_reference'] ?? '');
    $proofUrl = (string)($row['proof_url'] ?? '');
    $createdAt = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
    $paidAt = (string)($row['paid_at'] ?? '');
    $stmt->bind_param('sssssssssisssssss', $invoiceNo, $period, $routerSession, $customerName, $username, $product, $device, $phone, $email, $amount, $dueDate, $status, $paymentMethod, $reference, $proofUrl, $createdAt, $paidAt);
    $stmt->execute();
    $counts['invoices']++;
  }
  $stmt->close();
}
$db->close();
echo "Migrasi selesai. Customers={$counts['customers']} invoices={$counts['invoices']}\n";
echo "Jalankan hanya setelah backup, lalu verifikasi jumlah baris pada database tujuan.\n";
?>
