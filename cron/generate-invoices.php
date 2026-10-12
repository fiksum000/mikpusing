<?php
/**
 * Create monthly invoices idempotently. Run daily from cron after configuring
 * non-zero customer prices (or MIKHMON_DEFAULT_MONTHLY_AMOUNT).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');
require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../lib/free_radius_client.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/customers.php';
require_once __DIR__ . '/../include/billing.php';

$period = date('Y-m');
$integrationSettings = mikhmonIntegrationSettings();
$defaultAmountEnv = getenv('MIKHMON_DEFAULT_MONTHLY_AMOUNT');
$defaultAmount = max(0, (int)($defaultAmountEnv !== false && $defaultAmountEnv !== '' ? $defaultAmountEnv : ($integrationSettings['default_monthly_amount'] ?? 0)));
$backendConfig = mikhmonRadiusConfig();
$backend = strtolower((string)(getenv('MIKHMON_PPP_AUTH_BACKEND') ?: ($backendConfig['ppp_auth_backend'] ?? 'routeros')));
$totalCreated = 0; $totalCustomers = 0; $errors = 0;
$logPath = __DIR__ . '/../storage/invoice-generation.log';
function mikhmonInvoiceLog($message) {
  global $logPath;
  $dir = dirname($logPath);
  if (!is_dir($dir)) @mkdir($dir, 0750, true);
  @file_put_contents($logPath, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

if ($backend === 'radius') {
  $radius = null;
  try {
    $radius = new RadiusClient();
    $secrets = array();
    foreach ($radius->listUsers() as $user) {
      $secrets[] = array('name' => (string)($user['username'] ?? ''), 'profile' => (string)($user['profile'] ?? ''), 'comment' => '');
    }
    customerSyncSecrets($secrets, 'RADIUS');
    $customers = array_values(array_filter(customerReadAll(), function($row) {
      return (string)($row['router_session'] ?? '') === 'RADIUS';
    }));
    $totalCustomers = count($customers);
    $created = billingCreateForCustomers($customers, $period, $defaultAmount);
    $totalCreated += $created;
    mikhmonInvoiceLog("backend=radius period={$period} customers={$totalCustomers} created={$created}");
  } catch (Throwable $e) {
    $errors++;
    mikhmonInvoiceLog("ERROR backend=radius period={$period} message=" . preg_replace('/\s+/', ' ', $e->getMessage()));
    fwrite(STDERR, "Gagal membuat invoice RADIUS: " . $e->getMessage() . PHP_EOL);
  } finally {
    if ($radius instanceof RadiusClient) $radius->disconnect();
  }
} else {
  foreach ($data as $sessionName => $sessionData) {
    if ($sessionName === 'mikhmon' || !is_array($sessionData) || count($sessionData) < 4) continue;
    $hostField = explode('!', (string)$sessionData[1], 2);
    $userField = explode('@|@', (string)$sessionData[2], 2);
    $passField = explode('#|#', (string)$sessionData[3], 2);
    if (count($hostField) < 2 || count($userField) < 2 || count($passField) < 2) {
      $errors++; mikhmonInvoiceLog("ERROR router={$sessionName} invalid_config"); continue;
    }
    $endpoint = trim($hostField[1]); $host = $endpoint; $port = 8728;
    if (preg_match('/^\[(.+)\]:(\d+)$/', $endpoint, $m)) { $host = $m[1]; $port = (int)$m[2]; }
    elseif (preg_match('/^([^:]+):(\d+)$/', $endpoint, $m)) { $host = $m[1]; $port = (int)$m[2]; }
    $api = new RouterosAPI(); $api->debug = false; $api->attempts = 1; $api->delay = 1; $api->timeout = 3; $api->port = $port;
    if ($host === '' || !$api->connect($host, trim($userField[1]), decrypt($passField[1]))) {
      $errors++; mikhmonInvoiceLog("ERROR router={$sessionName} connect_failed"); continue;
    }
    try {
      $response = $api->comm('/ppp/secret/print');
      if (!is_array($response) || isset($response['!trap'])) {
        $errors++; mikhmonInvoiceLog("ERROR router={$sessionName} unable_to_read_ppp_secrets"); continue;
      }
      customerSyncSecrets($response, (string)$sessionName);
      $customers = array_values(array_filter(customerReadAll(), function($row) use ($sessionName) {
        return (string)($row['router_session'] ?? '') === (string)$sessionName;
      }));
      $totalCustomers += count($customers);
      $created = billingCreateForCustomers($customers, $period, $defaultAmount);
      $totalCreated += $created;
      mikhmonInvoiceLog("backend=routeros router={$sessionName} period={$period} customers=" . count($customers) . " created={$created}");
    } catch (Throwable $e) {
      $errors++; mikhmonInvoiceLog("ERROR router={$sessionName} message=" . preg_replace('/\s+/', ' ', $e->getMessage()));
    } finally { $api->disconnect(); }
  }
}
$summary = "Invoice generation period={$period} backend={$backend} customers={$totalCustomers} created={$totalCreated} errors={$errors} default_amount={$defaultAmount}";
echo date('c') . ' ' . $summary . PHP_EOL;
mikhmonInvoiceLog($summary);
if ($errors > 0) exit(1);
?>
