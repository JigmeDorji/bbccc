<?php
// admin-bank-settings.php — merged into feesSetting.php
require_once "include/config.php";
require_once "include/auth.php";
// Redirect permanently to the unified Fees Settings page
header("Location: feesSetting");
exit;
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id      = (int)($_POST['bank_id'] ?? 0);
        $bname   = trim($_POST['bank_name'] ?? '');
        $aname   = trim($_POST['account_name'] ?? '');
        $bsb     = trim($_POST['bsb'] ?? '');
        $accno   = trim($_POST['account_number'] ?? '');
        $hint    = trim($_POST['reference_hint'] ?? '');
        $active  = (int)($_POST['is_active'] ?? 1);

        if (!$bname || !$aname || !$bsb || !$accno) {
            $flash = 'All fields except Reference Hint are required.';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE pcm_bank_accounts
                    SET bank_name=:bn, account_name=:an, bsb=:bsb, account_number=:ac, reference_hint=:rh, is_active=:ia
                    WHERE id=:id
                ");
                $stmt->execute([':bn'=>$bname,':an'=>$aname,':bsb'=>$bsb,':ac'=>$accno,':rh'=>$hint?:null,':ia'=>$active,':id'=>$id]);
                $flash = 'Bank account updated.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO pcm_bank_accounts (bank_name, account_name, bsb, account_number, reference_hint, is_active)
                    VALUES (:bn,:an,:bsb,:ac,:rh,:ia)
                ");
                $stmt->execute([':bn'=>$bname,':an'=>$aname,':bsb'=>$bsb,':ac'=>$accno,':rh'=>$hint?:null,':ia'=>$active]);
                $flash = 'Bank account added.';
            }
            $ok = true;
        }
    }

    if ($act === 'delete') {
        $id = (int)($_POST['bank_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM pcm_bank_accounts WHERE id=:id")->execute([':id'=>$id]);
            $flash = 'Bank account deleted.';
            $ok = true;
        }
    }

    if ($act === 'toggle') {
        $id = (int)($_POST['bank_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE pcm_bank_accounts SET is_active = NOT is_active WHERE id=:id")->execute([':id'=>$id]);
            $flash = 'Status toggled.';
            $ok = true;
        }
    }
}

// ── Fetch all ──
$banks = $pdo->query("SELECT * FROM pcm_bank_accounts ORDER BY id")->fetchAll();

// ── Edit mode? ──
$editing = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($banks as $b) { if ($b['id'] === $eid) { $editing = $b; break; } }
}

$pageScripts = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Bank Settings</title>
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
    <?= $ok ? ".then(()=>window.location='admin-bank-settings.php')" : "" ?>;
});
</script>
<?php endif; ?>

<div class="row">
<!-- Form -->
<div class="col-lg-5 mb-4">
    <div class="card shadow">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-<?= $editing ? 'edit' : 'plus-circle' ?> mr-1"></i>
                <?= $editing ? 'Edit Bank Account' : 'Add Bank Account' ?>
            </h6>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="bank_id" value="<?= $editing ? $editing['id'] : 0 ?>">

                <div class="form-group">
                    <label class="font-weight-bold">Bank Name <span class="text-danger">*</span></label>
                    <input type="text" name="bank_name" class="form-control" required maxlength="120" value="<?= h($editing['bank_name'] ?? '') ?>" placeholder="e.g. Commonwealth Bank">
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">Account Name <span class="text-danger">*</span></label>
                    <input type="text" name="account_name" class="form-control" required maxlength="120" value="<?= h($editing['account_name'] ?? '') ?>">
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold">BSB <span class="text-danger">*</span></label>
                        <input type="text" name="bsb" class="form-control" required maxlength="20" value="<?= h($editing['bsb'] ?? '') ?>" placeholder="062-000">
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold">Account Number <span class="text-danger">*</span></label>
                        <input type="text" name="account_number" class="form-control" required maxlength="40" value="<?= h($editing['account_number'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">Reference Hint</label>
                    <input type="text" name="reference_hint" class="form-control" maxlength="255" value="<?= h($editing['reference_hint'] ?? '') ?>" placeholder="e.g. Use child name as reference">
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">Status</label>
                    <select name="is_active" class="form-control">
                        <option value="1" <?= ($editing && !$editing['is_active']) ? '' : 'selected' ?>>Active</option>
                        <option value="0" <?= ($editing && !$editing['is_active']) ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i><?= $editing ? 'Update' : 'Add' ?></button>
                <?php if ($editing): ?>
                    <a href="admin-bank-settings" class="btn btn-secondary ml-2">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<!-- List -->
<div class="col-lg-7 mb-4">
    <div class="card shadow">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list mr-1"></i>Bank Accounts (<?= count($banks) ?>)</h6></div>
        <div class="card-body">
        <?php if (empty($banks)): ?>
            <p class="text-muted mb-0">No bank accounts added yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="thead-light"><tr><th>Bank</th><th>Account</th><th>BSB</th><th>Acc #</th><th>Hint</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($banks as $b): ?>
                    <tr>
                        <td><?= h($b['bank_name']) ?></td>
                        <td><?= h($b['account_name']) ?></td>
                        <td><?= h($b['bsb']) ?></td>
                        <td><?= h($b['account_number']) ?></td>
                        <td><?= h($b['reference_hint'] ?? '—') ?></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="bank_id" value="<?= $b['id'] ?>">
                                <button class="btn btn-sm btn-<?= $b['is_active'] ? 'success' : 'secondary' ?>"><?= $b['is_active'] ? 'Active' : 'Inactive' ?></button>
                            </form>
                        </td>
                        <td>
                            <a href="?edit=<?= $b['id'] ?>" class="btn btn-info btn-sm"><i class="fas fa-edit"></i></a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this bank account?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="bank_id" value="<?= $b['id'] ?>">
                                <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                            </form>
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

</div>
</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>
</body>
</html>
