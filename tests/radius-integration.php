<?php
// Run only against a disposable CI database, never a customer's CT.
if (getenv('MIKPUSING_TEST_DATABASE') !== '1') exit("Set MIKPUSING_TEST_DATABASE=1 on a disposable database.\n");
require_once __DIR__ . '/../lib/free_radius_client.php';
require_once __DIR__ . '/../lib/radius_disconnect_client.php';
require_once __DIR__ . '/../lib/bulk_import.php';
function verifyRadius($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$tmp = sys_get_temp_dir() . '/radius-accounting-test-' . getmypid() . '.json';
putenv('MIKHMON_ACCOUNTING_FILE=' . $tmp);
putenv('MIKHMON_CUSTOMERS_FILE=' . $tmp . '.customers');
putenv('MIKHMON_RADIUS_PENDING_FILE=' . $tmp . '.pending');
$radius = new RadiusClient();
$username = 'ci-radius-user';
try {
    $radius->upsertUser($username, 'ci-test-password', 'PPPOE-10M');
    $radius->setProfile($username, 'PPPOE-10M-FUP');
    $rejected = false;
    try { $radius->upsertUser($username, 'must-not-persist', 'NO-SUCH-GROUP'); } catch (RuntimeException $e) { $rejected = true; }
    verifyRadius($rejected, 'invalid group rejected');
    verifyRadius($radius->getPppSecret($username)[0]['value'] === 'ci-test-password', 'failed update preserves password');
    $radius->db->query("INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime, acctupdatetime, acctinputoctets, acctoutputoctets) VALUES ('ci-session', 'ci-unique', 'ci-radius-user', '192.0.2.2', NOW(), NOW(), 4294967400, 200)");
    $fake = $tmp . '.radclient';
    file_put_contents($fake, "#!/bin/sh\ncat > /dev/null\necho Disconnect-ACK\n"); chmod($fake, 0700);
    $dm = new MikhmonRadiusDisconnectClient($radius, array_merge(mikhmonRadiusConfig(), array('radclient_path'=>$fake)));
    verifyRadius($dm->disconnectSubscribers(array($username))[$username]['sessions'] === 1, 'DM ACK accepted for matching active NAS session (mock transport)');
    file_put_contents($fake, "#!/bin/sh\ncat > /dev/null\necho Disconnect-NAK\nexit 1\n");
    verifyRadius($dm->disconnectSubscribers(array($username))[$username]['errors'] === 1, 'DM NAK remains failure (mock transport)');
    verifyRadius($dm->disconnectSubscribers(array('no-such-user'))['no-such-user']['no_active_sessions'], 'no accounting session is reported explicitly');
    $radius->sampleAccountingCounters();
    verifyRadius($radius->getUsageBytes($username) === 4294967600.0, '64-bit counters sampled without gigaword columns');
    $radius->sampleAccountingCounters();
    verifyRadius($radius->getUsageBytes($username) === 4294967600.0, 'repeated sampling does not double count');
    $radius->db->query("UPDATE radacct SET acctinputoctets=acctinputoctets+100 WHERE acctuniqueid='ci-unique'");
    $radius->sampleAccountingCounters();
    verifyRadius($radius->getUsageBytes($username) === 4294967700.0, 'interim delta added once');
    $radius->db->query("INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime, acctstoptime, acctinputoctets, acctoutputoctets) VALUES ('ci-old', 'ci-old-unique', 'ci-radius-user', '192.0.2.2', '2020-01-01', '2020-01-02', 1000, 2000)");
    $radius->sampleAccountingCounters('2020-01');
    $radius->sampleAccountingCounters('2020-01');
    verifyRadius($radius->getUsageBytes($username, '2020-01') === 3000.0, 'stopped sessions are not counted again after 14 days');
    $csv = $tmp . '.csv';
    $handle = fopen($csv, 'w');
    fputcsv($handle, array('username','password','name','phone','email','profile','amount','due_day'));
    for ($i=0; $i<1000; $i++) fputcsv($handle, array('ci-import-'.$i, 'ci-test-password', 'Test '.$i, '', '', 'PPPOE-10M', '100000', '20'));
    fclose($handle);
    verifyRadius(importCustomersFromCsv($csv) === 1000, '1000 CSV users imported into real SQL and metadata');
    verifyRadius(count(customerReadAll()) === 1000, '1000 customer metadata records persisted');
    verifyRadius(importCustomersFromCsv($csv) === 1000 && count(customerReadAll()) === 1000, 'CSV repeat updates existing users without duplication');
    file_put_contents($csv, "username,password,name,phone,email,profile,amount,due_day\ninvalid,short,Name,,,NO-SUCH-GROUP,100000,20\n");
    $invalid = false;
    try { importCustomersFromCsv($csv); } catch (InvalidArgumentException $e) { $invalid = true; }
    verifyRadius($invalid && count(customerReadAll()) === 1000, 'invalid CSV rejected without mutation');
    file_put_contents($tmp, '{');
    $rejected = false;
    try { $radius->sampleAccountingCounters(); } catch (RuntimeException $e) { $rejected = true; }
    verifyRadius($rejected && file_get_contents($tmp) === '{', 'corrupt accounting state preserved for recovery');
} finally {
    $radius->db->query("DELETE FROM radacct WHERE acctuniqueid IN ('ci-unique', 'ci-old-unique')");
    $radius->deleteUser($username);
    foreach (array('radcheck','radusergroup') as $table) $radius->db->query("DELETE FROM $table WHERE username LIKE 'ci-import-%'");
    $radius->disconnect();
    foreach (glob($tmp . '*') ?: array() as $path) unlink($path);
}
