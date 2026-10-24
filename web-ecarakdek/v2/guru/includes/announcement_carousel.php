<?php
/** @var array $pengumuman Daftar pesan terbaru dari API notifikasi. */
$pengumuman = $pengumuman ?? [];
?>
<section class="card announcement-card announcement-carousel" aria-label="Pengumuman terbaru" data-announcement-carousel>
  <div class="announcement-card__heading">
    <span class="announcement-card__icon"><i class="bi bi-megaphone-fill"></i></span>
    <h2>Pengumuman</h2>
  </div>
  <?php if ($pengumuman): ?>
    <div class="announcement-carousel__slides" aria-live="off">
      <?php foreach ($pengumuman as $i => $n):
          $createdAt = strtotime((string)($n['created_at'] ?? ''));
          $isNew = $createdAt && $createdAt <= time() && time() - $createdAt < 7 * 86400;
      ?>
        <article class="announcement-carousel__slide"<?= $i > 0 ? ' hidden' : '' ?>>
          <div class="announcement-carousel__title">
            <strong><?= e((string)($n['judul'] ?? 'Informasi terbaru')) ?></strong>
            <?php if ($isNew): ?><span class="announcement-carousel__new">BARU</span><?php endif; ?>
          </div>
          <p><?= e((string)($n['isi'] ?? '')) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if (count($pengumuman) > 1): ?>
      <div class="announcement-carousel__controls">
        <button type="button" data-carousel-prev aria-label="Pengumuman sebelumnya"><i class="bi bi-chevron-left"></i></button>
        <div class="announcement-carousel__dots" aria-label="Pilih pengumuman">
          <?php foreach ($pengumuman as $i => $_): ?>
            <button type="button" data-carousel-dot="<?= $i ?>" aria-label="Pengumuman <?= $i + 1 ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>"></button>
          <?php endforeach; ?>
        </div>
        <button type="button" data-carousel-next aria-label="Pengumuman berikutnya"><i class="bi bi-chevron-right"></i></button>
      </div>
    <?php endif; ?>
    <a class="btn btn--amber btn--block" href="<?= url('notifikasi.php') ?>">Lihat Semua Pengumuman</a>
  <?php else: ?>
    <p class="announcement-card__text">Belum ada pengumuman terbaru.</p>
  <?php endif; ?>
</section>
