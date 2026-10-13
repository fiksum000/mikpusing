<?php
error_reporting(E_ALL);
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../include/billing.php';
require_once __DIR__ . '/../include/pppoe_usage.php';
require_once __DIR__ . '/../include/pppoe_packages.php';
require_once __DIR__ . '/../include/customers.php';
require_once __DIR__ . '/../lib/free_radius_client.php';
require_once __DIR__ . '/../lib/decrypt_helper.php';
require_once __DIR__ . '/../include/radius_navigation.php';

function assertMikpusing($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}
function assertCloseMikpusing($actual, $expected, $epsilon = 0.000000001) {
    return abs((float)$actual - (float)$expected) <= $epsilon;
}

assertMikpusing(encrypt('Mikhmon-legacy-123!') === 'hZqdoJ6hpl6enZiTm6pfaWNlWQ==', 'config encoding remains compatible with legacy Mikhmon format');
assertMikpusing(decrypt('hZqdoJ6hpl6enZiTm6pfaWNlWQ==') === 'Mikhmon-legacy-123!', 'legacy Mikhmon config password can be decrypted without loading RouterOS API');

assertMikpusing(billingDueDate('2025-02', 31) === '2025-02-28', 'February non-leap due date clamps to last day');
assertMikpusing(billingDueDate('2024-02', 31) === '2024-02-29', 'leap-year due date clamps to February 29');
assertMikpusing(billingDueDate('2026-04', 31) === '2026-04-30', '30-day month due date clamps to the last day');
assertMikpusing(billingDueDate('2026-10', 20) === '2026-10-20', 'ordinary due date is unchanged');

assertMikpusing(array_keys(radiusNavigation('sessions')) === array('sessions', 'radius-nas'), 'outside navigation contains only Admin Settings and Add Router');
assertMikpusing(isset(radiusNavigation('radius-dashboard')['billing'], radiusNavigation('radius-dashboard')['radius-users'], radiusNavigation('radius-dashboard')['tripay-settings']), 'operational modules are inside dashboard');
$radiusConfigForTest = mikhmonRadiusConfig();
assertMikpusing(($radiusConfigForTest['ppp_auth_backend'] ?? '') === 'radius', 'Full RADIUS backend is enforced even if legacy settings exist');
assertMikpusing(($radiusConfigForTest['disconnect_mode'] ?? '') === 'radius-dm', 'RADIUS Disconnect-Message is enforced as session disconnect mode');

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
assertMikpusing(!billingInvoiceIsPastGracePeriod(array('status' => 'unpaid', 'amount' => 25000, 'due_date' => '2026-10-20'), strtotime('2026-10-24 23:59:59')), 'unpaid invoice remains active before grace period ends');
assertMikpusing(billingInvoiceIsPastGracePeriod(array('status' => 'unpaid', 'amount' => 25000, 'due_date' => '2026-10-20'), strtotime('2026-10-25 00:00:00')), 'unpaid invoice is overdue exactly at due date plus five days');
assertMikpusing(!billingInvoiceIsPastGracePeriod(array('status' => 'paid', 'amount' => 25000, 'due_date' => '2026-10-01'), strtotime('2026-10-20 00:00:00')), 'paid invoice never triggers isolation');
assertMikpusing(!billingInvoiceIsPastGracePeriod(array('status' => 'unpaid', 'amount' => 0, 'due_date' => '2026-10-01'), strtotime('2026-10-20 00:00:00')), 'zero amount invoice never triggers isolation');
assertMikpusing(billingCustomerHasPastDueInvoice(array(
    array('status' => 'paid', 'amount' => 25000, 'due_date' => '2026-09-01'),
    array('status' => 'unpaid', 'amount' => 25000, 'due_date' => '2026-10-20')
), strtotime('2026-10-24 12:00:00')) === false, 'paid history plus current invoice within grace is not isolated');
assertMikpusing(billingCustomerHasPastDueInvoice(array(
    array('status' => 'unpaid', 'amount' => 25000, 'due_date' => '2026-09-01'),
    array('status' => 'paid', 'amount' => 25000, 'due_date' => '2026-10-01')
), strtotime('2026-10-20 12:00:00')) === true, 'old unpaid overdue invoice still triggers isolation even if current invoice was paid');


