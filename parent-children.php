<?php
// parent-children.php — Parents register & manage their children
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_parent_role()) { header("Location: unauthorized.php"); exit; }

$pdo     = pcm_pdo();
$parent  = pcm_current_parent($pdo);
if (!$parent) { die("Parent account not found. Please contact admin."); }

$parentId = (int)$parent['id'];
$flash    = '';
$ok       = false;

// ── Handle POST: add child ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();

    if ($_POST['action'] === 'add_child') {
        $name   = trim($_POST['child_name'] ?? '');
        $dob    = trim($_POST['dob'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $med    = trim($_POST['medical'] ?? '');

        if ($name === '') {
            $flash = 'Child name is required.';
        } else {
            $sid = pcm_next_student_id($pdo);
            $stmt = $pdo->prepare("
                INSERT INTO students (student_id, student_name, dob, gender, medical_issue, registration_date, approval_status, parentId)
                VALUES (:sid, :name, :dob, :g, :med, CURDATE(), 'Pending', :pid)
            ");
            $stmt->execute([':sid'=>$sid, ':name'=>$name, ':dob'=>$dob?:null, ':g'=>$gender?:null, ':med'=>$med?:null, ':pid'=>$parentId]);
            $flash = "Child <strong>{$name}</strong> added (ID: {$sid}).";
            $ok = true;
        }
    }

    if ($_POST['action'] === 'remove_child') {
        $cid = (int)($_POST['child_id'] ?? 0);
        // only allow removing Pending children with no active enrolment
        $chk = $pdo->prepare("SELECT id FROM pcm_enrolments WHERE student_id = :id LIMIT 1");
        $chk->execute([':id'=>$cid]);
        if ($chk->fetch()) {
            $flash = 'Cannot remove a child who has an enrolment. Contact admin.';
        } else {
            $del = $pdo->prepare("DELETE FROM students WHERE id = :id AND parentId = :pid AND approval_status = 'Pending'");
            $del->execute([':id'=>$cid, ':pid'=>$parentId]);
            $flash = $del->rowCount() ? 'Child removed.' : 'Cannot remove this child.';
            $ok = (bool)$del->rowCount();
        }
    }
}

// ── Fetch children ──
$children = $pdo->prepare("SELECT * FROM students WHERE parentId = :pid ORDER BY id DESC");
$children->execute([':pid'=>$parentId]);
$children = $children->fetchAll();

$pageScripts = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>My Children</title>
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

<!-- Flash -->
<?php if ($flash): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({icon:'<?= $ok?"success":"warning" ?>',html:<?= json_encode($flash) ?>,timer:2200,showConfirmButton:false})
    <?= $ok ? ".then(()=>window.location='parent-children.php')" : "" ?>;
});
</script>
<?php endif; ?>

<!-- Add Child Card -->
<div class="card shadow mb-4">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-plus-circle mr-1"></i>Register a Child</h6></div>
    <div class="card-body">
        <form method="POST" class="row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_child">

            <div class="col-md-4 mb-3">
                <label class="font-weight-bold">Child's Full Name <span class="text-danger">*</span></label>
                <input type="text" name="child_name" class="form-control" required maxlength="150" placeholder="e.g. Karma Dorji">
            </div>
            <div class="col-md-3 mb-3">
                <label class="font-weight-bold">Date of Birth</label>
                <input type="date" name="dob" class="form-control" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2 mb-3">
                <label class="font-weight-bold">Gender</label>
                <select name="gender" class="form-control">
                    <option value="">--</option>
                    <option>Male</option>
                    <option>Female</option>
                    <option>Other</option>
                </select>
            </div>
            <div class="col-md-3 mb-3">
                <label class="font-weight-bold">Medical Issues</label>
                <input type="text" name="medical" class="form-control" maxlength="500" placeholder="None">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus mr-1"></i>Add Child</button>
            </div>
        </form>
    </div>
</div>

<!-- Children List -->
<div class="card shadow mb-4">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-child mr-1"></i>My Children (<?= count($children) ?>)</h6></div>
    <div class="card-body">
    <?php if (empty($children)): ?>
        <p class="text-muted mb-0">No children registered yet. Use the form above to add your first child.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-light">
                    <tr><th>#</th><th>Student ID</th><th>Name</th><th>DOB</th><th>Gender</th><th>Medical</th><th>Registered</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($children as $i => $c): ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><code><?= h($c['student_id']) ?></code></td>
                        <td><?= h($c['student_name']) ?></td>
                        <td><?= $c['dob'] ? date('d M Y', strtotime($c['dob'])) : '-' ?></td>
                        <td><?= h($c['gender'] ?? '-') ?></td>
                        <td><?= h($c['medical_issue'] ?? 'None') ?></td>
                        <td><?= $c['registration_date'] ? date('d M Y', strtotime($c['registration_date'])) : '-' ?></td>
                        <td><span class="badge badge-<?= pcm_badge($c['approval_status'] ?? 'Pending') ?>"><?= h($c['approval_status'] ?? 'Pending') ?></span></td>
                        <td>
                            <?php
                            // Can only remove children that are still Pending and have no enrolment
                            $hasEnrol = $pdo->prepare("SELECT 1 FROM pcm_enrolments WHERE student_id=:id LIMIT 1");
                            $hasEnrol->execute([':id'=>$c['id']]);
                            if (strtolower($c['approval_status'] ?? '') === 'pending' && !$hasEnrol->fetch()):
                            ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this child?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_child">
                                <input type="hidden" name="child_id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-outline-danger btn-sm"><i class="fas fa-trash-alt"></i></button>
                            </form>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
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

</div><!-- container -->
</div><!-- content -->
<?php include 'include/admin-footer.php'; ?>
</div>
</div>
</body>
</html>
