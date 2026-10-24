<?php
declare(strict_types=1);

/*
 * Repository TEMANKU berbasis REST API.
 * Tidak ada PDO/mysqli/query SQL di frontend ini.
 * Parameter utama menggunakan NIP dari session SSO/API SIMPEG.
 */
function current_nip(): string { return (string)($_SESSION['nip'] ?? ''); }

function portal_attendance_data(string $periode): ?array
{
    static $cache = [];
    if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $periode)) return null;
    if (!array_key_exists($periode, $cache)) {
        $cache[$periode] = temanku_get('/portal/attendance?periode=' . rawurlencode($periode));
    }
    return is_array($cache[$periode]) ? $cache[$periode] : null;
}

function apel_aturan(): array
{
    $r = portal_attendance_data(date('Y-m'));
    return ['lokasi'=>$r['lokasi'] ?? [], 'jadwal'=>$r['jadwal'] ?? [], 'jadwal_khusus'=>$r['jadwal_khusus'] ?? [], 'libur'=>$r['libur'] ?? []];
}

function presensi_hari_ini(int $unused = 0): ?array
{
    $r = portal_attendance_data(date('Y-m'));
    return isset($r['today']) && is_array($r['today']) ? $r['today'] : null;
}
function presensi_bulan(int $unused, string $periode): array
{
    $r = portal_attendance_data($periode);
    $rows = $r['records'] ?? [];
    $out = [];
    foreach ($rows as $row) if (!empty($row['tanggal'])) $out[$row['tanggal']] = $row;
    foreach ($r['libur'] ?? [] as $holiday) {
        if (!empty($holiday['tanggal']) && !isset($out[$holiday['tanggal']])) {
            $out[$holiday['tanggal']] = ['tanggal'=>$holiday['tanggal'], 'status'=>'libur', 'jam_masuk'=>null, 'jam_keluar'=>null];
        }
    }
    return $out;
}
function presensi_terakhir(int $unused = 0, int $limit = 5): array
{
    $r = portal_attendance_data(date('Y-m'));
    return array_slice($r['recent'] ?? [], 0, max(1, min(10, $limit)));
}
function rekap_bulan(int $unused, string $periode): array
{
    $r = portal_attendance_data($periode);
    $data = $r['rekap'] ?? null;
    if (is_array($data) && isset($data['hadir'])) {
        return [
            'hadir'=>(int)($data['hadir']??0), 'wfa'=>(int)($data['wfa']??0),
            'izin'=>(int)($data['izin']??0), 'libur'=>(int)($data['libur']??0),
            'hari_kerja'=>(int)($data['hari_kerja']??0), 'persen'=>(int)($data['persen']??0),
            'perkiraan_tpp'=>isset($data['perkiraan_tpp']) && is_numeric($data['perkiraan_tpp'])
                ? max(0, (float)$data['perkiraan_tpp']) : null, 'tersambung'=>true,
            // [temanku] rincian dari rekap APEL (sama dengan Rekap Bulanan & TPP)
            'tak'=>(int)($data['tak']??0), 'tl_menit'=>(int)($data['tl_menit']??0), 'psw_menit'=>(int)($data['psw_menit']??0),
            'potongan_persen'=>isset($data['potongan_persen']) ? (float)$data['potongan_persen'] : null,
            'tpp'=>is_array($data['tpp'] ?? null) ? $data['tpp'] : null, 'rekap_apel'=>($data['sumber'] ?? '') === 'rekap APEL',
        ];
    }
    return ['hadir'=>0,'wfa'=>0,'izin'=>0,'libur'=>0,'hari_kerja'=>0,
            'persen'=>0,'perkiraan_tpp'=>null,'tersambung'=>false,'tak'=>0,'tl_menit'=>0,'psw_menit'=>0,'potongan_persen'=>null,'tpp'=>null,'rekap_apel'=>false];
}
/** [temanku] Jenis hari ini dari rekap APEL: kerja | shift | libur | maintenance | belum_berlaku | cuti | dinas. */
function hari_ini_info(): ?array
{
    $r = portal_attendance_data(date('Y-m'));
    return is_array($r['hari_ini'] ?? null) ? $r['hari_ini'] : null;
}
function layanan(string $jenis='internal', string $cari=''): array
{
    // Portal guru e-Carakdek: katalog tetap (tanpa API layanan TEMANKU).
    if ($jenis === 'eksternal') {
        return [
            ['nama'=>'Info GTK', 'url'=>service_url('infogtk'), 'icon'=>'bi-person-vcard-fill', 'warna'=>'rose', 'deskripsi'=>'Kemendikdasmen · data GTK & tunjangan'],
            ['nama'=>'Ruang GTK', 'url'=>service_url('ruanggtk'), 'icon'=>'bi-easel2-fill', 'warna'=>'biru', 'deskripsi'=>'Kemendikdasmen · belajar & kinerja guru'],
            ['nama'=>'MyASN', 'url'=>service_url('myasn'), 'icon'=>'bi-person-badge-fill', 'warna'=>'rose', 'deskripsi'=>'BKN · profil ASN'],
            ['nama'=>'e-Kinerja', 'url'=>service_url('ekinerja'), 'icon'=>'bi-bar-chart-fill', 'warna'=>'hijau', 'deskripsi'=>'BKN · penilaian kinerja'],
            ['nama'=>'TTE Selayar', 'url'=>service_url('tte'), 'icon'=>'bi-pen-fill', 'warna'=>'biru', 'deskripsi'=>'Tanda tangan elektronik'],
            ['nama'=>'SP4N-LAPOR!', 'url'=>service_url('sp4n'), 'icon'=>'bi-chat-square-text-fill', 'warna'=>'kuning', 'deskripsi'=>'Pengaduan layanan publik'],
            ['nama'=>'ASN Karier', 'url'=>service_url('asnkarier'), 'icon'=>'bi-briefcase-fill', 'warna'=>'hijau', 'deskripsi'=>'Portal karier ASN'],
            ['nama'=>'SIMOLA / MOLA BKN', 'url'=>service_url('simola'), 'icon'=>'bi-search', 'warna'=>'hijau', 'deskripsi'=>'Monitoring layanan ASN'],
        ];
    }
    if ($jenis !== 'internal') return [];
    $rows = [
        ['nama'=>'Ajukan Cuti', 'url'=>url('cuti.php'), 'icon'=>'bi-calendar-check-fill', 'warna'=>'kuning', 'deskripsi'=>'Pengajuan cuti, disetujui admin sekolah'],
        ['nama'=>'Pengajuan Dinas', 'url'=>url('dinas.php'), 'icon'=>'bi-briefcase-fill', 'warna'=>'biru', 'deskripsi'=>'Surat tugas dinas dalam / luar daerah'],
        ['nama'=>'Claim Presensi', 'url'=>url('claim.php'), 'icon'=>'bi-clipboard-check-fill', 'warna'=>'hijau', 'deskripsi'=>'Tugas di luar sekolah saat jam absen'],
        ['nama'=>'Riwayat ASN', 'url'=>url('riwayat.php'), 'icon'=>'bi-clock-history', 'warna'=>'rose', 'deskripsi'=>'Data & usulan perubahan riwayat'],
        ['nama'=>'Rekam Wajah', 'url'=>url('wajah.php'), 'icon'=>'bi-person-bounding-box', 'warna'=>'biru', 'deskripsi'=>'Perekaman wajah untuk absen'],
        ['nama'=>'Helpdesk', 'url'=>service_url('helpdesk'), 'icon'=>'bi-headset', 'warna'=>'abu', 'deskripsi'=>'Bantuan layanan kepegawaian'],
    ];
    foreach ($rows as &$row) $row['url'] = safe_service_href($row['url']);
    unset($row);
    if ($cari !== '') {
        $rows = array_values(array_filter($rows, static function ($row) use ($cari): bool {
            return stripos((string)($row['nama'] ?? ''), $cari) !== false;
        }));
    }
    return $rows;
}
function usulan_aktif(int $unused=0): ?array
{
    return null;   // e-Carakdek tidak memakai alur usulan layanan TEMANKU; usulan riwayat ada di menu Riwayat ASN
}
function usulan_tahap(int $usulanId): array
{
    return [];
}
/** Rekap usulan perubahan riwayat (diverifikasi BKPSDM di SIMPEG). */
function rekap_usulan(int $unused=0): array
{
    $out = ['menunggu'=>0, 'disetujui'=>0, 'ditolak'=>0];
    $pns = (string)($_SESSION['pns_id'] ?? '');
    if ($pns === '') return $out;
    foreach (api_rows(temanku_get('/asn/usulan-riwayat?' . http_build_query(['pns_id' => $pns]))) as $r) {
        $st = is_array($r) ? (string)($r['status'] ?? '') : '';
        if ($st === 'diajukan' || $st === 'dikembalikan') $out['menunggu']++;
        elseif ($st === 'disetujui') $out['disetujui']++;
        elseif ($st === 'ditolak') $out['ditolak']++;
    }
    return $out;
}
function riwayat_ringkas(int $unused=0): array
{
    $rows = api_rows(temanku_get('/riwayat/ringkas?nip=' . rawurlencode(current_nip())));
    $out=[]; foreach($rows as $row) if(isset($row['kategori'])) $out[$row['kategori']]=$row;
    return $out;
}
function riwayat_kategori(int $unused, string $kategori): array
{
    return api_rows(temanku_get('/riwayat?nip=' . rawurlencode(current_nip()) . '&kategori=' . rawurlencode($kategori)));
}
function notifikasi(int $unused=0, int $limit=20): array
{
    return api_rows(temanku_get('/notifikasi?nip=' . rawurlencode(current_nip()) . '&limit=' . max(1,$limit)));
}
function notifikasi_belum_dibaca(int $unused=0): int
{
    $r = temanku_get('/notifikasi/belum-dibaca?nip=' . rawurlencode(current_nip()));
    $d = $r['data'] ?? $r ?? [];
    return (int)($d['jumlah'] ?? $d['count'] ?? 0);
}
function notifikasi_tandai_dibaca(): bool
{
    return temanku_put('/notifikasi/baca', ['nip'=>current_nip()]) !== null;
}
function presensi_kirim(string $aksi, float $lat, float $lng, float $jarak): ?array
{
    // API APEL saat ini hanya menerima check-in wajah melalui aplikasi seluler.
    return null;
}
