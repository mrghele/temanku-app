<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';

$page      = 'riwayat';
$pageTitle = 'Riwayat ASN';
require __DIR__ . '/includes/header.php';

$pid      = (int) $u['id'];
$ringkas  = riwayat_ringkas($pid);
$aktif    = in_array($_GET['kategori'] ?? '', ['jabatan','pangkat','pendidikan','diklat','keluarga'], true) ? $_GET['kategori'] : null;
$detail   = $aktif ? riwayat_kategori($pid, $aktif) : [];

$meta = [
    'jabatan'    => ['Riwayat Jabatan', 'bi-briefcase-fill', 'tosca'],
    'pangkat'    => ['Riwayat Pangkat', 'bi-arrow-up-circle-fill', 'hijau'],
    'pendidikan' => ['Riwayat Pendidikan', 'bi-mortarboard-fill', 'biru'],
    'diklat'     => ['Diklat & Sertifikasi', 'bi-award-fill', 'kuning'],
    'keluarga'   => ['Data Keluarga', 'bi-people-fill', 'abu'],
];
?>
<?php require __DIR__ . '/includes/mobile_top.php'; ?>
<h1 class="history-page__heading" style="margin-block:0;font-size:clamp(21px,2vw,27px);font-weight:800"><span class="history-page__desktop-title">Riwayat ASN</span><span class="history-page__mobile-title">Data Riwayat ASN</span></h1>
<p class="page__sub history-page__subtitle"><span class="history-page__desktop-title">Data bersumber dari SIMPEG dan tersinkron dengan BKN.</span><span class="history-page__mobile-title">Bersumber dari SIMPEG &amp; terverifikasi BKN</span></p>
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

<div class="grid grid--tiles">
  <?php foreach ($meta as $key => [$label, $icon, $warna]):
      $r = $ringkas[$key] ?? null; ?>
    <a class="tile" href="<?= url('riwayat.php?kategori=' . $key) ?>" style="<?= $aktif === $key ? 'border-color:var(--rose)' : '' ?>">
      <span class="tile__ico ico--<?= $warna ?>"><i class="bi <?= $icon ?>"></i></span>
      <span class="tile__txt">
        <strong><?= e($label) ?></strong>
        <small><?= $r && $r['terakhir'] ? 'Terakhir: ' . e(tanggal_id($r['terakhir'])) : 'Belum ada data' ?></small>
      </span>
      <span class="tile__num"><?= (int) ($r['jumlah'] ?? 0) ?></span>
      <i class="bi bi-chevron-right history-page__arrow" aria-hidden="true"></i>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($aktif): ?>
<section class="card" style="margin-top:14px">
  <div class="card__band">
    <h2><i class="bi <?= $meta[$aktif][1] ?>"></i> <?= e($meta[$aktif][0]) ?></h2>
    <span class="badge badge--rose"><?= count($detail) ?> data</span>
  </div>
  <?php if (!$detail): ?>
    <p style="margin:0;font-size:13px;color:var(--muted-2)">Belum ada data pada kategori ini.</p>
  <?php else: ?>
    <div class="rows">
      <?php foreach ($detail as $d): ?>
        <div class="row" style="grid-template-columns:1.6fr 1.2fr .8fr">
          <strong><?= e($d['judul']) ?></strong>
          <span style="color:var(--muted)"><?= e($d['keterangan']) ?></span>
          <span class="mono"><?= e(tanggal_id($d['tanggal'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
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
    if (reloadOnClose) { window.location.reload(); return; }
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
<?php require __DIR__ . '/includes/footer.php'; ?>
