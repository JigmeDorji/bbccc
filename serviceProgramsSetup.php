<?php
// serviceProgramsSetup.php — Edit icon/title/description for the
// "Classes and Practices" cards on services.php.
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_login();
if (!is_admin_role() && !is_website_admin_role()) {
    header("Location: unauthorized");
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$flashMessage = '';
$flashType = 'success';
if (isset($_SESSION['service_programs_flash']) && is_array($_SESSION['service_programs_flash'])) {
    $flashMessage = (string)($_SESSION['service_programs_flash']['message'] ?? '');
    $flashType = (string)($_SESSION['service_programs_flash']['type'] ?? 'success');
    unset($_SESSION['service_programs_flash']);
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $icon = trim((string)($_POST['icon'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        if ($id <= 0) {
            throw new Exception('Invalid card.');
        }
        if (!preg_match('/^fa-[a-z0-9-]+$/', $icon)) {
            throw new Exception('Icon must be a Font Awesome solid-icon class, e.g. fa-om or fa-chalkboard-user.');
        }
        if ($title === '') {
            throw new Exception('Title is required.');
        }

        $stmt = $pdo->prepare("UPDATE service_programs SET icon=:icon, title=:title, description=:description WHERE id=:id");
        $stmt->execute([':icon' => $icon, ':title' => $title, ':description' => $description, ':id' => $id]);

        $_SESSION['service_programs_flash'] = ['type' => 'success', 'message' => 'Card updated successfully.'];
        header('Location: serviceProgramsSetup');
        exit;
    }

    $cards = $pdo->query("SELECT * FROM service_programs ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $flashMessage = $e->getMessage();
    $flashType = 'error';
    $cards = $cards ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Setup Service Cards</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
<?php include_once 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column"><div id="content">
<?php include_once 'include/admin-header.php'; ?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Setup Service Cards</h1>
        <a href="services" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt mr-1"></i> View Services Page</a>
    </div>
    <p class="text-muted mb-4">Edit the icon, title, and description for each "Classes and Practices" card shown on the Services page. The link each card points to is fixed.</p>

    <div class="row">
        <?php foreach ($cards as $c): ?>
            <div class="col-lg-4 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas <?= htmlspecialchars((string)$c['icon']) ?> mr-1" id="preview-icon-<?= (int)$c['id'] ?>"></i>
                            <?= htmlspecialchars((string)$c['title']) ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="serviceProgramsSetup">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

                            <div class="form-group">
                                <label class="font-weight-bold">Icon</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas <?= htmlspecialchars((string)$c['icon']) ?>" id="icon-live-<?= (int)$c['id'] ?>"></i></span>
                                    </div>
                                    <input type="text" name="icon" class="form-control icon-input" data-preview="icon-live-<?= (int)$c['id'] ?>"
                                           value="<?= htmlspecialchars((string)$c['icon']) ?>" placeholder="fa-om" required>
                                </div>
                                <small class="form-text text-muted">
                                    Font Awesome solid-icon class (e.g. <code>fa-om</code>, <code>fa-hands-praying</code>).
                                    Browse icons at <a href="https://fontawesome.com/search?o=r&s=solid" target="_blank" rel="noopener">fontawesome.com</a>.
                                </small>
                            </div>

                            <div class="form-group">
                                <label class="font-weight-bold">Title</label>
                                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars((string)$c['title']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="font-weight-bold">Description</label>
                                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars((string)$c['description']) ?></textarea>
                            </div>

                            <div class="text-muted mb-2" style="font-size:.8rem;">
                                Links to: <code><?= htmlspecialchars((string)$c['link_url']) ?></code>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save mr-1"></i> Save</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</div><?php include_once 'include/admin-footer.php'; ?></div></div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
<script>
document.querySelectorAll('.icon-input').forEach(function (input) {
    input.addEventListener('input', function () {
        const preview = document.getElementById(input.dataset.preview);
        if (!preview) return;
        const cls = input.value.trim();
        preview.className = /^fa-[a-z0-9-]+$/.test(cls) ? 'fas ' + cls : 'fas fa-question';
    });
});
</script>
<script><?php if ($flashMessage): ?>Swal.fire({ icon:'<?= $flashType ?>', title:'<?= addslashes($flashMessage) ?>', showConfirmButton:false, timer:1800 });<?php endif; ?></script>
</body>
</html>
