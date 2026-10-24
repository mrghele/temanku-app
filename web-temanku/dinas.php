<?php
declare(strict_types=1);
/*
 * Pengajuan dinas oleh ASN (TEMANKU web). Status 'diajukan' sampai disetujui admin OPD di APEL;
 * setelah disetujui, absen wajah menyesuaikan (Dinas DALAM: Absen Dinas; Dinas LUAR: tidak perlu absen)
 * dan rekap mencatatnya. Surat tugas (PDF) diunggah ke penyimpanan dokumen yang sama dengan APEL.
 */
require __DIR__ . '/app/bootstrap.php';
middleware('absensi.view');

const DINAS_PDF_MAX = 2 * 1024 * 1024;

function dinas_upload_surat(string $refId, string $file, string $name): bool
{
    $serviceToken = api_service_token();
    if (!$serviceToken) return false;
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $serviceToken];
    if (!empty($_SESSION['access_token'])) $headers[] = 'Auth: Bearer ' . $_SESSION['access_token'];
    $ch = curl_init(rtrim(API_TEMANKU, '/') . '/upload-dok');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => ['kategori' => 'dinas', 'tipe' => 'usul', 'ref_id' => $refId,
            'file' => new CURLFile($file, 'application/pdf', preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'surat_tugas.pdf')],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($response) ? json_decode($response, true) : null;
    return $status >= 200 && $status < 300 && is_array($data) && empty($data['error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // CSRF diperiksa bootstrap.php
    $jenis = ($_POST['jenis'] ?? '') === 'LUAR' ? 'LUAR' : 'DALAM';
    $tempat = trim((string) ($_POST['tempat'] ?? ''));
    $awal = (string) ($_POST['tanggal_awal'] ?? '');
    $akhir = (string) ($_POST['tanggal_akhir'] ?? '');
    $kegiatan = trim((string) ($_POST['keterangan'] ?? ''));
    $tujuanKab = $jenis === 'LUAR' ? trim((string) ($_POST['tujuan_kabkota'] ?? '')) : '';
    $tujuanKec = $jenis === 'DALAM' ? trim((string) ($_POST['tujuan_kecamatan'] ?? '')) : '';
    $tujuanDesa = $jenis === 'DALAM' ? trim((string) ($_POST['tujuan_desa'] ?? '')) : '';
    $file = $_FILES['surat'] ?? null;
    $error = '';
    if ($tempat === '' || strlen($tempat) > 255) $error = 'Tempat wajib diisi.';
    elseif ($kegiatan === '' || strlen($kegiatan) > 1000) $error = 'Kegiatan wajib diisi.';
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $awal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $akhir) || $akhir < $awal) $error = 'Tanggal tidak valid.';
    elseif ((strtotime($akhir) - strtotime($awal)) > 90 * 86400) $error = 'Rentang dinas maksimal 90 hari.';
    elseif ($jenis === 'LUAR' && !preg_match('/^[0-9A-Z]{4,10}$/', $tujuanKab)) $error = 'Pilih kabupaten/kota tujuan.';
    elseif ($jenis === 'DALAM' && ($tujuanKec === '' || strlen($tujuanKec) > 100 || strlen($tujuanDesa) > 100)) $error = 'Pilih kecamatan tujuan.';
    elseif (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $error = 'Surat tugas (PDF) wajib diunggah.';
    elseif ($file['size'] > DINAS_PDF_MAX) $error = 'Surat tugas maksimal 2 MB.';
    elseif (file_get_contents($file['tmp_name'], false, null, 0, 5) !== '%PDF-') $error = 'Surat tugas harus berformat PDF.';
    if ($error === '') {
        $saved = api_request(API_TEMANKU, 'POST', '/portal/dinas/pengajuan',
            ['jenis' => $jenis, 'tempat' => $tempat, 'tanggal_awal' => $awal, 'tanggal_akhir' => $akhir, 'keterangan' => $kegiatan,
             'tujuan_kabkota' => $tujuanKab, 'tujuan_kecamatan' => $tujuanKec, 'tujuan_desa' => $tujuanDesa]);
        $id = is_array($saved) ? (string) ($saved['id'] ?? '') : '';
        if ($id === '') {
            $error = 'Pengajuan belum tersimpan. Periksa data lalu coba lagi.';
        } elseif (!dinas_upload_surat($id, $file['tmp_name'], (string) $file['name'])) {
            flash('Pengajuan tersimpan, tetapi surat tugas gagal diunggah. Hubungi admin OPD.', 'bahaya');
            redirect('dinas.php');
        }
    }
    flash($error === '' ? 'Pengajuan dinas terkirim. Menunggu persetujuan admin OPD.' : $error, $error === '' ? 'sukses' : 'bahaya');
    redirect('dinas.php');
}

