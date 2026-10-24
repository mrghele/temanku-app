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
 *   POST aksi=sync_data | sync_dok         sync SIASN kategori (langkah 1 data / satu dokumen langkah 2)
 *   GET  aksi=sync_dok_list&kategori=..    daftar dokumen SIASN hasil langkah 1 (untuk dipilih)
 *   POST aksi=identitas_simpan             ubah identitas (SIMPEG, opsional kirim ke SIASN BKN)
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
    // e-Carakdek tidak terhubung langsung ke BKN: SIASN dihubungi server SIMPEG (gerbang sync) lewat API Go.
    $bkn = $simpeg >= 200 && $simpeg < 300;
    $msBkn = $msSimpeg;
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
        'wilayah' => '/wilayah', 'unor' => '/asn/ref-jabatan', 'fungsional' => '/asn/ref-jabatan', 'pelaksana' => '/asn/ref-jabatan', 'struktural' => '/asn/ref-jabatan'][$nama] ?? null;
    if ($sumber === null) rw_keluar(['success' => false, 'message' => 'Referensi tidak dikenal'], 400);
    if ($nama === 'wilayah') {
        // Tempat lahir: hanya kabupaten (KB) / kota (KA) dengan ID SIASN, seperti lokasi.search.php SIMPEG.
        $q = trim(preg_replace('/[^\p{L}\p{N} .\'-]/u', '', rw_input('q')) ?? '');
        if ((function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) < 2) rw_keluar(['success' => true, 'data' => []]);
        $out = [];
        for ($off = 0; $off < 500 && count($out) < 40; $off += 100) {
            [$kode, $json] = riwayat_api_kirim('GET', '/wilayah?' . http_build_query(['search' => substr($q, 0, 50), 'limit' => 100, 'offset' => $off]));
            if ($kode !== 200 || !is_array($json)) { if ($off === 0) rw_keluar(['success' => false, 'message' => 'Referensi lokasi belum dapat dimuat'], 502); break; }
            $hal = is_array($json['data']['data'] ?? null) ? $json['data']['data'] : api_rows($json);
            foreach ($hal as $l) {
                $jenis = strtoupper((string)($l['jenis'] ?? ''));
                $kodeL = (string)($l['kode'] ?? '');
                if (in_array($jenis, ['KB', 'KA'], true) && preg_match('/^[0-9A-F]{32}$/', $kodeL)) {
                    $out[] = ['id' => $kodeL, 'nama' => ($jenis === 'KA' ? 'Kota ' : 'Kab. ') . trim((string)($l['nama'] ?? ''))];
                }
            }
            if (count($hal) < 100) break;
        }
        rw_keluar(['success' => true, 'data' => array_slice($out, 0, 40)]);
    }
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
    if (($kode !== 200 || !is_array($json)) && $nama !== 'agama') rw_keluar(['success' => false, 'message' => 'Referensi belum dapat dimuat (HTTP ' . $kode . ')'], 502);
    $out = [];
    foreach (is_array($json) ? api_rows($json) : [] as $r) {
        if (!is_array($r) || (isset($r['aktif']) && (int)$r['aktif'] !== 1)) continue;
        $id = '';
        foreach (['id', 'kode', 'harga_id', 'agama_id', 'id_agm', 'ID'] as $k) { if (isset($r[$k]) && is_scalar($r[$k]) && trim((string)$r[$k]) !== '') { $id = trim((string)$r[$k]); break; } }
        $lbl = '';
        foreach (['nama', 'name', 'harga_nama', 'nmagama', 'agama', 'nm_agm', 'nama_agama', 'nmpendidikan'] as $k) { if (isset($r[$k]) && is_scalar($r[$k]) && trim((string)$r[$k]) !== '') { $lbl = trim((string)$r[$k]); break; } }
        if ($id !== '' && $lbl !== '') $out[] = ['id' => $id, 'nama' => $lbl];
    }
    if ($nama === 'agama' && !$out) {   // cadangan sesuai tbl_agama (seperti ref_agama() SIMPEG)
        foreach (RIWAYAT_REF_AGAMA_CADANGAN as $id => $nm) $out[] = ['id' => (string)$id, 'nama' => $nm];
    }
    if (!in_array($nama, ['jurusan', 'unor', 'fungsional', 'pelaksana', 'struktural'], true)) $_SESSION['riwayat_ref'][$nama] = $out;
    rw_keluar(['success' => true, 'data' => $out]);
}

