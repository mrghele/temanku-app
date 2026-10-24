<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
// Setelah login pegawai langsung ke Beranda (tidak lagi dialihkan ke persiapan.php / absen).
require __DIR__ . '/includes/repository.php';

$page      = 'beranda';
$pageTitle = 'Beranda';
require __DIR__ . '/includes/header.php';

if (!can('absensi.view')):
    $adminServices = layanan('internal');
    $pengumuman = notifikasi((int)$u['id'], 5);
    $labelAdmin = getRoleName($u['level_user']) === 'super_admin' ? 'ADMINISTRATOR' : 'ADMIN OPD';
    $unitAdmin = trim((string)$u['unit_kerja']);
    if ($unitAdmin === '' || $unitAdmin === '-' || preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f-]{27,})$/i', $unitAdmin)) $unitAdmin = 'Unit kerja belum tersedia';
    $waktuAdmin = new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar'));
    $jamAdmin = (int)$waktuAdmin->format('H');
    $salamAdmin = $jamAdmin < 11 ? 'Selamat pagi' : ($jamAdmin < 15 ? 'Selamat siang' : ($jamAdmin < 18 ? 'Selamat sore' : 'Selamat malam'));
?>
<div class="admin-home">
  <header class="admin-home__hero">
    <p><?= e(tanggal_id($waktuAdmin->format('Y-m-d'), true)) ?></p>
    <h1><?= e($salamAdmin) ?></h1>
    <div class="admin-home__identity">
      <small>AKUN <?= e($labelAdmin) ?></small>
      <strong><?= e($u['nama']) ?></strong>
      <span><?= e($unitAdmin) ?></span>
    </div>
  </header>
  <?php /* [fitur-admin] SEMENTARA: tombol pengaturan fitur untuk super_admin tertentu */ require_once __DIR__ . '/app/fitur.php'; if (fitur_boleh_atur()): ?>
  <a class="fitur-tombol" href="<?= url('fitur.php') ?>"><i class="bi bi-toggles"></i><span><strong>Pengaturan Fitur Sementara</strong><small>Aktifkan / nonaktifkan simulasi wajah &amp; persiapan akun</small></span><i class="bi bi-chevron-right"></i></a>
  <style>.fitur-tombol{display:flex;align-items:center;gap:12px;margin:14px 0;padding:14px 16px;border-radius:16px;background:#fff6dc;border:1px solid #f3d27a;color:#3e1825;text-decoration:none}.fitur-tombol>i:first-child{font-size:22px;color:#b07a00}.fitur-tombol span{flex:1}.fitur-tombol strong{display:block;font-size:14px}.fitur-tombol small{display:block;font-size:12px;color:#6b5a2a}</style>
  <?php endif; /* [/fitur-admin] */ ?>
  <section class="admin-home__content" aria-labelledby="admin-services-title">
    <div class="card__band"><h2 id="admin-services-title">Layanan Admin OPD</h2><a href="<?= url('layanan.php') ?>">Semua layanan</a></div>
    <div class="admin-home__services">
      <?php foreach ($adminServices as $l): ?>
        <a class="services-page__tile" href="<?= e($l['url']) ?>"<?= preg_match('~^https?://~i', (string)$l['url']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
          <span class="services-page__icon ico--<?= e($l['warna']) ?>"><i class="bi <?= e($l['icon']) ?>"></i></span>
          <span class="services-page__text"><strong><?= e($l['nama']) ?></strong><small><?= e($l['deskripsi']) ?></small></span>
          <i class="bi bi-chevron-right services-page__arrow" aria-hidden="true"></i>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php require __DIR__ . '/includes/announcement_carousel.php'; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; return; endif; ?>

<?php
$pid      = (int) $u['id'];
$periode  = date('Y-m');
$rekap    = rekap_bulan($pid, $periode);
$usulan   = usulan_aktif($pid);
$tahap    = $usulan ? usulan_tahap((int) $usulan['id']) : [];
$statUsul = rekap_usulan($pid);
$layanan  = layanan('internal');
$eksternal = layanan('eksternal');
$presensi = presensi_terakhir($pid, 5);
$pengumuman = notifikasi($pid, 5);
$tpp      = $rekap['perkiraan_tpp'] ?? null;
$bulanTpp = explode(' ', tanggal_id($periode . '-01', true))[2] ?? '';
$waktuLokal = new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar'));
$jamSekarang = (int) $waktuLokal->format('H');
$salam = $jamSekarang < 11 ? 'Selamat pagi' : ($jamSekarang < 15 ? 'Selamat siang' : ($jamSekarang < 18 ? 'Selamat sore' : 'Selamat malam'));
$namaTampil = trim((string) $u['nama']);
if ($namaTampil === '' || $namaTampil === (string)$u['nip'] || ctype_digit($namaTampil)) $namaTampil = 'Pegawai ASN';
$unitTampil = trim((string)$u['unit_kerja']);
if ($unitTampil === '' || preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f-]{27,})$/i', $unitTampil)) $unitTampil = 'Belum tersedia';
$jabatanTampil = trim((string)$u['jabatan']);
if ($jabatanTampil === '' || $jabatanTampil === '-') $jabatanTampil = 'Jabatan belum tersedia';
?>
<div class="page__head dashboard-head">
  <div>
    <p><?= e(tanggal_id($waktuLokal->format('Y-m-d'), true)) ?></p>
    <h1><?= e($salam) ?></h1>
  </div>
  <a class="btn btn--amber" href="<?= url('absensi.php') ?>"><i class="bi bi-fingerprint"></i> Presensi Hari Ini</a>
</div>

<div class="dashboard-grid">

  <section class="hero">
    <span class="hero__sq" style="top:-30px;right:64px;width:110px;height:110px;border-radius:26px;transform:rotate(18deg)"></span>
    <span class="hero__sq" style="bottom:-44px;left:32%;width:88px;height:88px;border-radius:22px;transform:rotate(-16deg)"></span>
    <div class="hero__identity">
      <span class="hero__shine" aria-hidden="true"></span>
      <div class="hero__identity-main" style="position:relative;display:flex;align-items:flex-start;justify-content:space-between;gap:14px">
        <div>
          <p class="hero__eyebrow">Kartu Identitas ASN</p>
          <p class="hero__name"><?= e($namaTampil) ?></p>
          <p class="hero__role"><?= e($jabatanTampil) ?><?= trim((string)$u['golongan'], ' -') !== '' ? ' · Gol. ' . e($u['golongan']) : '' ?></p>
        </div>
        <img class="hero__avatar" src="<?= e(/*[foto-profil]*/ $u['foto'] ? url('foto_saya.php') . '?v=' . substr(md5((string) $u['foto']), 0, 10) : url('assets/img/user.svg')) ?>" data-foto-profil onerror="this.onerror=null;this.src='<?= e(url('assets/img/user.svg')) ?>'" alt="<?= e($namaTampil) ?>" />
      </div>
      <span class="hero__status hero__status--mobile"><i></i> Aktif</span>
      <dl class="hero__meta">
        <div><dt>NIP</dt><dd class="mono"><?= e($u['nip']) ?></dd></div>
        <div><dt>Unit Kerja</dt><dd><?= e($unitTampil) ?></dd></div>
        <div><dt>Status</dt><dd class="hero__active">Aktif</dd></div>
      </dl>
    </div>
  </section>

  <section class="card career-card">
    <div class="card__band">
      <h2><i class="bi bi-graph-up-arrow"></i> Prediksi Karier</h2>
      <span class="badge badge--rose">Dari SIMPEG</span>
    </div>
    <div class="career-list">
      <?php foreach ([
          ['KGB berikutnya', 'KGB', $u['tmt_kgb'], 'bi-cash-coin', 'biru'],
          ['TMT pangkat terakhir', 'TMT pangkat', $u['tmt_pangkat'], 'bi-arrow-up-circle-fill', 'hijau'],
          ['Batas usia pensiun', 'Pensiun', $u['tgl_pensiun'], 'bi-mortarboard-fill', 'kuning'],
      ] as [$desktopLabel, $mobileLabel, $tgl, $icon, $warna]): ?>
        <div class="career-item">
          <span class="career-item__icon ico--<?= e($warna) ?>"><i class="bi <?= e($icon) ?>"></i></span>
          <strong><span class="desktop-only"><?= e($desktopLabel) ?></span><span class="mobile-only"><?= e($mobileLabel) ?></span></strong>
          <time><?= e(tanggal_id($tgl)) ?></time>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="dashboard-grid__attendance">
  <section class="card attendance-card" style="display:flex;align-items:center;gap:16px">
    <div class="ring" style="background:conic-gradient(var(--rose) 0% <?= $rekap['persen'] ?>%, #f2dde5 <?= $rekap['persen'] ?>% 100%)">
      <div class="ring__inner">
        <span class="ring__val"><?= $rekap['persen'] ?>%</span>
        <span class="ring__cap">hadir</span>
      </div>
    </div>
    <div style="flex:1;min-width:0">
      <p style="margin:0;font-size:13.5px;font-weight:800">Presensi bulan ini</p>
      <?php if (!$rekap['tersambung']): ?><p style="margin:3px 0;color:var(--muted);font-size:11px">Data APEL belum tersambung</p><?php endif; ?>
      <p style="margin:4px 0 0;font-size:12.5px;color:var(--muted);line-height:1.5">
        <?= $rekap['hadir'] + $rekap['wfa'] ?> dari <?= $rekap['hari_kerja'] ?> hari kerja berjalan<br />
        <?= $rekap['hadir'] ?> hadir &middot; <?= $rekap['wfa'] ?> WFA &middot; <?= $rekap['izin'] ?> izin &middot; <?= $rekap['libur'] ?> libur
      </p>
      <p class="attendance-card__chips">
        <?php if ($hariIni && !empty($hariIni['jam_masuk'])): ?><span>Masuk <?= e(jam($hariIni['jam_masuk'])) ?></span><?php endif; ?>
        <?php if ($hariIni && !empty($hariIni['status'])): ?><span><?= e(label_status($hariIni['status'])) ?></span><?php endif; ?>
      </p>
    </div>
  </section>
  <div class="dashboard-grid__tpp">
    <?php require __DIR__ . '/includes/tpp_card.php'; ?>
  </div>
  </div>

</div>

<div class="mobile-tpp">
  <?php require __DIR__ . '/includes/tpp_card.php'; ?>
</div>

<section class="card quick-services">
  <div class="card__band"><h2>Layanan Cepat</h2><a href="<?= url('layanan.php') ?>">Semua</a></div>
  <div class="quick-services__grid">
    <?php foreach (array_slice($layanan, 0, 8) as $l): ?>
      <a href="<?= e($l['url'] ?: url('layanan.php')) ?>">
        <span class="tile__ico ico--<?= e($l['warna']) ?>"><i class="bi <?= e($l['icon']) ?>"></i></span>
        <span><?= e(['kenaikan pangkat' => 'Pangkat', 'pencantuman gelar' => 'Gelar'][strtolower(trim((string)$l['nama']))] ?? $l['nama']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<div class="dashboard-rest">
  <div class="grid grid--split dashboard-rest__columns">
    <div class="stack">

      <section class="card services-card">
        <div class="card__band">
          <h2><i class="bi bi-grid-fill"></i> Layanan Kepegawaian</h2>
          <span class="badge badge--rose">Total: <?= count($layanan) ?> layanan</span>
        </div>
        <div class="grid grid--tiles">
          <?php foreach ($layanan as $l): ?>
            <a class="tile" href="<?= e($l['url'] ?: '#') ?>">
              <span class="tile__ico ico--<?= e($l['warna']) ?>"><i class="bi <?= e($l['icon']) ?>"></i></span>
              <span class="tile__txt"><strong><?= e($l['nama']) ?></strong><small><?= e($l['deskripsi']) ?></small></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="card history-card">
        <div class="card__band">
          <h2><i class="bi bi-clock-history"></i> Riwayat Presensi Terakhir</h2>
          <a href="<?= url('absensi.php') ?>" style="font-size:12px;font-weight:700">Lihat rekap</a>
        </div>
        <div class="rows">
          <div class="row row--head">
            <span>Tanggal</span><span>Masuk</span><span class="row__out">Keluar</span><span>Status</span>
          </div>
          <?php foreach ($presensi as $p): ?>
            <div class="row">
              <strong><?= e(tanggal_id($p['tanggal'])) ?></strong>
              <span class="mono"><?= e(jam($p['jam_masuk'])) ?></span>
              <span class="mono row__out"><?= e(jam($p['jam_keluar'])) ?></span>
              <span><span class="<?= badge_status($p['status']) ?>"><?= e(label_status($p['status'])) ?></span></span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

    </div>
    <div class="stack">

      <section class="card application-card<?= $usulan ? '' : ' application-card--empty' ?>">
        <div class="card__band">
          <h2><?= e($usulan ? (preg_match('/^\s*Usulan\b/i', (string)($usulan['layanan'] ?? '')) ? (string)$usulan['layanan'] : 'Usulan ' . (string)($usulan['layanan'] ?? 'Pengajuan')) : 'Usulan Pengajuan') ?></h2>
          <span class="badge <?= $usulan ? 'badge--kuning' : 'badge--abu' ?>"><?= $usulan ? 'Proses' : 'Belum ada' ?></span>
        </div>
        <?php if ($usulan && $tahap): ?>
        <div class="timeline">
          <?php foreach ($tahap as $i => $t):
              $last = $i === count($tahap) - 1;
              $state = in_array($t['state'] ?? '', ['done', 'active', 'todo'], true) ? $t['state'] : 'todo';
              $ico = $state === 'done' ? 'bi-check' : ($state === 'active' ? 'bi-arrow-repeat' : 'bi-dot');
          ?>
            <div class="timeline__item timeline__item--<?= e($state) ?>">
              <div class="timeline__col">
                <span class="timeline__dot timeline__dot--<?= e($state) ?>"><i class="bi <?= $ico ?>"></i></span>
                <?php if (!$last): ?><span class="timeline__line <?= $state === 'done' ? 'timeline__line--done' : '' ?>"></span><?php endif; ?>
              </div>
              <div class="timeline__body">
                <p class="<?= $state === 'active' ? 'is-active' : ($state === 'todo' ? 'is-todo' : '') ?>"><?= e($t['judul'] ?? 'Tahap pengajuan') ?></p>
                <small><?= e($t['keterangan'] ?? '') ?></small>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php elseif ($usulan): ?>
          <p class="application-card__empty">Rincian tahapan akan tampil setelah usulan diproses.</p>
        <?php else: ?>
          <div class="application-card__no-usulan">
            <span class="application-card__no-usulan-icon"><i class="bi bi-file-earmark-plus"></i></span>
            <p>Belum ada usulan pengajuan yang sedang berjalan.</p>
            <a href="<?= url('layanan.php') ?>">Lihat layanan <i class="bi bi-arrow-right"></i></a>
          </div>
        <?php endif; ?>
      </section>

      <section class="card proposal-status-card">
        <div class="card__band"><h2><i class="bi bi-inboxes-fill"></i> Status Usulan</h2></div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <?php foreach ([
              ['Menunggu verifikasi', $statUsul['menunggu'], 'kuning'],
              ['Disetujui', $statUsul['disetujui'], 'hijau'],
              ['Ditolak', $statUsul['ditolak'], 'merah'],
          ] as [$label, $val, $warna]): ?>
            <div class="stat ico--<?= $warna ?>" style="display:flex;align-items:center;justify-content:space-between">
              <span style="font-size:12.5px;font-weight:700"><?= e($label) ?></span>
              <strong style="margin:0;font-size:17px"><?= (int) $val ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <?php require __DIR__ . '/includes/announcement_carousel.php'; ?>

      <section class="card external-services-card">
        <div class="card__band"><h2>Layanan Eksternal</h2></div>
        <div class="external-services-card__list">
          <?php foreach ($eksternal as $x): ?>
            <a class="tile external-services-card__item" href="<?= e($x['url']) ?>" target="_blank" rel="noopener">
              <span class="tile__ico ico--<?= e($x['warna']) ?>"><i class="bi <?= e($x['icon']) ?>"></i></span>
              <span class="tile__txt"><strong><?= e($x['nama']) ?></strong><small><?= e($x['deskripsi']) ?></small></span>
              <i class="bi bi-arrow-up-right external-services-card__arrow" aria-hidden="true"></i>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
