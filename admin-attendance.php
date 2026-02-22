<?php
// admin-attendance.php — Admin kiosk log viewer, manual entry, absence request management
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized.php"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    // Manual kiosk entry
    if ($act === 'manual_entry') {
        $childId  = (int)($_POST['child_id'] ?? 0);
        $logDate  = trim($_POST['log_date'] ?? '');
        $timeIn   = trim($_POST['time_in'] ?? '') ?: null;
        $timeOut  = trim($_POST['time_out'] ?? '') ?: null;

        if (!$childId || !$logDate) {
            $flash = 'Child and date are required.';
        } else {
            // Get parent ID from student
            $stu = $pdo->prepare("SELECT parentId FROM students WHERE id=:id LIMIT 1");
            $stu->execute([':id'=>$childId]);
            $s = $stu->fetch();
            $pid = $s ? (int)$s['parentId'] : 0;

            $stmt = $pdo->prepare("
                INSERT INTO pcm_kiosk_log (child_id, parent_id, log_date, time_in, time_out, method)
                VALUES (:cid, :pid, :d, :ti, :to, 'MANUAL')
                ON DUPLICATE KEY UPDATE time_in=COALESCE(VALUES(time_in),time_in), time_out=COALESCE(VALUES(time_out),time_out)
            ");
            $stmt->execute([':cid'=>$childId, ':pid'=>$pid, ':d'=>$logDate, ':ti'=>$timeIn, ':to'=>$timeOut]);
            $flash = 'Manual entry saved.';
            $ok = true;
        }
    }

    // Approve / Reject absence
    if (in_array($act, ['approve_absence','reject_absence'])) {
        $absId = (int)($_POST['absence_id'] ?? 0);
        $note  = trim($_POST['admin_note'] ?? '');
        $newSt = ($act === 'approve_absence') ? 'Approved' : 'Rejected';

        $upd = $pdo->prepare("
            UPDATE pcm_absence_requests SET status=:st, admin_note=:n, decided_by=:db, decided_at=NOW()
            WHERE id=:id AND status='Pending'
        ");
        $upd->execute([':st'=>$newSt, ':n'=>$note?:null, ':db'=>$_SESSION['username']??'admin', ':id'=>$absId]);
        $flash = $upd->rowCount() ? "Absence request {$newSt}." : 'Already processed.';
        $ok = (bool)$upd->rowCount();
    }
}

$tab = $_GET['tab'] ?? 'kiosk';

// ── Kiosk logs ──
$filterDate = $_GET['date'] ?? date('Y-m-d');
$kioskLogs = $pdo->prepare("
    SELECT k.*, s.student_name, s.student_id AS stu_code, p.full_name AS parent_name
    FROM pcm_kiosk_log k
    JOIN students s ON s.id = k.child_id
    LEFT JOIN parents p ON p.id = k.parent_id
    WHERE k.log_date = :d
    ORDER BY k.time_in
");
$kioskLogs->execute([':d'=>$filterDate]);
$kioskLogs = $kioskLogs->fetchAll();

// ── All approved students for manual entry ──
$allStudents = $pdo->query("SELECT id, student_id, student_name FROM students WHERE approval_status='Approved' ORDER BY student_name")->fetchAll();

// ── Absence requests ──
$absences = $pdo->query("
    SELECT ar.*, s.student_name, p.full_name AS parent_name, p.email AS parent_email
    FROM pcm_absence_requests ar
    JOIN students s ON s.id = ar.child_id
    JOIN parents  p ON p.id = ar.parent_id
    ORDER BY FIELD(ar.status,'Pending','Approved','Rejected'), ar.created_at DESC
    LIMIT 200
")->fetchAll();

$pendingAbs = count(array_filter($absences, fn($r)=>$r['status']==='Pending'));

$pageScripts = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Kiosk &amp; Absence</title>
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
    Swal.fire({icon:'<?= $ok?"success":"warning" ?>',html:<?= json_encode($flash) ?>,timer:2200,showConfirmButton:false})
    <?= $ok ? ".then(()=>window.location='admin-attendance.php?tab={$tab}')" : "" ?>;
});
</script>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><a class="nav-link <?= $tab==='kiosk'?'active':'' ?>" href="?tab=kiosk"><i class="fas fa-door-open mr-1"></i>Kiosk Records</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='manual'?'active':'' ?>" href="?tab=manual"><i class="fas fa-keyboard mr-1"></i>Manual Entry</a></li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='absence'?'active':'' ?>" href="?tab=absence">
            <i class="fas fa-calendar-times mr-1"></i>Absence Requests
            <?php if ($pendingAbs): ?><span class="badge badge-warning ml-1"><?= $pendingAbs ?></span><?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'kiosk'): ?>
