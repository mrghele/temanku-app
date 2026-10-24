<?php
declare(strict_types=1);
// Pustaka bersama halaman layanan presensi (Claim Presensi, Ajukan Cuti).

const LAYANAN_FILE_MAX = 2 * 1024 * 1024;

/** Unggah dokumen ke penyimpanan dokumen APEL (Go /upload-dok), sama seperti lampiran APEL. */
function layanan_upload_dok(string $kategori, string $tipe, string $refId, string $file, string $name, string $mime): bool
{
    $serviceToken = api_service_token();
    if (!$serviceToken) return false;
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $serviceToken];
    if (!empty($_SESSION['access_token'])) $headers[] = 'Auth: Bearer ' . $_SESSION['access_token'];
    $ch = curl_init(rtrim(API_TEMANKU, '/') . '/upload-dok');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => ['kategori' => $kategori, 'tipe' => $tipe, 'ref_id' => $refId,
            'file' => new CURLFile($file, $mime, preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'bukti')],
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($response) ? json_decode($response, true) : null;
    return $status >= 200 && $status < 300 && is_array($data) && empty($data['error']);
}

/** POST JSON ke Go TEMANKU; berbeda dari api_request(), pesan galat dari server ikut dikembalikan. */
function layanan_post(string $endpoint, array $data): array
{
    $serviceToken = api_service_token();
    if (!$serviceToken) return ['ok' => false, 'error' => 'Layanan belum dapat dihubungi'];
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $serviceToken];
    if (!empty($_SESSION['access_token'])) $headers[] = 'Auth: Bearer ' . $_SESSION['access_token'];
    $ch = curl_init(rtrim(API_TEMANKU, '/') . '/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE), CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30]);
    $res = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($res) ? json_decode($res, true) : null;
    if ($http >= 200 && $http < 300 && is_array($json)) return ['ok' => true, 'data' => $json];
    $msg = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : 'Layanan belum dapat dihubungi. Coba lagi.';
    return ['ok' => false, 'error' => $msg];
}

/** Jenis berkas dari isi (bukan ekstensi): PDF / JPEG / PNG; selain itu ''. */
function layanan_jenis_berkas(string $file): string
{
    $head = (string) file_get_contents($file, false, null, 0, 8);
    if (strncmp($head, '%PDF-', 5) === 0) return 'application/pdf';
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($head, "\x89PNG", 4) === 0) return 'image/png';
    return '';
}

function layanan_status_label(string $status): string
{
    return ['diajukan' => 'Menunggu persetujuan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'][$status] ?? $status;
}

function layanan_css(): string
{
    return '<style>
.lyn{max-width:640px;margin:24px auto 100px;padding:0 16px}.lyn h1{font-size:20px;color:#123f40;margin:0 0 4px}
.lyn__lead{color:#5f7474;font-size:13.5px;line-height:1.6;margin:0 0 14px}
.lyn__card{background:#fff;border-radius:18px;padding:18px;margin-bottom:14px;box-shadow:0 6px 24px #123b3c0f}
.lyn label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:#5f7474;margin-bottom:10px}
.lyn [hidden]{display:none!important}
.lyn input,.lyn textarea,.lyn select{padding:10px;border:1px solid #d8e3e2;border-radius:10px;font:inherit}
.lyn__grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.lyn button{width:100%;padding:13px;border:0;border-radius:14px;background:#168f90;color:#fff;font-weight:700;cursor:pointer}
.lyn__item{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid #eef2f2;font-size:13px}
.lyn__item:first-child{border-top:0}.lyn__item small{display:block;color:#6b7280}
.lyn__st{white-space:nowrap;padding:2px 10px;border-radius:999px;font-size:11.5px;font-weight:700;align-self:flex-start}
.st-diajukan{background:#fff6e0;color:#8a5a00}.st-disetujui{background:#e8f6ee;color:#14663a}.st-ditolak{background:#fff1f0;color:#8a2c22}
</style>';
}
