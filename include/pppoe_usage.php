<?php
/**
 * Legacy Simple Queue counter helpers retained for compatibility/tests only.
 * Full RADIUS production usage is read from FreeRADIUS radacct via
 * RadiusClient::sampleAccountingCounters() and getUsage(); do not call the
 * RouterOS queue helper for billing/FUP in Full RADIUS mode.
 */
if (!function_exists('pppoeUsagePath')) {
  function pppoeUsagePath() { return __DIR__ . '/../storage/pppoe_usage.json'; }
  function pppoeUsageRead() {
    $path = pppoeUsagePath();
    if (!is_file($path)) return array();
    $decoded = json_decode(@file_get_contents($path) ?: '{}', true);
    return is_array($decoded) ? $decoded : array();
  }
  function pppoeUsageWrite($data) {
    $dir = dirname(pppoeUsagePath());
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return $json !== false && @file_put_contents(pppoeUsagePath(), $json, LOCK_EX) !== false;
  }
  function pppoeBytesToGb($bytes) { return ((float)$bytes / 1073741824); }

  /**
   * Return cumulative octets for matching queues, or null if none match.
   * Queue rows should be fetched once per router, not once per subscriber.
   */
  function pppoeReadQueueCounters($queueRows, $secret) {
    $username = (string)($secret['name'] ?? '');
    $remote = trim((string)($secret['remote-address'] ?? ''));
    $total = 0.0; $matched = false;
    foreach ((array)$queueRows as $row) {
      $name = (string)($row['name'] ?? '');
      $comment = (string)($row['comment'] ?? '');
      $target = (string)($row['target'] ?? '');
      $match = $username !== '' && ($name === $username || $comment === $username);
      if (!$match && $remote !== '' && $remote !== '0.0.0.0' && strpos($target, $remote) !== false) $match = true;
      if (!$match) continue;
      $matched = true;
      if (isset($row['bytes'])) {
        foreach (explode('/', (string)$row['bytes']) as $part) $total += (float)preg_replace('/[^0-9.]/', '', $part);
      } else {
        foreach (array('bytes-in', 'bytes-out') as $key) {
          if (isset($row[$key])) $total += (float)preg_replace('/[^0-9.]/', '', (string)$row[$key]);
        }
      }
    }
    return $matched ? $total : null;
  }

  /**
   * Maintain an accumulated monthly counter, handling counter resets/reboots.
   * First observation in a period becomes the baseline; historical traffic
   * from before this script's first observation cannot be reconstructed.
   */
  function pppoeMonthlyUsageGb($currentCounterBytes, $routerSession, $username, $period) {
    if (!is_numeric($currentCounterBytes) || (float)$currentCounterBytes < 0) return null;
    $all = pppoeUsageRead();
    $key = (string)$routerSession . '|' . (string)$username . '|' . (string)$period;
    $current = (float)$currentCounterBytes;
    if (!isset($all[$key]) || !is_array($all[$key])) {
      $all[$key] = array(
        'last_counter_bytes' => $current,
        'accumulated_bytes' => 0.0,
        'usage_gb' => 0.0,
        'updated_at' => date('Y-m-d H:i:s'),
        'baseline' => true,
      );
      pppoeUsageWrite($all);
      return 0.0;
    }
    $last = max(0.0, (float)($all[$key]['last_counter_bytes'] ?? $current));
    $accumulated = max(0.0, (float)($all[$key]['accumulated_bytes'] ?? 0));
    // A decrease normally means the queue/router counter was reset.
    $delta = $current >= $last ? ($current - $last) : $current;
    $accumulated += $delta;
    $all[$key] = array(
      'last_counter_bytes' => $current,
      'accumulated_bytes' => $accumulated,
      'usage_gb' => pppoeBytesToGb($accumulated),
      'updated_at' => date('Y-m-d H:i:s'),
      'baseline' => false,
    );
    pppoeUsageWrite($all);
    return pppoeBytesToGb($accumulated);
  }
}
?>
