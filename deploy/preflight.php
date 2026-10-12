<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/free_radius_client.php';
$failures = 0;
function checkRadiusPreflight($ok, $label) {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures++;
}
foreach (array('mysqli', 'curl', 'mbstring') as $extension) checkRadiusPreflight(extension_loaded($extension), 'PHP ' . $extension);
checkRadiusPreflight(is_writable(__DIR__ . '/../storage'), 'storage dapat ditulis oleh cron/web');
checkRadiusPreflight(is_readable(__DIR__ . '/../include/config.php'), 'konfigurasi admin dapat dibaca');
$config = mikhmonRadiusConfig();
checkRadiusPreflight(($config['ppp_auth_backend'] ?? '') === 'radius' && ($config['disconnect_mode'] ?? '') === 'radius-dm', 'Full RADIUS / DM');
checkRadiusPreflight(filter_var($config['radius_nas_ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false, 'IPv4 NAS');
checkRadiusPreflight(preg_match('/^[A-Za-z0-9._-]{24,128}$/', $config['radius_client_secret'] ?? '') === 1, 'shared secret tersedia (tidak ditampilkan)');
checkRadiusPreflight((int)($config['radius_disconnect_port'] ?? 0) === 1700, 'DM UDP 1700');
checkRadiusPreflight(function_exists('proc_open') && is_executable($config['radclient_path'] ?? '/usr/bin/radclient'), 'radclient dan proc_open');
try {
    $radius = new RadiusClient();
    foreach (array('radcheck', 'radreply', 'radusergroup', 'radgroupreply', 'radacct') as $table) {
        $radius->db->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        checkRadiusPreflight(true, 'SQL ' . $table);
    }
    $radius->db->query('SELECT acctsessionid, nasipaddress, acctupdatetime, acctinputoctets, acctoutputoctets FROM radacct LIMIT 1');
    checkRadiusPreflight(true, 'skema accounting standar FreeRADIUS');
    $groups = $radius->getGroupAttributes();
    checkRadiusPreflight(($groups['ISOLIR']['Mikrotik-Address-List'] ?? '') === 'MIKPUSING-ISOLIR', 'atribut group ISOLIR');
    $radius->disconnect();
} catch (Throwable $e) { checkRadiusPreflight(false, 'SQL/config: ' . $e->getMessage()); }
foreach (glob(__DIR__ . '/../storage/*.json') ?: array() as $path) {
    json_decode(file_get_contents($path), true);
    checkRadiusPreflight(json_last_error() === JSON_ERROR_NONE, 'JSON ' . basename($path));
}
exit($failures ? 1 : 0);
