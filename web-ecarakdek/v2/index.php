<?php
require_once __DIR__ . '/app/bootstrap.php';

// Guru/tendik (peran pegawai) memakai Portal Guru /v2/guru (tampilan seperti TEMANKU, tema pink).
// Super admin & admin sekolah tetap memakai tampilan e-Carakdek ini.
if (($_SESSION['role'] ?? '') === 'pegawai') {
    header('Location: ' . APP_URL . 'guru/');
    exit;
}

$tahun = date('Y');
$page  = isset($_GET['page']) ? (string)$_GET['page'] : 'dashboard';
$page  = trim($page, '/');

/*
 * WHITELIST HALAMAN — hanya halaman tampilan yang boleh di-include lewat index.php.
 * Endpoint data/aksi (*.data.php, *.usul.php, api.php, ...) TIDAK bisa dipanggil lewat ?page=.
 */
$pagePattern = '#^(dashboard'
    . '|asn/(pns|pppk|pppkpw|detail)'
    . '|absen/(presensi|riwayat|cuti|dinas|rekap|wajah|lokasi|libur|semester|jadwal|titipan|kirim|tpp-besaran|tpp-pegawai|claim)'
    . ')$#';

if (!preg_match($pagePattern, $page)) {
    simpeg_error_page(404);
}

// RBAC
$permission = str_replace('/', '.', $page) . '.view';
allow($permission);

$file = realpath(__DIR__ . '/' . $page . '.php');
if (!$file || strpos($file, __DIR__ . DIRECTORY_SEPARATOR) !== 0) {
    simpeg_error_page(404);
}

$ssotoken = $_SESSION['access_token'] ?? '';

function tgl_indo($tanggal)
{
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
        'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $p = explode('-', (string)$tanggal);
    if (count($p) !== 3 || !isset($bulan[(int)$p[1]])) {
        return (string)$tanggal;
    }
    return $p[2] . ' ' . $bulan[(int)$p[1]] . ' ' . $p[0];
}

