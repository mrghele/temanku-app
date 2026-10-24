<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';
require __DIR__ . '/includes/riwayat_asn.php';
require __DIR__ . '/includes/riwayat_usulan.php';

$page      = 'riwayat';
$pageTitle = 'Riwayat ASN';
require __DIR__ . '/includes/header.php';

// Kategori & data mengikuti tab riwayat SIMPEG untuk jenis ASN pegawai (PNS / PPPK / PPPK paruh waktu).
$pegawai  = riwayat_pegawai();
$meta     = $pegawai ? riwayat_kategori_daftar($pegawai) : [];
$minta    = is_string($_GET['kategori'] ?? null) ? $_GET['kategori'] : '';
$aktif    = isset($meta[$minta]) || ($minta === 'identitas' && $pegawai) ? $minta : null;
$segar    = ($_GET['segar'] ?? '') === '1';
$data     = [];
foreach ($meta as $key => $_) $data[$key] = riwayat_data($pegawai, $key, $segar);
// Usulan perubahan riwayat milik sendiri (semua jenis); null bila API usulan gagal.
$usulanSemua = $pegawai ? riwayat_usulan_daftar($pegawai) : [];
$usulanMenunggu = [];
foreach ($meta as $key => $_) {
    $usulanMenunggu[$key] = count(array_filter($usulanSemua ?? [], static function (array $x) use ($key): bool {
        return in_array($x['jenis'] ?? '', RIWAYAT_KATEGORI_JENIS[$key] ?? [], true) && in_array($x['status'] ?? '', RIWAYAT_STATUS_UBAH, true);
    }));
}
?>
<?php require __DIR__ . '/includes/mobile_top.php'; ?>
<h1 class="history-page__heading rw-h1"><span class="history-page__desktop-title">Riwayat ASN</span><span class="history-page__mobile-title">Data Riwayat ASN</span></h1>
<p class="page__sub history-page__subtitle rw-sub-h1">Bersumber dari SIMPEG &amp; SIASN BKN · perubahan diajukan lewat usulan</p>
<link rel="stylesheet" href="<?= url('assets/css/riwayat.css') ?>?v=<?= (int)@filemtime(__DIR__ . '/assets/css/riwayat.css') ?>">


<?php if (!$pegawai): ?>
<section class="card" style="margin-top:14px">
  <p style="margin:0;font-size:13px;color:var(--muted-2)">Data kepegawaian untuk NIP <?= e(current_nip()) ?> belum ditemukan di SIMPEG. Silakan hubungi admin sekolah atau BKPSDM.</p>
</section>
<?php endif; ?>

<?php
// Navigasi kategori: desktop = daftar di kiri (konten di kanan), mobile = grid ikon seperti aplikasi (Gojek/MyTelkomsel).
// Tanpa ?kategori: desktop membuka Identitas, mobile menampilkan grid ikon dulu.
$awal = $aktif === null && $pegawai;
if ($awal) $aktif = 'identitas';
$navItems = $pegawai ? ['identitas' => ['Identitas', 'bi-person-vcard-fill', 'tosca']] + $meta : [];
?>
<div class="rw-layout<?= $awal ? ' rw-layout--awal' : '' ?>">
  <?php if ($navItems): ?>
  <nav class="rw-nav" aria-label="Kategori riwayat">
    <?php foreach ($navItems as $key => [$label, $icon, $warna]):
        $r = array_key_exists($key, $data) ? $data[$key] : []; $menungguKat = $usulanMenunggu[$key] ?? 0; ?>
      <a class="rw-nav__item<?= $aktif === $key ? ' is-aktif' : '' ?>" href="<?= url('riwayat.php?kategori=' . $key) ?>"<?= $aktif === $key ? ' aria-current="page"' : '' ?>>
        <span class="rw-nav__ico ico--<?= e($warna) ?>"><i class="bi <?= e($icon) ?>"></i><?php if ($menungguKat): ?><b class="rw-nav__dot" title="<?= (int)$menungguKat ?> usulan diproses"></b><?php endif; ?></span>
        <span class="rw-nav__label"><?= e(preg_replace('/^Riwayat /', '', $label)) ?></span>
        <?php if ($key !== 'identitas'): ?><span class="rw-nav__num<?= $r === null ? ' is-gagal' : '' ?>"><?= $r === null ? '!' : count($r) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <div class="rw-main">
