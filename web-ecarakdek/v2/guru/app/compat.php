<?php
declare(strict_types=1);
/*
 * Lapisan kompatibel TEMANKU -> e-Carakdek.
 * Halaman portal guru adalah salinan halaman TEMANKU; fungsi yang dipakainya disediakan di sini
 * dengan sumber e-Carakdek: sesi & peran dari /asn/me (app/auth.php), token servis $tokenlogin,
 * dan API Go /ecarakdek/api/v1 (rute portal guru: absensi/ecarakdek_portal.go).
 */
if (!defined('APP_INTERNAL')) { http_response_code(403); exit('Forbidden'); }

if (!defined('APP_NAME')) define('APP_NAME', 'eCarakdek');
if (!defined('APP_ORG'))  define('APP_ORG', 'BKPSDM Kep. Selayar');
if (!defined('BASE_URL')) define('BASE_URL', rtrim(APP_URL, '/') . '/guru');
// Semua data portal guru lewat API web e-Carakdek (handler TEMANKU yang sama, cakupan guru sendiri).
if (!defined('API_TEMANKU')) define('API_TEMANKU', API_SIMPEG);
// Jalur wajah (wajah_api.php) hanya mau HTTPS/loopback, atau host HTTP yang disebut eksplisit.
// e-Carakdek memanggil API Go yang sama lewat HTTP, jadi host itu yang diizinkan (bisa diganti di config).
if (!defined('FACE_INSECURE_HTTP_HOST')) {
    $guruApi = parse_url(API_SIMPEG);
    define('FACE_INSECURE_HTTP_HOST', (string)simpeg_config('api.face_insecure_host',
        strtolower((string)($guruApi['scheme'] ?? '')) === 'http' ? (string)($guruApi['host'] ?? '') : ''));
    unset($guruApi);
}

// Identitas (agama, alamat, kontak, ...) hanya dapat diubah admin sekolah di e-Carakdek (API Go: PATCH khusus admin).
if (!defined('RIWAYAT_UBAH_IDENTITAS')) define('RIWAYAT_UBAH_IDENTITAS', false);

/** Akun yang boleh memakai portal guru: guru/tendik (pegawai) & admin sekolah yang juga ASN. */
function guru_boleh(): bool
{
    return !empty($_SESSION['auth_ok'])
        && in_array($_SESSION['role'] ?? '', ['pegawai', 'admin_opd'], true)
        && valid_nip((string)($_SESSION['nip'] ?? ''))
        && valid_pns_id((string)($_SESSION['pns_id'] ?? ''));
}

function forbid(): void
{
    if (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => 'Anda tidak memiliki akses']));
    }
    simpeg_error_page(403);
}

/** Semua menu portal milik guru itu sendiri; tidak ada menu admin di sini. */
function can(string $permission): bool
{
    return guru_boleh();
}

function allow(string $permission): void
{
    if (!can($permission)) forbid();
}

function middleware(?string $permission = null): void
{
    allow($permission ?? 'app.access');
}

function csrf_check(?string $token): bool
{
    return is_string($token) && isset($_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], $token);
}

/* ---------------- API Go ---------------- */

function api_service_token(): ?string
{
    $t = (string)($GLOBALS['tokenlogin'] ?? '');
    return $t !== '' ? $t : null;
}

/**
 * Request REST generik: Authorization = token servis, Auth = token SSO user (pola SIMPEG/e-Carakdek).
 * null bila gagal / bukan 2xx.
 */
