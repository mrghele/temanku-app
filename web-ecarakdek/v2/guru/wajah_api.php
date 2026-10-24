<?php
declare(strict_types=1);
// Proxy BROWSER: browser -(HTTPS)-> PHP (sesi + CSRF) -> Go /portal/face/v2 -> Face Engine.
// Token service & token SSO tidak pernah dikirim ke JavaScript. Tidak ada template yang lewat sini.
require __DIR__ . '/app/bootstrap.php';   // validateAccessToken() + csrf_verify() (header X-CSRF-Token)
require __DIR__ . '/includes/face_upstream.php';
middleware('absensi.view');
require_post();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function face_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * Catatan kegagalan di PERANGKAT (yang tidak sampai ke Face Engine): hanya kode alasan,
 * pesan singkat, jenis perangkat, mode pendeteksi & lebar wajah (px). Tanpa gambar/data wajah.
 * File: storage/wajah_klien.log (folder storage diblokir dari web). Maks 30 catatan/sesi.
 */
function face_client_log(): void
{
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    $data = (is_string($raw) && strlen($raw) <= 4096) ? json_decode($raw, true) : null;
    if (!is_array($data)) face_fail(400, 'JSON tidak valid');
    $count = (int) ($_SESSION['face_client_log'] ?? 0);
    if ($count >= 30) { echo json_encode(['ok' => true]); return; }
    $_SESSION['face_client_log'] = $count + 1;
    $clean = static function ($value, int $max): string {
        $text = preg_replace('/[^\p{L}\p{N} ._:()\/,%+-]/u', '', (string) $value) ?? '';
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    };
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Android/i', $ua) ? 'Android'
        : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS X/i', $ua) ? 'Mac' : 'Lain')));
    $browser = preg_match('/SamsungBrowser/i', $ua) ? 'Samsung' : (preg_match('/MiuiBrowser|XiaoMi/i', $ua) ? 'Xiaomi'
        : (preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/CriOS|Chrome\//', $ua) ? 'Chrome'
        : (preg_match('/Firefox|FxiOS/', $ua) ? 'Firefox' : (preg_match('/Safari/', $ua) ? 'Safari' : 'Lain')))));
    $entry = [
        'waktu' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i:s'),
        'nip' => $clean($_SESSION['nip'] ?? '', 30),
        'tujuan' => $clean($data['tujuan'] ?? '', 12),
        'kode' => $clean($data['kode'] ?? 'lain', 30),
        'pesan' => $clean($data['pesan'] ?? '', 160),
        'perangkat' => $os . '/' . $browser,
        'mode' => $clean($data['mode'] ?? '', 4),
        'wajah_px' => max(0, min(5000, (int) ($data['wajah_px'] ?? 0))),
    ];
    $folder = __DIR__ . '/storage';
    if (!is_dir($folder)) @mkdir($folder, 0750, true);
    $file = $folder . '/wajah_klien.log';
    if (is_file($file) && filesize($file) > 2 * 1024 * 1024) @rename($file, $file . '.1');   // putar bila > 2 MB
    @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => true]);
}

$action = (string) ($_GET['action'] ?? '');
if ($action === 'catat') { face_client_log(); exit; }
if ($action === 'foto-sementara') {   // foto sementara dari rekam wajah (hanya bila belum ada foto SIMPEG)
    require_once __DIR__ . '/app/foto_sementara.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $nip = preg_replace('/\D/', '', (string) ($_SESSION['nip'] ?? ''));
    if (trim((string) ((user() ?: [])['foto'] ?? '')) !== '') { echo json_encode(['saved' => false, 'alasan' => 'Sudah ada foto SIMPEG']); exit; }
    $raw = (string) file_get_contents('php://input', false, null, 0, 1200000);
    $body = json_decode($raw, true);
    $jpeg = is_array($body) && is_string($body['jpeg'] ?? null) ? base64_decode($body['jpeg'], true) : false;
    echo json_encode(['saved' => is_string($jpeg) && foto_sementara_simpan($nip, $jpeg)]);
    exit;
}
if (!in_array($action, ['enroll/start', 'challenge', 'enroll/complete', 'verify', 'status', 'photo', 'simulasi/masuk', 'simulasi/pulang', 'absen/status', 'absen/masuk', 'absen/pulang', 'absen/wfa', 'absen/upacara', 'absen/dinas', 'absen/dinasluar', 'absen/laporan'], true)) face_fail(404, 'Endpoint tidak tersedia');
$base = face_upstream_base('ecarakdek');
if ($base === null) face_fail(503, 'Layanan wajah belum dikonfigurasi dengan koneksi yang diizinkan. Hubungi admin.');

set_time_limit(150);
$limit = 20 * 1024 * 1024;
$body = file_get_contents('php://input', false, null, 0, $limit + 1);
if (!is_string($body) || strlen($body) > $limit) face_fail(413, 'Permintaan terlalu besar');
if (!is_array(json_decode($body, true))) face_fail(400, 'JSON tidak valid');

$serviceToken = api_service_token();
$ssoToken = (string) ($_SESSION['access_token'] ?? '');
if (!$serviceToken || $ssoToken === '') face_fail(401, 'Silakan masuk kembali');
session_write_close();   // lepas kunci sesi selama proses wajah

face_upstream_forward($base . '/portal/face/v2/' . $action, $body,
    ['Authorization: Bearer ' . $serviceToken, 'Auth: Bearer ' . $ssoToken]);
