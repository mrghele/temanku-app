/*
 * Halaman Riwayat ASN (TEMANKU)
 *  - daftar riwayat ringkas (baris ala Gojek/MyTelkomsel) dengan pencarian dan halaman (page); klik baris = detail
 *  - drawer "Lihat" di kanan (desktop) / bottom-sheet (mobile) seperti MyASN; dokumen dipratinjau dengan pdf.js
 *    (desktop: tab bila lebih dari satu PDF, mobile: akordeon yang membuka pratinjau ke bawah)
 *  - usulan tambah / edit / hapus dengan pratinjau PDF unggahan & fitur pindai dokumen (kamera -> PDF, jsPDF)
 *  - daftar usulan, sync SIASN 2 langkah (data, lalu pilih dokumen yang ditarik) dengan badge hasil
 *  - identitas: ubah data seperti SIMPEG (opsional kirim ke SIASN BKN)
 * Semua teks dari server dipasang lewat textContent (aman dari HTML).
 */
(function () {
  'use strict';
  var CFG = window.RIWAYAT_CFG || {};
  var CDN = 'https://cdnjs.cloudflare.com/ajax/libs/';
  var PDFJS_URL = CDN + 'pdf.js/3.11.174/pdf.min.js';
  var PDFJS_WORKER = CDN + 'pdf.js/3.11.174/pdf.worker.min.js';
  var JSPDF_URL = CDN + 'jspdf/2.5.1/jspdf.umd.min.js';
  var JQUERY_URL = CDN + 'jquery/3.7.1/jquery.min.js';
  var SELECT2_URL = CDN + 'select2/4.0.13/js/select2.min.js';
  var SELECT2_CSS = CDN + 'select2/4.0.13/css/select2.min.css';
  var PDF_MAKS = CFG.pdfMaks || 1572864;
  var HP = window.matchMedia('(max-width: 820px)');
  var STATUS = {
    diajukan: ['Menunggu verifikasi', 'kuning', 'bi-hourglass-split'],
    dikembalikan: ['Perlu diperbaiki', 'biru', 'bi-arrow-counterclockwise'],
    disetujui: ['Disetujui', 'hijau', 'bi-check-circle-fill'],
    ditolak: ['Ditolak', 'merah', 'bi-x-circle-fill'],
    dibatalkan: ['Dibatalkan', 'abu', 'bi-slash-circle']
  };
  var AKSI = { tambah: ['Tambah', 'bi-plus-lg'], ubah: ['Ubah', 'bi-pencil-square'], hapus: ['Hapus', 'bi-trash3'] };
  var BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }
  function ikon(nama) { var i = el('i', 'bi ' + nama); i.setAttribute('aria-hidden', 'true'); return i; }
  function tombol(label, cls, ik, fn) {
    var b = el('button', 'rw-btn ' + (cls || ''));
    b.type = 'button';
    if (ik) b.append(ikon(ik));
    b.append(el('span', '', label));
    if (fn) b.addEventListener('click', fn);
    return b;
  }
  function tgl(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(v || '');
    if (!m) return v || '-';
    return (+m[3]) + ' ' + BULAN[+m[2] - 1] + ' ' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '');
  }
  function tag(teks, warna, ik) { var t = el('span', 'rw-tag rw-tag--' + (warna || 'abu')); if (ik) t.append(ikon(ik)); t.append(document.createTextNode(teks)); return t; }
  function statusTag(st) { var s = STATUS[st] || [st, 'abu', 'bi-circle']; return tag(s[0], s[1], s[2]); }
  function info(teks, warna, ik) { var d = el('div', 'rw-info rw-info--' + warna); d.append(ikon(ik), el('span', '', teks)); return d; }
  function ukuran(b) { return b >= 1048576 ? (b / 1048576).toFixed(2).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

  var toastT;
  function toast(pesan, ok) {
    var t = $('#rw-toast');
    if (!t) return;
    clearTimeout(toastT);
    t.textContent = pesan;
    t.className = 'rw-toast ' + (ok === false ? 'rw-toast--gagal' : ok ? 'rw-toast--ok' : '');
    t.hidden = false;
    toastT = setTimeout(function () { t.hidden = true; }, ok === false ? 7000 : 4500);
  }

  /* ---------- permintaan ke riwayat_aksi.php ---------- */
  function ambilJson(resp) {
    return resp.text().then(function (raw) {
      var j;
      try { j = JSON.parse(raw); } catch (e) {
        if (resp.status === 419) throw new Error('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        if (resp.status === 401 || resp.status === 403) throw new Error('Sesi masuk berakhir. Masuk kembali lalu coba lagi.');
        throw new Error('Server tidak mengirim jawaban yang valid (HTTP ' + resp.status + ').');
      }
      if (!resp.ok || j.success === false) {
        var err = new Error(j.message || ('Permintaan gagal (HTTP ' + resp.status + ').'));
        err.field = j.field || null; err.data = j;
        throw err;
      }
      return j;
    });
  }
  function get(params) {
    return fetch(CFG.aksiUrl + '?' + new URLSearchParams(params).toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(ambilJson);
  }
  function fdBaru(aksi) {
    var fd = new FormData($('#rw-csrf') || undefined);
    fd.set('aksi', aksi);
    return fd;
  }
  function post(fd) {
    return fetch(CFG.aksiUrl, { method: 'POST', body: fd, headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(ambilJson);
  }
  function muatUlang(extra) {
    var u = new URL(CFG.halamanUrl || window.location.href, window.location.href);
    u.search = '';
    u.searchParams.set('kategori', CFG.kategori);
    Object.keys(extra || {}).forEach(function (k) { u.searchParams.set(k, extra[k]); });
    window.location.replace(u.href);
  }
  // Setelah usulan tersimpan/dibatalkan: muat ulang tanpa parameter tambahan di URL,
  // seksi jenis tersebut dibuka dalam tampilan Usulan (diingat lewat sessionStorage).
  function muatUlangUsulan(jenis) {
    try { sessionStorage.setItem('rw-buka-usulan', JSON.stringify({ kategori: CFG.kategori, jenis: jenis || '' })); } catch (e) {}
    muatUlang();
  }
  function cekApi() { if (window.cekStatusApi) window.cekStatusApi(true); }

  /* ---------- pustaka dari CDN (dimuat saat dibutuhkan) ---------- */
  var muatan = {};
  function muatSkrip(url, cek) {
    if (cek()) return Promise.resolve();
    if (muatan[url]) return muatan[url];
    muatan[url] = new Promise(function (ok, gagal) {
      var s = document.createElement('script');
      s.src = url; s.async = true; s.crossOrigin = 'anonymous'; s.referrerPolicy = 'no-referrer';
      s.onload = function () { cek() ? ok() : gagal(new Error('pustaka tidak tersedia')); };
      s.onerror = function () { delete muatan[url]; gagal(new Error('pustaka gagal dimuat')); };
      document.head.appendChild(s);
    });
    return muatan[url];
  }
  function pdfjs() {
    return muatSkrip(PDFJS_URL, function () { return !!window.pdfjsLib; }).then(function () {
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
      return window.pdfjsLib;
    });
  }

  /* ---------- Select2 (semua select di form; referensi besar dicari lewat AJAX) ---------- */
  function select2Siap() {
    if (!document.querySelector('link[data-select2]')) {
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = SELECT2_CSS; l.dataset.select2 = '1'; l.crossOrigin = 'anonymous';
      document.head.appendChild(l);
    }
    return muatSkrip(JQUERY_URL, function () { return !!window.jQuery; })
      .then(function () { return muatSkrip(SELECT2_URL, function () { return !!(window.jQuery && window.jQuery.fn.select2); }); });
  }
  var S2_BAHASA = {
    noResults: function () { return 'Tidak ditemukan'; }, searching: function () { return 'Mencari…'; },
    inputTooShort: function () { return 'Ketik minimal 2 huruf'; }, errorLoading: function () { return 'Referensi gagal dimuat'; },
    loadingMore: function () { return 'Memuat…'; }
  };
  /** Pasang Select2 pada select; opsi.ajax(q) -> parameter riwayat_aksi.php?aksi=ref. Gagal memuat = tetap select biasa. */
  function pasangSelect2(sel, opsi) {
    opsi = opsi || {};
    return select2Siap().then(function () {
      var jq = window.jQuery, $s = jq(sel);
      if (!sel.isConnected) return;
      if ($s.hasClass('select2-hidden-accessible')) $s.select2('destroy');
      var o = { width: '100%', language: S2_BAHASA, placeholder: opsi.placeholder || '— Pilih —',
        dropdownParent: jq(sel.closest('.rw-modal') || document.body) };
      if (opsi.ajax) {
        o.minimumInputLength = 2;
        o.ajax = {
          delay: 300,
          transport: function (p, ok, gagal) {
            get(opsi.ajax((p.data && p.data.term) || '')).then(function (j) {
              ok({ results: (j.data || []).slice(0, 50).map(function (x) { return { id: x.id, text: x.nama }; }) });
            }).catch(gagal);
            return { abort: function () {} };
          }
        };
      } else if (sel.options.length <= 8) o.minimumResultsForSearch = Infinity;   // pilihan sedikit: tanpa kotak cari
      $s.select2(o);
      $s.on('select2:select select2:clear', function () { sel.dispatchEvent(new Event('change', { bubbles: true })); });
      sel._s2aktif = true;
    }).catch(function () { /* pustaka tidak tersedia: select biasa tetap berfungsi */ });
  }
  /** Perbarui tampilan Select2 setelah nilai/opsi select diubah lewat kode. */
  function segarS2(sel) { if (sel && sel._s2aktif && window.jQuery) window.jQuery(sel).trigger('change.select2'); }
  function pasangSemuaSelect(root) { $$('select', root).forEach(function (s) { pasangSelect2(s, s._s2); }); }

  /* ---------- popup / drawer ---------- */
  var modalEl = $('#rw-modal'), modalSibuk = false, fokusSebelum = null, pdfEl = $('#rw-pdf'), scanEl = $('#rw-scan');
  function kunciBody() {
    var buka = [modalEl, pdfEl, scanEl].some(function (m) { return m && !m.hidden; });
    document.body.classList.toggle('rw-modal-buka', buka);
  }
  function bukaModal(judul, isi, kaki, opsi) {
    opsi = opsi || {};
    if (modalEl.hidden) fokusSebelum = document.activeElement;
    modalEl.className = 'rw-modal' + (opsi.drawer ? ' rw-modal--drawer' : '') + (opsi.lebar ? ' rw-modal--lebar' : '') + (opsi.kelas ? ' ' + opsi.kelas : '');
    $('#rw-modal-title').textContent = judul;
    var body = $('#rw-modal-body'), foot = $('#rw-modal-foot');
    body.replaceChildren(isi);
    foot.replaceChildren.apply(foot, kaki || []);
    modalEl.hidden = false;
    kunciBody();
    body.scrollTop = 0;
    var f = opsi.fokus || $('input:not([type=hidden]):not([type=file]):not([disabled]),select:not([disabled]),textarea', body) || $('[data-tutup]', modalEl);
    if (f) setTimeout(function () { try { f.focus({ preventScroll: true }); } catch (e) { f.focus(); } }, 40);
  }
  function tutupModal() {
    if (modalSibuk) return;
    modalEl.hidden = true;
    $('#rw-modal-body').replaceChildren();
    kunciBody();
    if (fokusSebelum && fokusSebelum.focus) fokusSebelum.focus();
  }
  function sibuk(ya, btn, teks) {
    modalSibuk = ya;
    $$('#rw-modal-foot button').forEach(function (b) { b.disabled = ya; });
    if (btn) { if (ya) { btn.dataset.asli = $('span', btn).textContent; $('span', btn).textContent = teks || 'Memproses…'; } else if (btn.dataset.asli) $('span', btn).textContent = btn.dataset.asli; }
  }
  [modalEl, pdfEl, scanEl].forEach(function (m) {
    if (!m) return;
    $$('[data-tutup]', m).forEach(function (b) { b.addEventListener('click', function () { if (m === modalEl) tutupModal(); else if (m === pdfEl) tutupPdf(); else tutupScan(); }); });
    m.addEventListener('click', function (e) { if (e.target === m) { if (m === modalEl) tutupModal(); else if (m === pdfEl) tutupPdf(); } });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.querySelector('.select2-container--open') || e.target.closest && e.target.closest('.select2-container')) return;   // Esc menutup dropdown dulu
    if (scanEl && !scanEl.hidden) tutupScan();
    else if (pdfEl && !pdfEl.hidden) tutupPdf();
    else if (modalEl && !modalEl.hidden) tutupModal();
  });

  /* ---------- viewer PDF (pdf.js) ---------- */
  /**
   * Viewer di dalam halaman/popup. sumber = {url} (dokumen server) atau {data: ArrayBuffer} (berkas unggahan/pindaian).
   * Dimuat saat muat() dipanggil (lazy). opsi.penuh = tampilan layar penuh (tanpa tombol perbesar).
   */
  function Viewer(sumber, judul, opsi) {
    opsi = opsi || {};
    var root = el('div', 'rw-viewer' + (opsi.penuh ? ' rw-viewer--penuh' : ''));
    var bar = el('div', 'rw-viewer__bar');
    var nama = el('span', 'rw-viewer__nama'); nama.append(ikon('bi-file-earmark-pdf-fill'), document.createTextNode(judul || 'Dokumen'));
    var hal = el('span', 'rw-viewer__hal', '');
    var alat = el('span', 'rw-viewer__alat');
    var kecil = el('button', 'rw-ico'); kecil.type = 'button'; kecil.setAttribute('aria-label', 'Perkecil'); kecil.append(ikon('bi-zoom-out'));
    var zoom = el('span', 'rw-viewer__zoom', '100%');
    var besar = el('button', 'rw-ico'); besar.type = 'button'; besar.setAttribute('aria-label', 'Perbesar'); besar.append(ikon('bi-zoom-in'));
    alat.append(kecil, zoom, besar);
    if (!opsi.penuh) {
      var penuh = el('button', 'rw-ico'); penuh.type = 'button'; penuh.title = 'Layar penuh'; penuh.setAttribute('aria-label', 'Layar penuh'); penuh.append(ikon('bi-arrows-fullscreen'));
      penuh.addEventListener('click', function () { bukaPdf(sumber, judul); });
      alat.append(penuh);
    }
    if (sumber.url) {
      var unduh = el('a', 'rw-btn rw-btn--primary rw-btn--xs rw-viewer__unduh'); unduh.href = sumber.url; unduh.download = (judul || 'dokumen').replace(/[^\w\- ]+/g, '') + '.pdf';
      unduh.append(ikon('bi-download'), el('span', '', 'Unduh'));
      alat.append(unduh);
      var tab = el('a', 'rw-ico'); tab.href = sumber.url; tab.target = '_blank'; tab.rel = 'noopener'; tab.title = 'Buka di tab baru'; tab.setAttribute('aria-label', 'Buka di tab baru'); tab.append(ikon('bi-box-arrow-up-right'));
      alat.append(tab);
    }
    bar.append(nama, hal, alat);
    var area = el('div', 'rw-viewer__area');
    root.append(bar, area);
    var doc = null, skala = 1, token = 0, dimuat = false;
    function pesan(teks) {
      var p = el('div', 'rw-viewer__pesan');
      p.append(el('p', '', teks));
      if (sumber.url) { var a = el('a', 'rw-btn', 'Buka di tab baru'); a.href = sumber.url; a.target = '_blank'; a.rel = 'noopener'; p.append(a); }
      area.replaceChildren(p);
    }
    function render() {
      if (!doc) return;
      var t = ++token, dpr = window.devicePixelRatio || 1, lebar = Math.max(240, area.clientWidth - 24);
      zoom.textContent = Math.round(skala * 100) + '%';
      area.replaceChildren();
      var urut = Promise.resolve();
      for (var n = 1; n <= doc.numPages; n++) {
        (function (no) {
          urut = urut.then(function () {
            if (t !== token) return;
            return doc.getPage(no).then(function (pg) {
              var vp = pg.getViewport({ scale: (lebar / pg.getViewport({ scale: 1 }).width) * skala });
              var c = document.createElement('canvas');
              c.width = Math.floor(vp.width * dpr); c.height = Math.floor(vp.height * dpr);
              c.style.width = Math.floor(vp.width) + 'px'; c.style.height = Math.floor(vp.height) + 'px';
              c.setAttribute('aria-label', 'Halaman ' + no + ' dari ' + doc.numPages);
              area.append(c);
              return pg.render({ canvasContext: c.getContext('2d'), viewport: vp, transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null }).promise;
            });
          });
        })(n);
      }
      urut.catch(function () { if (t === token) pesan('Sebagian halaman gagal ditampilkan.'); });
    }
    kecil.addEventListener('click', function () { skala = Math.max(0.5, skala - 0.25); render(); });
    besar.addEventListener('click', function () { skala = Math.min(3, skala + 0.25); render(); });
    return {
      el: root,
      muat: function () {
        if (dimuat) return;
        dimuat = true;
        var sp = el('div', 'rw-viewer__pesan'); sp.append(el('div', 'rw-spin'), el('p', '', 'Memuat dokumen…'));
        area.replaceChildren(sp);
        pdfjs().then(function (lib) {
          return lib.getDocument(sumber.url ? { url: sumber.url, withCredentials: true } : { data: sumber.data.slice(0) }).promise;
        }).then(function (d) {
          doc = d;
          hal.textContent = d.numPages + ' hal.';
          requestAnimationFrame(render);
        }).catch(function (e) {
          dimuat = false;
          pesan(/pustaka/.test(e.message) ? 'Viewer PDF tidak dapat dimuat (periksa koneksi internet).' : 'Dokumen belum dapat ditampilkan. Dokumen mungkin belum tersedia di repository.');
        });
      },
      render: render,
      hancur: function () { token++; if (doc && doc.destroy) doc.destroy(); doc = null; }
    };
  }

  var viewerPenuh = null;
  function bukaPdf(sumber, judul) {
    $('#rw-pdf-title').textContent = judul || 'Dokumen';
    if (viewerPenuh) viewerPenuh.hancur();
    viewerPenuh = Viewer(sumber, judul, { penuh: true });
    $('#rw-pdf-body').replaceChildren(viewerPenuh.el);
    pdfEl.hidden = false;
    kunciBody();
    viewerPenuh.muat();
  }
  function tutupPdf() {
    pdfEl.hidden = true;
    if (viewerPenuh) viewerPenuh.hancur();
    viewerPenuh = null;
    $('#rw-pdf-body').replaceChildren();
    kunciBody();
  }
  var rz;
  window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(function () { if (viewerPenuh) viewerPenuh.render(); }, 200); });

  /**
   * Blok dokumen di popup Lihat: desktop = tab (lebih dari satu) + pratinjau langsung;
   * mobile = akordeon "Dok … ▾" yang membuka pratinjau ke bawah.
   * list = [{label, sumber}], fokus = indeks dokumen yang dibuka lebih dulu.
   */
  function blokDokumen(list, fokus, kosong) {
    var wrap = el('div', 'rw-doks');
    if (!list.length) { wrap.append(el('p', 'rw-muted', kosong || 'Belum ada dokumen.')); return wrap; }
    fokus = Math.max(0, Math.min(list.length - 1, fokus || 0));
    if (HP.matches) {
      list.forEach(function (d, i) {
        var item = el('div', 'rw-akordeon');
        var b = el('button', 'rw-akordeon__btn'); b.type = 'button'; b.setAttribute('aria-expanded', 'false');
        b.append(ikon('bi-file-earmark-pdf-fill'), el('span', '', d.label), ikon('bi-chevron-down rw-akordeon__panah'));
        var isi = el('div', 'rw-akordeon__isi'); isi.hidden = true;
        var v = null;
        b.addEventListener('click', function () {
          var buka = isi.hidden;
          isi.hidden = !buka; b.setAttribute('aria-expanded', buka ? 'true' : 'false');
          if (buka && !v) { v = Viewer(d.sumber, d.label); isi.append(v.el); v.muat(); }
        });
        item.append(b, isi);
        wrap.append(item);
        if (i === fokus && fokus > 0) setTimeout(function () { b.click(); }, 50);
      });
      return wrap;
    }
    var area = el('div', 'rw-doks__area');
    var viewers = [];
    function tampil(i) {
      $$('.rw-tab', wrap).forEach(function (t, j) { t.setAttribute('aria-selected', i === j ? 'true' : 'false'); t.tabIndex = i === j ? 0 : -1; });
      if (!viewers[i]) viewers[i] = Viewer(list[i].sumber, list[i].label);
      area.replaceChildren(viewers[i].el);
      viewers[i].muat();
    }
    if (list.length > 1) {
      var tabs = el('div', 'rw-tabs'); tabs.setAttribute('role', 'tablist');
      list.forEach(function (d, i) {
        var t = el('button', 'rw-tab'); t.type = 'button'; t.setAttribute('role', 'tab');
        t.append(ikon('bi-file-earmark-pdf'), document.createTextNode(d.label));
        t.addEventListener('click', function () { tampil(i); });
        t.addEventListener('keydown', function (e) {
          if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); var n = (i + (e.key === 'ArrowRight' ? 1 : -1) + list.length) % list.length; tampil(n); $$('.rw-tab', wrap)[n].focus(); }
        });
        tabs.append(t);
      });
      wrap.append(tabs);
    }
    wrap.append(area);
    setTimeout(function () { tampil(fokus); }, 0);
    return wrap;
  }

  /* ---------- badge hasil (kanan atas; bertahan melewati muat ulang halaman) ---------- */
  var BADGE_KEY = 'rw-badges';
  function badge(judul, ket, ok, simpan) {
    if (simpan) {
      try { var a = JSON.parse(sessionStorage.getItem(BADGE_KEY) || '[]'); a.push([judul, ket, ok]); sessionStorage.setItem(BADGE_KEY, JSON.stringify(a.slice(-4))); } catch (e) { /* abaikan */ }
      return;
    }
    var w = $('#rw-badges'); if (!w) return;
    var b = el('div', 'rw-badge rw-badge--' + (ok ? 'ok' : 'gagal')); b.setAttribute('role', 'status');
    var t = el('div'); t.append(el('strong', '', judul)); if (ket) t.append(el('small', '', ket));
    b.append(el('span', 'rw-badge__ikon', ok ? '✓' : '!'), t);
    b.addEventListener('click', function () { b.remove(); });
    w.append(b);
    setTimeout(function () { b.classList.add('rw-badge--hilang'); setTimeout(function () { b.remove(); }, 350); }, ok ? 6500 : 10000);
  }
  try {
    JSON.parse(sessionStorage.getItem(BADGE_KEY) || '[]').forEach(function (x, i) { setTimeout(function () { badge(x[0], x[1], x[2]); }, 150 * i); });
    sessionStorage.removeItem(BADGE_KEY);
  } catch (e) { /* abaikan */ }

  /** Status API SIMPEG & BKN untuk dialog (chip dua titik). */
  function koneksi(segar) {
    var w = el('div', 'rw-sync__koneksi');
    var s = { simpeg: el('span', '', 'SIMPEG'), bkn: el('span', '', 'BKN') };
    w.append(s.simpeg, s.bkn);
    var janji = get({ aksi: 'status', segar: segar ? '1' : '0' }).then(function (j) {
      ['simpeg', 'bkn'].forEach(function (k) { s[k].className = (j[k] || {}).ok ? 'is-ok' : 'is-gagal'; s[k].title = (j[k] || {}).ok ? 'Terhubung' : 'Tidak terhubung'; });
      document.dispatchEvent(new CustomEvent('api-status', { detail: j }));
      return j;
    }).catch(function () { s.simpeg.className = s.bkn.className = 'is-gagal'; return {}; });
    return { el: w, janji: janji };
  }

  if (!CFG.kategori) return;

  /* ---------- identitas: Sync = "Segarkan data"; Ubah = form seperti SIMPEG ---------- */
  if (CFG.kategori === 'identitas') {
    var IDT = CFG.idt || {};
    /** Form ubah identitas: drawer (mobile) atau langsung di halaman bila target diberikan (desktop, seperti SIMPEG). */
    function bukaUbahIdentitas(target) {
      var form = el('form', 'rw-form'); form.noValidate = true;
      var awal = {};
      function f(k, label, inp, opsi) {
        opsi = opsi || {};
        var w = el('div', 'rw-f' + (opsi.penuh ? ' rw-f--penuh' : '')); w.dataset.k = k;
        var sasaran = opsi.untuk || inp, lb = el('label', '', label);
        if (!sasaran.id) sasaran.id = 'rw-idt-' + k;
        lb.htmlFor = sasaran.id;
        if (CFG.idtBkn.indexOf(k) >= 0) { var b = tag('BKN', 'biru'); b.title = 'Ikut dikirim ke SIASN BKN bila dicentang'; lb.append(' ', b); }
        w.append(lb, inp);
        if (opsi.ket) w.append(el('small', '', opsi.ket));
        form.append(w);
        return w;
      }
      function teks(k, label, tipe, ph, opsi) {
        var i = el(tipe === 'textarea' ? 'textarea' : 'input');
        if (tipe !== 'textarea') i.type = tipe; else i.rows = 3;
        i.name = k; i.value = IDT[k] || ''; i.placeholder = ph || ''; i.autocomplete = 'off';
        if (opsi && opsi.max) i.maxLength = opsi.max;
        awal[k] = i.value;
        return f(k, label, i, opsi);
      }
      // agama (referensi)
      var sAg = el('select'); sAg.name = 'agamaId';
      var opA = el('option', '', IDT.agama || 'Memuat…'); opA.value = IDT.agamaId || ''; sAg.append(opA);
      awal.agamaId = String(IDT.agamaId || '');
      f('agamaId', 'Agama', sAg);
      get({ aksi: 'ref', nama: 'agama' }).then(function (j) {
        sAg.replaceChildren();
        var o0 = el('option', '', '— Pilih agama —'); o0.value = ''; sAg.append(o0);
        (j.data || []).forEach(function (x) { var o = el('option', '', x.nama); o.value = x.id; sAg.append(o); });
        sAg.value = awal.agamaId;
        if (sAg.value !== awal.agamaId) { var o = el('option', '', IDT.agama || awal.agamaId); o.value = awal.agamaId; sAg.append(o); sAg.value = awal.agamaId; }
        segarS2(sAg);
      }).catch(function () { /* tetap pakai nilai saat ini */ });
      // status perkawinan
      var sK = el('select'); sK.name = 'jenisKawinId';
      var k0 = el('option', '', '— Pilih —'); k0.value = ''; sK.append(k0);
      Object.keys(CFG.kawin || {}).forEach(function (id) { var o = el('option', '', CFG.kawin[id]); o.value = id; sK.append(o); });
      sK.value = String(IDT.jenisKawinId || ''); awal.jenisKawinId = sK.value;
      f('jenisKawinId', 'Status perkawinan', sK);
      // golongan darah: segmented
      var seg = el('div', 'rw-seg'); seg.setAttribute('role', 'radiogroup'); seg.id = 'rw-idt-golDarah';
      awal.golDarah = String(IDT.golDarah || '').toUpperCase();
      (CFG.golDarah || []).concat(['']).forEach(function (g) {
        var lb = el('label'), r = el('input'); r.type = 'radio'; r.name = 'golDarah'; r.value = g; r.checked = g === awal.golDarah;
        lb.append(r, el('span', '', g || '–')); seg.append(lb);
      });
      var wG = f('golDarah', 'Golongan darah', seg, { penuh: true });
      $('label', wG).removeAttribute('for');
      // tempat lahir: pencarian kabupaten/kota
      var cari = el('div', 'rw-cari');
      var vis = el('input'); vis.type = 'text'; vis.id = 'rw-idt-tempatLahirId'; vis.value = IDT.tempatLahir || ''; vis.placeholder = 'Ketik nama kabupaten / kota…'; vis.autocomplete = 'off';
      var hid = el('input'); hid.type = 'hidden'; hid.name = 'tempatLahirId'; hid.value = IDT.tempatLahirId || ''; awal.tempatLahirId = hid.value;
      var ul = el('ul', 'rw-cari__list'); ul.hidden = true; ul.setAttribute('role', 'listbox');
      cari.append(vis, hid, ul);
      f('tempatLahirId', 'Tempat lahir', cari, { untuk: vis, ket: 'Pilih dari daftar kabupaten/kota SIASN.' });
      var tm, aktifI = -1;
      vis.addEventListener('input', function () {
        hid.value = ''; clearTimeout(tm);
        var q = vis.value.trim();
        if (q.length < 2) { ul.hidden = true; return; }
        tm = setTimeout(function () {
          get({ aksi: 'ref', nama: 'wilayah', q: q }).then(function (j) {
            ul.replaceChildren(); aktifI = -1;
            (j.data || []).forEach(function (x) {
              var li = el('li', '', x.nama); li.setAttribute('role', 'option'); li._x = x;
              li.addEventListener('mousedown', function (e) { e.preventDefault(); pilihT(x); });
              ul.append(li);
            });
            if (!ul.childNodes.length) ul.append(el('li', 'rw-muted', 'Tidak ditemukan'));
            ul.hidden = false;
          }).catch(function () { ul.hidden = true; });
        }, 250);
      });
      function pilihT(x) { vis.value = x.nama; hid.value = x.id; ul.hidden = true; hid.dispatchEvent(new Event('change', { bubbles: true })); }
      vis.addEventListener('keydown', function (e) {
        var li = $$('li', ul).filter(function (x) { return x._x; });
        if (ul.hidden || !li.length) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault(); aktifI = (aktifI + (e.key === 'ArrowDown' ? 1 : -1) + li.length) % li.length;
          li.forEach(function (x, i) { x.classList.toggle('is-aktif', i === aktifI); });
        } else if (e.key === 'Enter' && aktifI >= 0) { e.preventDefault(); pilihT(li[aktifI]._x); }
      });
      vis.addEventListener('blur', function () { setTimeout(function () { ul.hidden = true; if (!hid.value) { vis.value = IDT.tempatLahir || ''; hid.value = awal.tempatLahirId; } }, 150); });

      teks('noHp', 'Nomor HP', 'tel', '081234567890', { max: 20 });
      teks('email', 'Email pribadi', 'email', 'nama@contoh.com', { max: 100 });
      teks('emailGov', 'Email dinas', 'email', 'nama@kepulauanselayarkab.go.id', { max: 100, ket: 'Harus berdomain .go.id' });
      teks('alamat', 'Alamat', 'textarea', 'Alamat lengkap', { max: 500, penuh: true });

      // kirim ke BKN (tampil bila ada kolom BKN yang berubah)
      var bknW = el('div', 'rw-f rw-f--penuh'); bknW.hidden = true;
      var bknBox = el('div', 'rw-info rw-info--biru');
      var cbL = el('label', 'rw-check'); cbL.style.padding = '0';
      var cb = el('input'); cb.type = 'checkbox'; cb.checked = true; cb.id = 'rw-idt-bkn';
      var cbT = el('span', '', 'Kirim juga perubahan ke SIASN BKN');
      cbL.append(cb, cbT); bknBox.append(cbL);
      var kon = null;
      bknW.append(bknBox); form.append(bknW);

      function nilai() {
        var v = {};
        $$('input[name],select[name],textarea[name]', form).forEach(function (i) {
          if (i.type === 'radio') { if (i.checked) v[i.name] = i.value; return; }
          v[i.name] = i.value.trim();
        });
        if (!('golDarah' in v)) v.golDarah = '';
        return v;
      }
      function beda() {
        var v = nilai(), d = {};
        Object.keys(v).forEach(function (k) { if (String(v[k]) !== String(awal[k] === undefined ? '' : awal[k])) d[k] = v[k]; });
        if ('tempatLahirId' in d && !d.tempatLahirId) delete d.tempatLahirId;
        return d;
      }
      function segar() {
        var d = beda();
        $$('[name]', form).forEach(function (i) { if (i.type !== 'radio' && i.type !== 'hidden') i.classList.toggle('is-ubah', i.name in d); });
        vis.classList.toggle('is-ubah', 'tempatLahirId' in d);
        var adaBkn = Object.keys(d).some(function (k) { return CFG.idtBkn.indexOf(k) >= 0; });
        bknW.hidden = !adaBkn;
        if (adaBkn && !kon) {
          kon = koneksi(false); bknBox.append(kon.el);
          kon.janji.then(function (j) { if (!(j.bkn || {}).ok) { cb.checked = false; cbT.textContent = 'Kirim juga ke SIASN BKN (API BKN sedang tidak terhubung)'; } });
        }
        simpan.disabled = !Object.keys(d).length;
      }
      form.addEventListener('input', function (e) { var w = e.target.closest('.rw-f'); if (w) { w.classList.remove('is-error'); $$('.rw-f__err', w).forEach(function (x) { x.remove(); }); } segar(); });
      form.addEventListener('change', segar);

      var simpan = tombol(target ? 'Simpan perubahan' : 'Simpan', 'rw-btn--primary', 'bi-check2');
      simpan.disabled = true;
      var batalI = target ? tombol('Batal', '', null, function () { bukaUbahIdentitas(target); }) : null;
      var kunciF = function (ya, teks) {
        if (!target) { sibuk(ya, simpan, teks); return; }
        simpan.disabled = batalI.disabled = ya;
        if (ya) { simpan.dataset.asli = $('span', simpan).textContent; $('span', simpan).textContent = teks; } else $('span', simpan).textContent = simpan.dataset.asli || 'Simpan perubahan';
      };
      function jalan(e) {
        if (e) e.preventDefault();
        var d = beda();
        if (!Object.keys(d).length) return;
        var fd = fdBaru('identitas_simpan');
        Object.keys(d).forEach(function (k) { fd.set(k, d[k]); });
        if (!bknW.hidden && cb.checked) fd.set('kirim_bkn', '1');
        kunciF(true, 'Menyimpan…');
        post(fd).then(function (j) {
          modalSibuk = false;
          badge('Identitas tersimpan di SIMPEG', j.message, true, true);
          if (j.bkn && j.bkn.status !== 'skip') badge(j.bkn.status === 'success' ? 'Terkirim ke SIASN BKN' : 'Belum terkirim ke SIASN BKN', j.bkn.message, j.bkn.status === 'success', true);
          else if (j.bkn && j.bkn.message) badge('Hanya disimpan di SIMPEG', j.bkn.message, true, true);
          muatUlang();
        }).catch(function (err) {
          kunciF(false);
          var errs = (err.data && err.data.errors) || {};
          if (err.data && err.data.field && !errs[err.data.field]) errs[err.data.field] = err.message;
          Object.keys(errs).forEach(function (k) {
            var w = $('.rw-f[data-k="' + k + '"]', form); if (!w) return;
            w.classList.add('is-error'); $$('.rw-f__err', w).forEach(function (x) { x.remove(); });
            w.append(el('small', 'rw-f__err', errs[k]));
          });
          var pertama = $('.rw-f.is-error input:not([type=hidden]),.rw-f.is-error select,.rw-f.is-error textarea', form);
          if (pertama) pertama.focus();
          toast(err.message, false);
        });
      }
      form.addEventListener('submit', jalan);
      simpan.addEventListener('click', jalan);
      var box = el('div');
      box.append(info('Perubahan langsung disimpan ke SIMPEG seperti menu Identitas SIMPEG. Isian bertanda BKN dapat ikut dikirim ke SIASN. Data lain (NIP, NIK, jabatan, unit) mengikuti SIASN.', 'biru', 'bi-info-circle'), form);
      if (target) {
        var aksiI = el('div', 'rw-idt__aksi'); aksiI.append(batalI, simpan);
        box.append(aksiI);
        target.replaceChildren(box);
      } else bukaModal('Ubah identitas', box, [tombol('Batal', '', null, tutupModal), simpan], { drawer: true });
      pasangSemuaSelect(form);
    }
    // desktop: form identitas langsung tampil di halaman (seperti SIMPEG); mobile: tombol Ubah -> drawer
    var idtTarget = $('#rw-idt-form'), idtNilai = $('#rw-idt-nilai'), idtPanel = $('#rw-panel');
    function aturIdtInline() {
      if (!idtTarget || !$('[data-rw="ubah-identitas"]')) return;
      var inline = !HP.matches;
      idtPanel.classList.toggle('rw-idt--inline', inline);
      if (idtNilai) idtNilai.hidden = inline;
      if (inline && !idtTarget.firstChild) bukaUbahIdentitas(idtTarget);
      if (!inline) idtTarget.replaceChildren();
    }
    aturIdtInline();
    if (HP.addEventListener) HP.addEventListener('change', aturIdtInline);
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-rw]');
      if (!b) return;
      if (b.dataset.rw === 'ubah-identitas') { bukaUbahIdentitas(); return; }
      if (b.dataset.rw !== 'segarkan') return;
      var form = document.getElementById('riwayat-refresh-form');
      if (!form) return;
      if (form.requestSubmit) form.requestSubmit(); else form.dispatchEvent(new Event('submit', { cancelable: true }));
    });
    return;
  }

  var BARIS = CFG.baris || [], USULAN = CFG.usulan || [], FORM = CFG.form || {}, IZIN = CFG.izin || {};
  function usulanById(id) { return USULAN.filter(function (u) { return u.id === id; })[0] || null; }
  function bisaUbahUsulan(u) { return u && (u.status === 'diajukan' || u.status === 'dikembalikan'); }
  function urlRiwayat(row, kolom) { return CFG.dokUrl + '?' + new URLSearchParams({ kategori: CFG.kategori, id: row.id_rw, berkas: kolom }).toString(); }
  function urlUsulan(u, kolom) { return CFG.dokUrl + '?' + new URLSearchParams({ usulan: u.id, berkas: kolom }).toString(); }
  function labelDok(jenis, kolom) {
    var d = (FORM[jenis] && FORM[jenis].dok || []).filter(function (x) { return x[0] === kolom; })[0];
    return d ? d[1] : kolom;
  }
  /** Kolom dokumen riwayat untuk kolom dokumen usulan (mis. SK pangkat: sk -> scan). */
  function kolomRiwayat(jenis, kolom) { return ((CFG.dokRiwayat || {})[jenis] || {})[kolom] || kolom; }

  /* ---------- daftar riwayat: kartu + pencarian + halaman ---------- */
  var daftar = { q: '', hal: 1, per: 10 };
  try { daftar.per = +(localStorage.getItem('rw-per') || 10) || 10; } catch (e) { /* penyimpanan tidak tersedia */ }
  var listEl = $('#rw-list'), pagerEl = $('#rw-pager'), cariEl = $('#rw-cari'), perEl = $('#rw-per');
  function teksCari(r) {
    if (!r._cari) r._cari = [r.judul, r.sub, r.label, r.waktu].concat((r.info || []).map(function (p) { return p[1]; })).join(' ').toLowerCase();
    return r._cari;
  }
  function badgeInfo(teks, warna, ik, judul) {
    var b = el('span', 'rw-bdg rw-bdg--' + warna);
    if (ik) b.append(ikon(ik));
    b.append(el('span', '', teks));
    if (judul) b.title = judul;
    return b;
  }
  /** Status kelengkapan dokumen (seperti kolom "Kelengkapan" SIMPEG). */
  function tagLengkap(r, kecil) {
    if (r.lengkap === null || r.lengkap === undefined) return null;
    var t = el('span', 'rw-lengkap ' + (r.lengkap ? 'is-ok' : 'is-kurang') + (kecil ? ' rw-lengkap--kecil' : ''), r.lengkap ? 'Lengkap' : 'Belum lengkap');
    if (!r.lengkap && (r.kurang || []).length) t.title = 'Belum ada: ' + r.kurang.join(', ');
    return t;
  }
  /**
   * Kartu mobile, 3 baris: (1) judul + badge kecil (gelar / jenis jabatan / status), (2) keterangan,
   * (3) tanggal / TMT + status kelengkapan. Klik = detail (Edit/Hapus di bottom sheet).
   */
  function kartu(r) {
    var card = el('article', 'rw-card'); card.dataset.idx = r.idx; card.tabIndex = 0; card.setAttribute('role', 'button');
    card.setAttribute('aria-label', r.judul + (r.waktu ? ', ' + r.waktu : '') + ' — lihat detail');
    var ico = el('span', 'rw-card__ico ico--' + (CFG.warna || 'tosca')); ico.append(ikon(CFG.ikon || 'bi-folder'));
    if (r.usulan) { var dot = el('b', 'rw-card__dot'); dot.title = 'Ada usulan diproses'; ico.append(dot); }
    var body = el('div', 'rw-card__body');
    var h = el('h3', 'rw-card__judul'); h.append(document.createTextNode(r.judul));
    if (r.badge) h.append(document.createTextNode(' '), el('span', 'rw-mini', r.badge));
    body.append(h);
    if (r.sub) body.append(el('p', 'rw-card__sub', r.sub));
    var b3 = el('div', 'rw-card__b3');
    if (r.b3) b3.append(el('span', '', r.b3));
    var lk = tagLengkap(r, true); if (lk) b3.append(lk);
    if (r.usulan) b3.append(el('span', 'rw-lengkap rw-lengkap--kecil is-proses', 'Usulan diproses'));
    if (b3.childNodes.length) body.append(b3);
    card.append(ico, body, ikon('bi-chevron-right rw-card__panah'));
    return card;
  }
  /** Tabel desktop per jenis (seperti tab riwayat SIMPEG): No, kolom data, Dokumen, Kelengkapan, Aksi. */
  function tabel(rows, nomorAwal) {
    var wrap = el('div', 'rw-tabel-wrap');
    var t = el('table', 'rw-tabel'), th = el('thead'), tr = el('tr');
    tr.append(el('th', 'rw-tabel__no', 'No'));
    (rows[0].kolom || []).forEach(function (c) { tr.append(el('th', '', c[0])); });
    if (!(rows[0].kolom || []).length) tr.append(el('th', '', 'Riwayat'));
    tr.append(el('th', 'rw-tabel__dok', 'Dokumen'), el('th', 'rw-tabel__tengah', 'Kelengkapan'), el('th', 'rw-tabel__tengah', 'Aksi'));
    th.append(tr); t.append(th);
    var tb = el('tbody');
    rows.forEach(function (r, i) {
      var tr2 = el('tr'); tr2.dataset.idx = r.idx;
      tr2.append(el('td', 'rw-tabel__no', String(nomorAwal + i + 1)));
      if ((r.kolom || []).length) r.kolom.forEach(function (c, j) {
        var td = el('td', j === 0 ? 'rw-tabel__utama' : '');
        var bd = c[2] && c[2] !== c[1] ? el('span', 'rw-mini' + (c[4] ? ' rw-mini--biru' : ''), c[2]) : null;
        if (bd && c[4]) { td.append(bd, el('br')); bd = null; }                 // badge di atas (mis. jenis diklat)
        td.append(c[4] && j === 0 ? el('b', '', c[1] || '-') : document.createTextNode(c[1] || '-'));
        if (bd) td.append(document.createTextNode(' '), bd);
        if (c[3]) td.append(el('div', 'rw-tabel__sub', c[3]));
        if (j === 0 && r.usulan) td.append(document.createTextNode(' '), el('span', 'rw-mini rw-mini--kuning', 'Usulan diproses'));
        tr2.append(td);
      });
      else { var td0 = el('td', 'rw-tabel__utama', r.judul); if (r.sub) td0.append(el('div', 'rw-muted', r.sub)); tr2.append(td0); }
      var tdD = el('td', 'rw-tabel__dok');
      if ((r.dok || []).length && r.id_rw) r.dok.forEach(function (d) {
        var b = el('button', 'rw-dokbtn'); b.type = 'button'; b.dataset.rw = 'lihat'; b.dataset.idx = r.idx; b.dataset.dok = d[1]; b.title = 'Lihat ' + d[0];
        b.append(ikon('bi-file-earmark-text'), document.createTextNode(d[0])); tdD.append(b);
      });
      else tdD.append(el('span', 'rw-muted', '-'));
      var tdL = el('td', 'rw-tabel__tengah'); var lk = tagLengkap(r); tdL.append(lk || el('span', 'rw-muted', '-'));
      var tdA = el('td', 'rw-tabel__tengah'), ak = el('div', 'rw-aksi');
      var mk = function (aksi, ik, cls, lbl) {
        var b = el('button', 'rw-aksi__btn ' + cls); b.type = 'button'; b.dataset.rw = aksi; b.dataset.idx = r.idx; b.title = lbl;
        b.setAttribute('aria-label', lbl + ' ' + r.judul); b.append(ikon(ik)); ak.append(b);
      };
      var iz = IZIN[r.jenis] || {};
      mk('lihat', 'bi-eye', '', 'Lihat');
      if (iz.ubah) mk('ubah', 'bi-pencil', 'rw-aksi__btn--edit', r.usulan ? 'Lihat usulan' : 'Edit');
      if (iz.hapus) mk('hapus', 'bi-trash3', 'rw-aksi__btn--hapus', r.usulan ? 'Lihat usulan' : 'Hapus');
      tdA.append(ak);
      tr2.append(tdD, tdL, tdA);
      tb.append(tr2);
    });
    t.append(tb); wrap.append(t);
    return wrap;
  }
  /* ---------- desktop: satu bagian per jenis (seperti tab SIMPEG): Tambah, Sync, Tampilkan Usulan, segarkan ---------- */
  function pagerKe(target, total, hal, per, q, pilih) {
    target.replaceChildren();
    var jml = Math.max(1, Math.ceil(total / per));
    target.hidden = total <= per;
    var awal = (hal - 1) * per;
    target.append(el('span', 'rw-pager__info', (awal + 1) + '–' + Math.min(total, awal + per) + ' dari ' + total + (q ? ' hasil' : ' riwayat')));
    if (jml < 2) return;
    var nav = el('span', 'rw-pager__nav');
    var btn = function (isi, h, aktif, label) {
      var b = el('button', 'rw-pager__btn' + (aktif ? ' is-aktif' : '')); b.type = 'button';
      if (typeof isi === 'string') b.textContent = isi; else b.append(isi);
      if (label) b.setAttribute('aria-label', label);
      if (aktif) b.setAttribute('aria-current', 'page');
      b.disabled = h < 1 || h > jml;
      b.addEventListener('click', function () { pilih(h); });
      nav.append(b);
    };
    btn(ikon('bi-chevron-left'), hal - 1, false, 'Sebelumnya');
    var nomor = [1, hal - 1, hal, hal + 1, jml].filter(function (h, i, a) { return h >= 1 && h <= jml && a.indexOf(h) === i; }).sort(function (a, b) { return a - b; });
    nomor.forEach(function (h, i) {
      if (i > 0 && h - nomor[i - 1] > 1) nav.append(el('span', 'rw-pager__elip', '…'));
      btn(String(h), h, h === hal);
    });
    btn(ikon('bi-chevron-right'), hal + 1, false, 'Berikutnya');
    target.append(nav);
  }
  function ringkasUsulan(u) {
    var d = u.data || {}, l = u.data_lama || {};
    return [d.nama || d.latihan_nama || d.harga_nama || d.jabatan_nama || d.unor_nama || d.tk_pendidikan_nama || l.nama || l.latihan_nama || l.jabatan_nama || '',
      d.penyelenggara || d.nama_sekolah || d.unor_nama || '', d.tahun || (d.tgl_mulai || d.tmt_jabatan || d.tgl_sk || '').slice(0, 4) || d.no_sk || ''].filter(Boolean).filter(function (x, i, a) { return a.indexOf(x) === i; }).join(' · ') || '-';
  }
  var WARNA_AKSI = { tambah: 'hijau', ubah: 'kuning', hapus: 'merah' };
  function tabelUsulan(jenis) {
    var list = USULAN.filter(function (u) { return u.jenis === jenis; });
    var wrap = el('div', 'rw-tabel-wrap'), t = el('table', 'rw-tabel rw-tabel--usulan'), tr = el('tr');
    ['Diajukan', 'Usulan', 'Ringkasan', 'Status', 'Aksi'].forEach(function (h, i) { tr.append(el('th', i === 1 || i >= 3 ? 'rw-tabel__tengah' : '', h)); });
    var th = el('thead'); th.append(tr); t.append(th);
    var tb = el('tbody');
    if (CFG.usulanGagal || !list.length) {
      var tr0 = el('tr', 'rw-tabel__kosong'), td0 = el('td', '', CFG.usulanGagal ? 'Daftar usulan belum dapat dimuat dari SIMPEG.' : 'Belum ada usulan.'); td0.colSpan = 5; tr0.append(td0); tb.append(tr0);
    }
    list.forEach(function (u) {
      var r = el('tr'); r.dataset.uid = u.id;
      var d1 = el('td'); d1.append(el('b', '', tgl(u.diajukan_at)), el('div', 'rw-muted', u.diverifikasi_at ? 'Diverifikasi ' + tgl(u.diverifikasi_at) : 'oleh Anda'));
      var d2 = el('td', 'rw-tabel__tengah'); d2.append(el('span', 'rw-mini rw-mini--' + (WARNA_AKSI[u.aksi] || 'abu'), (AKSI[u.aksi] || [u.aksi])[0]));
      var d3 = el('td'); d3.append(document.createTextNode(ringkasUsulan(u)));
      if (u.catatan) d3.append(el('div', 'rw-muted', 'Catatan: ' + u.catatan));
      var d4 = el('td', 'rw-tabel__tengah'); d4.append(statusTag(u.status));
      var d5 = el('td', 'rw-tabel__tengah'), ak = el('div', 'rw-aksi');
      var mk = function (aksi, ik, cls, lbl) { var b = el('button', 'rw-aksi__btn ' + cls); b.type = 'button'; b.dataset.rw = aksi; b.dataset.uid = u.id; b.title = lbl; b.setAttribute('aria-label', lbl); b.append(ikon(ik)); ak.append(b); };
      mk('u-lihat', 'bi-eye', '', 'Lihat usulan');
      if (bisaUbahUsulan(u)) {
        if ((IZIN[u.jenis] || {})[u.aksi]) mk('u-edit', 'bi-pencil', 'rw-aksi__btn--edit', u.status === 'dikembalikan' ? 'Perbaiki usulan' : 'Edit usulan');
        mk('u-batal', 'bi-trash3', 'rw-aksi__btn--hapus', 'Batalkan usulan');
      }
      d5.append(ak);
      r.append(d1, d2, d3, d4, d5);
      tb.append(r);
    });
    t.append(tb); wrap.append(t);
    return wrap;
  }
  function editUsulan(u) {
    if (u.aksi === 'hapus') bukaHapus(BARIS.filter(function (r) { return r.jenis === u.jenis && r.id_rw === u.ref_id; })[0] || null, u);
    else bukaForm(u.jenis, u.aksi, BARIS.filter(function (r) { return r.jenis === u.jenis && (r.id_rw === u.ref_id || u.jenis === 'cpns'); })[0] || null, u);
  }
  function tabelKosong(jenis) {
    var wrap = el('div', 'rw-tabel-wrap'), t = el('table', 'rw-tabel'), tr = el('tr');
    var jud = ((CFG.kolomJudul || {})[jenis] || []);
    tr.append(el('th', 'rw-tabel__no', 'No'));
    (jud.length ? jud : ['Riwayat']).forEach(function (h) { tr.append(el('th', '', h)); });
    tr.append(el('th', 'rw-tabel__dok', 'Dokumen'), el('th', 'rw-tabel__tengah', 'Kelengkapan'), el('th', 'rw-tabel__tengah', 'Aksi'));
    var th = el('thead'); th.append(tr); t.append(th);
    var tb = el('tbody'), r = el('tr', 'rw-tabel__kosong'), td = el('td', '', daftar.q.trim() ? 'Tidak ada riwayat yang cocok dengan pencarian.' : 'Belum ada riwayat. Gunakan "Sync dari SIASN".');
    td.colSpan = (jud.length || 1) + 4; r.append(td); tb.append(r); t.append(tb); wrap.append(t);
    return wrap;
  }
  daftar.halJ = {}; daftar.modeJ = {};
  (function () {
    var buka = null;
    try { buka = JSON.parse(sessionStorage.getItem('rw-buka-usulan') || 'null'); sessionStorage.removeItem('rw-buka-usulan'); } catch (e) {}
    if (buka && buka.kategori === CFG.kategori) {
      if (buka.jenis) daftar.modeJ[buka.jenis] = 'usulan';
      else USULAN.forEach(function (u) { daftar.modeJ[u.jenis] = 'usulan'; });
    } else if (CFG.bukaUsulan) USULAN.forEach(function (u) { daftar.modeJ[u.jenis] = 'usulan'; });
    // tautan lama ...&usulan=1: rapikan URL
    if (CFG.bukaUsulan && window.history.replaceState) {
      var bersih = new URL(window.location.href); bersih.searchParams.delete('usulan'); window.history.replaceState(null, '', bersih.href);
    }
  })();
  function renderSeksi(cocok, q) {
    var urutan = (CFG.jenis || []).slice().sort(function (a, b) { return (a === 'diklatpim' ? -1 : 0) - (b === 'diklatpim' ? -1 : 0); });
    urutan.forEach(function (j) {
      var label = (CFG.jenisLabel || {})[j] || j;
      var rows = BARIS.filter(function (r) { return r.jenis === j && cocok(r); });
      var mode = daftar.modeJ[j] || 'riwayat';
      var sek = el('section', 'rw-seksi'); sek.dataset.jenis = j;
      var head = el('div', 'rw-seksi__head');
      var h = el('h3', 'rw-seksi__judul'); h.append(ikon(CFG.ikon || 'bi-folder'), document.createTextNode(' ' + (/^riwayat/i.test(label) || j === 'cpns' ? label : 'Riwayat ' + label)));
      var bar = el('div', 'rw-seksi__aksi');
      var b = function (rw, teks, ik, cls, extra) {
        var x = el('button', 'rw-sbtn ' + cls); x.type = 'button'; x.dataset.rw = rw; x.dataset.jenis = j;
        if (ik) x.append(ikon(ik)); if (teks) x.append(el('span', '', teks));
        if (extra) x.append(extra);
        bar.append(x); return x;
      };
      if ((IZIN[j] || {}).tambah) b('tambah', 'Tambah', 'bi-plus-lg', 'rw-sbtn--tambah');
      var sy = b('sync', 'Sync dari SIASN', 'bi-cloud-arrow-down-fill', 'rw-sbtn--sync', ikon('bi-caret-down-fill rw-caret')); sy.setAttribute('aria-haspopup', 'menu');
      var nU = USULAN.filter(function (u) { return u.jenis === j && bisaUbahUsulan(u); }).length;
      b('usulan-j', mode === 'usulan' ? 'Tampilkan Riwayat' : 'Tampilkan Usulan', mode === 'usulan' ? 'bi-list-ul' : 'bi-journal-text', 'rw-sbtn--usulan', nU ? el('b', 'rw-sbtn__n', String(nU)) : null);
      var seg = b('segar', '', 'bi-arrow-clockwise', 'rw-sbtn--ikon'); seg.title = 'Muat ulang dari SIMPEG'; seg.setAttribute('aria-label', 'Muat ulang');
      head.append(h, bar);
      sek.append(head);
      if (mode === 'usulan') sek.append(tabelUsulan(j));
      else {
        if (j === 'pendidikan' && !q && BARIS[0]) {
          var t0 = BARIS[0], k0 = t0.kolom || [], rk = el('div', 'rw-ringkas');
          [['Tingkat Pendidikan Terakhir', (k0[0] || [])[1]], ['Pendidikan Terakhir', (k0[1] || [])[1] || t0.judul], ['Tanggal Lulus', t0.waktu]].forEach(function (p) {
            var d = el('div'); d.append(el('small', '', p[0]), el('strong', '', p[1] || '-')); rk.append(d);
          });
          sek.append(rk);
        }
        if (!rows.length) sek.append(tabelKosong(j));
        else {
          var hal = Math.min(daftar.halJ[j] || 1, Math.max(1, Math.ceil(rows.length / daftar.per)));
          var awal = (hal - 1) * daftar.per;
          sek.append(tabel(rows.slice(awal, awal + daftar.per), awal));
          var pg = el('nav', 'rw-pager'); pg.setAttribute('aria-label', 'Halaman ' + label);
          pagerKe(pg, rows.length, hal, daftar.per, q, function (h2) { daftar.halJ[j] = h2; renderDaftar(); var s2 = $('.rw-seksi[data-jenis="' + j + '"]'); if (s2) s2.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
          sek.append(pg);
        }
      }
      listEl.append(sek);
    });
  }
  function renderDaftar() {
    if (!listEl) return;
    listEl.replaceChildren();
    if (CFG.gagal) {
      var g = el('div', 'rw-empty rw-empty--gagal'); g.append(ikon('bi-cloud-slash'), el('p', '', 'Riwayat belum dapat dimuat dari SIMPEG' + (CFG.kodeGagal !== null && CFG.kodeGagal !== undefined ? ' (kode ' + CFG.kodeGagal + ')' : '') + '. Silakan coba lagi beberapa saat lagi.'));
      listEl.append(g); pagerEl.hidden = true; return;
    }
    var q = daftar.q.trim().toLowerCase();
    var cocok = function (r) { return !q || q.split(/\s+/).every(function (k) { return teksCari(r).indexOf(k) >= 0; }); };
    var pnl = $('#rw-panel');
    listEl.classList.toggle('rw-list--tabel', !HP.matches);
    if (pnl) pnl.classList.toggle('rw-panel--seksi', !HP.matches);
    if (!HP.matches) { renderSeksi(cocok, q); pagerEl.hidden = true; return; }
    var data = q ? BARIS.filter(cocok) : BARIS;
    var jml = Math.max(1, Math.ceil(data.length / daftar.per));
    daftar.hal = Math.min(Math.max(1, daftar.hal), jml);
    if (!data.length) {
      var k = el('div', 'rw-empty'); k.append(ikon(q ? 'bi-search' : 'bi-inbox'));
      var p = el('p', '', q ? 'Tidak ada riwayat yang cocok dengan "' + daftar.q.trim() + '".' : 'Belum ada data pada kategori ini.');
      if (!q) { p.append(el('br')); p.append(el('small', '', 'Gunakan Sync SIASN untuk menarik data dari BKN' + ((CFG.jenis || []).some(function (j) { return (IZIN[j] || {}).tambah; }) ? ' atau Tambah untuk mengajukan riwayat baru.' : '.'))); }
      k.append(p); listEl.append(k); pagerEl.hidden = true; return;
    }
    var awal = (daftar.hal - 1) * daftar.per;
    var hal = data.slice(awal, awal + daftar.per);
    hal.forEach(function (r) { listEl.append(kartu(r)); });
    // halaman
    pagerEl.replaceChildren();
    pagerEl.hidden = data.length <= daftar.per && !q;
    pagerEl.append(el('span', 'rw-pager__info', (awal + 1) + '–' + Math.min(data.length, awal + daftar.per) + ' dari ' + data.length + (q ? ' hasil' : ' riwayat')));
    if (jml > 1) {
      var nav = el('span', 'rw-pager__nav');
      var btn = function (isi, h, aktif, label) {
        var b = el('button', 'rw-pager__btn' + (aktif ? ' is-aktif' : '')); b.type = 'button';
        if (typeof isi === 'string') b.textContent = isi; else b.append(isi);
        if (label) b.setAttribute('aria-label', label);
        if (aktif) b.setAttribute('aria-current', 'page');
        b.disabled = h < 1 || h > jml;
        b.addEventListener('click', function () { daftar.hal = h; renderDaftar(); var pn = $('#rw-panel'); if (pn) pn.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        nav.append(b);
      };
      btn(ikon('bi-chevron-left'), daftar.hal - 1, false, 'Sebelumnya');
      var nomor = [1, daftar.hal - 1, daftar.hal, daftar.hal + 1, jml].filter(function (h, i, a) { return h >= 1 && h <= jml && a.indexOf(h) === i; }).sort(function (a, b) { return a - b; });
      nomor.forEach(function (h, i) {
        if (i > 0 && h - nomor[i - 1] > 1) nav.append(el('span', 'rw-pager__elip', '…'));
        btn(String(h), h, h === daftar.hal);
      });
      btn(ikon('bi-chevron-right'), daftar.hal + 1, false, 'Berikutnya');
      pagerEl.append(nav);
    }
  }
  if (perEl) {
    perEl.value = String(daftar.per);
    if (perEl.value !== String(daftar.per)) { daftar.per = 10; perEl.value = '10'; }
    perEl.addEventListener('change', function () { daftar.per = +perEl.value; daftar.hal = 1; try { localStorage.setItem('rw-per', perEl.value); } catch (e) { /* abaikan */ } renderDaftar(); });
  }
  if (cariEl) {
    var tc;
    cariEl.addEventListener('input', function () { clearTimeout(tc); tc = setTimeout(function () { daftar.q = cariEl.value; daftar.hal = 1; renderDaftar(); }, 150); });
  }
  renderDaftar();
  if (HP.addEventListener) HP.addEventListener('change', function () { renderDaftar(); }); else if (HP.addListener) HP.addListener(function () { renderDaftar(); });

  /* ---------- referensi (select & pencarian) ---------- */
  var REF = {};
  function ambilRef(nama) {
    if (!REF[nama]) REF[nama] = get({ aksi: 'ref', nama: nama }).then(function (j) { return j.data || []; }).catch(function (e) { delete REF[nama]; throw e; });
    return REF[nama];
  }

  /* ---------- Lihat riwayat (drawer) ---------- */
  function ikonLabel(l) {
    l = String(l).toLowerCase();
    if (/kelengkapan/.test(l)) return 'bi-patch-check';
    if (/tingkat|jurusan|gelar|program/.test(l)) return 'bi-mortarboard';
    if (/tanggal|tgl|tmt|lahir|pelaksanaan|berakhir|periode|tahun|lulus/.test(l)) return 'bi-calendar3';
    if (/no\.|nomor|sk|akta|ijazah|sertifikat|pertek|sttpl|spmt|karpeg/.test(l)) return 'bi-file-earmark-text';
    if (/unit|instansi|lokasi|penyelenggara|sekolah|unor/.test(l)) return 'bi-building';
    if (/jam|masa/.test(l)) return 'bi-clock-history';
    if (/gaji|angka|unsur|kredit/.test(l)) return 'bi-123';
    if (/penilai|pejabat|nama/.test(l)) return 'bi-person';
    if (/jabatan|jenis|mutasi/.test(l)) return 'bi-briefcase';
    return 'bi-dot';
  }
  function barisInfo(pasangan) {
    var dl = el('dl', 'rw-rows');
    pasangan.forEach(function (p) {
      var d = el('div'), dt = el('dt');
      dt.append(ikon(ikonLabel(p[0])), document.createTextNode(p[0]));
      d.append(dt, el('dd', '', p[1]));
      dl.append(d);
    });
    return dl;
  }
  function lihat(idx, dokKolom) {
    var r = BARIS[idx];
    if (!r) return;
    var box = el('div', 'rw-detail');
    var u = r.usulan ? usulanById(r.usulan) : null;
    if (u) box.append(info('Riwayat ini memiliki usulan ' + u.aksi + ' yang sedang diproses (' + (STATUS[u.status] || [u.status])[0].toLowerCase() + ').', 'kuning', 'bi-hourglass-split'));
    // rincian tanpa judul besar: kolom utama (seperti tabel SIMPEG) lalu info lain yang belum tampil
    var pasang = (r.kolom || []).filter(function (c) { return c[1]; }).map(function (c) { return [c[0], c[1] + (c[2] && c[2] !== c[1] ? ' (' + c[2] + ')' : '')]; });
    if (!pasang.length) { pasang.push(['Riwayat', r.judul]); if (r.sub) pasang.push(['Keterangan', r.sub]); }
    var adaNilai = pasang.map(function (p) { return p[1]; }).join('|');
    (r.info || []).forEach(function (p) { if (adaNilai.indexOf(p[1]) < 0) pasang.push(p); });
    if (r.lengkap !== null && r.lengkap !== undefined) pasang.push(['Kelengkapan', r.lengkap ? 'Lengkap' : 'Belum lengkap' + ((r.kurang || []).length ? ' — belum ada ' + r.kurang.join(', ') : '')]);
    var docs = (r.id_rw ? r.dok || [] : []).map(function (d) { return { label: d[0], kolom: d[1], sumber: { url: urlRiwayat(r, d[1]) } }; });
    var fokus = 0;
    docs.forEach(function (d, i) { if (d.kolom === dokKolom) fokus = i; });
    var blok = blokDokumen(docs, fokus, 'Belum ada dokumen di repository. Gunakan Sync SIASN untuk menarik dokumen dari BKN.');
    if (HP.matches) { box.append(barisInfo(pasang), el('div', 'rw-sec', 'Dokumen'), blok); }
    else {           // desktop: popup lebar, rincian di kiri & dokumen (tab) di kanan seperti SIMPEG
      box.classList.add('rw-dua');
      var kiri = el('div', 'rw-dua__kiri'), kanan = el('div', 'rw-dua__kanan');
      kiri.append.apply(kiri, Array.prototype.slice.call(box.childNodes));
      kiri.append(tabelInfo(pasang));
      kanan.append(blok);
      box.append(kiri, kanan);
    }
    var kaki = [];
    var iz = IZIN[r.jenis] || {};
    if (u) kaki.push(tombol('Lihat usulan', 'rw-btn--primary', 'bi-inboxes', function () { lihatUsulan(u); }));
    else {
      if (iz.hapus) kaki.push(tombol('Hapus', 'rw-btn--merah-garis', 'bi-trash3', function () { bukaHapus(r, null); }));
      if (iz.ubah) kaki.push(tombol('Edit', 'rw-btn--kuning', 'bi-pencil-square', function () { bukaForm(r.jenis, 'ubah', r, null); }));
    }
    kaki.push(tombol('Tutup', '', null, tutupModal));
    var lbl = (CFG.jenisLabel || {})[r.jenis] || String(CFG.judul || '').replace(/^Riwayat /, '');
    if (HP.matches) bukaModal('Detail ' + lbl, box, kaki, { drawer: true, fokus: $('[data-tutup]', modalEl) });
    else bukaModal('Riwayat ' + lbl + ' · ' + (((r.kolom || [])[0] || [])[1] || r.judul), box, kaki, { kelas: 'rw-modal--besar', fokus: $('[data-tutup]', modalEl) });
  }
  /** Rincian dua kolom (label | nilai) untuk popup desktop. */
  function tabelInfo(pasang) {
    var t = el('table', 'rw-info2'), tb = el('tbody');
    pasang.forEach(function (p) { var tr = el('tr'); tr.append(el('th', '', p[0]), el('td', '', p[1] || '-')); tb.append(tr); });
    t.append(tb); return t;
  }

  /* ---------- form usulan ---------- */
  /** Syarat tampil field; bertingkat: field yang menjadi syarat juga harus tampil (mis. TMT mutasi -> jenis mutasi -> jenis). */
  function cocokTampil(t, form) {
    var w = $('.rw-f[data-k="' + t[0] + '"]', form);
    if (w && w.hidden && w.dataset.tampil) return false;
    var dep = $('[name="f[' + t[0] + ']"]', form);
    var v = dep ? dep.value : '';
    return Array.isArray(t[1]) ? t[1].indexOf(v) >= 0 : v === t[1];
  }

  function kontrol(f, nilai, data) {
    var wrap = el('div', 'rw-f' + (f.lebar === 'penuh' || f.t === 'textarea' ? ' rw-f--penuh' : ''));
    wrap.dataset.k = f.k;
    var id = 'rwf-' + f.k, inp;
    if (f.t === 'tetap') {
      inp = el('input'); inp.type = 'hidden'; inp.name = 'f[' + f.k + ']'; inp.value = f.nilai;
      wrap.hidden = true; wrap.append(inp); return wrap;
    }
    if (f.t === 'judul') { wrap.className = 'rw-form__sec'; wrap.textContent = f.l; return wrap; }
    if (f.t === 'struk') {        // jabatan struktural mengikuti unit organisasi (hanya-baca)
      var lbS = el('label', '', f.l); lbS.htmlFor = id;
      inp = el('input'); inp.type = 'text'; inp.id = id; inp.readOnly = true; inp.className = 'rw-baca'; inp.value = data.jabatan_nama || '';
      inp.placeholder = 'Pilih unit organisasi lebih dulu';
      wrap.append(lbS, inp);
      if (f.bantu) wrap.append(el('small', '', f.bantu));
      return wrap;
    }
    if (f.t === 'dasar_mu') {     // riwayat dasar Mutasi Unor (diisi bukaForm)
      wrap.className += ' rw-dasar';
      wrap.append(el('div', 'rw-form__sec', f.l), el('div', 'rw-dasar__isi'));
      return wrap;
    }
    if (f.t === 'check') {
      wrap.className += ' rw-check';
      inp = el('input'); inp.type = 'checkbox'; inp.value = '1'; inp.id = id; inp.name = 'f[' + f.k + ']';
      inp.checked = String(nilai) === '1';
      var lb = el('label', '', f.l); lb.htmlFor = id;
      wrap.append(inp, lb);
      return wrap;
    }
    var label = el('label', '', f.l); label.htmlFor = id;
    if (f.w) { label.append(document.createTextNode(' ')); label.append(el('span', 'rw-wajib', '*')); }
    wrap.append(label);
    if (f.t === 'select') {
      inp = el('select'); inp.id = id; inp.name = 'f[' + f.k + ']';
      var kosong = el('option', '', f.kosong || '— Pilih —'); kosong.value = ''; inp.append(kosong);
      inp._s2 = { placeholder: f.kosong || '— Pilih —' };
      var namaInp = null;
      if (f.nama) { namaInp = el('input'); namaInp.type = 'hidden'; namaInp.name = 'f[' + f.nama + ']'; wrap.append(namaInp); }
      var isi = function (opsi) {
        opsi.forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0]; inp.append(op); });
        var v = nilai === undefined || nilai === null ? '' : String(nilai);
        if (v !== '') {
          inp.value = v;
          if (inp.value !== v) { var op = el('option', '', (f.nama && data[f.nama]) || v); op.value = v; inp.append(op); inp.value = v; }
        }
        if (namaInp) namaInp.value = inp.value ? inp.options[inp.selectedIndex].text : '';
      };
      if (f.ref) {
        inp.disabled = true; kosong.textContent = 'Memuat…';
        ambilRef(f.ref).then(function (list) {
          kosong.textContent = '— Pilih —'; inp.disabled = !!inp._kunci;
          isi(list.map(function (x) { return [x.id, x.nama]; }));
          inp.dispatchEvent(new Event('change', { bubbles: true }));
          if (inp._s2aktif) pasangSelect2(inp, inp._s2);
        }).catch(function () { kosong.textContent = 'Referensi gagal dimuat'; inp.disabled = false; isi([]); });
      } else isi(f.o || []);
      inp.addEventListener('change', function () { if (namaInp) namaInp.value = inp.value ? inp.options[inp.selectedIndex].text : ''; });
    } else if (f.t === 'search') {
      // referensi besar (unit, jabatan, jurusan): Select2 dengan pencarian AJAX
      inp = el('select'); inp.id = id; inp.name = 'f[' + f.k + ']';
      var o0 = el('option', '', ''); o0.value = ''; inp.append(o0);
      var hn = el('input'); hn.type = 'hidden'; hn.name = 'f[' + f.nama + ']'; hn.value = data[f.nama] || '';
      if (nilai) { var oc = el('option', '', data[f.nama] || String(nilai)); oc.value = String(nilai); inp.append(oc); inp.value = String(nilai); }
      wrap.append(hn);
      inp.addEventListener('change', function () { hn.value = inp.value ? inp.options[inp.selectedIndex].text : ''; });
      inp._s2 = {
        placeholder: 'Ketik minimal 2 huruf…',
        ajax: function (q) {
          var p = { aksi: 'ref', nama: f.ref, q: q };
          if (f.induk) p.tingkat = (($('[name="f[' + f.induk + ']"]', wrap.closest('form')) || {}).value || '');
          return p;
        }
      };
    } else if (f.t === 'textarea') {
      inp = el('textarea'); inp.id = id; inp.name = 'f[' + f.k + ']'; inp.value = nilai || '';
      if (f.max) inp.maxLength = f.max;
    } else {
      inp = el('input'); inp.id = id; inp.name = 'f[' + f.k + ']';
      var tipe = { date: 'date', year: 'number', number: 'number', int: 'number', decimal: 'text', email: 'email', tel: 'tel', nik: 'text' }[f.t] || 'text';
      inp.type = tipe;
      if (f.t === 'year') { inp.min = f.min || 1940; inp.max = new Date().getFullYear() + 1; inp.step = 1; inp.inputMode = 'numeric'; }
      if (f.t === 'number' || f.t === 'int') { if (f.min !== undefined) inp.min = f.min; if (f.max !== undefined) inp.max = f.max; inp.step = 1; inp.inputMode = 'numeric'; }
      if (f.t === 'decimal') inp.inputMode = 'decimal';
      if (f.t === 'nik') { inp.inputMode = 'numeric'; inp.maxLength = 16; inp.pattern = '\\d{16}'; }
      if (f.max && tipe === 'text') inp.maxLength = f.max;
      inp.value = nilai === undefined || nilai === null ? '' : String(nilai);
      if (tipe === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(inp.value)) inp.value = '';
    }
    wrap.append(inp);
    if (f.bantu) wrap.append(el('small', '', f.bantu));
    return wrap;
  }

  /**
   * Satu blok dokumen di form: pilih PDF atau pindai dengan kamera, pratinjau berkas baru (pdf.js),
   * dan pratinjau dokumen saat ini (riwayat / usulan) yang bisa dibuka-tutup.
   */
  function kontrolDok(d, aksi, row, u, berkas) {
    var kol = d[0];
    var wrap = el('div', 'rw-f rw-f--penuh rw-dokf');
    wrap.dataset.k = 'dok_' + kol;
    var ada = null;
    if (u && u.dok.indexOf(kol) >= 0) ada = { url: urlUsulan(u, kol), ket: 'Dokumen usulan' };
    else if (row && (row.dok || []).some(function (x) { return x[1] === kolomRiwayat(row.jenis, kol); })) ada = { url: urlRiwayat(row, kolomRiwayat(row.jenis, kol)), ket: 'Dokumen saat ini' };
    var wajib = d[2] && aksi === 'tambah' && !ada;
    var lb = el('label', 'rw-dokf__judul', d[1]);
    if (wajib) { lb.append(document.createTextNode(' ')); lb.append(el('span', 'rw-wajib', '*')); }
    wrap.append(lb);
    var pilih = el('div', 'rw-dokf__pilih');
    var inp = el('input'); inp.type = 'file'; inp.accept = 'application/pdf,.pdf'; inp.className = 'rw-sembunyi'; inp.id = 'rwd-' + kol;
    var bPilih = el('label', 'rw-btn'); bPilih.htmlFor = inp.id; bPilih.append(ikon('bi-upload'), el('span', '', 'Pilih PDF'));
    bPilih.tabIndex = 0;
    bPilih.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } });
    var bScan = tombol('Pindai dokumen', '', 'bi-camera', function () { bukaScan(d[1], function (file) { pasang(file); }); });
    var status = el('span', 'rw-dokf__status', ada ? 'Kosongkan bila dokumen lama tidak diganti' : 'PDF, maksimal 1,5 MB');
    pilih.append(inp, bPilih, bScan, status);
    wrap.append(pilih);
    var pratinjau = el('div', 'rw-dokf__baru');
    wrap.append(pratinjau);
    var viewer = null;
    function hapusErr() { wrap.classList.remove('is-error'); $$('.rw-f__err', wrap).forEach(function (x) { x.remove(); }); }
    function pasang(file) {
      hapusErr();
      var salah = !/\.pdf$/i.test(file.name) && file.type !== 'application/pdf' ? 'Berkas harus PDF.' : (file.size > PDF_MAKS ? 'Ukuran PDF ' + ukuran(file.size) + ', maksimal 1,5 MB.' : '');
      if (salah) { wrap.classList.add('is-error'); wrap.append(el('small', 'rw-f__err', salah)); return; }
      file.arrayBuffer().then(function (buf) {
        var head = new Uint8Array(buf.slice(0, 5));
        if (String.fromCharCode.apply(null, head) !== '%PDF-') { wrap.classList.add('is-error'); wrap.append(el('small', 'rw-f__err', 'Isi berkas bukan PDF yang valid.')); return; }
        berkas[kol] = file;
        status.textContent = file.name + ' · ' + ukuran(file.size);
        if (viewer) viewer.hancur();
        viewer = Viewer({ data: buf }, 'Pratinjau: ' + file.name);
        var batal = tombol('Batalkan berkas ini', 'rw-btn--kecil', 'bi-x', function () {
          delete berkas[kol]; inp.value = ''; if (viewer) viewer.hancur(); viewer = null; pratinjau.replaceChildren();
          status.textContent = ada ? 'Kosongkan bila dokumen lama tidak diganti' : 'PDF, maksimal 1,5 MB';
        });
        pratinjau.replaceChildren(viewer.el, batal);
        viewer.muat();
      });
    }
    inp.addEventListener('change', function () { if (inp.files && inp.files[0]) pasang(inp.files[0]); });
    if (ada) {
      var akr = el('div', 'rw-akordeon');
      var b = el('button', 'rw-akordeon__btn'); b.type = 'button'; b.setAttribute('aria-expanded', 'false');
      b.append(ikon('bi-file-earmark-pdf-fill'), el('span', '', ada.ket + ': ' + d[1]), ikon('bi-chevron-down rw-akordeon__panah'));
      var isi = el('div', 'rw-akordeon__isi'); isi.hidden = true;
      var v = null;
      b.addEventListener('click', function () {
        var buka = isi.hidden; isi.hidden = !buka; b.setAttribute('aria-expanded', buka ? 'true' : 'false');
        if (buka && !v) { v = Viewer({ url: ada.url }, d[1]); isi.append(v.el); v.muat(); }
      });
      akr.append(b, isi);
      wrap.append(akr);
    }
    if (wajib) wrap.dataset.wajib = '1';
    return wrap;
  }

  function tandaiError(form, field, pesan) {
    var w = field && $('[data-k="' + String(field).replace(/^f\[|\]$/g, '').replace(/"/g, '') + '"]', form);
    if (!w) return false;
    w.classList.add('is-error');
    $$('.rw-f__err', w).forEach(function (x) { x.remove(); });
    w.append(el('small', 'rw-f__err', pesan));
    var i = $('input:not([type=hidden]),select,textarea', w); if (i && i.focus) i.focus();
    w.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return true;
  }

  /* ---------- aturan form per jenis (mengikuti SIMPEG) ---------- */
  var STRUK = {};
  /** Jabatan struktural milik unit organisasi (untuk jenis struktural & tugas tambahan). */
  function aturStruk(form) {
    var box = $('#rwf-_struk', form), unor = $('[name="f[unor_id]"]', form);
    if (!box || !unor || box.closest('.rw-f').hidden) return;
    var id = unor.value;
    if (!id) { box.value = ''; box.placeholder = 'Pilih unit organisasi lebih dulu'; return; }
    var tulis = function (x) { box.value = x ? x.nama : ''; box.placeholder = x ? '' : 'Unit ini tidak memiliki jabatan struktural'; };
    if (STRUK[id] !== undefined) { tulis(STRUK[id]); return; }
    box.value = ''; box.placeholder = 'Memuat…';
    get({ aksi: 'ref', nama: 'struktural', unor: id }).then(function (j) { STRUK[id] = (j.data || [])[0] || null; tulis(STRUK[id]); })
      .catch(function () { box.placeholder = 'Jabatan struktural tidak dapat dimuat'; });
  }
  /**
   * Riwayat dasar Mutasi Unor (aturan SIMPEG): riwayat paling akhir (penugasan definitif); bila struktural -> MU ditolak
   * (buat MJ dulu); selain itu Mutasi Jabatan (MJ) terbaru, atau riwayat paling akhir bila belum ada MJ.
   */
  function dasarMutasiUnor(kecuali) {
    var cocok = BARIS.filter(function (r) {
      var a = r.awal || {};
      return r.jenis === 'jabatan' && r.id_rw && r.id_rw !== kecuali && (!a.jenis_penugasan || a.jenis_penugasan === 'D');
    });
    if (!cocok.length) return { alasan: 'Belum ada riwayat jabatan yang dapat menjadi dasar Mutasi Unor.' };
    var efektif = function (r) { return [r.awal.tmt_mutasi || '', r.awal.tmt_jabatan || ''].sort().pop(); };
    var akhir = cocok.slice().sort(function (a, b) { return efektif(b).localeCompare(efektif(a)); })[0];
    if (akhir.awal.jenis === '1') return { alasan: 'Riwayat jabatan terakhir adalah Struktural. Ajukan riwayat Mutasi Jabatan (MJ) terlebih dahulu sebelum Mutasi Unor (MU).' };
    var mj = cocok.filter(function (r) { return r.awal.jenis_mutasi === 'MJ'; })
      .sort(function (a, b) { return (b.awal.tmt_jabatan || '').localeCompare(a.awal.tmt_jabatan || ''); })[0];
    return { dasar: mj || akhir };
  }
  function aturMutasiUnor(form, ctx) {
    var mu = $('.rw-f[data-k="_dasar_mu"]', form);
    var tglSk = $('[name="f[tgl_sk]"]', form), tmt = $('[name="f[tmt_jabatan]"]', form), jm = $('[name="f[jenis_mutasi]"]', form);
    if (!mu || !tglSk || !tmt) return;
    if (mu.hidden) {
      tglSk.readOnly = tmt.readOnly = false;
      ctx.dasarMu = null; ctx.kunciMu = null;
      return;
    }
    var h = dasarMutasiUnor(ctx.ref);
    var isi = $('.rw-dasar__isi', mu);
    if (!h.dasar) {
      jm.value = 'MJ'; segarS2(jm);
      toast(h.alasan, false);
      ctx.segar();
      return;
    }
    var d = h.dasar;
    ctx.dasarMu = d;
    tglSk.value = d.awal.tgl_sk || ''; tmt.value = d.awal.tmt_jabatan || '';
    tglSk.readOnly = tmt.readOnly = true;           // mengikuti riwayat dasar
    if (ctx.kunciMu === d.id_rw) return;
    ctx.kunciMu = d.id_rw;
    isi.replaceChildren();
    var inf = info('', 'biru', 'bi-diagram-3');
    var t = $('span', inf);
    t.append(el('b', '', 'Riwayat dasar MU' + (d.awal.jenis_mutasi === 'MJ' ? ' (Mutasi Jabatan terakhir)' : ' (riwayat terakhir)') + ': '),
      document.createTextNode(d.judul + ' · SK ' + (d.awal.no_sk || '-') + ' tgl ' + (tgl(d.awal.tgl_sk) || '-') + ' · TMT jabatan ' + (tgl(d.awal.tmt_jabatan) || '-')),
      el('div', '', 'Tanggal SK & TMT jabatan mengikuti riwayat dasar. Bila tidak mengunggah SK baru, SK riwayat dasar dipakai.'));
    isi.append(inf);
    var sk = (d.dok || []).filter(function (x) { return x[1] === 'sk'; })[0];
    isi.append(blokDokumen(sk ? [{ label: 'SK jabatan dasar MU', sumber: { url: urlRiwayat(d, 'sk') } }] : [], 0, 'Riwayat dasar MU belum memiliki dokumen SK jabatan di repository.'));
  }
  /** Pendidikan pertama hanya satu: pemiliknya selain riwayat/usulan yang sedang diedit. */
  function pemilikPertama(ref, usulanId) {
    var r = BARIS.filter(function (x) { return x.jenis === 'pendidikan' && x.id_rw !== ref && String((x.awal || {}).pertama) === '1'; })[0];
    if (r) return r.judul + (r.waktu ? ' (' + r.waktu + ')' : '');
    var u = USULAN.filter(function (x) { return x.jenis === 'pendidikan' && x.id !== usulanId && bisaUbahUsulan(x) && x.aksi !== 'hapus' && String((x.data || {}).pertama) === '1'; })[0];
    return u ? 'usulan ' + u.aksi + ': ' + [u.data.tk_pendidikan_nama, u.data.nama_sekolah, u.data.tahun_lulus].filter(Boolean).join(' · ') : '';
  }

  function bukaForm(jenis, aksi, row, u) {
    var def = FORM[jenis];
    if (!def) { toast('Riwayat ini hanya dapat dilihat.', false); return; }
    if (jenis === 'pasangan' && aksi === 'tambah' && CFG.lajang) { toast('Status perkawinan Anda di Identitas "Belum Menikah". Perbarui status perkawinan lebih dulu.', false); return; }
    var data = u ? (u.data || {}) : (row ? (row.awal || {}) : {});
    var lama = u ? (u.data_lama || {}) : (row ? (row.awal || {}) : {});
    var kunci = !!def.kunci_ubah && (u ? u.aksi : aksi) === 'ubah';
    var berkas = {};
    var dua = !HP.matches;                    // desktop: popup lebar, isian kiri & dokumen kanan (seperti SIMPEG)
    var form = el('form', dua ? 'rw-dua rw-dua--form' : 'rw-form'); form.noValidate = true;
    var kiri = dua ? el('div', 'rw-form rw-dua__kiri') : form, kanan = dua ? el('div', 'rw-form rw-dua__kanan') : form;
    if (dua) form.append(kiri, kanan);
    var atas = el('div', 'rw-f--penuh');
    if (u && u.status === 'dikembalikan' && u.catatan) atas.append(info('Catatan verifikator: ' + u.catatan, 'biru', 'bi-chat-left-text'));
    if (aksi === 'ubah' && row) atas.append(info('Perubahan diajukan sebagai usulan untuk: ' + row.judul + '. Data riwayat tidak berubah sebelum disetujui admin.', 'kuning', 'bi-info-circle'));
    if (def.catatan) atas.append(info(def.catatan, kunci ? 'kuning' : 'biru', kunci ? 'bi-lock' : 'bi-info-circle'));
    var galat = el('div', 'rw-info rw-info--merah'); galat.hidden = true; atas.append(galat);
    kiri.append(atas);
    if (def.field[0] && def.field[0].t !== 'judul') kiri.append(el('div', 'rw-form__sec', 'Data'));
    def.field.forEach(function (f) {
      var w = kontrol(f, data[f.k], data);
      if (f.tampil) w.dataset.tampil = JSON.stringify(f.tampil);
      kiri.append(w);
    });
    if (kunci) {               // data dikunci: hanya dokumen yang dapat diperbarui
      $$('.rw-f input[name^="f["], .rw-f select, .rw-f textarea', form).forEach(function (i) {
        if (i.tagName === 'SELECT') { i.disabled = true; i._kunci = true; } else i.readOnly = true;
        i.closest('.rw-f').classList.add('is-kunci');
      });
    }
    kanan.append(el('div', 'rw-form__sec', 'Dokumen'));
    if (dua) kanan.append(el('p', 'rw-muted rw-f--penuh', 'PDF maksimal 1,5 MB. Dokumen baru menggantikan dokumen lama setelah usulan disetujui.'));
    def.dok.forEach(function (d) {
      var w = kontrolDok(d, u ? u.aksi : aksi, row, u, berkas);
      if (d[3]) w.dataset.tampil = JSON.stringify(d[3]);
      kanan.append(w);
    });
    var ket = el('div', 'rw-f rw-f--penuh'); ket.dataset.k = 'alasan';
    var lk = el('label', '', 'Keterangan untuk verifikator (opsional)'); lk.htmlFor = 'rwf-alasan';
    var ta = el('textarea'); ta.id = 'rwf-alasan'; ta.name = 'alasan'; ta.maxLength = 1000; ta.value = u ? (u.alasan || '') : '';
    ket.append(lk, ta); kanan.append(ket);

    // CPNS/PNS: sudah PNS -> tidak dapat kembali ke CPNS
    if (jenis === 'cpns' && String(lama.status || '').toUpperCase() === 'PNS') {
      var st = $('[name="f[status]"]', form), oC = st && $('option[value="CPNS"]', st);
      if (oC) { oC.disabled = true; if (st.value === 'CPNS' || !st.value) st.value = 'PNS'; }
      var wS = st && st.closest('.rw-f');
      if (wS) wS.append(el('small', 'rw-f__ket', '🔒 Sudah PNS — status tidak dapat dikembalikan ke CPNS.'));
    }
    // Pendidikan pertama: dikunci bila sudah dimiliki riwayat/usulan lain
    if (jenis === 'pendidikan') {
      var cb = $('[name="f[pertama]"]', form);
      if (cb) {
        var pem = pemilikPertama(row ? row.id_rw : (u ? u.ref_id : ''), u ? u.id : '');
        var ketP = el('small', 'rw-f__ket rw-f--penuh');
        if (pem) { cb.checked = false; cb.disabled = true; ketP.textContent = 'Pendidikan pertama sudah ditetapkan pada ' + pem + '.'; }
        else ketP.textContent = cb.checked ? 'Riwayat ini adalah pendidikan pertama.' : 'Belum ada pendidikan pertama.';
        cb.closest('.rw-f').after(ketP);
        cb.addEventListener('change', function () { ketP.textContent = cb.checked ? 'Riwayat ini adalah pendidikan pertama.' : 'Belum ada pendidikan pertama.'; });
      }
    }

    var ctx = { ref: row ? row.id_rw : (u ? u.ref_id : ''), dasarMu: null, kunciMu: null };
    var segarTampil = ctx.segar = function () {
      $$('[data-tampil]', form).forEach(function (w) { w.hidden = !cocokTampil(JSON.parse(w.dataset.tampil), form); });
      if (jenis === 'jabatan' || jenis === 'tugas') { aturStruk(form); if (jenis === 'jabatan') aturMutasiUnor(form, ctx); }
    };
    form.addEventListener('change', function (e) { if (e.target.name && e.target.name.indexOf('f[') === 0) segarTampil(); });
    form.addEventListener('input', function (e) { var w = e.target.closest('.rw-f'); if (w) { w.classList.remove('is-error'); $$('.rw-f__err', w).forEach(function (x) { x.remove(); }); } });
    setTimeout(segarTampil, 0);

    var kirim = tombol(u ? (u.status === 'dikembalikan' ? 'Simpan & ajukan ulang' : 'Simpan perubahan') : 'Ajukan usulan', 'rw-btn--primary', 'bi-send');
    var tampilW = function (w) { return w && !w.hidden && !(w.closest('[data-tampil]') && w.closest('[data-tampil]').hidden); };
    var jalankan = function (e) {
      if (e) e.preventDefault();
      galat.hidden = true;
      var pertama = null;
      def.field.forEach(function (f) {
        var w = $('.rw-f[data-k="' + f.k + '"]', form);
        if (!f.w || !tampilW(w)) return;
        var i = $('[name="f[' + f.k + ']"]', w);
        if (i && String(i.value || '').trim() === '') { if (!pertama) pertama = f.k; tandaiError(form, f.k, f.t === 'search' ? 'Pilih dari daftar pencarian.' : 'Wajib diisi.'); }
      });
      $$('[data-wajib="1"]', form).forEach(function (w) {
        var kol = w.dataset.k.replace(/^dok_/, '');
        if (tampilW(w) && !berkas[kol]) { if (!pertama) pertama = w.dataset.k; tandaiError(form, w.dataset.k, 'Dokumen wajib diunggah atau dipindai.'); }
      });
      if (jenis === 'jabatan' && aksi === 'tambah' && !u && !ctx.dasarMu && !berkas.sk && !berkas.sk_mutasi) {
        if (!pertama) pertama = 'dok_sk';
        tandaiError(form, 'dok_sk', 'SK jabatan (atau SK mutasi) wajib diunggah.');
      }
      if (pertama) { tandaiError(form, pertama, ($('[data-k="' + pertama + '"] .rw-f__err', form) || {}).textContent || 'Wajib diisi.'); return; }
      var fd = fdBaru('simpan');
      // hanya field yang tampil (field bersyarat yang tersembunyi tidak dikirim)
      $$('input[name],select[name],textarea[name]', form).forEach(function (i) {
        var w = i.closest('[data-tampil]');
        if (w && w.hidden) return;
        if (i.type === 'checkbox') fd.append(i.name, i.checked ? '1' : '0');
        else if (i.type !== 'file') fd.append(i.name, i.value);
      });
      Object.keys(berkas).forEach(function (kol) {
        if (tampilW($('.rw-f[data-k="dok_' + kol + '"]', form))) fd.append('dok_' + kol, berkas[kol], berkas[kol].name);
      });
      fd.set('jenis', jenis);
      if (u) fd.set('usulan_id', u.id);
      else { fd.set('aksi_usul', aksi); if (row) fd.set('ref_id', row.id_rw); }
      sibuk(true, kirim, 'Mengirim…');
      post(fd).then(function (j) {
        modalSibuk = false;
        toast(j.message || 'Usulan tersimpan.', true);
        setTimeout(function () { muatUlangUsulan(jenis); }, 700);
      }).catch(function (err) {
        sibuk(false, kirim);
        if (!tandaiError(form, err.field, err.message)) { galat.replaceChildren(ikon('bi-exclamation-triangle'), el('span', '', err.message)); galat.hidden = false; galat.scrollIntoView({ block: 'nearest' }); }
      });
    };
    kirim.addEventListener('click', jalankan);
    form.addEventListener('submit', jalankan);
    var lbl = (CFG.jenisLabel[jenis] || jenis).toLowerCase();
    var judul = u ? 'Perbaiki usulan ' + lbl : (aksi === 'tambah' ? 'Tambah ' : 'Edit ') + lbl;
    bukaModal(judul.charAt(0).toUpperCase() + judul.slice(1), form, [tombol('Batal', '', null, tutupModal), kirim], dua ? { kelas: 'rw-modal--besar' } : { drawer: true, lebar: true });
    pasangSemuaSelect(form);
  }

  function bukaHapus(row, u) {
    var box = el('form', 'rw-form'); box.noValidate = true;
    var a = info('Usulan hapus untuk riwayat: ' + (row ? row.judul : 'riwayat terpilih') + '. Riwayat baru dihapus setelah disetujui admin.', 'merah', 'bi-trash3');
    a.classList.add('rw-f--penuh'); box.append(a);
    if (u && u.status === 'dikembalikan' && u.catatan) { var c = info('Catatan verifikator: ' + u.catatan, 'biru', 'bi-chat-left-text'); c.classList.add('rw-f--penuh'); box.append(c); }
    var w = el('div', 'rw-f rw-f--penuh'); w.dataset.k = 'alasan';
    var lb = el('label', '', 'Alasan penghapusan '); lb.append(el('span', 'rw-wajib', '*')); lb.htmlFor = 'rwf-alasan';
    var ta = el('textarea'); ta.id = 'rwf-alasan'; ta.name = 'alasan'; ta.maxLength = 1000; ta.value = u ? (u.alasan || '') : '';
    ta.placeholder = 'Mis. data ganda / salah input';
    w.append(lb, ta, el('small', '', 'Minimal 5 karakter.'));
    box.append(w);
    var kirim = tombol(u ? 'Simpan' : 'Ajukan usulan hapus', 'rw-btn--merah', 'bi-send');
    var jalan = function (e) {
      if (e) e.preventDefault();
      if (ta.value.trim().length < 5) { tandaiError(box, 'alasan', 'Alasan minimal 5 karakter.'); return; }
      var fd = fdBaru('simpan');
      fd.set('jenis', u ? u.jenis : row.jenis); fd.set('alasan', ta.value.trim());
      if (u) fd.set('usulan_id', u.id); else { fd.set('aksi_usul', 'hapus'); fd.set('ref_id', row.id_rw); }
      sibuk(true, kirim, 'Mengirim…');
      post(fd).then(function (j) { modalSibuk = false; toast(j.message, true); setTimeout(function () { muatUlangUsulan(u ? u.jenis : row.jenis); }, 700); })
        .catch(function (err) { sibuk(false, kirim); tandaiError(box, 'alasan', err.message); });
    };
    kirim.addEventListener('click', jalan); box.addEventListener('submit', jalan);
    bukaModal(u ? 'Perbaiki usulan hapus' : 'Hapus riwayat', box, [tombol('Batal', '', null, tutupModal), kirim]);
  }

  function pilihTambah() {
    var bisa = (CFG.jenis || []).filter(function (j) { return (IZIN[j] || {}).tambah; });
    if (bisa.length === 1) { bukaForm(bisa[0], 'tambah', null, null); return; }
    var box = el('div', 'rw-ulist');
    bisa.forEach(function (j) {
      var b = el('button', 'rw-uitem'); b.type = 'button';
      var ic = el('span', 'rw-uitem__aksi rw-uitem__aksi--tambah'); ic.append(ikon('bi-plus-lg'));
      var t = el('span'); t.append(el('strong', '', CFG.jenisLabel[j] || j), el('small', '', 'Ajukan riwayat baru'));
      b.append(ic, t);
      b.addEventListener('click', function () { bukaForm(j, 'tambah', null, null); });
      box.append(b);
    });
    bukaModal('Tambah riwayat', box, [tombol('Tutup', '', null, tutupModal)]);
  }

  /* ---------- pemindai dokumen: kamera / foto -> PDF ---------- */
  var scan = { halaman: [], stream: null, selesai: null, mode: true };
  function tutupScan() {
    if (scan.stream) { scan.stream.getTracks().forEach(function (t) { t.stop(); }); scan.stream = null; }
    scanEl.hidden = true;
    $('#rw-scan-body').replaceChildren();
    kunciBody();
  }
  function muatGambar(file) {
    return new Promise(function (ok, gagal) {
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () { URL.revokeObjectURL(url); ok(img); };
      img.onerror = function () { URL.revokeObjectURL(url); gagal(new Error('Gambar tidak dapat dibaca')); };
      img.src = url;
    });
  }
  /** Gambar halaman (sudah diputar & diproses) pada kanvas dengan sisi terpanjang `maks` piksel. */
  function kanvasHalaman(h, maks, mode) {
    var sumber = h.img, w = sumber.naturalWidth || sumber.width, t = sumber.naturalHeight || sumber.height;
    var sk = Math.min(1, maks / Math.max(w, t));
    var putar = ((h.putar % 360) + 360) % 360, tegak = putar === 90 || putar === 270;
    var c = document.createElement('canvas');
    c.width = Math.round((tegak ? t : w) * sk); c.height = Math.round((tegak ? w : t) * sk);
    var x = c.getContext('2d');
    x.translate(c.width / 2, c.height / 2); x.rotate(putar * Math.PI / 180);
    x.drawImage(sumber, -w * sk / 2, -t * sk / 2, w * sk, t * sk);
    if (mode) {
      // Mode dokumen: abu-abu + regangkan kontras (persentil 2%–90%) agar kertas putih & tulisan tegas.
      var id = x.getImageData(0, 0, c.width, c.height), p = id.data, hist = new Uint32Array(256), n = p.length / 4;
      for (var i = 0; i < p.length; i += 4) { var l = (p[i] * 299 + p[i + 1] * 587 + p[i + 2] * 114) / 1000 | 0; p[i] = l; hist[l]++; }
      var lo = 0, hi = 255, s = 0;
      for (var a = 0; a < 256; a++) { s += hist[a]; if (s > n * 0.02) { lo = a; break; } }
      s = 0;
      for (var b = 0; b < 256; b++) { s += hist[b]; if (s > n * 0.90) { hi = b; break; } }
      var rentang = Math.max(1, hi - lo);
      for (var j = 0; j < p.length; j += 4) {
        var v = Math.max(0, Math.min(255, (p[j] - lo) * 255 / rentang));
        v = v > 200 ? 255 : v;
        p[j] = p[j + 1] = p[j + 2] = v;
      }
      x.putImageData(id, 0, 0);
    }
    return c;
  }
  function renderScan() {
    var daftarEl = $('#rw-scan-daftar'), simpan = $('#rw-scan-simpan');
    if (!daftarEl) return;
    daftarEl.replaceChildren();
    scan.halaman.forEach(function (h, i) {
      var item = el('figure', 'rw-scan__hal');
      var c = kanvasHalaman(h, 360, scan.mode);
      c.setAttribute('aria-label', 'Halaman ' + (i + 1));
      var cap = el('figcaption');
      var mk = function (ik, lbl, fn, mati) { var b = el('button', 'rw-ico'); b.type = 'button'; b.title = lbl; b.setAttribute('aria-label', lbl); b.append(ikon(ik)); b.disabled = !!mati; b.addEventListener('click', fn); cap.append(b); };
      cap.append(el('b', '', String(i + 1)));
      mk('bi-arrow-left', 'Geser ke kiri', function () { scan.halaman.splice(i - 1, 0, scan.halaman.splice(i, 1)[0]); renderScan(); }, i === 0);
      mk('bi-arrow-clockwise', 'Putar', function () { h.putar += 90; renderScan(); });
      mk('bi-arrow-right', 'Geser ke kanan', function () { scan.halaman.splice(i + 1, 0, scan.halaman.splice(i, 1)[0]); renderScan(); }, i === scan.halaman.length - 1);
      mk('bi-trash3', 'Hapus halaman', function () { scan.halaman.splice(i, 1); renderScan(); });
      item.append(c, cap);
      daftarEl.append(item);
    });
    if (!scan.halaman.length) daftarEl.append(el('p', 'rw-muted', 'Belum ada halaman. Jepret dengan kamera atau ambil foto dokumen.'));
    if (simpan) { simpan.disabled = !scan.halaman.length; $('span', simpan).textContent = scan.halaman.length ? 'Jadikan PDF (' + scan.halaman.length + ' hal.)' : 'Jadikan PDF'; }
  }
  function tambahFile(files) {
    Array.prototype.slice.call(files || []).forEach(function (f) {
      if (!/^image\//.test(f.type)) { toast('Berkas ' + f.name + ' bukan gambar.', false); return; }
      muatGambar(f).then(function (img) { scan.halaman.push({ img: img, putar: 0 }); renderScan(); }).catch(function (e) { toast(e.message, false); });
    });
  }
  function bukaKamera(video, tombolJepret, pesanEl) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.isSecureContext) {
      pesanEl.textContent = 'Kamera langsung tidak tersedia di peramban ini. Gunakan "Ambil foto".';
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false })
      .then(function (st) {
        if (scanEl.hidden) { st.getTracks().forEach(function (t) { t.stop(); }); return; }
        scan.stream = st; video.srcObject = st; video.hidden = false; tombolJepret.disabled = false; pesanEl.textContent = '';
        video.play().catch(function () { /* autoplay ditolak: pengguna bisa mengetuk video */ });
      })
      .catch(function () { pesanEl.textContent = 'Izin kamera ditolak atau kamera tidak ditemukan. Gunakan "Ambil foto".'; });
  }
  function bukaScan(label, selesai) {
    scan.halaman = []; scan.selesai = selesai;
    $('#rw-scan-title').textContent = 'Pindai ' + label;
    var body = el('div', 'rw-scan');
    var kamera = el('div', 'rw-scan__kamera');
    var video = el('video'); video.setAttribute('playsinline', ''); video.muted = true; video.hidden = true;
    var pesan = el('p', 'rw-scan__pesan', 'Menyalakan kamera…');
    var bingkai = el('div', 'rw-scan__bingkai');
    kamera.append(video, bingkai, pesan);
    var alat = el('div', 'rw-scan__alat');
    var jepret = tombol('Jepret', 'rw-btn--primary rw-scan__jepret', 'bi-camera-fill', function () {
      if (!video.videoWidth) return;
      var c = document.createElement('canvas'); c.width = video.videoWidth; c.height = video.videoHeight;
      c.getContext('2d').drawImage(video, 0, 0);
      scan.halaman.push({ img: c, putar: 0 }); renderScan();
      kamera.classList.add('is-kilat'); setTimeout(function () { kamera.classList.remove('is-kilat'); }, 180);
    });
    jepret.disabled = true;
    var foto = el('input'); foto.type = 'file'; foto.accept = 'image/*'; foto.multiple = true; foto.setAttribute('capture', 'environment'); foto.className = 'rw-sembunyi'; foto.id = 'rw-scan-foto';
    var galeri = el('input'); galeri.type = 'file'; galeri.accept = 'image/*'; galeri.multiple = true; galeri.className = 'rw-sembunyi'; galeri.id = 'rw-scan-galeri';
    var lf = el('label', 'rw-btn'); lf.htmlFor = foto.id; lf.append(ikon('bi-phone'), el('span', '', 'Ambil foto'));
    var lg = el('label', 'rw-btn'); lg.htmlFor = galeri.id; lg.append(ikon('bi-images'), el('span', '', 'Dari galeri'));
    foto.addEventListener('change', function () { tambahFile(foto.files); foto.value = ''; });
    galeri.addEventListener('change', function () { tambahFile(galeri.files); galeri.value = ''; });
    var mode = el('label', 'rw-scan__mode');
    var cb = el('input'); cb.type = 'checkbox'; cb.checked = scan.mode;
    cb.addEventListener('change', function () { scan.mode = cb.checked; renderScan(); });
    mode.append(cb, el('span', '', 'Mode dokumen (hitam-putih tajam)'));
    alat.append(jepret, lf, lg, foto, galeri, mode);
    var daftarEl = el('div', 'rw-scan__daftar'); daftarEl.id = 'rw-scan-daftar';
    body.append(kamera, alat, el('div', 'rw-sec', 'Halaman'), daftarEl,
      el('p', 'rw-scan__tips', 'Tips: letakkan dokumen di permukaan gelap, pastikan terang dan seluruh tepi kertas masuk bingkai. Halaman diurutkan sesuai nomor.'));
    var simpan = tombol('Jadikan PDF', 'rw-btn--primary', 'bi-file-earmark-pdf');
    simpan.id = 'rw-scan-simpan';
    simpan.addEventListener('click', function () {
      simpan.disabled = true; $('span', simpan).textContent = 'Membuat PDF…';
      buatPdf().then(function (file) {
        var cb2 = scan.selesai;
        tutupScan();
        toast('PDF hasil pindaian siap (' + ukuran(file.size) + ').', true);
        if (cb2) cb2(file);
      }).catch(function (e) { toast(e.message, false); renderScan(); });
    });
    $('#rw-scan-body').replaceChildren(body);
    $('#rw-scan-foot').replaceChildren(tombol('Batal', '', null, tutupScan), simpan);
    scanEl.hidden = false;
    kunciBody();
    renderScan();
    bukaKamera(video, jepret, pesan);
  }
  /** Susun halaman menjadi PDF A4 (jsPDF); kualitas diturunkan bertahap sampai ≤ 1,5 MB. */
  function buatPdf() {
    return muatSkrip(JSPDF_URL, function () { return !!(window.jspdf && window.jspdf.jsPDF); }).catch(function () {
      throw new Error('Pembuat PDF tidak dapat dimuat (periksa koneksi internet).');
    }).then(function () {
      var percobaan = [[1754, 0.72], [1600, 0.6], [1400, 0.5], [1200, 0.42], [1000, 0.36]];
      for (var k = 0; k < percobaan.length; k++) {
        var maks = percobaan[k][0], q = percobaan[k][1];
        var doc = null;
        scan.halaman.forEach(function (h) {
          var c = kanvasHalaman(h, maks, scan.mode);
          var lanskap = c.width > c.height;
          if (!doc) doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4', orientation: lanskap ? 'l' : 'p', compress: true });
          else doc.addPage('a4', lanskap ? 'l' : 'p');
          var pw = doc.internal.pageSize.getWidth(), ph = doc.internal.pageSize.getHeight();
          var r = Math.min(pw / c.width, ph / c.height), w = c.width * r, t = c.height * r;
          doc.addImage(c.toDataURL('image/jpeg', q), 'JPEG', (pw - w) / 2, (ph - t) / 2, w, t, undefined, 'FAST');
        });
        var blob = doc.output('blob');
        if (blob.size <= PDF_MAKS) return new File([blob], 'pindaian-' + Date.now() + '.pdf', { type: 'application/pdf' });
      }
      throw new Error('PDF hasil pindaian masih lebih dari 1,5 MB. Kurangi jumlah halaman.');
    });
  }

  /* ---------- usulan ---------- */
  function namaNilai(jenis, k, v, data) {
    var f = ((FORM[jenis] || {}).field || []).filter(function (x) { return x.k === k; })[0];
    if (v === '' || v === null || v === undefined) return '-';
    if (!f) return String(v);
    if (f.t === 'check') return String(v) === '1' ? 'Ya' : 'Tidak';
    if (f.nama && data && data[f.nama]) return data[f.nama];
    if (f.o) { var o = f.o.filter(function (x) { return x[0] === String(v); })[0]; if (o) return o[1]; }
    if (f.t === 'date') return tgl(String(v));
    return String(v);
  }

  function lihatUsulan(u) {
    var box = el('div', 'rw-detail');
    var st = STATUS[u.status] || [u.status, 'abu', 'bi-circle'];
    box.append(info(st[0] + (u.catatan ? ' — Catatan verifikator: ' + u.catatan : (u.status === 'diajukan' ? ' — usulan Anda sedang menunggu verifikasi admin SIMPEG.' : '')), st[1] === 'abu' ? 'biru' : st[1], st[2]));
    var meta = [['Jenis', u.jenis_label], ['Aksi', (AKSI[u.aksi] || [u.aksi])[0]], ['Diajukan', tgl(u.diajukan_at)],
      ['Diverifikasi', u.diverifikasi_at ? tgl(u.diverifikasi_at) : '-'], ['Revisi', u.revisi > 0 ? 'ke-' + u.revisi : '-']];
    if (u.alasan) meta.push([u.aksi === 'hapus' ? 'Alasan penghapusan' : 'Keterangan', u.alasan]);
    box.append(HP.matches ? barisInfo(meta) : tabelInfo(meta));
    var fields = ((FORM[u.jenis] || {}).field || []).filter(function (f) { return f.t !== 'tetap'; });
    var kunci = fields.length ? fields.map(function (f) { return [f.k, f.l]; }) : Object.keys(u.aksi === 'hapus' ? u.data_lama : u.data).map(function (k) { return [k, k]; });
    if (kunci.length) {
      box.append(el('div', 'rw-sec', u.aksi === 'tambah' ? 'Data yang diusulkan' : (u.aksi === 'hapus' ? 'Data yang diusulkan dihapus' : 'Perbandingan data')));
      var tb = el('table', 'rw-banding'), th = el('tr');
      th.append(el('th', '', 'Data'));
      if (u.aksi !== 'tambah') th.append(el('th', '', 'Saat ini'));
      if (u.aksi !== 'hapus') th.append(el('th', '', 'Diusulkan'));
      var thead = el('thead'); thead.append(th); tb.append(thead);
      var tbody = el('tbody');
      kunci.forEach(function (p) {
        var lama = namaNilai(u.jenis, p[0], (u.data_lama || {})[p[0]], u.data_lama), baru = namaNilai(u.jenis, p[0], (u.data || {})[p[0]], u.data);
        if (lama === '-' && baru === '-') return;
        var tr = el('tr'); tr.append(el('td', '', p[1]));
        if (u.aksi !== 'tambah') tr.append(el('td', '', lama));
        if (u.aksi !== 'hapus') tr.append(el('td', '', baru));
        if (u.aksi === 'ubah' && lama !== baru) tr.className = 'is-beda';
        tbody.append(tr);
      });
      tb.append(tbody); box.append(tb);
    }
    // dokumen: unggahan baru di usulan + dokumen riwayat saat ini yang tidak diganti (ubah) / yang akan dihapus (hapus)
    var rRef = u.aksi === 'tambah' ? null : BARIS.filter(function (r) { return r.jenis === u.jenis && r.id_rw && (r.id_rw === u.ref_id || u.jenis === 'cpns'); })[0];
    var docsU = u.aksi === 'hapus' ? [] : (u.dok || []).map(function (k) { return { label: labelDok(u.jenis, k) + (u.aksi === 'ubah' ? ' (baru)' : ''), sumber: { url: urlUsulan(u, k) } }; });
    var ganti = (u.dok || []).map(function (k) { return kolomRiwayat(u.jenis, k); });
    var docsR = rRef ? (rRef.dok || []).filter(function (d) { return u.aksi === 'hapus' || ganti.indexOf(d[1]) < 0; })
      .map(function (d) { return { label: d[0] + (u.aksi === 'ubah' && docsU.length ? ' (saat ini)' : ''), sumber: { url: urlRiwayat(rRef, d[1]) } }; }) : [];
    var dokJudul = el('div', 'rw-sec', u.aksi === 'hapus' ? 'Dokumen riwayat' : (docsU.length || !docsR.length ? 'Dokumen usulan' : 'Dokumen riwayat (tidak diganti)'));
    var dokBlok = blokDokumen(docsU.concat(docsR), 0, 'Tidak ada dokumen.');
    if (HP.matches) box.append(dokJudul, dokBlok);
    if (u.jejak && u.jejak.length) {
      box.append(el('div', 'rw-sec', 'Riwayat verifikasi'));
      var ol = el('ol', 'rw-jejak');
      u.jejak.forEach(function (j) {
        var li = el('li'); li.append(el('b', '', (STATUS[j.status] || [j.status])[0]), document.createTextNode(' '), el('small', '', tgl(j.waktu)));
        if (j.catatan) li.append(el('div', '', j.catatan));
        ol.append(li);
      });
      box.append(ol);
    }
    var kaki = [];
    var iz = IZIN[u.jenis] || {};
    if (bisaUbahUsulan(u)) {
      kaki.push(tombol('Batalkan usulan', 'rw-btn--merah-garis', 'bi-x-circle', function () { batalkan(u); }));
      if (iz[u.aksi]) {
        kaki.push(tombol(u.status === 'dikembalikan' ? 'Perbaiki' : 'Edit usulan', 'rw-btn--primary', 'bi-pencil-square', function () {
          if (u.aksi === 'hapus') bukaHapus(null, u);
          else bukaForm(u.jenis, u.aksi, BARIS.filter(function (r) { return r.jenis === u.jenis && (r.id_rw === u.ref_id || u.jenis === 'cpns'); })[0] || null, u);
        }));
      }
    }
    kaki.push(tombol('Tutup', '', null, tutupModal));
    var judulU = 'Usulan ' + (AKSI[u.aksi] || [u.aksi])[0].toLowerCase() + ' ' + String(u.jenis_label || '').toLowerCase();
    if (HP.matches) { bukaModal(judulU, box, kaki, { drawer: true, fokus: $('[data-tutup]', modalEl) }); return; }
    // desktop: popup di tengah seperti detail riwayat, rincian di kiri & dokumen (tab) di kanan
    box.classList.add('rw-dua');
    var kiri = el('div', 'rw-dua__kiri'), kanan = el('div', 'rw-dua__kanan');
    kiri.append.apply(kiri, Array.prototype.slice.call(box.childNodes));
    kanan.append(dokJudul, dokBlok);
    box.append(kiri, kanan);
    bukaModal(judulU, box, kaki, { kelas: 'rw-modal--besar', fokus: $('[data-tutup]', modalEl) });
  }

  function batalkan(u) {
    var box = el('div');
    box.append(info('Usulan ini akan dibatalkan dan tidak diproses admin. Lanjutkan?', 'kuning', 'bi-exclamation-triangle'));
    var ya = tombol('Ya, batalkan', 'rw-btn--merah', 'bi-x-circle');
    ya.addEventListener('click', function () {
      var fd = fdBaru('batal'); fd.set('usulan_id', u.id);
      sibuk(true, ya, 'Membatalkan…');
      post(fd).then(function (j) { modalSibuk = false; toast(j.message, true); setTimeout(function () { muatUlangUsulan(u.jenis); }, 600); })
        .catch(function (err) { sibuk(false, ya); toast(err.message, false); });
    });
    bukaModal('Batalkan usulan', box, [tombol('Kembali', '', null, function () { lihatUsulan(u); }), ya]);
  }

  var panelU = $('#rw-usulan'), tombolU = $('[data-rw="usulan"]'), saringU = 'semua';
  function renderUsulan() {
    var head = el('div', 'rw-usulan__head');
    head.append(el('h3', '', 'Usulan ' + String(CFG.judul || '').toLowerCase()));
    var flt = el('div', 'rw-filter');
    [['semua', 'Semua'], ['proses', 'Diproses'], ['selesai', 'Selesai']].forEach(function (p) {
      var b = el('button', p[0] === saringU ? 'is-aktif' : '', p[1]); b.type = 'button';
      b.addEventListener('click', function () { saringU = p[0]; renderUsulan(); });
      flt.append(b);
    });
    head.append(flt);
    var list = el('div', 'rw-ulist');
    var data = USULAN.filter(function (u) { return saringU === 'semua' || (saringU === 'proses') === bisaUbahUsulan(u); });
    if (CFG.usulanGagal) list.append(el('p', 'rw-muted', 'Daftar usulan belum dapat dimuat dari SIMPEG. Silakan coba lagi.'));
    else if (!data.length) list.append(el('p', 'rw-muted', saringU === 'semua' ? 'Belum ada usulan pada kategori ini.' : 'Tidak ada usulan.'));
    data.forEach(function (u) {
      var b = el('button', 'rw-uitem'); b.type = 'button';
      var ic = el('span', 'rw-uitem__aksi rw-uitem__aksi--' + u.aksi); ic.append(ikon((AKSI[u.aksi] || [0, 'bi-circle'])[1]));
      var t = el('span');
      var ringkas = u.data.nama || u.data.harga_nama || u.data.jurusan_nama || u.data.latihan_nama || u.data.jabatan_nama || u.data.unor_nama || u.data.no_sk || u.data.sk_nomor || u.data.tahun || u.data_lama.nama || u.data_lama.jabatan_nama || u.data_lama.no_sk || '';
      t.append(el('strong', '', (AKSI[u.aksi] || [u.aksi])[0] + ' ' + String(u.jenis_label).toLowerCase() + (ringkas ? ' — ' + ringkas : '')));
      t.append(el('small', '', 'Diajukan ' + tgl(u.diajukan_at) + (u.diverifikasi_at ? ' · diverifikasi ' + tgl(u.diverifikasi_at) : '')));
      if (u.catatan && u.status !== 'diajukan') t.append(el('span', 'rw-uitem__cat', 'Catatan: ' + u.catatan));
      b.append(ic, t, statusTag(u.status));
      b.addEventListener('click', function () { lihatUsulan(u); });
      list.append(b);
    });
    panelU.replaceChildren(head, list);
  }
  function togelUsulan(buka) {
    if (!panelU || !tombolU) return;
    buka = buka === undefined ? panelU.hidden : buka;
    panelU.hidden = !buka;
    // usulan dibuka = daftar riwayat ditutup (dan sebaliknya)
    ['.rw-cari-bar', '#rw-list', '.rw-panel > .rw-catatan'].forEach(function (q) { var x = $(q); if (x) x.hidden = buka; });
    if (pagerEl) { if (buka) pagerEl.hidden = true; else renderDaftar(); }
    tombolU.setAttribute('aria-expanded', buka ? 'true' : 'false');
    $('span', tombolU).textContent = buka ? 'Riwayat' : 'Usulan';
    var ik = $('i', tombolU); if (ik) ik.className = 'bi ' + (buka ? 'bi-list-ul' : 'bi-inboxes');
    if (buka) renderUsulan();
  }

  /* ---------- sync SIASN (seperti SIMPEG) ----------
     Menu Sync: "Tahap 1 + 2" = tarik data lalu otomatis lanjut menarik dokumen;
     "Tahap 2" = tarik dokumen saja. Dokumen yang sudah ada tidak ditarik ulang (bisa dipaksa per dokumen). */
  var menuSyncEl = null;
  function tutupMenuSync() { if (menuSyncEl) { menuSyncEl.remove(); menuSyncEl = null; } }
  function menuSync(btn) {
    if (menuSyncEl && menuSyncEl._btn === btn) { tutupMenuSync(); return; }
    tutupMenuSync();
    var m = el('div', 'rw-syncmenu'); m.setAttribute('role', 'menu'); m._btn = btn;
    [['penuh', 'Tahap 1 + 2', 'Tarik data lalu dokumen', 'bi-cloud-arrow-down-fill'],
     ['dok', 'Tahap 2', 'Tarik dokumen saja (ijazah, SK, transkrip, …)', 'bi-file-earmark-arrow-down']].forEach(function (x) {
      var b = el('button', 'rw-syncmenu__item'); b.type = 'button'; b.setAttribute('role', 'menuitem');
      var t = el('span'); t.append(el('b', '', x[1]), document.createTextNode(' · ' + x[2]));
      b.append(ikon(x[3]), t);
      b.addEventListener('click', function () { tutupMenuSync(); bukaSync(x[0]); });
      m.append(b);
    });
    document.body.append(m);
    var r = btn.getBoundingClientRect(), lebar = Math.min(340, window.innerWidth - 16);
    m.style.width = lebar + 'px';
    m.style.top = (r.bottom + window.scrollY + 6) + 'px';
    m.style.left = Math.max(8, Math.min(r.right + window.scrollX - lebar, window.innerWidth - lebar - 8)) + 'px';
    menuSyncEl = m;
    $('button', m).focus();
  }
  document.addEventListener('click', function (e) { if (menuSyncEl && !menuSyncEl.contains(e.target) && !menuSyncEl._btn.contains(e.target)) tutupMenuSync(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && menuSyncEl) { var b = menuSyncEl._btn; tutupMenuSync(); b.focus(); } });
  window.addEventListener('resize', tutupMenuSync);

  function bukaSync(mode) {
    var nama = String(CFG.judul || '').toLowerCase();
    var hanyaDok = mode === 'dok';
    var box = el('div', 'rw-sync');
    var ik = el('div', 'rw-sync__ikon'); ik.append(el('span', 'rw-spin'));
    var judul = el('h4', 'rw-sync__judul', hanyaDok ? 'Menyiapkan daftar dokumen…' : 'Langkah 1: menarik data…');
    var teks = el('p', 'rw-sync__teks', 'Mohon tunggu, jangan tutup halaman ini.');
    var kon = koneksi(true);
    function tahap(no, j, k) {
      var t = el('div', 'rw-tahap'), hd = el('div', 'rw-tahap__head'), tx = el('div');
      var n = el('span', 'rw-tahap__no', String(no));
      tx.append(el('strong', '', j), el('small', '', k));
      hd.append(n, tx); t.append(hd);
      var isi = el('div', 'rw-tahap__isi'); isi.hidden = true; t.append(isi);
      return { el: t, no: n, ket: $('small', tx), isi: isi };
    }
    var t1 = tahap(1, 'Data riwayat', hanyaDok ? 'Dilewati (Tahap 2 saja)' : 'Tarik & simpan data dari SIASN');
    var t2 = tahap(2, 'Dokumen', 'Tarik dokumen PDF yang belum ada ke repository');
    if (hanyaDok) { t1.el.classList.add('is-mati'); t2.el.classList.add('is-aktif'); }
    else { t1.el.classList.add('is-aktif'); t2.el.classList.add('is-mati'); }
    box.append(ik, judul, teks, kon.el, t1.el, t2.el);
    var berubah = false;
    function selesai(ok, j, k) {
      ik.className = 'rw-sync__ikon ' + (ok ? 'is-ok' : 'is-gagal'); ik.replaceChildren(ikon(ok ? 'bi-check-lg' : 'bi-exclamation-lg'));
      judul.textContent = j; teks.textContent = k;
    }
    function catat(j, k, ok) { (box._badges = box._badges || []).push([j, k, ok]); }   // tampil sebagai badge setelah halaman dimuat ulang
    function kaki(tombolLain) {
      var b = tombol(berubah ? 'Selesai' : 'Tutup', berubah ? 'rw-btn--primary' : '', berubah ? 'bi-check2' : null, function () {
        if (berubah) { (box._badges || []).forEach(function (x) { badge(x[0], x[1], x[2], true); }); muatUlang({ segar: '1' }); } else tutupModal();
      });
      $('#rw-modal-foot').replaceChildren.apply($('#rw-modal-foot'), (tombolLain || []).concat([b]));
    }
    function gagalTahap(t, e, ulang) {
      t.isi.hidden = false; t.isi.replaceChildren(info(e.message, 'kuning', 'bi-exclamation-triangle'));
      t.no.replaceChildren(ikon('bi-exclamation-lg'));
      var off = e.data && e.data.offline;
      if (off) cekApi();
      badge(off ? 'API BKN tidak terhubung' : 'Sync belum berhasil', e.message, false);
      selesai(false, off ? 'API BKN tidak terhubung' : 'Sync belum berhasil', berubah ? 'Data riwayat sudah tersimpan; dokumen belum ditarik.' : 'Data di SIMPEG tidak diubah.');
      modalSibuk = false;
      kaki([tombol('Coba lagi', 'rw-btn--primary', 'bi-arrow-repeat', ulang)]);
    }

    /* Tahap 2: daftar dokumen, lalu otomatis menarik yang belum ada */
    function langkah2(siapkan) {
      if (!hanyaDok) { t1.el.classList.remove('is-aktif'); t1.el.classList.add('is-ok'); t1.no.replaceChildren(ikon('bi-check-lg')); }
      t2.el.classList.remove('is-mati'); t2.el.classList.add('is-aktif');
      ik.replaceChildren(el('span', 'rw-spin'));
      judul.textContent = 'Langkah 2: dokumen'; teks.textContent = 'Mengambil daftar dokumen dari SIASN…';
      t2.isi.hidden = false; t2.isi.replaceChildren(el('p', 'rw-muted', 'Memuat daftar dokumen…'));
      modalSibuk = true; kaki([]);
      var janji;
      if (siapkan) { var f = fdBaru('sync_dok_siapkan'); f.set('kategori', CFG.kategori); janji = post(f); }
      else janji = get({ aksi: 'sync_dok_list', kategori: CFG.kategori });
      janji.then(function (j) {
        var list = j.data || [];
        if (!list.length) {
          t2.ket.textContent = 'Tidak ada dokumen di SIASN';
          t2.isi.replaceChildren(el('p', 'rw-muted', hanyaDok ? 'SIASN tidak menyediakan dokumen untuk riwayat yang ada. Bila riwayat belum lengkap, jalankan Tahap 1 + 2.' : 'SIASN tidak menyediakan dokumen untuk riwayat ini.'));
          t2.el.classList.remove('is-aktif'); t2.el.classList.add('is-ok'); t2.no.replaceChildren(ikon('bi-check-lg'));
          selesai(true, 'Sync selesai', hanyaDok ? 'Tidak ada dokumen untuk ditarik.' : 'Data riwayat ' + nama + ' sudah sesuai SIASN.');
          modalSibuk = false; kaki(); return;
        }
        var ul = el('ul', 'rw-dokpilih');
        list.forEach(function (d) {
          var li = el('li'), lb = el('div'); lb.style.flex = '1'; lb.style.minWidth = '0';
          lb.append(document.createTextNode(d.dokumen), el('small', '', d.label));
          var st = el('b', '', d.sudah ? '✓' : '');
          li.append(lb, st); li._st = st; li._d = d; li._lb = lb;
          if (d.sudah) {
            li.className = 'ok is-ada'; st.title = 'Sudah ada di repository — tidak ditarik ulang';
            var ulangBtn = el('button', 'rw-dokpilih__ulang', 'Tarik ulang'); ulangBtn.type = 'button';
            ulangBtn.addEventListener('click', function () { if (!modalSibuk) jalankan([li]); });
            lb.append(el('small', '', 'Sudah ada')); li.insertBefore(ulangBtn, st);
          }
          ul.append(li);
        });
        var prog = el('div', 'rw-progress'); var bar = el('span'); prog.append(bar);
        t2.isi.replaceChildren(ul, prog);
        var semuaLi = $$('li', ul), antre = semuaLi.filter(function (li) { return !li._d.sudah; });
        t2.ket.textContent = list.length + ' dokumen · ' + antre.length + ' belum ada di repository';
        var ok = 0, gagalLi = [];
        function jalankan(daftar) {
          modalSibuk = true; kaki([]);
          ik.replaceChildren(el('span', 'rw-spin')); judul.textContent = 'Menarik dokumen…';
          gagalLi = []; var i = 0, putus = false; bar.style.width = '3%';
          function lanjut() {
            if (i >= daftar.length) return Promise.resolve();
            var li = daftar[i];
            if (putus) { li.className = 'gagal'; li._st.textContent = '!'; li._st.title = 'Dilewati: API BKN terputus'; gagalLi.push(li); i++; return lanjut(); }
            li.className = 'proses'; li._st.textContent = '⏳'; li.scrollIntoView({ block: 'nearest' });
            teks.textContent = 'Dokumen ' + (i + 1) + ' dari ' + daftar.length + ': ' + li._d.dokumen;
            var f2 = fdBaru('sync_dok'); f2.set('kategori', CFG.kategori); f2.set('idx', String(li._d.idx));
            return post(f2).then(function (r) { ok++; berubah = true; li.className = 'ok'; li._st.textContent = '✓'; li._st.title = r.message || ''; })
              .catch(function (e) { gagalLi.push(li); li.className = 'gagal'; li._st.textContent = '!'; li._st.title = e.message; if (e.data && e.data.offline) { putus = true; cekApi(); } })
              .then(function () { i++; bar.style.width = Math.round(100 * i / daftar.length) + '%'; return lanjut(); });
          }
          return lanjut().then(function () {
            var g = gagalLi.length;
            t2.no.replaceChildren(ikon(g ? 'bi-exclamation-lg' : 'bi-check-lg'));
            t2.el.classList.remove('is-aktif'); if (!g) t2.el.classList.add('is-ok');
            if (ok) catat('Langkah 2: dokumen', ok + ' dokumen berhasil ditarik ke repository', true);
            if (g) catat('Sebagian dokumen gagal', g + ' dokumen belum berhasil' + (putus ? ' (API BKN terputus)' : '') + '. Arahkan kursor ke tanda ! untuk detail.', false);
            selesai(!g, g ? 'Sync selesai dengan catatan' : 'Sync selesai', ok + ' dokumen ditarik' + (g ? ', ' + g + ' gagal.' : '.') + (list.length - antre.length ? ' ' + (list.length - antre.length) + ' sudah ada (tidak ditarik ulang).' : ''));
            modalSibuk = false;
            kaki(g ? [tombol('Tarik ulang yang gagal (' + g + ')', 'rw-btn--primary', 'bi-arrow-repeat', function () { gagalLi.forEach(function (li) { li.className = ''; li._st.textContent = ''; }); jalankan(gagalLi.slice()); })] : []);
          });
        }
        if (!antre.length) {
          bar.style.width = '100%';
          t2.el.classList.remove('is-aktif'); t2.el.classList.add('is-ok'); t2.no.replaceChildren(ikon('bi-check-lg'));
          selesai(true, 'Sync selesai', 'Semua dokumen sudah ada di repository (tidak ditarik ulang).');
          modalSibuk = false; kaki(); return;
        }
        jalankan(antre);
      }).catch(function (e) { gagalTahap(t2, e, function () { langkah2(siapkan); }); });
    }

    /* Tahap 1: tarik data, lalu otomatis lanjut ke Tahap 2 */
    function langkah1() {
      modalSibuk = true; kaki([]);
      ik.replaceChildren(el('span', 'rw-spin'));
      judul.textContent = 'Langkah 1: menarik data…'; teks.textContent = 'Mohon tunggu, jangan tutup halaman ini.';
      t1.no.textContent = '1';
      t1.isi.hidden = false; t1.isi.replaceChildren(el('div', 'rw-progress', ''));
      var bar = el('span'); t1.isi.firstChild.append(bar); bar.style.width = '35%';
      var fd = fdBaru('sync_data'); fd.set('kategori', CFG.kategori);
      post(fd).then(function (j) {
        berubah = true; bar.style.width = '100%';
        t1.ket.textContent = j.message;
        t1.isi.hidden = true;
        catat('Langkah 1: data', j.message, true);
        if (!j.dokumen) {
          t1.el.classList.remove('is-aktif'); t1.el.classList.add('is-ok'); t1.no.replaceChildren(ikon('bi-check-lg'));
          t2.ket.textContent = 'Tidak ada dokumen di SIASN';
          selesai(true, 'Sync selesai', 'Data riwayat ' + nama + ' sudah sesuai SIASN.');
          modalSibuk = false; kaki(); return;
        }
        setTimeout(function () { langkah2(false); }, 600);   // lanjut otomatis seperti SIMPEG
      }).catch(function (e) { gagalTahap(t1, e, langkah1); });
    }

    bukaModal('Sync SIASN · ' + (hanyaDok ? 'Tahap 2' : 'Tahap 1 + 2'), box, [], { kelas: 'rw-modal--sync' });
    if (hanyaDok) langkah2(true); else langkah1();
  }

  /* ---------- tombol halaman ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-rw]');
    var kartuEl = !b && e.target.closest && e.target.closest('.rw-card, .rw-tabel tbody tr');
    if (kartuEl && !e.target.closest('a,button')) {                       // klik kartu / baris = Lihat
      if (kartuEl.dataset.uid) { if (usulanById(kartuEl.dataset.uid)) lihatUsulan(usulanById(kartuEl.dataset.uid)); }
      else if (kartuEl.dataset.idx !== undefined) lihat(+kartuEl.dataset.idx);
      return;
    }
    if (!b || (modalEl && modalEl.contains(b))) return;
    var idx = b.dataset.idx !== undefined ? +b.dataset.idx : -1, r = BARIS[idx];
    switch (b.dataset.rw) {
      case 'lihat':   // klik chip dokumen = popup dokumen saja (seperti SIMPEG); tombol Lihat = rincian
        if (b.dataset.dok && r && r.id_rw) bukaPdf({ url: urlRiwayat(r, b.dataset.dok) }, String(b.title || 'Dokumen').replace(/^Lihat /, ''));
        else lihat(idx, b.dataset.dok);
        break;
      case 'ubah':
        if (r && r.usulan && usulanById(r.usulan)) lihatUsulan(usulanById(r.usulan)); else if (r) bukaForm(r.jenis, 'ubah', r, null);
        break;
      case 'hapus':
        if (r && r.usulan && usulanById(r.usulan)) lihatUsulan(usulanById(r.usulan)); else if (r) bukaHapus(r, null);
        break;
      case 'tambah': if (b.dataset.jenis) bukaForm(b.dataset.jenis, 'tambah', null, null); else pilihTambah(); break;
      case 'usulan-j': daftar.modeJ[b.dataset.jenis] = daftar.modeJ[b.dataset.jenis] === 'usulan' ? 'riwayat' : 'usulan'; renderDaftar(); break;
      case 'segar': muatUlang({ segar: '1' }); break;
      case 'u-lihat': if (usulanById(b.dataset.uid)) lihatUsulan(usulanById(b.dataset.uid)); break;
      case 'u-edit': if (usulanById(b.dataset.uid)) editUsulan(usulanById(b.dataset.uid)); break;
      case 'u-batal': if (usulanById(b.dataset.uid)) batalkan(usulanById(b.dataset.uid)); break;
      case 'usulan': togelUsulan(); break;
      case 'sync': menuSync(b); break;
    }
  });
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('rw-card')) { e.preventDefault(); lihat(+e.target.dataset.idx); }
  });
})();
