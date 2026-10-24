<?php
$nav = [
    ['beranda',    'index.php',      'Beranda',     'bi-house-door-fill', ''],
    ['absensi',    'absensi.php',    'Absensi',     'bi-fingerprint',     ''],
    ['layanan',    'layanan.php',    'Layanan',     'bi-grid-fill',       ''],
    ['riwayat',    'riwayat.php',    'Riwayat ASN', 'bi-clock-history',   ''],
    ['notifikasi', 'notifikasi.php', 'Notifikasi',  'bi-bell-fill',       $belumBaca > 0 ? (string) $belumBaca : ''],
    ['profil',     'profil.php',     'Profil',      'bi-person-fill',     ''],
];
$nav = array_values(array_filter($nav, static function ($item): bool {
    return ($item[0] !== 'absensi' || can('absensi.view'))
        && ($item[0] !== 'riwayat' || can('riwayat.view'));
}));
?>
<aside class="sidebar">
  <span class="sidebar__blob" style="top:-80px;right:-90px;width:240px;height:240px"></span>
  <span class="sidebar__sq" style="bottom:120px;right:-46px;width:120px;height:120px;border-radius:28px;transform:rotate(20deg)"></span>
  <span class="sidebar__sq" style="top:44%;left:-40px;width:90px;height:90px;border-radius:22px;transform:rotate(-18deg)"></span>

  <div class="brand">
    <span class="brand__mark">e</span>
    <span>
      <p class="brand__name"><?= APP_NAME ?></p>
      <p class="brand__org"><?= APP_ORG ?></p>
    </span>
  </div>

  <nav class="menu">
    <p class="menu__label">Menu</p>
    <?php foreach ($nav as [$key, $file, $label, $icon, $badge]): ?>
      <a href="<?= url($file) ?>" class="<?= $page === $key ? 'is-active' : '' ?>">
        <i class="bi <?= $icon ?>"></i>
        <span><?= e($label) ?></span>
        <?php if ($badge !== ''): ?><span class="menu__badge"><?= e($badge) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="helpcard">
    <strong>Butuh bantuan?</strong>
    <p>Hubungi helpdesk kepegawaian pada hari kerja 07.30 – 16.00 WITA.</p>
    <a href="<?= e(service_url('helpdesk')) ?>" target="_blank" rel="noopener noreferrer">Helpdesk</a>
  </div>
</aside>
