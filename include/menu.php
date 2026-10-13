<?php
if (!isset($_SESSION['mikhmon'])) { http_response_code(403); exit; }
require_once __DIR__ . '/radius_navigation.php';
$navigation = radiusNavigation((string)$id);
$mpage = $navigation[$id][1] ?? 'Full RADIUS';
$outside = in_array($id, array('sessions','radius-nas'), true);
$groups = array('radius-dashboard'=>'OVERVIEW','radius-users'=>'PELANGGAN & LAYANAN','billing'=>'KEUANGAN','fonnte'=>'KOMUNIKASI','billing-integrations'=>'PENGATURAN');
$labels = array('radius-users'=>'Pelanggan','bulk-import'=>'Impor pelanggan','radius-accounting'=>'Sesi & accounting','tripay-settings'=>'Payment gateway','sessions'=>$outside ? 'Admin Settings' : 'Admin Settings','radius-nas'=>'Add Router');
?>
<header id="navbar" class="navbar">
<a id="brand" href="./admin.php?id=sessions"><span class="brand-symbol"><i class="fa fa-bolt"></i></span> MIKPUSING<span class="brand-sub">RADIUS MANAGER</span></a>
<button id="navToggle" class="nav-toggle" aria-label="Buka menu" aria-controls="sidenav" aria-expanded="false"><i class="fa fa-bars"></i></button>
<div class="nav-breadcrumb">Workspace <span>/</span> <strong><?= htmlspecialchars($mpage, ENT_QUOTES, 'UTF-8'); ?></strong></div>
<a class="nav-logout" href="./admin.php?id=logout"><i class="fa fa-sign-out"></i><span> Keluar</span></a>
</header>
<aside id="sidenav" class="sidenav" aria-label="Menu utama">
<div class="workspace-label"><span class="workspace-avatar"><i class="fa fa-server"></i></span><div>Full RADIUS<small>PPPoE & Hotspot</small></div></div>
<nav>
<?php foreach ($navigation as $route => $item) { if (!$outside && isset($groups[$route])) { ?><div class="nav-section"><?= $groups[$route]; ?></div><?php } ?>
<a href="./admin.php?id=<?= $route; ?>" class="menu <?= $id === $route ? 'active' : ''; ?>" <?= $id === $route ? 'aria-current="page"' : ''; ?>><i class="fa fa-<?= $item[0]; ?>"></i><span><?= htmlspecialchars($labels[$route] ?? $item[1], ENT_QUOTES, 'UTF-8'); ?></span></a>
<?php } ?></nav>
<div class="sidebar-footer"><span class="status-dot"></span> RADIUS · RouterOS v7<small>Billing & network management</small></div>
</aside>
<div id="notify"><div class="message"></div></div><div id="temp"></div>
<main id="main" class="main">
