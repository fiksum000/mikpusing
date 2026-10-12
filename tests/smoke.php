<?php
error_reporting(E_ALL);
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../include/billing.php';
require_once __DIR__ . '/../include/pppoe_usage.php';

function assertMikpusing($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}
function assertCloseMikpusing($actual, $expected, $epsilon = 0.000000001) {
    return abs((float)$actual - (float)$expected) <= $epsilon;
}

assertMikpusing(billingDueDate('2025-02', 31) === '2025-02-28', 'February non-leap due date clamps to last day');
assertMikpusing(billingDueDate('2024-02', 31) === '2024-02-29', 'leap-year due date clamps to February 29');
assertMikpusing(billingDueDate('2026-04', 31) === '2026-04-30', '30-day month due date clamps to the last day');
assertMikpusing(billingDueDate('2026-10', 20) === '2026-10-20', 'ordinary due date is unchanged');

$ids = array();
for ($i = 0; $i < 1000; $i++) {
    $id = billingInvoiceNo('subscriber-' . $i, '2026-10', 'RADIUS');
    $ids[$id] = true;
}
assertMikpusing(count($ids) === 1000, '1000 invoice identifiers are unique');
assertMikpusing(
    billingInvoiceNo('same-user', '2026-10', 'router-a') !== billingInvoiceNo('same-user', '2026-10', 'router-b'),
    'same username on separate routers gets separate invoice identifiers'
);
$invalidPeriodRejected = false;
try {
    billingInvoiceNo('subscriber', '2026-13', 'RADIUS');
} catch (InvalidArgumentException $e) {
    $invalidPeriodRejected = true;
}
assertMikpusing($invalidPeriodRejected, 'invalid invoice month is rejected');

$bytes = pppoeReadQueueCounters(array(
    array('name' => 'customer-1', 'bytes' => '100/200', 'target' => '10.10.10.2/32'),
    array('name' => 'another', 'bytes' => '500/500', 'target' => '10.10.10.3/32'),
), array('name' => 'customer-1', 'remote-address' => '10.10.10.2'));
assertMikpusing($bytes !== null && $bytes === 300.0, 'queue counter matches the exact username');
$byAddress = pppoeReadQueueCounters(array(
    array('name' => 'dynamic-queue', 'bytes' => '1024/2048', 'target' => '10.10.10.2/32'),
), array('name' => 'customer-2', 'remote-address' => '10.10.10.2'));
assertMikpusing($byAddress !== null && $byAddress === 3072.0, 'queue counter can match a static remote address');
$noQueue = pppoeReadQueueCounters(array(
    array('name' => 'other', 'bytes' => '50/50', 'target' => '10.10.10.3/32'),
), array('name' => 'customer-3', 'remote-address' => '10.10.10.2'));
assertMikpusing($noQueue === null, 'missing queue does not return a false zero-usage value');

echo "OK: Mikhmon smoke tests passed (date boundaries, 1000 invoice IDs, queue counters).\n";
?>
