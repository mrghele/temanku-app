    </main>
  </div>
</div>
<?php require __DIR__ . '/bottomnav.php'; ?>
<script>
(function () {
  var trigger = document.querySelector('.bottomnav__fab');
  var sheet = document.getElementById('presensi-sheet');
  if (!trigger || !sheet) return;
  function close() { sheet.hidden = true; trigger.setAttribute('aria-expanded', 'false'); document.body.classList.remove('sheet-open'); trigger.focus(); }
  trigger.addEventListener('click', function () { sheet.hidden = false; trigger.setAttribute('aria-expanded', 'true'); document.body.classList.add('sheet-open'); sheet.querySelector('.presensi-sheet__handle').focus(); });
  sheet.querySelectorAll('[data-close-sheet]').forEach(function (el) { el.addEventListener('click', close); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !sheet.hidden) close(); });
  // [simulasi-menu] SEMENTARA: buka menu presensi otomatis bila alamat berakhiran #presensi
  if (window.location.hash === '#presensi') {
    trigger.click();
    if (window.history && history.replaceState) history.replaceState(null, '', window.location.pathname + window.location.search);
  }
  // [/simulasi-menu]
}());
(function () {
  var menu = document.querySelector('.account-menu');
  if (!menu) return;
  document.addEventListener('click', function (event) {
    if (menu.open && !menu.contains(event.target)) menu.open = false;
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && menu.open) {
      menu.open = false;
      menu.querySelector('summary').focus();
    }
  });
}());
(function () {
  document.querySelectorAll('[data-announcement-carousel]').forEach(function (carousel) {
    var slides = Array.prototype.slice.call(carousel.querySelectorAll('.announcement-carousel__slide'));
    var dots = Array.prototype.slice.call(carousel.querySelectorAll('[data-carousel-dot]'));
    if (slides.length < 2) return;
    var active = 0;
    var timer;
    function show(index) {
      active = (index + slides.length) % slides.length;
      slides.forEach(function (slide, i) { slide.hidden = i !== active; });
      dots.forEach(function (dot, i) { dot.setAttribute('aria-current', i === active ? 'true' : 'false'); });
    }
    function pause() { if (timer) clearInterval(timer); timer = null; }
    function play() {
      pause();
      if (!document.hidden && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        timer = setInterval(function () { show(active + 1); }, 6500);
      }
    }
    carousel.querySelector('[data-carousel-prev]').addEventListener('click', function () { show(active - 1); play(); });
    carousel.querySelector('[data-carousel-next]').addEventListener('click', function () { show(active + 1); play(); });
    dots.forEach(function (dot, i) { dot.addEventListener('click', function () { show(i); play(); }); });
    carousel.addEventListener('mouseenter', pause);
    carousel.addEventListener('mouseleave', play);
    carousel.addEventListener('focusin', pause);
    carousel.addEventListener('focusout', function (event) { if (!carousel.contains(event.relatedTarget)) play(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) pause(); else play(); });
    play();
  });
}());
</script>
</body>
</html>
