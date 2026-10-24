<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/includes/repository.php';

$page      = 'layanan';
$pageTitle = 'Layanan Kepegawaian';
require __DIR__ . '/includes/header.php';

$internal  = layanan('internal');
$eksternal = layanan('eksternal');
$adminLayanan = !can('absensi.view');
?>
<?php require __DIR__ . '/includes/mobile_top.php'; ?>
<div class="services-page">
  <form class="services-page__mobile-search" method="get" action="<?= url('layanan.php') ?>" role="search" id="services-search">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="search" name="q" value="<?= e($cari) ?>" placeholder="Cari layanan <?= $adminLayanan ? 'Admin OPD' : 'kepegawaian' ?>..." aria-label="Cari layanan" />
  </form>
  <header class="services-page__heading">
    <h1><?= $adminLayanan ? 'Layanan Admin OPD' : 'Layanan Kepegawaian' ?></h1>
    <p><?= $adminLayanan ? 'Akses aplikasi pengelolaan dan bantuan layanan OPD.' : 'Semua pengajuan diproses melalui SIMPEG dan diverifikasi BKPSDM.' ?></p>
  </header>

  <div class="services-page__internal-group" id="services-internal">
    <h2 class="services-page__mobile-title"><?= $adminLayanan ? 'Layanan Admin OPD' : 'Layanan Internal' ?></h2>
    <?php if ($internal): ?>
      <div class="services-page__internal">
        <?php foreach ($internal as $l): ?>
          <a class="services-page__tile" href="<?= e($l['url'] ?: '#') ?>" data-service-search="<?= e($l['nama'] . ' ' . $l['deskripsi']) ?>"<?= preg_match('~^https?://~i', (string)($l['url'] ?? '')) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
            <span class="services-page__icon ico--<?= e($l['warna']) ?>"><i class="bi <?= e($l['icon']) ?>"></i></span>
            <span class="services-page__text"><strong><?= e($l['nama']) ?></strong><small><?= e($l['deskripsi']) ?></small></span>
            <i class="bi bi-chevron-right services-page__arrow" aria-hidden="true"></i>
          </a>
        <?php endforeach; ?>
      </div>
      <p class="services-page__empty" id="services-no-results" hidden></p>
    <?php else: ?>
      <p class="services-page__empty">Belum ada layanan internal.</p>
    <?php endif; ?>
  </div>

  <h2 class="services-page__section-title">Layanan Eksternal</h2>
  <div class="services-page__external">
    <?php foreach ($eksternal as $x): ?>
      <a class="services-page__tile" href="<?= e($x['url']) ?>" target="_blank" rel="noopener noreferrer">
        <span class="services-page__icon ico--<?= e($x['warna']) ?>"><i class="bi <?= e($x['icon']) ?>"></i></span>
        <span class="services-page__text"><strong><?= e($x['nama']) ?></strong><small><?= e($x['deskripsi']) ?></small></span>
        <i class="bi bi-arrow-up-right services-page__arrow" aria-hidden="true"></i>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function () {
  var form = document.getElementById('services-search');
  var empty = document.getElementById('services-no-results');
  if (!form || !empty) return;
  var input = form.querySelector('input[name="q"]');
  var tiles = Array.prototype.slice.call(document.querySelectorAll('#services-internal [data-service-search]'));
  function normalize(text) { return text.toLocaleLowerCase('id').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }
  function filter() {
    var query = normalize(input.value);
    var visible = 0;
    tiles.forEach(function (tile) {
      var matches = normalize(tile.getAttribute('data-service-search')).indexOf(query) !== -1;
      tile.hidden = !matches;
      if (matches) visible++;
    });
    empty.hidden = visible !== 0;
    if (!visible) empty.textContent = 'Tidak ada layanan internal yang cocok dengan “' + input.value.trim() + '”.';
  }
  input.addEventListener('input', filter);
  form.addEventListener('submit', function (event) { event.preventDefault(); filter(); });
  filter();
}());
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
