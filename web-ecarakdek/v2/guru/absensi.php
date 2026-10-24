<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';

$page      = 'absensi';
$pageTitle = 'Absensi & Kehadiran';
require __DIR__ . '/includes/header.php';

$pid      = (int) $u['id'];
$periode  = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['periode'] ?? '')) ? $_GET['periode'] : date('Y-m');
$rekap    = rekap_bulan($pid, $periode);
$bulan    = presensi_bulan($pid, $periode);
$presensi = presensi_terakhir($pid, 10);
// [temanku] Riwayat dari rekap APEL (bulan terpilih, terbaru dulu): termasuk TAK, keterangan tidak sah / dikoreksi / libur jadwal.
if (!empty($rekap['rekap_apel'])) {
    $presensi = array_values(array_filter($bulan, static function ($p) { return isset($p['status']) && $p['status'] !== 'libur' || !empty($p['keterangan']); }));
    usort($presensi, static function ($a, $b) { return strcmp($b['tanggal'], $a['tanggal']); });
}
$hariIniInfo = $periode === date('Y-m') ? hari_ini_info() : null;
$aturanApel = apel_aturan();
$hariJadwal = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][(int) date('w')];
$jadwalHariIni = null;
foreach ($aturanApel['jadwal'] as $j) {
    if (($j['hari'] ?? '') === $hariJadwal) { $jadwalHariIni = $j; break; }
}
$hari     = (int) date('t', strtotime($periode . '-01'));
$bulanPanjang = explode(' ', tanggal_id($periode . '-01', true));
$judulBulan = ($bulanPanjang[2] ?? '') . ' ' . ($bulanPanjang[3] ?? '');
?>
<div class="page__head attendance-page__head">
  <div>
    <p><?= e(tanggal_id(date('Y-m-d'), true)) ?></p>
    <h1>Absensi &amp; Kehadiran</h1>
  </div>
  <div class="attendance-page__actions">
    <a class="btn btn--ghost" id="aksi-wfa" href="#aksi-wfa"><i class="bi bi-house-door-fill ico-text--biru"></i> Ajukan WFA</a>
    <a class="btn btn--ghost" id="aksi-izin" href="#aksi-izin"><i class="bi bi-envelope-paper-fill ico-text--kuning"></i> Ajukan Izin</a>
    <a class="btn btn--ghost" href="#kalender"><i class="bi bi-calendar-check-fill ico-text--hijau"></i> Rekap Bulanan</a>
    <a class="btn btn--ghost" id="aksi-laporan" href="#aksi-laporan"><i class="bi bi-journal-text ico-text--rose"></i> Laporan Kinerja</a>
    <?php /* [layanan-aksi] Ajukan WFA diganti pilihan FWA saat absen; Ajukan Izin -> Ajukan Cuti; + Ajukan Dinas & Claim Presensi */ ?>
    <a class="btn btn--ghost" href="<?= url('cuti.php') ?>"><i class="bi bi-calendar2-x-fill ico-text--kuning"></i> Ajukan Cuti</a>
    <a class="btn btn--ghost" href="<?= url('dinas.php') ?>"><i class="bi bi-briefcase-fill ico-text--biru"></i> Ajukan Dinas</a>
    <a class="btn btn--ghost" href="<?= url('claim.php') ?>"><i class="bi bi-patch-check-fill ico-text--hijau"></i> Claim Presensi</a>
    <style>#aksi-wfa,#aksi-izin{display:none!important}</style>
    <?php /* [/layanan-aksi] */ ?>
  </div>
</div>

