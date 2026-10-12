<?php
if (!isset($_SESSION['mikhmon'])) { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/bulk_import.php';
require_once __DIR__ . '/../include/job_runtime.php';
if (empty($_SESSION['import_csrf'])) $_SESSION['import_csrf'] = bin2hex(random_bytes(32));
$message = ''; $type = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lock = null;
    try {
        if (!hash_equals($_SESSION['import_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Token keamanan tidak cocok.');
        $upload = $_FILES['csv_file'] ?? array();
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Upload CSV gagal.');
        $lock = mikhmonJobLock('pppoe-profile-automation');
        if (!$lock) throw new RuntimeException('Scheduler sedang berjalan; coba lagi setelah selesai.');
        $message = importCustomersFromCsv($upload['tmp_name']) . ' pelanggan diimpor. Perubahan sesi masuk antrean disconnect.';
        $type = 'success';
    } catch (Throwable $e) { $message = $e->getMessage(); $type = 'danger'; }
    finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
}
?>
<div class="card"><div class="card-header"><h3>Impor pelanggan PPPoE / Hotspot</h3></div><div class="card-body">
<p>CSV maksimal 1000 pelanggan / 2 MB. Paket harus dibuat dahulu. Password kosong mempertahankan password user yang sudah ada. Username sama memperbarui data pelanggan tersebut.</p>
<pre>username,password,name,phone,email,profile,amount,due_day
pelanggan-tes,password-tes-123,Pelanggan Tes,628123456789,,PPPOE-10M,100000,20</pre>
<p>CSV berisi password pelanggan. Simpan privat dan hapus salinan upload setelah migrasi. Validasi dilakukan sebelum menulis; kegagalan layanan saat proses dapat menghasilkan impor sebagian dan dilaporkan.</p>
<?php if ($message !== '') { ?><div class="bg-<?= $type; ?> pd-10"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['import_csrf'], ENT_QUOTES, 'UTF-8'); ?>"/><input type="file" name="csv_file" accept=".csv" required/><button class="group-item" type="submit">Validasi & impor</button></form>
</div></div>
