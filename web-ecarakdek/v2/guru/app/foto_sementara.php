<?php
declare(strict_types=1);
/*
 * Foto SEMENTARA dari rekam wajah: dipakai bila pegawai BELUM punya foto SIMPEG dan server BKN (SIASN)
 * sedang tidak terhubung. Disimpan di luar public_html (tidak dapat dibuka langsung dari web) dan TIDAK
 * ditulis ke data resmi SIMPEG. Dihapus otomatis begitu foto dari BKN berhasil disimpan.
 */
function foto_sementara_dir(): string
{
    return dirname(__DIR__, 2) . '/ecarakdek_guru_foto_sementara';
}

function foto_sementara_path(string $nip): string
{
    return foto_sementara_dir() . '/' . preg_replace('/\D/', '', $nip) . '.jpg';
}

function foto_sementara_ada(string $nip): bool
{
    return preg_match('/^\d{18}$/', $nip) === 1 && is_file(foto_sementara_path($nip));
}

/** Simpan JPEG (2 KB – 800 KB). Mengembalikan true bila tersimpan. */
function foto_sementara_simpan(string $nip, string $jpeg): bool
{
    if (preg_match('/^\d{18}$/', $nip) !== 1 || strncmp($jpeg, "\xFF\xD8\xFF", 3) !== 0) return false;
    if (strlen($jpeg) < 2048 || strlen($jpeg) > 800 * 1024) return false;
    $dir = foto_sementara_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $jpeg, LOCK_EX) !== strlen($jpeg)) { @unlink($tmp); return false; }
    @chmod($tmp, 0600);
    return @rename($tmp, foto_sementara_path($nip));
}

function foto_sementara_hapus(string $nip): void
{
    if (preg_match('/^\d{18}$/', $nip) === 1) @unlink(foto_sementara_path($nip));
}
