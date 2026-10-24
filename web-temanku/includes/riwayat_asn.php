<?php
declare(strict_types=1);

/*
 * Riwayat ASN lengkap untuk TEMANKU, mengikuti tab riwayat SIMPEG (asn/detail).
 * Sumber data sama dengan SIMPEG: endpoint /asn/rw-* API Go.
 * Pegawai hanya melihat riwayat miliknya sendiri: PNS_ID dicari dari NIP sesi, tidak pernah dari input.
 */

const RIWAYAT_CACHE_TTL = 300;
const RIWAYAT_DOK_POLA  = '#^[a-z_]+/(?:riwayat|usul)/[A-Za-z0-9._-]+\.pdf$#';

/** PNS_ID & jenis ASN pegawai yang login: ['nip', 'pns_id', 'jenis' => pns|pppk|pppkpw, 'cpns' => bool]. */
function riwayat_pegawai(): ?array
{
    $nip = current_nip();
    $info = $_SESSION['riwayat_pegawai'] ?? null;
    if (is_array($info) && ($info['nip'] ?? '') === $nip) return $info;
    if (!preg_match('/^\d{8,20}$/', $nip)) return null;

    $r = temanku_get('/asn/data-utama/nip/' . rawurlencode($nip));
    if (isset($r['data']) && is_array($r['data']) && !is_list_array($r['data'])) $r = $r['data'];
    $pnsId = trim((string)($r['pns_id'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $pnsId)) return null;

    $status = strtolower(trim((string)($r['status_pegawai'] ?? '')));
    $info = [
        'nip'    => $nip,
        'pns_id' => $pnsId,
        'jenis'  => riwayat_jenis_asn($status, $nip),
        'cpns'   => in_array($status, ['c', 'cpns'], true),
    ];
    $_SESSION['riwayat_pegawai'] = $info;
    return $info;
}

/** Kelompok ASN seperti asn_status_group() SIMPEG; NIP PPPK berkode "21" pada digit 13-14. */
function riwayat_jenis_asn(string $status, string $nip): string
{
    if (in_array($status, ['3', 'pppkpw', 'pppk-pw', 'pppk_pw', 'pppk pw'], true) || strpos($status, 'paruh') !== false) return 'pppkpw';
    if (in_array($status, ['2', 'k'], true) || strpos($status, 'pppk') === 0) return 'pppk';
    if ($status === '' && strlen($nip) === 18 && substr($nip, 12, 2) === '21') return 'pppk';
    return 'pns';
}

/**
 * Kategori riwayat per jenis ASN, urutan & aturan sama dengan tab SIMPEG:
 * PNS semua tab; PPPK tanpa pangkat/CPNS/jabatan/tugas/angka kredit; PPPK paruh waktu hanya kinerja & disiplin;
 * penghargaan tidak untuk CPNS & PPPK paruh waktu.
 */
function riwayat_kategori_daftar(array $pegawai): array
{
    $pns = $pegawai['jenis'] === 'pns';
    $pw  = $pegawai['jenis'] === 'pppkpw';
    $daftar = [
        'pendidikan'  => ['Riwayat Pendidikan', 'bi-mortarboard-fill', 'biru', !$pw],
        'diklat'      => ['Diklat & Kursus', 'bi-award-fill', 'kuning', !$pw],
        'keluarga'    => ['Data Keluarga', 'bi-people-fill', 'abu', !$pw],
        'pangkat'     => ['Riwayat Pangkat', 'bi-arrow-up-circle-fill', 'hijau', $pns],
        'penghargaan' => ['Penghargaan', 'bi-trophy-fill', 'kuning', !$pegawai['cpns'] && !$pw],
        'cpns'        => ['CPNS/PNS', 'bi-shield-check', 'biru', $pns],
        'jabatan'     => ['Riwayat Jabatan', 'bi-briefcase-fill', 'tosca', $pns],
        'tugas'       => ['Tugas Tambahan', 'bi-person-workspace', 'tosca', $pns],
        'kredit'      => ['Angka Kredit', 'bi-graph-up-arrow', 'hijau', $pns],
        'kinerja'     => ['Kinerja (SKP)', 'bi-bar-chart-fill', 'biru', true],
        'disiplin'    => ['Hukuman Disiplin', 'bi-shield-exclamation', 'merah', true],
    ];
    return array_filter($daftar, static function (array $k): bool { return $k[3]; });
}

/**
 * Ambil baris mentah satu endpoint rw-*; null bila API gagal (beda dengan [] = memang belum ada data).
 * Rute /asn/rw-{jenis}/pns/{id} didaftarkan di grup TEMANKU (temanku.router.go), handler sama dengan SIMPEG.
 * Kode HTTP kegagalan dicatat di $GLOBALS['riwayat_api_kode'] untuk pesan & log.
 */
function riwayat_api(string $jenis, string $pnsId): ?array
{
    $endpoint = '/asn/rw-' . $jenis . '/pns/' . rawurlencode($pnsId);
    [$kode, $json] = riwayat_api_get(API_TEMANKU, $endpoint);
    if ($kode >= 200 && $kode < 300 && is_array($json)) {
        return array_values(array_filter(api_rows($json), 'is_array'));
    }
    $GLOBALS['riwayat_api_kode'] = $kode;
    error_log('TEMANKU riwayat: GET ' . $endpoint . ' gagal (HTTP ' . $kode . ')');
    return null;
}

/** GET JSON dengan token layanan + token SSO (pola SIMPEG); mengembalikan [kode HTTP, data]. Kode 0 = koneksi gagal. */
function riwayat_api_get(string $base, string $endpoint): array
{
    $token = api_service_token();
    if (!$token) return [0, null];
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    if (!empty($_SESSION['access_token'])) $headers[] = 'Auth: Bearer ' . $_SESSION['access_token'];
    $ch = curl_init(rtrim($base, '/') . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $res  = curl_exec($ch);
    $kode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$kode, is_string($res) ? json_decode($res, true) : null];
}

/**
 * Riwayat satu kategori dalam bentuk seragam untuk tampilan:
 * ['id_rw', 'judul', 'sub', 'label', 'waktu', 'urut', 'info' => [[label, nilai]], 'dok' => [[label, kolom]], '_raw'].
 * Hasil disimpan di sesi selama RIWAYAT_CACHE_TTL detik. null bila API gagal.
 */
function riwayat_data(array $pegawai, string $kategori, bool $segar = false): ?array
{
    $kunci = 'v5:' . $pegawai['pns_id'] . ':' . $kategori;   // v4: baris memuat kolom tabel & kelengkapan
    $cache = $_SESSION['riwayat_cache'][$kunci] ?? null;
    if (!$segar && is_array($cache) && $cache['sampai'] > time()) return $cache['data'];

    $rows = riwayat_muat($pegawai['pns_id'], $kategori);
    if ($rows === null) return null;
    usort($rows, static function (array $a, array $b): int { return strcmp($b['urut'], $a['urut']); });
    $_SESSION['riwayat_cache'][$kunci] = ['sampai' => time() + RIWAYAT_CACHE_TTL, 'data' => $rows];
    return $rows;
}

function riwayat_muat(string $pnsId, string $kategori): ?array
{
    switch ($kategori) {
        case 'pendidikan':
            $src = riwayat_api('pendidikan', $pnsId);
            return $src === null ? null : riwayat_bentuk($src, 'riwayat_baris_pendidikan', 'pendidikan');
        case 'diklat':
            $pim = riwayat_api('diklatpim', $pnsId);
            $krs = riwayat_api('kursus', $pnsId);
            if ($pim === null && $krs === null) return null;
            return array_merge(riwayat_bentuk($pim ?? [], 'riwayat_baris_diklatpim', 'diklatpim'), riwayat_bentuk($krs ?? [], 'riwayat_baris_kursus', 'kursus'));
        case 'keluarga':
            $src = riwayat_api('pasangan', $pnsId);
            return $src === null ? null : riwayat_bentuk($src, 'riwayat_baris_pasangan', 'pasangan');
        case 'pangkat':
            $src = riwayat_api('golongan', $pnsId);
            return $src === null ? null : riwayat_bentuk($src, 'riwayat_baris_golongan', 'golongan');
        case 'penghargaan':
            $src = riwayat_api('penghargaan', $pnsId);
            return $src === null ? null : riwayat_bentuk($src, 'riwayat_baris_penghargaan', 'penghargaan');
        case 'cpns':
            $src = riwayat_api('cpns', $pnsId);
            return $src === null ? null : riwayat_bentuk($src, 'riwayat_baris_cpns', 'cpns');
        case 'jabatan':
        case 'tugas':
            $src = riwayat_api('jabatan', $pnsId);
            if ($src === null) return null;
            // Seperti SIMPEG: jabatan = penugasan definitif (D/kosong), tugas tambahan = selain definitif (TT/PLT/PJ).
            $src = array_filter($src, static function (array $g) use ($kategori): bool {
                $pg = strtoupper(trim((string)($g['jenis_penugasan'] ?? '')));
                return ($kategori === 'tugas') !== ($pg === '' || $pg === 'D');
            });
            return riwayat_bentuk(array_values($src), 'riwayat_baris_jabatan', $kategori);
        case 'kredit':
            $src = riwayat_api('pak', $pnsId);
            return $src === null ? null : riwayat_bentuk($src, 'riwayat_baris_pak', 'pak');
        case 'kinerja':
            $src = riwayat_api('kinerja', $pnsId);
            return $src === null ? null : riwayat_bentuk($src, 'riwayat_baris_kinerja', 'kinerja');
        case 'disiplin':
            $src = riwayat_api('hukdis', $pnsId);
            return $src === null ? null : riwayat_bentuk($src, 'riwayat_baris_hukdis', 'hukdis');
    }
    return null;
}

/**
 * Bentuk baris tampilan + jenis usulan (sama dengan jenis usulan_riwayat API Go) + nilai awal form usulan "ubah".
 * Nilai awal hanya berisi kolom data (path berkas tidak pernah ikut), lihat riwayat_usul_awal().
 */
function riwayat_bentuk(array $src, callable $baris, string $jenis): array
{
    $out = [];
    foreach ($src as $g) {
        $row = $baris($g);
        $row['jenis'] = $jenis;
        $row['awal'] = function_exists('riwayat_usul_awal') ? riwayat_usul_awal($jenis, $g) : [];
        $out[] = $row;
    }
    return $out;
}

/* ---------- Pembentuk baris per kategori ---------- */

function rw_s(array $g, string $k): string
{
    return trim((string)($g[$k] ?? ''));
}

/** Tanggal ISO (YYYY-MM-DD...) -> "5 Jan 2024"; teks lain dikembalikan apa adanya. */
function rw_tgl(string $v): string
{
    if ($v === '' || strpos($v, '0000-00-00') === 0) return '';
    return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? tanggal_id(substr($v, 0, 10)) : $v;
}

/** "No. SK, tgl" tanpa koma menggantung. */
function rw_sk(string $no, string $tgl): string
{
    return implode(', ', array_filter([$no, rw_tgl($tgl)], 'strlen'));
}

/**
 * $x (tampilan): kolom = [[judul kolom, teks, badge?]] tabel desktop (seperti SIMPEG); badge = badge kecil di judul kartu mobile;
 * b3 = baris ketiga kartu mobile; wajib = kolom dokumen wajib untuk "Lengkap" (elemen array = salah satu cukup).
 */
function rw_baris(array $g, string $judul, string $sub, string $waktu, string $urut, array $info, array $dok, string $label = '', array $x = []): array
{
    $labelDok = array_column($dok, 0, 1);
    $kurang = [];
    foreach ($x['wajib'] ?? [] as $w) {
        $ada = false;
        foreach ((array)$w as $k) { $v = rw_s($g, $k); if ($v !== '' && $v !== '-') $ada = true; }
        if (!$ada) $kurang[] = implode(' / ', array_map(static function ($k) use ($labelDok) { return $labelDok[$k] ?? $k; }, (array)$w));
    }
    // Dokumen hanya ditautkan bila path berkas repository valid (berkas arsip lama belum bisa diunduh).
    $berkas = [];
    foreach ($dok as $i => [, $kolom]) {
        if (preg_match(RIWAYAT_DOK_POLA, rw_s($g, $kolom))) $berkas[$kolom] = rw_s($g, $kolom);
        else unset($dok[$i]);
    }
    return [
        'id_rw' => rw_s($g, 'id_rw'),
        'judul' => $judul !== '' ? $judul : '-',
        'sub'   => $sub,
        'label' => $label,
        'waktu' => $waktu,
        'urut'  => $urut,
        // Baris info kosong tidak ditampilkan.
        'info'  => array_values(array_filter($info, static function (array $i): bool { return trim((string)$i[1]) !== ''; })),
        'dok'   => array_values($dok),
        // sel tabel: [judul, teks, badge, keterangan kecil di bawah, badge di atas teks?]
        'kolom' => array_map(static function (array $c): array { return [$c[0], trim((string)$c[1]), (string)($c[2] ?? ''), (string)($c[3] ?? ''), !empty($c[4])]; }, $x['kolom'] ?? []),
        'badge' => (string)($x['badge'] ?? ''),
        'b3'    => (string)($x['b3'] ?? $waktu),
        'lengkap' => isset($x['wajib']) ? !$kurang : null,
        'kurang' => $kurang,
        // Path berkas hanya disimpan di sesi (server) untuk riwayat_dok.php, tidak pernah dikirim ke browser.
        'berkas' => $berkas,
    ];
}

function riwayat_baris_pendidikan(array $g): array
{
    $tgl = rw_s($g, 'tgl_lulus');
    return rw_baris($g,
        implode(' – ', array_filter([rw_s($g, 'tk_pendidikan_nama'), rw_s($g, 'jurusan_nama')], 'strlen')),
        implode(', ', array_filter([rw_s($g, 'nama_sekolah'), rw_s($g, 'kota')], 'strlen')),
        $tgl !== '' ? rw_tgl($tgl) : rw_s($g, 'tahun_lulus'),
        $tgl !== '' ? $tgl : rw_s($g, 'tahun_lulus'),
        [['No. Ijazah', rw_s($g, 'no_ijazah')], ['Gelar depan', rw_s($g, 'gelar_depan')],
         ['Gelar belakang', rw_s($g, 'gelar_belakang')], ['Pimpinan', rw_s($g, 'pimpinan')]],
        [['Ijazah', 'ijazah'], ['Transkrip', 'transkrip']],
        rw_s($g, 'pertama') === '1' ? 'Pendidikan pertama' : '',
        ['kolom' => [['Tingkat', rw_s($g, 'tk_pendidikan_nama'), rw_s($g, 'pertama') === '1' ? 'Pertama' : ''], ['Jurusan / Program Studi', rw_s($g, 'jurusan_nama')],
            ['Nama Sekolah / PT', rw_s($g, 'nama_sekolah')], ['Lulus', rw_s($g, 'tahun_lulus') ?: substr($tgl, 0, 4)],
            ['Gelar', trim(rw_s($g, 'gelar_depan') . ' ' . rw_s($g, 'gelar_belakang'))]],
         'badge' => trim(rw_s($g, 'gelar_depan') . ' ' . rw_s($g, 'gelar_belakang')), 'wajib' => ['ijazah', 'transkrip']]);
}

function riwayat_baris_diklatpim(array $g): array
{
    $mulai = rw_s($g, 'tgl_mulai');
    return rw_baris($g, rw_s($g, 'latihan_nama'), rw_s($g, 'penyelenggara'),
        $mulai !== '' ? rw_tgl($mulai) : rw_s($g, 'tahun'), $mulai !== '' ? $mulai : rw_s($g, 'tahun'),
        [['Pelaksanaan', implode(' s.d. ', array_filter([rw_tgl($mulai), rw_tgl(rw_s($g, 'tgl_selesai'))], 'strlen'))],
         ['Jumlah jam', (int)($g['jam'] ?? 0) > 0 ? (int)$g['jam'] . ' JP' : ''],
         ['No. Sertifikat', rw_s($g, 'nomor')], ['Kompetensi', rw_s($g, 'jenis_kompetensi')]],
        [['Sertifikat', 'sertifikat']], 'Diklat Struktural',
        ['kolom' => [['Diklat Struktural', rw_s($g, 'latihan_nama'), '', rw_s($g, 'nomor') !== '' ? 'No. ' . rw_s($g, 'nomor') : ''], ['Penyelenggara', rw_s($g, 'penyelenggara')],
            ['Tanggal', implode(' s.d. ', array_filter([rw_tgl($mulai), rw_tgl(rw_s($g, 'tgl_selesai'))], 'strlen'))], ['Jam', (int)($g['jam'] ?? 0) > 0 ? (string)(int)$g['jam'] : '']],
         'b3' => implode(' · ', array_filter([rw_tgl($mulai) ?: rw_s($g, 'tahun'), 'Diklat Struktural'], 'strlen')), 'wajib' => ['sertifikat']]);
}

function riwayat_baris_kursus(array $g): array
{
    $mulai = rw_s($g, 'tgl_mulai');
    return rw_baris($g, rw_s($g, 'nama'), rw_s($g, 'penyelenggara'),
        $mulai !== '' ? rw_tgl($mulai) : rw_s($g, 'tahun'), $mulai !== '' ? $mulai : rw_s($g, 'tahun'),
        [['Pelaksanaan', implode(' s.d. ', array_filter([rw_tgl($mulai), rw_tgl(rw_s($g, 'tgl_selesai'))], 'strlen'))],
         ['Jumlah jam', (int)($g['jam'] ?? 0) > 0 ? (int)$g['jam'] . ' JP' : ''],
         ['No. Sertifikat', rw_s($g, 'no_sertifikat')], ['Lokasi', rw_s($g, 'lokasi')]],
        [['Sertifikat', 'sertifikat']], rw_s($g, 'jenis_diklat_nama') ?: rw_s($g, 'jenis_kursus_nama'),
        ['kolom' => [['Diklat / Kursus', rw_s($g, 'nama'), rw_s($g, 'jenis_diklat_nama') ?: rw_s($g, 'jenis_kursus_nama'),
                rw_s($g, 'no_sertifikat') !== '' ? 'No. ' . rw_s($g, 'no_sertifikat') : '', true],
            ['Penyelenggara', rw_s($g, 'penyelenggara')],
            ['Tanggal', implode(' s.d. ', array_filter([rw_tgl($mulai), rw_tgl(rw_s($g, 'tgl_selesai'))], 'strlen'))], ['Jam', (int)($g['jam'] ?? 0) > 0 ? (string)(int)$g['jam'] : '']],
         'b3' => implode(' · ', array_filter([rw_tgl($mulai) ?: rw_s($g, 'tahun'), rw_s($g, 'jenis_diklat_nama') ?: rw_s($g, 'jenis_kursus_nama')], 'strlen')), 'wajib' => ['sertifikat']]);
}

/** Referensi agama aktif [id => nama] (tbl_agama: id_agm, nmagama), cache sesi; cadangan bila API gagal. */
function riwayat_ref_agama(): array
{
    if (isset($_SESSION['riwayat_ref_agama']) && is_array($_SESSION['riwayat_ref_agama'])) return $_SESSION['riwayat_ref_agama'];
    $list = [];
    [$kode, $json] = riwayat_api_get(API_TEMANKU, '/agama');
    if ($kode === 200 && is_array($json)) {
        foreach (api_rows($json) as $a) {
            if (!is_array($a) || (isset($a['aktif']) && (int)$a['aktif'] !== 1)) continue;
            $id = (string)(int)($a['id_agm'] ?? ($a['id'] ?? 0));
            $nm = trim((string)($a['nmagama'] ?? ($a['nama'] ?? ($a['agama'] ?? ''))));
            if ($id !== '0' && $nm !== '') $list[$id] = $nm;
        }
    }
    if (!$list) return RIWAYAT_REF_AGAMA_CADANGAN;
    return $_SESSION['riwayat_ref_agama'] = $list;
}
const RIWAYAT_REF_AGAMA_CADANGAN = ['1' => 'Islam', '2' => 'Kristen', '3' => 'Katholik', '4' => 'Hindu', '5' => 'Buddha', '6' => 'Konghucu', '7' => 'Lainnya'];

function riwayat_baris_pasangan(array $g): array
{
    $status = ['1' => 'Menikah', '2' => 'Cerai', '3' => 'Meninggal'][rw_s($g, 'status_pernikahan')] ?? '';
    $nama = trim(implode(' ', array_filter([rw_s($g, 'gelar_depan'), rw_s($g, 'nama')], 'strlen'))
        . (rw_s($g, 'gelar_belakang') !== '' ? ', ' . rw_s($g, 'gelar_belakang') : ''));
    $nikah = rw_s($g, 'tgl_akta_menikah');
    $ke = (int)($g['pasangan_ke'] ?? 0);
    return rw_baris($g, $nama, implode(' · ', array_filter([$ke > 0 ? 'Pasangan ke-' . $ke : 'Pasangan', $status], 'strlen')),
        rw_tgl($nikah), $nikah,
        [['Tempat, tgl lahir', implode(', ', array_filter([rw_s($g, 'tempat_lahir'), rw_tgl(rw_s($g, 'tgl_lahir'))], 'strlen'))],
         ['Akta nikah', rw_sk(rw_s($g, 'no_akta_menikah'), $nikah)],
         ['Akta cerai', rw_sk(rw_s($g, 'no_akta_cerai'), rw_s($g, 'tgl_cerai'))],
         ['Akta kematian', rw_sk(rw_s($g, 'no_akta_meninggal'), rw_s($g, 'tgl_meninggal'))],
         ['Agama', rw_s($g, 'agama_id') !== '' ? (riwayat_ref_agama()[(string)(int)rw_s($g, 'agama_id')] ?? rw_s($g, 'agama_nama')) : rw_s($g, 'agama_nama')],
         ['NIK', rw_s($g, 'nomor_identitas')],
         ['Pekerjaan', (int)($g['is_pns'] ?? 0) === 1 ? 'PNS' : '']],
        [['Buku/akta nikah', 'akta']], '',
        ['kolom' => [['Pasangan', $nama, $ke > 0 ? 'Ke-' . $ke : ''], ['Tanggal Lahir', rw_tgl(rw_s($g, 'tgl_lahir'))],
            ['Akta Nikah', rw_sk(rw_s($g, 'no_akta_menikah'), $nikah)], ['Status', $status]],
         'badge' => $status, 'b3' => $nikah !== '' ? 'Menikah ' . rw_tgl($nikah) : '', 'wajib' => ['akta']]);
}

const RIWAYAT_GOLONGAN = [
    11 => ['I/a', 'Juru Muda'], 12 => ['I/b', 'Juru Muda Tingkat I'], 13 => ['I/c', 'Juru'], 14 => ['I/d', 'Juru Tingkat I'],
    21 => ['II/a', 'Pengatur Muda'], 22 => ['II/b', 'Pengatur Muda Tingkat I'], 23 => ['II/c', 'Pengatur'], 24 => ['II/d', 'Pengatur Tingkat I'],
    31 => ['III/a', 'Penata Muda'], 32 => ['III/b', 'Penata Muda Tingkat I'], 33 => ['III/c', 'Penata'], 34 => ['III/d', 'Penata Tingkat I'],
    41 => ['IV/a', 'Pembina'], 42 => ['IV/b', 'Pembina Tingkat I'], 43 => ['IV/c', 'Pembina Utama Muda'], 44 => ['IV/d', 'Pembina Utama Madya'],
    45 => ['IV/e', 'Pembina Utama'],
];
const RIWAYAT_JENIS_KP = [
    '101' => 'Reguler', '201' => 'Pilihan (Jabatan Struktural)', '202' => 'Pilihan (Jabatan Fungsional Tertentu)',
    '203' => 'Pilihan (Penyesuaian Ijazah)', '211' => 'Gol. dari Pengadaan CPNS/PNS',
];

function riwayat_baris_golongan(array $g): array
{
    $kode = (int)($g['id_gol'] ?? 0);
    [$gol, $pangkat] = RIWAYAT_GOLONGAN[$kode] ?? [$kode > 0 ? (string)$kode : '', ''];
    $jenis = rw_s($g, 'jenis');
    $tmt = rw_s($g, 'tmt_tgl');
    $gaji = rw_s($g, 'gaji');
    return rw_baris($g, implode(' – ', array_filter([$gol, $pangkat], 'strlen')), RIWAYAT_JENIS_KP[$jenis] ?? $jenis,
        rw_tgl($tmt), $tmt,
        [['TMT', rw_tgl($tmt)], ['No. & tgl SK', rw_sk(rw_s($g, 'no_sk'), rw_s($g, 'tgl_sk'))],
         ['Masa kerja', (int)($g['tahun'] ?? 0) . ' tahun ' . (int)($g['bulan'] ?? 0) . ' bulan'],
         ['Gaji pokok', ctype_digit($gaji) && (int)$gaji > 0 ? rupiah($gaji) : ''],
         ['Pertek BKN', rw_sk(rw_s($g, 'nopertek'), rw_s($g, 'tglpertek'))]],
        [['SK pangkat', 'scan'], ['Pertek', 'dok_pertek']], '',
        ['kolom' => [['Golongan', $gol], ['Pangkat', $pangkat], ['Jenis KP', RIWAYAT_JENIS_KP[$jenis] ?? $jenis], ['TMT', rw_tgl($tmt)],
            ['No. & Tgl SK', rw_sk(rw_s($g, 'no_sk'), rw_s($g, 'tgl_sk'))], ['Masa Kerja', (int)($g['tahun'] ?? 0) . ' th ' . (int)($g['bulan'] ?? 0) . ' bl']],
         'badge' => $gol, 'b3' => $tmt !== '' ? 'TMT ' . rw_tgl($tmt) : '', 'wajib' => ['scan']]);
}

function riwayat_baris_penghargaan(array $g): array
{
    $tgl = rw_s($g, 'tgl_sk');
    $tahun = (int)($g['tahun'] ?? 0);
    return rw_baris($g, rw_s($g, 'jenis_nama') ?: rw_s($g, 'jenis'), rw_s($g, 'kelompok'),
        $tgl !== '' ? rw_tgl($tgl) : ($tahun > 0 ? (string)$tahun : ''), $tgl !== '' ? $tgl : (string)$tahun,
        [['No. & tgl SK', rw_sk(rw_s($g, 'no_sk'), $tgl)], ['Tahun', $tahun > 0 ? (string)$tahun : '']],
        [['SK penghargaan', 'scan']], '',
        ['kolom' => [['Penghargaan', rw_s($g, 'jenis_nama') ?: rw_s($g, 'jenis')], ['Tahun', $tahun > 0 ? (string)$tahun : ''], ['No. & Tgl SK', rw_sk(rw_s($g, 'no_sk'), $tgl)]],
         'b3' => implode(' · ', array_filter([$tahun > 0 ? 'Tahun ' . $tahun : '', rw_s($g, 'no_sk')], 'strlen')), 'wajib' => ['scan']]);
}

function riwayat_baris_cpns(array $g): array
{
    $tmt = rw_s($g, 'tmt_pns') ?: rw_s($g, 'tmt_cpns');
    return rw_baris($g, 'Status ' . (rw_s($g, 'status') ?: 'CPNS/PNS'), rw_s($g, 'jabatan_angkat'),
        rw_tgl($tmt), $tmt,
        [['TMT CPNS', rw_tgl(rw_s($g, 'tmt_cpns'))], ['SK CPNS', rw_sk(rw_s($g, 'no_sk_cpns'), rw_s($g, 'tgl_sk_cpns'))],
         ['TMT PNS', rw_tgl(rw_s($g, 'tmt_pns'))], ['SK PNS', rw_sk(rw_s($g, 'no_sk_pns'), rw_s($g, 'tgl_sk_pns'))],
         ['SPMT', rw_sk(rw_s($g, 'no_spmt'), rw_s($g, 'tgl_spmt'))], ['TMT tugas', rw_tgl(rw_s($g, 'tmt_tugas'))],
         ['STTPL', rw_sk(rw_s($g, 'no_sttpl'), rw_s($g, 'tgl_sttpl'))], ['Surat ket. dokter', rw_sk(rw_s($g, 'no_dokter'), rw_s($g, 'tgl_dokter'))],
         ['Pertek CPNS/PNS > 2 tahun', rw_sk(rw_s($g, 'pertek_nomor'), rw_s($g, 'pertek_tgl'))], ['Karpeg', rw_s($g, 'karpeg')]],
        [['SK CPNS', 'sk_cpns'], ['SK PNS', 'sk_pns'], ['SPMT', 'spmt']], '',
        ['kolom' => [['Status', rw_s($g, 'status'), rw_s($g, 'status')], ['SK CPNS', rw_sk(rw_s($g, 'no_sk_cpns'), rw_s($g, 'tgl_sk_cpns'))], ['TMT CPNS', rw_tgl(rw_s($g, 'tmt_cpns'))],
            ['SK PNS', rw_sk(rw_s($g, 'no_sk_pns'), rw_s($g, 'tgl_sk_pns'))], ['TMT PNS', rw_tgl(rw_s($g, 'tmt_pns'))]],
         'badge' => rw_s($g, 'status'), 'b3' => implode(' · ', array_filter([rw_s($g, 'tmt_cpns') !== '' ? 'TMT CPNS ' . rw_tgl(rw_s($g, 'tmt_cpns')) : '', rw_s($g, 'tmt_pns') !== '' ? 'TMT PNS ' . rw_tgl(rw_s($g, 'tmt_pns')) : ''], 'strlen')),
         'wajib' => strtoupper(rw_s($g, 'status')) === 'PNS' ? ['sk_cpns', 'sk_pns'] : ['sk_cpns']]);
}

function riwayat_baris_jabatan(array $g): array
{
    $pg = strtoupper(rw_s($g, 'jenis_penugasan'));
    [$penugasan, $sebutan] = ['TT' => ['Tugas Tambahan', ''], 'PLT' => ['Pelaksana Tugas', 'Plt. '], 'PJ' => ['Penjabat', 'Pj. ']][$pg]
        ?? [$pg === '' || $pg === 'D' ? '' : $pg, ''];
    $tmt = rw_s($g, 'tmt_jabatan');
    return rw_baris($g, $sebutan . rw_s($g, 'jabatan_nama'), rw_s($g, 'unor_nama'), rw_tgl($tmt), $tmt,
        [['Jenis jabatan', ['1' => 'Struktural', '2' => 'Fungsional', '4' => 'Pelaksana'][rw_s($g, 'jenis')] ?? ''],
         ['No. & tgl SK', rw_sk(rw_s($g, 'no_sk'), rw_s($g, 'tgl_sk'))],
         ['TMT pelantikan', rw_tgl(rw_s($g, 'tmt_pelantikan'))],
         ['Mutasi', ['MJ' => 'Mutasi Jabatan', 'MU' => 'Mutasi Unor'][rw_s($g, 'jenis_mutasi')] ?? ''],
         ['TMT mutasi', rw_tgl(rw_s($g, 'tmt_mutasi'))]],
        [['SK jabatan', 'sk'], ['Pelantikan', 'lantik'], ['SK mutasi', 'sk_mutasi']], $penugasan,
        ['kolom' => [[$penugasan !== '' ? 'Tugas' : 'Jabatan', $sebutan . rw_s($g, 'jabatan_nama'), $penugasan !== '' ? $penugasan : (['1' => 'Struktural', '2' => 'Fungsional', '4' => 'Pelaksana'][rw_s($g, 'jenis')] ?? '')],
            ['Unit Organisasi', rw_s($g, 'unor_nama')], ['No. & Tgl SK', rw_sk(rw_s($g, 'no_sk'), rw_s($g, 'tgl_sk'))], [$penugasan !== '' ? 'TMT' : 'TMT Jabatan', rw_tgl($tmt)]],
         'badge' => $penugasan !== '' ? $penugasan : (['1' => 'Struktural', '2' => 'Fungsional', '4' => 'Pelaksana'][rw_s($g, 'jenis')] ?? ''),
         'b3' => $tmt !== '' ? 'TMT ' . rw_tgl($tmt) : '', 'wajib' => [['sk', 'sk_mutasi']]]);
}

function riwayat_baris_pak(array $g): array
{
    $angka = static function ($v): string {
        return is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ',') : '';
    };
    $periode = static function ($bln, $thn): string {
        return (int)$thn > 0 ? ((int)$bln > 0 ? sprintf('%02d/', (int)$bln) : '') . (int)$thn : '';
    };
    $tgl = rw_s($g, 'tgl_sk');
    $label = (int)($g['pertama'] ?? 0) === 1 ? 'PAK pertama' : ((int)($g['integrasi'] ?? 0) === 1 ? 'Integrasi' : ((int)($g['konversi'] ?? 0) === 1 ? 'Konversi' : ''));
    return rw_baris($g, 'Total angka kredit ' . ($angka($g['total'] ?? null) ?: '-'),
        'Periode ' . (implode(' s.d. ', array_filter([$periode($g['bln_mulai'] ?? 0, $g['thn_mulai'] ?? 0), $periode($g['bln_selesai'] ?? 0, $g['thn_selesai'] ?? 0)], 'strlen')) ?: '-'),
        rw_tgl($tgl), $tgl,
        [['No. & tgl SK', rw_sk(rw_s($g, 'no_sk'), $tgl)], ['Unsur utama', $angka($g['utama'] ?? null)], ['Unsur penunjang', $angka($g['penunjang'] ?? null)]],
        [['Dokumen PAK', 'dok_pak'], ['SK PAK', 'sk_pak']], $label,
        ['kolom' => [['No. SK', rw_s($g, 'no_sk'), $label], ['Tgl SK', rw_tgl($tgl)],
            ['Periode', implode(' s.d. ', array_filter([$periode($g['bln_mulai'] ?? 0, $g['thn_mulai'] ?? 0), $periode($g['bln_selesai'] ?? 0, $g['thn_selesai'] ?? 0)], 'strlen'))],
            ['Utama', $angka($g['utama'] ?? null)], ['Penunjang', $angka($g['penunjang'] ?? null)], ['Total', $angka($g['total'] ?? null)]],
         'badge' => $label, 'b3' => implode(' · ', array_filter([rw_s($g, 'no_sk'), rw_tgl($tgl)], 'strlen')), 'wajib' => [['dok_pak', 'sk_pak']]]);
}

