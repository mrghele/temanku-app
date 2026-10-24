<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';

$page      = 'profil';
$pageTitle = 'Profil';
require __DIR__ . '/includes/header.php';

$isAdminProfile = !can('riwayat.view');
$adminRole = getRoleName($u['level_user']) === 'super_admin' ? 'Administrator' : 'Admin OPD';
$menu = $isAdminProfile ? [
    ['Layanan Kepegawaian', 'bi-grid', url('layanan.php'), false],
    ['SIMPEG', 'bi-people', service_url('simpeg'), true],
    ['APEL', 'bi-clipboard-check', service_url('apel'), true],
    ['SIPEKA', 'bi-file-earmark-text', service_url('sipeka'), true],
    ['Notifikasi', 'bi-bell', url('notifikasi.php'), false],
    ['Bantuan', 'bi-question-circle', service_url('helpdesk'), true],
] : [
    ['Perekaman Wajah', 'bi-person-bounding-box', url('wajah.php'), false],
    ['Edit Profil', 'bi-person', '#', false],
    ['Data Kepegawaian', 'bi-file-earmark-text', url('riwayat.php'), false],
    ['Notifikasi', 'bi-bell', url('notifikasi.php'), false],
    ['Keamanan Akun', 'bi-shield-lock', '#', false],
    ['Bantuan', 'bi-question-circle', service_url('helpdesk'), true],
];
?>
<?php require __DIR__ . '/includes/mobile_top.php'; ?>
<div class="profile-page<?= $isAdminProfile ? ' profile-page--admin' : '' ?>">
  <h1 class="profile-page__sr-only">Profil</h1>
  <section class="profile-page__identity">
    <span class="profile-page__glow" aria-hidden="true"></span>
    <img class="profile-page__avatar" src="<?= e(/*[foto-profil]*/ $u['foto'] ? url('foto_saya.php') . '?v=' . substr(md5((string) $u['foto']), 0, 10) : url('assets/img/user.svg')) ?>" onerror="this.onerror=null;this.src='<?= e(url('assets/img/user.svg')) ?>'" alt="Foto <?= e($mobileName) ?>" />
    <p class="profile-page__name"><?= e($mobileName) ?></p>
    <?php if ($isAdminProfile): ?>
      <p class="profile-page__role"><?= e($adminRole) ?></p>
      <p class="profile-page__unit"><?= e($mobileUnit) ?></p>
    <?php else: ?>
      <p class="profile-page__role"><?= e($mobileRole) ?><?= trim((string)$u['golongan'], ' -') !== '' ? ' · Gol. ' . e($u['golongan']) : '' ?></p>
      <p class="profile-page__nip"><?= e($u['nip']) ?></p>
    <?php endif; ?>
  </section>

  <div class="profile-page__right">
    <?php if ($isAdminProfile): ?><h2 class="profile-page__section-title">Akses Admin OPD</h2><?php endif; ?>
    <nav class="profile-page__settings" aria-label="Pengaturan profil">
      <?php foreach ($menu as [$label, $icon, $href, $external]): ?>
        <a class="profile-page__setting" href="<?= e($href) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
          <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
          <span><?= e($label) ?></span>
          <i class="bi bi-chevron-right profile-page__chevron" aria-hidden="true"></i>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
