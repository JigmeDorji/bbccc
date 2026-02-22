<?php
echo '
<!-- Core JS -->
<script src="bbccassests/js/vendor/jquery-1.12.0.min.js"></script>

<!-- SweetAlert2 (shared) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Fade-up animation on scroll -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    var els = document.querySelectorAll(".fade-up");
    if (!els.length) return;
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (e.isIntersecting) { e.target.classList.add("visible"); observer.unobserve(e.target); }
        });
    }, { threshold: 0.15 });
    els.forEach(function(el) { observer.observe(el); });
});
</script>
'
?>