/* Use isolated temporary storage so invoice tests never touch production invoices. */
$originalBillingFileEnv = getenv('MIKHMON_BILLING_FILE');
$invoiceTestDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mikpusing-smoke-' . getmypid() . '-' . bin2hex(random_bytes(4));
assertMikpusing(@mkdir($invoiceTestDir, 0700, true), 'temporary invoice test directory is created');
$invoiceTestPath = $invoiceTestDir . DIRECTORY_SEPARATOR . 'invoices.json';
putenv('MIKHMON_BILLING_FILE=' . $invoiceTestPath);
try {
    $testCustomer = array(array(
        'username' => 'radius-test-user',
        'router_session' => 'RADIUS',
        'name' => 'RADIUS Test User',
        'amount' => 25000,
        'due_day' => 31,
        'profile' => 'PPPOE-TEST',
        'phone' => '628000000000'
    ));
    $firstCreated = billingCreateForCustomers($testCustomer, '2026-10', 0);
    $secondCreated = billingCreateForCustomers($testCustomer, '2026-10', 0);
    assertMikpusing($firstCreated === 1, 'first invoice generation creates one invoice');
    assertMikpusing($secondCreated === 0, 'repeat invoice generation is idempotent');
    $testInvoiceNo = billingInvoiceNo('radius-test-user', '2026-10', 'RADIUS');
    $testInvoice = billingFindInvoice($testInvoiceNo);
    assertMikpusing(is_array($testInvoice) && (int)$testInvoice['amount'] === 25000, 'created invoice stores subscriber amount');
    assertMikpusing($testInvoice['due_date'] === '2026-10-31', 'invoice due day is valid for month length');
    assertMikpusing(billingUpdateInvoice($testInvoiceNo, array(
        'status' => 'paid',
        'tripay_reference' => 'TRIPAY-TEST-REFERENCE',
        'payment_notification_sent_at' => 'payment-sent'
    )), 'invoice update is persisted');
    assertMikpusing(billingUpdateInvoice($testInvoiceNo, array(
        'invoice_notification_sent_at' => 'invoice-sent'
    )), 'independent invoice-notification marker is persisted');
    $updatedInvoice = billingFindInvoice($testInvoiceNo);
    assertMikpusing(($updatedInvoice['status'] ?? '') === 'paid', 'invoice status survives subsequent partial update');
    assertMikpusing(($updatedInvoice['tripay_reference'] ?? '') === 'TRIPAY-TEST-REFERENCE', 'TriPay reference survives subsequent partial update');
    assertMikpusing(($updatedInvoice['payment_notification_sent_at'] ?? '') === 'payment-sent', 'payment-receipt marker is preserved');
    assertMikpusing(($updatedInvoice['invoice_notification_sent_at'] ?? '') === 'invoice-sent', 'invoice-message marker is independent');
    file_put_contents($invoiceTestPath, '{', LOCK_EX);
    $corruptInvoiceRejected = false;
    try {
        billingUpdateInvoice($testInvoiceNo, array('status' => 'unpaid'));
    } catch (RuntimeException $e) {
        $corruptInvoiceRejected = true;
    }
    assertMikpusing($corruptInvoiceRejected, 'corrupt invoice JSON aborts update safely');
    assertMikpusing(file_get_contents($invoiceTestPath) === '{', 'corrupt invoice JSON is not silently overwritten');
} finally {
    putenv($originalBillingFileEnv === false ? 'MIKHMON_BILLING_FILE' : 'MIKHMON_BILLING_FILE=' . $originalBillingFileEnv);
    foreach (glob($invoiceTestPath . '*') ?: array() as $testFile) @unlink($testFile);
    @rmdir($invoiceTestDir);
}

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


$packagePath = pppoePackagePath();
$packageExisted = is_file($packagePath);
$packageBackup = $packageExisted ? file_get_contents($packagePath) : null;
try {
    pppoePackageWrite(array(
        'isolir_profile' => 'ISOLIR',
        'packages' => array(array('name' => 'Test 10M', 'normal_profile' => 'PPPOE-10M', 'fup_profile' => 'PPPOE-10M-FUP', 'quota_gb' => 300))
    ));
    $normalPackage = pppoePackageForProfile('PPPOE-10M');
    $fupPackage = pppoePackageForProfile('PPPOE-10M-FUP');
    assertMikpusing(is_array($normalPackage) && $normalPackage['normal_profile'] === 'PPPOE-10M', 'normal profile resolves to package mapping');
    assertMikpusing(is_array($fupPackage) && $fupPackage['normal_profile'] === 'PPPOE-10M', 'FUP profile resolves to its normal package for restoration');

    $originalCustomersEnv = getenv('MIKHMON_CUSTOMERS_FILE');
    $customerTestDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mikpusing-customer-smoke-' . getmypid() . '-' . bin2hex(random_bytes(4));
    assertMikpusing(@mkdir($customerTestDir, 0700, true), 'temporary customer test directory is created');
    $customerTestPath = $customerTestDir . DIRECTORY_SEPARATOR . 'customers.json';
    putenv('MIKHMON_CUSTOMERS_FILE=' . $customerTestPath);
    try {
        customerSyncSecrets(array(array('name' => 'radius-fup-test', 'profile' => 'PPPOE-10M')), 'RADIUS');
        assertMikpusing(customerUpdate('radius-fup-test', array('amount' => 35000, 'phone' => '628123456789'), 'RADIUS'), 'customer price and phone can be updated');
        customerSyncSecrets(array(array('name' => 'radius-fup-test', 'profile' => 'PPPOE-10M-FUP')), 'RADIUS');
        $syncedCustomer = customerFind('radius-fup-test', 'RADIUS');
        assertMikpusing(($syncedCustomer['normal_profile'] ?? '') === 'PPPOE-10M', 'RADIUS sync preserves normal profile while subscriber is on FUP');
        assertMikpusing((int)($syncedCustomer['amount'] ?? 0) === 35000, 'RADIUS sync preserves customer price');
        assertMikpusing(($syncedCustomer['phone'] ?? '') === '628123456789', 'RADIUS sync preserves customer WhatsApp number');
        assertMikpusing(($syncedCustomer['profile'] ?? '') === 'PPPOE-10M-FUP', 'RADIUS sync still records current FUP group');
    } finally {
        putenv($originalCustomersEnv === false ? 'MIKHMON_CUSTOMERS_FILE' : 'MIKHMON_CUSTOMERS_FILE=' . $originalCustomersEnv);
        foreach (glob($customerTestPath . '*') ?: array() as $customerTestFile) @unlink($customerTestFile);
        @rmdir($customerTestDir);
    }
} finally {
    if ($packageExisted) file_put_contents($packagePath, $packageBackup, LOCK_EX);
    elseif (is_file($packagePath)) unlink($packagePath);
}

echo "OK: Mikhmon smoke tests passed (date boundaries, invoice IDs, queue counters, package mapping, enforced Full RADIUS config).\\n";
?>
