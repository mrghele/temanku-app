<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/siasn_sync.php';

/*
 * Sync riwayat dari SIASN BKN untuk pegawai yang login (port sync_pegawai_lib.php + *.sync.php SIMPEG):
 *   tahap 1 (data)  GET SIASN rw-* -> PUT /asn/rw-{jenis}/pns/{PNS_ID}/sync (riwayat yang hilang di SIASN ikut terhapus)
 *   tahap 2 (dok)   satu dokumen per permintaan: download-dok SIASN -> /upload-dok -> PATCH /asn/rw-{jenis}/{id}/dokumen
 * Baris SIASN milik PNS lain selalu dilewati; PNS_ID & NIP hanya dari sesi.
 */

const RIWAYAT_SYNC_ENDPOINT = [
    'pendidikan' => 'rw-pendidikan', 'golongan' => 'rw-golongan', 'penghargaan' => 'rw-penghargaan', 'hukdis' => 'rw-hukdis',
    'kinerja' => 'rw-kinerja', 'pak' => 'rw-pak', 'cpns' => 'rw-cpns', 'jabatan' => 'rw-jabatan', 'kursus' => 'rw-kursus',
    'diklatpim' => 'rw-diklatpim', 'pasangan' => 'rw-pasangan',
];

/** Jenis sync per kategori halaman (tugas tambahan ada di riwayat jabatan SIASN). */
function riwayat_sync_jenis(string $kategori, string $hanya = ''): array
{
    $jenis = RIWAYAT_KATEGORI_JENIS[$kategori] ?? [];
    // Sync per seksi (mis. Diklat Struktural vs Diklat/Kursus): hanya jenis itu, endpoint SIASN masing-masing (seperti SIMPEG).
    if ($hanya !== '' && in_array($hanya, $jenis, true)) $jenis = [$hanya];
    return array_values(array_unique(array_map(static function (string $j): string { return $j === 'tugas' ? 'jabatan' : $j; }, $jenis)));
}

/** Kunci sesi daftar dokumen tahap 2: per kategori, atau per kategori + jenis bila sync satu seksi. */
function riwayat_sync_kunci(string $kategori, string $jenis = ''): string
{
    return $jenis !== '' && in_array($jenis, RIWAYAT_KATEGORI_JENIS[$kategori] ?? [], true) ? $kategori . ':' . $jenis : $kategori;
}

function rs_txt($v): string
{
    return is_scalar($v) && (string)$v !== 'null' ? trim(strip_tags((string)$v)) : '';
}

function rs_cut(string $s, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

/** SIASN dd-mm-yyyy / yyyy-mm-dd -> yyyy-mm-dd ('' bila kosong/tidak valid). */
function rs_tgl($v): string
{
    $v = rs_txt($v);
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $v, $m) && (int)$m[3] > 1900) return $m[3] . '-' . $m[2] . '-' . $m[1];
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && (int)$m[1] > 1900) return $m[1] . '-' . $m[2] . '-' . $m[3];
    return '';
}

/** Nilai pertama yang terisi dari beberapa kemungkinan nama field SIASN. */
function rs_ambil(array $d, array $kunci, int $max = 255): string
{
    foreach ($kunci as $k) {
        if (isset($d[$k]) && !is_array($d[$k]) && trim((string)$d[$k]) !== '') return rs_cut(rs_txt($d[$k]), $max);
    }
    return '';
}

function rs_id_valid(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9-]{8,64}$/', $id);
}

/** Token SIASN (login BKN + WS APIMWS); null bila BKN tidak terhubung. */
function rs_token(): ?array
{
    if (siasn_bkn_offline()) return null;
    try {
        return ['login' => siasn_rest_login_token(), 'ws' => siasn_ws_token_cached()];
    } catch (Throwable $e) {
        error_log('TEMANKU riwayat sync token: ' . $e->getMessage());
        siasn_tandai_offline();
        return null;
    }
}