<div class="attendance-page grid">

  <section class="hero attendance-page__clock-card">
    <span class="hero__sq" style="top:-30px;right:-20px;width:130px;height:130px;border-radius:30px;transform:rotate(18deg)"></span>
    <p class="hero__eyebrow">Waktu server (WITA)</p>
    <p class="clock" id="clock"><?= date('H:i:s') ?></p>
    <div class="geo" id="geo"><i class="bi bi-geo-alt-fill"></i> <span>Memeriksa GPS…</span></div>
    <form id="formAbsen" action="<?= url('absen_proses.php') ?>" method="post" class="attendance-page__form">
      <?= csrf_field() ?>
      <input type="hidden" name="latitude" id="gps-latitude"><input type="hidden" name="longitude" id="gps-longitude"><input type="hidden" name="accuracy_m" id="gps-accuracy">
      <button type="submit" name="aksi" value="masuk" disabled><i class="bi bi-fingerprint" aria-hidden="true"></i> Absen Masuk</button>
      <button type="submit" name="aksi" value="keluar" disabled>Absen Keluar</button>
    </form>
    <?php /* [absen-wajah] tombol Absen (wajah / lokasi sesuai metode unit) menggantikan form GPS bila saklar aktif */ require_once __DIR__ . '/app/fitur.php'; if (fitur_aktif('absen_wajah')): $pakaiWajah = temanku_metode_absen() === 'WAJAH'; ?>
    <?php /* satu tombol: masuk/pulang/WFA/upacara dipilih di halaman Absen; abu-abu bila sudah absen / libur / lewat jam */ ?>
    <a class="attendance-page__face" href="<?= url('absen.php') ?>" data-absen-tombol="segera"
       data-status="<?= e(url('wajah_api.php')) ?>?action=absen/status" data-csrf="<?= e(csrf_token()) ?>">
      <i class="bi <?= $pakaiWajah ? 'bi-person-bounding-box' : 'bi-geo-alt-fill' ?>"></i>
      <span class="absen-tombol__teks"><span data-absen-judul>Absen Sekarang</span><small data-absen-ket hidden></small></span>
    </a>
    <style>#formAbsen{display:none!important}.attendance-page__face{display:flex;align-items:center;justify-content:center;gap:10px;margin:14px 0 6px;padding:14px;border-radius:14px;background:#fff;color:#3e1825;font-weight:800;text-decoration:none}.attendance-page__face i{font-size:22px;color:#d94f7f}.attendance-page__face .absen-tombol__teks{display:flex;flex-direction:column;align-items:flex-start;line-height:1.2}.attendance-page__face small{font-size:11.5px;font-weight:600;color:#ad9099}.attendance-page__face.is-memuat{opacity:.8}.attendance-page__face.is-off{background:#e5e7eb;color:#6b7280;cursor:not-allowed}.attendance-page__face.is-off i{color:#9ca3af}</style>
    <script src="<?= e(url('assets/js/absen_tombol.js')) ?>?v=<?= (int) @filemtime(__DIR__ . '/assets/js/absen_tombol.js') ?>" defer></script>
    <?php endif; /* [/absen-wajah] */ ?>
    <?php if ($hariIniInfo): $ji = (string) ($hariIniInfo['jenis'] ?? ''); /* [temanku] jenis hari ini dari rekap APEL */ ?>
      <p class="attendance-page__hours" style="font-weight:700"><?php
        if ($ji === 'shift') echo 'Jadwal shift hari ini: ' . e((string) $hariIniInfo['jadwal_shift']);
        elseif (in_array($ji, ['libur', 'maintenance', 'belum_berlaku', 'cuti', 'dinas'], true)) echo e(($hariIniInfo['keterangan'] ?? '') ?: label_status($ji === 'belum_berlaku' ? 'belum_berlaku' : $ji)) . ' — tidak wajib absen hari ini';
      ?></p>
    <?php endif; ?>
    <?php /* jadwal reguler APEL; hari yang hanya ada di jadwal jenjang sekolah (mis. Sabtu sekolah 6 hari) dibaca tombol Absen dari mesin absen */ if ($jadwalHariIni): ?>
    <p class="attendance-page__hours">Masuk <?= e($jadwalHariIni['bmasuk']) ?>–<?= e($jadwalHariIni['tmasuk']) ?> · Pulang <?= e($jadwalHariIni['bpulang']) ?>–<?= e($jadwalHariIni['tpulang']) ?></p>
    <?php endif; ?>
  </section>

  <div class="attendance-page__gps-overlay" id="gps-overlay" role="alertdialog" aria-modal="true" aria-labelledby="gps-title">
    <div class="attendance-page__gps-dialog">
      <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
      <h2 id="gps-title">Aktifkan lokasi perangkat</h2>
      <p id="gps-help">Izinkan akses lokasi untuk melihat posisi Anda dan memakai presensi.</p>
      <button type="button" id="gps-retry">Coba aktifkan lokasi</button>
    </div>
  </div>

  <?php if (!$rekap['tersambung']): ?>
    <p class="card span-all">Data presensi APEL belum terhubung. Pastikan API Go dengan endpoint portal sudah berjalan.</p>
  <?php endif; ?>
  <section class="card attendance-page__today">
    <div class="card__band"><h2>Kehadiran hari ini</h2></div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
      <div class="stat ico--hijau"><p>Masuk</p><strong><?= e(jam($hariIni['jam_masuk'] ?? null)) ?></strong></div>
      <div class="stat ico--rose"><p>Status</p><strong><?= e($hariIni ? label_status($hariIni['status']) : '—') ?></strong></div>
      <div class="stat ico--abu"><p>Keluar</p><strong><?= e(jam($hariIni['jam_keluar'] ?? null)) ?></strong></div>
    </div>
    <div class="attendance-page__map" id="apel-map" role="img" aria-label="Peta titik presensi APEL"></div>
    <p class="attendance-page__map-caption" id="map-caption">Menentukan posisi terhadap area presensi…</p>
  </section>

  <section class="card attendance-page__recap">
    <div class="ring" style="background:conic-gradient(var(--rose) 0% <?= $rekap['persen'] ?>%, #f7e3e9 <?= $rekap['persen'] ?>% 100%)">
      <div class="ring__inner"><span class="ring__val"><?= $rekap['persen'] ?>%</span><span class="ring__cap">hadir</span></div>
    </div>
    <div>
      <p style="margin:0;font-size:13.5px;font-weight:800">Rekap <?= e(trim($judulBulan)) ?></p>
      <p style="margin:5px 0 0;font-size:12.5px;color:var(--muted);line-height:1.6">
        <?= $rekap['hadir'] ?> hadir &middot; <?= $rekap['wfa'] ?> WFA &middot; <?= $rekap['izin'] ?> izin &middot; <?= $rekap['libur'] ?> libur<br />
        <?= $rekap['hadir'] + $rekap['wfa'] ?> dari <?= $rekap['hari_kerja'] ?> hari kerja
        <?php if (!empty($rekap['rekap_apel'])): ?><br /><strong style="color:<?= $rekap['tak'] ? '#b4232c' : 'inherit' ?>">TAK <?= (int) $rekap['tak'] ?> hari</strong>
          &middot; terlambat <?= (int) $rekap['tl_menit'] ?> mnt &middot; pulang cepat <?= (int) $rekap['psw_menit'] ?> mnt
          <?php if ($rekap['potongan_persen'] !== null): ?>&middot; potongan <?= e(number_format((float) $rekap['potongan_persen'], 1, ',', '.')) ?>%<?php endif; ?><?php endif; ?>
      </p>
    </div>
  </section>

  <section class="card span-all attendance-page__calendar" id="kalender">
    <div class="card__band">
      <h2>Kalender Presensi &middot; <?= e(trim($judulBulan)) ?></h2>
      <div class="legend">
        <span><i style="background:var(--hijau)"></i>Hadir</span>
        <span><i style="background:var(--kuning)"></i>WFA</span>
        <span><i style="background:var(--biru)"></i>Izin</span>
        <span><i style="background:var(--merah)"></i>Libur</span>
        <span><i style="background:#b4232c"></i>TAK</span>
        <span><i style="background:#eccfd9"></i>Akhir pekan</span>
      </div>
    </div>
    <div class="kal">
      <?php for ($d = 1; $d <= $hari; $d++):
          $tgl  = sprintf('%s-%02d', $periode, $d);
          $p    = $bulan[$tgl] ?? null;
          $dow  = (int) date('N', strtotime($tgl));
          if ($p) {
              $k = in_array($p['status'], ['izin','sakit','cuti','dinas','lepas'], true) ? 'izin' : (in_array($p['status'], ['hadir','wfa','libur','tak'], true) ? $p['status'] : 'kosong');
          } elseif ($dow >= 6) {
              $k = 'pekan';
          } else {
              $k = 'kosong';
          }
      ?>
        <span class="kal__day kal__day--<?= $k ?>" title="<?= e(tanggal_id($tgl) . ($p ? ' · ' . label_status($p['status']) : '')) ?>"><?= $d ?></span>
      <?php endfor; ?>
    </div>
  </section>

  <section class="card span-all attendance-page__history">
    <div class="card__band"><h2>Riwayat Presensi</h2></div>
    <div class="rows">
      <div class="row row--head"><span>Tanggal</span><span>Masuk</span><span class="row__out">Keluar</span><span>Status</span></div>
      <?php foreach ($presensi as $p): ?>
        <div class="row">
          <strong><?= e(tanggal_id($p['tanggal'])) ?></strong>
          <span class="mono"><?= e(jam($p['jam_masuk'])) ?></span>
          <span class="mono row__out"><?= e(jam($p['jam_keluar'])) ?></span>
          <span><span class="<?= badge_status($p['status']) ?>"><?= e(label_status($p['status'])) ?></span></span>
        </div>
        <?php if (!empty($p['keterangan']) || !empty($p['telat_menit']) || !empty($p['psw_menit'])): ?>
          <div class="row" style="display:block;padding-top:0;font-size:12px;color:var(--muted)">
            <?= !empty($p['telat_menit']) ? 'Terlambat ' . (int) $p['telat_menit'] . ' mnt · ' : '' ?><?= !empty($p['psw_menit']) ? 'Pulang cepat ' . (int) $p['psw_menit'] . ' mnt · ' : '' ?><?= e((string) ($p['keterangan'] ?? '')) ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </section>

