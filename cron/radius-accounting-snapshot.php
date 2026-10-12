<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');
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
