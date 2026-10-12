<?php
if (!function_exists('waTemplatePath')) {
  function waTemplatePath() { return __DIR__ . '/../storage/wa_templates.json'; }
  function waTemplateDefaults() { return array(
    'invoice' => "Halo {nama},\n\nTagihan internet PPPoE Anda sudah tersedia.\nInvoice: {invoice}\nPeriode: {periode}\nTotal: {total}\nJatuh tempo: {jatuh_tempo}\n\nBayar di sini: {link_bayar}\n\nTerima kasih.",
    'reminder' => "Pesan Otomatis. Nomor ini tidak bisa menerima telepon.\n\nPengingat: internet Anda akan segera jatuh tempo/isolir\n\nInvoice No: {invoice}\nProduct: {product}\nStatus: Belum Dibayar\nDevice: {device}\n\nJumlah Tagihan: {total}\nTanggal Jatuh Tempo: {jatuh_tempo}\n\nAnda dapat melihat dan membayar faktur melalui tautan berikut:\n{link_bayar}\n\nMohon segera selesaikan pembayaran Anda untuk menghindari pemutusan layanan. Terima kasih.\n\nSalam hormat,\n{nama_perusahaan}",
    'overdue' => "Pesan Otomatis. Nomor ini tidak bisa menerima telepon.\n\nPemberitahuan: layanan internet Anda masuk masa isolir karena tagihan belum dibayar.\n\nInvoice No: {invoice}\nProduct: {product}\nStatus: Belum Dibayar\nDevice: {device}\n\nJumlah Tagihan: {total}\nTanggal Jatuh Tempo: {jatuh_tempo}\n\nSegera lakukan pembayaran melalui tautan berikut:\n{link_bayar}\n\nSalam hormat,\n{nama_perusahaan}",
    'payment' => "Pesan otomatis — nomor ini tidak bisa menerima telepon.\n\nKonfirmasi Pembayaran\n\nInvoice No: {invoice}\nProduct: {product}\nStatus: Lunas\nDevice: {device}\n\nJumlah Dibayar: {total}\nTanggal Bayar: {tanggal_bayar}\n\nAnda dapat melihat bukti pembayaran melalui tautan berikut:\n{bukti_pembayaran}\n\nNomor ini tidak bisa menerima pesan atau telepon.\n\nKELUHAN DAN SARAN CHAT {kontak_admin}\n\nTerima kasih atas pembayaran Anda! Proses layanan Anda telah berhasil diaktifkan, dan layanan kini siap digunakan.\n\nJika Anda memiliki pertanyaan atau membutuhkan bantuan lebih lanjut, silakan hubungi kami. Kami siap membantu Anda.\n\nRegards,\n{nama_perusahaan}"
  ); }
  function waTemplateRead() { $defaults = waTemplateDefaults(); if (!file_exists(waTemplatePath())) return $defaults; $rows = json_decode(@file_get_contents(waTemplatePath()) ?: '{}', true); return is_array($rows) ? array_merge($defaults, $rows) : $defaults; }
  function waTemplateWrite($rows) { $dir = dirname(waTemplatePath()); if (!is_dir($dir)) @mkdir($dir, 0750, true); return @file_put_contents(waTemplatePath(), json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false; }
  function waTemplateRender($key, $data) { $templates = waTemplateRead(); $message = isset($templates[$key]) ? $templates[$key] : ''; foreach ($data as $placeholder => $value) $message = str_replace('{' . $placeholder . '}', (string)$value, $message); return $message; }
}
?>
