<?php
/**
 * Install/update the HTTP redirect rule on every RouterOS session configured in
 * include/config.php. Dry-run by default; use --apply to modify router firewall.
 *
 * Usage: php deploy/configure-isolir-routeros.php --portal-ip 192.168.1.10 [--apply]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../include/config.php';

$options = getopt('', array('portal-ip:', 'apply'));
$portalIp = trim((string)($options['portal-ip'] ?? ''));
$apply = array_key_exists('apply', $options);
if (!filter_var($portalIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    fwrite(STDERR, "Wajib isi --portal-ip dengan IPv4 CT yang bisa dijangkau router.\n");
    exit(2);
}
$natComment = 'mikpusing-isolir-portal';
$filterComment = 'mikpusing-isolir-portal-allow';
$routers = 0; $updated = 0; $errors = 0;

function mikhmonParseRouterCredentialsForIsolir($sessionData) {
    if (!is_array($sessionData) || count($sessionData) < 4) return null;
    $hostField = explode('!', (string)$sessionData[1], 2);
    $userField = explode('@|@', (string)$sessionData[2], 2);
    $passField = explode('#|#', (string)$sessionData[3], 2);
    if (count($hostField) < 2 || count($userField) < 2 || count($passField) < 2) return null;
    $endpoint = trim($hostField[1]); $routerUser = trim($userField[1]);
    if ($endpoint === '' || $routerUser === '') return null;
    $host = $endpoint; $port = 8728;
    if (preg_match('/^\[(.+)\]:(\d+)$/', $endpoint, $m)) { $host = $m[1]; $port = (int)$m[2]; }
    elseif (preg_match('/^([^:]+):(\d+)$/', $endpoint, $m)) { $host = $m[1]; $port = (int)$m[2]; }
    if ($host === '' || $port < 1 || $port > 65535) return null;
    return array($host, $port, $routerUser, decrypt((string)$passField[1]));
}

foreach ($data as $sessionName => $sessionData) {
    if ($sessionName === 'mikhmon' || !is_array($sessionData)) continue;
    $credentials = mikhmonParseRouterCredentialsForIsolir($sessionData);
    if ($credentials === null) { $errors++; echo "ERROR {$sessionName}: konfigurasi tidak valid\n"; continue; }
    list($host, $port, $user, $password) = $credentials;
    $api = new RouterosAPI();
    $api->debug = false; $api->attempts = 1; $api->delay = 1; $api->timeout = 3; $api->port = $port;
    if (!$api->connect($host, $user, $password)) {
        $errors++; echo "ERROR {$sessionName}: koneksi RouterOS gagal ({$host}:{$port})\n"; continue;
    }
    $routers++;
    try {
        $natRows = $api->comm('/ip/firewall/nat/print', array('?comment' => $natComment));
        $natItem = array(
            'chain' => 'dstnat',
            'src-address-list' => 'MIKPUSING-ISOLIR',
            'dst-address-type' => '!local',
            'protocol' => 'tcp',
            'dst-port' => '80',
            'action' => 'dst-nat',
            'to-addresses' => $portalIp,
            'to-ports' => '8081',
            'comment' => $natComment,
            'disabled' => 'no'
        );
        if (!$apply) {
            echo "DRY-RUN {$sessionName}: NAT HTTP isolir -> {$portalIp}:8081\n";
            echo "DRY-RUN {$sessionName}: izinkan port 8081 hanya untuk source list MIKPUSING-ISOLIR\n";
            continue;
        }
        if (is_array($natRows) && !isset($natRows['!trap']) && count($natRows) > 0) {
            foreach ($natRows as $natRow) {
                if (empty($natRow['.id'])) continue;
                $result = $api->comm('/ip/firewall/nat/set', array_merge(array('.id' => $natRow['.id']), $natItem));
                if (is_array($result) && isset($result['!trap'])) { $errors++; echo "ERROR {$sessionName}: update NAT gagal\n"; }
                else $updated++;
            }
        } else {
            $result = $api->comm('/ip/firewall/nat/add', array_merge($natItem, array('place-before' => '0')));
            if (is_array($result) && isset($result['!trap'])) { $errors++; echo "ERROR {$sessionName}: tambah NAT gagal\n"; }
            else $updated++;
        }

        $filterRows = $api->comm('/ip/firewall/filter/print', array('?comment' => $filterComment));
        $filterItem = array(
            'chain' => 'forward',
            'src-address-list' => 'MIKPUSING-ISOLIR',
            'dst-address' => $portalIp,
            'protocol' => 'tcp',
            'dst-port' => '8081',
            'action' => 'accept',
            'comment' => $filterComment,
            'disabled' => 'no'
        );
        if (is_array($filterRows) && !isset($filterRows['!trap']) && count($filterRows) > 0) {
            foreach ($filterRows as $filterRow) {
                if (empty($filterRow['.id'])) continue;
                $result = $api->comm('/ip/firewall/filter/set', array_merge(array('.id' => $filterRow['.id']), $filterItem));
                if (is_array($result) && isset($result['!trap'])) { $errors++; echo "ERROR {$sessionName}: update filter allow gagal\n"; }
            }
        } else {
            $result = $api->comm('/ip/firewall/filter/add', array_merge($filterItem, array('place-before' => '0')));
            if (is_array($result) && isset($result['!trap'])) { $errors++; echo "ERROR {$sessionName}: tambah filter allow gagal\n"; }
        }
        echo "OK {$sessionName}: redirect HTTP isolir dikonfigurasi\n";
    } catch (Throwable $e) {
        $errors++; echo "ERROR {$sessionName}: " . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n";
    }
    $api->disconnect();
}
if ($routers === 0) { fwrite(STDERR, "Tidak ada router Mikhmon yang berhasil dihubungi.\n"); exit(1); }
echo date('c') . " isolir portal router setup routers={$routers} changes={$updated} errors={$errors} mode=" . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
if ($errors > 0) exit(1);
?>
