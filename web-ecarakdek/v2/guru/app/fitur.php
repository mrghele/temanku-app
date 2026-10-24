<?php
declare(strict_types=1);
if (!defined('APP_INTERNAL')) { http_response_code(403); exit('Forbidden'); }

/*
 * Saklar fitur SEMENTARA. Disimpan di storage/fitur.json (folder storage diblokir
 * .htaccess dari akses web). Halaman web hanya mengubah saklar ini — tidak pernah
 * mengubah file kode. Bila file belum ada, semua fitur memakai nilai bawaan (aktif).
 */

const FITUR_DAFTAR = [
    'simulasi_menu' => [
        'judul' => 'Simulasi Absen Wajah di menu Presensi & Kehadiran',
        'keterangan' => 'Tombol "Simulasi Absen Wajah" di menu bawah (HP). Setelah login, pegawai yang sudah rekam wajah diarahkan ke menu ini.',
        'bawaan' => true,
    ],
    'absen_wajah' => [
        'judul' => 'Absen wajib lewat halaman Absen (pola kerja)',
        'keterangan' => 'Absen masuk/pulang, shift, WFA (Kamis/Jumat, wilayah Selayar), upacara & dinas lewat halaman Absen. Wajah hanya untuk unit bermetode Lokasi + Wajah (diatur di APEL); unit lain cukup lokasi. Form absen GPS lama ditolak.',
        'bawaan' => true,
    ],
    'persiapan_akun' => [
        'judul' => 'Persiapan akun setelah login',
        'keterangan' => 'Pegawai diarahkan sekali per sesi: foto SIMPEG belum ada → Segarkan data otomatis; wajah belum direkam → Perekaman Wajah.',
        'bawaan' => true,
    ],
];

function fitur_file(): string
{
    return dirname(__DIR__) . '/storage/fitur.json';
}

function fitur_semua(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $saved = [];
    $file = fitur_file();
    if (is_file($file)) {
        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data)) $saved = $data;
    }
    $cache = ['nilai' => [], 'diubah_oleh' => (string) ($saved['diubah_oleh'] ?? ''), 'diubah_pada' => (string) ($saved['diubah_pada'] ?? '')];
    foreach (FITUR_DAFTAR as $kunci => $info) {
        $cache['nilai'][$kunci] = isset($saved['nilai'][$kunci]) ? (bool) $saved['nilai'][$kunci] : (bool) $info['bawaan'];
    }
    return $cache;
}

function fitur_aktif(string $kunci): bool
{
    return (bool) (fitur_semua()['nilai'][$kunci] ?? false);
}

/** Hanya super_admin (level 1) dengan username yang diizinkan. */
function fitur_boleh_atur(): bool
{
    return false;   // portal guru e-Carakdek: tidak ada halaman pengaturan fitur
    $izin = defined('FITUR_ADMIN_USERS') ? (array) constant('FITUR_ADMIN_USERS') : ['demosimpeg'];
    $username = strtolower(trim((string) ($_SESSION['username'] ?? '')));
    return ($_SESSION['role'] ?? '') === 'super_admin'
        && (int) ($_SESSION['level_user'] ?? 0) === 1
        && $username !== ''
        && in_array($username, array_map('strtolower', $izin), true);
}

/** Simpan atomik (tulis file sementara lalu rename). */
function fitur_simpan(string $kunci, bool $aktif): void
{
    if (!isset(FITUR_DAFTAR[$kunci])) throw new InvalidArgumentException('Fitur tidak dikenal');
    $semua = fitur_semua();
    $nilai = $semua['nilai'];
    $nilai[$kunci] = $aktif;
    $folder = dirname(fitur_file());
    if (!is_dir($folder) && !@mkdir($folder, 0750, true)) throw new RuntimeException('Folder storage tidak dapat dibuat');
    $isi = json_encode([
        'nilai' => $nilai,
        'diubah_oleh' => (string) ($_SESSION['username'] ?? ''),
        'diubah_pada' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $sementara = fitur_file() . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($sementara, $isi, LOCK_EX) === false || !@rename($sementara, fitur_file())) {
        @unlink($sementara);
        throw new RuntimeException('Pengaturan tidak dapat disimpan');
    }
    @chmod(fitur_file(), 0640);
}

/**
 * Metode absensi unit pegawai yang login: 'WAJAH' (lokasi + wajah) atau 'LOKASI' (lokasi saja).
 * Ditentukan Go dari pengaturan APEL (berjenjang ke unit induk); disimpan di sesi 5 menit.
 */
function temanku_metode_absen(): string
{
    $cache = $_SESSION['metode_absen'] ?? null;
    if (is_array($cache) && ($cache['sampai'] ?? 0) > time()) return (string) $cache['metode'];
    $data = function_exists('temanku_get') ? temanku_get('/portal/absen/metode') : null;
    $metode = (is_array($data) && ($data['metode'] ?? '') === 'WAJAH') ? 'WAJAH' : 'LOKASI';
    $_SESSION['metode_absen'] = ['metode' => $metode, 'sampai' => time() + 300];
    return $metode;
}
