<?php
/**
 * Scheduled profile automation entry point.
 * Full RADIUS is the only enabled backend: changes are made in radusergroup
 * and active sessions are disconnected with RFC 5176 Disconnect-Message.
 * RouterOS API credentials and connections are never used by this script.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');

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

// $argv is intentionally preserved, so the installer cron's --apply argument
// reaches radius_profile_automation.php without enabling any legacy fallback.
require __DIR__ . '/radius_profile_automation.php';
?>