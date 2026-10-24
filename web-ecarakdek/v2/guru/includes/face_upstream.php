<?php
declare(strict_types=1);
// Menentukan alamat Go untuk jalur wajah browser (wajah_api.php). Folder includes/ diblokir dari akses web oleh .htaccess.
if (!defined('APP_INTERNAL')) { http_response_code(403); exit('Forbidden'); }

/**
 * Aturan koneksi web -> Go:
 *  - https://...            selalu boleh (target akhir)
 *  - http://127.0.0.1 dst   boleh (satu server)
 *  - http://HOST lain       HANYA bila HOST sama persis dengan FACE_INSECURE_HTTP_HOST
 *                           di config.php. Ini izin SEMENTARA dan disengaja eksplisit:
 *                           foto wajah & token lewat internet tanpa enkripsi pada ruas ini.
 * Mengembalikan base URL grup API ("/temanku/api/v1" atau "/ecarakdek/api/v1"), atau null.
 */
function face_upstream_base(string $app = 'temanku'): ?string
{
    if (!in_array($app, ['temanku', 'ecarakdek'], true)) return null;
    $configured = defined('API_TEMANKU_FACE') ? (string) constant('API_TEMANKU_FACE') : (string) API_TEMANKU;
    $parts = parse_url($configured);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['query'])) return null;
    $scheme = strtolower($parts['scheme']);
    $host = strtolower(trim($parts['host'], '[]'));
    $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    $insecureHost = defined('FACE_INSECURE_HTTP_HOST') ? strtolower((string) constant('FACE_INSECURE_HTTP_HOST')) : '';
    $allowed = $scheme === 'https'
        || ($scheme === 'http' && ($loopback || ($insecureHost !== '' && hash_equals($insecureHost, $host))));
    if (!$allowed) {
        error_log('TEMANKU face: koneksi ke Go ditolak (bukan HTTPS/loopback/FACE_INSECURE_HTTP_HOST): ' . $scheme . '://' . $host);
        return null;
    }
    $root = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    return $root . '/' . $app . '/api/v1';
}

function face_upstream_is_https(string $base): bool
{
    return strtolower((string) parse_url($base, PHP_URL_SCHEME)) === 'https';
}

/** Kirim body JSON ke Go dan teruskan jawabannya. Tidak pernah menulis body ke log/disk. */
function face_upstream_forward(string $url, string $body, array $headers): void
{
    $https = face_upstream_is_https($url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 130,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => $https ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS),
    ]);
    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch);
    curl_close($ch);
    if (!is_string($result) || strlen($result) > 262144 || !is_array(json_decode($result, true))) {
        // Bedakan penyebab agar pegawai/admin tidak mengira ini masalah sinyal HP.
        // "upstream" = kode HTTP dari Go (0 = Go tidak dapat dihubungi dari server web).
        $upstream = is_string($result) ? $status : 0;
        error_log('TEMANKU wajah: jawaban Go tidak valid, HTTP ' . $upstream . ($curlError ? ', curl ' . $curlError : '') . ' ' . parse_url($url, PHP_URL_PATH));
        if ($upstream === 0) {
            $message = 'Server wajah tidak dapat dihubungi dari server web';
        } elseif ($upstream === 404) {
            $message = 'Fitur ini belum terpasang di server wajah (kode 404). Hubungi admin';
        } else {
            $message = 'Server wajah menjawab tidak valid (kode ' . $upstream . ')';
        }
        http_response_code(502);
        echo json_encode(['error' => $message, 'upstream' => $upstream]);
        return;
    }
    http_response_code($status >= 200 && $status <= 599 ? $status : 502);
    echo $result;
}