function api_request(string $base, string $method, string $endpoint, ?array $data = null): ?array
{
    $serviceToken = api_service_token();
    if (!$serviceToken) return null;
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $serviceToken];
    if (!empty($_SESSION['access_token'])) $headers[] = 'Auth: Bearer ' . $_SESSION['access_token'];
    $ch = curl_init(rtrim($base, '/') . '/' . ltrim($endpoint, '/'));
    $opt = [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($data !== null) {
        $opt[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    $opt[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opt + simpeg_curl_tls_opts());
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($res) || $http < 200 || $http >= 300) return null;
    $json = json_decode($res, true);
    return is_array($json) ? $json : null;
}

function simpeg_get(string $endpoint): ?array { return api_request(API_SIMPEG, 'GET', $endpoint); }
function temanku_get(string $endpoint): ?array { return api_request(API_TEMANKU, 'GET', $endpoint); }
function temanku_post(string $endpoint, array $data = []): ?array { return api_request(API_TEMANKU, 'POST', $endpoint, $data); }
function temanku_put(string $endpoint, array $data = []): ?array { return api_request(API_TEMANKU, 'PUT', $endpoint, $data); }
function temanku_delete(string $endpoint): ?array { return api_request(API_TEMANKU, 'DELETE', $endpoint); }

function api_rows(?array $response): array
{
    if (!$response) return [];
    foreach (['data', 'rows', 'items', 'result'] as $key) {
        if (isset($response[$key]) && is_array($response[$key])) return $response[$key];
    }
    return is_list_array($response) ? $response : [];
}

/* ---------------- Profil pegawai (format TEMANKU) ---------------- */

function normalize_api_user(array $raw, string $username): array
{
    if (isset($raw['data']) && is_array($raw['data']) && !is_list_array($raw['data'])) $raw = $raw['data'];
    return [
        'id'          => (int)($raw['id'] ?? 0),
        'nip'         => (string)($raw['nip'] ?? ($_SESSION['nip'] ?? $username)),
        'nama'        => (string)($raw['nama'] ?? $raw['nama_lengkap'] ?? ($_SESSION['nama'] ?? $username)),
        'jabatan'     => (string)($raw['jabatan'] ?? $raw['nama_jabatan'] ?? '-'),
        'unit_kerja'  => (string)($raw['unit_kerja'] ?? ($_SESSION['unit_nama'] ?? '-')),
        'foto'        => (string)($raw['foto'] ?? $raw['photo'] ?? ''),
        'golongan'    => (string)($raw['golongan'] ?? $raw['pangkat_golongan'] ?? '-'),
        'status_asn'  => (string)($raw['status_asn'] ?? $raw['status'] ?? 'ASN'),
        'tmt_kgb'     => (string)($raw['tmt_kgb'] ?? ''),
        'tmt_pangkat' => (string)($raw['tmt_pangkat'] ?? ''),
        'tgl_pensiun' => (string)($raw['tgl_pensiun'] ?? $raw['tanggal_pensiun'] ?? ''),
        'level_user'  => 5,
        '_raw'        => $raw,
    ];
}

/** Muat ulang profil dari GET /portal/profile (dipanggil otomatis tiap 10 menit). */
function hydrateUserSession(): bool
{
    $raw = temanku_get('/portal/profile');
    $username = (string)($_SESSION['username'] ?? '');
    if (!$raw) {
        if (empty($_SESSION['guru_profile'])) {   // API portal belum siap: tampilkan data dari /asn/me
            $_SESSION['guru_profile'] = normalize_api_user(['nip' => $_SESSION['nip'] ?? '', 'nama' => $_SESSION['nama'] ?? '',
                'unit_kerja' => $_SESSION['unit_nama'] ?? '', 'foto' => $_SESSION['photo'] ?? ''], $username);
            $_SESSION['guru_profile_at'] = time() - 540;   // coba lagi 1 menit lagi
        }
        return false;
    }
    $_SESSION['guru_profile'] = normalize_api_user($raw, $username);
    $_SESSION['guru_profile_at'] = time();
    return true;
}

function user(): ?array
{
    if (!guru_boleh()) return null;
    $p = $_SESSION['guru_profile'] ?? null;
    if (!is_array($p) || ($p['nip'] ?? '') !== (string)$_SESSION['nip'] || time() - (int)($_SESSION['guru_profile_at'] ?? 0) > 600) {
        hydrateUserSession();
    }
    return is_array($_SESSION['guru_profile'] ?? null) ? $_SESSION['guru_profile'] : null;
}

function require_login(): array
{
    allow('app.access');
    $u = user();
    if (!$u) forbid();
    return $u;
}
