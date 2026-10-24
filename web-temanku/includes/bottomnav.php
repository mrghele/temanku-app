<nav class="bottomnav">
  <?php if (can('absensi.view')): ?>
  <button class="bottomnav__fab" type="button" aria-label="Buka menu presensi" aria-expanded="false" aria-controls="presensi-sheet"><i class="bi bi-fingerprint"></i></button>
  <?php endif; ?>
  <div class="bottomnav__bar">
    <a href="<?= url('index.php') ?>" class="<?= $page === 'beranda' ? 'is-active' : '' ?>"><i class="bi bi-house-door-fill"></i><span>Beranda</span></a>
    <a href="<?= url('layanan.php') ?>" class="<?= $page === 'layanan' ? 'is-active' : '' ?>"><i class="bi bi-grid-fill"></i><span>Layanan</span></a>
    <?php if (can('absensi.view')): ?><span class="bottomnav__gap"></span><?php endif; ?>
    <?php if (can('riwayat.view')): ?>
      <a href="<?= url('riwayat.php') ?>" class="<?= $page === 'riwayat' ? 'is-active' : '' ?>"><i class="bi bi-clock-history"></i><span>Riwayat</span></a>
    <?php else: ?>
      <a href="<?= url('notifikasi.php') ?>" class="<?= $page === 'notifikasi' ? 'is-active' : '' ?>"><i class="bi bi-bell-fill"></i><span>Notifikasi</span></a>
    <?php endif; ?>
    <a href="<?= url('profil.php') ?>" class="<?= $page === 'profil' ? 'is-active' : '' ?>"><i class="bi bi-person-fill"></i><span>Profil</span></a>
  </div>
</nav>
<?php if (can('absensi.view')): ?>
<div class="presensi-sheet" id="presensi-sheet" hidden>
  <div class="presensi-sheet__backdrop" data-close-sheet></div>
  <section class="presensi-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="presensi-sheet-title">
    <button class="presensi-sheet__handle" type="button" data-close-sheet aria-label="Tutup menu presensi"></button>
    <h2 id="presensi-sheet-title">Presensi &amp; Kehadiran</h2>
    <p>Catat presensi melalui lokasi GPS sesuai titik APEL.</p>
    <a class="presensi-sheet__primary" href="<?= url('absensi.php') ?>#formAbsen"><i class="bi bi-fingerprint"></i> Absen Sekarang</a>
    <!-- [simulasi-menu] SEMENTARA: simulasi absen dengan wajah, tidak dicatat (saklar di fitur.php) -->
    <?php require_once __DIR__ . '/../app/fitur.php'; if (fitur_aktif('absen_wajah')): ?>
    <a class="presensi-sheet__face" href="<?= url('absen.php?mode=upacara') ?>"><i class="bi bi-flag-fill"></i><span><strong>Absen Upacara</strong><small>Senin, tanggal 17 &amp; hari besar sesuai jadwal</small></span></a>
    <?php endif; if (fitur_aktif('simulasi_menu') && temanku_metode_absen() === 'WAJAH'): ?>
    <a class="presensi-sheet__face" href="<?= url('wajah.php') ?>#simulasi"><i class="bi bi-person-bounding-box"></i><span><strong>Simulasi Absen Wajah</strong><small>Uji wajah Anda, tidak dicatat sebagai absen</small></span></a>
    <style>
      .presensi-sheet__face{display:flex;align-items:center;gap:12px;margin-top:10px;padding:11px 14px;border-radius:15px;background:#e6f3f3;color:#123f40;text-decoration:none}
      .presensi-sheet__face:hover{background:#d6ecec;color:#123f40}
      .presensi-sheet__face i{font-size:22px;color:#168f90}
      .presensi-sheet__face strong{display:block;font-size:13.5px}
      .presensi-sheet__face small{display:block;font-size:11px;color:#5f7474;font-weight:500}
    </style>
    <?php endif; ?>
    <!-- [/simulasi-menu] -->
    <div class="presensi-sheet__actions">
      <a href="<?= url('absensi.php') ?>#kalender"><span class="ico--hijau"><i class="bi bi-calendar-check-fill"></i></span>Rekap</a>
      <a href="<?= url('absensi.php') ?>#aksi-wfa"><span class="ico--biru"><i class="bi bi-house-door-fill"></i></span>WFA</a>
      <a href="<?= url('absensi.php') ?>#aksi-izin"><span class="ico--kuning"><i class="bi bi-envelope-paper-fill"></i></span>Izin</a>
      <a href="<?= url('absensi.php') ?>#aksi-laporan"><span class="ico--rose"><i class="bi bi-journal-text"></i></span>Laporan</a>
    </div>
  </section>
</div>
<?php endif; ?>
