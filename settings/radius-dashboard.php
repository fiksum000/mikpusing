<?php
if (!isset($_SESSION['mikhmon'])) { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/free_radius_client.php';
require_once __DIR__ . '/../include/billing.php';
$dashboardError = '';
$users = $active = $history = $invoices = array();
$usage = 0; $activeCount = 0; $nas = ''; 
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
$periodInvoices = array_filter($invoices, function($invoice) { return ($invoice['period'] ?? '') === date('Y-m'); });
$paidTotal = $dueTotal = $paidCount = 0;
foreach ($periodInvoices as $invoice) {
    if (($invoice['status'] ?? '') === 'paid') { $paidTotal += max(0, (int)($invoice['amount'] ?? 0)); $paidCount++; }
    else { $dueTotal += max(0, (int)($invoice['amount'] ?? 0)); }
}
$paidPercent = count($periodInvoices) ? round(100 * $paidCount / count($periodInvoices)) : 0;
$accountingPage = $id === 'radius-accounting';
$visibleHistory = $accountingPage ? $history : array_slice($history, 0, 8);
?>
<section class="dashboard-shell">
<div class="page-heading"><div><div class="eyebrow">NETWORK OVERVIEW</div><h1><?= $accountingPage ? 'Sesi & accounting' : 'Dashboard'; ?><span class="title-dot">.</span></h1><p>Pantau pelanggan, pemakaian, dan billing dari satu tempat.</p></div><div class="heading-actions"><span class="date-chip"><i class="fa fa-calendar-o"></i> <?= date('d M Y'); ?></span><a class="button-primary" href="./admin.php?id=radius-users"><i class="fa fa-plus"></i> Kelola pelanggan</a></div></div>
<?php if ($dashboardError !== '') { ?><div class="dashboard-alert"><i class="fa fa-exclamation-circle"></i> <?= radiusDashboardEscape($dashboardError); ?></div><?php } ?>
<div class="metric-grid">
<?php $metrics = array(
 array('users','Total pelanggan',count($users),'Akun PPPoE & Hotspot','cyan','radius-users'),
 array('exchange','Sesi terbuka',$activeCount,'Belum menerima Accounting Stop','green','radius-accounting'),
 array('signal','Pemakaian bulan ini',number_format($usage / 1073741824, 2),'GiB · '.date('F Y'),'blue','radius-accounting'),
 array('file-text-o','Invoice belum lunas',$unpaid,'Seluruh periode tagihan','amber','billing'),
 array('lock','Pelanggan isolir',$isolated,'Group layanan ISOLIR','rose','radius-users'));
foreach ($metrics as $metric) { ?><a href="./admin.php?id=<?= $metric[5]; ?>" class="metric-card tone-<?= $metric[4]; ?>"><div class="metric-top"><span class="metric-icon"><i class="fa fa-<?= $metric[0]; ?>"></i></span><i class="fa fa-angle-right"></i></div><span class="metric-label"><?= $metric[1]; ?></span><strong class="metric-value"><?= $dashboardError === '' ? radiusDashboardEscape($metric[2]) : '—'; ?></strong><span class="metric-note"><?= $metric[3]; ?></span></a><?php } ?>
</div>
<?php if (!$accountingPage) { ?>
<div class="overview-grid">
<section class="dash-panel billing-overview"><div class="panel-heading"><div><span class="eyebrow">BILLING</span><h2>Tagihan bulan ini</h2></div><a class="text-link" href="./admin.php?id=billing">Lihat invoice <i class="fa fa-arrow-right"></i></a></div>
<div class="billing-total"><small>Nominal invoice lunas</small><strong><?= $dashboardError === '' ? 'Rp '.number_format($paidTotal,0,',','.') : '—'; ?></strong></div>
<div class="billing-progress-label"><span><?= $paidCount; ?> dari <?= count($periodInvoices); ?> invoice lunas</span><strong><?= $paidPercent; ?>%</strong></div><div class="billing-progress"><span style="width:<?= $paidPercent; ?>%"></span></div>
<div class="billing-footer"><span><i class="fa fa-circle"></i> Belum lunas</span><strong><?= $dashboardError === '' ? 'Rp '.number_format($dueTotal,0,',','.') : '—'; ?></strong></div>
<?php if (!$periodInvoices) { ?><p class="muted-note">Ringkasan akan terisi setelah invoice pertama dibuat.</p><?php } ?></section>
<section class="dash-panel network-panel"><div class="panel-heading"><div><span class="eyebrow">RADIUS WORKSPACE</span><h2>Router & layanan</h2></div><span class="protocol-badge">RouterOS v7</span></div>
<div class="network-route"><div class="network-node"><i class="fa fa-server"></i><span>Server RADIUS</span></div><div class="network-connector"><span>AUTH · ACCOUNTING</span><div></div><small>UDP 1812 / 1813</small></div><div class="network-node"><i class="fa fa-sitemap"></i><span>MikroTik NAS</span></div></div>
<div class="network-info"><span>NAS terdaftar</span><strong><?= radiusDashboardEscape($nas ?: 'Belum dikonfigurasi'); ?></strong></div>
<div class="quick-actions"><a href="./admin.php?id=pppoe-packages"><i class="fa fa-sliders"></i> Paket & FUP</a><a href="./admin.php?id=billing-integrations"><i class="fa fa-plug"></i> Integrasi & isolir</a></div>
<p class="muted-note">Konfigurasi tersimpan; status koneksi router perlu diverifikasi dari accounting.</p></section>
</div>
<?php } ?>
<section class="dash-panel activity-panel"><div class="panel-heading"><div><span class="eyebrow">AKTIVITAS JARINGAN</span><h2><?= $accountingPage ? '100 catatan accounting terbaru' : 'Sesi terbaru'; ?></h2></div><a class="text-link" href="./admin.php?id=radius-accounting">Semua sesi <i class="fa fa-arrow-right"></i></a></div>
<?php if (!$visibleHistory) { ?><div class="empty-state"><span class="empty-icon"><i class="fa fa-exchange"></i></span><h3>Belum ada aktivitas pelanggan</h3><p>Sesi akan tampil saat MikroTik mengirim accounting RADIUS.</p><a class="button-secondary" href="./admin.php?id=radius-users">Siapkan pelanggan pertama <i class="fa fa-arrow-right"></i></a></div><?php } else { ?>
<div class="table-scroll"><table class="table session-table"><thead><tr><th>Pelanggan</th><th>IP pelanggan</th><th>Mulai</th><th>Update terakhir</th><th>Status</th><th>Pemakaian</th></tr></thead><tbody>
<?php foreach ($visibleHistory as $row) { ?><tr><td><strong><?= radiusDashboardEscape($row['username']); ?></strong></td><td><?= radiusDashboardEscape($row['framedipaddress']); ?></td><td><?= radiusDashboardEscape($row['acctstarttime']); ?></td><td><?= radiusDashboardEscape($row['acctupdatetime']); ?></td><td><span class="state-badge <?= $row['acctstoptime'] ? 'neutral' : ''; ?>"><?= $row['acctstoptime'] ? 'Selesai' : 'Terbuka'; ?></span></td><td><?= number_format(((float)$row['acctinputoctets'] + (float)$row['acctoutputoctets']) / 1073741824, 2); ?> GiB</td></tr><?php } ?></tbody></table></div><?php } ?>
<div class="panel-footnote"><i class="fa fa-info-circle"></i> Sesi terbuka belum menerima Stop dan belum tentu masih tersambung.</div></section>
<section class="dash-panel jobs-panel"><div class="panel-heading"><div><span class="eyebrow">AUTOMASI</span><h2>Aktivitas tugas terjadwal</h2></div><span class="muted-note">Siklus 5 menit</span></div><div class="job-grid">
<?php foreach (array('Accounting' => 'radius-accounting.log', 'Paket & isolir' => 'pppoe-automation.log', 'Invoice & WhatsApp' => 'invoice-generation.log') as $job => $file) { $path=__DIR__.'/../storage/'.$file; $modified=is_file($path)?filemtime($path):false; $recent=$modified && time()-$modified<900; ?><div class="job-item"><span class="job-indicator <?= $recent ? '' : 'warning'; ?>"></span><div><strong><?= $job; ?></strong><small><?= $modified ? date('d M · H:i', $modified) : 'Belum ada aktivitas'; ?></small></div><span class="job-status"><?= $recent ? 'Log terbaru' : 'Periksa'; ?></span></div><?php } ?></div><div class="panel-footnote">Waktu aktivitas berasal dari log, bukan jaminan tugas berhasil.</div></section>
<footer class="dashboard-footer"><span>MIKPUSING <b>Full RADIUS</b></span><span>Diperbarui saat halaman dimuat · <?= date('H:i'); ?></span></footer>
</section>
