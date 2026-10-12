<?php
/**
 * Migrasi data dari storage/*.json ke MariaDB (mikhmon_db)
 * Jalankan setelah setup MariaDB dan import schema
 */
$mysqli = new mysqli('localhost', 'mikhmon', 'mikhmon_pass', 'mikhmon_db');
if ($mysqli->connect_error) die('DB connect error: ' . $mysqli->connect_error);

// Migrasi customers.json
if (file_exists(__DIR__ . '/../storage/customers.json')) {
  $rows = json_decode(file_get_contents(__DIR__ . '/../storage/customers.json'), true);
  $stmt = $mysqli->prepare("INSERT IGNORE INTO customers (username,name,phone,email,amount,due_day,router_session,profile,normal_profile,status,mikrotik_comment,last_sync) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
  foreach ($rows as $r) {
    $stmt->bind_param("sssiisssssss",
      $r['username'], $r['name'] ?? '', $r['phone'] ?? '', $r['email'] ?? '',
      $r['amount'] ?? 0, $r['due_day'] ?? 20, $r['router_session'] ?? '',
      $r['profile'] ?? '', $r['normal_profile'] ?? '', $r['status'] ?? 'active',
      $r['mikrotik_comment'] ?? '', $r['last_sync'] ?? date('Y-m-d H:i:s')
    );
    $stmt->execute();
  }
  echo "Migrasi customers: " . count($rows) . " baris\n";
}
// Migrasi invoices.json
if (file_exists(__DIR__ . '/../storage/invoices.json')) {
  $rows = json_decode(file_get_contents(__DIR__ . '/../storage/invoices.json'), true);
  $stmt = $mysqli->prepare("INSERT IGNORE INTO invoices (invoice_no,period,customer_name,username,product,device,customer_phone,customer_email,amount,due_date,status,payment_method,tripay_reference,proof_url,created_at,paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  foreach ($rows as $r) {
    $stmt->bind_param("ssssssssiisssssss",
      $r['invoice_no'], $r['period'] ?? '', $r['customer_name'] ?? '', $r['username'] ?? '',
      $r['product'] ?? '', $r['device'] ?? '', $r['customer_phone'] ?? '', $r['customer_email'] ?? '',
      $r['amount'] ?? 0, $r['due_date'] ?? '', $r['status'] ?? 'unpaid', $r['payment_method'] ?? '',
      $r['tripay_reference'] ?? '', $r['proof_url'] ?? '', $r['created_at'] ?? '', $r['paid_at'] ?? ''
    );
    $stmt->execute();
  }
  echo "Migrasi invoices: " . count($rows) . " baris\n";
}
echo "Migrasi selesai. Verifikasi dengan SELECT COUNT(*) FROM customers;\n";
