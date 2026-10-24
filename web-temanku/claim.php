<?php
declare(strict_types=1);
/*
 * Claim Presensi: tugas di luar kantor yang membuat pegawai tidak dapat absen di kantor (dipanggil rapat
 * RAPIM, undangan kegiatan di Ruang Pola, mewakili pimpinan di kecamatan lain, ...). Disetujui admin OPD
 * di APEL; setelah disetujui catatan absen hari itu disesuaikan (ditandai claim di rekap).
 */
require __DIR__ . '/app/bootstrap.php';
middleware('absensi.view');
require_once __DIR__ . '/app/layanan.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // CSRF diperiksa bootstrap.php
    $jenis = in_array($_POST['jenis'] ?? '', ['masuk', 'pulang', 'keduanya'], true) ? $_POST['jenis'] : 'masuk';
    $payload = [
        'tanggal' => (string) ($_POST['tanggal'] ?? ''), 'jenis' => $jenis,
        'jam_masuk' => $jenis !== 'pulang' ? (string) ($_POST['jam_masuk'] ?? '') : '',
        'jam_pulang' => $jenis !== 'masuk' ? (string) ($_POST['jam_pulang'] ?? '') : '',
        'kategori' => (string) ($_POST['kategori'] ?? ''), 'kegiatan' => trim((string) ($_POST['kegiatan'] ?? '')),
        'tempat' => trim((string) ($_POST['tempat'] ?? '')),
    ];
    if (is_numeric($_POST['latitude'] ?? null) && is_numeric($_POST['longitude'] ?? null)) {
        $payload['latitude'] = (float) $_POST['latitude'];
        $payload['longitude'] = (float) $_POST['longitude'];
    }
    $file = $_FILES['bukti'] ?? null;
    $mime = '';
    $error = '';
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $error = 'Bukti (undangan / surat / foto kegiatan) wajib diunggah.';
    elseif ($file['size'] > LAYANAN_FILE_MAX) $error = 'Bukti maksimal 2 MB.';
    elseif (($mime = layanan_jenis_berkas($file['tmp_name'])) === '') $error = 'Bukti harus PDF, JPG, atau PNG.';
    if ($error === '') {
        $saved = layanan_post('/portal/claim', $payload);
        $id = $saved['ok'] ? (string) ($saved['data']['id'] ?? '') : '';
        if ($id === '') {
            $error = $saved['error'] ?? 'Claim belum tersimpan. Coba lagi.';
        } elseif (!layanan_upload_dok('claim', 'bukti', $id, $file['tmp_name'], (string) $file['name'], $mime)) {
            flash('Claim tersimpan, tetapi bukti gagal diunggah. Hubungi admin OPD.', 'bahaya');
            redirect('claim.php');
        }
    }
    flash($error === '' ? 'Claim presensi terkirim. Menunggu persetujuan admin OPD.' : $error, $error === '' ? 'sukses' : 'bahaya');
    redirect('claim.php');
}

require __DIR__ . '/includes/repository.php';
$page = 'absensi'; $pageTitle = 'Claim Presensi';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/unggah.php';
$list = api_request(API_TEMANKU, 'GET', '/portal/claim')['data'] ?? [];
$hariIni = (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')));
echo layanan_css();
?>
<section class="lyn">
  <h1>Claim Presensi</h1>
  <p class="lyn__lead">Untuk tugas di luar kantor yang membuat Anda tidak dapat absen di kantor, mis. <b>dipanggil rapat RAPIM</b>, <b>undangan kegiatan di Ruang Pola</b>,
    atau <b>mewakili pimpinan</b> di tempat lain. Diajukan paling lama <b>3 hari</b> setelahnya, dengan bukti; disetujui admin OPD.</p>
  <form class="lyn__card" method="post" action="<?= e(url('claim.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="lyn__grid">
      <label>Tanggal<input type="date" name="tanggal" required max="<?= e($hariIni->format('Y-m-d')) ?>" min="<?= e($hariIni->modify('-3 day')->format('Y-m-d')) ?>" value="<?= e($hariIni->format('Y-m-d')) ?>"></label>
      <label>Yang di-claim<select name="jenis" id="claim-jenis"><option value="masuk">Absen masuk</option><option value="pulang">Absen pulang</option><option value="keduanya">Masuk &amp; pulang</option></select></label>
    </div>
    <div class="lyn__grid">
      <label id="claim-masuk">Jam mulai tugas<input type="time" name="jam_masuk" value="07:30"></label>
      <label id="claim-pulang" hidden>Jam selesai tugas<input type="time" name="jam_pulang" value="16:00"></label>
    </div>
    <label>Kategori<select name="kategori"><option value="rapat">Rapat / dipanggil pimpinan</option><option value="undangan">Undangan kegiatan</option>
      <option value="mewakili">Mewakili pimpinan</option><option value="lainnya">Tugas lainnya</option></select></label>
    <label>Kegiatan<textarea name="kegiatan" rows="2" maxlength="500" required placeholder="Contoh: Rapat Pimpinan (RAPIM) membahas APBD"></textarea></label>
    <label>Tempat<input type="text" name="tempat" maxlength="255" required placeholder="Contoh: Ruang Pola Kantor Bupati"></label>
    <?= unggah_field('bukti', 'Bukti undangan / surat / foto kegiatan', 'application/pdf,image/jpeg,image/png', 'PDF, JPG, atau PNG', true) ?>
    <input type="hidden" name="latitude" id="claim-lat"><input type="hidden" name="longitude" id="claim-lng">
    <button type="submit">Kirim claim</button>
  </form>
  <div class="lyn__card">
    <h2 style="font-size:15px;margin:0 0 6px;color:#123f40">Riwayat claim</h2>
    <?php foreach ($list as $d): ?>
      <div class="lyn__item">
        <div><b><?= e($d['tanggal']) ?> · <?= e(['masuk' => 'Masuk ' . $d['jam_masuk'], 'pulang' => 'Pulang ' . $d['jam_pulang'], 'keduanya' => $d['jam_masuk'] . '–' . $d['jam_pulang']][$d['jenis']] ?? '') ?></b>
          <small><?= e($d['kategori_nama']) ?> — <?= e($d['kegiatan']) ?> (<?= e($d['tempat']) ?>)</small>
          <?php if (!empty($d['catatan_admin'])): ?><small>Catatan admin: <?= e($d['catatan_admin']) ?></small><?php endif; ?></div>
        <span class="lyn__st st-<?= e($d['status']) ?>"><?= e(layanan_status_label((string) $d['status'])) ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$list): ?><p style="color:#7a8c8c;font-size:13px;margin:0">Belum ada claim.</p><?php endif; ?>
  </div>
</section>
<script>
(function () {
  const jenis = document.getElementById('claim-jenis');
  const atur = () => { document.getElementById('claim-masuk').hidden = jenis.value === 'pulang'; document.getElementById('claim-pulang').hidden = jenis.value === 'masuk'; };
  jenis.addEventListener('change', atur); atur();
  // Lokasi saat mengajukan (bila diizinkan) membantu admin OPD memeriksa claim yang diajukan di tempat kegiatan.
  if (navigator.geolocation) navigator.geolocation.getCurrentPosition(p => {
    document.getElementById('claim-lat').value = p.coords.latitude; document.getElementById('claim-lng').value = p.coords.longitude;
  }, () => {}, { enableHighAccuracy: true, timeout: 15000 });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