$userDisplayName = (string)($_SESSION['nama'] ?? '') !== '' ? (string)$_SESSION['nama'] : (string)($_SESSION['namauser'] ?? ($_SESSION['username'] ?? ''));
$userInitial     = strtoupper(substr($userDisplayName !== '' ? $userDisplayName : '?', 0, 1));
$A = h(APP_URL);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <meta name="description" content="e-Carakdek - Data kepegawaian & presensi ASN sekolah (guru &amp; tenaga kependidikan) Kabupaten Kepulauan Selayar">
    <link rel="icon" href="<?= $A ?>images/logo.png">
    <title>eCarakdek | Kepegawaian Guru &amp; Tendik</title>

    <!-- Tema e-Carakdek (Bootstrap 5 + template e-cak) -->
    <link rel="stylesheet" href="<?= $A ?>assets/css/vendors_css.css">
    <link rel="stylesheet" href="<?= $A ?>assets/css/style.css">
    <link rel="stylesheet" href="<?= $A ?>assets/css/skin_color.css">
    <link rel="stylesheet" href="<?= $A ?>assets/css/ecarakdek-rose.css?v=20261023">

    <!-- Komponen halaman kepegawaian (ikon, select2 BS5, datatables BS5, notifikasi) -->
    <link rel="stylesheet" href="<?= $A ?>assets/fonts/remix/remixicon.css">
    <link rel="stylesheet" href="<?= $A ?>assets/vendor/select2/select2-bootstrap-5-theme.min.css?v=1.3.0">
    <link rel="stylesheet" href="<?= $A ?>assets/vendor/datatables/dataTables.bs5.css">
    <link rel="stylesheet" href="<?= $A ?>assets/vendor/datatables/dataTables.bs5-custom.css">
    <link rel="stylesheet" href="<?= $A ?>assets/css/simpeg-app.css?v=20261023">
    <link rel="stylesheet" href="<?= $A ?>assets/css/ecarakdek-tema.css?v=20261024">

    <script>
      window.SIMPEG_CFG = <?= json_encode([
          'base'    => APP_URL,
          'welcome' => APP_URL . 'welcome.php',
          'status'  => APP_URL . 'asn/koneksi.php',
      ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="<?= $A ?>assets/js/simpeg-app.js?v=20261023"></script>
</head>

<body class="hold-transition light-skin sidebar-mini theme-primary fixed">

<div class="wrapper">
  <div id="loader"></div>

  <header class="main-header">
    <div class="d-flex align-items-center logo-box justify-content-start">
      <a href="<?= $A ?>" class="logo">
        <div class="logo-mini w-50">
          <span class="light-logo"><img src="<?= $A ?>images/logo.png" alt="eCarakdek"></span>
          <span class="dark-logo"><img src="<?= $A ?>images/logo.png" alt="eCarakdek"></span>
        </div>
        <div class="logo-lg">
          <span class="light-logo"><img src="<?= $A ?>images/logo.png" alt="eCarakdek"></span>
          <span class="dark-logo"><img src="<?= $A ?>images/logo.png" alt="eCarakdek"></span>
        </div>
      </a>
    </div>

    <nav class="navbar navbar-static-top">
      <div class="app-menu">
        <ul class="header-megamenu nav">
          <li class="btn-group nav-item">
            <a href="#" class="waves-effect waves-light nav-link push-btn btn-primary-light" data-toggle="push-menu" role="button">
              <i class="icon-Menu"><span class="path1"></span><span class="path2"></span></i>
            </a>
          </li>
        </ul>
      </div>

      <div class="navbar-custom-menu r-side">
        <ul class="nav navbar-nav align-items-center">
          <li class="nav-item d-none d-md-flex me-2">
            <span class="sp-status" aria-label="Status koneksi">
              <span class="sp-badge wait" id="spStatusGo" title="API e-Carakdek: memeriksa…"><span class="sp-dot"></span><span class="sp-badge-text">API e-Carakdek</span></span>
            </span>
          </li>
          <li class="dropdown user user-menu">
            <a href="#" class="waves-effect waves-light dropdown-toggle w-auto l-h-12 bg-transparent p-0 no-shadow" data-bs-toggle="dropdown" title="Akun">
              <div class="d-flex pt-1 align-items-center">
                <div class="text-end me-10">
                  <p class="pt-5 fs-14 mb-0 fw-700 text-primary"><?= h($userDisplayName) ?></p>
                  <small class="fs-10 mb-0 text-uppercase text-mute"><?= h(roleLabel()) ?></small>
                </div>
                <span class="avatar rounded-10 bg-primary-light h-40 w-40 d-flex align-items-center justify-content-center fw-700 text-primary"><?= h($userInitial) ?></span>
              </div>
            </a>
            <ul class="dropdown-menu dropdown-menu-end animated flipInX">
              <li class="user-body">
                <?php if (($_SESSION['role'] ?? '') === 'pegawai' && !empty($_SESSION['pns_id'])): ?>
                <a class="dropdown-item" href="<?= h(APP_URL . 'asn/detail?' . http_build_query(['id' => (string)$_SESSION['pns_id']])) ?>"><i class="ti-user text-muted me-2"></i> Data Saya</a>
                <?php endif; ?>
                <a class="dropdown-item" href="<?= $A ?>keluar.php"><i class="ti-lock text-muted me-2"></i> Keluar</a>
              </li>
            </ul>
          </li>
          <li class="btn-group nav-item d-lg-inline-flex d-none">
            <a href="#" data-provide="fullscreen" class="waves-effect waves-light nav-link full-screen btn-warning-light" title="Layar penuh">
              <i class="icon-Position"></i>
            </a>
          </li>
        </ul>
      </div>
    </nav>
  </header>

  <?php include __DIR__ . '/sidebar.php'; ?>

  <!-- Content Wrapper -->
  <div class="content-wrapper">
    <div class="container-full">
      <section class="content">
        <?php require $file; ?>
      </section>
    </div>
  </div>

  <footer class="main-footer">
    <div class="pull-right d-none d-sm-inline-block"><b>e-Carakdek</b> v2</div>
    &copy; <?= h($tahun) ?> <a href="https://dikbud.kepulauanselayarkab.go.id/" target="_blank" rel="noopener">Dinas Pendidikan, Pemuda dan Olahraga Kabupaten Kepulauan Selayar</a>. All Rights Reserved.
  </footer>

  <div class="control-sidebar-bg"></div>
</div>

<!-- Template e-cak: jQuery 3.6 + Bootstrap 5 bundle + plugin tema (JANGAN muat jQuery/Bootstrap lain) -->
<script src="<?= $A ?>assets/js/vendors.min.js"></script>
<script src="<?= $A ?>assets/js/ecarakdek-bs-compat.js?v=20261023"></script>
<script src="<?= $A ?>assets/icons/feather-icons/feather.min.js"></script>

<!-- Komponen halaman kepegawaian -->
<script src="<?= $A ?>assets/vendor/select2/select2.min.js?v=4.1.0rc0"></script>
<script src="<?= $A ?>assets/vendor/select2/i18n/id.js?v=4.1.0rc0"></script>
<script src="<?= $A ?>assets/js/simpeg-select2.js?v=20261019"></script>
<script src="<?= $A ?>assets/js/simpeg-drh.js?v=20261012"></script>
<script src="<?= $A ?>assets/js/moment.min.js"></script>
<script src="<?= $A ?>assets/vendor/datatables/dataTables.min.js"></script>
<script src="<?= $A ?>assets/vendor/datatables/dataTables.bootstrap.min.js"></script>
<script src="<?= $A ?>assets/js/simpeg-kh-filter.js?v=20261015"></script>

<script src="<?= $A ?>assets/js/template.js"></script>
</body>
</html>
