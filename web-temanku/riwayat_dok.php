<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';
require __DIR__ . '/includes/riwayat_asn.php';
require __DIR__ . '/includes/riwayat_usulan.php';
middleware('riwayat.view');

/*
 * Buka dokumen riwayat / usulan (PDF) milik pegawai yang login (ditampilkan di viewer pdf.js halaman Riwayat).
 * Path berkas tidak pernah diterima dari browser: dicari dari riwayat pegawai sendiri
 * (kategori + id riwayat + kolom berkas) atau dari usulan miliknya (usulan + kolom berkas),
 * sehingga dokumen pegawai lain tidak bisa dibuka.
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
$usulanId = is_string($_GET['usulan'] ?? null) ? $_GET['usulan'] : '';

$pegawai = riwayat_pegawai();
if (!$pegawai) riwayat_dok_gagal(404, 'Dokumen tidak ditemukan.');

$path = null;
if ($usulanId !== '') {
    // Dokumen unggahan usulan: hanya usulan milik sendiri dan kolom berkas jenis tersebut.
    $u = riwayat_usulan_milik($pegawai, $usulanId);
    $kolomDok = $u ? array_column(riwayat_form_daftar()[(string)($u['jenis'] ?? '')]['dok'] ?? [], 0) : [];
    $nilai = $u && in_array($kolom, $kolomDok, true) ? (string)(riwayat_json_objek($u['data'] ?? null)[$kolom] ?? '') : '';
    if (preg_match(RIWAYAT_POLA_REPO, $nilai)) $path = $nilai;
    $kategori = 'usulan';
} else {
    if (!isset(riwayat_kategori_daftar($pegawai)[$kategori])) riwayat_dok_gagal(404, 'Dokumen tidak ditemukan.');
    foreach (riwayat_data($pegawai, $kategori) ?? [] as $row) {
        if ($id !== '' && hash_equals($row['id_rw'], $id) && isset($row['berkas'][$kolom])) {
            $path = $row['berkas'][$kolom];
            break;
        }
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
header('Content-Disposition: inline; filename="riwayat-' . preg_replace('/[^a-z_]/', '', $kategori) . '-' . preg_replace('/[^a-z_]/', '', $kolom) . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
echo $isi;