<!-- ─── Kiosk Records ─── -->
<div class="card shadow">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">Kiosk Sign In/Out — <?= date('d M Y', strtotime($filterDate)) ?></h6>
        <form class="form-inline" method="GET">
            <input type="hidden" name="tab" value="kiosk">
            <input type="date" name="date" class="form-control form-control-sm" value="<?= h($filterDate) ?>">
            <button class="btn btn-primary btn-sm ml-2">Go</button>
        </form>
    </div>
    <div class="card-body">
    <?php if (empty($kioskLogs)): ?>
        <p class="text-muted mb-0">No kiosk records for this date.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-hover">
                <thead class="thead-light"><tr><th>#</th><th>Child</th><th>Student ID</th><th>Parent</th><th>Sign In</th><th>Sign Out</th><th>Method</th></tr></thead>
                <tbody>
                <?php foreach ($kioskLogs as $i => $k): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= h($k['student_name']) ?></td>
                    <td><code><?= h($k['stu_code']) ?></code></td>
                    <td><?= h($k['parent_name'] ?? '-') ?></td>
                    <td><?= $k['time_in'] ? date('h:i A', strtotime($k['time_in'])) : '—' ?></td>
                    <td><?= $k['time_out'] ? date('h:i A', strtotime($k['time_out'])) : '—' ?></td>
                    <td><span class="badge badge-<?= $k['method']==='KIOSK'?'info':'secondary' ?>"><?= h($k['method']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'manual'): ?>
<!-- ─── Manual Entry ─── -->
<div class="card shadow" style="max-width:600px">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-keyboard mr-1"></i>Manual Kiosk Entry</h6></div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="manual_entry">
            <div class="form-group">
                <label class="font-weight-bold">Child <span class="text-danger">*</span></label>
                <select name="child_id" class="form-control" required>
                    <option value="">--</option>
                    <?php foreach ($allStudents as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= h($s['student_name']) ?> (<?= h($s['student_id']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="font-weight-bold">Date <span class="text-danger">*</span></label>
                <input type="date" name="log_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold">Time In</label>
                    <input type="time" name="time_in" class="form-control">
                </div>
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold">Time Out</label>
                    <input type="time" name="time_out" class="form-control">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save</button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'absence'): ?>
<!-- ─── Absence Requests ─── -->
<div class="card shadow">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Absence Requests (<?= $pendingAbs ?> pending)</h6></div>
    <div class="card-body">
    <?php if (empty($absences)): ?>
        <p class="text-muted mb-0">No absence requests.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-hover">
                <thead class="thead-light"><tr><th>#</th><th>Child</th><th>Parent</th><th>Date</th><th>Reason</th><th>Status</th><th>Admin Note</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($absences as $i => $ab): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= h($ab['student_name']) ?></td>
                    <td><?= h($ab['parent_name']) ?></td>
                    <td><?= date('d M Y', strtotime($ab['absence_date'])) ?></td>
                    <td style="max-width:200px"><?= h(mb_strimwidth($ab['reason'],0,100,'…')) ?></td>
                    <td><span class="badge badge-<?= pcm_badge($ab['status']) ?>"><?= h($ab['status']) ?></span></td>
                    <td><?= h($ab['admin_note'] ?? '—') ?></td>
                    <td>
                        <?php if ($ab['status'] === 'Pending'): ?>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve_absence">
                            <input type="hidden" name="absence_id" value="<?= $ab['id'] ?>">
                            <button class="btn btn-success btn-sm" onclick="return confirm('Approve?')"><i class="fas fa-check"></i></button>
                        </form>
                        <button class="btn btn-danger btn-sm" data-toggle="modal" data-target="#rejectAbs<?= $ab['id'] ?>"><i class="fas fa-times"></i></button>

                        <div class="modal fade" id="rejectAbs<?= $ab['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject_absence">
                                    <input type="hidden" name="absence_id" value="<?= $ab['id'] ?>">
                                    <div class="modal-header bg-danger text-white"><h5 class="modal-title">Reject Absence</h5><button class="close text-white" data-dismiss="modal">&times;</button></div>
                                    <div class="modal-body">
                                        <p><?= h($ab['student_name']) ?> — <?= date('d M Y', strtotime($ab['absence_date'])) ?></p>
                                        <div class="form-group"><label>Note</label><textarea name="admin_note" class="form-control" rows="2"></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Reject</button></div>
                                </form>
                            </div></div>
                        </div>
                        <?php else: ?>
                            <span class="text-muted small"><?= h($ab['decided_by'] ?? '') ?></span>
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
<?php endif; ?>

</div>
</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>
</body>
</html>
