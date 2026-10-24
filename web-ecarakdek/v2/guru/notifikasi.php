<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';

$page      = 'notifikasi';
$pageTitle = 'Notifikasi';
require __DIR__ . '/includes/header.php';

// Halaman (page) notifikasi: API Go mengirim total + offset; API lama (tanpa total) dipotong di sini.
const NOTIF_PER_HALAMAN = 15;
$hal = max(1, (int)($_GET['hal'] ?? 1));
$r = temanku_get('/notifikasi?' . http_build_query(['nip' => current_nip(), 'limit' => NOTIF_PER_HALAMAN, 'offset' => ($hal - 1) * NOTIF_PER_HALAMAN]));
if (is_array($r) && isset($r['total'])) {
    $total = (int)$r['total'];
    $list = api_rows($r);
} else {
    $semua = api_rows(temanku_get('/notifikasi?' . http_build_query(['nip' => current_nip(), 'limit' => 100])));
    $total = count($semua);
    $list = array_slice($semua, ($hal - 1) * NOTIF_PER_HALAMAN, NOTIF_PER_HALAMAN);
}
$jumlahHal = max(1, (int)ceil($total / NOTIF_PER_HALAMAN));

// tandai terbaca melalui REST API
notifikasi_tandai_dibaca();

$ikon = [
    'info'       => ['bi-info-circle-fill', 'tosca'],
    'sukses'     => ['bi-check-circle-fill', 'hijau'],
    'peringatan' => ['bi-megaphone-fill', 'kuning'],
    'bahaya'     => ['bi-exclamation-circle-fill', 'merah'],
];
?>
<?php require __DIR__ . '/includes/mobile_top.php'; ?>
<section class="notifications-page" aria-labelledby="notifications-title">
<h1 id="notifications-title">Notifikasi</h1>

<div class="notifications-page__list">
  <?php foreach ($list as $n):
      [$ic, $warna] = $ikon[$n['jenis']] ?? $ikon['info'];
      if (preg_match('/presensi|absen/i', (string)$n['judul'])) $ic = 'bi-fingerprint';
      if (preg_match('/usulan riwayat/i', (string)$n['judul'])) $ic = 'bi-file-earmark-person-fill';
      $timestamp = strtotime((string)($n['created_at'] ?? ''));
      $selisih = $timestamp ? max(0, time() - $timestamp) : 0;
      if (!$timestamp) $waktuSingkat = '';
      elseif ($selisih < 60) $waktuSingkat = 'Baru';
      elseif ($selisih < 3600) $waktuSingkat = floor($selisih / 60) . 'm';
      elseif ($selisih < 86400) $waktuSingkat = floor($selisih / 3600) . 'j';
      elseif ($selisih < 604800) $waktuSingkat = floor($selisih / 86400) . 'h';
      else $waktuSingkat = tanggal_id(date('Y-m-d', $timestamp));
  ?>
    <article class="notifications-page__item">
      <span class="notifications-page__icon ico--<?= e($warna) ?>"><i class="bi <?= e($ic) ?>"></i></span>
      <div class="notifications-page__body">
        <div class="notifications-page__row">
          <h2><?= e($n['judul']) ?></h2>
          <time datetime="<?= e($n['created_at'] ?? '') ?>" title="<?= e(tanggal_id(substr((string)($n['created_at'] ?? ''), 0, 10))) ?>"><?= e($waktuSingkat) ?></time>
        </div>
        <p><?= e($n['isi']) ?></p>
        <?php if (preg_match('/usulan riwayat/i', (string)$n['judul'])): ?><a class="notif-tautan" href="<?= url('riwayat.php') ?>">Buka Riwayat <i class="bi bi-arrow-right"></i></a><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if (!$list): ?>
    <p class="notifications-page__empty"><?= $hal > 1 ? 'Tidak ada notifikasi di halaman ini.' : 'Belum ada notifikasi.' ?></p>
  <?php endif; ?>
</div>

<?php if ($jumlahHal > 1): ?>
<style>
.notif-pager{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px;font-size:13px;color:var(--muted,#64748b)}
.notif-pager__nav{display:flex;gap:6px;flex-wrap:wrap}
.notif-pager a,.notif-pager span.is-aktif{min-width:36px;height:36px;padding:0 10px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;border:1px solid rgba(15,23,42,.12);background:#fff;color:inherit;text-decoration:none;font-weight:600}
.notif-pager span.is-aktif{background:#d94f7f;border-color:#d94f7f;color:#fff}
.notif-pager a:hover{border-color:#d94f7f;color:#602438}
.notif-tautan{display:inline-flex;gap:4px;align-items:center;margin-top:4px;font-size:12.5px;font-weight:700;color:#602438;text-decoration:none}
</style>
<nav class="notif-pager" aria-label="Halaman notifikasi">
  <span><?= ($hal - 1) * NOTIF_PER_HALAMAN + 1 ?>–<?= min($total, $hal * NOTIF_PER_HALAMAN) ?> dari <?= $total ?></span>
  <span class="notif-pager__nav">
    <?php if ($hal > 1): ?><a href="<?= url('notifikasi.php?hal=' . ($hal - 1)) ?>" aria-label="Sebelumnya"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
    <?php foreach (array_unique([1, max(1, $hal - 1), $hal, min($jumlahHal, $hal + 1), $jumlahHal]) as $i => $h): ?>
      <?php if ($h === $hal): ?><span class="is-aktif" aria-current="page"><?= $h ?></span><?php else: ?><a href="<?= url('notifikasi.php?hal=' . $h) ?>"><?= $h ?></a><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($hal < $jumlahHal): ?><a href="<?= url('notifikasi.php?hal=' . ($hal + 1)) ?>" aria-label="Berikutnya"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
  </span>
</nav>
<?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
