<section class="tpp-card" aria-label="Perkiraan TPP">
  <span class="tpp-card__glow" aria-hidden="true"></span>
  <p class="tpp-card__label">Perkiraan TPP <?= e($bulanTpp) ?></p>
  <?php if ($tpp !== null): ?>
    <p class="tpp-card__amount"><?= e(rupiah($tpp)) ?></p>
    <div class="tpp-card__track"><span style="width:<?= max(0, min(100, (int)$rekap['persen'])) ?>%"></span></div>
    <?php $tppInfo = $rekap['tpp'] ?? null; /* [temanku] rincian dari rekap APEL */ ?>
    <?php if (is_array($tppInfo)): ?>
      <p class="tpp-card__caption"><?= !empty($tppInfo['final']) ? '<strong>Final</strong> — bulan sudah dikunci (angka yang dibayarkan).' : '<strong>Sementara</strong> — masih dapat berubah sampai bulan dikunci.' ?><br />
        Besaran <?= e(rupiah((float) ($tppInfo['besaran'] ?? 0))) ?> · potongan <?= e(number_format((float) ($tppInfo['potongan_persen'] ?? 0), 1, ',', '.')) ?>% (<?= e(rupiah((float) ($tppInfo['potongan'] ?? 0))) ?>)
        <?php if ((int) ($rekap['tak'] ?? 0) > 0): ?> · TAK <?= (int) $rekap['tak'] ?> hari<?php endif; ?></p>
    <?php else: ?>
    <p class="tpp-card__caption">Perkiraan berdasarkan rekap kehadiran; nilai akhir mengikuti perhitungan resmi.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="tpp-card__amount tpp-card__amount--empty">Data belum tersedia</p>
    <p class="tpp-card__caption">Perkiraan TPP akan muncul setelah data diterima dari sistem.</p>
  <?php endif; ?>
</section>
