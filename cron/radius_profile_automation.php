<?php
/**
 * FreeRADIUS-backed PPPoE and Hotspot profile automation.
 * Manage every group configured in the package mapping. Default is DRY RUN;
 * pass --apply to change radusergroup and reauthenticate active RouterOS sessions.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$lockPath = __DIR__ . '/../storage/pppoe-profile-automation.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir) && !@mkdir($lockDir, 0750, true) && !is_dir($lockDir)) {
    fwrite(STDERR, date('c') . " Cannot create automation lock directory.\n");
    exit(1);
}
$lockHandle = @fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (is_resource($lockHandle)) fclose($lockHandle);
    echo date('c') . " RADIUS automation skipped: another run is still active.\n";
    exit(0);
}
register_shutdown_function(function() use ($lockHandle) {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
});


require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../lib/free_radius_client.php';
$radiusConfig = mikhmonRadiusConfig();
// Full RADIUS mode uses RFC 5176 Disconnect-Message only. Do not fall back
// to RouterOS API even if an old config file still says routeros-api.
$radiusDisconnectMode = 'radius-dm';
require_once __DIR__ . '/../lib/radius_disconnect_client.php';
require_once __DIR__ . '/../include/customers.php';
require_once __DIR__ . '/../include/billing.php';
require_once __DIR__ . '/../include/pppoe_packages.php';

date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');
$apply = in_array('--apply', $argv, true);
$period = date('Y-m'); $today = (int)date('j'); $daysInMonth = (int)date('t');
$config = pppoePackageRead();
$isolir = 'ISOLIR'; // Must match db/radius-mikrotik-groups.example.sql and Mikrotik-Address-List policy.
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
$pendingPath = getenv('MIKHMON_RADIUS_PENDING_FILE') ?: __DIR__ . '/../storage/radius-kick-pending.json';
$pending = array();
$kickRequested = array();
if (is_file($pendingPath)) {
    $storedPending = json_decode(@file_get_contents($pendingPath) ?: '{}', true);
    if (!is_array($storedPending)) { fwrite(STDERR, "Pending DM JSON rusak; pulihkan backup.\n"); exit(1); }
    $pending = $storedPending;
}
function saveRadiusPending($path, $pending) {
    $json = json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $tmp = $path . '.tmp.' . getmypid();
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) throw new RuntimeException('Gagal menyimpan antrean DM.');
}
$radiusDisconnectController = null;
try {
    $radius = new RadiusClient();
    $radiusDisconnectController = new MikhmonRadiusDisconnectClient($radius, $radiusConfig);
    $users = $radius->listUsers();
    // Persist each RADIUS subscriber's last known normal group before any isolation
    // transition; this is how the system can restore the right package after payment.
    $customerSyncRows = array();
    foreach ($users as $radiusUser) {
        $customerSyncRows[] = array(
            'name' => (string)($radiusUser['username'] ?? ''),
            'profile' => (string)($radiusUser['profile'] ?? ''),
            'comment' => ''
        );
    }
    customerSyncSecrets($customerSyncRows, 'RADIUS');
    $customers = array();
    foreach (customerReadAll() as $row) {
        $usernameKey = (string)($row['username'] ?? '');
        if ($usernameKey !== '') $customers[(string)($row['router_session'] ?? '') . '|' . $usernameKey] = $row;
    }
    $usageMap = $radius->getMonthlyUsageMap($period);
    // Index all invoice periods once. Billing/isolation decisions must account for
    // old unpaid invoices too, not only the invoice for the current month.
    $invoicesByUsername = array();
    foreach (billingReadInvoices() as $invoiceRow) {
        $invoiceUsername = trim((string)($invoiceRow['username'] ?? ''));
        if ($invoiceUsername !== '') $invoicesByUsername[$invoiceUsername][] = $invoiceRow;
    }
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
        $subscriberInvoices = $invoicesByUsername[$username] ?? array();
        // Without billing evidence do not undo an existing isolation; normal/free
        // customers still receive FUP decisions independently of invoice generation.
        if (!$subscriberInvoices && $profile === $isolir) {
            $skipped++;
            $log("WARN backend=radius user={$username} invoice_history_missing; profile unchanged");
            continue;
        }
        $pastGrace = billingCustomerHasPastDueInvoice($subscriberInvoices);
        if (!$pastGrace) {
            foreach ($subscriberInvoices as $subscriberInvoice) {
                if (strtolower((string)($subscriberInvoice['status'] ?? 'unpaid')) === 'paid'
                    || (int)($subscriberInvoice['amount'] ?? 0) <= 0) continue;
                $dueDate = trim((string)($subscriberInvoice['due_date'] ?? ''));
                $dueTimestamp = $dueDate !== '' ? strtotime($dueDate . ' 00:00:00') : false;
                if ($dueTimestamp === false || date('Y-m-d', $dueTimestamp) !== $dueDate) {
                    $log("WARN backend=radius user={$username} invoice_due_date_invalid; that invoice will not trigger isolation");
                }
            }
        }
        $usageBytes = array_key_exists($username, $usageMap) ? (float)$usageMap[$username] : null;
        $usageGb = $usageBytes === null ? null : $usageBytes / 1073741824;
        $target = $profile;
        if ($pastGrace && $isolir !== '') {
            $target = $isolir;
        } elseif ($fup !== '' && $quota > 0 && $usageGb !== null && $usageGb >= $quota) {
            $target = $fup;
        } elseif ($normal !== '' && ($profile === $isolir || ($profile === $fup && ($quota <= 0 || ($usageGb !== null && $usageGb < $quota))))) {
            // Restore normal service if no invoice remains past grace, even when
            // this month's invoice exists but has not reached its own due date.
            $target = $normal;
        }
        if ($quota > 0 && $usageGb === null) $log("WARN backend=radius user={$username} monthly_usage_unknown; run radius-accounting-snapshot.php every 5 minutes");
        if ($target === '' || $target === $profile) continue;
        $message = ($apply ? 'APPLY' : 'DRY-RUN') . " backend=radius user={$username} {$profile} -> {$target} usage_gb=" . ($usageGb === null ? 'unknown' : number_format($usageGb, 3, '.', ''));
        if (!$apply) { echo $message . PHP_EOL; continue; }
        try {
            $pending[$username] = array('target' => $target, 'requested_at' => date('c'));
            saveRadiusPending($pendingPath, $pending);
            $radius->setProfile($username, $target);
            $kickRequested[$username] = $message;
            $changed++;
        } catch (Throwable $e) {
            $errors++; $log("ERROR " . $message . " reason=" . preg_replace('/\s+/', ' ', $e->getMessage()));
        }
    }
    // Retry durable reconnect intents with a bounded packet/time budget.
    if ($apply && count($pending) > 0) {
        $toKick = array_values(array_unique(array_merge(array_keys($pending), array_keys($kickRequested))));
        usort($toKick, function($a, $b) use ($pending) { return strcmp($pending[$a]['last_attempt_at'] ?? '', $pending[$b]['last_attempt_at'] ?? ''); });
        $kickResults = $radiusDisconnectController->disconnectSubscribers($toKick);
        foreach ($toKick as $pendingUser) {
            $kicked = $kickResults[$pendingUser] ?? array('routers_checked' => 0, 'sessions' => 0, 'errors' => 1, 'router_errors' => array('unknown' => 'missing_kick_result'));
            if (!empty($kicked['deferred'])) continue;
            if (isset($pending[$pendingUser])) $pending[$pendingUser]['last_attempt_at'] = date('c');
            if ($kicked['routers_checked'] > 0 && $kicked['errors'] === 0 && count($kicked['router_errors']) === 0) {
                unset($pending[$pendingUser]);
                $label = isset($kickRequested[$pendingUser]) ? 'APPLIED' : 'RECOVERED';
                $log("{$label} backend=radius-dm user={$pendingUser} sessions_disconnected={$kicked['sessions']} no_active_sessions=" . (!empty($kicked['no_active_sessions']) ? 'yes' : 'no'));
            } else {
                if (!isset($pending[$pendingUser])) $pending[$pendingUser] = array('target' => '', 'requested_at' => date('c'));
                $details = implode('; ', array_values($kicked['router_errors']));
                $log("WARN backend=radius-dm user={$pendingUser} session_disconnect_pending errors={$kicked['errors']} details=" . preg_replace('/\s+/', ' ', $details) . "; retrying on next five-minute run");
            }
        }
        try { saveRadiusPending($pendingPath, $pending); }
        catch (Throwable $e) { $errors++; $log('ERROR unable_to_save_session_kick_pending_queue'); }
    }
    $radius->disconnect();
} catch (Throwable $e) {
    if ($radius instanceof RadiusClient) $radius->disconnect();
    fwrite(STDERR, date('c') . ' RADIUS automation FAILED: ' . preg_replace('/\s+/', ' ', $e->getMessage()) . PHP_EOL);
    exit(1);
}
$summary = "RADIUS PPPoE automation users={$managed}, profile_changes={$changed}, skipped={$skipped}, errors={$errors}, period={$period}, mode=" . ($apply ? 'APPLY' : 'DRY-RUN');
echo date('c') . ' ' . $summary . PHP_EOL; $log($summary);
if ($errors > 0) exit(1);
?>
