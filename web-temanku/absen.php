<?php
declare(strict_types=1);
/*
 * Absen dengan wajah: masuk, pulang, WFA (Kamis/Jumat, wilayah Kab. Selayar) dan upacara
 * (bila ada jadwal; sekaligus absen masuk). Semua diputuskan & dicatat server (Go + Face Engine).
 */
require __DIR__ . '/app/bootstrap.php';
middleware('absensi.view');
require __DIR__ . '/includes/repository.php';
$page = 'absensi';
$absenMode = ($_GET['mode'] ?? '') === 'upacara' ? 'upacara' : 'umum';   // menu Absen Upacara tersendiri
$pageTitle = $absenMode === 'upacara' ? 'Absen Upacara' : 'Absen';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/mobile_top.php';
// Pengumuman dari APEL (aktif hari ini, untuk semua pegawai / OPD pegawai). Gagal dimuat: halaman tetap tampil.
$pengumuman = [];
try {
    $resPengumuman = api_request(API_TEMANKU, 'GET', '/portal/pengumuman');
    $pengumuman = is_array($resPengumuman['data'] ?? null) ? $resPengumuman['data'] : [];
} catch (Throwable $e) {
    $pengumuman = [];
}
?>
<?php if ($pengumuman): ?>
<div class="pengumuman-daftar" style="max-width:720px;margin:0 auto 12px;padding:0 12px">
  <?php foreach ($pengumuman as $pg): $penting = !empty($pg['penting']); ?>
    <div style="background:#fff;border:1px solid <?= $penting ? '#f1aeb5' : '#cfe2ff' ?>;border-left:5px solid <?= $penting ? '#dc3545' : '#0d6efd' ?>;border-radius:10px;padding:10px 12px;margin-bottom:8px">
      <div style="font-weight:700"><?= $penting ? '⚠️ ' : '📢 ' ?><?= e((string) ($pg['judul'] ?? '')) ?></div>
      <div style="white-space:pre-wrap;font-size:14px;margin-top:2px"><?= e((string) ($pg['isi'] ?? '')) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<link rel="stylesheet" href="<?= e(url('assets/css/wajah.css')) ?>?v=<?= (int) @filemtime(__DIR__ . '/assets/css/wajah.css') ?>" />