</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
<script>
(function () {
  var el = document.getElementById('clock');
  var serverMs = <?= (int) (time() * 1000) ?>;
  var startedAt = Date.now();
  var wita = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Makassar', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
  });
  function updateClock() { el.textContent = wita.format(new Date(serverMs + Date.now() - startedAt)); }
  updateClock();
  setInterval(updateClock, 1000);

  var geo = document.querySelector('#geo span');
  var locations = <?= json_encode($aturanApel['lokasi'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var caption = document.getElementById('map-caption');
  var overlay = document.getElementById('gps-overlay');
  var help = document.getElementById('gps-help');
  var buttons = document.querySelectorAll('#formAbsen button');
  var fences = [];
  var map = typeof L !== 'undefined' ? L.map('apel-map', {scrollWheelZoom:false}).setView([-6.11,120.46], 12) : null;
  var bounds = map ? L.latLngBounds([]) : null;
  function meters(a,b) {
    var r=Math.PI/180, dlat=(b[0]-a[0])*r, dlng=(b[1]-a[1])*r;
    var h=Math.sin(dlat/2)**2+Math.cos(a[0]*r)*Math.cos(b[0]*r)*Math.sin(dlng/2)**2;
    return 12742000*Math.atan2(Math.sqrt(h),Math.sqrt(1-h));
  }
  function insidePolygon(p,vs) {
    var inside=false;
    for(var i=0,j=vs.length-1;i<vs.length;j=i++) {
      if ((vs[i][0]>p[0])!==(vs[j][0]>p[0]) && p[1]<(vs[j][1]-vs[i][1])*(p[0]-vs[i][0])/(vs[j][0]-vs[i][0])+vs[i][1]) inside=!inside;
    }
    return inside;
  }
  function edgeDistance(p,a,b) {
    var k=111195, cos=Math.cos(p[0]*Math.PI/180);
    var ax=(a[1]-p[1])*k*cos, ay=(a[0]-p[0])*k, bx=(b[1]-p[1])*k*cos, by=(b[0]-p[0])*k;
    var t=Math.max(0,Math.min(1,-(ax*(bx-ax)+ay*(by-ay))/((bx-ax)**2+(by-ay)**2||1)));
    return Math.hypot(ax+t*(bx-ax),ay+t*(by-ay));
  }
  if (map) {
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
  }
  locations.forEach(function (place) {
    var lat=Number(place.lat),lng=Number(place.lng);
    if (!Number.isFinite(lat)||!Number.isFinite(lng)||Math.abs(lat)>90||Math.abs(lng)>180) return;
    var raw;
    try { raw=JSON.parse(place.polygon||''); } catch (_) { raw=null; }
    var points=Array.isArray(raw)?raw.map(function(p){return [Number(p.lat),Number(p.lng)];}):null;
    var polygon=points && points.length>=3 && points.every(function(p){return Number.isFinite(p[0])&&Number.isFinite(p[1])&&Math.abs(p[0])<=90&&Math.abs(p[1])<=180;});
    var radius=Number(place.radius), shape;
    var placeName=String(place.nama||'Lokasi presensi');
    var placeLabel=placeName;
    if (polygon) {
      placeLabel+=' · area polygon';
      fences.push({name:placeName,label:placeLabel,points:points});
      if (map) shape=L.polygon(points,{color:'#c74670',fillOpacity:.15}).addTo(map);
    } else if (!place.polygon && Number.isFinite(radius) && radius>0) {
      placeLabel+=' · radius '+Math.round(radius)+' m';
      fences.push({name:placeName,label:placeLabel,center:[lat,lng],radius:radius});
      if (map) shape=L.circle([lat,lng],{radius:radius,color:'#c74670',fillOpacity:.15}).addTo(map);
    }
    if (shape) { shape.bindPopup(placeLabel);bounds.extend(shape.getBounds()); }
  });
  if (map) {
    if (bounds.isValid()) map.fitBounds(bounds.pad(.2),{maxZoom:17});
    else caption.textContent='Lokasi presensi unit kerja belum tersedia di APEL.';
    setTimeout(function(){map.invalidateSize();},200);
  } else caption.textContent='Peta belum dapat dimuat. Muat ulang halaman untuk mencoba lagi.';
  var marker, firstFix=true, watchId=null, retryTimer=null;
  function showGpsError(message) {
    geo.textContent='GPS belum aktif';
    if (fences.length) caption.textContent='Lokasi perangkat belum tersedia.';
    buttons.forEach(function(btn){btn.disabled=true;});
    help.textContent=message;
    overlay.hidden=false;
  }
  function gpsSuccess(pos) {
    var c=pos.coords, point=[c.latitude,c.longitude];
    if (!Number.isFinite(c.latitude)||!Number.isFinite(c.longitude)) return;
    overlay.hidden=true;
    document.getElementById('gps-latitude').value=c.latitude;
    document.getElementById('gps-longitude').value=c.longitude;
    document.getElementById('gps-accuracy').value=Number.isFinite(c.accuracy)&&c.accuracy>0?c.accuracy:1;
    var nearest=Infinity, inside=null, closest=null;
    fences.forEach(function(f) {
      var d,ok;
      if (f.points) {
        ok=insidePolygon(point,f.points);
        d=Math.min.apply(null,f.points.map(function(a,i){return edgeDistance(point,a,f.points[(i+1)%f.points.length]);}));
      } else { d=meters(point,f.center)-f.radius;ok=d<=0;d=Math.abs(d); }
      if (ok && !inside) inside=f;
      if (!ok && d<nearest) {nearest=d;closest=f;}
    });
    geo.textContent=inside?inside.label:(closest?closest.label:'Lokasi presensi belum tersedia');
    if (inside) caption.textContent='Di dalam area presensi · '+inside.name;
    else if (closest) caption.textContent='Di luar area presensi · sekitar '+Math.round(nearest)+' m dari batas '+closest.name;
    else caption.textContent='Lokasi presensi unit kerja belum tersedia di APEL.';
    buttons.forEach(function(btn){btn.disabled=!inside;});
    if (map) {
      if (marker) map.removeLayer(marker);
      marker=L.circleMarker(point,{radius:9,color:'#fff',weight:3,fillColor:'#1761c0',fillOpacity:1}).addTo(map).bindPopup('Lokasi Anda');
      if (firstFix) {
        var view=bounds.isValid()?bounds.extend(point).pad(.15):L.latLngBounds([point,point]).pad(.2);
        map.fitBounds(view,{maxZoom:17});firstFix=false;
      }
    }
  }
  function gpsError(err) {
    var denied=err && err.code===1;
    showGpsError(denied?'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini melalui pengaturan browser dan nyalakan GPS, lalu coba lagi.':'Aktifkan GPS perangkat dan izinkan lokasi untuk situs ini, lalu coba lagi.');
    if (!denied && !retryTimer) retryTimer=setTimeout(function(){retryTimer=null;startGps();},12000);
  }
  function startGps() {
    if (!window.isSecureContext) {showGpsError('Lokasi browser memerlukan HTTPS. Buka situs melalui alamat HTTPS.');return;}
    if (!navigator.geolocation) {showGpsError('Browser atau perangkat tidak mendukung lokasi GPS.');return;}
    if (watchId!==null) navigator.geolocation.clearWatch(watchId);
    watchId=navigator.geolocation.watchPosition(gpsSuccess,gpsError,{enableHighAccuracy:true,timeout:15000,maximumAge:0});
  }
  document.getElementById('gps-retry').addEventListener('click',startGps);
  startGps();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
