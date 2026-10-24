<?php
declare(strict_types=1);
/*
 * PORTAL GURU e-CARAKDEK (/v2/guru) — tampilan & fungsi TEMANKU untuk guru/tendik.
 * Login, sesi, token servis & SSO, CSRF dan halaman error MEMAKAI e-Carakdek (/v2/app):
 * satu sesi ECARAKDEKSID, jadi guru yang login di /v2 langsung masuk ke sini tanpa login ulang.
 * Fungsi bantu TEMANKU (url, user, api_request, can, middleware, ...) ada di app/compat.php.
 */
if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_INTERNAL', true);
define('BASE_PATH', realpath(__DIR__ . '/../../app'));   // library e-Carakdek
define('GURU_PATH', dirname(__DIR__));

require_once BASE_PATH . '/errorpage.php';
if (PHP_SAPI !== 'cli' && !defined('SIMPEG_ERROR_HANDLERS')) {
    define('SIMPEG_ERROR_HANDLERS', true);
    ob_start();
    set_exception_handler(function (Throwable $e) {
        error_log('ECARAKDEK guru uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        simpeg_error_page(500, null, 'exception');
    });
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            error_log('ECARAKDEK guru fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
            simpeg_error_page(500, null, 'fatal');
        }
    });
}

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/session.php';
require_once BASE_PATH . '/security.php';

error_reporting(E_ALL);
ini_set('display_errors', (bool)simpeg_config('app.debug', false) ? '1' : '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Makassar');
if (!defined('APP_URL')) {
    define('APP_URL', simpeg_base_url());
}

simpeg_session_start();
simpeg_send_security_headers();
if (!headers_sent()) {
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    // Rekam/absen wajah (MediaPipe) butuh WebAssembly & worker blob; selain itu sama dengan CSP e-Carakdek.
    $sso = preg_replace('#^(https?://[^/]+).*$#', '$1', (string)simpeg_config('keycloak.base', 'https://sso-bkd.kepulauanselayarkab.go.id'));
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
        . "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net; "
        . "img-src 'self' data: blob: https://*.tile.openstreetmap.org https://server.arcgisonline.com https://cdn.jsdelivr.net; "
        . "media-src 'self' blob: mediastream:; worker-src 'self' blob:; connect-src 'self' blob: data:; "
        . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self' " . $sso);
}

// Validasi token SSO (+ refresh), peran dari API Go /asn/me, same-origin & CSRF (_csrf / X-CSRF-Token)
require_once BASE_PATH . '/auth.php';
require_once BASE_PATH . '/api.php';

require_once GURU_PATH . '/includes/functions.php';
require_once __DIR__ . '/compat.php';
