<?php
// include/admin-footer.php
?>

<!-- End main content landmark -->
</main>

<!-- Footer -->
<footer class="sticky-footer bg-white" role="contentinfo">
    <div class="container my-auto">
        <div class="copyright text-center my-auto">
            <span>Copyright &copy; Bhutanese Buddhist Centre Canberra <?php echo date('Y'); ?></span>
        </div>
    </div>
</footer>
<!-- End of Footer -->

<!-- ✅ Core scripts (load ONCE on every page) -->
<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/jquery-easing/jquery.easing.min.js"></script>
<script src="js/sb-admin-2.min.js"></script>

<?php
/**
 * OPTIONAL: load chart scripts only when a page asks for them:
 * On dashboard page, set: $loadCharts = true;
 */
if (!empty($loadCharts)) : ?>
    <script src="vendor/chart.js/Chart.min.js"></script>
    <script src="js/demo/chart-area-demo.js"></script>
    <script src="js/demo/chart-pie-demo.js"></script>
<?php endif; ?>

<?php
/**
 * OPTIONAL: allow pages to inject extra JS files cleanly (e.g., DataTables)
 * Usage:
 * $pageScripts = [
 *   'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
 *   ...
 * ];
 */
if (!empty($pageScripts) && is_array($pageScripts)) :
    foreach ($pageScripts as $src) :
        $src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>
        <script src="<?php echo $src; ?>"></script>
<?php
    endforeach;
endif;
?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('form');
    forms.forEach(function (form) {
        form.addEventListener('submit', function () {
            if (form.dataset.submitting === '1') {
                return false;
            }
            form.dataset.submitting = '1';
            var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            buttons.forEach(function (btn) {
                btn.disabled = true;
                if (btn.tagName === 'BUTTON') {
                    if (!btn.dataset.originalHtml) btn.dataset.originalHtml = btn.innerHTML;
                    if (btn.dataset.loadingText) {
                        btn.innerHTML = btn.dataset.loadingText;
                    }
                } else if (btn.tagName === 'INPUT' && btn.dataset.loadingText) {
                    btn.value = btn.dataset.loadingText;
                }
            });
        }, {capture: true});
    });
});
</script>
