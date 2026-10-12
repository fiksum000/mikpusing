<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/wa_templates.php');
$templates = waTemplateRead(); $message = ''; $type = 'info';
if (isset($_POST['save_templates'])) {
  foreach (array_keys(waTemplateDefaults()) as $key) $templates[$key] = trim(isset($_POST['template_' . $key]) ? $_POST['template_' . $key] : '');
  if (waTemplateWrite($templates)) { $message = 'Template WhatsApp berhasil disimpan.'; $type = 'success'; } else { $message = 'Template gagal disimpan. Periksa permission folder storage.'; $type = 'danger'; }
}
$placeholderHelp = '{nama}, {invoice}, {periode}, {total}, {jatuh_tempo}, {link_bayar}, {referensi}, {product}, {device}, {tanggal_bayar}, {bukti_pembayaran}, {kontak_admin}, {nama_perusahaan}';
?>
<div class="row"><div class="col-12"><div class="card"><div class="card-header"><h3 class="card-title"><i class="fa fa-comments"></i> Template WhatsApp Billing</h3></div><div class="card-body">
<?php if ($message !== '') { ?><div class="bg-<?= $type; ?> pd-10 radius-3 mb-3"><?= $message; ?></div><?php } ?>
<p class="text-muted">Template digunakan otomatis untuk pelanggan PPPoE. Placeholder yang tersedia: <code><?= $placeholderHelp; ?></code></p>
<form method="post" action="./admin.php?id=wa-templates"><div class="row"><div class="col-6"><label>Invoice baru / penagihan</label><textarea class="form-control" name="template_invoice" rows="9" required="1"><?= htmlspecialchars($templates['invoice'], ENT_QUOTES, 'UTF-8'); ?></textarea></div><div class="col-6"><label>Reminder jatuh tempo</label><textarea class="form-control" name="template_reminder" rows="9" required="1"><?= htmlspecialchars($templates['reminder'], ENT_QUOTES, 'UTF-8'); ?></textarea></div><div class="col-6 mt-3"><label>Tagihan terlambat</label><textarea class="form-control" name="template_overdue" rows="9" required="1"><?= htmlspecialchars($templates['overdue'], ENT_QUOTES, 'UTF-8'); ?></textarea></div><div class="col-6 mt-3"><label>Pembayaran berhasil</label><textarea class="form-control" name="template_payment" rows="9" required="1"><?= htmlspecialchars($templates['payment'], ENT_QUOTES, 'UTF-8'); ?></textarea></div></div><div class="mt-3"><input class="group-item group-item-md" type="submit" name="save_templates" value="Simpan Template"/> <a class="group-item group-item-md" href="./admin.php?id=wa-templates">Reset Tampilan</a></div></form>
</div></div></div></div>
