<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
require_once('./lib/free_radius_client.php');
require_once('./lib/radius_pending.php');
require_once('./include/job_runtime.php');
$userChangeLock = null;

if (empty($_SESSION['radius_users_csrf'])) $_SESSION['radius_users_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['radius_users_csrf'];
$message = ''; $messageType = 'info'; $radius = null; $groups = array(); $users = array();
$filter = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 50;

try {
  $radius = new RadiusClient();
  $groups = $radius->listGroups();
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
      $message = 'Permintaan ditolak: token keamanan tidak cocok. Muat ulang halaman.'; $messageType = 'danger';
    } else {
      $userChangeLock = mikhmonJobLock('pppoe-profile-automation');
      if (!$userChangeLock) throw new RuntimeException('Scheduler sedang berjalan. Coba lagi setelah selesai.');
      $action = (string)($_POST['action'] ?? '');
      $username = trim((string)($_POST['username'] ?? ''));
      if ($action === 'save') {
        $password = (string)($_POST['password'] ?? '');
        $group = trim((string)($_POST['groupname'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $username)) {
          throw new InvalidArgumentException('Username hanya boleh berisi huruf, angka, titik, garis bawah, @, dan tanda minus (maks. 64 karakter).');
        }
        if (!in_array($group, $groups, true)) throw new InvalidArgumentException('Pilih group RADIUS yang sudah memiliki atribut reply.');
        if ($password !== '') {
          if (strlen($password) < 8) throw new InvalidArgumentException('Password minimal 8 karakter.');
          radiusQueueReconnect($username, $group);
          $radius->upsertUser($username, $password, $group);
          $message = 'User RADIUS tersimpan. Password tidak ditampilkan kembali.'; $messageType = 'success';
        } else {
          $existing = false;
          foreach ($radius->listUsers() as $row) if (($row['username'] ?? '') === $username) { $existing = true; break; }
          if (!$existing) throw new InvalidArgumentException('Password wajib diisi untuk user baru.');
          radiusQueueReconnect($username, $group);
          $radius->setProfile($username, $group);
          $message = 'Group user RADIUS berhasil diperbarui. Password lama tidak diubah.'; $messageType = 'success';
        }
      } elseif ($action === 'delete') {
        if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $username)) throw new InvalidArgumentException('Username tidak valid.');
        radiusQueueReconnect($username, 'deleted');
        $radius->deleteUser($username);
        $message = 'User RADIUS dihapus dari tabel autentikasi. Pemutusan sesi aktif masuk antrean scheduler (maksimal satu siklus saat layanan sehat).'; $messageType = 'success';
      }
    }
  }
  $users = $radius->listUsers();
  if ($filter !== '') {
    $users = array_values(array_filter($users, function($user) use ($filter) {
      return stripos((string)($user['username'] ?? ''), $filter) !== false || stripos((string)($user['profile'] ?? ''), $filter) !== false;
    }));
  }
} catch (Throwable $e) {
  $message = 'RADIUS belum siap: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'); $messageType = 'danger';
}
if (is_resource($userChangeLock)) { flock($userChangeLock, LOCK_UN); fclose($userChangeLock); }
if ($radius instanceof RadiusClient) $radius->disconnect();
$total = count($users);
$pageCount = max(1, (int)ceil($total / $pageSize));
$page = min($page, $pageCount);
$visible = array_slice($users, ($page - 1) * $pageSize, $pageSize);
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3 class="card-title"><i class="fa fa-users"></i> User MikroTik RADIUS</h3></div>
  <div class="card-body">
    <?php if ($message !== '') { ?><div class="bg-<?= $messageType; ?> pd-10 mb-3"><?= $message; ?></div><?php } ?>
    <p class="text-muted">User autentikasi terpusat PPPoE/Hotspot di FreeRADIUS + MariaDB. Password tidak pernah ditampilkan. Group harus sudah dikonfigurasi di <code>radgroupreply</code>.</p>
    <?php if (count($groups) === 0) { ?><div class="bg-warning pd-10 mb-3">Belum ada group RADIUS di <code>radgroupreply</code>. Tambahkan group paket dahulu sebelum membuat user.</div><?php } ?>
    <form method="post" action="./admin.php?id=radius-users" class="row mb-4">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"/>
      <input type="hidden" name="action" value="save"/>
      <div class="col-md-3 mb-2"><label>Username</label><input class="form-control" name="username" maxlength="64" pattern="[A-Za-z0-9_.@-]{1,64}" required/></div>
      <div class="col-md-3 mb-2"><label>Password baru</label><input class="form-control" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Minimal 8 karakter"/></div>
      <div class="col-md-3 mb-2"><label>Group / Paket</label><select class="form-control" name="groupname" required><?php foreach ($groups as $group) { ?><option value="<?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></option><?php } ?></select></div>
      <div class="col-md-3 mb-2 align-self-end"><button class="group-item group-item-md" type="submit" <?= count($groups) === 0 ? 'disabled' : ''; ?>>Simpan user / password</button></div>
    </form>
    <form method="get" action="./admin.php" class="row mb-3">
      <input type="hidden" name="id" value="radius-users"/>
      <div class="col-md-5"><input class="form-control" name="q" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Cari username atau group"/></div>
      <div class="col-md-2"><button class="group-item group-item-md">Cari</button></div>
      <div class="col-md-5 text-muted text-right align-self-center"><?= number_format($total); ?> user · halaman <?= $page; ?>/<?= $pageCount; ?></div>
    </form>
    <div class="table-responsive"><table class="table table-sm table-striped">
      <thead><tr><th>Username</th><th>Group</th><th>Ubah group</th><th>Hapus</th></tr></thead><tbody>
      <?php if (!$visible) { ?><tr><td colspan="4" class="text-center text-muted">Tidak ada user untuk ditampilkan.</td></tr><?php } else { foreach ($visible as $user) { ?>
        <tr><td><code><?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><?= htmlspecialchars((string)$user['profile'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><form method="post" action="./admin.php?id=radius-users" class="form-inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"/><input type="hidden" name="action" value="save"/><input type="hidden" name="username" value="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8'); ?>"/><input type="hidden" name="password" value=""/><select class="form-control form-control-sm mr-2" name="groupname"><?php foreach ($groups as $group) { ?><option value="<?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>" <?= $user['profile'] === $group ? 'selected' : ''; ?>><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></option><?php } ?></select><button class="group-item group-item-sm" <?= count($groups)===0?'disabled':''; ?>>Simpan</button></form></td>
        <td><form method="post" action="./admin.php?id=radius-users" onsubmit="return confirm('Hapus user RADIUS ini?');"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"/><input type="hidden" name="action" value="delete"/><input type="hidden" name="username" value="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8'); ?>"/><button class="group-item group-item-sm" type="submit">Hapus</button></form></td></tr>
      <?php }} ?>
      </tbody></table></div>
    <div class="mt-3"><?php if ($page > 1) { ?><a class="group-item group-item-sm" href="./admin.php?id=radius-users&amp;q=<?= rawurlencode($filter); ?>&amp;page=<?= $page-1; ?>">Sebelumnya</a><?php } ?> <?php if ($page < $pageCount) { ?><a class="group-item group-item-sm" href="./admin.php?id=radius-users&amp;q=<?= rawurlencode($filter); ?>&amp;page=<?= $page+1; ?>">Berikutnya</a><?php } ?></div>
  </div>
</div></div></div>
