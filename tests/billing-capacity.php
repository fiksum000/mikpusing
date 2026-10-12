<?php
require_once __DIR__ . '/../include/billing.php';
if ($argc < 2) exit(1);
putenv('MIKHMON_BILLING_FILE=' . $argv[1]);
$customers = array();
for ($i=0; $i<1000; $i++) $customers[] = array('username'=>'capacity-'.$i, 'router_session'=>'RADIUS', 'amount'=>100000, 'due_day'=>31);
$started = microtime(true);
$created = billingCreateForCustomers($customers, '2026-02');
$rows = billingReadInvoices();
if (count($rows) !== 1000 || count(array_unique(array_column($rows, 'invoice_no'))) !== 1000) throw new RuntimeException('Duplicate/missing invoices');
foreach ($rows as $row) if ($row['due_date'] !== '2026-02-28' || $row['amount'] !== 100000) throw new RuntimeException('Invalid invoice');
if (billingCreateForCustomers($customers, '2026-02') !== 0) throw new RuntimeException('Not idempotent');
echo json_encode(array('created'=>$created, 'rows'=>count($rows), 'seconds'=>microtime(true)-$started, 'peak_mb'=>memory_get_peak_usage(true)/1048576)) . PHP_EOL;
