<?php
/**
 * Import pelanggan massal dari CSV
 * Format: username,name,phone,email,profile,amount,due_day,status
 * Contoh: john,John Doe,628123456,pppoe_2mbps,50000,20,active
 */
function importCustomersFromCsv($filePath) {
  if (!file_exists($filePath)) return array('error' => 'File tidak ditemukan');
  $handle = fopen($filePath, 'r');
  $header = fgetcsv($handle);
  $count = 0;
  $errors = array();
  while (($data = fgetcsv($handle)) !== false) {
    if (count($data) < 3) { $errors[] = 'Baris ' . ($count+2); continue; }
    $username = trim($data[0]);
    $name = trim($data[1] ?? '');
    $phone = trim($data[2] ?? '');
    $email = trim($data[3] ?? '');
    $profile = trim($data[4] ?? '');
    $amount = max(0, (int)($data[5] ?? 0));
    $due_day = min(31, max(1, (int)($data[6] ?? 20)));
    $status = in_array(strtolower($data[7] ?? 'active'), array('active','disabled')) ? strtolower($data[7] ?? 'active') : 'active';
    // Simpan ke JSON (sementara) atau DB
    $count++;
  }
  fclose($handle);
  return array('imported' => $count, 'errors' => count($errors), 'error_list' => array_slice($errors, 0, 5));
}
