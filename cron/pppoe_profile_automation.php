<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../lib/free_radius_client.php';
require_once __DIR__ . '/../lib/decrypt_helper.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/customers.php';
require_once __DIR__ . '/../include/billing.php';
require_once __DIR__ . '/../include/pppoe_packages.php';
require_once __DIR__ . '/../include/pppoe_usage.php';
$dryRun = in_array('--dry-run', $argv, true); $today = (int)date('j'); $period = date('Y-m'); $packageConfig = pppoePackageRead(); $isolirProfile = trim($packageConfig['isolir_profile']); $customerMap = array(); foreach (customerReadAll() as $customer) $customerMap[$customer['router_session'] . '|' . $customer['username']] = $customer;
$changed = 0; $routers = 0; $skipped = 0;
foreach ($data as $sessionName => $sessionData) {
  if ($sessionName === 'mikhmon' || !is_array($sessionData) || count($sessionData) < 4) continue;
  $ipParts = explode('!', (string)$sessionData[1], 2); $userParts = explode('@|@', (string)$sessionData[2], 2); $passParts = explode('#|#', (string)$sessionData[3], 2);
  if (count($ipParts) < 2 || count($userParts) < 2 || count($passParts) < 2) { $skipped++; continue; }
  // RADIUS mode: tidak perlu connect router 
  $secrets = $radius->db->query("SELECT username FROM radcheck GROUP BY username");
  foreach ((array)$secrets as $secret) {
    if (empty($secret['name']) || empty($secret['.id'])) continue; $username = (string)$secret['name']; $customer = isset($customerMap[$sessionName . '|' . $username]) ? $customerMap[$sessionName . '|' . $username] : (isset($customerMap['|' . $username]) ? $customerMap['|' . $username] : array()); $comment = isset($secret['comment']) ? (string)$secret['comment'] : '';
    $billingDay = isset($customer['due_day']) ? min(31, max(1, (int)$customer['due_day'])) : $today; $isolateDay = min(31, $billingDay + 5); if (preg_match('/BILLING\s*=\s*(\d{1,2})/i', $comment, $m)) $billingDay = min(31, max(1, (int)$m[1])); if (preg_match('/ISOLIR\s*=\s*(\d{1,2})/i', $comment, $m)) $isolateDay = min(31, max(1, (int)$m[1]));
    $profile = isset($secret['profile']) ? (string)$secret['profile'] : ''; $package = pppoePackageForProfile($profile); if (!$package && isset($customer['normal_profile'])) $package = pppoePackageForProfile($customer['normal_profile']); if (!$package) { $skipped++; continue; }
    $invoice = billingFindInvoice(billingInvoiceNo($username, $period)); $paid = $invoice && isset($invoice['status']) && $invoice['status'] === 'paid'; $usageGb = pppoeMonthlyUsageGb($api, $sessionName, $username, $period); $target = $profile;
    if ($paid) $target = !empty($package['normal_profile']) ? $package['normal_profile'] : $profile;
    elseif ($today >= $isolateDay) $target = $isolirProfile;
    elseif (!empty($package['fup_profile']) && (float)$package['quota_gb'] > 0 && $usageGb >= (float)$package['quota_gb']) $target = $package['fup_profile'];
    elseif ($profile === $isolirProfile || $profile === $package['fup_profile']) $target = $package['normal_profile'];
    if ($target !== '' && $target !== $profile) { if ($dryRun) { echo "DRY-RUN {$sessionName}/{$username}: {$profile} -> {$target}, usage=" . round($usageGb, 2) . "GB\n"; } else { $result = $api->comm('/ppp/secret/set', array('.id' => $secret['.id'], 'profile' => $target)); if (!isset($result['!trap'])) $changed++; } }
  }
  $api->disconnect();
}
echo date('c') . " PPPoE automation: routers={$routers}, profile_changes={$changed}, skipped={$skipped}, period={$period}, dry_run=" . ($dryRun ? 'yes' : 'no') . "\n";
?>
