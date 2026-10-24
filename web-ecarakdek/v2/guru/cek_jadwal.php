<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';
middleware('absensi.view');

/*
 * Pemeriksaan hari kerja guru/tendik (data diri sendiri): rantai penentu hari kerja dari API Go
 * GET /portal/cek-hari-kerja - unit absen, jenjang sekolah, pola kerja, jadwal jenjang hari ini,
 * dan unit/pola yang dipakai rekap. Dipakai untuk menelusuri mengapa suatu hari dianggap libur/akhir pekan.
 */
$cek = temanku_get('/portal/cek-hari-kerja');
$pageTitle = 'Cek Jadwal';
$page = 'absensi';
require __DIR__ . '/includes/header.php';

$ya = static function ($v): string { return $v ? '<b style="color:#14663a">ya</b>' : '<b style="color:#b4232c">tidak</b>'; };
$pola = static function ($list): string {
    if (!is_array($list) || !$list) return '<b style="color:#b4232c">tidak ada</b>';
    return e(implode(', ', array_map(static function ($p) { return ($p['kode'] ?? '?') . ' (' . (int)($p['hari_kerja'] ?? 0) . ' hari' . (empty($p['reguler']) ? ', non-reguler' : '') . ')'; }, $list)));
};
?>
<style>
.cj{max-width:760px;margin:24px auto 100px;padding:0 16px}.cj h1{font-size:20px;margin:0 0 4px}
.cj__card{background:#fff;border-radius:18px;padding:18px;margin-bottom:14px;box-shadow:0 6px 24px #5a10300f}
.cj table{width:100%;border-collapse:collapse;font-size:13.5px}.cj td{padding:7px 4px;border-top:1px solid #f6e3ea;vertical-align:top}
.cj td:first-child{color:#8a6b75;width:42%}.cj tr:first-child td{border-top:0}.cj h2{font-size:15px;margin:0 0 8px}
.cj pre{white-space:pre-wrap;font-size:12px;background:#fdf4f7;border-radius:10px;padding:10px;margin:0}
</style>
<div class="cj">
  <h1>Cek jadwal hari kerja</h1>
  <p style="color:#8a6b75;font-size:13.5px;margin:0 0 14px">Rantai penentu hari kerja Anda hari ini (dari server absensi).</p>
  <?php if (!$cek): ?>
    <div class="cj__card"><b>Pemeriksaan tidak tersedia.</b> Server absensi belum memakai versi terbaru (rute <code>/portal/cek-hari-kerja</code> tidak ditemukan) atau tidak dapat dihubungi.</div>
  <?php else: $a = (array)($cek['absen'] ?? []); $r = (array)($cek['rekap'] ?? []); $j = (array)($a['jadwal_hari_ini'] ?? []); ?>
  <div class="cj__card"><table>
    <tr><td>Versi server</td><td><?= e((string)($cek['build'] ?? '-')) ?></td></tr>
    <tr><td>Hari</td><td><?= e((string)($cek['hari'] ?? '-')) ?></td></tr>
    <tr><td>Pegawai modul e-Carakdek</td><td><?= $ya(!empty($cek['modul_ecarakdek'])) ?></td></tr>
    <tr><td>Ada jadwal jenjang sekolah</td><td><?= $ya(!empty($cek['ada_jadwal_sekolah'])) ?></td></tr>
  </table></div>
  <div class="cj__card"><h2>Absen (tombol Absen)</h2><table>
    <tr><td>Unit</td><td><?= e((string)($a['unit_nama'] ?? '')) ?> <small>(<?= e((string)($a['unit'] ?? '')) ?>)</small></td></tr>
    <tr><td>Jenjang terbaca</td><td><?= ($a['jenjang'] ?? '') !== '' ? e((string)$a['jenjang']) : '<b style="color:#b4232c">tidak terbaca</b>' ?></td></tr>
    <tr><td>Jadwal jenjang</td><td><?= e(implode(', ', (array)($a['jadwal_jenjang'] ?? [])) ?: '-') ?></td></tr>
    <tr><td>Hari kerja dari jadwal jenjang</td><td><?= (int)($a['hari_kerja_jenjang'] ?? 0) ?: '-' ?></td></tr>
    <tr><td>Pola kerja</td><td><?= $pola($a['pola'] ?? null) ?></td></tr>
    <tr><td>Jadwal masuk hari ini</td><td><?= !empty($j['ada']) ? e($j['buka'] . ' – ' . $j['tutup']) : '<b style="color:#b4232c">tidak ada</b>' ?></td></tr>
  </table></div>
  <div class="cj__card"><h2>Rekap (kalender & TAK)</h2><table>
    <tr><td>Unit rekap</td><td><?= e((string)($r['unit_nama'] ?? '')) ?> <small>(<?= e((string)($r['unit'] ?? '-')) ?>)</small></td></tr>
    <tr><td>Subunit SIMPEG</td><td><?= e((string)($r['subunit'] ?? '-')) ?></td></tr>
    <tr><td>Jenjang terbaca</td><td><?= ($r['jenjang'] ?? '') !== '' ? e((string)$r['jenjang']) : '<b style="color:#b4232c">tidak terbaca</b>' ?></td></tr>
    <tr><td>Pola kerja rekap</td><td><?= $pola($r['pola_konteks'] ?? ($r['pola'] ?? null)) ?></td></tr>
  </table></div>
  <div class="cj__card"><h2>Data lengkap</h2><pre><?= e(json_encode($cek, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
