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
<p class="page__sub history-page__subtitle rw-sub-h1">Bersumber dari SIMPEG &amp; tersinkron dengan SIASN BKN</p>
<link rel="stylesheet" href="<?= url('assets/css/riwayat.css') ?>?v=<?= (int)@filemtime(__DIR__ . '/assets/css/riwayat.css') ?>">
<?php if (can('absensi.view')): ?>
<div class="history-page__actions">
  <form action="<?= url('riwayat_segar.php') ?>" method="post" class="history-page__refresh-form" id="riwayat-refresh-form">
    <?= csrf_field() ?>
    <input type="hidden" name="kategori" value="<?= e($aktif ?? '') ?>">
    <button type="submit" class="history-page__action"><i class="bi bi-arrow-clockwise"></i> Segarkan data</button>
  </form>
</div>
<?php endif; ?>

<?php if (can('absensi.view')): ?>
<div class="history-sync" id="history-sync" hidden>
  <div class="history-sync__dialog" role="dialog" aria-modal="true" aria-labelledby="history-sync-title" aria-describedby="history-sync-description">
    <div class="history-sync__icon" id="history-sync-icon"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></div>
    <h2 id="history-sync-title">Segarkan data ASN?</h2>
    <p id="history-sync-description">Golongan, jabatan, data utama, dan foto Anda akan disinkronkan dari SIASN ke SIMPEG, lalu profil diperbarui. Proses ini bisa memerlukan hingga 1–2 menit.</p>
    <ul class="history-sync__steps" id="history-sync-steps" hidden></ul>
    <div class="history-sync__buttons" id="history-sync-buttons">
      <button type="button" class="history-sync__cancel" id="history-sync-cancel">Batal</button>
      <button type="button" class="history-sync__confirm" id="history-sync-confirm">Ya, segarkan data</button>
    </div>
    <div class="history-sync__buttons" id="history-sync-done-wrap" hidden>
      <button type="button" class="history-sync__confirm" id="history-sync-done">Tutup</button>
    </div>
  </div>
