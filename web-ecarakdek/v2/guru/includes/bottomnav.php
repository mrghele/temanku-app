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
    <p>Catat presensi melalui lokasi GPS sesuai titik sekolah.</p>
    <?php /* Satu tombol "Absen Sekarang": masuk, pulang, WFA & upacara dipilih di halaman Absen.
             Abu-abu bila sudah absen, hari libur/akhir pekan, belum waktunya, atau lewat jam (assets/js/absen_tombol.js). */
      require_once __DIR__ . '/../app/fitur.php';
      $urlAbsen = fitur_aktif('absen_wajah') ? url('absen.php') : url('absensi.php') . '#formAbsen'; ?>
    <a class="presensi-sheet__primary" href="<?= e($urlAbsen) ?>" data-absen-tombol="menu"
       data-status="<?= e(url('wajah_api.php')) ?>?action=absen/status" data-csrf="<?= e(csrf_token()) ?>">
      <i class="bi bi-fingerprint"></i><span class="absen-tombol__teks"><span data-absen-judul>Absen Sekarang</span><small data-absen-ket hidden></small></span>
    </a>
    <style>
      .presensi-sheet__primary .absen-tombol__teks{display:flex;flex-direction:column;align-items:flex-start;line-height:1.2}
      .presensi-sheet__primary small{font-size:11.5px;font-weight:500;opacity:.9}
      .presensi-sheet__primary.is-memuat{opacity:.75}
      .presensi-sheet__primary.is-off,.presensi-sheet__primary.is-off:hover{background:#e5e7eb!important;color:#6b7280!important;box-shadow:none!important;cursor:not-allowed}
      .presensi-sheet__primary.is-off i{color:#9ca3af!important}
    </style>
    <script src="<?= e(url('assets/js/absen_tombol.js')) ?>?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/absen_tombol.js') ?>" defer></script>
    <div class="presensi-sheet__actions">
      <a href="<?= url('absensi.php') ?>#kalender"><span class="ico--hijau"><i class="bi bi-calendar-check-fill"></i></span>Rekap</a>
      <a href="<?= url('absensi.php') ?>#aksi-wfa"><span class="ico--biru"><i class="bi bi-house-door-fill"></i></span>WFA</a>
      <a href="<?= url('absensi.php') ?>#aksi-izin"><span class="ico--kuning"><i class="bi bi-envelope-paper-fill"></i></span>Izin</a>
      <a href="<?= url('absensi.php') ?>#aksi-laporan"><span class="ico--rose"><i class="bi bi-journal-text"></i></span>Laporan</a>
    </div>
  </section>
</div>
<?php endif; ?>
