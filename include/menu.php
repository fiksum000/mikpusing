<?php
if (!isset($_SESSION['mikhmon'])) { http_response_code(403); exit; }
require_once __DIR__ . '/radius_navigation.php';
$navigation = radiusNavigation((string)$id);
$mpage = $navigation[$id][1] ?? 'Full RADIUS';
$idleto = 'disable';
?>
<span style="display:none" id="idto">disable</span>
<div id="navbar" class="navbar">
  <div class="navbar-left">
    <a id="brand" class="text-center" href="./admin.php?id=sessions">MIKPUSING</a>
    <a id="openNav" class="navbar-hover" href="javascript:void(0)"><i class="fa fa-bars"></i></a>
    <a id="closeNav" class="navbar-hover" href="javascript:void(0)"><i class="fa fa-bars"></i></a>
    <a id="cpage" class="navbar-left"><?= htmlspecialchars($mpage, ENT_QUOTES, 'UTF-8'); ?></a>
  </div>
  <div class="navbar-right"><a href="./admin.php?id=logout"><i class="fa fa-sign-out"></i> Logout</a></div>
</div>
<div id="sidenav" class="sidenav">
<?php foreach ($navigation as $route => $item) { ?>
  <a href="./admin.php?id=<?= $route; ?>" class="menu <?= $id === $route ? 'active' : ''; ?>"><i class="fa fa-<?= $item[0]; ?>"></i> <?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8'); ?></a>
<?php } ?>
</div>
<div id="notify"><div class="message"></div></div><div id="temp"></div>

<div id="main" class="main">
