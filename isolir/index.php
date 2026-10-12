<?php
// Public, deliberately minimal captive page. Do not expose customer or invoice data here.
date_default_timezone_set('Asia/Jakarta');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$refreshSeconds = 45;
$support = getenv('MIKHMON_SUPPORT_PHONE') ?: '';
$portalTitle = getenv('MIKHMON_ISOLIR_TITLE') ?: 'Layanan Internet Dibatasi';
$supportHref = preg_replace('/[^0-9+]/', '', $support);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="<?= $refreshSeconds; ?>">
  <title><?= htmlspecialchars($portalTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    :root{color-scheme:light;--ink:#182230;--muted:#667085;--line:#e5eaf0;--bg:#f4f7fb;--accent:#b54708;--accent-bg:#fff4e5;--white:#fff}
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at top,#eaf1fb 0,var(--bg) 52%);font:16px/1.6 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink)}
    main{width:min(100%,620px);background:var(--white);border:1px solid var(--line);border-radius:24px;padding:clamp(24px,5vw,44px);box-shadow:0 22px 70px rgba(25,48,79,.09)}
    .icon{width:64px;height:64px;display:grid;place-items:center;border-radius:20px;background:var(--accent-bg);color:var(--accent);font-size:30px;margin-bottom:24px}
    .eyebrow{font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--accent)}
    h1{font-size:clamp(27px,5vw,38px);line-height:1.15;letter-spacing:-.03em;margin:8px 0 14px}
    p{margin:0 0 16px;color:var(--muted)}
    .notice{padding:16px 18px;border:1px solid #f8d7a5;background:#fffbf3;border-radius:14px;margin:24px 0}
    .notice strong{display:block;color:#7a2e0e;margin-bottom:4px}
    .steps{list-style:none;padding:0;margin:22px 0;counter-reset:step}
    .steps li{display:flex;gap:12px;margin:12px 0;color:var(--muted)}
    .steps li:before{counter-increment:step;content:counter(step);flex:0 0 28px;height:28px;border-radius:50%;background:#eef2f6;color:#344054;font-size:13px;font-weight:800;display:grid;place-items:center}
    .button{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:10px;background:#172b4d;color:#fff;text-decoration:none;font-weight:700;margin:4px 8px 4px 0}
    .button:hover{background:#263f68}
    .small{font-size:13px;color:#98a2b3;margin-top:22px}
    @media(max-width:420px){body{padding:12px}main{border-radius:16px}.button{width:100%;margin:5px 0}}
  </style>
</head>
<body>
<main>
  <div class="icon" aria-hidden="true">!</div>
  <div class="eyebrow">Pemberitahuan layanan</div>
  <h1><?= htmlspecialchars($portalTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
  <p>Akses internet akun ini sedang dibatasi karena tagihan belum tercatat lunas. Halaman ini akan memeriksa ulang otomatis setiap 45 detik.</p>
  <div class="notice">
    <strong>Baru saja melakukan pembayaran?</strong>
    Tidak perlu membayar dua kali. Pastikan pembayaran berhasil dan tunggu sistem menerima konfirmasi dari penyedia pembayaran.
  </div>
  <ol class="steps">
    <li>Bayar melalui tautan tagihan resmi yang dikirim oleh admin melalui WhatsApp.</li>
    <li>Setelah pembayaran dikonfirmasi, sistem memperbarui status akun dan menyambungkan ulang sesi secara otomatis.</li>
    <li>Pemulihan biasanya berlangsung dalam beberapa menit dan ditargetkan maksimal 20 menit saat server, router, dan koneksi RADIUS berfungsi normal.</li>
  </ol>
  <?php if ($supportHref !== '') { ?>
    <a class="button" href="https://wa.me/<?= htmlspecialchars(ltrim($supportHref, '+'), ENT_QUOTES, 'UTF-8'); ?>" rel="noopener noreferrer">Hubungi admin via WhatsApp</a>
  <?php } ?>
  <a class="button" href="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES, 'UTF-8'); ?>">Cek status sekarang</a>
  <p class="small">Jika akses belum pulih setelah 20 menit dari konfirmasi pembayaran, hubungi admin dan sertakan waktu pembayaran. Jangan kirim password akun melalui halaman ini.</p>
</main>
</body>
</html>