/** GET SIASN tanpa melempar exception: ['kode', 'json']; kode 0 / 5xx = BKN tidak terhubung. */
function rs_bkn_get(string $path, array $tok): array
{
    $ch = curl_init(siasn_api_base() . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => siasn_rest_headers($tok['login'], $tok['ws']),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $res = curl_exec($ch);
    $kode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['kode' => $kode, 'json' => is_string($res) ? json_decode($res, true) : null];
}

/** Daftar baris `data` SIASN, atau hasil gagal ['ok' => false, ...]. */
function rs_baris_siasn(string $path, array $tok): array
{
    $r = rs_bkn_get($path, $tok);
    if ($r['kode'] === 0 || $r['kode'] >= 500) return ['ok' => false, 'offline' => true, 'pesan' => 'Server BKN tidak terhubung'];
    if (!is_array($r['json'])) return ['ok' => false, 'pesan' => 'Respon SIASN tidak valid (HTTP ' . $r['kode'] . ')'];
    $data = $r['json']['data'] ?? null;
    if (!is_array($data)) {
        $m = rs_txt(is_string($data) ? $data : ($r['json']['message'] ?? ''));
        return ['ok' => true, 'rows' => [], 'pesan' => $m];
    }
    return ['ok' => true, 'rows' => $data, 'pesan' => ''];
}

/** Dokumen pada `path` SIASN menurut id dokumen: [id => ['uri', 'nama', 'dok_id']]. */
function rs_dok_per_ref($path): array
{
    if (is_string($path)) $path = json_decode($path, true);
    $out = [];
    if (!is_array($path)) return $out;
    foreach ($path as $k => $p) {
        if (!is_array($p)) continue;
        $uri = rs_txt($p['dok_uri'] ?? ($p['object'] ?? ''));
        if ($uri === '' || strlen($uri) >= 600 || strpos($uri, '..') !== false) continue;
        $ref = (string)($p['dok_id'] ?? $k);
        $out[$ref] = ['uri' => $uri, 'nama' => strtolower(rs_txt($p['dok_nama'] ?? ($p['nama'] ?? ''))), 'dok_id' => preg_replace('/\D/', '', $ref) ?: '0'];
    }
    return $out;
}

function rs_kode_ekspektasi(string $nama): string
{
    $n = strtoupper(preg_replace('/[^A-Za-z]/', '', $nama) ?? '');
    return strpos($n, 'DIATAS') !== false ? '1' : (strpos($n, 'SESUAI') !== false ? '2' : (strpos($n, 'DIBAWAH') !== false ? '3' : ''));
}

function rs_kode_kuadran(string $nama): string
{
    $n = strtoupper(trim(preg_replace('/\s+/', ' ', $nama) ?? ''));
    return ['SANGAT BAIK' => '1', 'BAIK' => '2', 'BUTUH PERBAIKAN' => '3', 'PERLU PERBAIKAN' => '3', 'KURANG' => '4', 'SANGAT KURANG' => '5'][$n] ?? '';
}

/**
 * Riwayat satu jenis dari SIASN dipetakan ke format endpoint Go sync (sama dengan sl_bkn_rw_* SIMPEG).
 * @return array{ok:bool, offline?:bool, items:array, pesan:string}  item['_dok'] = [kolom => [uri, dok_id]]
 */
function riwayat_sync_ambil(string $jenis, string $nip, string $pnsId, array $tok): array
{
    $sumber = [
        'pendidikan' => '/pns/rw-pendidikan/', 'golongan' => '/pns/rw-golongan/', 'penghargaan' => '/pns/rw-penghargaan/',
        'hukdis' => '/pns/rw-hukdis/', 'kinerja' => '/pns/rw-skp22/', 'pak' => '/pns/rw-angkakredit/', 'cpns' => '/pns/data-utama/',
        'jabatan' => '/pns/rw-jabatan/', 'kursus' => '/pns/rw-kursus/', 'diklatpim' => '/pns/rw-diklat/', 'pasangan' => '/pns/data-pasangan/',
    ][$jenis] ?? null;
    if ($sumber === null) return ['ok' => false, 'items' => [], 'pesan' => 'Jenis riwayat tidak dikenal'];
    $r = rs_baris_siasn($sumber . rawurlencode($nip), $tok);
    if (!$r['ok']) return $r + ['items' => []];
    $rows = $r['rows'];
    $items = [];

    if ($jenis === 'cpns') {
        $d = $rows;
        if (!$d || is_list_array($d)) return ['ok' => false, 'items' => [], 'pesan' => 'Data utama SIASN tidak ditemukan'];
        $idP = rs_ambil($d, ['id'], 64);
        if ($idP !== '' && $idP !== $pnsId) return ['ok' => false, 'items' => [], 'pesan' => 'ID PNS dari SIASN tidak cocok dengan akun Anda'];
        $dok = [];
        foreach (rs_dok_per_ref($d['path'] ?? null) as $ref => $x) {
            $kol = ['889' => 'sk_cpns', '887' => 'sk_pns', '888' => 'spmt'][(string)$ref] ?? null;
            if ($kol) $dok[$kol] = $x;
        }
        $st = strtoupper(rs_ambil($d, ['statusPegawai'], 10));
        $items[] = [
            'id_rw' => $pnsId, 'status' => in_array($st, ['CPNS', 'PNS'], true) ? $st : ($st === 'C' ? 'CPNS' : ($st === 'P' ? 'PNS' : $st)),
            'tmt_cpns' => rs_tgl($d['tmtCpns'] ?? ''), 'no_sk_cpns' => rs_ambil($d, ['nomorSkCpns']), 'tgl_sk_cpns' => rs_tgl($d['tglSkCpns'] ?? ''),
            'jabatan_angkat' => rs_ambil($d, ['namaJabatanAngkatCpns', 'jabatanAngkatCpns']),
            'tmt_pns' => rs_tgl($d['tmtPns'] ?? ''), 'no_sk_pns' => rs_ambil($d, ['nomorSkPns']), 'tgl_sk_pns' => rs_tgl($d['tglSkPns'] ?? ''),
            'no_sttpl' => rs_ambil($d, ['nomorSttpl'], 100), 'tgl_sttpl' => rs_tgl($d['tglSttpl'] ?? ''),
            'no_spmt' => rs_ambil($d, ['noSpmt', 'nomorSpmt'], 100), 'tgl_spmt' => rs_tgl($d['tglSpmt'] ?? ''),
            'tmt_tugas' => rs_tgl($d['tmtMelaksanakanTugas'] ?? ''),
            'no_dokter' => rs_ambil($d, ['noSuratKeteranganDokter'], 100), 'tgl_dokter' => rs_tgl($d['tglSuratKeteranganDokter'] ?? ''),
            'karpeg' => rs_ambil($d, ['noSeriKarpeg', 'kartuPegawai'], 50),
            'pertek_nomor' => rs_ambil($d, ['pertekCpnsPnsL2thNomor'], 100), 'pertek_tgl' => rs_tgl($d['pertekCpnsPnsL2thTanggal'] ?? ''),
            '_label' => 'CPNS/PNS', '_dok' => $dok,
        ];
        return ['ok' => true, 'items' => $items, 'pesan' => 'Data CPNS/PNS dari SIASN'];
    }

    if ($jenis === 'pasangan') {
        $data = is_list_array($rows) ? [] : $rows;
        $idP = rs_ambil($data, ['idPns'], 64);
        if ($idP !== '' && $idP !== $pnsId) return ['ok' => false, 'items' => [], 'pesan' => 'ID PNS dari SIASN tidak cocok dengan akun Anda'];
        $tgl = static function ($v): string { $t = rs_tgl($v); return $t !== '' && substr($t, 0, 4) >= '1900' ? $t : ''; };
        foreach ((array)($data['listPasangan'] ?? []) as $x) {
            if (!is_array($x)) continue;
            $p = is_array($x['dataPernikahan'] ?? null) ? $x['dataPernikahan'] : [];
            $o = is_array($x['orang'] ?? null) ? $x['orang'] : [];
            $idRw = rs_ambil($p, ['id'], 64);
            if (!rs_id_valid($idRw)) continue;
            $dok = [];
            foreach ([$x['path'] ?? null, $p['path'] ?? null, $o['path'] ?? null] as $pt) {
                foreach (rs_dok_per_ref($pt) as $ref => $dk) {
                    if ((string)$ref === '875' || !isset($dok['akta'])) $dok['akta'] = $dk;
                }
            }
            $ispns = $p['isPns'] ?? false;
            $jk = strtoupper(rs_ambil($o, ['jenisKelamin'], 2));
            $items[] = [
                'id_rw' => $idRw, 'orang_id' => rs_ambil($o, ['id'], 64) ?: rs_ambil($p, ['orangId'], 64),
                'pasangan_ke' => (int)($p['posisi'] ?? 0), 'nama' => rs_ambil($o, ['nama', 'namaKtp']),
                'gelar_depan' => rs_ambil($o, ['gelarDepan'], 50), 'gelar_belakang' => rs_ambil($o, ['gelarBlk'], 100),
                'jenis_kelamin' => ['M' => 'L', 'F' => 'P'][$jk] ?? $jk, 'tempat_lahir' => rs_ambil($o, ['tempatLahir']),
                'tgl_lahir' => $tgl($o['tglLahir'] ?? ''), 'is_pns' => in_array($ispns, [true, 'true', 1, '1'], true) ? 1 : 0,
                'status_pernikahan' => rs_ambil($p, ['status'], 2), 'status_hidup' => rs_ambil($o, ['statusHidup'], 10),
                'no_akta_menikah' => rs_ambil($p, ['aktaMenikah']), 'tgl_akta_menikah' => $tgl($p['tgglMenikah'] ?? ''),
                'no_akta_cerai' => rs_ambil($p, ['aktaCerai']), 'tgl_cerai' => $tgl($p['tgglCerai'] ?? ''),
                'no_akta_meninggal' => rs_ambil($o, ['aktaMeninggal']), 'tgl_meninggal' => $tgl($o['tglMeninggal'] ?? ''),
                // tidak dikirim SIASN: dikosongkan (API Go tidak menimpa nilai SIMPEG dengan kosong)
                'jenis_identitas' => '', 'nomor_identitas' => '', 'agama_id' => '', 'alamat' => '', 'no_hp' => '', 'email' => '', 'status_pekerjaan' => '',
                '_label' => rs_ambil($o, ['nama']), '_dok' => $dok,
            ];
        }
        return ['ok' => true, 'items' => $items, 'pesan' => $items ? count($items) . ' data pasangan dari SIASN' : ($r['pesan'] ?: 'SIASN tidak memiliki data pasangan')];
    }

    foreach ($rows as $d) {
        if (!is_array($d)) continue;
        $dokRef = rs_dok_per_ref($d['path'] ?? null);
        $pertama = $dokRef ? reset($dokRef) : null;
        switch ($jenis) {
            case 'pendidikan':
                $idRw = rs_txt($d['id'] ?? '');
                if (!rs_id_valid($idRw) || rs_txt($d['idPns'] ?? $pnsId) !== $pnsId) continue 2;
                $dok = [];
                foreach (['870' => 'ijazah', '871' => 'transkrip', '867' => 'pertek'] as $ref => $kol) {
                    if (isset($dokRef[$ref])) $dok[$kol] = $dokRef[$ref];
                }
                $tahun = rs_txt($d['tahunLulus'] ?? '');
                $items[] = [
                    'id_rw' => $idRw, 'tk_pendidikan_id' => preg_replace('/\D/', '', rs_txt($d['tkPendidikanId'] ?? '')),
                    'jurusan_id' => rs_cut(rs_txt($d['pendidikanId'] ?? ''), 64), 'tahun_lulus' => preg_match('/^\d{4}$/', $tahun) ? $tahun : '',
                    'tgl_lulus' => rs_tgl($d['tglLulus'] ?? ''), 'nama_sekolah' => rs_cut(rs_txt($d['namaSekolah'] ?? ''), 255),
                    'no_ijazah' => rs_cut(rs_txt($d['nomorIjasah'] ?? ($d['nomorIjazah'] ?? '')), 100),
                    'gelar_depan' => rs_cut(rs_txt($d['gelarDepan'] ?? ''), 50), 'gelar_belakang' => rs_cut(rs_txt($d['gelarBelakang'] ?? ''), 50),
                    'pertama' => in_array(strtolower(rs_txt($d['isPendidikanPertama'] ?? '')), ['1', 'true', 'y', 'ya'], true) ? '1' : '0',
                    '_label' => trim(rs_txt($d['namaSekolah'] ?? '') . ' ' . $tahun), '_dok' => $dok,
                ];
                break;
            case 'golongan':
                $idRw = rs_txt($d['id'] ?? '');
                $gol = (int)preg_replace('/\D/', '', rs_txt($d['golonganId'] ?? ''));
                if (!rs_id_valid($idRw) || rs_txt($d['idPns'] ?? $pnsId) !== $pnsId || $gol < 11 || $gol > 45) continue 2;
                $dok = [];
                foreach ($dokRef as $x) {
                    $kol = strpos($x['nama'], 'pertek') !== false ? 'pertek' : 'sk';
                    if (!isset($dok[$kol])) $dok[$kol] = $x;
                }
                $jn = rs_txt($d['jenisKPNama'] ?? '');
                $items[] = [
                    'id_rw' => $idRw, 'no_sk' => rs_cut(rs_txt($d['skNomor'] ?? ''), 255), 'tgl_sk' => rs_tgl($d['skTanggal'] ?? ''),
                    'tmt_tgl' => rs_tgl($d['tmtGolongan'] ?? ''), 'id_gol' => $gol,
                    'jenis' => rs_cut($jn !== '' ? $jn : rs_txt($d['jenisKPId'] ?? ''), 120),
                    'tahun' => max(0, min(60, (int)rs_txt($d['masaKerjaGolonganTahun'] ?? '0'))),
                    'bulan' => max(0, min(11, (int)rs_txt($d['masaKerjaGolonganBulan'] ?? '0'))),
                    'nopertek' => rs_cut(rs_txt($d['noPertekBkn'] ?? ''), 100), 'tglpertek' => rs_tgl($d['tglPertekBkn'] ?? ''),
                    '_label' => (RIWAYAT_GOLONGAN[$gol][0] ?? (string)$gol) . ' TMT ' . rs_tgl($d['tmtGolongan'] ?? ''), '_dok' => $dok,
                ];
                break;
            case 'penghargaan':
                $idRw = rs_txt($d['ID'] ?? ($d['id'] ?? ''));
                $harga = rs_txt($d['hargaId'] ?? '');
                if (!rs_id_valid($idRw) || rs_txt($d['pnsOrangId'] ?? $pnsId) !== $pnsId || !preg_match('/^[A-Za-z0-9-]{1,20}$/', $harga)) continue 2;
                $nama = rs_cut(rs_txt($d['hargaNama'] ?? ''), 255);
                $tahun = (int)($d['tahun'] ?? 0);
                $items[] = [
                    'id_rw' => $idRw, 'jenis' => $harga, 'jenis_nama' => $nama, 'no_sk' => rs_cut(rs_txt($d['skNomor'] ?? ''), 255),
                    'tgl_sk' => rs_tgl($d['skDate'] ?? ''), 'tahun' => $tahun > 1900 && $tahun < 2100 ? $tahun : 0,
                    '_label' => trim($nama . ' ' . ($tahun ?: '')), '_dok' => $pertama ? ['sertifikat' => $pertama] : [],
                ];
                break;
            case 'hukdis':
                $idRw = rs_txt($d['id'] ?? ($d['ID'] ?? ''));
                if (!rs_id_valid($idRw) || rs_txt($d['pnsOrang'] ?? ($d['pnsOrangId'] ?? $pnsId)) !== $pnsId) continue 2;
                $t = static function (string $k, int $max = 255) use ($d): string { return rs_cut(rs_txt($d[$k] ?? ''), $max); };
                $items[] = [
                    'id_rw' => $idRw, 'riwayat' => $t('rwHukumanDisiplin', 64), 'tingkat' => strtoupper($t('jenisTingkatHukumanId', 5)),
                    'tindakan' => $t('jenisHukuman', 10), 'tindakan_nama' => $t('jenisHukumanNama', 500),
                    'alasan' => $t('alasanHukumanDisiplin', 64), 'alasan_nama' => $t('alasanHukumanDisiplinNama', 1000),
                    'gol_kode' => $t('golongan', 10), 'gol_lama' => $t('golonganLama', 10), 'kedudukan' => $t('kedudukanHukum', 10),
                    'no_sk' => $t('skNomor'), 'tgl_sk' => rs_tgl($d['skTanggal'] ?? ''), 'tmt' => rs_tgl($d['hukumanTanggal'] ?? ''),
                    'masa_tahun' => (int)($d['masaTahun'] ?? 0), 'masa_bulan' => (int)($d['masaBulan'] ?? 0),
                    'akhir' => rs_tgl($d['akhirHukumTanggal'] ?? ''), 'pp' => $t('nomorPp', 100),
                    'no_batal' => $t('skPembatalanNomor'), 'tgl_batal' => rs_tgl($d['skPembatalanTanggal'] ?? ''),
                    'keterangan' => $t('keterangan', 1000), '_label' => trim($t('jenisHukumanNama', 500) . ' ' . $t('skNomor')),
                    '_dok' => $pertama ? ['sk' => $pertama] : [],
                ];
                break;
            case 'kinerja':
                $idRw = rs_ambil($d, ['id', 'ID'], 64);
                $idP = rs_ambil($d, ['pnsDinilaiId', 'pns', 'pnsOrang'], 64);
                if (!rs_id_valid($idRw) || ($idP !== '' && $idP !== $pnsId)) continue 2;
                $nm = ['1' => 'DIATAS EKSPEKTASI', '2' => 'SESUAI EKSPEKTASI', '3' => 'DIBAWAH EKSPEKTASI'];
                $hasilNm = rs_ambil($d, ['hasilKinerja', 'HasilKinerja', 'hasilKinerjaNama'], 100);
                $periNm = rs_ambil($d, ['perilakuKerja', 'PerilakuKerja', 'perilakuKerjaNama'], 100);
                $kuadNm = rs_ambil($d, ['kuadranKinerja', 'KuadranKinerja', 'kuadranKinerjaNama'], 100);
                $hasil = rs_ambil($d, ['hasilKinerjaNilai', 'HasilKinerjaNilai', 'hasilKinerjaId'], 2);
                $peri = rs_ambil($d, ['perilakuKerjaNilai', 'PerilakuKerjaNilai', 'perilakuKerjaId'], 2);
                $kuad = rs_ambil($d, ['kuadranKinerjaNilai', 'KuadranKinerjaNilai', 'kuadranKinerjaId'], 2);
                $hasil = in_array($hasil, ['1', '2', '3'], true) ? $hasil : rs_kode_ekspektasi($hasilNm);
                $peri = in_array($peri, ['1', '2', '3'], true) ? $peri : rs_kode_ekspektasi($periNm);
                $kuad = in_array($kuad, ['1', '2', '3', '4', '5'], true) ? $kuad : rs_kode_kuadran($kuadNm);
                $golPn = rs_ambil($d, ['penilaiGolonganId', 'penilaiGolongan', 'penilaiGolId', 'golonganPenilai'], 50);
                $tahun = (int)rs_ambil($d, ['tahun'], 4);
                $items[] = [
                    'id_rw' => $idRw, 'tahun' => $tahun,
                    'hasil' => $hasil, 'hasil_nama' => $hasilNm ?: ($nm[$hasil] ?? ''), 'perilaku' => $peri, 'perilaku_nama' => $periNm ?: ($nm[$peri] ?? ''),
                    'kuadran' => $kuad, 'kuadran_nama' => $kuadNm,
                    'penilai_nama' => rs_ambil($d, ['penilaiNama', 'namaPenilai']), 'penilai_nip' => rs_ambil($d, ['nipNrpPenilai', 'penilaiNipNrp'], 30),
                    'penilai_golongan' => in_array(strtolower($golPn), ['null', '0', '-'], true) ? '' : $golPn,
                    'penilai_jabatan' => rs_ambil($d, ['penilaiJabatanNm', 'penilaiJabatan']), 'penilai_unor' => rs_ambil($d, ['penilaiUnorNm', 'penilaiUnorNama']),
                    'status_penilai' => strtoupper(rs_ambil($d, ['statusPenilai'], 10)),
                    '_label' => 'SKP ' . $tahun, '_dok' => $pertama ? ['skp' => $pertama] : [],
                ];
                break;
            case 'pak':
                $idRw = rs_ambil($d, ['id', 'ID'], 64);
                $idP = rs_ambil($d, ['pns', 'pnsOrang', 'pnsId'], 64);
                if (!rs_id_valid($idRw) || ($idP !== '' && $idP !== $pnsId)) continue 2;
                $angka = static function ($v): float { $v = str_replace(',', '.', trim((string)$v)); return is_numeric($v) ? (float)$v : 0.0; };
                $ya = static function ($v): int { return in_array(strtolower(trim((string)$v)), ['1', 'true', 'ya', 'y'], true) ? 1 : 0; };
                $dok = [];
                foreach ($dokRef as $ref => $x) {
                    if ((string)$ref === '880') $dok['sk_pak'] = $x;
                    elseif ((string)$ref === '879' || !isset($dok['dok_pak'])) $dok['dok_pak'] = $x;
                }
                $noSk = rs_ambil($d, ['nomorSk']);
                $items[] = [
                    'id_rw' => $idRw, 'no_sk' => $noSk, 'tgl_sk' => rs_tgl($d['tanggalSk'] ?? ''),
                    'bln_mulai' => (int)rs_ambil($d, ['bulanMulaiPenailan', 'bulanMulaiPenilaian'], 2), 'thn_mulai' => (int)rs_ambil($d, ['tahunMulaiPenailan', 'tahunMulaiPenilaian'], 4),
                    'bln_selesai' => (int)rs_ambil($d, ['bulanSelesaiPenailan', 'bulanSelesaiPenilaian'], 2), 'thn_selesai' => (int)rs_ambil($d, ['tahunSelesaiPenailan', 'tahunSelesaiPenilaian'], 4),
                    'utama' => $angka($d['kreditUtamaBaru'] ?? 0), 'penunjang' => $angka($d['kreditPenunjangBaru'] ?? 0), 'total' => $angka($d['kreditBaruTotal'] ?? 0),
                    'rw_jabatan' => rs_ambil($d, ['rwJabatan'], 64),
                    'pertama' => $ya($d['isAngkaKreditPertama'] ?? 0), 'integrasi' => $ya($d['isIntegrasi'] ?? 0), 'konversi' => $ya($d['isKonversi'] ?? 0),
                    '_label' => trim('PAK ' . $noSk), '_dok' => $dok,
                ];
                break;
            case 'jabatan':
                $idRw = rs_ambil($d, ['id', 'ID'], 64);
                $idP = rs_ambil($d, ['idPns', 'pnsId'], 64);
                if (!rs_id_valid($idRw) || ($idP !== '' && $idP !== $pnsId)) continue 2;
                $dok = [];
                foreach ($dokRef as $ref => $x) {
                    $ref = (string)$ref;
                    if ($ref === '873') $dok['lantik'] = $x;
                    elseif ($ref === '1634') $dok['sk_mutasi'] = $x;
                    elseif ($ref === '872' || !isset($dok['sk'])) $dok['sk'] = $x;
                }
                $nama = rs_ambil($d, ['namaJabatan', 'jabatanFungsionalNama', 'jabatanFungsionalUmumNama'], 500);
                $items[] = [
                    'id_rw' => $idRw, 'jenis' => rs_ambil($d, ['jenisJabatan', 'jenisJabatanId'], 2),
                    'instansi' => rs_ambil($d, ['instansiKerjaId'], 64), 'satker' => rs_ambil($d, ['satuanKerjaId'], 64),
                    'unor_id' => rs_ambil($d, ['unorId'], 64), 'unor_nama' => rs_ambil($d, ['unorNama', 'namaUnor'], 500),
                    'induk_id' => rs_ambil($d, ['unorIndukId'], 64), 'eselon_id' => rs_ambil($d, ['eselonId'], 5),
                    'fungsional_id' => rs_ambil($d, ['jabatanFungsionalId'], 64), 'umum_id' => rs_ambil($d, ['jabatanFungsionalUmumId'], 64),
                    'jabatan_nama' => $nama, 'jenis_mutasi' => strtoupper(rs_ambil($d, ['jenisMutasiId'], 5)),
                    'tmt_mutasi' => rs_tgl($d['tmtMutasi'] ?? ''), 'jenis_penugasan' => strtoupper(rs_ambil($d, ['jenisPenugasanId'], 5)),
                    'sub_jabatan' => rs_ambil($d, ['subJabatanId'], 64),
                    'no_sk' => rs_ambil($d, ['nomorSk']), 'tgl_sk' => rs_tgl($d['tanggalSk'] ?? ''),
                    'tmt_jabatan' => rs_tgl($d['tmtJabatan'] ?? ''), 'tmt_pelantikan' => rs_tgl($d['tmtPelantikan'] ?? ''),
                    '_label' => trim($nama . ' ' . rs_ambil($d, ['nomorSk'])), '_dok' => $dok,
                ];
                break;
            case 'kursus':
                $idRw = rs_ambil($d, ['id', 'ID'], 64);
                $idP = rs_ambil($d, ['idPns', 'pnsOrangId'], 64);
                if (!rs_id_valid($idRw) || ($idP !== '' && $idP !== $pnsId)) continue 2;
                $nama = rs_ambil($d, ['namaKursus'], 500);
                $items[] = [
                    'id_rw' => $idRw, 'jenis_diklat_id' => rs_ambil($d, ['jenisDiklatId'], 5),
                    'jenis_kursus_id' => rs_ambil($d, ['jenisKursusId'], 64), 'jenis_kursus_nama' => rs_ambil($d, ['jenisKursusNama']),
                    'nama' => $nama, 'jam' => (int)preg_replace('/\D/', '', (string)($d['jumlahJam'] ?? '0')), 'tahun' => rs_ambil($d, ['tahunKursus'], 4),
                    'tgl_mulai' => rs_tgl($d['tanggalKursus'] ?? ''), 'tgl_selesai' => rs_tgl($d['tanggalSelesaiKursus'] ?? ''),
                    'no_sertifikat' => rs_ambil($d, ['noSertipikat', 'nomorSertipikat']), 'penyelenggara' => rs_ambil($d, ['institusiPenyelenggara'], 500),
                    'instansi' => rs_ambil($d, ['instansiId'], 64), 'lokasi' => rs_ambil($d, ['lokasiId'], 64),
                    '_label' => trim($nama . ' ' . rs_ambil($d, ['tahunKursus'], 4)), '_dok' => $pertama ? ['sertifikat' => $pertama] : [],
                ];
                break;
            case 'diklatpim':
                $idRw = rs_ambil($d, ['id', 'ID'], 64);
                $idP = rs_ambil($d, ['idPns', 'pnsOrangId'], 64);
                if (!rs_id_valid($idRw) || ($idP !== '' && $idP !== $pnsId)) continue 2;
                $nama = rs_ambil($d, ['latihanStrukturalNama', 'namaLatihanStruktural', 'namaDiklat']);
                $items[] = [
                    'id_rw' => $idRw, 'latihan_id' => rs_ambil($d, ['latihanStrukturalId'], 64), 'latihan_nama' => $nama,
                    'nomor' => rs_ambil($d, ['nomor', 'nomorSertifikat']), 'tgl_mulai' => rs_tgl($d['tanggal'] ?? ''), 'tgl_selesai' => rs_tgl($d['tanggalSelesai'] ?? ''),
                    'bobot' => (int)preg_replace('/\D/', '', (string)($d['bobot'] ?? '0')), 'jenis_kompetensi' => rs_ambil($d, ['jenisKompetensi']),
                    'jam' => (int)preg_replace('/\D/', '', (string)($d['jumlahJam'] ?? '0')), 'tahun' => rs_ambil($d, ['tahun'], 4),
                    'penyelenggara' => rs_ambil($d, ['institusiPenyelenggara'], 500),
                    '_label' => trim(($nama !== '' ? $nama : 'Diklat struktural') . ' ' . rs_ambil($d, ['tahun'], 4)), '_dok' => $pertama ? ['sertifikat' => $pertama] : [],
                ];
                break;
        }
    }
    if (!$items) return ['ok' => true, 'items' => [], 'pesan' => $r['pesan'] ?: 'SIASN tidak memiliki riwayat ' . strtolower(RIWAYAT_JENIS_LABEL[$jenis] ?? $jenis)];
    return ['ok' => true, 'items' => $items, 'pesan' => count($items) . ' riwayat dari SIASN'];
}

/**
 * Peta dok_uri SIASN -> path repository agar dokumen yang sama tidak diunduh/diunggah ulang setiap sync.
 * Disimpan di folder private (di luar public_html, sama dengan konfigurasi SIASN); cadangan di sesi.
 */
function riwayat_dokmap(?array $set = null): array
{
    $dir = dirname(__DIR__, 2) . '/private';
    $file = $dir . '/riwayat_dokmap.json';
    $map = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    $map = is_array($map) ? $map : [];
    $map += (array)($_SESSION['riwayat_dokmap'] ?? []);
    if ($set !== null) {
        $map = array_merge($map, $set);
        if (!(is_dir($dir) && @file_put_contents($file, json_encode($map), LOCK_EX) !== false)) {
            $_SESSION['riwayat_dokmap'] = array_merge((array)($_SESSION['riwayat_dokmap'] ?? []), $set);
        }
    }
    return $map;
}

/**
 * Tahap 1: tarik data SIASN satu kategori dan simpan ke SIMPEG. Daftar dokumen SIASN disimpan di sesi untuk tahap 2.
 * @return array{success:bool, offline?:bool, message:string, dokumen:int}
 */
function riwayat_sync_data(array $pegawai, string $kategori, string $hanya = ''): array
{
    $tok = rs_token();
    if ($tok === null) return ['success' => false, 'offline' => true, 'message' => 'API BKN tidak terhubung. Data lokal tidak diubah.', 'dokumen' => 0];
    $pesan = [];
    $docs = [];
    $berhasil = false;
    foreach (riwayat_sync_jenis($kategori, $hanya) as $jenis) {
        $label = RIWAYAT_JENIS_LABEL[$jenis] ?? $jenis;
        if (in_array($jenis, ['golongan', 'jabatan'], true)) {
            // Seperti SIMPEG: minta SIASN menyinkronkan riwayat ke data utama dulu (tidak menggagalkan sync).
            try { siasn_sync_pendahuluan($jenis, $pegawai['pns_id'], $tok['login'], $tok['ws']); }
            catch (Throwable $e) { error_log('TEMANKU riwayat sync pendahuluan ' . $jenis . ': ' . $e->getMessage()); }
        }
        $src = riwayat_sync_ambil($jenis, $pegawai['nip'], $pegawai['pns_id'], $tok);
        if (!$src['ok']) {
            if (!empty($src['offline'])) siasn_tandai_offline();
            return ['success' => false, 'offline' => !empty($src['offline']), 'message' => $label . ': ' . $src['pesan'] . '. Data lokal tidak diubah.', 'dokumen' => 0];
        }
        if (!$src['items']) {
            $pesan[] = $label . ': ' . $src['pesan'] . ' (data lokal tidak diubah)';
            continue;
        }
        $items = array_map(static function (array $it): array { unset($it['_dok'], $it['_label']); return $it; }, $src['items']);
        [$kode, $json] = riwayat_api_kirim('PUT', '/asn/' . RIWAYAT_SYNC_ENDPOINT[$jenis] . '/pns/' . rawurlencode($pegawai['pns_id']) . '/sync', ['items' => $items], 90);
        if ($kode < 200 || $kode >= 300) {
            error_log('TEMANKU riwayat sync ' . $jenis . ' HTTP ' . $kode);
            return ['success' => false, 'message' => $label . ': gagal menyimpan ke SIMPEG (' . riwayat_api_pesan($json, 'HTTP ' . $kode) . '). Data lama tidak diubah.', 'dokumen' => 0];
        }
        $berhasil = true;
        foreach ($src['items'] as $it) {
            foreach ($it['_dok'] ?? [] as $kolom => $dok) {
                $docs[] = ['jenis' => $jenis, 'id_rw' => $it['id_rw'], 'kolom' => $kolom, 'uri' => $dok['uri'], 'dok_id' => $dok['dok_id'], 'label' => $it['_label'] ?? $label];
            }
        }
        $pesan[] = sprintf('%s: %d riwayat (%d baru, %d diperbarui, %d dihapus)', $label, count($items),
            (int)($json['inserted'] ?? 0), (int)($json['updated'] ?? 0), (int)($json['deleted'] ?? 0));
    }
    $_SESSION['riwayat_sync_dok'][riwayat_sync_kunci($kategori, $hanya)] = ['pns_id' => $pegawai['pns_id'], 'docs' => $docs];
    unset($_SESSION['riwayat_cache'][$pegawai['pns_id'] . ':' . $kategori]);
    if ($kategori === 'jabatan' || $kategori === 'tugas') {
        unset($_SESSION['riwayat_cache'][$pegawai['pns_id'] . ':jabatan'], $_SESSION['riwayat_cache'][$pegawai['pns_id'] . ':tugas']);
    }
    $map = riwayat_dokmap();
    $baru = count(array_filter($docs, static function (array $d) use ($map): bool { return empty($map[sha1($d['uri'])]); }));
    return ['success' => true, 'berubah' => $berhasil, 'message' => implode('; ', $pesan) ?: 'Tidak ada data', 'dokumen' => count($docs), 'dokumen_baru' => $baru];
}

/**
 * Tahap 2: satu dokumen SIASN -> repository -> riwayat. Dokumen yang sudah pernah ditarik tidak diunduh ulang.
 * @return array{success:bool, message:string, offline?:bool}
 */
function riwayat_sync_dok(array $pegawai, string $kategori, int $idx, string $hanya = ''): array
{
    $simpan = $_SESSION['riwayat_sync_dok'][riwayat_sync_kunci($kategori, $hanya)] ?? null;
    if (!is_array($simpan) || ($simpan['pns_id'] ?? '') !== $pegawai['pns_id'] || !isset($simpan['docs'][$idx])) {
        return ['success' => false, 'message' => 'Daftar dokumen kedaluwarsa. Jalankan sync lagi.'];
    }
    $d = $simpan['docs'][$idx];
    $kunci = sha1($d['uri']);
    $path = (string)(riwayat_dokmap()[$kunci] ?? '');
    $ket = 'sudah pernah ditarik';
    if (!preg_match(RIWAYAT_POLA_REPO, $path)) {
        $tok = rs_token();
        if ($tok === null) return ['success' => false, 'offline' => true, 'message' => 'API BKN tidak terhubung'];
        try {
            $isi = siasn_rest_raw(siasn_api_base() . '/download-dok?filePath=' . rawurlencode($d['uri']),
                siasn_rest_headers($tok['login'], $tok['ws'], 'application/pdf, application/octet-stream, */*'), 10 * 1024 * 1024, 90);
        } catch (Throwable $e) {
            error_log('TEMANKU riwayat sync dok: ' . $e->getMessage());
            return ['success' => false, 'offline' => siasn_galat_koneksi($e), 'message' => 'Dokumen belum dapat diunduh dari SIASN'];
        }
        if (strncmp($isi, '%PDF', 4) !== 0) return ['success' => false, 'message' => 'SIASN tidak mengirim PDF'];
        try {
            $path = riwayat_unggah_pdf($isi, $d['id_rw'], $d['kolom'], $d['dok_id'], 'riwayat', RIWAYAT_REPO_KATEGORI[$d['jenis']]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        riwayat_dokmap([$kunci => $path]);
        $ket = 'diunduh dari SIASN (' . round(strlen($isi) / 1024) . ' KB)';
    }
    [$kode, $json] = riwayat_api_kirim('PATCH', '/asn/' . RIWAYAT_SYNC_ENDPOINT[$d['jenis']] . '/' . rawurlencode($d['id_rw']) . '/dokumen',
        ['pns_id' => $pegawai['pns_id'], $d['kolom'] => $path]);
    // 404 tanpa perubahan = dokumen sama dengan yang sudah tersimpan.
    if (($kode < 200 || $kode >= 300) && $kode !== 404) {
        return ['success' => false, 'message' => 'Gagal menautkan dokumen: ' . riwayat_api_pesan($json, 'HTTP ' . $kode)];
    }
    unset($_SESSION['riwayat_cache'][$pegawai['pns_id'] . ':' . $kategori]);
    return ['success' => true, 'message' => $d['label'] . ' – ' . $d['kolom'] . ': ' . $ket];
}

/** Label kolom dokumen hasil sync (tahap 2). */
const RIWAYAT_SYNC_DOK_LABEL = [
    'ijazah' => 'Ijazah', 'transkrip' => 'Transkrip', 'pertek' => 'Pertek', 'sk' => 'SK', 'lantik' => 'Pelantikan',
    'sk_mutasi' => 'SK mutasi', 'sertifikat' => 'Sertifikat', 'skp' => 'Dokumen SKP', 'dok_pak' => 'Dokumen PAK', 'sk_pak' => 'SK PAK',
    'sk_cpns' => 'SK CPNS', 'sk_pns' => 'SK PNS', 'spmt' => 'SPMT', 'akta' => 'Buku/akta nikah',
];

/** Dokumen yang sudah ada pada riwayat lokal (SIMPEG): ['id_rw|kolom' => true]; null bila riwayat gagal dimuat. */
function riwayat_sync_lokal(array $pegawai, string $kategori): ?array
{
    $rows = riwayat_data($pegawai, $kategori, true);
    if ($rows === null) return null;
    $ada = [];
    foreach ($rows as $r) {
        foreach ($r['dok'] ?? [] as [, $kolom]) $ada[$r['id_rw'] . '|' . $kolom] = true;
        $ada[$r['id_rw']] = true;
    }
    return $ada;
}

/**
 * Daftar dokumen SIASN untuk tahap 2: [idx, label, dokumen, sudah]. "sudah" = berkas sudah ada di riwayat SIMPEG
 * atau pernah ditarik ke repository; seperti SIMPEG, yang sudah ada tidak ditarik ulang otomatis.
 */
function riwayat_sync_dok_daftar(array $pegawai, string $kategori, ?array $lokal = null, string $hanya = ''): ?array
{
    $simpan = $_SESSION['riwayat_sync_dok'][riwayat_sync_kunci($kategori, $hanya)] ?? null;
    if (!is_array($simpan) || ($simpan['pns_id'] ?? '') !== $pegawai['pns_id']) return null;
    $map = riwayat_dokmap();
    $lokal = $lokal ?? riwayat_sync_lokal($pegawai, $kategori) ?? [];
    $out = [];
    foreach ($simpan['docs'] as $i => $d) {
        $out[] = ['idx' => $i, 'label' => (string)$d['label'], 'dokumen' => RIWAYAT_SYNC_DOK_LABEL[$d['kolom']] ?? $d['kolom'],
            'sudah' => isset($lokal[$d['id_rw'] . '|' . $d['kolom']]) || preg_match(RIWAYAT_POLA_REPO, (string)($map[sha1($d['uri'])] ?? '')) === 1];
    }
    return $out;
}

/**
 * Tahap 2 saja (tanpa menarik data): ambil daftar dokumen dari SIASN untuk riwayat yang sudah ada di SIMPEG.
 * @return array{success:bool, message?:string, offline?:bool, data?:array}
 */
function riwayat_sync_dok_siapkan(array $pegawai, string $kategori, string $hanya = ''): array
{
    $tok = rs_token();
    if ($tok === null) return ['success' => false, 'offline' => true, 'message' => 'API BKN tidak terhubung.'];
    $lokal = riwayat_sync_lokal($pegawai, $kategori);
    if ($lokal === null) return ['success' => false, 'message' => 'Riwayat SIMPEG belum dapat dimuat. Coba lagi.'];
    $docs = [];
    foreach (riwayat_sync_jenis($kategori, $hanya) as $jenis) {
        $label = RIWAYAT_JENIS_LABEL[$jenis] ?? $jenis;
        $src = riwayat_sync_ambil($jenis, $pegawai['nip'], $pegawai['pns_id'], $tok);
        if (!$src['ok']) {
            if (!empty($src['offline'])) siasn_tandai_offline();
            return ['success' => false, 'offline' => !empty($src['offline']), 'message' => $label . ': ' . $src['pesan']];
        }
        foreach ($src['items'] as $it) {
            if (!isset($lokal[$it['id_rw']])) continue;   // riwayat belum ada di SIMPEG: jalankan Tahap 1 + 2
            foreach ($it['_dok'] ?? [] as $kolom => $dok) {
                $docs[] = ['jenis' => $jenis, 'id_rw' => $it['id_rw'], 'kolom' => $kolom, 'uri' => $dok['uri'], 'dok_id' => $dok['dok_id'], 'label' => $it['_label'] ?? $label];
            }
        }
    }
    $_SESSION['riwayat_sync_dok'][riwayat_sync_kunci($kategori, $hanya)] = ['pns_id' => $pegawai['pns_id'], 'docs' => $docs];
    return ['success' => true, 'data' => riwayat_sync_dok_daftar($pegawai, $kategori, $lokal, $hanya) ?? []];
}

/**
 * Kirim perubahan identitas ke SIASN (POST /pns/data-utama-update), port sl_bkn_update_data_utama SIMPEG.
 * Kolom yang tidak diubah diisi dari data SIASN saat ini supaya tidak terkosongkan.
 * @param array $ubah kunci SIMPEG: agamaId, tempatLahirId, alamat, noHp, email, emailGov
 * @return array{status:string, message:string, offline?:bool}
 */
function riwayat_bkn_ubah_data_utama(array $pegawai, array $ubah): array
{
    $tok = rs_token();
    if ($tok === null) return ['status' => 'failed', 'offline' => true, 'message' => 'API BKN tidak terhubung; perubahan belum dikirim ke SIASN'];
    $r = rs_bkn_get('/pns/data-utama/' . rawurlencode($pegawai['nip']), $tok);
    $cur = is_array($r['json']['data'] ?? null) ? $r['json']['data'] : null;
    if ($cur === null) return ['status' => 'failed', 'offline' => $r['kode'] === 0 || $r['kode'] >= 500, 'message' => 'Data SIASN saat ini tidak dapat dibaca; perubahan tidak dikirim ke BKN'];
    if (rs_txt($cur['id'] ?? '') !== $pegawai['pns_id']) return ['status' => 'failed', 'message' => 'ID SIASN tidak cocok dengan akun Anda; perubahan tidak dikirim ke BKN'];
    $pick = static function (array $keys) use ($cur): string {
        foreach ($keys as $k) {
            $v = isset($cur[$k]) && is_scalar($cur[$k]) ? trim((string)$cur[$k]) : '';
            if ($v !== '' && $v !== 'null' && $v !== '-') return $v;
        }
        return '';
    };
    $nilai = static function (string $k, array $siasn) use ($ubah, $pick): string {
        return array_key_exists($k, $ubah) ? (string)$ubah[$k] : $pick($siasn);
    };
    $payload = [
        'pns_orang_id' => $pegawai['pns_id'],
        'agama_id' => $nilai('agamaId', ['agamaId']), 'kabupaten_id' => $nilai('tempatLahirId', ['tempatLahirId']),
        'alamat' => $nilai('alamat', ['alamat']), 'email' => $nilai('email', ['email']), 'email_gov' => $nilai('emailGov', ['emailGov']),
        'nomor_hp' => $nilai('noHp', ['noHp']), 'nomor_telpon' => $pick(['noTelp']),
        'nomor_bpjs' => $pick(['bpjs', 'noAskes']), 'npwp_nomor' => $pick(['noNpwp']), 'npwp_tanggal' => $pick(['tglNpwp']),
        'taspen_nomor' => $pick(['noTaspen']), 'tanggal_taspen' => $pick(['tanggal_taspen', 'tglTaspen']), 'karis_karsu' => $pick(['karis_karsu']),
        'kpkn_id' => $pick(['kpknId']), 'lokasi_kerja_id' => $pick(['lokasiKerjaId']), 'kelas_jabatan' => $pick(['kelas_jabatan']),
        'tapera_nomor' => $pick(['noTapera', 'taperaNomor']),
    ];
    if (in_array($payload['nomor_telpon'], ['', '0'], true)) $payload['nomor_telpon'] = $payload['nomor_hp'];
    foreach (['npwp_tanggal', 'tanggal_taspen'] as $tg) {
        if (in_array($payload[$tg], ['01-01-0001', '0001-01-01'], true)) $payload[$tg] = '';
    }
    $peta = ['agama_id' => 'agamaId', 'kabupaten_id' => 'tempatLahirId', 'alamat' => 'alamat', 'email' => 'email', 'email_gov' => 'emailGov', 'nomor_hp' => 'noHp'];
    foreach ($payload as $k => $v) {
        if ($v === '' && $k !== 'pns_orang_id' && !(isset($peta[$k]) && array_key_exists($peta[$k], $ubah))) unset($payload[$k]);
    }
    $ch = curl_init(siasn_api_base() . '/pns/data-utama-update');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(siasn_rest_headers($tok['login'], $tok['ws']), ['Content-Type: application/json']),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $res = curl_exec($ch);
    $kode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($kode === 0 || $kode >= 500) return ['status' => 'failed', 'offline' => true, 'message' => 'Server BKN tidak terhubung saat mengirim perubahan'];
    $j = is_string($res) ? json_decode($res, true) : null;
    error_log('TEMANKU data-utama-update pns=' . $pegawai['pns_id'] . ' http=' . $kode . ' resp=' . substr((string)$res, 0, 300));
    $msg = is_array($j) ? rs_cut(rs_txt($j['message'] ?? ($j['error'] ?? '')), 200) : '';
    $ok = $kode >= 200 && $kode < 300 && is_array($j) && !(isset($j['success']) && $j['success'] === false) && empty($j['error'])
        && !(isset($j['code']) && !in_array((string)$j['code'], ['1', '200', 'success', 'SUCCESS'], true));
    return $ok ? ['status' => 'success', 'message' => $msg !== '' ? $msg : 'Perubahan terkirim ke SIASN BKN']
        : ['status' => 'failed', 'message' => 'SIASN menolak perubahan' . ($msg !== '' ? ': ' . $msg : ' (HTTP ' . $kode . ')')];
}
