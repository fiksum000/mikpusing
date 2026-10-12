<?php
/**
 * FreeRADIUS-backed PPPoE and Hotspot profile automation.
 * Manage every group configured in the package mapping. Default is DRY RUN;
 * pass --apply to change radusergroup and reauthenticate active RouterOS sessions.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../lib/free_radius_client.php';
require_once __DIR__ . '/../lib/routeros_session_control.php';
require_once __DIR__ . '/../include/customers.php';
require_once __DIR__ . '/../include/billing.php';
require_once __DIR__ . '/../include/pppoe_packages.php';

date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');
$apply = in_array('--apply', $argv, true);
$period = date('Y-m'); $today = (int)date('j'); $daysInMonth = (int)date('t');
$config = pppoePackageRead();
$isolir = trim((string)($config['isolir_profile'] ?? 'ISOLIR'));
$packageByProfile = array();
foreach ((array)($config['packages'] ?? array()) as $package) {
    foreach (array('normal_profile', 'fup_profile') as $field) {
        $name = trim((string)($package[$field] ?? ''));
        if ($name !== '') $packageByProfile[$name] = $package;
    }
}
$customers = array();
foreach (customerReadAll() as $row) {
    $username = (string)($row['username'] ?? '');
    if ($username === '') continue;
    $customers[(string)($row['router_session'] ?? '') . '|' . $username] = $row;
}
$logPath = __DIR__ . '/../storage/pppoe-automation.log';
$log = function($message) use ($logPath) {
    $dir = dirname($logPath);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($logPath, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
};
$errors = 0; $changed = 0; $skipped = 0; $managed = 0;
$radius = null;
$pendingPath = __DIR__ . '/../storage/radius-kick-pending.json';
$pending = array();
$kickRequested = array();
if (is_file($pendingPath)) {
    $storedPending = json_decode(@file_get_contents($pendingPath) ?: '{}', true);
    if (is_array($storedPending)) $pending = $storedPending;
}
$routerSessionController = new MikhmonRouterSessionController(isset($data) && is_array($data) ? $data : array());
try {
    $radius = new RadiusClient();
    $users = $radius->listUsers();
    $usageMap = $radius->getMonthlyUsageMap($period);
    foreach ($users as $user) {
        $username = trim((string)($user['username'] ?? ''));
        $profile = trim((string)($user['profile'] ?? ''));
        if ($username === '' || $profile === '') { $skipped++; continue; }
        $customerKey = 'RADIUS|' . $username;
        $customer = $customers[$customerKey] ?? ($customers['|' . $username] ?? array());
        $package = $packageByProfile[$profile] ?? null;
        if (!$package && !empty($customer['normal_profile'])) $package = $packageByProfile[(string)$customer['normal_profile']] ?? null;
        // Unknown profile/group: this is not a managed PPPoE package (e.g. Hotspot).
        if (!$package) { $skipped++; continue; }
        $managed++;
        $normal = trim((string)($package['normal_profile'] ?? ''));
        $fup = trim((string)($package['fup_profile'] ?? ''));
        $quota = max(0.0, (float)($package['quota_gb'] ?? 0));
        $invoiceNo = billingInvoiceNo($username, $period, 'RADIUS');
        $invoice = billingFindInvoice($invoiceNo);
        // Missing invoice is not interpreted as unpaid: prevent mass isolation if billing was not generated.
        if (!is_array($invoice)) {
            $skipped++;
            $log("WARN backend=radius user={$username} invoice_missing; profile unchanged");
            continue;
        }
        $paid = ($invoice['status'] ?? '') === 'paid';
        $dueTimestamp = !empty($invoice['due_date']) ? strtotime((string)$invoice['due_date'] . ' 00:00:00') : false;
        $isolateTimestamp = $dueTimestamp === false ? false : strtotime('+5 days', $dueTimestamp);
        $pastGrace = $isolateTimestamp !== false && time() >= $isolateTimestamp;
        if ($isolateTimestamp === false) $log("WARN backend=radius user={$username} invoice_due_date_invalid; isolation disabled for safety");
        $usageBytes = array_key_exists($username, $usageMap) ? (float)$usageMap[$username] : null;
        $usageGb = $usageBytes === null ? null : $usageBytes / 1073741824;
        $target = $profile;
        if ($paid && $normal !== '') $target = $normal;
        elseif (!$paid && $pastGrace && $isolir !== '') $target = $isolir;
        elseif (!$paid && $fup !== '' && $quota > 0 && $usageGb !== null && $usageGb >= $quota) $target = $fup;
        elseif (!$paid && $fup !== '' && $profile === $fup && $normal !== '' && ($quota <= 0 || ($usageGb !== null && $usageGb < $quota))) $target = $normal;
        if ($quota > 0 && $usageGb === null) $log("WARN backend=radius user={$username} monthly_usage_unknown; run radius-accounting-snapshot.php every 5 minutes");
        if ($target === '' || $target === $profile) continue;
        $message = ($apply ? 'APPLY' : 'DRY-RUN') . " backend=radius user={$username} {$profile} -> {$target} usage_gb=" . ($usageGb === null ? 'unknown' : number_format($usageGb, 3, '.', ''));
        if (!$apply) { echo $message . PHP_EOL; continue; }
        try {
            $radius->setProfile($username, $target);
            $pending[$username] = array('target' => $target, 'requested_at' => date('c'));
            $kickRequested[$username] = $message;
            $changed++;
        } catch (Throwable $e) {
            $errors++; $log("ERROR " . $message . " reason=" . preg_replace('/\s+/', ' ', $e->getMessage()));
        }
    }
    // Batch all profile changes and earlier retries together: only two active-session
    // list queries per router, rather than two list queries for every subscriber.
    if ($apply && count($pending) > 0) {
        $toKick = array_values(array_unique(array_merge(array_keys($pending), array_keys($kickRequested))));
        $kickResults = $routerSessionController->disconnectSubscribers($toKick);
        foreach ($toKick as $pendingUser) {
            $kicked = $kickResults[$pendingUser] ?? array('routers_checked' => 0, 'ppp' => 0, 'hotspot' => 0, 'errors' => 1, 'router_errors' => array('unknown' => 'missing_kick_result'));
            if ($kicked['routers_checked'] > 0 && $kicked['errors'] === 0 && count($kicked['router_errors']) === 0) {
                unset($pending[$pendingUser]);
                $label = isset($kickRequested[$pendingUser]) ? 'APPLIED' : 'RECOVERED';
                $log("{$label} backend=radius user={$pendingUser} sessions_kicked_ppp={$kicked['ppp']} sessions_kicked_hotspot={$kicked['hotspot']} routers_checked={$kicked['routers_checked']}");
            } else {
                if (!isset($pending[$pendingUser])) $pending[$pendingUser] = array('target' => '', 'requested_at' => date('c'));
                $log("WARN backend=radius user={$pendingUser} session_kick_pending routers_checked={$kicked['routers_checked']} errors={$kicked['errors']} offline_routers=" . count($kicked['router_errors']) . "; retrying on next five-minute run");
            }
        }
        $pendingJson = json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($pendingJson === false || @file_put_contents($pendingPath, $pendingJson, LOCK_EX) === false) {
            $errors++;
            $log("ERROR unable_to_save_session_kick_pending_queue");
        }
    }
    $radius->disconnect();
    $routerSessionController->closeAll();
} catch (Throwable $e) {
    if ($radius instanceof RadiusClient) $radius->disconnect();
    fwrite(STDERR, date('c') . ' RADIUS automation FAILED: ' . preg_replace('/\s+/', ' ', $e->getMessage()) . PHP_EOL);
    exit(1);
}
$summary = "RADIUS PPPoE automation users={$managed}, profile_changes={$changed}, skipped={$skipped}, errors={$errors}, period={$period}, mode=" . ($apply ? 'APPLY' : 'DRY-RUN');
echo date('c') . ' ' . $summary . PHP_EOL; $log($summary);
if ($errors > 0) exit(1);
?>
