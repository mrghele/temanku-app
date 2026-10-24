<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
middleware('absensi.view');
require __DIR__ . '/includes/repository.php';
$page='profil'; $pageTitle='Perekaman Wajah';
// Unit bermetode LOKASI (diatur super admin di APEL): rekam wajah belum diaktifkan.
if (is_file(__DIR__ . '/app/fitur.php')) require_once __DIR__ . '/app/fitur.php';
$metodeAbsen = function_exists('temanku_metode_absen') ? temanku_metode_absen() : 'WAJAH';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/mobile_top.php';
if ($metodeAbsen !== 'WAJAH'):
?>
<link rel="stylesheet" href="<?= e(url('assets/css/wajah.css')) ?>?v=<?= (int) @filemtime(__DIR__ . '/assets/css/wajah.css') ?>" />
<section class="face-page"><div class="face-card">
  <div class="face-head"><span class="face-head__icon" aria-hidden="true">📍</span>
    <div><h1>Absen dengan lokasi</h1><p>Unit kerja Anda memakai <b>absen lokasi</b>. Rekam wajah belum diperlukan.</p></div></div>
  <p class="face-note">Absen cukup dari halaman Absen: tombol aktif bila jadwal dan lokasi Anda sesuai. Bila BKPSDM mengaktifkan absen wajah untuk unit Anda, halaman ini akan menampilkan perekaman wajah.</p>
  <a class="face-btn" href="<?= e(url('absen.php')) ?>" style="text-align:center;text-decoration:none">Buka halaman Absen</a>
</div></section>
<?php require __DIR__ . '/includes/footer.php'; return; endif; ?>
<link rel="stylesheet" href="<?= e(url('assets/css/wajah.css')) ?>?v=<?= (int) @filemtime(__DIR__ . '/assets/css/wajah.css') ?>" />
<section id="face-app" class="face-page" data-api="<?= e(url('wajah_api.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-assets="<?= e(url('assets/vendor/mediapipe')) ?>" data-foto="<?= e(url('foto_saya.php')) ?>" data-foto-simpeg="<?= trim((string) ((user() ?: [])['foto'] ?? '')) !== '' ? '1' : '0' ?>" data-simulasi="<?= (defined('FACE_SIMULASI_LENGKAP') && FACE_SIMULASI_LENGKAP) ? 'lengkap' : 'wajah' ?>">
  <div class="face-card">
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
