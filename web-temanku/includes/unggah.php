<?php
declare(strict_types=1);
/*
 * Kolom unggah berkas (lampiran/bukti) bergaya kartu: klik atau tarik-lepas, pratinjau nama/ukuran/thumbnail,
 * cek jenis & ukuran di browser (server tetap memeriksa ulang). Dipakai halaman Cuti, Dinas, dan Claim.
 * unggah_field('surat', 'Surat tugas', 'application/pdf', 'PDF', true)
 */
function unggah_field(string $name, string $label, string $accept, string $jenisTeks, bool $wajib, int $maksMb = 2): string
{
    static $asetSudah = false;
    $id = 'ung-' . preg_replace('/[^a-z0-9_-]/i', '', $name);
    $html = '<div class="ung" data-ung data-maks="' . ($maksMb * 1024 * 1024) . '" data-accept="' . e($accept) . '">'
        . '<span class="ung__label">' . e($label) . ($wajib ? ' <b class="ung__wajib">wajib</b>' : ' <span class="ung__opsi">opsional</span>') . '</span>'
        . '<label class="ung__zona" for="' . e($id) . '">'
        . '<input class="ung__input" type="file" id="' . e($id) . '" name="' . e($name) . '" accept="' . e($accept) . '"' . ($wajib ? ' required' : '') . '>'
        . '<span class="ung__kosong"><span class="ung__ikon"><i class="bi bi-cloud-arrow-up-fill"></i></span>'
        . '<span class="ung__teks"><strong>Klik untuk memilih</strong> atau tarik berkas ke sini<small>' . e($jenisTeks) . ' · maks. ' . $maksMb . ' MB</small></span></span>'
        . '<span class="ung__isi" hidden><span class="ung__thumb"><i class="bi bi-file-earmark-pdf-fill"></i></span>'
        . '<span class="ung__info"><strong class="ung__nama"></strong><small class="ung__ukuran"></small></span>'
        . '<button type="button" class="ung__hapus" aria-label="Hapus berkas"><i class="bi bi-x-lg"></i></button></span>'
        . '</label><p class="ung__galat" role="alert" hidden></p></div>';
    if (!$asetSudah) {
        $asetSudah = true;
        $html .= <<<'HTML'
<style>
.ung{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
.ung .ung__label{font-size:12.5px;color:var(--muted,#5f7474)}
.ung .ung__wajib,.ung .ung__opsi{margin-left:4px;padding:1px 7px;border-radius:99px;font-size:10.5px;font-weight:700;letter-spacing:.02em}
.ung .ung__wajib{background:var(--rose-bg,#e1f4f3);color:var(--rose-700,#0f7f80)}
.ung .ung__opsi{background:#f2f4f7;color:#667085}
.ung .ung__zona{margin:0;font-size:inherit;color:inherit;position:relative;display:block;border:2px dashed var(--line,#d8e3e2);border-radius:16px;background:linear-gradient(180deg,#fff,var(--band,#f6fbfa));cursor:pointer;transition:border-color .15s,background .15s,box-shadow .15s}
.ung .ung__zona:hover,.ung.is-seret .ung__zona{border-color:var(--rose,#169c9c);background:var(--rose-bg,#e1f4f3);box-shadow:0 10px 26px -18px var(--rose-700,#0f7f80)}
.ung .ung__input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.ung .ung__kosong{display:flex;align-items:center;gap:14px;padding:16px 18px;pointer-events:none}
.ung .ung__ikon{flex:none;display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:15px;background:linear-gradient(135deg,var(--rose,#169c9c),var(--rose-700,#0f7f80));color:#fff;font-size:22px;box-shadow:0 10px 20px -12px var(--rose-700,#0f7f80)}
.ung .ung__teks{display:flex;flex-direction:column;gap:2px;font-size:13.5px;color:var(--ink,#123f40)}
.ung .ung__teks strong{color:var(--rose-700,#0f7f80)}
.ung .ung__teks small{font-size:11.5px;color:var(--muted,#748f8f)}
.ung .ung__isi{display:flex;align-items:center;gap:12px;padding:12px 14px}
.ung .ung__isi[hidden],.ung .ung__kosong[hidden],.ung .ung__galat[hidden]{display:none!important}
.ung.is-isi .ung__zona{border-style:solid;border-color:var(--rose,#169c9c);background:#fff}
.ung.is-isi .ung__input{pointer-events:none}
.ung .ung__thumb{flex:none;display:flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:12px;overflow:hidden;background:#fdecec;color:#c93b3b;font-size:24px}
.ung .ung__thumb img{width:100%;height:100%;object-fit:cover}
.ung .ung__info{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.ung .ung__nama{font-size:13.5px;color:var(--ink,#123f40);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ung .ung__ukuran{font-size:11.5px;color:var(--hijau,#1c8a52);font-weight:600}
.ung .ung__hapus{width:34px;height:34px;padding:0;font-weight:400;position:relative;z-index:2;flex:none;width:34px;height:34px;border-radius:50%;border:1px solid var(--line,#d8e3e2);background:#fff;color:#667085;cursor:pointer;display:flex;align-items:center;justify-content:center}
.ung .ung__hapus:hover{background:#fdecec;color:#c93b3b;border-color:#f4c7c7}
.ung .ung__galat{margin:0;padding:8px 12px;border-radius:10px;background:#fdecec;color:#a52a2a;font-size:12.5px}
</style>
<script>
(function () {
  function mulai() {
  function ukuran(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function pasang(w) {
    var inp = w.querySelector('.ung__input'), zona = w.querySelector('.ung__zona'), kosong = w.querySelector('.ung__kosong'), isi = w.querySelector('.ung__isi');
    var galat = w.querySelector('.ung__galat'), thumb = w.querySelector('.ung__thumb'), maks = +w.dataset.maks, terima = w.dataset.accept.split(',');
    function salah(t) { galat.textContent = t; galat.hidden = !t; }
    function kosongkan() { inp.value = ''; w.classList.remove('is-isi'); isi.hidden = true; kosong.hidden = false; thumb.innerHTML = '<i class="bi bi-file-earmark-pdf-fill"></i>'; }
    function tampil() {
      salah('');
      var f = inp.files && inp.files[0];
      if (!f) { kosongkan(); return; }
      if (f.type && terima.indexOf(f.type) < 0) { kosongkan(); salah('Jenis berkas tidak didukung. Gunakan ' + w.querySelector('.ung__teks small').textContent.split(' · ')[0] + '.'); return; }
      if (f.size > maks) { kosongkan(); salah('Ukuran berkas ' + ukuran(f.size) + ', melebihi batas ' + ukuran(maks) + '.'); return; }
      w.querySelector('.ung__nama').textContent = f.name;
      w.querySelector('.ung__ukuran').textContent = ukuran(f.size) + ' · siap diunggah';
      if (/^image\//.test(f.type)) { var img = new Image(); img.alt = ''; img.src = URL.createObjectURL(f); thumb.replaceChildren(img); }
      kosong.hidden = true; isi.hidden = false; w.classList.add('is-isi');
    }
    inp.addEventListener('change', tampil);
    w.querySelector('.ung__hapus').addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); kosongkan(); salah(''); });
    ['dragenter', 'dragover'].forEach(function (n) { zona.addEventListener(n, function (e) { e.preventDefault(); w.classList.add('is-seret'); }); });
    ['dragleave', 'drop'].forEach(function (n) { zona.addEventListener(n, function () { w.classList.remove('is-seret'); }); });
    zona.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files.length) { try { inp.files = e.dataTransfer.files; } catch (_) {} tampil(); }
    });
  }
  document.querySelectorAll('[data-ung]').forEach(pasang);
  // tombol kirim: tampilkan status agar tidak diklik dua kali saat mengunggah
  document.querySelectorAll('form').forEach(function (f) {
    if (!f.querySelector('[data-ung]')) return;
    f.addEventListener('submit', function () { var b = f.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.textContent = 'Mengirim…'; } });
  });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mulai); else mulai();
}());
</script>
HTML;
    }
    return $html;
}
