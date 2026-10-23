<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';
require __DIR__ . '/includes/riwayat_asn.php';
middleware('riwayat.view');

/*
 * Buka dokumen riwayat (PDF) milik pegawai yang login.
 * Path berkas tidak pernah diterima dari browser: dicari dari riwayat pegawai sendiri
 * (kategori + id riwayat + kolom berkas), sehingga dokumen pegawai lain tidak bisa dibuka.
 */
function riwayat_dok_gagal(int $kode, string $pesan): void
{
    http_response_code($kode);
    header('Content-Type: text/plain; charset=utf-8');
    exit($pesan);
}

$kategori = is_string($_GET['kategori'] ?? null) ? $_GET['kategori'] : '';
$id       = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$kolom    = is_string($_GET['berkas'] ?? null) ? $_GET['berkas'] : '';

$pegawai = riwayat_pegawai();
if (!$pegawai || !isset(riwayat_kategori_daftar($pegawai)[$kategori])) riwayat_dok_gagal(404, 'Dokumen tidak ditemukan.');

$path = null;
foreach (riwayat_data($pegawai, $kategori) ?? [] as $row) {
    if ($id !== '' && hash_equals($row['id_rw'], $id) && isset($row['berkas'][$kolom])) {
        $path = $row['berkas'][$kolom];
        break;
    }
}
if ($path === null) riwayat_dok_gagal(404, 'Dokumen tidak ditemukan.');

$serviceToken = api_service_token();
$accessToken  = (string)($_SESSION['access_token'] ?? '');
session_write_close();
if (!$serviceToken) riwayat_dok_gagal(502, 'Layanan dokumen sedang tidak tersedia.');

$headers = ['Accept: application/pdf', 'Authorization: Bearer ' . $serviceToken];
if ($accessToken !== '') $headers[] = 'Auth: Bearer ' . $accessToken;
$ch = curl_init(rtrim(API_TEMANKU, '/') . '/download-dok?' . http_build_query(['filePath' => $path]));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$isi  = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if (!is_string($isi) || $http !== 200 || strncmp($isi, '%PDF', 4) !== 0) {
    riwayat_dok_gagal(502, 'Dokumen belum dapat diambil dari repository. Silakan coba lagi.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($isi));
header('Content-Disposition: inline; filename="riwayat-' . $kategori . '-' . $kolom . '.pdf"');
echo $isi;