</div>
<div class="history-sync__toast" id="history-sync-toast" role="alert" hidden></div>
<div class="sync-badges" id="sync-badges" aria-live="polite"></div>
<style>
/* Rincian langkah di dialog */
.history-sync__steps{list-style:none;margin:12px 0 4px;padding:0;text-align:left;display:grid;gap:8px}
.history-sync__steps li{display:grid;grid-template-columns:22px 1fr;gap:8px;align-items:start;font-size:14px;line-height:1.45;color:#1f2937}
.history-sync__steps .ikon{font-weight:700;text-align:center}
.history-sync__steps .ok .ikon{color:#15803d}
.history-sync__steps .gagal .ikon{color:#b45309}
.history-sync__steps .proses .ikon{color:#6b7280}
.history-sync__steps .tunggu{opacity:.55}
.history-sync__steps small{display:block;color:#6b7280;font-size:12.5px}
/* Badge notifikasi kanan atas: hijau lembut (berhasil), kuning lembut (belum berhasil) */
.sync-badges{position:fixed;top:calc(env(safe-area-inset-top,0px) + 16px);right:16px;z-index:10000;display:flex;flex-direction:column;gap:8px;width:min(340px,calc(100vw - 32px));pointer-events:none}
.sync-badge{display:grid;grid-template-columns:24px 1fr;gap:10px;align-items:start;padding:11px 14px;border-radius:12px;font-size:13.5px;line-height:1.45;box-shadow:0 8px 24px rgba(15,23,42,.12);pointer-events:auto;animation:sync-badge-in .25s ease-out;transition:opacity .3s,transform .3s}
.sync-badge strong{display:block;font-weight:600}
.sync-badge small{display:block;opacity:.85;font-size:12.5px}
.sync-badge__ikon{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px}
.sync-badge--ok{background:#ecfdf3;border:1px solid #abefc6;color:#05603a}
.sync-badge--ok .sync-badge__ikon{background:#d1fadf;color:#05603a}
.sync-badge--gagal{background:#fffaeb;border:1px solid #fedf89;color:#7a4b05}
.sync-badge--gagal .sync-badge__ikon{background:#fef0c7;color:#7a4b05}
.sync-badge--hilang{opacity:0;transform:translateX(16px)}
@keyframes sync-badge-in{from{opacity:0;transform:translateX(16px)}to{opacity:1;transform:none}}
</style>
<?php endif; ?>

<?php if (!$pegawai): ?>
<section class="card" style="margin-top:14px">
  <p style="margin:0;font-size:13px;color:var(--muted-2)">Data kepegawaian untuk NIP <?= e(current_nip()) ?> belum ditemukan di SIMPEG. Silakan hubungi admin OPD atau BKPSDM.</p>
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
      <?php if (can('absensi.view')): ?><button type="button" class="rw-pill rw-pill--kuning" data-rw="segarkan"><i class="bi bi-arrow-repeat"></i><span>Sync</span></button><?php endif; ?>
      <?php if ($idt && $idt['ubahSiap']): ?><button type="button" class="rw-pill rw-pill--utama" data-rw="ubah-identitas"><i class="bi bi-pencil"></i><span>Ubah</span></button><?php endif; ?>
    </div>
  </div>
  <?php if ($idt === null): ?>
    <div class="rw-empty rw-empty--gagal"><i class="bi bi-cloud-slash"></i><p>Identitas belum dapat dimuat dari SIMPEG<?= isset($GLOBALS['riwayat_api_kode']) ? ' (kode ' . (int)$GLOBALS['riwayat_api_kode'] . ')' : '' ?>.</p></div>
  <?php else: $ub = $idt['ubah']; ?>
    <div class="rw-idt">
      <aside class="rw-idt__head">
        <img src="<?= e($u['foto'] ? url('foto_saya.php') . '?v=' . substr(md5((string)$u['foto']), 0, 10) : url('lama/assets/images/user.png')) ?>" onerror="this.onerror=null;this.src='<?= e(url('lama/assets/images/user.png')) ?>'" alt="">
        <div>
          <strong><?= e($idt['nama']) ?></strong><span><?= e($idt['nip']) ?></span>
          <?php if (!empty($u['golongan'])): ?><em class="rw-idt__gol"><i class="bi bi-award"></i> Gol. <?= e($u['golongan']) ?></em><?php endif; ?>
        </div>
      </aside>
      <div class="rw-idt__isi">
        <section class="rw-grup rw-grup--ubah" id="rw-idt-ubah">
          <h3><i class="bi bi-pencil-square"></i> Data yang dapat diubah</h3>
          <dl class="rw-rows" id="rw-idt-nilai">
            <?php foreach ([['Agama', $ub['agama'], 'bi-moon-stars'], ['Status perkawinan', $ub['kawin'], 'bi-heart'], ['Golongan darah', $ub['golDarah'], 'bi-droplet'],
                            ['Tempat lahir', $ub['tempatLahir'], 'bi-geo-alt'], ['Nomor HP', $ub['noHp'], 'bi-phone'], ['Email pribadi', $ub['email'], 'bi-envelope'],
                            ['Email dinas', $ub['emailGov'], 'bi-envelope-at'], ['Alamat', $ub['alamat'], 'bi-house']] as [$lbl, $nilai, $ik]): ?>
              <div><dt><i class="bi <?= e($ik) ?>"></i><?= e($lbl) ?></dt><dd><?= e($nilai !== '' ? $nilai : '–') ?></dd></div>
            <?php endforeach; ?>
          </dl>
          <div id="rw-idt-form"></div>
          <?php if (!$idt['ubahSiap']): ?><p class="rw-catatan">Pengubahan aktif setelah API TEMANKU (Go) diperbarui.</p><?php endif; ?>
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
        'kategori' => $aktif, 'judul' => $meta[$aktif][0], 'ikon' => $meta[$aktif][1], 'warna' => $meta[$aktif][2],
        'aksiUrl' => url('riwayat_aksi.php'), 'dokUrl' => url('riwayat_dok.php'), 'halamanUrl' => url('riwayat.php'),
        'jenis' => $jenisKat, 'jenisLabel' => array_intersect_key(RIWAYAT_JENIS_LABEL, array_flip($jenisKat)),
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
      <button type="button" class="rw-pill rw-pill--kuning" data-rw="sync"><i class="bi bi-arrow-repeat"></i><span>Sync</span></button>
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
<?php if (can('absensi.view')): ?>
<script>
(() => {
  const form = document.getElementById('riwayat-refresh-form');
  const overlay = document.getElementById('history-sync');
  const dialog = overlay.querySelector('.history-sync__dialog');
  const title = document.getElementById('history-sync-title');
  const description = document.getElementById('history-sync-description');
  const buttons = document.getElementById('history-sync-buttons');
  const confirm = document.getElementById('history-sync-confirm');
  const cancel = document.getElementById('history-sync-cancel');
  const toast = document.getElementById('history-sync-toast');
  const stepsList = document.getElementById('history-sync-steps');
  const doneWrap = document.getElementById('history-sync-done-wrap');
  const done = document.getElementById('history-sync-done');
  const badges = document.getElementById('sync-badges');
  const initialDescription = description.textContent;
  const STEPS = [
    ['golongan', 'Sinkron golongan (SIASN)'],
    ['jabatan', 'Sinkron jabatan (SIASN)'],
    ['data_utama', 'Data utama'],
    ['foto', 'Foto'],
  ];
  let reloadOnClose = false;
  let busy = false;
  let previousFocus = null;
  let toastTimer;

  function close() {
    if (busy) return;
    overlay.hidden = true;
    if (reloadOnClose) {
      // Muat ulang tanpa cache riwayat di sesi agar hasil sinkron langsung tampil.
      const next = new URL(window.location.href);
      next.searchParams.set('segar', '1');
      window.location.replace(next.href);
      return;
    }
    previousFocus?.focus();
  }
  // Badge kanan atas untuk tiap langkah. Teks lewat textContent (aman dari HTML).
  function badge(step) {
    const ok = step.status === 'ok';
    const item = document.createElement('div');
    const tetap = step.status === 'peringatan';   // BKN tidak terhubung, foto yang ada tetap dipakai
    item.className = 'sync-badge ' + (ok ? 'sync-badge--ok' : 'sync-badge--gagal');
    item.setAttribute('role', 'status');
    const icon = document.createElement('span');
    icon.className = 'sync-badge__ikon';
    icon.textContent = ok ? '✓' : '!';
    const text = document.createElement('span');
    const label = document.createElement('strong');
    label.textContent = step.label + (ok ? ' berhasil' : tetap ? ': memakai foto yang ada' : ' belum berhasil');
    const note = document.createElement('small');
    note.textContent = step.message || '';
    text.append(label, note);
    item.append(icon, text);
    badges.append(item);
    setTimeout(() => { item.classList.add('sync-badge--hilang'); setTimeout(() => item.remove(), 350); }, ok ? 5000 : 9000);
  }
  // Baris langkah di dialog: tunggu → proses (⏳) → ok (✓) / gagal (!)
  function renderSteps(states) {
    stepsList.replaceChildren();
    for (const state of states) {
      const item = document.createElement('li');
      item.className = state.status;
      const icon = document.createElement('span');
      icon.className = 'ikon';
      icon.textContent = { ok: '✓', gagal: '!', proses: '⏳', tunggu: '•' }[state.status] || '•';
      const text = document.createElement('span');
      const label = document.createElement('strong');
      label.textContent = state.label;
      text.append(label);
      if (state.message) {
        const note = document.createElement('small');
        note.textContent = state.message;
        text.append(note);
      }
      item.append(icon, text);
      stepsList.append(item);
    }
    stepsList.hidden = false;
  }
  async function runStep(key) {
    const body = new FormData(form);
    body.append('langkah', key);
    const response = await fetch(form.action, {
      method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin'
    });
    const raw = await response.text();
    let result;
    try { result = JSON.parse(raw); } catch (_) {
      if (response.status === 419) throw new Error('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
      if (response.status === 401 || response.status === 403) throw new Error('Sesi masuk berakhir. Masuk kembali lalu coba lagi.');
      throw new Error('Server tidak mengirim hasil sinkronisasi (HTTP ' + response.status + ').');
    }
    if (!result.step) throw new Error(result.message || 'Penyegaran data belum berhasil. Silakan coba lagi.');
    return result.step;
  }
  function notify(message, success) {
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.classList.toggle('history-sync__toast--success', success);
    toast.classList.toggle('history-sync__toast--error', !success);
    toast.hidden = false;
    toastTimer = setTimeout(() => { toast.hidden = true; }, success ? 4500 : 7000);
  }
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (busy) return;
    previousFocus = document.activeElement;
    title.textContent = 'Segarkan data ASN?';
    description.textContent = initialDescription;
    stepsList.hidden = true;
    doneWrap.hidden = true;
    reloadOnClose = false;
    buttons.hidden = false;
    overlay.hidden = false;
    confirm.focus();
  });
  cancel.addEventListener('click', close);
  done.addEventListener('click', close);
  overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
  document.addEventListener('keydown', event => {
    if (overlay.hidden) return;
    if (event.key === 'Escape' && !busy) close();
    if (event.key === 'Tab') {
      if (busy) { event.preventDefault(); return; }
      if (!doneWrap.hidden) { event.preventDefault(); done.focus(); return; }
      if (event.shiftKey && document.activeElement === cancel) { event.preventDefault(); confirm.focus(); }
      else if (!event.shiftKey && document.activeElement === confirm) { event.preventDefault(); cancel.focus(); }
    }
  });
  confirm.addEventListener('click', async () => {
    if (busy) return;
    busy = true;
    dialog.classList.add('history-sync__dialog--loading');
    title.textContent = 'Sedang menyegarkan data';
    description.textContent = 'Mohon tunggu, jangan tutup halaman ini…';
    buttons.hidden = true;
    const states = STEPS.map(([key, label]) => ({ key, label, status: 'tunggu', message: '' }));
    renderSteps(states);
    try {
      // Langkah dijalankan satu per satu; setiap hasil langsung tampil sebagai badge.
      for (const state of states) {
        state.status = 'proses';
        renderSteps(states);
        const step = await runStep(state.key);
        Object.assign(state, { status: step.status === 'ok' ? 'ok' : 'gagal', message: step.message, label: step.label || state.label,
          tetap: step.status === 'peringatan', offline: Boolean(step.bkn_offline) });
        renderSteps(states);
        badge(step);
      }
      const offline = states.some(s => s.offline);
      const changed = states.some(s => (s.key === 'data_utama' || s.key === 'foto') && s.status === 'ok');
      // BKN tidak terhubung: tetap muat ulang profil dari SIMPEG agar data & foto yang sudah ada dipakai.
      if (changed || offline) {
        const cache = { key: 'cache', label: 'Perbarui profil & cache pegawai', status: 'proses', message: '' };
        states.push(cache);
        renderSteps(states);
        const step = await runStep('cache');
        Object.assign(cache, { status: step.status === 'ok' ? 'ok' : 'gagal', message: step.message });
        renderSteps(states);
        badge(step);
      }
      const failed = states.filter(s => s.status !== 'ok' && !s.tetap).length;
      title.textContent = offline ? 'Server BKN tidak terhubung' : failed ? 'Penyegaran selesai dengan catatan' : 'Penyegaran selesai';
      description.textContent = offline
        ? 'Data & foto yang sudah ada di SIMPEG tetap dipakai. Silakan segarkan lagi nanti saat BKN tersambung.'
        : failed ? failed + ' langkah belum berhasil. Langkah lain sudah tersimpan.' : 'Semua data berhasil diperbarui dari SIASN.';
      reloadOnClose = states.some(s => s.status === 'ok');
    } catch (error) {
      title.textContent = 'Penyegaran terhenti';
      description.textContent = error.message || 'Koneksi terputus. Silakan coba lagi.';
      states.filter(s => s.status === 'proses').forEach(s => { s.status = 'gagal'; s.message = 'Terhenti'; });
      renderSteps(states);
      badge({ status: 'gagal', label: 'Penyegaran', message: description.textContent });
      reloadOnClose = states.some(s => s.status === 'ok');
    } finally {
      busy = false;
      dialog.classList.remove('history-sync__dialog--loading');
      doneWrap.hidden = false;
      done.focus();
    }
  });
})();
</script>
<?php endif; ?>
<?php if ($pegawai): ?>
<script src="<?= url('assets/js/riwayat.js') ?>?v=<?= (int)@filemtime(__DIR__ . '/assets/js/riwayat.js') ?>" defer></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