function riwayat_baris_kinerja(array $g): array
{
    $tahun = (int)($g['tahun'] ?? 0);
    return rw_baris($g, 'Sasaran Kinerja Pegawai (SKP)', rw_s($g, 'kuadran_nama') !== '' ? 'Predikat: ' . rw_s($g, 'kuadran_nama') : '',
        $tahun > 0 ? (string)$tahun : '', (string)$tahun,
        [['Hasil kerja', rw_s($g, 'hasil_nama')], ['Perilaku kerja', rw_s($g, 'perilaku_nama')],
         ['Pejabat penilai', implode(' · ', array_filter([rw_s($g, 'penilai_nama'), rw_s($g, 'penilai_jabatan')], 'strlen'))]],
        [['Dokumen SKP', 'scan']], '',
        ['kolom' => [['Tahun', $tahun > 0 ? (string)$tahun : ''], ['Hasil Kerja', rw_s($g, 'hasil_nama')], ['Perilaku Kerja', rw_s($g, 'perilaku_nama')],
            ['Predikat', rw_s($g, 'kuadran_nama')], ['Pejabat Penilai', rw_s($g, 'penilai_nama')]],
         'badge' => $tahun > 0 ? (string)$tahun : '', 'b3' => implode(' · ', array_filter([rw_s($g, 'hasil_nama') !== '' ? 'Hasil ' . rw_s($g, 'hasil_nama') : '', rw_s($g, 'perilaku_nama') !== '' ? 'Perilaku ' . rw_s($g, 'perilaku_nama') : ''], 'strlen')),
         'wajib' => ['scan']]);
}