<?php if (!$pegawai): ?>
<?php elseif ($aktif === 'identitas'): $idt = riwayat_identitas($pegawai, $segar); ?>
<a class="rw-kembali" href="<?= url('riwayat.php') ?>"><i class="bi bi-arrow-left"></i> Kategori</a>
<section class="rw-panel" id="rw-panel">
  <div class="rw-panel__head">
    <div class="rw-panel__title"><h2>Identitas</h2><small>Data utama SIMPEG · SIASN BKN</small></div>
    <div class="rw-toolbar" role="toolbar" aria-label="Aksi identitas">
      <?php if ($idt && $idt['ubahSiap'] && RIWAYAT_UBAH_IDENTITAS): ?><button type="button" class="rw-pill rw-pill--utama" data-rw="ubah-identitas"><i class="bi bi-pencil"></i><span>Ubah</span></button><?php endif; ?>
    </div>
  </div>
  <?php if ($idt === null): ?>
    <div class="rw-empty rw-empty--gagal"><i class="bi bi-cloud-slash"></i><p>Identitas belum dapat dimuat dari SIMPEG<?= isset($GLOBALS['riwayat_api_kode']) ? ' (kode ' . (int)$GLOBALS['riwayat_api_kode'] . ')' : '' ?>.</p></div>
  <?php else: $ub = $idt['ubah']; ?>
    <div class="rw-idt">
      <aside class="rw-idt__head">
        <img src="<?= e($u['foto'] ? url('foto_saya.php') . '?v=' . substr(md5((string)$u['foto']), 0, 10) : url('assets/img/user.svg')) ?>" onerror="this.onerror=null;this.src='<?= e(url('assets/img/user.svg')) ?>'" alt="">
        <div>
          <strong><?= e($idt['nama']) ?></strong><span><?= e($idt['nip']) ?></span>
          <?php if (!empty($u['golongan'])): ?><em class="rw-idt__gol"><i class="bi bi-award"></i> Gol. <?= e($u['golongan']) ?></em><?php endif; ?>
        </div>
      </aside>
      <div class="rw-idt__isi">
        <section class="rw-grup rw-grup--ubah" id="rw-idt-ubah">
          <h3><i class="bi bi-pencil-square"></i> <?= RIWAYAT_UBAH_IDENTITAS ? 'Data yang dapat diubah' : 'Data pribadi' ?></h3>
          <dl class="rw-rows" id="rw-idt-nilai">
            <?php foreach ([['Agama', $ub['agama'], 'bi-moon-stars'], ['Status perkawinan', $ub['kawin'], 'bi-heart'], ['Golongan darah', $ub['golDarah'], 'bi-droplet'],
                            ['Tempat lahir', $ub['tempatLahir'], 'bi-geo-alt'], ['Nomor HP', $ub['noHp'], 'bi-phone'], ['Email pribadi', $ub['email'], 'bi-envelope'],
                            ['Email dinas', $ub['emailGov'], 'bi-envelope-at'], ['Alamat', $ub['alamat'], 'bi-house']] as [$lbl, $nilai, $ik]): ?>
              <div><dt><i class="bi <?= e($ik) ?>"></i><?= e($lbl) ?></dt><dd><?= e($nilai !== '' ? $nilai : '–') ?></dd></div>
            <?php endforeach; ?>
          </dl>
          <div id="rw-idt-form"></div>
          <?php if (!RIWAYAT_UBAH_IDENTITAS): ?><p class="rw-catatan">Perubahan data pribadi diajukan melalui admin sekolah.</p><?php elseif (!$idt['ubahSiap']): ?><p class="rw-catatan">Pengubahan aktif setelah API e-Carakdek (Go) diperbarui.</p><?php endif; ?>
        </section>
        <?php foreach ($idt['kelompok'] as $judul => $barisIdt): ?>
          <section class="rw-grup rw-grup--siasn">
            <h3><i class="bi bi-lock"></i> <?= e($judul) ?> <span class="rw-tag">SIASN · hanya baca</span></h3>
            <dl class="rw-rows">
              <?php foreach ($barisIdt as [$lbl, $nilai, $ik]): ?>
                <div><dt><i class="bi <?= e($ik) ?>"></i><?= e($lbl) ?></dt><dd><?= e($nilai) ?></dd></div>
              <?php endforeach; ?>
            </dl>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