<section id="face-app" class="face-page" data-mode="absen" data-api="<?= e(url('wajah_api.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-assets="<?= e(url('assets/vendor/mediapipe')) ?>" data-foto="<?= e(url('foto_saya.php')) ?>" data-rekam="<?= e(url('wajah.php')) ?>" data-simulasi="wajah" data-absen-mode="<?= e($absenMode) ?>">
  <div class="face-card absen-card" id="absen-panel">
    <div class="face-head">
      <span class="face-head__icon" aria-hidden="true"><?= $absenMode === 'upacara' ? '🎌' : '⏱' ?></span>
      <div>
        <h1 id="absen-judul"><?= $absenMode === 'upacara' ? 'Absen Upacara' : 'Absen' ?></h1>
        <p id="absen-sub" role="status">Memuat jadwal hari ini…</p>
      </div>
    </div>
    <p class="absen-lokasi" id="absen-lokasi">Membaca lokasi GPS…</p>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
    <div class="absen-peta" id="absen-peta" aria-label="Peta lokasi absen"></div>
    <p class="absen-notice" id="absen-notice" hidden></p>
    <div class="absen-catatan" id="absen-catatan" hidden></div>
    <p class="face-review" id="absen-wajib" hidden>Anda wajib <b>merekam wajah</b> terlebih dahulu sebelum dapat absen. <a id="absen-rekam-link" href="<?= e(url('wajah.php')) ?>">Rekam wajah sekarang</a></p>
    <section class="absen-group" id="absen-group-masuk" hidden>
      <h2>Absen masuk</h2>
      <div class="absen-list" id="absen-list-masuk"></div>
    </section>
    <section class="absen-group" id="absen-group-pulang" hidden>
      <h2>Absen pulang</h2>
      <div class="absen-list" id="absen-list-pulang"></div>
    </section>
    <section class="absen-laporan" id="absen-laporan" hidden>
      <h2>Laporan harian FWA</h2>
      <p class="face-note">Wajib diisi sebelum absen pulang FWA: apa yang dikerjakan &amp; tautan bukti dukung.</p>
      <ol class="absen-laporan__list" id="laporan-list"></ol>
      <form class="absen-laporan__form" id="laporan-form">
        <textarea id="laporan-uraian" rows="2" maxlength="1000" placeholder="Uraian kegiatan, mis. Menyusun draf SK mutasi" required></textarea>
        <input type="url" id="laporan-url" maxlength="500" placeholder="URL bukti dukung (https://…)" required>
        <button type="submit" class="face-btn">Tambah kegiatan</button>
        <p class="face-note" id="laporan-pesan" role="status"></p>
      </form>
    </section>
    <div class="absen-pilih" id="absen-pilih" hidden role="dialog" aria-modal="true" aria-labelledby="absen-pilih-judul">
      <div class="absen-pilih__kotak">
        <h2 id="absen-pilih-judul">Absen masuk hari ini</h2>
        <p class="face-note">Pilih tempat bekerja hari ini.</p>
        <div class="absen-list" id="absen-pilih-list"></div>
        <button type="button" class="face-link" id="absen-pilih-batal">Batal</button>
      </div>
    </div>
    <button type="button" class="face-link" id="absen-refresh">Perbarui sekarang</button>
    <p class="face-note">Tombol aktif bila <b>jadwal</b> dan <b>lokasi</b> sesuai; lokasi &amp; jarak diperbarui otomatis saat Anda bergerak.</p>
    <p class="face-note" id="absen-info"></p>
  </div>
  <div class="face-card" id="face-record-card" hidden>
    <div class="face-head">
      <span class="face-head__icon" id="face-status-icon" aria-hidden="true">◎</span>
      <div>
        <h1 id="face-status-title">Perekaman Wajah</h1>
        <p id="face-status-sub" role="status">Memuat status wajah…</p>
      </div>
    </div>

    <p id="face-review" class="face-review" role="alert" hidden></p>

    <div class="face-compare" aria-label="Pencocokan dengan foto SIMPEG">
      <figure><img id="face-simpeg-photo" alt="Foto SIMPEG Anda" hidden /><span id="face-simpeg-empty" class="face-compare__empty">Memuat foto SIMPEG…</span><figcaption>Foto SIMPEG</figcaption></figure>
      <figure><img id="face-snapshot" alt="Cuplikan hasil kamera" hidden /><span id="face-snapshot-empty" class="face-compare__empty">Belum ada cuplikan</span><figcaption>Hasil kamera</figcaption></figure>
      <p id="face-compare-result" class="face-compare__result" hidden></p>
    </div>

    <p class="face-consent">Dengan menekan tombol di bawah, Anda menyetujui perekaman <b>template wajah terenkripsi</b> untuk verifikasi identitas di Web dan Android. Gambar kamera hanya diproses sementara dan tidak disimpan sebagai foto.</p>
    <button id="face-main" class="face-btn" type="button" disabled>Rekam wajah</button>
    <button id="face-simulasi" class="face-link" type="button" hidden>Simulasi absensi</button>
    <p class="face-note" id="face-no-blink" hidden>Mode tanpa kedip aktif untuk akun Anda (diatur admin): gerakan hanya menoleh kiri/kanan.</p>
    <p class="face-note">Gunakan cahaya yang cukup dan lepaskan masker. Kacamata boleh dipakai selama mata terlihat jelas.</p>
  </div>

  <!-- Popup kamera -->
  <div class="face-modal" id="face-modal" role="dialog" aria-modal="true" aria-labelledby="face-modal-title" hidden data-state="loading">
    <div class="face-modal__panel">
      <div class="face-modal__bar">
        <button type="button" class="face-icon-btn" id="face-close" aria-label="Tutup">✕</button>
        <strong id="face-modal-title">Perekaman wajah</strong>
        <button type="button" class="face-icon-btn" id="face-mute" aria-label="Matikan suara" aria-pressed="false">🔊</button>
      </div>

      <div class="face-choose" id="face-choose" hidden>
        <p class="face-choose__title">Simulasi absensi</p>
        <p class="face-choose__text"><?php if (defined('FACE_SIMULASI_LENGKAP') && FACE_SIMULASI_LENGKAP): ?>Wajah, lokasi, dan jadwal Anda diperiksa seperti absen sungguhan, tetapi <b>tidak dicatat</b>.<?php else: ?>Hanya wajah Anda yang diperiksa (lokasi &amp; waktu belum). Simulasi <b>tidak dicatat</b> sebagai absen.<?php endif; ?></p>
        <button type="button" class="face-btn" id="face-choose-masuk">Simulasi absen masuk</button>
        <button type="button" class="face-btn face-btn--soft" id="face-choose-pulang">Simulasi absen pulang</button>
      </div>

      <div class="face-stage" id="face-stage">
        <div class="face-circle">
          <video id="face-video" autoplay playsinline muted></video>
          <svg class="face-ring" viewBox="0 0 120 120" aria-hidden="true">
            <circle class="face-ring__track" cx="60" cy="60" r="56"></circle>
            <circle class="face-ring__progress" id="face-ring-progress" cx="60" cy="60" r="56"></circle>
          </svg>
          <span class="face-arrow face-arrow--left" id="face-arrow-left" aria-hidden="true" hidden>‹</span>
          <span class="face-arrow face-arrow--right" id="face-arrow-right" aria-hidden="true" hidden>›</span>
          <span class="face-blink" id="face-blink" aria-hidden="true" hidden>👁</span>
          <div class="face-result-icon" id="face-result-icon" aria-hidden="true"></div>
        </div>
        <p class="face-instruction" id="face-instruction" aria-live="assertive">Menyiapkan kamera…</p>
        <p class="face-hint" id="face-hint" aria-live="polite"></p>
        <ul class="face-checks" id="face-checks" hidden></ul>
      </div>

      <div class="face-modal__actions" id="face-modal-actions" hidden>
        <button type="button" class="face-btn" id="face-done">Selesai</button>
        <button type="button" class="face-btn face-btn--soft" id="face-retry" hidden>Coba lagi</button>
      </div>
    </div>
  </div>
</section>
<script type="module" src="<?= e(url('assets/js/wajah.js')) ?>?v=<?= (int) @filemtime(__DIR__ . '/assets/js/wajah.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
