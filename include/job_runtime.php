<?php
function mikhmonJobLock($name) {
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $lock = fopen($dir . '/' . $name . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Cannot open job lock');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }
    register_shutdown_function(function() use ($lock) { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } });
    return $lock;
}
function mikhmonBatchLimit() { return max(1, min(100, (int)(getenv('MIKHMON_DELIVERY_BATCH') ?: 25))); }
function mikhmonBatchExpired($started, $attempted) { return $attempted >= mikhmonBatchLimit() || microtime(true) - $started >= 180; }
