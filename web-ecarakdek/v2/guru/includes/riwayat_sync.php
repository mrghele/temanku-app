<?php
declare(strict_types=1);

/*
 * Sinkron SIASN untuk portal guru e-Carakdek. e-Carakdek tidak terhubung langsung ke SIASN:
 * permintaan diteruskan ke API Go POST /ecarakdek/api/v1/asn/sinkron/{jenis} (cakupan: data guru sendiri),
 * lalu Go menjalankan file sync SIMPEG yang sama lewat gerbang sync SIMPEG BKPSDM
 * (sama dengan tombol Sync di e-Carakdek versi admin sekolah / super admin).
 * Protokol gerbang: mode=data (tarik data), mode=dok_list (daftar dokumen), mode=dok&idx (satu dokumen);
 * data utama: jenis "utama" dengan langkah=golongan|jabatan|data_utama|foto.
 */

/** Jenis sync per kategori halaman (tugas tambahan ada di riwayat jabatan SIASN). */
function riwayat_sync_jenis(string $kategori): array
{
    $jenis = RIWAYAT_KATEGORI_JENIS[$kategori] ?? [];
    return array_values(array_unique(array_map(static function (string $j): string { return $j === 'tugas' ? 'jabatan' : $j; }, $jenis)));
}

/** POST ke gerbang sinkron e-Carakdek; mengembalikan [kode HTTP, json]. Kode 0 = server tidak terhubung. */
function guru_sinkron(string $jenis, array $body): array
{
    $token = api_service_token();
    if (!$token) return [0, null];
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $token];
    if (!empty($_SESSION['access_token'])) $headers[] = 'Auth: Bearer ' . $_SESSION['access_token'];
    $ch = curl_init(rtrim(API_SIMPEG, '/') . '/asn/sinkron/' . rawurlencode($jenis));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_FOLLOWLOCATION => false,
    ] + simpeg_curl_tls_opts());
    $res = curl_exec($ch);
    $kode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($res) ? json_decode($res, true) : null;
    return [$kode, is_array($json) ? $json : null];
}

/** Pesan dari jawaban gerbang (message / error), atau cadangan. */
function guru_sinkron_pesan(?array $j, int $kode, string $cadangan): string
{
    $m = is_array($j) ? trim((string)($j['message'] ?? $j['error'] ?? '')) : '';
    if ($m !== '') return $m;
    return $kode === 0 ? 'Server sinkron tidak dapat dihubungi' : $cadangan . ' (HTTP ' . $kode . ')';
}

/** Tahap 1: tarik data semua jenis dalam kategori, lalu siapkan daftar dokumen untuk tahap 2. */
function riwayat_sync_data(array $pegawai, string $kategori): array
{
    $pesan = [];
    $docs = [];
    foreach (riwayat_sync_jenis($kategori) as $jenis) {
        $label = RIWAYAT_JENIS_LABEL[$jenis] ?? $jenis;
        [$kode, $j] = guru_sinkron($jenis, ['id' => $pegawai['pns_id'], 'mode' => 'data']);
        if ($kode < 200 || $kode >= 300 || !is_array($j) || empty($j['success'])) {
            return ['success' => false, 'offline' => $kode === 0 || !empty($j['offline']), 'dokumen' => 0,
                'message' => $label . ': ' . guru_sinkron_pesan($j, $kode, 'Sinkron gagal') . '. Data lokal tidak diubah.'];
        }
        $pesan[] = $label . ': ' . guru_sinkron_pesan($j, $kode, 'Data tersinkron');
        [$kodeD, $d] = guru_sinkron($jenis, ['id' => $pegawai['pns_id'], 'mode' => 'dok_list']);
        if ($kodeD >= 200 && $kodeD < 300 && is_array($d) && !empty($d['success'])) {
            foreach ((array)($d['data'] ?? []) as $x) {
                if (!is_array($x) || !isset($x['idx'])) continue;
                $docs[] = ['jenis' => $jenis, 'idx' => (int)$x['idx'], 'dokumen' => (string)($x['label'] ?? 'Dokumen'),
                    'label' => $label, 'sudah' => !empty($x['sudah'])];
            }
        }
    }
    $_SESSION['riwayat_sync_dok'][$kategori] = ['pns_id' => $pegawai['pns_id'], 'docs' => $docs];
    unset($_SESSION['riwayat_cache'], $_SESSION['riwayat_identitas']);   // tampilkan hasil sinkron
    $baru = count(array_filter($docs, static function (array $x): bool { return !$x['sudah']; }));
    return ['success' => true, 'berubah' => true, 'message' => implode('; ', $pesan) ?: 'Tidak ada data',
        'dokumen' => count($docs), 'dokumen_baru' => $baru];
}

/** Daftar dokumen hasil tahap 1 (untuk dipilih); null bila tahap 1 belum dijalankan. */
function riwayat_sync_dok_daftar(array $pegawai, string $kategori): ?array
{
    $simpan = $_SESSION['riwayat_sync_dok'][$kategori] ?? null;
    if (!is_array($simpan) || ($simpan['pns_id'] ?? '') !== $pegawai['pns_id']) return null;
    $out = [];
    foreach ($simpan['docs'] as $i => $d) {
        $out[] = ['idx' => $i, 'label' => $d['label'], 'dokumen' => $d['dokumen'], 'sudah' => $d['sudah']];
    }
    return $out;
}

/** Tahap 2: satu dokumen SIASN -> repository -> riwayat (lewat gerbang). */
function riwayat_sync_dok(array $pegawai, string $kategori, int $idx): array
{
    $simpan = $_SESSION['riwayat_sync_dok'][$kategori] ?? null;
    $d = is_array($simpan) && ($simpan['pns_id'] ?? '') === $pegawai['pns_id'] ? ($simpan['docs'][$idx] ?? null) : null;
    if (!$d) return ['success' => false, 'message' => 'Daftar dokumen kedaluwarsa. Jalankan sync lagi.'];
    [$kode, $j] = guru_sinkron($d['jenis'], ['id' => $pegawai['pns_id'], 'mode' => 'dok', 'idx' => $d['idx']]);
    $ok = $kode >= 200 && $kode < 300 && is_array($j) && !empty($j['success']);
    if ($ok) {
        $_SESSION['riwayat_sync_dok'][$kategori]['docs'][$idx]['sudah'] = true;
        unset($_SESSION['riwayat_cache']);
    }
    return ['success' => $ok, 'offline' => $kode === 0 || !empty($j['offline']),
        'message' => guru_sinkron_pesan($j, $kode, $ok ? 'Dokumen tersimpan' : 'Dokumen gagal ditarik')];
}
