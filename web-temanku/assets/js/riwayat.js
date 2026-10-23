/*
 * Halaman Riwayat ASN (TEMANKU): status koneksi API SIMPEG/BKN, popup lihat/tambah/ubah/hapus (usulan),
 * daftar usulan, sync SIASN (data lalu dokumen satu per satu), dan viewer PDF memakai pdf.js.
 * Semua teks dari server dipasang lewat textContent (aman dari HTML).
 */
(function () {
  'use strict';
  var CFG = window.RIWAYAT_CFG || {};
  var PDFJS_VERSI = '3.11.174';
  var PDFJS_URL = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' + PDFJS_VERSI + '/pdf.min.js';
  var PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' + PDFJS_VERSI + '/pdf.worker.min.js';
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
    var q = new URLSearchParams(params).toString();
    return fetch(CFG.aksiUrl + (CFG.aksiUrl.indexOf('?') >= 0 ? '&' : '?') + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(ambilJson);
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

  /* ---------- status koneksi API SIMPEG & BKN ---------- */
  function cekKoneksi(segar) {
    var chips = $$('.rw-conn__chip');
    if (!chips.length) return;
    chips.forEach(function (c) { c.className = 'rw-conn__chip is-cek'; $('.rw-conn__txt', c).textContent = (c.dataset.conn === 'bkn' ? 'API BKN' : 'API SIMPEG') + ': memeriksa…'; });
    get(segar ? { aksi: 'status', segar: '1' } : { aksi: 'status' }).then(function (j) {
      chips.forEach(function (c) {
        var s = j[c.dataset.conn] || {};
        var nama = c.dataset.conn === 'bkn' ? 'API BKN' : 'API SIMPEG';
        c.className = 'rw-conn__chip ' + (s.ok ? 'is-ok' : 'is-gagal');
        $('.rw-conn__txt', c).textContent = nama + (s.ok ? ' terhubung' : ' tidak terhubung');
        c.title = (s.ok ? 'Terhubung' : 'Tidak terhubung') + (s.ms ? ' · ' + s.ms + ' ms' : '') + (j.dicek ? ' · dicek ' + j.dicek : '') + ' — klik untuk periksa ulang';
      });
    }).catch(function () {
      chips.forEach(function (c) { c.className = 'rw-conn__chip is-gagal'; $('.rw-conn__txt', c).textContent = (c.dataset.conn === 'bkn' ? 'API BKN' : 'API SIMPEG') + ': status tidak diketahui'; });
    });
  }
  $$('.rw-conn__chip').forEach(function (c) { c.addEventListener('click', function () { cekKoneksi(true); }); });
  cekKoneksi(false);

  /* ---------- popup ---------- */
  var modalEl = $('#rw-modal'), modalSibuk = false, fokusSebelum = null;
  function bukaModal(judul, isi, kaki) {
    fokusSebelum = document.activeElement;
    $('#rw-modal-title').textContent = judul;
    var body = $('#rw-modal-body'), foot = $('#rw-modal-foot');
    body.replaceChildren(isi);
    foot.replaceChildren.apply(foot, kaki || []);
    modalEl.hidden = false;
    document.body.classList.add('rw-modal-buka');
    body.scrollTop = 0;
    var f = $('input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea,button', body) || $('[data-tutup]', modalEl);
    if (f) setTimeout(function () { f.focus(); }, 30);
  }
  function tutupModal() {
    if (modalSibuk) return;
    modalEl.hidden = true;
    if (pdfEl.hidden) document.body.classList.remove('rw-modal-buka');
    if (fokusSebelum && fokusSebelum.focus) fokusSebelum.focus();
  }
  function sibuk(ya, btn, teks) {
    modalSibuk = ya;
    $$('#rw-modal-foot button').forEach(function (b) { b.disabled = ya; });
    if (btn) { if (ya) { btn.dataset.asli = $('span', btn).textContent; $('span', btn).textContent = teks || 'Memproses…'; } else if (btn.dataset.asli) $('span', btn).textContent = btn.dataset.asli; }
  }
  if (modalEl) {
    $$('[data-tutup]', modalEl).forEach(function (b) { b.addEventListener('click', tutupModal); });
    modalEl.addEventListener('click', function (e) { if (e.target === modalEl) tutupModal(); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (pdfEl && !pdfEl.hidden) tutupPdf(); else if (modalEl && !modalEl.hidden) tutupModal();
  });

  /* ---------- viewer PDF (pdf.js) ---------- */
  var pdfEl = $('#rw-pdf'), pdfDoc = null, pdfSkala = 1, pdfMuat = null, pdfToken = 0;
  function muatPdfJs() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (pdfMuat) return pdfMuat;
    pdfMuat = new Promise(function (ok, gagal) {
      var s = document.createElement('script');
      s.src = PDFJS_URL; s.async = true; s.crossOrigin = 'anonymous'; s.referrerPolicy = 'no-referrer';
      s.onload = function () {
        if (!window.pdfjsLib) { gagal(new Error('pdf.js tidak tersedia')); return; }
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
        ok(window.pdfjsLib);
      };
      s.onerror = function () { pdfMuat = null; gagal(new Error('pdf.js gagal dimuat')); };
      document.head.appendChild(s);
    });
    return pdfMuat;
  }
  function pesanPdf(teks, href) {
    var box = el('div', 'rw-pdf__pesan');
    box.append(el('p', '', teks));
    if (href) { var a = el('a', 'rw-btn', 'Buka di tab baru'); a.href = href; a.target = '_blank'; a.rel = 'noopener'; box.append(a); }
    $('#rw-pdf-body').replaceChildren(box);
  }
  function renderPdf() {
    var body = $('#rw-pdf-body'), token = ++pdfToken;
    if (!pdfDoc) return;
    $('#rw-pdf-zoom').textContent = Math.round(pdfSkala * 100) + '%';
    body.replaceChildren();
    var dpr = window.devicePixelRatio || 1, lebar = Math.max(280, body.clientWidth - 28);
    var urut = Promise.resolve();
    for (var n = 1; n <= pdfDoc.numPages; n++) {
      (function (no) {
        urut = urut.then(function () {
          if (token !== pdfToken) return;
          return pdfDoc.getPage(no).then(function (hal) {
            var dasar = hal.getViewport({ scale: 1 });
            var vp = hal.getViewport({ scale: (lebar / dasar.width) * pdfSkala });
            var c = document.createElement('canvas');
            c.width = Math.floor(vp.width * dpr); c.height = Math.floor(vp.height * dpr);
            c.style.width = Math.floor(vp.width) + 'px'; c.style.height = Math.floor(vp.height) + 'px';
            c.setAttribute('aria-label', 'Halaman ' + no + ' dari ' + pdfDoc.numPages);
            body.append(c);
            return hal.render({ canvasContext: c.getContext('2d'), viewport: vp, transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null }).promise;
          });
        });
      })(n);
    }
    urut.catch(function () { if (token === pdfToken) pesanPdf('Sebagian halaman gagal ditampilkan.'); });
  }
  function bukaPdf(href, judul) {
    if (!pdfEl) { window.open(href, '_blank', 'noopener'); return; }
    $('#rw-pdf-title').textContent = judul || 'Dokumen';
    $('#rw-pdf-unduh').href = href;
    var body = $('#rw-pdf-body');
    var sp = el('div', 'rw-pdf__pesan'); sp.append(el('div', 'rw-spin'), el('p', '', 'Memuat dokumen…'));
    body.replaceChildren(sp);
    pdfEl.hidden = false; pdfSkala = 1; pdfDoc = null;
    document.body.classList.add('rw-modal-buka');
    var token = ++pdfToken;
    muatPdfJs().then(function (lib) {
      return lib.getDocument({ url: href, withCredentials: true }).promise;
    }).then(function (doc) {
      if (token !== pdfToken) return;
      pdfDoc = doc; renderPdf();
    }).catch(function (e) {
      if (token !== pdfToken) return;
      pesanPdf(/pdf\.js/.test(e.message) ? 'Viewer PDF tidak dapat dimuat (periksa koneksi internet).' : 'Dokumen belum dapat ditampilkan. Dokumen mungkin belum tersedia di repository.', href);
    });
  }
  function tutupPdf() {
    pdfEl.hidden = true; pdfToken++;
    if (pdfDoc && pdfDoc.destroy) pdfDoc.destroy();
    pdfDoc = null;
    $('#rw-pdf-body').replaceChildren();
    if (!modalEl || modalEl.hidden) document.body.classList.remove('rw-modal-buka');
  }
  if (pdfEl) {
    $$('[data-tutup]', pdfEl).forEach(function (b) { b.addEventListener('click', tutupPdf); });
    $$('[data-pdf-aksi]', pdfEl).forEach(function (b) {
      b.addEventListener('click', function () {
        pdfSkala = Math.min(3, Math.max(0.5, pdfSkala + (b.dataset.pdfAksi === 'besar' ? 0.25 : -0.25)));
        renderPdf();
      });
    });
    var rz;
    window.addEventListener('resize', function () { if (!pdfEl.hidden) { clearTimeout(rz); rz = setTimeout(renderPdf, 200); } });
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-pdf]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    bukaPdf(a.href, a.dataset.pdf);
  });
  function linkPdf(href, label) {
    var a = el('a', 'rw-dok'); a.href = href; a.target = '_blank'; a.rel = 'noopener'; a.dataset.pdf = label;
    a.append(ikon('bi-file-earmark-pdf-fill'), document.createTextNode(label));
    return a;
  }

  if (!CFG.kategori) return;   // tanpa kategori aktif: hanya status koneksi

  var BARIS = CFG.baris || [], USULAN = CFG.usulan || [], FORM = CFG.form || {}, IZIN = CFG.izin || {};
  function usulanById(id) { return USULAN.filter(function (u) { return u.id === id; })[0] || null; }
  function bisaUbahUsulan(u) { return u && (u.status === 'diajukan' || u.status === 'dikembalikan'); }
  function dokRiwayat(row, kolom) {
    return CFG.dokUrl + '?' + new URLSearchParams({ kategori: CFG.kategori, id: row.id_rw, berkas: kolom }).toString();
  }
  function dokUsulan(u, kolom) { return CFG.dokUrl + '?' + new URLSearchParams({ usulan: u.id, berkas: kolom }).toString(); }
  function labelDok(jenis, kolom) {
    var d = (FORM[jenis] && FORM[jenis].dok || []).filter(function (x) { return x[0] === kolom; })[0];
    return d ? d[1] : kolom;
  }

  /* ---------- referensi (select & pencarian) ---------- */
  var REF = {};
  function ambilRef(nama) {
    if (!REF[nama]) REF[nama] = get({ aksi: 'ref', nama: nama }).then(function (j) { return j.data || []; }).catch(function (e) { delete REF[nama]; throw e; });
    return REF[nama];
  }

  /* ---------- detail riwayat ---------- */
  function lihat(idx) {
    var r = BARIS[idx];
    if (!r) return;
    var box = el('div', 'rw-detail');
    if (r.sub) box.append(el('p', 'rw-detail__sub', r.sub));
    var tags = el('div', 'rw-tags');
    if (r.label) tags.append(tag(r.label));
    if (r.waktu) tags.append(tag(r.waktu, 'biru', 'bi-calendar3'));
    box.append(tags);
    var u = r.usulan ? usulanById(r.usulan) : null;
    if (u) {
      var inf = el('div', 'rw-info rw-info--kuning');
      inf.append(ikon('bi-hourglass-split'), el('span', '', 'Riwayat ini memiliki usulan ' + u.aksi + ' yang sedang diproses (' + (STATUS[u.status] || [u.status])[0].toLowerCase() + ').'));
      box.append(inf);
    }
    box.append(el('div', 'rw-sec', 'Rincian'));
    var dl = el('dl', 'rw-grid');
    (r.info || []).forEach(function (p) { var d = el('div'); d.append(el('dt', '', p[0]), el('dd', '', p[1])); dl.append(d); });
    if (!(r.info || []).length) dl.append(el('div', 'rw-muted', 'Tidak ada rincian tambahan.'));
    box.append(dl);
    box.append(el('div', 'rw-sec', 'Dokumen'));
    var dok = el('div');
    (r.dok || []).forEach(function (d) { if (r.id_rw) dok.append(linkPdf(dokRiwayat(r, d[1]), d[0])); });
    if (!dok.childNodes.length) dok.append(el('span', 'rw-muted', 'Belum ada dokumen di repository. Gunakan Sync SIASN untuk menarik dokumen dari BKN.'));
    box.append(dok);
    var kaki = [];
    var iz = IZIN[r.jenis] || {};
    if (u) kaki.push(tombol('Lihat usulan', '', 'bi-inboxes', function () { lihatUsulan(u); }));
    else {
      if (iz.hapus) kaki.push(tombol('Usulkan hapus', '', 'bi-trash3', function () { bukaHapus(r, null); }));
      if (iz.ubah) kaki.push(tombol('Usulkan perubahan', 'rw-btn--primary', 'bi-pencil-square', function () { bukaForm(r.jenis, 'ubah', r, null); }));
    }
    kaki.push(tombol('Tutup', '', null, tutupModal));
    bukaModal(r.judul, box, kaki);
  }

  /* ---------- form usulan ---------- */
  function kontrol(f, nilai, terkunci) {
    var wrap = el('div', 'rw-f' + (f.lebar === 'penuh' || f.t === 'textarea' ? ' rw-f--penuh' : ''));
    wrap.dataset.k = f.k;
    var id = 'rwf-' + f.k;
    var inp;
    if (f.t === 'check') {
      wrap.className += ' rw-check';
      inp = el('input'); inp.type = 'checkbox'; inp.value = '1'; inp.id = id; inp.name = 'f[' + f.k + ']';
      inp.checked = String(nilai) === '1' || nilai === 1 || nilai === true;
      var lb = el('label', '', f.l); lb.htmlFor = id;
      wrap.append(inp, lb);
      if (terkunci) inp.disabled = true;
      return wrap;
    }
    var label = el('label', '', f.l); label.htmlFor = id;
    if (f.w && !terkunci) { label.append(document.createTextNode(' ')); label.append(el('span', 'rw-wajib', '*')); }
    wrap.append(label);
    if (f.t === 'select') {
      inp = el('select'); inp.id = id; inp.name = 'f[' + f.k + ']';
      var kosong = el('option', '', '— Pilih —'); kosong.value = ''; inp.append(kosong);
      var namaInp = null;
      if (f.nama) { namaInp = el('input'); namaInp.type = 'hidden'; namaInp.name = 'f[' + f.nama + ']'; wrap.append(namaInp); }
      var isi = function (opsi) {
        opsi.forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0]; inp.append(op); });
        if (nilai !== undefined && nilai !== null && String(nilai) !== '') {
          inp.value = String(nilai);
          if (inp.value !== String(nilai)) {   // nilai lama tidak ada di referensi: tetap tampilkan
            var op = el('option', '', (namaInp && f.nama && wrap.dataset.namaAwal) || String(nilai)); op.value = String(nilai); inp.append(op); inp.value = String(nilai);
          }
        }
        if (namaInp) namaInp.value = inp.value ? inp.options[inp.selectedIndex].text : '';
      };
      if (f.ref) {
        inp.disabled = true; kosong.textContent = 'Memuat…';
        ambilRef(f.ref).then(function (list) {
          kosong.textContent = '— Pilih —'; inp.disabled = !!terkunci;
          isi(list.map(function (x) { return [x.id, x.nama]; }));
          inp.dispatchEvent(new Event('change'));
        }).catch(function () { kosong.textContent = 'Referensi gagal dimuat'; inp.disabled = !!terkunci; isi([]); });
      } else isi(f.o || []);
      inp.addEventListener('change', function () { if (namaInp) namaInp.value = inp.value ? inp.options[inp.selectedIndex].text : ''; });
    } else if (f.t === 'search') {
      var cari = el('div', 'rw-cari');
      var vis = el('input'); vis.type = 'text'; vis.id = id; vis.autocomplete = 'off'; vis.placeholder = 'Cari…';
      var hid = el('input'); hid.type = 'hidden'; hid.name = 'f[' + f.k + ']'; hid.value = nilai || '';
      var hn = el('input'); hn.type = 'hidden'; hn.name = 'f[' + f.nama + ']';
      var list = el('ul', 'rw-cari__list'); list.hidden = true;
      cari.append(vis, list);
      wrap.append(cari, hid, hn);
      inp = vis;
      var tmr, aktifIdx = -1;
      var pilih = function (x) { hid.value = x.id; hn.value = x.nama; vis.value = x.nama; list.hidden = true; wrap.classList.remove('is-error'); };
      vis.addEventListener('input', function () {
        hid.value = ''; hn.value = '';
        clearTimeout(tmr);
        var q = vis.value.trim();
        if (q.length < 2) { list.hidden = true; return; }
        tmr = setTimeout(function () {
          var ind = f.induk ? (($('[name="f[' + f.induk + ']"]', wrap.closest('form')) || {}).value || '') : '';
          get({ aksi: 'ref', nama: f.ref, q: q, tingkat: ind }).then(function (j) {
            list.replaceChildren(); aktifIdx = -1;
            (j.data || []).slice(0, 40).forEach(function (x) {
              var li = el('li', '', x.nama); li.addEventListener('mousedown', function (e) { e.preventDefault(); pilih(x); }); li._x = x; list.append(li);
            });
            if (!list.childNodes.length) list.append(el('li', 'rw-muted', 'Tidak ditemukan'));
            list.hidden = false;
          }).catch(function () { list.hidden = true; });
        }, 300);
      });
      vis.addEventListener('keydown', function (e) {
        var li = $$('li', list).filter(function (x) { return x._x; });
        if (list.hidden || !li.length) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          aktifIdx = (aktifIdx + (e.key === 'ArrowDown' ? 1 : -1) + li.length) % li.length;
          li.forEach(function (x, i) { x.classList.toggle('is-aktif', i === aktifIdx); });
        } else if (e.key === 'Enter' && aktifIdx >= 0) { e.preventDefault(); pilih(li[aktifIdx]._x); }
      });
      vis.addEventListener('blur', function () { setTimeout(function () { list.hidden = true; }, 150); });
      wrap._isiNama = function (n) { hn.value = n || ''; vis.value = n || ''; };
    } else if (f.t === 'textarea') {
      inp = el('textarea'); inp.id = id; inp.name = 'f[' + f.k + ']'; inp.value = nilai || '';
      if (f.max) inp.maxLength = f.max;
    } else {
      inp = el('input'); inp.id = id; inp.name = 'f[' + f.k + ']';
      var tipe = { date: 'date', year: 'number', number: 'number', int: 'number', decimal: 'text', email: 'email', tel: 'tel', nik: 'text' }[f.t] || 'text';
      inp.type = tipe;
      if (f.t === 'year') { inp.min = f.min || 1940; inp.max = new Date().getFullYear() + 1; inp.step = 1; }
      if (f.t === 'number' || f.t === 'int') { if (f.min !== undefined) inp.min = f.min; if (f.max !== undefined) inp.max = f.max; inp.step = 1; }
      if (f.t === 'decimal') inp.inputMode = 'decimal';
      if (f.t === 'nik') { inp.inputMode = 'numeric'; inp.maxLength = 16; inp.pattern = '\\d{16}'; }
      if (f.max && tipe === 'text') inp.maxLength = f.max;
      inp.value = nilai === undefined || nilai === null ? '' : String(nilai);
      if (tipe === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(inp.value)) inp.value = '';
    }
    if (terkunci) inp.disabled = true;
    wrap.append(inp);
    if (f.bantu) wrap.append(el('small', '', f.bantu));
    return wrap;
  }

  function kontrolDok(d, aksi, u) {
    var wrap = el('div', 'rw-f rw-f--penuh');
    wrap.dataset.k = 'dok_' + d[0];
    var lb = el('label', '', d[1]);
    var wajib = d[2] && aksi === 'tambah' && !(u && u.dok.indexOf(d[0]) >= 0);
    if (wajib) { lb.append(document.createTextNode(' ')); lb.append(el('span', 'rw-wajib', '*')); }
    var box = el('label', 'rw-file');
    var inp = el('input'); inp.type = 'file'; inp.name = 'dok_' + d[0]; inp.accept = 'application/pdf,.pdf';
    var info = el('span'); var b = el('b', '', 'Pilih PDF (maks. 1,5 MB)'); var em = el('em', '', aksi === 'tambah' && !u ? 'Belum ada berkas' : 'Kosongkan bila dokumen lama tidak diganti');
    info.append(b, em);
    box.append(ikon('bi-file-earmark-arrow-up'), info, inp);
    lb.htmlFor = ''; wrap.append(lb, box);
    if (u && u.dok.indexOf(d[0]) >= 0) { var l = linkPdf(dokUsulan(u, d[0]), 'Lihat ' + d[1] + ' yang diunggah'); l.style.marginTop = '6px'; wrap.append(l); }
    inp.addEventListener('change', function () {
      wrap.classList.remove('is-error'); $$('.rw-f__err', wrap).forEach(function (x) { x.remove(); });
      var f = inp.files && inp.files[0];
      if (!f) { b.textContent = 'Pilih PDF (maks. 1,5 MB)'; return; }
      var salah = !/\.pdf$/i.test(f.name) && f.type !== 'application/pdf' ? 'Berkas harus PDF.' : (f.size > (CFG.pdfMaks || 1572864) ? 'Ukuran PDF maksimal 1,5 MB.' : '');
      if (salah) { inp.value = ''; b.textContent = 'Pilih PDF (maks. 1,5 MB)'; wrap.classList.add('is-error'); wrap.append(el('small', 'rw-f__err', salah)); return; }
      b.textContent = f.name; em.textContent = Math.round(f.size / 1024) + ' KB';
    });
    if (wajib) wrap.dataset.wajib = '1';
    return wrap;
  }

  function tandaiError(form, field, pesan) {
    var w = field && $('[data-k="' + String(field).replace(/^f\[|\]$/g, '').replace(/"/g, '') + '"]', form);
    if (!w) return false;
    w.classList.add('is-error');
    $$('.rw-f__err', w).forEach(function (x) { x.remove(); });
    w.append(el('small', 'rw-f__err', pesan));
    var i = $('input,select,textarea', w); if (i && i.focus) i.focus();
    return true;
  }

  function bukaForm(jenis, aksi, row, u) {
    var def = FORM[jenis];
    if (!def) { toast('Usulan untuk riwayat ini diajukan melalui admin OPD (SIMPEG).', false); return; }
    var nilai = u ? (u.data || {}) : (row ? (row.awal || {}) : {});
    var terkunci = !!def.kunci_ubah && aksi === 'ubah';
    var form = el('form', 'rw-form'); form.noValidate = true;
    var atas = el('div', 'rw-f--penuh');
    if (u && u.status === 'dikembalikan' && u.catatan) {
      var c = el('div', 'rw-info rw-info--biru'); c.append(ikon('bi-chat-left-text'), el('span', '', 'Catatan verifikator: ' + u.catatan)); atas.append(c);
    }
    if (aksi === 'ubah' && row) { var r = el('div', 'rw-info rw-info--kuning'); r.append(ikon('bi-info-circle'), el('span', '', 'Perubahan akan diajukan sebagai usulan untuk riwayat: ' + row.judul + '. Data riwayat tidak berubah sebelum disetujui admin.')); atas.append(r); }
    if (terkunci) { var k = el('div', 'rw-info rw-info--biru'); k.append(ikon('bi-lock'), el('span', '', 'Data kinerja mengikuti SIASN BKN. Anda dapat mengusulkan penggantian dokumen SKP.')); atas.append(k); }
    if (def.catatan) { var cc = el('div', 'rw-info rw-info--biru'); cc.append(ikon('bi-info-circle'), el('span', '', def.catatan)); atas.append(cc); }
    var galat = el('div', 'rw-info rw-info--merah'); galat.hidden = true; atas.append(galat);
    form.append(atas);
    form.append(el('div', 'rw-form__sec', 'Data'));
    def.field.forEach(function (f) {
      var w = kontrol(f, nilai[f.k], terkunci);
      if (f.t === 'search' && w._isiNama) w._isiNama(nilai[f.nama] || '');
      if (f.t === 'select' && f.nama && nilai[f.nama]) w.dataset.namaAwal = nilai[f.nama];
      if (f.tampil) w.dataset.tampil = JSON.stringify(f.tampil);
      form.append(w);
    });
    form.append(el('div', 'rw-form__sec', 'Dokumen'));
    def.dok.forEach(function (d) { form.append(kontrolDok(d, aksi, u)); });
    var ket = el('div', 'rw-f rw-f--penuh'); ket.dataset.k = 'alasan';
    var lk = el('label', '', 'Keterangan untuk verifikator (opsional)'); lk.htmlFor = 'rwf-alasan';
    var ta = el('textarea'); ta.id = 'rwf-alasan'; ta.name = 'alasan'; ta.maxLength = 1000; ta.value = u ? (u.alasan || '') : '';
    ket.append(lk, ta); form.append(ket);
    var segarTampil = function () {
      $$('[data-tampil]', form).forEach(function (w) {
        var t = JSON.parse(w.dataset.tampil), dep = $('[name="f[' + t[0] + ']"]', form);
        w.hidden = !(dep && dep.value === t[1]);
      });
    };
    form.addEventListener('change', segarTampil);
    segarTampil();
    form.addEventListener('input', function (e) { var w = e.target.closest('.rw-f'); if (w) { w.classList.remove('is-error'); $$('.rw-f__err', w).forEach(function (x) { x.remove(); }); } });

    var kirim = tombol(u ? (u.status === 'dikembalikan' ? 'Simpan & ajukan ulang' : 'Simpan perubahan') : 'Ajukan usulan', 'rw-btn--primary', 'bi-send');
    var jalankan = function (e) {
      if (e) e.preventDefault();
      galat.hidden = true;
      var pertama = null;
      if (!terkunci) {
        def.field.forEach(function (f) {
          var w = $('[data-k="' + f.k + '"]', form);
          if (!f.w || !w || w.hidden) return;
          var i = $('[name="f[' + f.k + ']"]', w);
          if (i && String(i.value || '').trim() === '' && f.t !== 'check') { tandaiError(form, f.k, f.t === 'search' ? 'Pilih dari daftar pencarian.' : 'Wajib diisi.'); pertama = pertama || w; }
        });
      }
      $$('[data-wajib="1"]', form).forEach(function (w) {
        var i = $('input[type=file]', w);
        if (i && !i.files.length) { tandaiError(form, w.dataset.k, 'Dokumen wajib diunggah.'); pertama = pertama || w; }
      });
      if (pertama) { pertama.scrollIntoView({ block: 'center', behavior: 'smooth' }); return; }
      var fd = fdBaru('simpan');
      new FormData(form).forEach(function (v, k2) { fd.append(k2, v); });
      def.field.forEach(function (f) { if (f.t === 'check' && !fd.has('f[' + f.k + ']')) fd.append('f[' + f.k + ']', '0'); });
      fd.set('jenis', jenis);
      if (u) fd.set('usulan_id', u.id);
      else { fd.set('aksi_usul', aksi); if (row) fd.set('ref_id', row.id_rw); }
      sibuk(true, kirim, 'Mengirim…');
      post(fd).then(function (j) {
        modalSibuk = false;
        toast(j.message || 'Usulan tersimpan.', true);
        setTimeout(function () { muatUlang({ usulan: '1' }); }, 700);
      }).catch(function (err) {
        sibuk(false, kirim);
        if (!tandaiError(form, err.field, err.message)) { galat.replaceChildren(ikon('bi-exclamation-triangle'), el('span', '', err.message)); galat.hidden = false; galat.scrollIntoView({ block: 'nearest' }); }
      });
    };
    kirim.addEventListener('click', jalankan);
    form.addEventListener('submit', jalankan);
    var judul = (u ? 'Perbaiki usulan ' : 'Usulan ' + (AKSI[aksi] || [aksi])[0].toLowerCase() + ' ') + (CFG.jenisLabel[jenis] || jenis).toLowerCase();
    bukaModal(judul.charAt(0).toUpperCase() + judul.slice(1), form, [tombol('Batal', '', null, tutupModal), kirim]);
  }

  function bukaHapus(row, u) {
    var box = el('form', 'rw-form'); box.noValidate = true;
    var info = el('div', 'rw-info rw-info--merah rw-f--penuh');
    info.append(ikon('bi-trash3'), el('span', '', 'Usulan hapus untuk riwayat: ' + (row ? row.judul : (u && u.data_lama && Object.keys(u.data_lama).length ? 'riwayat terpilih' : '-')) + '. Riwayat baru dihapus setelah disetujui admin.'));
    box.append(info);
    if (u && u.status === 'dikembalikan' && u.catatan) { var c = el('div', 'rw-info rw-info--biru rw-f--penuh'); c.append(ikon('bi-chat-left-text'), el('span', '', 'Catatan verifikator: ' + u.catatan)); box.append(c); }
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
      post(fd).then(function (j) { modalSibuk = false; toast(j.message, true); setTimeout(function () { muatUlang({ usulan: '1' }); }, 700); })
        .catch(function (err) { sibuk(false, kirim); tandaiError(box, 'alasan', err.message); });
    };
    kirim.addEventListener('click', jalan); box.addEventListener('submit', jalan);
    bukaModal(u ? 'Perbaiki usulan hapus' : 'Usulkan hapus riwayat', box, [tombol('Batal', '', null, tutupModal), kirim]);
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
    var ban = el('div', 'rw-info rw-info--' + (st[1] === 'abu' ? 'biru' : st[1]));
    ban.append(ikon(st[2]), el('span', '', st[0] + (u.catatan ? ' — Catatan verifikator: ' + u.catatan : (u.status === 'diajukan' ? ' — usulan Anda sedang menunggu verifikasi admin SIMPEG.' : ''))));
    box.append(ban);
    var dl = el('dl', 'rw-grid');
    [['Jenis', u.jenis_label], ['Aksi', (AKSI[u.aksi] || [u.aksi])[0]], ['Diajukan', tgl(u.diajukan_at)],
      ['Diverifikasi', u.diverifikasi_at ? tgl(u.diverifikasi_at) : '-'], ['Revisi', u.revisi > 0 ? 'ke-' + u.revisi : '-']]
      .forEach(function (p) { var d = el('div'); d.append(el('dt', '', p[0]), el('dd', '', p[1])); dl.append(d); });
    if (u.alasan) { var da = el('div'); da.style.gridColumn = '1/-1'; da.append(el('dt', '', u.aksi === 'hapus' ? 'Alasan penghapusan' : 'Keterangan'), el('dd', '', u.alasan)); dl.append(da); }
    box.append(dl);
    var fields = ((FORM[u.jenis] || {}).field || []);
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
    if (u.dok && u.dok.length) {
      box.append(el('div', 'rw-sec', 'Dokumen usulan'));
      var dk = el('div');
      u.dok.forEach(function (k) { dk.append(linkPdf(dokUsulan(u, k), labelDok(u.jenis, k))); });
      box.append(dk);
    }
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
      kaki.push(tombol('Batalkan usulan', '', 'bi-x-circle', function () { batalkan(u); }));
      if (iz[u.aksi]) {
        kaki.push(tombol(u.status === 'dikembalikan' ? 'Perbaiki usulan' : 'Edit usulan', 'rw-btn--primary', 'bi-pencil-square', function () {
          if (u.aksi === 'hapus') bukaHapus(null, u);
          else bukaForm(u.jenis, u.aksi, BARIS.filter(function (r) { return r.jenis === u.jenis && r.id_rw === u.ref_id; })[0] || null, u);
        }));
      }
    }
    kaki.push(tombol('Tutup', '', null, tutupModal));
    bukaModal('Usulan ' + (AKSI[u.aksi] || [u.aksi])[0].toLowerCase() + ' ' + String(u.jenis_label || '').toLowerCase(), box, kaki);
  }

  function batalkan(u) {
    var box = el('div');
    var i = el('div', 'rw-info rw-info--kuning'); i.append(ikon('bi-exclamation-triangle'), el('span', '', 'Usulan ini akan dibatalkan dan tidak diproses admin. Lanjutkan?'));
    box.append(i);
    var ya = tombol('Ya, batalkan', 'rw-btn--merah', 'bi-x-circle');
    ya.addEventListener('click', function () {
      var fd = fdBaru('batal'); fd.set('usulan_id', u.id);
      sibuk(true, ya, 'Membatalkan…');
      post(fd).then(function (j) { modalSibuk = false; toast(j.message, true); setTimeout(function () { muatUlang({ usulan: '1' }); }, 600); })
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
      var ringkas = u.data.nama || u.data.harga_nama || u.data.jurusan_nama || u.data.latihan_nama || u.data.no_sk || u.data.sk_nomor || u.data.tahun || u.data_lama.nama || u.data_lama.no_sk || '';
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
    tombolU.setAttribute('aria-expanded', buka ? 'true' : 'false');
    $('span', tombolU).textContent = buka ? 'Sembunyikan usulan' : 'Usulan';
    if (buka) renderUsulan();
  }
  if (CFG.bukaUsulan) togelUsulan(true);

  /* ---------- sync SIASN ---------- */
  function bukaSync() {
    var box = el('div');
    var inf = el('div', 'rw-info rw-info--biru');
    inf.append(ikon('bi-arrow-repeat'), el('span', '', 'Riwayat ' + String(CFG.judul || '').toLowerCase() + ' Anda akan ditarik dari SIASN BKN lalu disimpan ke SIMPEG, termasuk dokumennya. Riwayat yang tidak ada lagi di SIASN akan ikut terhapus dari SIMPEG.'));
    box.append(inf);
    var prog = el('div', 'rw-progress'); var bar = el('span'); prog.append(bar); prog.hidden = true;
    var langkah = el('ul', 'rw-langkah');
    box.append(prog, langkah);
    var mulai = tombol('Mulai sync', 'rw-btn--primary', 'bi-arrow-repeat');
    var tutup = tombol('Batal', '', null, tutupModal);
    var berubah = false;
    function baris(teks, st) {
      var li = el('li', st); li.append(el('b', '', { ok: '✓', gagal: '!', proses: '⏳' }[st] || '•'), el('span', '', teks));
      langkah.append(li); li.scrollIntoView({ block: 'nearest' }); return li;
    }
    function ubahBaris(li, teks, st) { li.className = st; $('b', li).textContent = { ok: '✓', gagal: '!', proses: '⏳' }[st]; $('span', li).textContent = teks; }
    mulai.addEventListener('click', function () {
      modalSibuk = true; mulai.hidden = true; tutup.disabled = true; prog.hidden = false; bar.style.width = '5%';
      var fd = fdBaru('sync_data'); fd.set('kategori', CFG.kategori);
      var l1 = baris('Tahap 1: menarik data dari SIASN…', 'proses');
      post(fd).then(function (j) {
        berubah = true;
        ubahBaris(l1, 'Tahap 1 selesai: ' + j.message, 'ok');
        var n = j.dokumen || 0, i = 0, gagal = 0;
        if (!n) { bar.style.width = '100%'; return { n: 0, gagal: 0 }; }
        baris(n + ' dokumen ditemukan' + (j.dokumen_baru !== undefined ? ' (' + j.dokumen_baru + ' baru)' : '') + '. Tahap 2: menarik dokumen…', 'ok');
        function lanjut() {
          if (i >= n) return Promise.resolve({ n: n, gagal: gagal });
          var li = baris('Dokumen ' + (i + 1) + ' dari ' + n + '…', 'proses');
          var f2 = fdBaru('sync_dok'); f2.set('kategori', CFG.kategori); f2.set('idx', String(i));
          return post(f2).then(function (r) { ubahBaris(li, r.message, 'ok'); }).catch(function (e) { gagal++; ubahBaris(li, 'Dokumen ' + (i + 1) + ': ' + e.message, 'gagal'); if (e.data && e.data.offline) i = n; })
            .then(function () { i++; bar.style.width = Math.round(10 + 90 * Math.min(i, n) / n) + '%'; return lanjut(); });
        }
        return lanjut();
      }).then(function (h) {
        $('#rw-modal-title').textContent = h.gagal ? 'Sync selesai dengan catatan' : 'Sync selesai';
        toast(h.gagal ? h.gagal + ' dokumen belum berhasil ditarik.' : 'Riwayat berhasil disinkronkan dari SIASN.', !h.gagal);
      }).catch(function (e) {
        ubahBaris(l1, e.message, 'gagal');
        $('#rw-modal-title').textContent = e.data && e.data.offline ? 'API BKN tidak terhubung' : 'Sync belum berhasil';
        bar.style.width = '100%';
        if (e.data && e.data.offline) cekKoneksi(true);
      }).then(function () {
        modalSibuk = false; tutup.disabled = false;
        $('span', tutup).textContent = 'Tutup';
        if (berubah) { tutup.replaceWith(tombol('Selesai', 'rw-btn--primary', 'bi-check2', function () { modalSibuk = false; muatUlang({ segar: '1' }); })); }
      });
    });
    bukaModal('Sync SIASN — ' + CFG.judul, box, [tutup, mulai]);
  }

  /* ---------- tombol halaman ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-rw]');
    if (!b || (modalEl && modalEl.contains(b))) return;
    var idx = b.dataset.idx !== undefined ? +b.dataset.idx : -1, r = BARIS[idx];
    switch (b.dataset.rw) {
      case 'lihat': lihat(idx); break;
      case 'ubah':
        if (r && r.usulan && usulanById(r.usulan)) lihatUsulan(usulanById(r.usulan)); else if (r) bukaForm(r.jenis, 'ubah', r, null);
        break;
      case 'hapus':
        if (r && r.usulan && usulanById(r.usulan)) lihatUsulan(usulanById(r.usulan)); else if (r) bukaHapus(r, null);
        break;
      case 'tambah': pilihTambah(); break;
      case 'usulan': togelUsulan(); break;
      case 'sync': bukaSync(); break;
    }
  });
})();