/* ---------- e-Carakdek: identitas diubah admin sekolah (sinkron SIASN lewat gerbang SIMPEG, includes/riwayat_sync.php) ---------- */
if ($aksi === 'identitas_simpan' && !RIWAYAT_UBAH_IDENTITAS) {
    rw_keluar(['success' => false, 'message' => 'Perubahan identitas diajukan melalui admin sekolah.'], 403);
}

/* ---------- sync: daftar dokumen tahap 2 ---------- */
if ($aksi === 'sync_dok_list') {
    require_once __DIR__ . '/includes/riwayat_sync.php';
    $kategori = rw_input('kategori');
    $jenisSync = rw_jenis_sync($kategori);
    $d = isset($kategoriTampil[$kategori]) ? riwayat_sync_dok_daftar($pegawai, $kategori, $jenisSync) : null;
    if ($d === null) rw_keluar(['success' => false, 'message' => 'Jalankan langkah 1 (data) lebih dulu.'], 409);
    rw_keluar(['success' => true, 'data' => $d]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rw_keluar(['success' => false, 'message' => 'Metode tidak diizinkan'], 405);
require_post();

/* ---------- sync SIASN ---------- */
/** Jenis satu seksi (opsional) yang disync; kosong = semua jenis kategori. */
function rw_jenis_sync(string $kategori): string
{
    $j = rw_input('jenis');
    return in_array($j, RIWAYAT_KATEGORI_JENIS[$kategori] ?? [], true) ? $j : '';
}
if ($aksi === 'sync_dok_siapkan') {   // Tahap 2 saja: daftar dokumen SIASN tanpa menarik data
    require_once __DIR__ . '/includes/riwayat_sync.php';
    set_time_limit(180);
    $kategori = rw_input('kategori');
    if (!isset($kategoriTampil[$kategori])) rw_keluar(['success' => false, 'message' => 'Kategori tidak dikenal'], 400);
    if (!rw_batas('sync_data', 20, 600)) rw_keluar(['success' => false, 'message' => 'Terlalu sering sinkron. Tunggu beberapa menit.'], 429);
    $h = riwayat_sync_dok_siapkan($pegawai, $kategori, rw_jenis_sync($kategori));
    rw_keluar($h, $h['success'] ? 200 : 502);
}
if ($aksi === 'sync_data' || $aksi === 'sync_dok') {
    require_once __DIR__ . '/includes/riwayat_sync.php';
    set_time_limit(180);
    $kategori = rw_input('kategori');
    if (!isset($kategoriTampil[$kategori])) rw_keluar(['success' => false, 'message' => 'Kategori tidak dikenal'], 400);
    if ($aksi === 'sync_data') {
        if (!rw_batas('sync_data', 20, 600)) rw_keluar(['success' => false, 'message' => 'Terlalu sering sinkron. Tunggu beberapa menit.'], 429);
        $h = riwayat_sync_data($pegawai, $kategori, rw_jenis_sync($kategori));
        error_log('TEMANKU riwayat sync ' . $kategori . ' pns=' . $pegawai['pns_id'] . ': ' . ($h['success'] ? 'ok' : 'gagal') . ' ' . $h['message']);
        rw_keluar($h, $h['success'] ? 200 : 502);
    }
    if (!rw_batas('sync_dok', 150, 600)) rw_keluar(['success' => false, 'message' => 'Terlalu banyak dokumen ditarik. Tunggu beberapa menit.'], 429);
    $h = riwayat_sync_dok($pegawai, $kategori, (int)rw_input('idx'), rw_jenis_sync($kategori));
    rw_keluar($h);
}

/* ---------- simpan identitas (seperti identitas.save.php SIMPEG, tanpa pindah unit) ---------- */
if ($aksi === 'identitas_simpan') {
    if (!rw_batas('identitas', 20, 600)) rw_keluar(['success' => false, 'message' => 'Terlalu banyak penyimpanan. Tunggu beberapa menit.'], 429);
    $masuk = static function (string $k): ?string {
        if (!array_key_exists($k, $_POST) || !is_string($_POST[$k])) return null;   // tidak dikirim = tidak diubah
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $_POST[$k]) ?? '');
    };
    $payload = [];
    $errors = [];
    if (($v = $masuk('agamaId')) !== null) {
        [$kodeA, $jsonA] = riwayat_api_kirim('GET', '/agama');
        $ids = [];
        foreach (api_rows(is_array($jsonA) ? $jsonA : null) as $a) $ids[] = (string)($a['id_agm'] ?? ($a['id'] ?? ''));
        if (!ctype_digit($v) || ($ids && !in_array($v, $ids, true))) $errors['agamaId'] = 'Pilih agama.'; else $payload['agamaId'] = (int)$v;
    }
    if (($v = $masuk('jenisKawinId')) !== null) {
        if (!isset(RIWAYAT_REF_KAWIN[$v])) $errors['jenisKawinId'] = 'Pilih status perkawinan.'; else $payload['jenisKawinId'] = (int)$v;
    }
    if (($v = $masuk('golDarah')) !== null) {
        $v = strtoupper($v);
        if ($v !== '' && !in_array($v, RIWAYAT_REF_GOL_DARAH, true)) $errors['golDarah'] = 'Golongan darah harus A, B, AB, atau O.'; else $payload['golDarah'] = $v;
    }
    if (($v = $masuk('tempatLahirId')) !== null) {
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $v)) $errors['tempatLahirId'] = 'Pilih tempat lahir dari daftar.'; else $payload['tempatLahirId'] = $v;
    }
    if (($v = $masuk('alamat')) !== null) {
        if ((function_exists('mb_strlen') ? mb_strlen($v) : strlen($v)) > 500) $errors['alamat'] = 'Maksimal 500 karakter.'; else $payload['alamat'] = $v;
    }
    if (($v = $masuk('noHp')) !== null) {
        $v = preg_replace('/[\s.-]/', '', $v) ?? '';
        if ($v !== '' && !preg_match('/^(\+?62|0)[0-9]{8,14}$/', $v)) $errors['noHp'] = 'Format No. HP tidak valid (contoh 081234567890).'; else $payload['noHp'] = $v;
    }
    if (($v = $masuk('email')) !== null) {
        if ($v !== '' && (strlen($v) > 100 || !preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $v))) $errors['email'] = 'Format email tidak valid.'; else $payload['email'] = $v;
    }
    if (($v = $masuk('emailGov')) !== null) {
        if ($v !== '' && (strlen($v) > 100 || !preg_match('/^[A-Za-z0-9._%+\-]+@([A-Za-z0-9-]+\.)*go\.id$/i', $v))) $errors['emailGov'] = 'Email dinas harus berdomain .go.id.'; else $payload['emailGov'] = strtolower($v);
    }
    if ($errors) rw_keluar(['success' => false, 'message' => 'Periksa kembali isian yang ditandai.', 'errors' => $errors, 'field' => array_key_first($errors)], 422);
    if (!$payload) rw_keluar(['success' => false, 'message' => 'Tidak ada data yang diubah.'], 422);
    [$kode, $json] = riwayat_api_kirim('PATCH', '/asn/data-utama/' . rawurlencode($pegawai['pns_id']) . '/identitas', $payload);
    if ($kode < 200 || $kode >= 300) {
        error_log('TEMANKU identitas gagal pns=' . $pegawai['pns_id'] . ' http=' . $kode);
        rw_keluar(['success' => false, 'message' => riwayat_api_pesan($json, $kode === 404 ? 'Fitur simpan identitas belum tersedia di API.' : 'Gagal menyimpan ke SIMPEG (HTTP ' . $kode . ').'),
            'field' => is_array($json) ? ($json['field'] ?? null) : null], $kode >= 400 && $kode < 500 ? 422 : 502);
    }
    unset($_SESSION['riwayat_identitas']);
    error_log('TEMANKU identitas diubah pns=' . $pegawai['pns_id'] . ' field=' . implode(',', array_keys($payload)));
    $bknKeys = array_intersect_key($payload, array_flip(RIWAYAT_IDT_BKN));
    $bkn = ['status' => 'skip', 'message' => ''];
    if ($bknKeys) {   // e-Carakdek tidak terhubung langsung ke SIASN BKN
        $bkn = ['status' => 'skip', 'message' => 'Perubahan disimpan di SIMPEG. Pengiriman ke SIASN BKN dilakukan BKPSDM.'];
    }
    rw_keluar(['success' => true, 'message' => (string)($json['message'] ?? 'Data identitas berhasil diperbarui.'), 'bkn' => $bkn]);
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

