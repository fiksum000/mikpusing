<?php
// Full RADIUS front controller. Only explicitly registered management routes run.
session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ob_start();
$id = (string)($_GET['id'] ?? 'sessions');
$session = ''; $url = (string)($_SERVER['REQUEST_URI'] ?? '');
if (!is_file(__DIR__ . '/include/config.php')) { http_response_code(503); exit('Konfigurasi belum tersedia. Jalankan installer Full RADIUS di CT.'); }
require __DIR__ . '/include/config.php';
require_once __DIR__ . '/lib/decrypt_helper.php';
if ($id === 'tripay-callback') {
    session_write_close();
    require __DIR__ . '/settings/tripay-callback.php';
    exit;
}
if ($id === 'logout') { $_SESSION = array(); session_destroy(); header('Location: ./admin.php?id=login'); exit; }
$useradm = explode('<|<', (string)($data['mikhmon'][1] ?? ''))[1] ?? '';
$passadm = explode('>|>', (string)($data['mikhmon'][2] ?? ''))[1] ?? '';
$error = '';
if ($id === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($useradm !== '' && hash_equals($useradm, (string)($_POST['user'] ?? '')) && hash_equals(decrypt($passadm), (string)($_POST['pass'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['mikhmon'] = $useradm;
        header('Location: ./admin.php?id=sessions'); exit;
    }
    $error = '<div class="bg-danger pd-10">Username atau password salah.</div>';
}
if ($id !== 'login' && !isset($_SESSION['mikhmon'])) { header('Location: ./admin.php?id=login'); exit; }
if ($id === 'settings' || $id === 'connect') { header('Location: ./admin.php?id=radius-nas'); exit; }
$routes = array('sessions', 'radius-nas', 'radius-dashboard', 'radius-accounting', 'radius-users', 'bulk-import', 'pppoe-packages', 'billing', 'billing-integrations', 'tripay-settings', 'fonnte', 'fonnte-log', 'wa-templates');
if ($id !== 'login' && !in_array($id, $routes, true)) { http_response_code(404); exit('Halaman tidak tersedia pada Full RADIUS.'); }
require __DIR__ . '/lang/isocodelang.php';
require __DIR__ . '/include/lang.php';
require __DIR__ . '/lang/' . $langid . '.php';
require __DIR__ . '/include/quickbt.php';
require __DIR__ . '/include/theme.php';
$hotspotname = 'Full RADIUS'; $idleto = 'disable';
require __DIR__ . '/include/headhtml.php';
if ($id === 'login') {
    require __DIR__ . '/include/login.php';
} else {
    require __DIR__ . '/include/menu.php';
    try { require __DIR__ . '/settings/' . ($id === 'radius-accounting' ? 'radius-dashboard' : $id) . '.php'; }
    catch (Throwable $e) {
        error_log('Management page ' . $id . ': ' . $e->getMessage());
        http_response_code(500);
        echo '<div class="bg-danger pd-10">Operasi tidak selesai. Periksa log aplikasi dan preflight CT sebelum mencoba lagi.</div>';
    }
    echo '</div>';
}
?>
<?php if ($id !== 'login') { ?><script src="js/mikhmon-ui.<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8'); ?>.min.js"></script><?php } ?>
<script src="js/mikhmon.js"></script>
</div></body></html>
