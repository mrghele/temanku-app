<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';
require __DIR__ . '/includes/riwayat_asn.php';
require __DIR__ . '/includes/riwayat_usulan.php';
middleware('riwayat.view');

/*
 * Aksi JSON halaman Riwayat (dipanggil JavaScript riwayat.php):
 *   GET  aksi=status                       status koneksi API SIMPEG & API BKN (SIASN)
 *   GET  aksi=ref&nama=..&q=..&tingkat=..  referensi form usulan
 *   POST aksi=simpan                       ajukan / perbaiki usulan tambah|ubah|hapus (multipart, dokumen PDF)
 *   POST aksi=batal                        batalkan usulan sendiri yang masih diajukan / dikembalikan
 *   POST aksi=sync_data | sync_dok         sync SIASN kategori (tahap data / satu dokumen)
 * Semua aksi memakai PNS_ID pegawai yang login; jenis & riwayat yang diubah dicocokkan dengan data milik sendiri.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rw_keluar(array $isi, int $kode = 200): void
{
    http_response_code($kode);
    echo json_encode($isi, JSON_UNESCAPED_UNICODE);
    exit;
}

function rw_input(string $k): string
{
    $v = $_POST[$k] ?? ($_GET[$k] ?? '');
    return is_string($v) ? trim($v) : '';
}

/** Batas sederhana per sesi: maksimal $maks permintaan per $detik untuk satu kunci. */
function rw_batas(string $kunci, int $maks, int $detik): bool
{
    $now = time();
    $log = array_filter((array)($_SESSION['riwayat_batas'][$kunci] ?? []), static function ($t) use ($now, $detik): bool { return is_int($t) && $t > $now - $detik; });
    if (count($log) >= $maks) return false;
    $log[] = $now;
    $_SESSION['riwayat_batas'][$kunci] = array_values($log);
    return true;
}

/* ---------- status koneksi ---------- */
if (rw_input('aksi') === 'status') {
    $c = $_SESSION['riwayat_status'] ?? null;
    if (is_array($c) && ($c['sampai'] ?? 0) > time() && rw_input('segar') !== '1') rw_keluar(['success' => true] + $c['data']);
    $mulai = microtime(true);
    $ch = curl_init(rtrim(API_SIMPEG, '/') . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch);
    $simpeg = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $msSimpeg = (int)round((microtime(true) - $mulai) * 1000);
    require_once __DIR__ . '/includes/riwayat_sync.php';
    $mulai = microtime(true);
    $bkn = rs_token() !== null;
    $msBkn = (int)round((microtime(true) - $mulai) * 1000);
    $data = [
        'simpeg' => ['ok' => $simpeg >= 200 && $simpeg < 300, 'ms' => $msSimpeg],
        'bkn' => ['ok' => $bkn, 'ms' => $msBkn],
        'dicek' => date('H:i'),
    ];
    $_SESSION['riwayat_status'] = ['sampai' => time() + 60, 'data' => $data];
    rw_keluar(['success' => true] + $data);
}

$pegawai = riwayat_pegawai();
if (!$pegawai) rw_keluar(['success' => false, 'message' => 'Data kepegawaian Anda belum ditemukan di SIMPEG.'], 404);
$kategoriTampil = riwayat_kategori_daftar($pegawai);
$aksi = rw_input('aksi');

/** Kategori halaman untuk jenis usulan; hanya kategori yang tampil untuk jenis ASN pegawai. */
function rw_kategori_jenis(string $jenis, array $kategoriTampil): ?string
{
    foreach (RIWAYAT_KATEGORI_JENIS as $kat => $daftar) {
        if (in_array($jenis, $daftar, true) && isset($kategoriTampil[$kat])) return $kat;
    }
    return null;
}

