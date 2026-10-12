<?php
if (!isset($_SESSION['mikhmon'])) { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/free_radius_client.php';
require_once __DIR__ . '/../include/billing.php';
$dashboardError = '';
$users = $active = $history = $invoices = array();
$usage = 0;
try {
    $radius = new RadiusClient();
    $users = $radius->listUsers();
    $nas = (string)(mikhmonRadiusConfig()['radius_nas_ip'] ?? '');
    $query = $radius->db->prepare('SELECT username, acctsessionid, framedipaddress, acctstarttime, acctupdatetime, acctstoptime, acctinputoctets, acctoutputoctets FROM radacct WHERE nasipaddress = ? ORDER BY radacctid DESC LIMIT 100');
    $query->bind_param('s', $nas);
    $query->execute();
    $history = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $query->close();
    $query = $radius->db->prepare('SELECT COUNT(*) FROM radacct WHERE nasipaddress = ? AND acctstoptime IS NULL');
    $query->bind_param('s', $nas);
    $query->execute();
    $activeCount = (int)$query->get_result()->fetch_row()[0];
    $query->close();
    $usage = array_sum($radius->getMonthlyUsageMap(date('Y-m')));
    $invoices = billingReadInvoices();
} catch (Throwable $e) {
    $dashboardError = 'Data belum tersedia. Jalankan preflight CT dan periksa log layanan RADIUS.';
    error_log('RADIUS dashboard: ' . $e->getMessage());
} finally {
    if (isset($radius)) $radius->disconnect();
}
$unpaid = count(array_filter($invoices, function($invoice) { return !in_array($invoice['status'] ?? '', array('paid'), true); }));
$isolated = count(array_filter($users, function($user) { return ($user['profile'] ?? '') === 'ISOLIR'; }));
function radiusDashboardEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<div class="row"><div class="col-12"><div class="card">
<div class="card-header"><h3>Dashboard Full RADIUS</h3></div><div class="card-body">
<?php if ($dashboardError !== '') { ?><div class="bg-warning pd-10"><?= radiusDashboardEscape($dashboardError); ?></div><?php } else { ?>
<div class="row">
<?php foreach (array('Pelanggan' => count($users), 'Sesi belum menerima Stop' => $activeCount, 'Pemakaian bulan ini (GiB)' => number_format($usage / 1073741824, 2), 'Invoice belum lunas' => $unpaid, 'Pelanggan isolir' => $isolated) as $label => $value) { ?>
<div class="col-4"><div class="card bg-info"><div class="card-body"><h3><?= radiusDashboardEscape($value); ?></h3><?= radiusDashboardEscape($label); ?></div></div></div>
<?php } ?></div><?php } ?>
<p>Server ini mengelola satu NAS. Data sesi berasal dari accounting RADIUS; sesi tanpa Stop dapat sudah terputus. Periksa waktu update terakhir.</p>
<p>Isolir berlaku setelah jatuh tempo + 5 hari. Setelah pembayaran, scheduler memulihkan paket sesuai FUP dan meminta koneksi ulang melalui UDP 1700.</p>
<h4>Kesehatan tugas terjadwal</h4>
<table class="table table-bordered"><tr><th>Tugas</th><th>Aktivitas terakhir</th><th>Status</th></tr>
<?php foreach (array('Accounting' => 'radius-accounting.log', 'Paket / isolir / DM' => 'pppoe-automation.log', 'Invoice / antrean WA' => 'invoice-generation.log') as $job => $file) {
 $path = __DIR__ . '/../storage/' . $file; $modified = is_file($path) ? filemtime($path) : false;
?><tr><td><?= radiusDashboardEscape($job); ?></td><td><?= $modified ? date('Y-m-d H:i:s', $modified) : 'Belum ada'; ?></td><td><?= $modified && time() - $modified < 900 ? 'Log baru tersedia — periksa hasilnya' : 'Perlu diperiksa: log belum ada / >15 menit'; ?></td></tr><?php } ?></table>
<p>Waktu log bukan jaminan sukses. Periksa pesan gagal dan jalankan preflight bila ada peringatan.</p>
<h4>100 catatan accounting terbaru</h4><div style="overflow-x:auto"><table class="table table-bordered table-hover">
<thead><tr><th>Pelanggan</th><th>IP</th><th>Mulai</th><th>Update</th><th>Stop</th><th>Total byte</th></tr></thead><tbody>
<?php foreach ($history as $row) { ?><tr>
<?php foreach (array($row['username'], $row['framedipaddress'], $row['acctstarttime'], $row['acctupdatetime'], $row['acctstoptime'] ?: 'Belum diterima', (float)$row['acctinputoctets'] + (float)$row['acctoutputoctets']) as $value) { ?><td><?= radiusDashboardEscape($value); ?></td><?php } ?>
</tr><?php } ?>
<?php if (!$history) { ?><tr><td colspan="6">Belum ada accounting dari NAS ini.</td></tr><?php } ?>
</tbody></table></div></div></div></div></div>
