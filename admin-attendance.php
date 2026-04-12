<?php
// admin-attendance.php — Admin kiosk log viewer, manual entry, absence request management
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

    // Admin override: clear/re-enable parent absence mark
    if ($act === 'set_absence_status') {
        $absenceId = (int)($_POST['absence_id'] ?? 0);
        $newStatus = trim((string)($_POST['new_status'] ?? ''));
        if ($absenceId <= 0 || !in_array($newStatus, ['Pending', 'Rejected'], true)) {
            $flash = 'Invalid absence update request.';
        } else {
            $upd = $pdo->prepare("
                UPDATE pcm_absence_requests
                SET status = :st, decided_by = :db, decided_at = NOW()
                WHERE id = :id
            ");
            $upd->execute([
                ':st' => $newStatus,
                ':db' => (string)($_SESSION['username'] ?? 'admin'),
                ':id' => $absenceId
            ]);
            if ($upd->rowCount() > 0) {
                $flash = ($newStatus === 'Rejected')
                    ? 'Absence mark cleared by admin.'
                    : 'Absence mark restored by admin.';
                $ok = true;
            } else {
                $flash = 'No changes made.';
            }
        }
    }
}

}

$tab = $_GET['tab'] ?? 'kiosk';

// Shareable kiosk URLs for other iPads/devices
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$origin = $scheme . '://' . $host . ($basePath === '' || $basePath === '.' ? '' : $basePath);
$doorKioskUrl = $origin . '/kiosk';
$qrDisplayUrl = $origin . '/kiosk-qr';
$mobileKioskUrl = $origin . '/kiosk-mobile';

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
    ORDER BY ar.created_at DESC
    LIMIT 200
")->fetchAll();

$absenceCount = count($absences);

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

<!-- Shareable Kiosk Links -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-link mr-1"></i>Kiosk Links</h6>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3" style="font-size:.88rem;">
            Copy and paste these links on your iPads/devices.
        </p>
        <div class="mb-3">
            <label class="font-weight-bold mb-1">Door Kiosk (iPad at entrance)</label>
            <div class="input-group">
                <input type="text" class="form-control" id="doorKioskUrl" value="<?= h($doorKioskUrl) ?>" readonly>
                <div class="input-group-append">
                    <button type="button" class="btn btn-outline-primary js-copy-link" data-target="doorKioskUrl">Copy</button>
                </div>
            </div>
        </div>
        <div class="mb-3">
            <label class="font-weight-bold mb-1">QR Display (second iPad/screen)</label>
            <div class="input-group">
                <input type="text" class="form-control" id="qrDisplayUrl" value="<?= h($qrDisplayUrl) ?>" readonly>
                <div class="input-group-append">
                    <button type="button" class="btn btn-outline-primary js-copy-link" data-target="qrDisplayUrl">Copy</button>
                </div>
            </div>
        </div>
        <div class="mb-0">
            <label class="font-weight-bold mb-1">Mobile Page (direct link)</label>
            <div class="input-group">
                <input type="text" class="form-control" id="mobileKioskUrl" value="<?= h($mobileKioskUrl) ?>" readonly>
                <div class="input-group-append">
                    <button type="button" class="btn btn-outline-primary js-copy-link" data-target="mobileKioskUrl">Copy</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><a class="nav-link <?= $tab==='kiosk'?'active':'' ?>" href="?tab=kiosk"><i class="fas fa-door-open mr-1"></i>Kiosk Records</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='manual'?'active':'' ?>" href="?tab=manual"><i class="fas fa-keyboard mr-1"></i>Manual Entry</a></li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='absence'?'active':'' ?>" href="?tab=absence">
            <i class="fas fa-calendar-times mr-1"></i>Absence Records
            <?php if ($absenceCount): ?><span class="badge badge-secondary ml-1"><?= $absenceCount ?></span><?php endif; ?>
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
<!-- ─── Absence Records ─── -->
<div class="card shadow">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Absence Records (<?= $absenceCount ?>)</h6></div>
    <div class="card-body">
    <?php if (empty($absences)): ?>
        <p class="text-muted mb-0">No absence records.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-hover">
                <thead class="thead-light"><tr><th>#</th><th>Date</th><th>Child</th><th>Parent</th><th>Reason</th><th>Date Applied</th><th>Remark</th><th>Admin Action</th></tr></thead>
                <tbody>
                <?php foreach ($absences as $i => $ab): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= date('d M Y', strtotime($ab['absence_date'])) ?></td>
                    <td><?= h($ab['student_name']) ?></td>
                    <td><?= h($ab['parent_name']) ?></td>
                    <td style="max-width:200px"><?= h(mb_strimwidth($ab['reason'],0,100,'…')) ?></td>
                    <td><?= !empty($ab['created_at']) ? date('d M Y, h:i A', strtotime($ab['created_at'])) : '—' ?></td>
                    <td>
                        <?php if (($ab['status'] ?? '') === 'Rejected'): ?>
                            <span class="badge badge-secondary">Absence cleared by admin</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Marked absent by <?= h($ab['parent_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#editAbsence<?= (int)$ab['id'] ?>">Edit</button>

                        <div class="modal fade" id="editAbsence<?= (int)$ab['id'] ?>" tabindex="-1" role="dialog" aria-hidden="true">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_absence_status">
                                        <input type="hidden" name="absence_id" value="<?= (int)$ab['id'] ?>">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title">Edit Absence Status</h5>
                                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <p class="mb-2"><strong>Child:</strong> <?= h($ab['student_name']) ?></p>
                                            <p class="mb-3"><strong>Date:</strong> <?= date('d M Y', strtotime($ab['absence_date'])) ?></p>
                                            <div class="form-group mb-0">
                                                <label class="font-weight-bold">Status</label>
                                                <select class="form-control" name="new_status" required>
                                                    <option value="Pending" <?= (($ab['status'] ?? '') !== 'Rejected') ? 'selected' : '' ?>>Marked absent</option>
                                                    <option value="Rejected" <?= (($ab['status'] ?? '') === 'Rejected') ? 'selected' : '' ?>>Cleared by admin</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-copy-link').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const id = this.getAttribute('data-target');
            const input = document.getElementById(id);
            if (!input) return;
            const value = input.value || '';
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(value);
                } else {
                    input.select();
                    input.setSelectionRange(0, value.length);
                    document.execCommand('copy');
                }
                Swal.fire({ icon: 'success', title: 'Copied', text: 'Link copied to clipboard.', timer: 1300, showConfirmButton: false });
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Copy failed', text: 'Please copy the link manually.' });
            }
        });
    });
});
</script>
</body>
</html>
