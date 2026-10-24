<?php
/** Tombol pindah antar-pengajuan (Cuti · Dinas · Claim presensi). @var string $tabAktif cuti|dinas|claim */
$tabAktif = $tabAktif ?? '';
?>
<nav class="pj-tab" aria-label="Jenis pengajuan">
  <?php foreach ([['cuti', 'cuti.php', 'Ajukan Cuti', 'bi-calendar-check-fill'], ['dinas', 'dinas.php', 'Pengajuan Dinas', 'bi-briefcase-fill'], ['claim', 'claim.php', 'Claim Presensi', 'bi-clipboard-check-fill']] as [$k, $f, $l, $i]): ?>
    <a class="pj-tab__btn<?= $tabAktif === $k ? ' is-aktif' : '' ?>" href="<?= e(url($f)) ?>"<?= $tabAktif === $k ? ' aria-current="page"' : '' ?>><i class="bi <?= e($i) ?>"></i><span><?= e($l) ?></span></a>
  <?php endforeach; ?>
</nav>
<style>
.pj-tab{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}
.pj-tab__btn{display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:0 16px;border-radius:999px;border:1px solid #f2dde5;background:#fff;color:#b83a63;font-weight:700;font-size:13.5px;text-decoration:none;box-shadow:0 6px 18px -14px rgba(140,33,73,.45);transition:background .15s,transform .15s}
.pj-tab__btn i{font-size:15px}
.pj-tab__btn:hover{background:#fdeff4;color:#8c2149;transform:translateY(-1px)}
.pj-tab__btn.is-aktif{background:linear-gradient(135deg,#e35d8a 0%,#d94f7f 55%,#b83a63 100%);border-color:transparent;color:#fff}
@media (max-width:560px){.pj-tab{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.pj-tab__btn{flex-direction:column;justify-content:center;gap:3px;min-height:58px;padding:6px 4px;border-radius:16px;font-size:11.5px;text-align:center}.pj-tab__btn i{font-size:18px}}
</style>
