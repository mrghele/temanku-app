<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/session.php';
start_app_session();
header('Cache-Control: no-store, max-age=0');

if (!isset($_GET['state'], $_SESSION['oauth_state']) || !is_string($_GET['state'])
    || !hash_equals((string)$_SESSION['oauth_state'], $_GET['state'])) {
    http_response_code(400); exit('OAuth state tidak valid. Silakan login ulang.');
}
unset($_SESSION['oauth_state']);

$code = $_GET['code'] ?? null;
if (!is_string($code) || $code === '' || strlen($code) > 4096) {
    http_response_code(400); exit('Authorization code tidak valid.');
}

$ch = curl_init(SSO_BASE_URL . '/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => SSO_CLIENT_ID,
        'client_secret' => SSO_CLIENT_SECRET,
        'code' => $code,
        'redirect_uri' => SSO_REDIRECT_URI,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$json = is_string($response) ? json_decode($response, true) : null;

if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['access_token'])) {
    http_response_code(502); exit('Gagal mengambil token SSO.');
}

session_regenerate_id(true);
$_SESSION['initiated'] = true;
$_SESSION['fingerprint'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$_SESSION['access_token'] = $json['access_token'];
$_SESSION['refresh_token'] = $json['refresh_token'] ?? null;
$_SESSION['expires_at'] = time() + (int)($json['expires_in'] ?? 300);

// [temanku] Guru/tendik & admin sekolah hanya memakai eCarakdek (aturan Go pref.IsModuleAllowed, modul temanku).
// 403 kode "sekolah" -> sesi dihapus. Go tidak terjangkau -> login diteruskan (endpoint pegawai Go tetap menolak akun sekolah).
// rest.config.php dijaga APP_INTERNAL (biasanya didefinisikan app/bootstrap.php). Tanpa konstanta ini callback berhenti
// dengan "Forbidden" setelah token tersimpan, sehingga login selalu tampak gagal dulu dan cek akun sekolah tidak pernah jalan.
if (!defined('APP_INTERNAL')) define('APP_INTERNAL', true);
require_once __DIR__ . '/app/rest.config.php';
if (temanku_akses_ditolak_sekolah()) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['login_error'] = 'Akun sekolah tidak menggunakan TEMANKU. Silakan gunakan aplikasi eCarakdek.';
    header('Location: ' . rtrim(BASE_URL, '/') . '/login.php');
    exit;
}

header('Location: ' . rtrim(BASE_URL, '/') . '/');
exit;

function temanku_akses_ditolak_sekolah(): bool
{
    $token = api_service_token();
    if (!$token) return false;
    $ch = api_curl();
    curl_setopt($ch, CURLOPT_URL, rtrim(API_TEMANKU, '/') . '/portal/akses');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Authorization: Bearer ' . $token, 'Auth: Bearer ' . $_SESSION['access_token']]);
    $res = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http !== 403 || !is_string($res)) return false;
    $j = json_decode($res, true);
    return is_array($j) && ($j['kode'] ?? '') === 'sekolah';
}
