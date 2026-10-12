<?php
// Run from Linux cron or Windows Task Scheduler. Example: php cron/fonnte_log_cleanup.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../include/fonnte_log.php';
$removed = fonntePurgeOldLogs(30);
echo date('c') . " Fonnte log cleanup: " . $removed . " log(s) removed; retention=30 days\n";
?>