/**
 * Aturan per jenis seperti SIMPEG (dicek ulang di server, tidak hanya di form):
 *  - cpns       : status yang sudah PNS tidak dapat dikembalikan ke CPNS
 *  - pendidikan : pendidikan pertama hanya boleh satu
 *  - pasangan   : pada urutan pasangan yang sama nama tidak boleh berganti (pasangan baru = Tambah);
 *                 status perkawinan "Belum Menikah" tidak dapat menambah pasangan
 */
$aturan = static function (string $aksiU, array $data, array $lama, string $refId) use ($jenis, $pegawai, $kategori): void {
    if ($jenis === 'cpns' && strtoupper((string)($lama['status'] ?? '')) === 'PNS' && strtoupper((string)($data['status'] ?? '')) === 'CPNS') {
        rw_keluar(['success' => false, 'message' => 'Sudah PNS — status tidak dapat dikembalikan ke CPNS.', 'field' => 'status'], 422);
    }
    if ($jenis === 'pendidikan' && (string)($data['pertama'] ?? '') === '1') {
        foreach (riwayat_data($pegawai, $kategori) ?? [] as $row) {
            if (($row['jenis'] ?? '') === 'pendidikan' && $row['id_rw'] !== $refId && (string)($row['awal']['pertama'] ?? '') === '1') {
                rw_keluar(['success' => false, 'message' => 'Pendidikan pertama sudah ditetapkan pada ' . $row['judul'] . '.', 'field' => 'pertama'], 422);
            }
        }
    }
    if ($jenis === 'pasangan') {
        if ($aksiU === 'tambah') {
            $idt = riwayat_identitas($pegawai);
            if ($idt && (string)($idt['ubah']['jenisKawinId'] ?? '') === '4') {
                rw_keluar(['success' => false, 'message' => 'Status perkawinan Anda di data utama "Belum Menikah". Perbarui status perkawinan di Identitas lebih dulu.'], 422);
            }
        }
        $norm = static function (string $x): string {
            $x = function_exists('mb_strtoupper') ? mb_strtoupper($x, 'UTF-8') : strtoupper($x);
            return trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[^\p{L}\s]+/u', ' ', $x)));
        };
        $nB = $norm((string)($data['nama'] ?? '')); $nL = $norm((string)($lama['nama'] ?? ''));
        if ($aksiU === 'ubah' && $nL !== '' && $nB !== $nL && (int)($data['pasangan_ke'] ?? 0) === (int)($lama['pasangan_ke'] ?? 0)
            && (strlen($nB) >= 255 || strlen($nL) >= 255 || levenshtein($nB, $nL) > 2)) {
            rw_keluar(['success' => false, 'field' => 'nama', 'message' => 'Nama pasangan berbeda dengan data awal pada urutan pasangan yang sama. '
                . 'Data pasangan sebelumnya DILARANG ditimpa. Jika menikah lagi, gunakan "Tambah" dengan urutan pasangan berikutnya (mis. pasangan ke-2). Bila hanya koreksi penulisan nama, hubungi admin BKPSDM.'], 422);
        }
    }
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
        $aturan($aksiU, $data, riwayat_json_objek($u['data_lama'] ?? null), (string)($u['ref_id'] ?? ''));
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
    $lama = $dasar;
    $dasar = $tanpaBerkas($dasar);
    $data = !empty($form['kunci_ubah']) && $aksiBaru === 'ubah' ? $dasar : riwayat_usul_data($jenis, $dasar, $isian);
    $aturan($aksiBaru, $data, $lama, $refId);
    $body['data'] = $unggahDok($data, $refId);
}
[$kode, $json] = riwayat_api_kirim('POST', '/asn/usulan-riwayat', $body);
if ($kode !== 201 && $kode !== 200) {
    rw_keluar(['success' => false, 'message' => riwayat_api_pesan($json, 'Usulan belum dapat diajukan (HTTP ' . $kode . ')'), 'field' => $json['field'] ?? null], $kode ?: 502);
}
error_log('TEMANKU riwayat usulan ' . $aksiBaru . ' ' . $jenis . ' pns=' . $pegawai['pns_id'] . ' id=' . ($json['id'] ?? '-'));
rw_keluar(['success' => true, 'message' => 'Usulan berhasil diajukan dan menunggu verifikasi admin.', 'id' => $json['id'] ?? '']);
