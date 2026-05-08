<?php
// mark-absenteeism.php — Parent absence marking and history
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_parent_role()) { header("Location: unauthorized"); exit; }

$pdo      = pcm_pdo();
$parent   = pcm_current_parent($pdo);
if (!$parent) die("Parent account not found.");
$parentId = (int)$parent['id'];
$flash    = '';
$ok       = false;

function bbcc_ensure_absence_unique_daily(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $idx = $pdo->query("SHOW INDEX FROM pcm_absence_requests WHERE Key_name = 'uniq_absence_child_day'");
        if (!$idx || !$idx->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE pcm_absence_requests ADD UNIQUE KEY uniq_absence_child_day (child_id, absence_date)");
        }
    } catch (Throwable $e) {
        error_log('[BBCC] absence unique index check skipped: ' . $e->getMessage());
    }
    $done = true;
}
bbcc_ensure_absence_unique_daily($pdo);

// ── POST: absence request ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'absence') {
    verify_csrf();
    if (!bbcc_verify_form_nonce_once('parent_absence_submit')) {
        $flash = 'Duplicate submission detected. Please submit once and wait.';
    } else {
    $childId = (int)($_POST['child_id'] ?? 0);
    $date    = trim($_POST['absence_date'] ?? '');
    $reason  = trim($_POST['reason'] ?? '');

    // Verify child belongs to parent
    $own = $pdo->prepare("SELECT student_name FROM students WHERE id=:id AND parentId=:pid LIMIT 1");
    $own->execute([':id'=>$childId, ':pid'=>$parentId]);
    $child = $own->fetch();

    if (!$child)          { $flash = 'Invalid child.'; }
    elseif (!$date)       { $flash = 'Date is required.'; }
    elseif (!$reason)     { $flash = 'Reason is required.'; }
    else {
        $dup = $pdo->prepare("
            SELECT id
            FROM pcm_absence_requests
            WHERE child_id = :cid AND absence_date = :d
            LIMIT 1
        ");
        $dup->execute([':cid'=>$childId, ':d'=>$date]);
        if ($dup->fetch(PDO::FETCH_ASSOC)) {
            $flash = 'Absence already marked for this child on this date.';
        } else {
            $ins = $pdo->prepare("
                INSERT INTO pcm_absence_requests (child_id, parent_id, absence_date, reason)
                VALUES (:cid, :pid, :d, :r)
            ");
            $ins->execute([':cid'=>$childId, ':pid'=>$parentId, ':d'=>$date, ':r'=>$reason]);
            pcm_notify_admin_absence($child['student_name'], $parent['full_name'], $date);
            $flash = 'Absence request submitted.';
            $ok = true;
        }
    }
    }
}

// ── My children ──
$kids = $pdo->prepare("SELECT id, student_id, student_name FROM students WHERE parentId=:pid ORDER BY student_name");
$kids->execute([':pid'=>$parentId]);
$kids = $kids->fetchAll();
$kidIds = array_column($kids, 'id');

// ── Absence requests ──
$absences = [];
if ($kidIds) {
    $in = implode(',', array_map('intval', $kidIds));
    $absences = $pdo->query("
        SELECT ar.*, s.student_name
        FROM pcm_absence_requests ar
        JOIN students s ON s.id = ar.child_id
        WHERE ar.child_id IN ({$in})
        ORDER BY ar.created_at DESC
        LIMIT 100
    ")->fetchAll();
}

// This page is now dedicated to absence marking only.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Mark Absenteeism</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    .att-summary-card {
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 12px 14px;
        background: #fff;
    }
    .att-summary-label { font-size: .75rem; color: #6c757d; text-transform: uppercase; letter-spacing: .03em; }
    .att-summary-value { font-size: 1.2rem; font-weight: 700; color: #1f2937; line-height: 1.2; }
    </style>
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
    Swal.fire({icon:'<?= $ok?"success":"warning" ?>',html:<?= json_encode($flash) ?>,timer:2200,showConfirmButton:false})
    <?= $ok ? ".then(()=>window.location='mark-absenteeism.php')" : "" ?>;
});
</script>
<?php endif; ?>
<!-- ─── Absence Requests ─── -->
<div class="row">
<div class="col-lg-5 mb-4">
    <div class="card shadow">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-plus-circle mr-1"></i>New Absence Request</h6></div>
        <div class="card-body">
            <?php if (empty($kids)): ?>
                <div class="text-center py-3 text-muted">
                    <i class="fas fa-child fa-2x mb-2" style="opacity:0.3;"></i>
                    <p class="mb-0">Register a child first.</p>
                </div>
            <?php else: ?>
            <form method="POST" id="absenceForm">
                <?= csrf_field() ?>
                <?= bbcc_form_nonce_field('parent_absence_submit') ?>
                <input type="hidden" name="action" value="absence">
                <div class="form-group">
                    <label><i class="fas fa-child mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Child <span class="text-danger">*</span></label>
                    <select name="child_id" class="form-control" required>
                        <option value="">— Select child —</option>
                        <?php foreach ($kids as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= h($k['student_name']) ?> (<?= h($k['student_id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-day mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Date <span class="text-danger">*</span></label>
                    <input type="date" name="absence_date" class="form-control" required min="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-comment-alt mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Reason <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="3" required maxlength="2000" placeholder="Why will your child be absent?"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" id="absBtn" data-loading-text="<span class='spinner-border spinner-border-sm mr-1'></span>Submitting..."><i class="fas fa-paper-plane mr-1"></i>Submit Request</button>
            </form>
            <script>
            document.getElementById('absenceForm')?.addEventListener('submit', function() {
                const btn = document.getElementById('absBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Submitting...';
            });
            </script>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="col-lg-7 mb-4">
    <div class="card shadow">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">My Absence Requests</h6></div>
        <div class="card-body">
        <?php if (empty($absences)): ?>
            <p class="text-muted mb-0">No absence requests submitted.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-hover">
                    <thead class="thead-light"><tr><th>Date</th><th>Child</th><th>Reason</th><th>Date Applied</th><th>Remark</th></tr></thead>
                    <tbody>
                    <?php foreach ($absences as $ab): ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($ab['absence_date'])) ?></td>
                        <td><?= h($ab['student_name']) ?></td>
                        <td><?= h(mb_strimwidth($ab['reason'],0,80,'…')) ?></td>
                        <td><?= !empty($ab['created_at']) ? date('d M Y, h:i A', strtotime($ab['created_at'])) : '—' ?></td>
                        <td><span class="badge badge-danger">Absent</span></td>
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