function riwayat_baris_hukdis(array $g): array
{
    $tmt = rw_s($g, 'tmt');
    $masa = trim(((int)($g['masa_tahun'] ?? 0) > 0 ? (int)$g['masa_tahun'] . ' tahun ' : '') . ((int)($g['masa_bulan'] ?? 0) > 0 ? (int)$g['masa_bulan'] . ' bulan' : ''));
    return rw_baris($g, rw_s($g, 'tindakan_nama') ?: rw_s($g, 'tindakan'), rw_s($g, 'alasan_nama') ?: rw_s($g, 'alasan'),
        rw_tgl($tmt), $tmt,
        [['No. & tgl SK', rw_sk(rw_s($g, 'no_sk'), rw_s($g, 'tgl_sk'))], ['Masa hukuman', $masa],
         ['Berakhir', rw_tgl(rw_s($g, 'akhir'))], ['Peraturan', rw_s($g, 'pp')], ['Keterangan', rw_s($g, 'keterangan')],
         ['Pembatalan', rw_sk(rw_s($g, 'no_batal'), rw_s($g, 'tgl_batal'))]],
        [['SK hukuman', 'scan']], rw_s($g, 'tingkat') !== '' ? 'Tingkat ' . rw_s($g, 'tingkat') : '',
        ['kolom' => [['Hukuman', rw_s($g, 'tindakan_nama') ?: rw_s($g, 'tindakan')], ['Tingkat', rw_s($g, 'tingkat')], ['No. & Tgl SK', rw_sk(rw_s($g, 'no_sk'), rw_s($g, 'tgl_sk'))],
            ['TMT', rw_tgl($tmt)], ['Masa', $masa]],
         'badge' => rw_s($g, 'tingkat'), 'b3' => implode(' · ', array_filter([$tmt !== '' ? 'TMT ' . rw_tgl($tmt) : '', $masa], 'strlen')), 'wajib' => ['scan']]);
}

