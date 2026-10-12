<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); exit; }
include_once('./include/fonnte_log.php');
$logs = fonnteReadLogs();
$statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';
$eventFilter = isset($_GET['event']) ? strtolower(trim($_GET['event'])) : '';
$searchFilter = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';
$filtered = array();
foreach ($logs as $log) {
  if ($statusFilter !== '' && strtolower(isset($log['status']) ? $log['status'] : '') !== $statusFilter) continue;
  if ($eventFilter !== '' && strtolower(isset($log['event']) ? $log['event'] : '') !== $eventFilter) continue;
  $haystack = strtolower(json_encode($log));
  if ($searchFilter !== '' && strpos($haystack, $searchFilter) === false) continue;
  $filtered[] = $log;
}
$sentCount = 0; $failedCount = 0;
foreach ($logs as $log) { if (isset($log['status']) && $log['status'] === 'sent') $sentCount++; if (isset($log['status']) && $log['status'] === 'failed') $failedCount++; }
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fa fa-whatsapp"></i> Log Pengiriman WhatsApp Fonnte</h3><small class="text-muted">Retensi otomatis: 30 hari</small></div>
      <div class="card-body">
        <div class="row mb-3">
          <div class="col-4"><div class="card"><div class="card-body"><small>Total log</small><h3><?= count($logs); ?></h3></div></div></div>
          <div class="col-4"><div class="card"><div class="card-body"><small class="text-green">Berhasil</small><h3 class="text-green"><?= $sentCount; ?></h3></div></div></div>
          <div class="col-4"><div class="card"><div class="card-body"><small class="text-red">Gagal</small><h3 class="text-red"><?= $failedCount; ?></h3></div></div></div>
        </div>
        <form method="get" action="./admin.php" class="row mb-3">
          <input type="hidden" name="id" value="fonnte-log"/>
          <div class="col-3"><input class="form-control" name="q" value="<?= htmlspecialchars($searchFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Cari target / invoice"/></div>
          <div class="col-3"><select class="form-control" name="status"><option value="">Semua status</option><option value="sent" <?= $statusFilter === 'sent' ? 'selected' : ''; ?>>Berhasil</option><option value="failed" <?= $statusFilter === 'failed' ? 'selected' : ''; ?>>Gagal</option></select></div>
          <div class="col-3"><select class="form-control" name="event"><option value="">Semua event</option><option value="invoice" <?= $eventFilter === 'invoice' ? 'selected' : ''; ?>>Invoice</option><option value="payment" <?= $eventFilter === 'payment' ? 'selected' : ''; ?>>Pembayaran</option><option value="manual" <?= $eventFilter === 'manual' ? 'selected' : ''; ?>>Manual</option></select></div>
          <div class="col-3"><input class="group-item group-item-md" type="submit" value="Filter"/> <a class="group-item group-item-md" href="./admin.php?id=fonnte-log">Reset</a></div>
        </form>
        <div class="alert bg-info">Log yang lebih lama dari 30 hari akan dihapus otomatis saat halaman dibuka atau saat pesan baru dicatat. Untuk server tanpa aktivitas, jadwalkan <code>php cron/fonnte_log_cleanup.php</code> setiap hari.</div>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Waktu</th><th>Status</th><th>Event</th><th>Target</th><th>Invoice</th><th>Pesan</th><th>Detail</th></tr></thead><tbody>
        <?php if (count($filtered) === 0) { ?><tr><td colspan="7" class="text-center text-muted">Belum ada log pengiriman.</td></tr><?php } else { foreach ($filtered as $log) { $ok = isset($log['status']) && $log['status'] === 'sent'; ?><tr><td class="text-nowrap"><?= htmlspecialchars(isset($log['time']) ? $log['time'] : '-', ENT_QUOTES, 'UTF-8'); ?></td><td><span class="<?= $ok ? 'text-green' : 'text-red'; ?>"><i class="fa <?= $ok ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i> <?= $ok ? 'Berhasil' : 'Gagal'; ?></span></td><td><?= htmlspecialchars(isset($log['event']) ? $log['event'] : '-', ENT_QUOTES, 'UTF-8'); ?></td><td><?= htmlspecialchars(isset($log['target']) ? $log['target'] : '-', ENT_QUOTES, 'UTF-8'); ?></td><td><?= htmlspecialchars(isset($log['invoice']) ? $log['invoice'] : '-', ENT_QUOTES, 'UTF-8'); ?></td><td><?= htmlspecialchars(isset($log['message']) ? $log['message'] : '-', ENT_QUOTES, 'UTF-8'); ?></td><td><?= htmlspecialchars(isset($log['detail']) ? $log['detail'] : '-', ENT_QUOTES, 'UTF-8'); ?></td></tr><?php }} ?>
        </tbody></table></div>
      </div>
    </div>
  </div>
</div>
