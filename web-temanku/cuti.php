<?php
declare(strict_types=1);
/* Ajukan Cuti: diajukan ASN, disetujui admin OPD lewat menu Cuti di APEL (alur cuti APEL yang sudah ada).
 * Setelah disetujui, tombol absen tertutup pada hari cuti & rekap mencatat CUTI. */
require __DIR__ . '/app/bootstrap.php';
middleware('absensi.view');
require_once __DIR__ . '/app/layanan.php';
const CUTI_JENIS = ['Cuti Tahunan', 'Cuti Sakit', 'Cuti Besar', 'Cuti Melahirkan', 'Cuti Alasan Penting', 'Cuti Di Luar Tanggungan Negara'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // CSRF diperiksa bootstrap.php
    $jenis = (string) ($_POST['jenis_cuti'] ?? '');
    $mulai = (string) ($_POST['tanggal_mulai'] ?? '');
    $selesai = (string) ($_POST['tanggal_selesai'] ?? '');
    $alasan = trim((string) ($_POST['alasan'] ?? ''));
    $error = '';
    if (!in_array($jenis, CUTI_JENIS, true)) $error = 'Pilih jenis cuti.';
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai) || $selesai < $mulai) $error = 'Tanggal cuti tidak valid.';
    elseif (strlen($alasan) < 5) $error = 'Alasan cuti wajib diisi.';
    if ($error === '') {
        $saved = layanan_post('/portal/cuti/pengajuan', ['jenis_cuti' => $jenis, 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai, 'alasan' => $alasan]);
        if (!$saved['ok'] || empty($saved['data']['id'])) $error = $saved['error'] ?? 'Pengajuan cuti belum tersimpan. Coba lagi.';
    }
    flash($error === '' ? 'Pengajuan cuti terkirim. Menunggu persetujuan admin OPD.' : $error, $error === '' ? 'sukses' : 'bahaya');
    redirect('cuti.php');
}

require __DIR__ . '/includes/repository.php';
$page = 'absensi'; $pageTitle = 'Ajukan Cuti';
require __DIR__ . '/includes/header.php';
$list = api_request(API_TEMANKU, 'GET', '/portal/cuti/pengajuan')['data'] ?? [];
echo layanan_css();
?>
<section class="lyn">
  <h1>Ajukan Cuti</h1>
  <p class="lyn__lead">Pengajuan diteruskan ke admin OPD (menu Cuti di APEL). Setelah <b>disetujui</b>, absen pada hari cuti tidak diperlukan dan rekap mencatat <b>CUTI</b>.</p>
  <form class="lyn__card" method="post" action="<?= e(url('cuti.php')) ?>">
    <?= csrf_field() ?>
    <label>Jenis cuti<select name="jenis_cuti" required><option value="">-- Pilih --</option>
      <?php foreach (CUTI_JENIS as $j): ?><option value="<?= e($j) ?>"><?= e($j) ?></option><?php endforeach; ?></select></label>
    <div class="lyn__grid">
      <label>Tanggal mulai<input type="date" name="tanggal_mulai" required></label>
      <label>Tanggal selesai<input type="date" name="tanggal_selesai" required></label>
    </div>
    <label>Alasan<textarea name="alasan" rows="2" maxlength="1000" required></textarea></label>
    <button type="submit">Kirim pengajuan</button>
  </form>
  <div class="lyn__card">
    <h2 style="font-size:15px;margin:0 0 6px;color:#123f40">Riwayat cuti</h2>
    <?php foreach ($list as $d): ?>
      <div class="lyn__item">
        <div><b><?= e($d['jenis_cuti']) ?> · <?= (int) $d['jumlah_hari'] ?> hari</b><small><?= e($d['tanggal_mulai']) ?> s.d. <?= e($d['tanggal_selesai']) ?> — <?= e($d['alasan']) ?></small>
          <?php if (!empty($d['catatan_admin'])): ?><small>Catatan admin: <?= e($d['catatan_admin']) ?></small><?php endif; ?></div>
        <span class="lyn__st st-<?= e($d['status']) ?>"><?= e(layanan_status_label((string) $d['status'])) ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$list): ?><p style="color:#7a8c8c;font-size:13px;margin:0">Belum ada pengajuan cuti.</p><?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
