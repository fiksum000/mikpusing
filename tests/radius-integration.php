<?php
// Run only against a disposable CI database, never a customer's CT.
if (getenv('MIKPUSING_TEST_DATABASE') !== '1') exit("Set MIKPUSING_TEST_DATABASE=1 on a disposable database.\n");
require_once __DIR__ . '/../lib/free_radius_client.php';
function verifyRadius($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$tmp = sys_get_temp_dir() . '/radius-accounting-test-' . getmypid() . '.json';
putenv('MIKHMON_ACCOUNTING_FILE=' . $tmp);
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
    $radius->sampleAccountingCounters();
    verifyRadius($radius->getUsageBytes($username) === 4294967600.0, '64-bit counters sampled without gigaword columns');
    $radius->sampleAccountingCounters();
    verifyRadius($radius->getUsageBytes($username) === 4294967600.0, 'repeated sampling does not double count');
    $radius->db->query("UPDATE radacct SET acctinputoctets=acctinputoctets+100 WHERE acctuniqueid='ci-unique'");
    $radius->sampleAccountingCounters();
    verifyRadius($radius->getUsageBytes($username) === 4294967700.0, 'interim delta added once');
    file_put_contents($tmp, '{');
    $rejected = false;
    try { $radius->sampleAccountingCounters(); } catch (RuntimeException $e) { $rejected = true; }
    verifyRadius($rejected && file_get_contents($tmp) === '{', 'corrupt accounting state preserved for recovery');
} finally {
    $radius->db->query("DELETE FROM radacct WHERE acctuniqueid='ci-unique'");
    $radius->deleteUser($username);
    $radius->disconnect();
    foreach (glob($tmp . '*') ?: array() as $path) unlink($path);
}
