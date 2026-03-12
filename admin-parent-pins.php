<?php
// admin-parent-pins.php — Admin sets / resets kiosk PINs for parents
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;

// ── POST: set PIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_pin') {
    verify_csrf();
    $pid = (int)($_POST['parent_id'] ?? 0);
    $pin = trim($_POST['pin'] ?? '');

    if (!$pid) {
        $flash = 'Invalid parent.';
    } elseif (!preg_match('/^\d{4,6}$/', $pin)) {
        $flash = 'PIN must be 4–6 digits.';
    } else {
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE parents SET pin_hash=:h WHERE id=:id");
        $upd->execute([':h'=>$hash, ':id'=>$pid]);
        $flash = 'PIN updated successfully.';
        $ok = true;
    }
}

// ── POST: clear PIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_pin') {
    verify_csrf();
    $pid = (int)($_POST['parent_id'] ?? 0);
    if ($pid > 0) {
        $pdo->prepare("UPDATE parents SET pin_hash=NULL WHERE id=:id")->execute([':id'=>$pid]);
        $flash = 'PIN cleared.';
        $ok = true;
    }
}

// ── Fetch parents ──
$parents = $pdo->query("SELECT id, full_name, email, phone, pin_hash, status FROM parents ORDER BY full_name")->fetchAll();

$pageScripts = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Parent Kiosk PINs</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid py-3">

<?php if ($flash): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({icon:'<?= $ok?"success":"error" ?>',html:<?= json_encode($flash) ?>,timer:2000,showConfirmButton:false})
    <?= $ok ? ".then(()=>window.location='admin-parent-pins.php')" : "" ?>;
});
</script>
<?php endif; ?>

<div class="card shadow">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-key mr-1"></i>Parent Kiosk PINs</h6>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Set a 4–6 digit numeric PIN for each parent. They will use their <strong>phone number + PIN</strong> to sign in at the kiosk.</p>
        <?php if (empty($parents)): ?>
            <p class="text-muted">No parent accounts found.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-light">
                    <tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>PIN Set?</th><th>Status</th><th style="width:280px">Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($parents as $i => $p): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td class="font-weight-bold"><?= h($p['full_name']) ?></td>
                    <td><?= h($p['email']) ?></td>
                    <td><?= h($p['phone'] ?? '—') ?></td>
                    <td>
                        <?php if ($p['pin_hash']): ?>
                            <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Yes</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">No</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= ($p['status']??'Active')==='Active'?'success':'secondary' ?>"><?= h($p['status'] ?? 'Active') ?></span></td>
                    <td>
                        <form method="POST" class="form-inline" onsubmit="return confirm('Set this PIN?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_pin">
                            <input type="hidden" name="parent_id" value="<?= $p['id'] ?>">
                            <input type="text" name="pin" class="form-control form-control-sm mr-1" style="width:90px"
                                   placeholder="PIN" pattern="\d{4,6}" maxlength="6" required inputmode="numeric">
                            <button class="btn btn-primary btn-sm mr-1"><i class="fas fa-save"></i></button>
                        </form>
                        <?php if ($p['pin_hash']): ?>
                        <form method="POST" class="d-inline mt-1" onsubmit="return confirm('Clear PIN for this parent?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="clear_pin">
                            <input type="hidden" name="parent_id" value="<?= $p['id'] ?>">
                            <button class="btn btn-outline-danger btn-sm"><i class="fas fa-eraser mr-1"></i>Clear</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>
</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>
</body>
</html>
