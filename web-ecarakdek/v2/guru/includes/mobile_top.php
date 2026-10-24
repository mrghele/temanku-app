<?php
/** Bagian atas yang sama untuk Layanan, Riwayat, dan Profil pada ponsel. */
$mobileNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar'));
$mobileHour = (int)$mobileNow->format('H');
$mobileGreeting = $mobileHour < 11 ? 'Selamat pagi' : ($mobileHour < 15 ? 'Selamat siang' : ($mobileHour < 18 ? 'Selamat sore' : 'Selamat malam'));
$mobileName = trim((string)$u['nama']);
if ($mobileName === '' || $mobileName === (string)$u['nip'] || ctype_digit($mobileName)) $mobileName = can('absensi.view') ? 'Pegawai ASN' : 'Admin OPD';
$mobileRole = trim((string)$u['jabatan']);
if ($mobileRole === '' || $mobileRole === '-') $mobileRole = 'Jabatan belum tersedia';
$mobileUnit = trim((string)$u['unit_kerja']);
if ($mobileUnit === '' || $mobileUnit === '-' || preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f-]{27,})$/i', $mobileUnit)) $mobileUnit = 'Belum tersedia';
?>
<?php if (!can('absensi.view')): ?>
<div class="mobile-shared-header mobile-shared-header--admin">
  <div class="mobile-shared-header__greeting">
    <p><?= e(tanggal_id($mobileNow->format('Y-m-d'), true)) ?></p>
    <h1><?= e($mobileGreeting) ?></h1>
  </div>
  <section class="mobile-shared-header__card" aria-label="Akun administrator">
    <p class="hero__eyebrow"><?= getRoleName($u['level_user']) === 'super_admin' ? 'Akun Administrator' : 'Akun Admin OPD' ?></p>
    <p class="hero__name"><?= e($mobileName) ?></p>
    <dl class="hero__meta"><div><dt>Unit Kerja</dt><dd><?= e($mobileUnit) ?></dd></div></dl>
  </section>
</div>
<?php else: ?>
<div class="mobile-shared-header">
  <div class="mobile-shared-header__greeting">
    <p><?= e(tanggal_id($mobileNow->format('Y-m-d'), true)) ?></p>
    <h1><?= e($mobileGreeting) ?></h1>
  </div>
  <section class="mobile-shared-header__card" aria-label="Kartu Identitas ASN">
    <span class="mobile-shared-header__shine" aria-hidden="true"></span>
    <p class="hero__eyebrow">Kartu Identitas ASN</p>
    <p class="hero__name"><?= e($mobileName) ?></p>
    <p class="hero__role"><?= e($mobileRole) ?><?= trim((string)$u['golongan'], ' -') !== '' ? ' · Gol. ' . e($u['golongan']) : '' ?></p>
    <span class="hero__status"><i></i> Aktif</span>
    <dl class="hero__meta">
      <div><dt>NIP</dt><dd class="mono"><?= e($u['nip']) ?></dd></div>
      <div><dt>Unit Kerja</dt><dd><?= e($mobileUnit) ?></dd></div>
    </dl>
  </section>
</div>
<?php endif; ?>
<?php if (can('absensi.view')): ?>
<div class="mobile-shared-career">
  <section class="card" aria-label="Prediksi karier">
    <div class="career-list">
      <?php foreach ([['KGB',$u['tmt_kgb'],'bi-cash-coin','biru'],['TMT pangkat',$u['tmt_pangkat'],'bi-arrow-up-circle-fill','hijau'],['Pensiun',$u['tgl_pensiun'],'bi-mortarboard-fill','kuning']] as [$label,$tgl,$icon,$warna]): ?>
        <div class="career-item"><span class="career-item__icon ico--<?= e($warna) ?>"><i class="bi <?= e($icon) ?>"></i></span><strong><?= e($label) ?></strong><time><?= e(tanggal_id($tgl)) ?></time></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php endif; ?>
