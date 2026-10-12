<?php
/**
 * FreeRADIUS-backed PPPoE profile automation.
 *
 * Only users assigned to one of the configured PPPoE package groups are managed.
 * Hotspot users should use their own groups and are intentionally ignored.
 * Default is DRY RUN; pass --apply to change radusergroup.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../lib/free_radius_client.php';
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
        $dueDay = min($daysInMonth, max(1, (int)($customer['due_day'] ?? 20)));
        $isolateDay = min($daysInMonth, $dueDay + 5);
        $invoiceNo = billingInvoiceNo($username, $period, 'RADIUS');
        $invoice = billingFindInvoice($invoiceNo);
        $paid = is_array($invoice) && ($invoice['status'] ?? '') === 'paid';
        $usageBytes = array_key_exists($username, $usageMap) ? (float)$usageMap[$username] : null;
        $usageGb = $usageBytes === null ? null : $usageBytes / 1073741824;
        $target = $profile;
        if ($paid && $normal !== '') $target = $normal;
        elseif (!$paid && $today >= $isolateDay && $isolir !== '') $target = $isolir;
        elseif (!$paid && $fup !== '' && $quota > 0 && $usageGb !== null && $usageGb >= $quota) $target = $fup;
        elseif (($profile === $isolir || ($fup !== '' && $profile === $fup)) && $normal !== '') $target = $normal;
        if ($quota > 0 && $usageGb === null) $log("WARN backend=radius user={$username} monthly_usage_unknown; run radius-accounting-snapshot.php every 5 minutes");
        if ($target === '' || $target === $profile) continue;
        $message = ($apply ? 'APPLY' : 'DRY-RUN') . " backend=radius user={$username} {$profile} -> {$target} usage_gb=" . ($usageGb === null ? 'unknown' : number_format($usageGb, 3, '.', ''));
        if (!$apply) { echo $message . PHP_EOL; continue; }
        try {
            $radius->setProfile($username, $target);
            $changed++; $log($message . ' note=existing_sessions_may_need_reauth');
        } catch (Throwable $e) {
            $errors++; $log("ERROR " . $message . " reason=" . preg_replace('/\s+/', ' ', $e->getMessage()));
        }
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
