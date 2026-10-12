<?php
/**
 * Safe PPPoE profile automation for MikroTik RouterOS.
 *
 * Default mode is DRY RUN. Use --apply to change profiles.
 * Requires: RouterOS API credentials in include/config.php and package mappings
 * in storage/pppoe_packages.json. FUP usage requires a simple queue whose name
 * or comment is the PPP username, or whose target contains that secret's
 * static remote-address.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');

$automationLockPath = __DIR__ . '/../storage/pppoe-profile-automation.lock';
if (!is_dir(dirname($automationLockPath))) @mkdir(dirname($automationLockPath), 0750, true);
$automationLockHandle = @fopen($automationLockPath, 'c');
if (!$automationLockHandle || !flock($automationLockHandle, LOCK_EX | LOCK_NB)) {
    echo date('c') . " PPPoE automation skipped: another run is still active.\\n";
    if (is_resource($automationLockHandle)) fclose($automationLockHandle);
    exit(0);
}
register_shutdown_function(function() use ($automationLockHandle) {
    if (is_resource($automationLockHandle)) { flock($automationLockHandle, LOCK_UN); fclose($automationLockHandle); }
});

require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/customers.php';
require_once __DIR__ . '/../include/billing.php';
require_once __DIR__ . '/../include/pppoe_packages.php';
require_once __DIR__ . '/../include/pppoe_usage.php';
require_once __DIR__ . '/../lib/free_radius_client.php';
require_once __DIR__ . '/../lib/routeros_session_control.php';

// Central FreeRADIUS users are managed in SQL groups, not local /ppp/secret.
$radiusConfig = mikhmonRadiusConfig();
$authBackend = strtolower((string)(getenv('MIKHMON_PPP_AUTH_BACKEND') ?: ($radiusConfig['ppp_auth_backend'] ?? 'routeros')));
if ($authBackend === 'radius') {
    require __DIR__ . '/radius_profile_automation.php';
    exit;
}

$apply = in_array('--apply', $argv, true);
$period = date('Y-m');
$today = (int) date('j');
$daysInMonth = (int) date('t');
$packageConfig = pppoePackageRead();
$isolirProfile = trim((string) ($packageConfig['isolir_profile'] ?? 'ISOLIR'));
$customersByRouterUser = array();
foreach (customerReadAll() as $customer) {
    $key = (string)($customer['router_session'] ?? '') . '|' . (string)($customer['username'] ?? '');
    $customersByRouterUser[$key] = $customer;
}
$routers = 0; $changed = 0; $skipped = 0; $errors = 0;

function mikhmonRouterCredentials($sessionData) {
    if (!is_array($sessionData) || count($sessionData) < 4) return null;
    $hostField = explode('!', (string)$sessionData[1], 2);
    $userField = explode('@|@', (string)$sessionData[2], 2);
    $passField = explode('#|#', (string)$sessionData[3], 2);
    if (count($hostField) < 2 || count($userField) < 2 || count($passField) < 2) return null;
    $endpoint = trim($hostField[1]);
    $user = trim($userField[1]);
    $password = (string)$passField[1];
    if ($endpoint === '' || $user === '') return null;
    $host = $endpoint; $port = 8728;
    if (preg_match('/^\[(.+)\]:(\d+)$/', $endpoint, $m)) { $host = $m[1]; $port = (int)$m[2]; }
    elseif (preg_match('/^([^:]+):(\d+)$/', $endpoint, $m)) { $host = $m[1]; $port = (int)$m[2]; }
    if ($host === '' || $port < 1 || $port > 65535) return null;
    return array($host, $port, $user, decrypt($password));
}

function mikhmonKickPppOnly($api, $username) {
    $result = array('kicked' => 0, 'errors' => 0);
    $rows = $api->comm('/ppp/active/print', array('?name' => (string)$username));
    if (!is_array($rows) || isset($rows['!trap'])) return array('kicked' => 0, 'errors' => 1);
    foreach ($rows as $row) {
        if (empty($row['.id'])) continue;
        $removed = $api->comm('/ppp/active/remove', array('.id' => $row['.id']));
        if (is_array($removed) && isset($removed['!trap'])) $result['errors']++;
        else $result['kicked']++;
    }
    return $result;
}

function mikhmonQueueBytesForSecret($queues, $secret) {
    $username = (string)($secret['name'] ?? '');
    $remote = trim((string)($secret['remote-address'] ?? ''));
    $total = 0.0; $matched = false;
    foreach ((array)$queues as $queue) {
        $name = (string)($queue['name'] ?? '');
        $comment = (string)($queue['comment'] ?? '');
        $target = (string)($queue['target'] ?? '');
        $match = ($username !== '' && ($name === $username || $comment === $username));
        if (!$match && $remote !== '' && $remote !== '0.0.0.0' && strpos($target, $remote) !== false) $match = true;
        if (!$match) continue;
        $matched = true;
        if (isset($queue['bytes'])) {
            foreach (explode('/', (string)$queue['bytes']) as $part) $total += (float)preg_replace('/[^0-9.]/', '', $part);
        } else {
            foreach (array('bytes-in', 'bytes-out') as $key) if (isset($queue[$key])) $total += (float)preg_replace('/[^0-9.]/', '', (string)$queue[$key]);
        }
    }
    return $matched ? $total : null;
}

function mikhmonLogAutomation($line) {
    $path = __DIR__ . '/../storage/pppoe-automation.log';
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($path, date('c') . ' ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

foreach ($data as $sessionName => $sessionData) {
    if ($sessionName === 'mikhmon' || !is_array($sessionData)) continue;
    $credentials = mikhmonRouterCredentials($sessionData);
    if ($credentials === null) { $skipped++; continue; }
    list($host, $port, $routerUser, $routerPassword) = $credentials;
    $api = new RouterosAPI();
    $api->debug = false;
    if (property_exists($api, 'port')) $api->port = $port;
    if (!$api->connect($host, $routerUser, $routerPassword)) {
        $errors++; mikhmonLogAutomation("ERROR router={$sessionName} connect_failed host={$host}:{$port}");
        continue;
    }
    $routers++;
    try {
        $secrets = $api->comm('/ppp/secret/print');
        $queues = $api->comm('/queue/simple/print');
        if (!is_array($secrets) || isset($secrets['!trap'])) {
            $errors++; mikhmonLogAutomation("ERROR router={$sessionName} ppp_secret_query_failed");
            $api->disconnect(); continue;
        }
        if (!is_array($queues) || isset($queues['!trap'])) $queues = array();
        customerSyncSecrets($secrets, (string)$sessionName);

        foreach ($secrets as $secret) {
            $username = (string)($secret['name'] ?? '');
            $id = (string)($secret['.id'] ?? '');
            $profile = (string)($secret['profile'] ?? '');
            if ($username === '' || $id === '' || $profile === '') { $skipped++; continue; }
            $customerKey = (string)$sessionName . '|' . $username;
            $customer = $customersByRouterUser[$customerKey] ?? array();
            $comment = (string)($secret['comment'] ?? '');
            $isolateDayOverride = null;
            if (preg_match('/ISOLIR\s*=\s*(\d{1,2})/i', $comment, $m)) $isolateDayOverride = (int)$m[1];

            $package = pppoePackageForProfile($profile);
            if (!$package && !empty($customer['normal_profile'])) $package = pppoePackageForProfile((string)$customer['normal_profile']);
            if (!$package) { $skipped++; continue; }
            $invoiceNo = billingInvoiceNo($username, $period, (string)$sessionName);
            $invoice = billingFindInvoice($invoiceNo);
            if (!is_array($invoice)) {
                // Compatibility with invoices created by older versions before hashed IDs.
                $legacyInvoiceNo = billingLegacyInvoiceNo($username, $period);
                if ($legacyInvoiceNo !== '') $invoice = billingFindInvoice($legacyInvoiceNo);
            }
            // Missing invoice is not interpreted as unpaid: prevent mass isolation if billing was not generated.
            if (!is_array($invoice)) {
                $skipped++;
                mikhmonLogAutomation("WARN router={$sessionName} user={$username} invoice_missing; profile unchanged");
                continue;
            }
            $paid = ($invoice['status'] ?? '') === 'paid';
            $dueTimestamp = !empty($invoice['due_date']) ? strtotime((string)$invoice['due_date'] . ' 00:00:00') : false;
            $isolateTimestamp = $dueTimestamp === false ? false : strtotime('+5 days', $dueTimestamp);
            if ($isolateDayOverride !== null) {
                $overrideDay = min($daysInMonth, max(1, $isolateDayOverride));
                $isolateTimestamp = strtotime(sprintf('%s-%02d 00:00:00', $period, $overrideDay));
            }
            $pastGrace = $isolateTimestamp !== false && time() >= $isolateTimestamp;
            if ($isolateTimestamp === false) mikhmonLogAutomation("WARN router={$sessionName} user={$username} invoice_due_date_invalid; isolation disabled for safety");
            $currentBytes = mikhmonQueueBytesForSecret($queues, $secret);
            $usageGb = $currentBytes === null ? null : pppoeMonthlyUsageGb($currentBytes, (string)$sessionName, $username, $period);

            $normalProfile = trim((string)($package['normal_profile'] ?? ''));
            $fupProfile = trim((string)($package['fup_profile'] ?? ''));
            $quotaGb = (float)($package['quota_gb'] ?? 0);
            $target = $profile;
            if ($paid && $normalProfile !== '') {
                $target = $normalProfile;
            } elseif (!$paid && $pastGrace && $isolirProfile !== '') {
                $target = $isolirProfile;
            } elseif (!$paid && $fupProfile !== '' && $quotaGb > 0 && $usageGb !== null && $usageGb >= $quotaGb) {
                $target = $fupProfile;
            } elseif (!$paid && $fupProfile !== '' && $profile === $fupProfile && $normalProfile !== '' && ($quotaGb <= 0 || ($usageGb !== null && $usageGb < $quotaGb))) {
                $target = $normalProfile;
            }

            if ($quotaGb > 0 && $usageGb === null) {
                mikhmonLogAutomation("WARN router={$sessionName} user={$username} usage_unknown; FUP not evaluated (no matching simple queue)");
            }
            if ($target === '' || $target === $profile) continue;
            $message = ($apply ? 'APPLY' : 'DRY-RUN') . " router={$sessionName} user={$username} {$profile} -> {$target} usage_gb=" . ($usageGb === null ? 'unknown' : number_format($usageGb, 3, '.', ''));
            if (!$apply) { echo $message . PHP_EOL; continue; }
            $result = $api->comm('/ppp/secret/set', array('.id' => $id, 'profile' => $target));
            if (is_array($result) && isset($result['!trap'])) {
                $errors++; mikhmonLogAutomation("ERROR " . $message . " router_trap");
            } else {
                $kick = mikhmonKickSubscriberOnApi($api, $username);
                $changed++;
                mikhmonLogAutomation($message . " kicked_ppp={$kick['ppp']} kicked_hotspot={$kick['hotspot']} kick_errors={$kick['errors']}");
            }
        }
    } catch (Throwable $e) {
        $errors++; mikhmonLogAutomation("ERROR router={$sessionName} exception=" . preg_replace('/\s+/', ' ', $e->getMessage()));
    }
    $api->disconnect();
}
$summary = "PPPoE automation routers={$routers}, profile_changes={$changed}, skipped={$skipped}, errors={$errors}, period={$period}, mode=" . ($apply ? 'APPLY' : 'DRY-RUN');
echo date('c') . ' ' . $summary . PHP_EOL;
mikhmonLogAutomation($summary);
if ($errors > 0) exit(1);
?>