<form id="rw-csrf" hidden><?= csrf_field() ?></form>
<script>window.RIWAYAT_CFG = <?= json_encode(['aksiUrl' => url('riwayat_aksi.php'), 'halamanUrl' => url('riwayat.php'), 'kategori' => 'identitas',
    'idt' => $idt ? $idt['ubah'] : null, 'kawin' => RIWAYAT_REF_KAWIN, 'golDarah' => RIWAYAT_REF_GOL_DARAH, 'idtBkn' => RIWAYAT_IDT_BKN],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

<?php else:
    $detail = $data[$aktif];
    $jenisKat = RIWAYAT_KATEGORI_JENIS[$aktif] ?? [];
    $izin = [];
    foreach ($jenisKat as $j) $izin[$j] = riwayat_izin_jenis($j);
    $bisaTambah = array_keys(array_filter($izin, static function (array $i): bool { return $i['tambah']; }));
    $usulanKat = array_values(array_map('riwayat_usulan_publik', array_filter($usulanSemua ?? [], static function (array $x) use ($jenisKat): bool {
        return in_array($x['jenis'] ?? '', $jenisKat, true);
    })));
    // Usulan ubah/hapus yang masih diproses per riwayat (Edit/Hapus diganti "Lihat usulan").
    $usulPerRef = [];
    foreach ($usulanKat as $us) {   // bukan $u: $u = pegawai aktif dari header.php
        if ($us['ref_id'] !== '' && in_array($us['status'], RIWAYAT_STATUS_UBAH, true)) $usulPerRef[$us['jenis'] . ':' . $us['ref_id']] = $us['id'];
    }
    $baris = [];
    foreach ($detail ?? [] as $i => $d) {
        $kunciRef = $d['jenis'] . ':' . ($d['jenis'] === 'cpns' ? $pegawai['pns_id'] : $d['id_rw']);
        $baris[] = [
            'idx' => $i, 'id_rw' => $d['id_rw'], 'jenis' => $d['jenis'], 'judul' => $d['judul'], 'sub' => $d['sub'], 'label' => $d['label'],
            'waktu' => $d['waktu'], 'info' => $d['info'], 'dok' => $d['dok'], 'awal' => $d['awal'] ?? [], 'usulan' => $usulPerRef[$kunciRef] ?? '',
            'kolom' => $d['kolom'] ?? [], 'badge' => $d['badge'] ?? '', 'b3' => $d['b3'] ?? $d['waktu'], 'lengkap' => $d['lengkap'] ?? null, 'kurang' => $d['kurang'] ?? [],
        ];
    }
    $formKat = array_intersect_key(riwayat_form_daftar(), array_flip($jenisKat));
    $cfg = [
        'kategori' => $aktif, 'sinkron' => false, 'judul' => $meta[$aktif][0], 'ikon' => $meta[$aktif][1], 'warna' => $meta[$aktif][2],
        'aksiUrl' => url('riwayat_aksi.php'), 'dokUrl' => url('riwayat_dok.php'), 'halamanUrl' => url('riwayat.php'),
        'jenis' => $jenisKat, 'jenisLabel' => array_intersect_key(RIWAYAT_JENIS_LABEL, array_flip($jenisKat)),
        'kolomJudul' => array_combine($jenisKat, array_map('riwayat_kolom_judul', $jenisKat)),
        'izin' => $izin, 'form' => $formKat, 'baris' => $baris, 'usulan' => $usulanKat, 'usulanGagal' => $usulanSemua === null,
        'gagal' => $detail === null, 'kodeGagal' => $GLOBALS['riwayat_api_kode'] ?? null,
        'pdfMaks' => RIWAYAT_PDF_MAKS, 'dokRiwayat' => RIWAYAT_DOK_RIWAYAT, 'bukaUsulan' => ($_GET['usulan'] ?? '') === '1',
        // status perkawinan "Belum Menikah" di data utama: pasangan tidak dapat ditambah (seperti SIMPEG)
        'lajang' => $aktif === 'keluarga' && (string)((riwayat_identitas($pegawai)['ubah']['jenisKawinId'] ?? '')) === '4',
    ];
    $menunggu = $usulanMenunggu[$aktif] ?? 0;
?>
<a class="rw-kembali" href="<?= url('riwayat.php') ?>"><i class="bi bi-arrow-left"></i> Kategori</a>
<section class="rw-panel" id="rw-panel">
  <div class="rw-panel__head">
    <div class="rw-panel__title">
      <h2><?= e($meta[$aktif][0]) ?></h2>
      <small><?= $detail === null ? 'Gagal dimuat' : count($detail) . ' riwayat' ?><?= $menunggu ? ' · ' . (int)$menunggu . ' usulan diproses' : '' ?></small>
    </div>
    <div class="rw-toolbar" role="toolbar" aria-label="Aksi riwayat">
      <button type="button" class="rw-pill rw-pill--biru" data-rw="usulan" aria-expanded="false" aria-controls="rw-usulan"><i class="bi bi-inboxes"></i><span>Usulan</span><?php if (count($usulanKat)): ?><b class="rw-count"><?= count($usulanKat) ?></b><?php endif; ?></button>
      <?php if ($bisaTambah): ?><button type="button" class="rw-pill rw-pill--utama" data-rw="tambah"><i class="bi bi-plus-lg"></i><span>Tambah</span></button><?php endif; ?>
    </div>
  </div>
  <div class="rw-usulan" id="rw-usulan" hidden></div>
  <?php if ($detail && count($detail) > 5): ?>
  <div class="rw-cari-bar">
    <label class="rw-cari-bar__input"><i class="bi bi-search"></i><input type="search" id="rw-cari" placeholder="Cari <?= e(strtolower($meta[$aktif][0])) ?>…" autocomplete="off" aria-label="Cari riwayat"></label>
    <select id="rw-per" aria-label="Jumlah per halaman"><option value="10">10</option><option value="25">25</option><option value="50">50</option></select>
  </div>
  <?php endif; ?>
  <div class="rw-list" id="rw-list" aria-live="polite"></div>
  <nav class="rw-pager" id="rw-pager" aria-label="Halaman riwayat" hidden></nav>
  <noscript><p class="rw-catatan">Aktifkan JavaScript untuk menampilkan riwayat.</p></noscript>
  <p class="rw-catatan"><i class="bi bi-info-circle"></i> Perubahan diajukan sebagai <b>usulan</b> dan diverifikasi admin; hasilnya muncul di <a href="<?= url('notifikasi.php') ?>">Notifikasi</a>.<?= array_diff($jenisKat, array_keys($formKat)) ? ' ' . e($meta[$aktif][0]) . ' hanya dapat dilihat.' : '' ?></p>
</section>
<form id="rw-csrf" hidden><?= csrf_field() ?></form>
<script>window.RIWAYAT_CFG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php endif; ?>
  </div>
</div>
<?php if (!$pegawai): ?><script>window.RIWAYAT_CFG = <?= json_encode(['aksiUrl' => url('riwayat_aksi.php')], JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script><?php endif; ?>
<div class="rw-badges" id="rw-badges" aria-live="polite"></div>

<!-- Popup umum / drawer (detail, form, konfirmasi), viewer PDF (pdf.js) & pemindai dokumen -->
<div class="rw-modal" id="rw-modal" hidden>
  <div class="rw-modal__box" role="dialog" aria-modal="true" aria-labelledby="rw-modal-title">
    <div class="rw-modal__head"><h3 id="rw-modal-title"></h3><button type="button" class="rw-ico" data-tutup aria-label="Tutup"><i class="bi bi-x-lg"></i></button></div>
    <div class="rw-modal__body" id="rw-modal-body"></div>
    <div class="rw-modal__foot" id="rw-modal-foot"></div>
  </div>
</div>
<div class="rw-modal rw-modal--pdf" id="rw-pdf" hidden>
  <div class="rw-modal__box" role="dialog" aria-modal="true" aria-labelledby="rw-pdf-title">
    <div class="rw-modal__head">
      <h3 id="rw-pdf-title">Dokumen</h3>
      <button type="button" class="rw-ico" data-tutup aria-label="Tutup"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="rw-pdf__body" id="rw-pdf-body"></div>
  </div>
</div>
<div class="rw-modal rw-modal--scan" id="rw-scan" hidden>
  <div class="rw-modal__box" role="dialog" aria-modal="true" aria-labelledby="rw-scan-title">
    <div class="rw-modal__head"><h3 id="rw-scan-title">Pindai dokumen</h3><button type="button" class="rw-ico" data-tutup aria-label="Tutup"><i class="bi bi-x-lg"></i></button></div>
    <div class="rw-modal__body" id="rw-scan-body"></div>
    <div class="rw-modal__foot" id="rw-scan-foot"></div>
  </div>
</div>
<div class="rw-toast" id="rw-toast" role="status" hidden></div>
<?php if ($pegawai): ?>
<script src="<?= url('assets/js/riwayat.js') ?>?v=<?= (int)@filemtime(__DIR__ . '/assets/js/riwayat.js') ?>" defer></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
