<?php
declare(strict_types=1);

/** @var array $u  pegawai aktif */
middleware();
$u          = user();
$page       = $page       ?? 'beranda';
$pageTitle  = $pageTitle  ?? 'Beranda';
$belumBaca  = notifikasi_belum_dibaca((int) $u['id']);
$hariIni    = can('absensi.view') ? presensi_hari_ini((int) $u['id']) : null;
$cari       = trim((string) ($_GET['q'] ?? ''));
$f          = flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="theme-color" content="#d94f7f" />
<title><?= e($pageTitle) ?> · <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=<?= (int) filemtime(__DIR__ . '/../assets/css/style.css') ?>" />
</head>
<body class="page-<?= e($page) ?> role-<?= e(getRoleName($u['level_user'])) ?>">
<div class="shell">
<?php require __DIR__ . '/sidebar.php'; ?>
  <div class="main">
    <header class="topbar">
      <a class="mobile-brand" href="<?= url('index.php') ?>"><span class="brand__mark">e</span><span><strong>eCARAKDEK</strong><small>BKPSDM KEP. SELAYAR</small></span></a>
      <form class="search" method="get" action="<?= url('layanan.php') ?>">
        <i class="bi bi-search"></i>
        <input type="search" name="q" value="<?= e($cari) ?>" placeholder="Cari layanan, berkas, atau riwayat..." />
      </form>
      <div class="topbar__right">
        <?php if ($hariIni && $hariIni['jam_masuk']): ?>
          <span class="chip-hadir"><span></span>Masuk <?= e(jam($hariIni['jam_masuk'])) ?></span>
        <?php endif; ?>
        <a class="icon-btn" href="<?= url('notifikasi.php') ?>" aria-label="Notifikasi">
          <i class="bi bi-bell"></i>
          <?php if ($belumBaca > 0): ?><span class="dot"></span><?php endif; ?>
        </a>
        <details class="account-menu">
          <summary class="userchip" aria-label="Buka menu akun">
            <img src="<?= e(/*[foto-profil]*/ $u['foto'] ? url('foto_saya.php') . '?v=' . substr(md5((string) $u['foto']), 0, 10) : url('assets/img/user.svg')) ?>" onerror="this.onerror=null;this.src='<?= e(url('assets/img/user.svg')) ?>'" alt="" />
            <span>
              <p><?= e($u['nama']) ?></p>
              <small><?= can('riwayat.view') ? 'Gol. ' . e($u['golongan']) : (getRoleName($u['level_user']) === 'super_admin' ? 'Administrator' : 'Admin OPD') ?></small>
            </span>
            <i class="bi bi-chevron-down account-menu__caret" aria-hidden="true"></i>
          </summary>
          <div class="account-menu__panel">
            <div class="account-menu__identity">
              <strong><?= e($u['nama']) ?></strong>
              <small><?= can('riwayat.view') ? e($u['nip']) : e($u['unit_kerja'] ?? 'Admin OPD') ?></small>
            </div>
            <a href="<?= url('profil.php') ?>"><i class="bi bi-person"></i> Profil saya</a>
            <a class="account-menu__logout" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> Keluar dari akun</a>
          </div>
        </details>
      </div>
    </header>
    <main class="page">
      <?php if ($f): ?>
        <div class="flash flash--<?= e($f['tipe']) ?>"><i class="bi bi-info-circle-fill"></i><?= e($f['msg']) ?></div>
      <?php endif; ?>