/* ---------- referensi form ---------- */
if ($aksi === 'ref') {
    $nama = rw_input('nama');
    $sumber = ['pendidikan' => '/asn/ref-pendidikan', 'penghargaan' => '/asn/ref-penghargaan', 'diklat' => '/asn/ref-diklat',
        'diklatpim' => '/asn/ref-diklatpim', 'agama' => '/agama', 'jurusan' => '/asn/ref-jurusan',
        'unor' => '/asn/ref-jabatan', 'fungsional' => '/asn/ref-jabatan', 'pelaksana' => '/asn/ref-jabatan', 'struktural' => '/asn/ref-jabatan'][$nama] ?? null;
    if ($sumber === null) rw_keluar(['success' => false, 'message' => 'Referensi tidak dikenal'], 400);
    if ($nama === 'struktural') {
        $unor = rw_input('unor');
        if (!preg_match(RIWAYAT_POLA_ID, $unor)) rw_keluar(['success' => true, 'data' => []]);
        $sumber .= '?' . http_build_query(['jenis' => 'struktural', 'unor' => $unor]);
    } elseif (in_array($nama, ['unor', 'fungsional', 'pelaksana'], true)) {
        $q = rw_input('q');
        if ((function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) < 2) rw_keluar(['success' => true, 'data' => []]);
        $sumber .= '?' . http_build_query(['jenis' => $nama, 'q' => substr($q, 0, 60)]);
    } elseif ($nama === 'jurusan') {
        $q = rw_input('q');
        if ((function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) < 2) rw_keluar(['success' => true, 'data' => []]);
        $sumber .= '?' . http_build_query(['q' => substr($q, 0, 60), 'tingkat' => preg_replace('/\D/', '', rw_input('tingkat'))]);
    } elseif (isset($_SESSION['riwayat_ref'][$nama])) {
        rw_keluar(['success' => true, 'data' => $_SESSION['riwayat_ref'][$nama]]);
    }
    [$kode, $json] = riwayat_api_kirim('GET', $sumber);
    if ($kode !== 200 || !is_array($json)) rw_keluar(['success' => false, 'message' => 'Referensi belum dapat dimuat (HTTP ' . $kode . ')'], 502);
    $out = [];
    foreach (api_rows($json) as $r) {
        if (!is_array($r)) continue;
        $id = '';
        foreach (['id', 'kode', 'harga_id', 'agama_id', 'ID'] as $k) { if (isset($r[$k]) && is_scalar($r[$k]) && trim((string)$r[$k]) !== '') { $id = trim((string)$r[$k]); break; } }
        $lbl = '';
        foreach (['nama', 'name', 'harga_nama', 'agama', 'nmpendidikan'] as $k) { if (isset($r[$k]) && is_scalar($r[$k]) && trim((string)$r[$k]) !== '') { $lbl = trim((string)$r[$k]); break; } }
        if ($id !== '' && $lbl !== '') $out[] = ['id' => $id, 'nama' => $lbl];
    }
    if (!in_array($nama, ['jurusan', 'unor', 'fungsional', 'pelaksana', 'struktural'], true)) $_SESSION['riwayat_ref'][$nama] = $out;
    rw_keluar(['success' => true, 'data' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rw_keluar(['success' => false, 'message' => 'Metode tidak diizinkan'], 405);
require_post();

/* ---------- sync SIASN ---------- */
if ($aksi === 'sync_data' || $aksi === 'sync_dok') {
    require_once __DIR__ . '/includes/riwayat_sync.php';
    set_time_limit(180);
    $kategori = rw_input('kategori');
    if (!isset($kategoriTampil[$kategori])) rw_keluar(['success' => false, 'message' => 'Kategori tidak dikenal'], 400);
    if ($aksi === 'sync_data') {
        if (!rw_batas('sync_data', 20, 600)) rw_keluar(['success' => false, 'message' => 'Terlalu sering sinkron. Tunggu beberapa menit.'], 429);
        $h = riwayat_sync_data($pegawai, $kategori);
        error_log('TEMANKU riwayat sync ' . $kategori . ' pns=' . $pegawai['pns_id'] . ': ' . ($h['success'] ? 'ok' : 'gagal') . ' ' . $h['message']);
        rw_keluar($h, $h['success'] ? 200 : 502);
    }
    if (!rw_batas('sync_dok', 150, 600)) rw_keluar(['success' => false, 'message' => 'Terlalu banyak dokumen ditarik. Tunggu beberapa menit.'], 429);
    $h = riwayat_sync_dok($pegawai, $kategori, (int)rw_input('idx'));
    rw_keluar($h);
}

/* ---------- batalkan usulan ---------- */
if ($aksi === 'batal') {
    $u = riwayat_usulan_milik($pegawai, rw_input('usulan_id'));
    if (!$u) rw_keluar(['success' => false, 'message' => 'Usulan tidak ditemukan.'], 404);
    if (!in_array($u['status'] ?? '', RIWAYAT_STATUS_UBAH, true)) rw_keluar(['success' => false, 'message' => 'Usulan yang sudah diproses tidak dapat dibatalkan.'], 409);
    [$kode, $json] = riwayat_api_kirim('DELETE', '/asn/usulan-riwayat/' . rawurlencode((string)$u['id']));
    if ($kode !== 200) rw_keluar(['success' => false, 'message' => riwayat_api_pesan($json, 'Usulan belum dapat dibatalkan (HTTP ' . $kode . ')')], $kode ?: 502);
    rw_keluar(['success' => true, 'message' => 'Usulan dibatalkan.']);
}

/* ---------- simpan usulan ---------- */
if ($aksi !== 'simpan') rw_keluar(['success' => false, 'message' => 'Aksi tidak dikenal'], 400);
if (!rw_batas('simpan', 30, 600)) rw_keluar(['success' => false, 'message' => 'Terlalu banyak pengajuan. Tunggu beberapa menit.'], 429);

$jenis = rw_input('jenis');
$form = riwayat_form_daftar()[$jenis] ?? null;
$kategori = rw_kategori_jenis($jenis, $kategoriTampil);
if (!$form || !$kategori) rw_keluar(['success' => false, 'message' => 'Usulan untuk riwayat ini diajukan melalui admin OPD (SIMPEG).'], 403);
$izin = riwayat_izin_jenis($jenis);
$isian = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
$alasan = rw_input('alasan');
$usulanId = rw_input('usulan_id');
$kolomDok = array_column($form['dok'], 0);

/** Dokumen PDF dari form (opsional per kolom): divalidasi lalu diunggah ke repository tipe usul. */
$unggahDok = static function (array $data, string $refId) use ($form, $jenis, $pegawai): array {
    $idDok = ['ijazah' => '870', 'transkrip' => '871', 'skp' => '891', 'dok_pak' => '879', 'sk_pak' => '880', 'sk_cpns' => '889',
        'sk_pns' => '887', 'spmt' => '888', 'lantik' => '873', 'sk_mutasi' => '1634', 'sertifikat' => '874', 'akta' => '875'];
    foreach ($form['dok'] as [$kol, $label]) {
        $data[$kol] = '';
        $f = $_FILES['dok_' . $kol] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) rw_keluar(['success' => false, 'message' => $label . ': berkas gagal diunggah.', 'field' => 'dok_' . $kol], 400);
        if ((int)$f['size'] > RIWAYAT_PDF_MAKS) rw_keluar(['success' => false, 'message' => $label . ': ukuran PDF maksimal 1,5 MB.', 'field' => 'dok_' . $kol], 400);
        $isi = (string)file_get_contents($f['tmp_name']);
        if (strncmp($isi, '%PDF-', 5) !== 0) rw_keluar(['success' => false, 'message' => $label . ': berkas harus PDF.', 'field' => 'dok_' . $kol], 400);
        $kode = $jenis === 'golongan' && $kol === 'pertek' ? 'pertek_gol' : $kol;
        try {
            $data[$kol] = riwayat_unggah_pdf($isi, $refId !== '' ? $refId : $pegawai['pns_id'], $kode, $idDok[$kol] ?? ($jenis === 'pendidikan' && $kol === 'pertek' ? '867' : '0'), 'usul', RIWAYAT_REPO_KATEGORI[$jenis]);
        } catch (Throwable $e) {
            rw_keluar(['success' => false, 'message' => $label . ': ' . $e->getMessage(), 'field' => 'dok_' . $kol], 502);
        }
    }
    return $data;
};

/** Hapus path berkas dari data dasar: kosong = dokumen lama dipertahankan oleh API Go. */
$tanpaBerkas = static function (array $d) use ($kolomDok): array {
    foreach ($d as $k => $v) {
        if (in_array($k, $kolomDok, true) || (is_string($v) && (preg_match(RIWAYAT_POLA_REPO, $v) || preg_match(RIWAYAT_DOK_POLA, $v)))) $d[$k] = '';
    }
    return $d;
};

if ($usulanId !== '') {
    // Perbaiki usulan sendiri (diajukan / dikembalikan) — PUT usulan yang sama.
    $u = riwayat_usulan_milik($pegawai, $usulanId);
    if (!$u || ($u['jenis'] ?? '') !== $jenis) rw_keluar(['success' => false, 'message' => 'Usulan tidak ditemukan.'], 404);
    if (!in_array($u['status'] ?? '', RIWAYAT_STATUS_UBAH, true)) rw_keluar(['success' => false, 'message' => 'Usulan yang sudah diproses tidak dapat diubah.'], 409);
    $aksiU = (string)($u['aksi'] ?? '');
    if (empty($izin[$aksiU])) rw_keluar(['success' => false, 'message' => 'Anda tidak memiliki hak untuk usulan ini.'], 403);
    if ($aksiU === 'hapus') {
        $body = ['alasan' => $alasan];
    } else {
        $dasar = $tanpaBerkas(riwayat_json_objek($u['data'] ?? null));
        $data = !empty($form['kunci_ubah']) && $aksiU === 'ubah' ? $dasar : riwayat_usul_data($jenis, $dasar, $isian);
        $body = ['data' => $unggahDok($data, (string)($u['ref_id'] ?? ''))];
    }
    [$kode, $json] = riwayat_api_kirim('PUT', '/asn/usulan-riwayat/' . rawurlencode((string)$u['id']), $body);
    if ($kode !== 200) rw_keluar(['success' => false, 'message' => riwayat_api_pesan($json, 'Usulan belum dapat disimpan (HTTP ' . $kode . ')'), 'field' => $json['field'] ?? null], $kode ?: 502);
    rw_keluar(['success' => true, 'message' => !empty($json['diajukan_ulang']) ? 'Perbaikan tersimpan dan usulan diajukan ulang untuk diverifikasi.' : 'Perubahan usulan tersimpan.', 'id' => $u['id']]);
}

$aksiBaru = rw_input('aksi_usul');
if (!in_array($aksiBaru, ['tambah', 'ubah', 'hapus'], true)) rw_keluar(['success' => false, 'message' => 'Jenis usulan tidak valid.'], 400);
if (empty($izin[$aksiBaru])) rw_keluar(['success' => false, 'message' => 'Anda tidak memiliki hak untuk ' . $aksiBaru . ' riwayat ini.'], 403);

$refId = '';
$dasar = [];
if ($aksiBaru !== 'tambah') {
    // Riwayat yang diubah/dihapus harus milik sendiri: dicari dari riwayat pegawai (bukan dari input).
    $ref = rw_input('ref_id');
    foreach (riwayat_data($pegawai, $kategori, true) ?? [] as $row) {
        if (($row['jenis'] ?? '') === $jenis && $ref !== '' && hash_equals((string)$row['id_rw'], $ref)) { $refId = $row['id_rw']; $dasar = $row['awal'] ?? []; break; }
    }
    if ($refId === '') rw_keluar(['success' => false, 'message' => 'Riwayat tidak ditemukan. Muat ulang halaman lalu coba lagi.'], 404);
    if ($jenis === 'cpns') $refId = $pegawai['pns_id'];
}

$body = ['jenis' => $jenis, 'aksi' => $aksiBaru, 'pns_id' => $pegawai['pns_id'], 'ref_id' => $refId, 'alasan' => $alasan];
if ($aksiBaru === 'hapus') {
    if ((function_exists('mb_strlen') ? mb_strlen($alasan) : strlen($alasan)) < 5) rw_keluar(['success' => false, 'message' => 'Alasan penghapusan wajib diisi (minimal 5 karakter).', 'field' => 'alasan'], 400);
} else {
    if ($aksiBaru === 'tambah') {
        // Cek dokumen wajib lebih dulu agar berkas tidak terunggah sia-sia.
        foreach ($form['dok'] as [$kol, $label, $wajib]) {
            if ($wajib && (($_FILES['dok_' . $kol]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
                rw_keluar(['success' => false, 'message' => $label . ' wajib diunggah.', 'field' => 'dok_' . $kol], 400);
            }
        }
    }
    $dasar = $tanpaBerkas($dasar);
    $data = !empty($form['kunci_ubah']) && $aksiBaru === 'ubah' ? $dasar : riwayat_usul_data($jenis, $dasar, $isian);
    $body['data'] = $unggahDok($data, $refId);
}
[$kode, $json] = riwayat_api_kirim('POST', '/asn/usulan-riwayat', $body);
if ($kode !== 201 && $kode !== 200) {
    rw_keluar(['success' => false, 'message' => riwayat_api_pesan($json, 'Usulan belum dapat diajukan (HTTP ' . $kode . ')'), 'field' => $json['field'] ?? null], $kode ?: 502);
}
error_log('TEMANKU riwayat usulan ' . $aksiBaru . ' ' . $jenis . ' pns=' . $pegawai['pns_id'] . ' id=' . ($json['id'] ?? '-'));
rw_keluar(['success' => true, 'message' => 'Usulan berhasil diajukan dan menunggu verifikasi admin.', 'id' => $json['id'] ?? '']);
