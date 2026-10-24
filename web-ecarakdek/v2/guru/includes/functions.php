<?php
declare(strict_types=1);


function is_list_array(array $array): bool
{
    if ($array === []) return true;
    return array_keys($array) === range(0, count($array) - 1);
}

/** Escape output HTML. */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    // Portal guru berada di /v2/guru; login & keluar memakai halaman e-Carakdek (/v2).
    $luar = ['login.php' => 'welcome.php', 'logout.php' => 'keluar.php'];
    $parts = preg_split('/(?=[?#])/', ltrim($path, '/'), 2);
    if (isset($luar[$parts[0]])) return rtrim(APP_URL, '/') . '/' . $luar[$parts[0]] . ($parts[1] ?? '');
    $route = $parts[0] === 'index.php' ? '' : $parts[0];
    return rtrim(BASE_URL, '/') . '/' . $route . ($parts[1] ?? '');
}

/** URL layanan dari API hanya boleh menggunakan skema web atau path lokal. */
function safe_service_href($href): string
{
    if (!is_string($href)) return '#';
    $href = trim($href);
    if ($href === '' || preg_match('/[\x00-\x20\x7f]/', $href) || strpos($href, '\\') !== false) return '#';
    if (preg_match('~^https?://[^/\s?#]+(?:[/?#]|$)~i', $href)) return $href;
    if (preg_match('~^(?!//)[a-z0-9_./?#=&%-]+$~i', $href) && !preg_match('~(?:^|/)\.\.(?:/|$)~', $href)) return $href;
    return '#';
}

function service_url(string $key): string
{
    static $links = null;
    if ($links === null) $links = require __DIR__ . '/../config/service_urls.php';
    $target = $links[$key] ?? null;
    if (!is_string($target) || $target === '') return '#';
    return preg_match('~^https?://~i', $target) ? $target : url($target);
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function rupiah($n): string
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}

function tanggal_id(?string $date, bool $panjang = false): string
{
    if (!$date) {
        return '-';
    }
    $hari  = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $ts    = strtotime($date);
    $out   = (int) date('j', $ts) . ' ' . ($panjang ? $bulan[(int) date('n', $ts)] : substr($bulan[(int) date('n', $ts)], 0, 3)) . ' ' . date('Y', $ts);

    return $panjang ? $hari[date('l', $ts)] . ', ' . $out : $out;
}

function jam(?string $t): string
{
    return $t ? substr($t, 0, 5) : '—';
}

/** Jarak dua titik dalam meter (haversine). */
function jarak_meter(float $lat1, float $lng1, float $lat2, float $lng2): int
{
    $r    = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
}

/** Peta warna status -> kelas CSS badge. */
function badge_status(string $status): string
{
    switch ($status) {
        case 'hadir': return 'badge badge--hijau';
        case 'wfa': return 'badge badge--kuning';
        case 'izin':
        case 'sakit':
        case 'cuti': return 'badge badge--biru';
        case 'libur': return 'badge badge--merah';
        case 'tak': return 'badge badge--merah';          // [temanku] status dari rekap APEL
        case 'dinas':
        case 'lepas': return 'badge badge--biru';
        default: return 'badge badge--abu';
    }
}

function label_status(string $status): string
{
    switch ($status) {
        case 'hadir': return 'Hadir';
        case 'wfa': return 'WFA';
        case 'izin': return 'Izin';
        case 'sakit': return 'Sakit';
        case 'cuti': return 'Cuti';
        case 'libur': return 'Libur';
        case 'tak': return 'Tidak absen (TAK)';            // [temanku] status dari rekap APEL
        case 'dinas': return 'Dinas';
        case 'lepas': return 'Lepas jaga';
        case 'maintenance': return 'Maintenance';
        case 'belum_berlaku': return 'Belum berlaku';
        default: return 'Alpa';
    }
}

function flash(?string $msg = null, string $tipe = 'info'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'tipe' => $tipe];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $f;
}
