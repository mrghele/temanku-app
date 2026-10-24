/*
 * Tombol "Absen Sekarang" (menu Presensi & Kehadiran dan halaman Absensi).
 * Status diambil dari Go absen/status (jadwal saja, tanpa GPS): tombol abu-abu bila sudah absen masuk
 * (kantor / WFA / upacara), hari libur atau akhir pekan, belum waktunya, atau lewat jam absen.
 * Pemeriksaan lokasi & absen sebenarnya tetap di halaman Absen.
 */
(function () {
  'use strict';
  if (window.absenTombolSiap) return;   // dimuat menu presensi & halaman Absensi
  window.absenTombolSiap = true;
  var tombol = Array.prototype.slice.call(document.querySelectorAll('[data-absen-tombol]'));
  if (!tombol.length || !window.fetch) return;
  var janji = null;

  function ambil(el) {
    if (!janji) {
      janji = fetch(el.dataset.status, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': el.dataset.csrf, Accept: 'application/json' },
        body: JSON.stringify({ location_error: 'Lokasi belum dibaca (menu presensi)' })
      }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
      // status dibaca ulang paling cepat 60 detik kemudian (mis. menu dibuka lagi)
      janji.then(function () { setTimeout(function () { janji = null; }, 60000); });
    }
    return janji;
  }

  function jamDari(teks) { var m = /(\d{1,2}[:.]\d{2})/.exec(teks || ''); return m ? m[1] : ''; }

  /** Ringkasan status hari ini: {aktif, judul, ket}. null = tidak dapat diputuskan (tombol dibiarkan aktif). */
  function ringkas(d) {
    if (!d || !Array.isArray(d.opsi)) return null;
    var opsi = d.opsi;
    if (opsi.length && !opsi.some(function (o) { return 'jadwal_aktif' in o; })) return null;   // API Go versi lama
    var aktif = opsi.filter(function (o) { return o.jadwal_aktif; });
    var hariIni = (d.catatan || []).filter(function (r) { return r.tanggal_dinas === d.tanggal; });
    var masuk = hariIni.filter(function (r) { return !r.tanpa_masuk; })[0];
    var bukaPulang = opsi.filter(function (o) { return o.aksi === 'pulang' && /^Dibuka/.test(o.alasan || ''); })[0];
    if (aktif.length) {
      var pulangSaja = aktif.every(function (o) { return o.aksi === 'pulang'; });
      var upacara = aktif.filter(function (o) { return o.aksi === 'upacara'; })[0];
      // lupa absen masuk: saat jam pulang dibuka tombol tetap aktif sebagai Absen Pulang
      var lupa = aktif.some(function (o) { return o.aksi === 'pulang' && o.mode === 'lupa'; });
      return { aktif: true, judul: pulangSaja ? 'Absen Pulang' : 'Absen Sekarang',
        ket: masuk && pulangSaja ? 'Masuk pukul ' + masuk.masuk : (pulangSaja && lupa ? 'Belum absen masuk hari ini' : (upacara ? upacara.label : '')) };
    }
    if (masuk) {
      var jenis = { wfa: ' (WFA)', dinas: ' (dinas)' }[masuk.jenis] || '';
      if (masuk.pulang) return { aktif: false, judul: 'Sudah absen hari ini', ket: 'Masuk ' + masuk.masuk + ' · pulang ' + masuk.pulang };
      return { aktif: false, judul: masuk.jenis === 'upacara' ? 'Sudah absen upacara' : 'Sudah absen masuk' + jenis,
        ket: 'Pukul ' + masuk.masuk + (bukaPulang ? ' · absen pulang dibuka pukul ' + jamDari(bukaPulang.alasan) : '') };
    }
    if (d.absen_lain && d.absen_lain.pesan) return { aktif: false, judul: d.absen_lain.pesan, ket: d.absen_lain.keterangan || '' };
    if (d.libur) return { aktif: false, judul: 'Hari libur', ket: d.libur_nama || '' };
    if (d.hari === 'Sabtu' || d.hari === 'Minggu') return { aktif: false, judul: 'Hari libur', ket: 'Akhir pekan (' + d.hari + ')' };
    var alasan = (opsi.filter(function (o) { return o.aksi !== 'pulang'; })[0] || opsi[0] || {}).alasan || '';
    if (/^Dibuka/.test(alasan)) return { aktif: false, judul: 'Belum waktunya absen', ket: alasan };
    if (/^Ditutup/.test(alasan)) return { aktif: false, judul: 'Lewat jam absen masuk', ket: alasan + ' · absen pulang tetap bisa saat jam pulang dibuka' };
    return { aktif: false, judul: 'Belum bisa absen', ket: alasan || 'Tidak ada jadwal absen hari ini' };
  }

  function terapkan(el, s) {
    var judul = el.querySelector('[data-absen-judul]'), ket = el.querySelector('[data-absen-ket]');
    el.classList.remove('is-memuat');
    if (!s) return;
    el.classList.toggle('is-off', !s.aktif);
    if (s.aktif) { el.removeAttribute('aria-disabled'); el.removeAttribute('tabindex'); }
    else { el.setAttribute('aria-disabled', 'true'); el.setAttribute('tabindex', '-1'); }
    if (judul) judul.textContent = s.judul;
    if (ket) { ket.textContent = s.ket || ''; ket.hidden = !s.ket; }
  }

  function muat(el) {
    el.classList.add('is-memuat');
    ambil(el).then(function (d) { terapkan(el, ringkas(d)); });
  }

  tombol.forEach(function (el) {
    el.addEventListener('click', function (e) { if (el.classList.contains('is-off')) e.preventDefault(); });
    if (el.dataset.absenTombol === 'segera') muat(el);
  });
  // Menu presensi (HP): status dibaca saat menu dibuka agar tidak memanggil API di setiap halaman.
  var fab = document.querySelector('.bottomnav__fab');
  if (fab) fab.addEventListener('click', function () {
    tombol.forEach(function (el) { if (el.dataset.absenTombol === 'menu') muat(el); });
  });
}());
