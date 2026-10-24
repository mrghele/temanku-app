<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';
middleware('absensi.create');
require_post();
// [absen-wajah] Bila absen wajah aktif (saklar di fitur.php), absen GPS tanpa wajah ditolak.
require_once __DIR__ . '/app/fitur.php';
if (fitur_aktif('absen_wajah')) { flash('Absen kini wajib dengan verifikasi wajah.', 'bahaya'); redirect('absen.php'); }
// [/absen-wajah]
$action = (string)($_POST['aksi'] ?? '');
$endpoint = ['masuk'=>'check-in', 'keluar'=>'check-out'][$action] ?? null;
$lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$accuracy = filter_var($_POST['accuracy_m'] ?? null, FILTER_VALIDATE_FLOAT);
if (!$endpoint || $lat === false || $lng === false || $accuracy === false || abs($lat)>90 || abs($lng)>180 || $accuracy<=0) {
    flash('Lokasi GPS belum tersedia. Aktifkan lokasi dan coba lagi.', 'error');
    redirect('absensi.php');
}
$result = temanku_post('/portal/attendance/' . $endpoint, ['latitude'=>$lat,'longitude'=>$lng,'accuracy_m'=>$accuracy]);
flash($result ? (string)($result['message'] ?? 'Presensi tercatat di APEL.') : 'Presensi belum berhasil. Periksa titik dan jadwal APEL, lalu coba lagi.', $result ? 'success' : 'error');
redirect('absensi.php');