require __DIR__ . '/includes/repository.php';
$page = 'absensi'; $pageTitle = 'Pengajuan Dinas';
require __DIR__ . '/includes/header.php';
$list = api_request(API_TEMANKU, 'GET', '/portal/dinas/pengajuan')['data'] ?? [];
$kecamatan = api_request(API_TEMANKU, 'GET', '/wilayah/selayar')['data'] ?? [];
$kabkota = api_request(API_TEMANKU, 'GET', '/wilayah/kabkota')['data'] ?? [];
$perProvinsi = [];
foreach ($kabkota as $k) {
    if (stripos((string) ($k['nama'] ?? ''), 'selayar') !== false) continue; // [temanku] dinas luar daerah: bukan Kab. Kep. Selayar
    $perProvinsi[$k['provinsi']][] = $k;
}
$label = ['diajukan' => 'Menunggu persetujuan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];
?>
<style>
.dns{max-width:640px;margin:24px auto 100px;padding:0 16px}
.dns h1{font-size:20px;color:#123f40;margin:0 0 4px}.dns__lead{color:#5f7474;font-size:13.5px;line-height:1.6;margin:0 0 14px}
.dns__card{background:#fff;border-radius:18px;padding:18px;margin-bottom:14px;box-shadow:0 6px 24px #123b3c0f}
.dns label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:#5f7474;margin-bottom:10px}
.dns input,.dns textarea,.dns select{padding:10px;border:1px solid #d8e3e2;border-radius:10px;font:inherit}
.dns__grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.dns [hidden]{display:none!important} /* [temanku] atribut hidden harus mengalahkan display:flex/grid */
.dns__tetap{font-size:13px;color:#24403f;background:#f2f7f6;border-radius:10px;padding:10px 12px;margin-bottom:10px}
.dns button{width:100%;padding:13px;border:0;border-radius:14px;background:#168f90;color:#fff;font-weight:700;cursor:pointer}
.dns__item{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid #eef2f2;font-size:13px}
.dns__item:first-child{border-top:0}.dns__item small{display:block;color:#6b7280}
.dns__st{white-space:nowrap;padding:2px 10px;border-radius:999px;font-size:11.5px;font-weight:700;align-self:flex-start}
.st-diajukan{background:#fff6e0;color:#8a5a00}.st-disetujui{background:#e8f6ee;color:#14663a}.st-ditolak{background:#fff1f0;color:#8a2c22}
</style>
<section class="dns">
  <h1>Pengajuan Dinas</h1>
  <p class="dns__lead">Ajukan surat tugas Anda. Setelah <b>disetujui admin OPD</b>, absen lewat tombol <i>Absen Dinas</i>:
    Dinas Dalam Daerah → di kecamatan/desa tujuan; Dinas Luar Daerah → sekali sehari di kabupaten/kota tujuan (hari berangkat &amp; kembali dibebaskan).</p>
  <form class="dns__card" method="post" action="<?= e(url('dinas.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label>Jenis dinas<select name="jenis" id="dinas-jenis"><option value="DALAM">Dinas Dalam Daerah (Kab. Kep. Selayar)</option><option value="LUAR">Dinas Luar Daerah</option></select></label>
    <div id="tujuan-dalam"><p class="dns__tetap">Kabupaten: <b>Kepulauan Selayar</b></p>
    <div class="dns__grid">
      <label>Kecamatan tujuan<select name="tujuan_kecamatan" id="tujuan-kec"><option value="">-- Pilih --</option>
        <?php foreach ($kecamatan as $k): ?><option value="<?= e($k['kecamatan']) ?>" data-desa="<?= e(json_encode($k['desa'])) ?>"><?= e($k['kecamatan']) ?></option><?php endforeach; ?></select></label>
      <label>Desa/kelurahan (opsional)<select name="tujuan_desa" id="tujuan-desa"><option value="">-- Seluruh kecamatan --</option></select></label>
    </div></div>
    <label id="tujuan-luar" hidden>Kabupaten/kota tujuan<select name="tujuan_kabkota"><option value="">-- Pilih --</option>
      <?php foreach ($perProvinsi as $prov => $items): ?><optgroup label="<?= e($prov) ?>"><?php foreach ($items as $k): ?><option value="<?= e($k['kode']) ?>"><?= e($k['nama']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
    <p class="dns__lead" style="margin:0 0 10px" id="info-dalam">Absen dinas sah bila lokasi GPS Anda berada di kecamatan/desa tujuan.</p>
    <p class="dns__lead" style="margin:0 0 10px" id="info-luar" hidden>Absen dinas sekali sehari, sah bila lokasi GPS Anda berada di kabupaten/kota tujuan. Hari berangkat &amp; hari kembali dibebaskan.</p>
    <label>Tempat<input type="text" name="tempat" maxlength="255" required placeholder="Contoh: Aula Kec. Bontoharu / Makassar"></label>
    <div class="dns__grid">
      <label>Tanggal mulai<input type="date" name="tanggal_awal" required></label>
      <label>Tanggal selesai<input type="date" name="tanggal_akhir" required></label>
    </div>
    <label>Kegiatan<textarea name="keterangan" rows="2" maxlength="1000" required placeholder="Contoh: Monitoring dan evaluasi"></textarea></label>
    <label>Surat tugas (PDF, maks. 2 MB)<input type="file" name="surat" accept="application/pdf" required></label>
    <button type="submit">Kirim pengajuan</button>
  </form>
  <div class="dns__card">
    <h2 style="font-size:15px;margin:0 0 6px;color:#123f40">Riwayat pengajuan</h2>
    <?php foreach ($list as $d): ?>
      <div class="dns__item">
        <div><b><?= e($d['jenis'] === 'LUAR' ? 'Dinas Luar' : 'Dinas Dalam') ?> · <?= e($d['tempat']) ?></b>
          <small><?= e($d['tanggal_awal']) ?> s.d. <?= e($d['tanggal_akhir']) ?> — <?= e($d['keterangan']) ?></small>
          <?php if (!empty($d['catatan_admin'])): ?><small>Catatan admin: <?= e($d['catatan_admin']) ?></small><?php endif; ?></div>
        <span class="dns__st st-<?= e($d['status']) ?>"><?= e($label[$d['status']] ?? $d['status']) ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$list): ?><p style="color:#7a8c8c;font-size:13px;margin:0">Belum ada pengajuan.</p><?php endif; ?>
  </div>
</section>
<script>
(function () {
  const jenis = document.getElementById('dinas-jenis'), kec = document.getElementById('tujuan-kec'), desa = document.getElementById('tujuan-desa');
  const atur = () => { const luar = jenis.value === 'LUAR'; document.getElementById('tujuan-dalam').hidden = luar; document.getElementById('tujuan-luar').hidden = !luar;
    document.getElementById('info-dalam').hidden = luar; document.getElementById('info-luar').hidden = !luar;
    kec.required = !luar; document.querySelector('[name=tujuan_kabkota]').required = luar; };
  kec.addEventListener('change', () => {
    const opt = kec.selectedOptions[0], daftar = opt && opt.dataset.desa ? JSON.parse(opt.dataset.desa) : [];
    desa.replaceChildren(new Option('-- Seluruh kecamatan --', ''), ...daftar.map(d => new Option(d, d)));
  });
  jenis.addEventListener('change', atur); atur();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
