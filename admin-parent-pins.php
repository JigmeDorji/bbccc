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
    } elseif (!preg_match('/^\d{4,}$/', $pin)) {
        $flash = 'PIN must be at least 4 digits.';
    } else {
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE parents SET pin_hash=:h WHERE id=:id");
        $upd->execute([':h'=>$hash, ':id'=>$pid]);
        $flash = 'PIN updated successfully.';
        $ok = true;
    }
}

// ── POST: bulk set PIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_set_pin') {
    verify_csrf();
    $selected = $_POST['selected_parent_ids'] ?? [];
    $pinsById = $_POST['pins'] ?? [];

    if (!is_array($selected) || empty($selected)) {
        $flash = 'Please select at least one parent.';
    } else {
        $upd = $pdo->prepare("UPDATE parents SET pin_hash=:h WHERE id=:id");
        $updated = 0;
        $skipped = 0;
        foreach ($selected as $rawPid) {
            $pid = (int)$rawPid;
            if ($pid <= 0) { $skipped++; continue; }
            $pin = trim((string)($pinsById[$pid] ?? ''));
            if (!preg_match('/^\d{4,}$/', $pin)) {
                $skipped++;
                continue;
            }
            $hash = password_hash($pin, PASSWORD_DEFAULT);
            $upd->execute([':h' => $hash, ':id' => $pid]);
            $updated++;
        }
        if ($updated > 0) {
            $flash = "Updated PIN for {$updated} parent(s)." . ($skipped > 0 ? " Skipped {$skipped} invalid/blank row(s)." : '');
            $ok = true;
        } else {
            $flash = 'No PIN updated. Enter at least 4-digit PIN for selected rows.';
        }
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

// ── Fetch parents + students ──
$parents = $pdo->query("
    SELECT
        p.id,
        p.full_name,
        p.email,
        p.phone,
        p.pin_hash,
        p.status,
        GROUP_CONCAT(DISTINCT s.student_name ORDER BY s.student_name SEPARATOR ', ') AS student_names
    FROM parents p
    LEFT JOIN students s
        ON s.parentId = p.id OR s.parent_id = p.id
    GROUP BY p.id, p.full_name, p.email, p.phone, p.pin_hash, p.status
    ORDER BY p.full_name
")->fetchAll();

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
        <p class="text-muted small mb-3">Set a numeric PIN with at least 4 digits for each parent. They will use their <strong>phone number + PIN</strong> to sign in at the kiosk.</p>
        <div class="row mb-3">
            <div class="col-md-5">
                <label class="small font-weight-bold text-muted mb-1">Search by Parent Name</label>
                <input type="text" id="parentNameSearch" class="form-control form-control-sm" placeholder="Type parent name...">
            </div>
        </div>
        <?php if (empty($parents)): ?>
            <p class="text-muted">No parent accounts found.</p>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-center mb-2">
                <button type="button" class="btn btn-outline-secondary btn-sm mr-2 mb-2" id="selectAllParentsBtn">
                    <i class="fas fa-check-square mr-1"></i>Select All
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm mr-2 mb-2" id="clearSelectionBtn">
                    <i class="fas fa-square mr-1"></i>Clear Selection
                </button>
                <button type="button" class="btn btn-success btn-sm mb-2" id="bulkSaveBtn">
                    <i class="fas fa-save mr-1"></i>Save Selected
                </button>
            </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="parentPinsTable">
                <thead class="thead-light">
                    <tr><th style="width:44px">Sel</th><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Students</th><th>PIN Set?</th><th>Status</th><th style="width:280px">Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($parents as $i => $p): ?>
                <tr data-parent-name="<?= h(strtolower((string)$p['full_name'])) ?>">
                    <td class="text-center align-middle">
                        <input type="checkbox" class="bulk-parent-check" name="selected_parent_ids[]" value="<?= (int)$p['id'] ?>">
                    </td>
                    <td><?= $i+1 ?></td>
                    <td class="font-weight-bold"><?= h($p['full_name']) ?></td>
                    <td><?= h($p['email']) ?></td>
                    <td><?= h($p['phone'] ?? '—') ?></td>
                    <td><?= h($p['student_names'] ?? '—') ?></td>
                    <td>
                        <?php if ($p['pin_hash']): ?>
                            <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Yes</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">No</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= ($p['status']??'Active')==='Active'?'success':'secondary' ?>"><?= h($p['status'] ?? 'Active') ?></span></td>
                    <td>
                        <input type="text"
                               name="pins[<?= (int)$p['id'] ?>]"
                               class="form-control form-control-sm mb-2"
                               style="max-width:160px"
                               placeholder="PIN for bulk save"
                               pattern="\d{4,}"
                               inputmode="numeric">
                        <form method="POST" class="form-inline" data-confirm="Set this PIN?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_pin">
                            <input type="hidden" name="parent_id" value="<?= $p['id'] ?>">
                            <input type="text" name="pin" class="form-control form-control-sm mr-1" style="width:90px"
                                   placeholder="PIN" pattern="\d{4,}" required inputmode="numeric">
                            <button class="btn btn-primary btn-sm mr-1"><i class="fas fa-save"></i></button>
                        </form>
                        <?php if ($p['pin_hash']): ?>
                        <form method="POST" class="d-inline mt-1" data-confirm="Clear PIN for this parent?">
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
<form method="POST" id="bulkPinSubmitForm" style="display:none;" data-confirm="Save PIN for selected parents?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_set_pin">
    <div id="bulkPinHiddenInputs"></div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('parentNameSearch');
    const rows = document.querySelectorAll('#parentPinsTable tbody tr');
    const selectAllBtn = document.getElementById('selectAllParentsBtn');
    const clearSelectionBtn = document.getElementById('clearSelectionBtn');
    const bulkSaveBtn = document.getElementById('bulkSaveBtn');
    const bulkForm = document.getElementById('bulkPinSubmitForm');
    const bulkHidden = document.getElementById('bulkPinHiddenInputs');
    if (!input) return;
    input.addEventListener('input', function () {
        const q = (input.value || '').trim().toLowerCase();
        rows.forEach((row) => {
            const name = row.getAttribute('data-parent-name') || '';
            row.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
    });
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
            document.querySelectorAll('.bulk-parent-check').forEach((cb) => {
                const row = cb.closest('tr');
                if (row && row.style.display !== 'none') cb.checked = true;
            });
        });
    }
    if (clearSelectionBtn) {
        clearSelectionBtn.addEventListener('click', function () {
            document.querySelectorAll('.bulk-parent-check').forEach((cb) => cb.checked = false);
        });
    }
    if (bulkSaveBtn && bulkForm && bulkHidden) {
        bulkSaveBtn.addEventListener('click', function () {
            const selected = Array.from(document.querySelectorAll('.bulk-parent-check:checked'));
            bulkHidden.innerHTML = '';
            selected.forEach((cb) => {
                const pid = cb.value;
                const pinInput = document.querySelector('input[name="pins[' + pid + ']"]');
                const pinVal = pinInput ? (pinInput.value || '') : '';

                const i1 = document.createElement('input');
                i1.type = 'hidden';
                i1.name = 'selected_parent_ids[]';
                i1.value = pid;
                bulkHidden.appendChild(i1);

                const i2 = document.createElement('input');
                i2.type = 'hidden';
                i2.name = 'pins[' + pid + ']';
                i2.value = pinVal;
                bulkHidden.appendChild(i2);
            });
            bulkForm.submit();
        });
    }
});
</script>
</body>
</html>
