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
<meta name="theme-color" content="#168f90" />
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
      <a class="mobile-brand" href="<?= url('index.php') ?>"><span class="brand__mark">T</span><span><strong>TEMANKU</strong><small>BKPSDM KEP. SELAYAR</small></span></a>
      <form class="search" method="get" action="<?= url('layanan.php') ?>">
        <i class="bi bi-search"></i>
        <input type="search" name="q" value="<?= e($cari) ?>" placeholder="Cari layanan, berkas, atau riwayat..." />
      </form>
      <div class="topbar__right">
        <?php if ($hariIni && $hariIni['jam_masuk']): ?>
          <span class="chip-hadir"><span></span>Masuk <?= e(jam($hariIni['jam_masuk'])) ?></span>
        <?php endif; ?>
        <?php if (can('riwayat.view')): /* status koneksi API SIMPEG & BKN (riwayat_aksi.php?aksi=status, cache 60 detik) */ ?>
          <style>
          .api-status{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 11px;border-radius:99px;border:1px solid rgba(15,23,42,.1);background:#fff;font:600 12px/1 inherit;color:#475467;cursor:pointer}
          .api-status__i{display:inline-flex;align-items:center;gap:5px}
          .api-status__d{width:8px;height:8px;border-radius:50%;background:#cbd5e1}
          .api-status__i.is-ok .api-status__d{background:#12b76a;box-shadow:0 0 0 3px rgba(18,183,106,.18)}
          .api-status__i.is-gagal .api-status__d{background:#f04438;box-shadow:0 0 0 3px rgba(240,68,56,.18)}
          .api-status__i.is-cek .api-status__d{animation:api-denyut 1s infinite}
          .api-status__sep{width:1px;height:14px;background:rgba(15,23,42,.12)}
          @keyframes api-denyut{50%{opacity:.3}}
          @media (max-width:820px){.api-status{display:none}}  /* mobile: status tampil di dialog sync halaman Riwayat */
          </style>
          <button type="button" class="api-status" id="api-status" data-url="<?= e(url('riwayat_aksi.php')) ?>" title="Status koneksi API — klik untuk periksa ulang" aria-label="Status koneksi API SIMPEG dan BKN">
            <span class="api-status__i is-cek" data-api="simpeg"><span class="api-status__d"></span><span>SIMPEG</span></span>
            <span class="api-status__sep"></span>
            <span class="api-status__i is-cek" data-api="bkn"><span class="api-status__d"></span><span>BKN</span></span>
          </button>
          <script>
          (function () {
            var b = document.getElementById('api-status');
            function cek(segar) {
              var items = b.querySelectorAll('[data-api]');
              items.forEach(function (i) { i.className = 'api-status__i is-cek'; });
              fetch(b.dataset.url + '?aksi=status' + (segar ? '&segar=1' : ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                  var teks = [];
                  items.forEach(function (i) {
                    var s = j[i.dataset.api] || {}, nama = i.dataset.api === 'bkn' ? 'API BKN' : 'API SIMPEG';
                    i.className = 'api-status__i ' + (s.ok ? 'is-ok' : 'is-gagal');
                    teks.push(nama + (s.ok ? ' terhubung' : ' tidak terhubung'));
                  });
                  b.title = teks.join(' · ') + (j.dicek ? ' (dicek ' + j.dicek + ')' : '') + ' — klik untuk periksa ulang';
                  document.dispatchEvent(new CustomEvent('api-status', { detail: j }));
                })
                .catch(function () { items.forEach(function (i) { i.className = 'api-status__i is-gagal'; }); b.title = 'Status koneksi tidak diketahui — klik untuk periksa ulang'; });
            }
            b.addEventListener('click', function () { cek(true); });
            window.cekStatusApi = cek;
            cek(false);
          })();
          </script>
        <?php endif; ?>
        <a class="icon-btn" href="<?= url('notifikasi.php') ?>" aria-label="Notifikasi">
          <i class="bi bi-bell"></i>
          <?php if ($belumBaca > 0): ?><span class="dot"></span><?php endif; ?>
        </a>
        <details class="account-menu">
          <summary class="userchip" aria-label="Buka menu akun">
            <img src="<?= e(/*[foto-profil]*/ $u['foto'] ? url('foto_saya.php') . '?v=' . substr(md5((string) $u['foto']), 0, 10) : url('lama/assets/images/user.png')) ?>" onerror="this.onerror=null;this.src='<?= e(url('lama/assets/images/user.png')) ?>'" alt="" />
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