/* ---------- Identitas (data utama SIMPEG/SIASN) ---------- */

/** Label kolom data utama SIASN (GET /asn/data-utama/{PNS_ID}) yang hanya dibaca, per kelompok. */
const RIWAYAT_IDENTITAS = [
    'Data pribadi' => [
        'nipBaru' => ['NIP', 'bi-person-vcard'], 'nipLama' => ['NIP lama', 'bi-person-vcard'], 'nik' => ['NIK', 'bi-credit-card-2-front'],
        'tglLahir' => ['Tanggal lahir', 'bi-calendar3'], 'jenisKelamin' => ['Jenis kelamin', 'bi-gender-ambiguous'],
    ],
    'Kepegawaian' => [
        'statusPegawai' => ['Status pegawai', 'bi-person-badge'], 'kedudukanPnsNama' => ['Kedudukan', 'bi-patch-check'],
        'golRuangAkhir' => ['Golongan / ruang', 'bi-arrow-up-circle'], 'jabatanNama' => ['Jabatan', 'bi-briefcase'],
        'jenisJabatan' => ['Jenis jabatan', 'bi-tags'], 'eselonLevel' => ['Eselon', 'bi-diagram-3'], 'tmtJabatan' => ['TMT jabatan', 'bi-calendar3'],
        'unorNama' => ['Unit organisasi', 'bi-building'], 'unorIndukNama' => ['Unit organisasi induk', 'bi-buildings'],
        'tmtCpns' => ['TMT CPNS', 'bi-calendar3'], 'tmtPns' => ['TMT PNS', 'bi-calendar3'], 'masaKerja' => ['Masa kerja', 'bi-hourglass-split'],
    ],
    'Pendidikan terakhir' => [
        'pendidikanTerakhirNama' => ['Pendidikan', 'bi-mortarboard'], 'tkPendidikanTerakhir' => ['Tingkat', 'bi-bar-chart-steps'],
        'tahunLulus' => ['Tahun lulus', 'bi-calendar-check'],
    ],
];

