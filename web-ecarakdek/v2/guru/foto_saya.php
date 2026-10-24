<?php
declare(strict_types=1);
/*
 * Foto SIMPEG milik pegawai YANG SEDANG LOGIN, diteruskan apa adanya dari Go /download-dok
 * (cara yang sama dengan SIMPEG asn/pns.foto.php). Path diambil dari profil sesi — TIDAK ada
 * parameter path di alamat, sehingga tidak bisa dipakai untuk melihat foto pegawai lain.
 * Dipakai avatar header/Beranda/Profil dan panel "Foto SIMPEG" di Perekaman Wajah.
 */
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/foto_sementara.php';
$u = require_login();
$nipSaya = preg_replace('/\D/', '', (string) ($_SESSION['nip'] ?? ((user() ?: $u)['nip'] ?? '')));

$path = str_replace('\\', '/', trim((string) ((user() ?: $u)['foto'] ?? '')));
$serviceToken = api_service_token();
$ssoToken = (string) ($_SESSION['access_token'] ?? '');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // jangan kunci sesi selama mengunduh

function foto_saya_gagal(int $code): void
{
    // Foto SIMPEG belum ada / tidak terambil: pakai foto sementara dari rekam wajah (bila ada).
    global $nipSaya;
    if (foto_sementara_ada((string) $nipSaya)) {
        $file = foto_sementara_path((string) $nipSaya);
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline');
        header('Cache-Control: private, no-cache');
        header('X-Foto-Sementara: 1');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
    http_response_code($code);
    header('Cache-Control: no-store');
    exit;
}

if ($path === '' || !preg_match('#^foto/[A-Za-z0-9._-]+\.(?:png|jpe?g|webp)$#i', $path) || strpos($path, '..') !== false) {
    foto_saya_gagal(404);
}
if (!defined('API_SIMPEG') || !$serviceToken || $ssoToken === '') foto_saya_gagal(503);

// Path berubah setiap foto diperbarui, jadi aman disimpan browser selama sejam.
$etag = '"' . substr(hash('sha256', $path), 0, 20) . '"';
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

$body = '';
$limit = 5 * 1024 * 1024;
$ch = curl_init(rtrim(API_SIMPEG, '/') . '/download-dok?filePath=' . rawurlencode($path));
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $serviceToken, 'Auth: Bearer ' . $ssoToken, 'Accept: image/*'],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
        $body .= $chunk;
        return strlen($body) > $limit ? 0 : strlen($chunk);
    },
]);
curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($status < 200 || $status >= 300 || $body === '' || strlen($body) > $limit) {
    error_log('TEMANKU foto_saya: gagal mengambil foto (HTTP ' . $status . ')');
    foto_saya_gagal(404);
}

// Jenis gambar dari isi file, bukan dari server (cegah konten selain gambar).
$type = strncmp($body, "\xFF\xD8\xFF", 3) === 0 ? 'image/jpeg'
    : (strncmp($body, "\x89PNG\r\n\x1A\n", 8) === 0 ? 'image/png'
    : ((substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP') ? 'image/webp' : ''));
if ($type === '') foto_saya_gagal(404);

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($body));
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=3600');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
echo $body;
