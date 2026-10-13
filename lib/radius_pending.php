<?php
// Caller must hold pppoe-profile-automation.lock before mutation + queue update.
function radiusQueueReconnect($username, $target) {
    $path = getenv('MIKHMON_RADIUS_PENDING_FILE') ?: __DIR__ . '/../storage/radius-kick-pending.json';
    $pending = is_file($path) ? json_decode(file_get_contents($path), true) : array();
    if (!is_array($pending)) throw new RuntimeException('Antrean disconnect rusak; pulihkan backup.');
    $pending[$username] = array('target' => $target, 'requested_at' => date('c'));
    $json = json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $tmp = $path . '.tmp.' . getmypid();
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) throw new RuntimeException('Tidak dapat menyimpan antrean disconnect.');
}