/** Pilihan identitas yang dapat diubah (sama dengan referensi.php SIMPEG). */
const RIWAYAT_REF_KAWIN = ['1' => 'Menikah', '2' => 'Janda', '3' => 'Duda', '4' => 'Belum Menikah'];
const RIWAYAT_REF_GOL_DARAH = ['A', 'B', 'AB', 'O'];
/** Kolom identitas yang juga dikirim ke SIASN BKN bila diubah (identitas.save.php SIMPEG). */
const RIWAYAT_IDT_BKN = ['agamaId', 'tempatLahirId', 'alamat', 'noHp', 'email', 'emailGov'];

/**
 * Identitas pegawai yang login: nama, NIP, kelompok data SIASN (hanya baca) dan nilai yang dapat diubah
 * (GET /asn/data-utama/{PNS_ID}/identitas, seperti tab Identitas SIMPEG). null bila API gagal.
 */
function riwayat_identitas(array $pegawai, bool $segar = false): ?array
{
    $cache = $_SESSION['riwayat_identitas'] ?? null;
    if (!$segar && is_array($cache) && ($cache['pns_id'] ?? '') === $pegawai['pns_id'] && $cache['sampai'] > time()) return $cache['data'];
    [$kode, $json] = riwayat_api_get(API_TEMANKU, '/asn/data-utama/' . rawurlencode($pegawai['pns_id']));
    if ($kode < 200 || $kode >= 300 || !is_array($json)) {
        $GLOBALS['riwayat_api_kode'] = $kode;
        error_log('TEMANKU riwayat identitas: HTTP ' . $kode);
        return null;
    }
    $d = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
    $nama = trim(implode(' ', array_filter([rw_s($d, 'gelarDepan'), rw_s($d, 'nama')], 'strlen')) . (rw_s($d, 'gelarBelakang') !== '' ? ', ' . rw_s($d, 'gelarBelakang') : ''));
    $kelompok = [];
    foreach (RIWAYAT_IDENTITAS as $judul => $kolom) {
        foreach ($kolom as $k => [$label, $ikon]) {
            $v = rw_s($d, $k);
            if ($v === '' || $v === '-' || $v === '0') continue;
            if (preg_match('/^tgl|^tmt/i', $k)) $v = rw_tgl(preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $v, $m) ? $m[3] . '-' . $m[2] . '-' . $m[1] : $v);
            if ($k === 'jenisKelamin') $v = ['M' => 'Laki-laki', 'L' => 'Laki-laki', 'F' => 'Perempuan', 'P' => 'Perempuan'][strtoupper($v)] ?? $v;
            if ($k === 'golRuangAkhir' && ctype_digit($v) && isset(RIWAYAT_GOLONGAN[(int)$v])) $v = implode(' – ', RIWAYAT_GOLONGAN[(int)$v]);
            $kelompok[$judul][] = [$label, $v, $ikon];
        }
    }
    // Nilai yang dapat diubah: dari endpoint identitas (kolom SIMPEG); cadangan dari data utama.
    [$kodeI, $idt] = riwayat_api_get(API_TEMANKU, '/asn/data-utama/' . rawurlencode($pegawai['pns_id']) . '/identitas');
    $siap = $kodeI === 200 && is_array($idt);
    $idt = $siap ? (isset($idt['data']) && is_array($idt['data']) && !isset($idt['id']) ? $idt['data'] : $idt) : [];
    $ambil = static function (string $k, string $cadangan = '') use ($idt, $d): string {
        $v = isset($idt[$k]) && is_scalar($idt[$k]) ? trim((string)$idt[$k]) : '';
        return $v !== '' && $v !== '0' ? $v : rw_s($d, $cadangan !== '' ? $cadangan : $k);
    };
    $ubah = [
        'agamaId' => $ambil('agamaId'), 'agama' => rw_s($d, 'agama'),
        'jenisKawinId' => $ambil('jenisKawinId'), 'golDarah' => strtoupper($ambil('golDarah')),
        'tempatLahirId' => $ambil('tempatLahirId'), 'tempatLahir' => $ambil('tempatLahir'),
        'alamat' => $ambil('alamat'), 'noHp' => $ambil('noHp'), 'email' => $ambil('email'), 'emailGov' => $ambil('emailGov'),
    ];
    $ubah['kawin'] = RIWAYAT_REF_KAWIN[$ubah['jenisKawinId']] ?? rw_s($d, 'statusPerkawinan');
    $data = ['nama' => $nama !== '' ? $nama : rw_s($d, 'nama'), 'nip' => rw_s($d, 'nipBaru') ?: $pegawai['nip'], 'kelompok' => $kelompok,
        'ubah' => $ubah, 'ubahSiap' => $siap];
    $_SESSION['riwayat_identitas'] = ['pns_id' => $pegawai['pns_id'], 'sampai' => time() + RIWAYAT_CACHE_TTL, 'data' => $data];
    return $data;
}

/** Judul kolom tabel per jenis (untuk tabel kosong di desktop). */
function riwayat_kolom_judul(string $jenis): array
{
    $fn = $jenis === 'tugas' ? 'riwayat_baris_jabatan' : 'riwayat_baris_' . $jenis;
    if (!function_exists($fn)) return [];
    $b = $fn($jenis === 'tugas' ? ['jenis_penugasan' => 'TT'] : []);
    return array_column($b['kolom'], 0);
}
