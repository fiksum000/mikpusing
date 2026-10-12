<?php
if (!function_exists('pppoeUsagePath')) {
  function pppoeUsagePath() { return __DIR__ . '/../storage/pppoe_usage.json'; }
  function pppoeUsageRead() { if (!file_exists(pppoeUsagePath())) return array(); $data = json_decode(@file_get_contents(pppoeUsagePath()) ?: '{}', true); return is_array($data) ? $data : array(); }
  function pppoeUsageWrite($data) { $dir = dirname(pppoeUsagePath()); if (!is_dir($dir)) @mkdir($dir, 0750, true); @file_put_contents(pppoeUsagePath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX); }
  function pppoeBytesToGb($bytes) { return ((float)$bytes / 1073741824); }
  function pppoeReadQueueCounters($api, $username) {
    $rows = $api->comm('/queue/simple/print'); $total = 0;
    foreach ((array)$rows as $row) {
      $name = isset($row['name']) ? (string)$row['name'] : '';
      $target = isset($row['target']) ? (string)$row['target'] : '';
      if ($name !== $username && strpos($target, $username) === false) continue;
      foreach (array('bytes', 'bytes-in', 'bytes-out') as $key) if (isset($row[$key])) { $parts = explode('/', (string)$row[$key]); foreach ($parts as $part) $total += (float)preg_replace('/[^0-9.]/', '', $part); }
    }
    return $total;
  }
  function pppoeMonthlyUsageGb($api, $routerSession, $username, $period) {
    $current = pppoeReadQueueCounters($api, $username); $all = pppoeUsageRead(); $key = $routerSession . '|' . $username . '|' . $period; $previous = isset($all[$key]['counter_bytes']) ? (float)$all[$key]['counter_bytes'] : $current; $usage = max(0, $current - $previous); $all[$key] = array('counter_bytes' => $current, 'usage_gb' => pppoeBytesToGb($usage), 'updated_at' => date('Y-m-d H:i:s')); pppoeUsageWrite($all); return pppoeBytesToGb($usage); }
}
?>
