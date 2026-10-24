<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/includes/riwayat_usulan.php';
require_once __DIR__ . '/includes/riwayat_sync.php';
middleware('riwayat.view');
require_post();
set_time_limit(240);

/*
 * Segarkan data ASN (portal guru): sinkron golongan, jabatan, data utama & foto dari SIASN lewat
 * gerbang sync SIMPEG (POST /asn/sinkron/utama, sama dengan "Sync Data Utama" e-Carakdek admin),
 * lalu cache pegawai & profil sesi diperbarui. Hasil dilaporkan per langkah (format TEMANKU).
 */
const SEGAR_LANGKAH = [
    'golongan' => 'Sinkron golongan (SIASN)', 'jabatan' => 'Sinkron jabatan (SIASN)',
    'data_utama' => 'Data utama', 'foto' => 'Foto',
];
$wantsJson = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
$pnsId = (string) ($_SESSION['pns_id'] ?? '');

function riwayat_segar_cache(): array
{
    $cache = temanku_post('/portal/profile/refresh');
    $cacheOk = is_array($cache) && ($cache['success'] ?? false) === true;
    unset($_SESSION['riwayat_cache'], $_SESSION['riwayat_identitas'], $_SESSION['riwayat_pegawai']);
    $profileOk = $cacheOk && hydrateUserSession();
    if (!$profileOk) error_log('ECARAKDEK guru segarkan: pembaruan cache/profil gagal');
    return [
        'key' => 'cache', 'label' => 'Perbarui profil & cache pegawai',
        'status' => $profileOk ? 'ok' : 'gagal',
        'message' => $profileOk ? 'Profil diperbarui' : ($cacheOk ? 'Cache diperbarui, tetapi profil belum dapat dimuat ulang' : 'Pembaruan cache pegawai gagal. Silakan coba lagi'),
    ];
}

function riwayat_segar_langkah(string $pnsId, string $langkah): array
{
    [$kode, $j] = guru_sinkron('utama', ['id' => $pnsId, 'langkah' => $langkah]);
    $st = is_array($j) ? (string) ($j['status'] ?? (!empty($j['success']) ? 'ok' : 'gagal')) : 'gagal';
    if (!in_array($st, ['ok', 'gagal', 'peringatan'], true)) $st = 'gagal';
    if ($kode < 200 || $kode >= 300) $st = 'gagal';
    return ['key' => $langkah, 'label' => SEGAR_LANGKAH[$langkah], 'status' => $st,
        'message' => guru_sinkron_pesan($j, $kode, $st === 'ok' ? 'Berhasil' : 'Belum berhasil'),
        'bkn_offline' => $kode === 0 || !empty($j['offline'])];
}

if (!valid_pns_id($pnsId)) {
    $message = 'Data pegawai SIMPEG untuk akun ini belum ditemukan.';
    if ($wantsJson) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => false, 'message' => $message]); exit; }
    flash($message, 'bahaya');
    redirect('riwayat.php');
}

/* Mode satu langkah (JavaScript halaman Riwayat): langkah=golongan|jabatan|data_utama|foto|cache */
$langkah = (string) ($_POST['langkah'] ?? '');
if ($langkah !== '' && $wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($langkah !== 'cache' && !isset(SEGAR_LANGKAH[$langkah])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Langkah tidak dikenal']);
        exit;
    }
    $step = $langkah === 'cache' ? riwayat_segar_cache() : riwayat_segar_langkah($pnsId, $langkah);
    echo json_encode(['success' => $step['status'] === 'ok', 'step' => $step], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Tanpa JavaScript: semua langkah sekaligus. */
$steps = [];
foreach (array_keys(SEGAR_LANGKAH) as $k) $steps[] = riwayat_segar_langkah($pnsId, $k);
$ok = [];
foreach ($steps as $s) $ok[$s['key']] = $s['status'] === 'ok';
if (!empty($ok['data_utama']) || !empty($ok['foto'])) $steps[] = riwayat_segar_cache();
$success = !empty($ok['data_utama']);
$summary = [];
foreach ($steps as $step) $summary[] = ($step['status'] === 'ok' ? '✓ ' : '✗ ') . $step['label'];
$message = $success ? 'Data utama berhasil diperbarui dari SIASN.' : 'Data utama belum berhasil diperbarui.';
if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'steps' => $steps], JSON_UNESCAPED_UNICODE);
    exit;
}
flash($message . ' (' . implode(', ', $summary) . ')', $success ? 'sukses' : 'bahaya');
$category = (string) ($_POST['kategori'] ?? '');
redirect('riwayat.php' . (in_array($category, ['jabatan', 'pangkat', 'pendidikan', 'diklat', 'keluarga'], true) ? '?kategori=' . $category : ''));
