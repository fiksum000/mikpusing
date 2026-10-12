<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');
$lockPath = __DIR__ . '/../storage/radius-accounting-snapshot.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir) && !@mkdir($lockDir, 0750, true) && !is_dir($lockDir)) {
    fwrite(STDERR, date('c') . " Cannot create accounting lock directory.\\n");
    exit(1);
}
$lockHandle = @fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (is_resource($lockHandle)) fclose($lockHandle);
    echo date('c') . " RADIUS accounting sample skipped: another run is active.\\n";
    exit(0);
}
register_shutdown_function(function() use ($lockHandle) {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
});
require_once __DIR__ . '/../lib/free_radius_client.php';
try {
    $radius = new RadiusClient();
    $result = $radius->sampleAccountingCounters(date('Y-m'));
    echo date('c') . ' RADIUS accounting sample: ' . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $radius->disconnect();
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' RADIUS accounting sample FAILED: ' . preg_replace('/\s+/', ' ', $e->getMessage()) . PHP_EOL);
    exit(1);
}
?>
