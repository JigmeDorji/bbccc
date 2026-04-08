<?php
// parent-attendance.php — View class attendance, kiosk log, submit absence requests
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

// ── My children ──
$kids = $pdo->prepare("SELECT id, student_id, student_name FROM students WHERE parentId=:pid ORDER BY student_name");
$kids->execute([':pid'=>$parentId]);
$kids = $kids->fetchAll();
$kidIds = array_column($kids, 'id');

// ── Class attendance (from existing 'attendance' table) ──
$classAtt = [];
if ($kidIds) {
    $in = implode(',', array_map('intval', $kidIds));
    $classAtt = $pdo->query("
        SELECT a.*, s.student_name, c.class_name,
               CASE
                   WHEN LOWER(COALESCE(a.status,'')) = 'absent' AND ar.child_id IS NOT NULL THEN 'Parent'
                   ELSE 'Teacher'
               END AS marked_by
        FROM attendance a
        JOIN students s ON s.id = a.student_id
        LEFT JOIN classes c ON c.id = a.class_id
        LEFT JOIN (
            SELECT DISTINCT child_id, absence_date
            FROM pcm_absence_requests
            WHERE status <> 'Rejected'
        ) ar ON ar.child_id = a.student_id AND ar.absence_date = a.attendance_date
        WHERE a.student_id IN ({$in})
        ORDER BY a.attendance_date DESC, a.marked_at DESC, s.student_name
        LIMIT 100
    ")->fetchAll();
}

// ── Kiosk log ──
$kioskLog = [];
if ($kidIds) {
    $in = implode(',', array_map('intval', $kidIds));
    $kioskLog = $pdo->query("
        SELECT k.*, s.student_name
        FROM pcm_kiosk_log k
        JOIN students s ON s.id = k.child_id
        WHERE k.child_id IN ({$in})
        ORDER BY k.log_date DESC, k.time_in DESC
        LIMIT 100
    ")->fetchAll();
}

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

$tab = $_GET['tab'] ?? 'class';
$pageScripts = [];
$dateHeadersMap = [];
$gridRows = [];
foreach ($classAtt as $r) {
    $dateKey = (string)($r['attendance_date'] ?? '');
    $markedAt = (string)($r['marked_at'] ?? '');
    if ($dateKey === '') continue;

    $batchId = (string)($r['batch_id'] ?? '');
    $sessionKey = $batchId !== '' ? ('batch:' . $batchId) : ($dateKey . '|' . $markedAt);
    $dateHeadersMap[$sessionKey] = [
        'key'  => $sessionKey,
        'date' => $dateKey,
        'time' => $markedAt,
    ];

    $rowKey = (string)($r['student_id'] ?? '') . '|' . (string)($r['class_name'] ?? '');
    if (!isset($gridRows[$rowKey])) {
        $gridRows[$rowKey] = [
            'student_name' => (string)($r['student_name'] ?? ''),
            'student_id'   => (string)($r['student_id'] ?? ''),
            'class_name'   => (string)($r['class_name'] ?? ''),
            'cells'        => [],
        ];
    }

    $gridRows[$rowKey]['cells'][$sessionKey] = [
        'status'    => (string)($r['status'] ?? ''),
        'notes'     => (string)(($r['notes'] ?? '') !== '' ? $r['notes'] : ($r['remarks'] ?? '')),
        'marked_by' => (string)($r['marked_by'] ?? 'Teacher'),
    ];
}

$dateHeaders = array_values($dateHeadersMap);
usort($dateHeaders, function ($a, $b) {
    $ta = strtotime((string)($a['time'] ?? '') ?: (string)($a['date'] ?? ''));
    $tb = strtotime((string)($b['time'] ?? '') ?: (string)($b['date'] ?? ''));
    if ($ta === $tb) return 0;
    return ($ta > $tb) ? -1 : 1;
});
if (count($dateHeaders) > 31) {
    $dateHeaders = array_slice($dateHeaders, 0, 31);
}
uasort($gridRows, function ($a, $b) {
    return strcasecmp((string)$a['student_name'], (string)$b['student_name']);
});

$classSessionCount = count($dateHeaders);
$presentCount = 0;
$absentCount = 0;
$lateCount = 0;
foreach ($classAtt as $row) {
    $st = strtolower((string)($row['status'] ?? ''));
    if ($st === 'present') $presentCount++;
    elseif ($st === 'absent') $absentCount++;
    elseif ($st === 'late') $lateCount++;
}
$absenceRecords = array_values(array_filter($classAtt, function ($r) {
    return strtolower((string)($r['status'] ?? '')) === 'absent';
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Attendance</title>
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
    <?= $ok ? ".then(()=>window.location='parent-attendance.php?tab=absence')" : "" ?>;
});
</script>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><a class="nav-link <?= $tab==='class'?'active':'' ?>" href="?tab=class"><i class="fas fa-chalkboard-teacher mr-1"></i>Class Attendance</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='kiosk'?'active':'' ?>" href="?tab=kiosk"><i class="fas fa-door-open mr-1"></i>Kiosk Sign In/Out</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='absence'?'active':'' ?>" href="?tab=absence"><i class="fas fa-calendar-times mr-1"></i>Absence Requests</a></li>
</ul>

<?php if ($tab === 'class'): ?>
<!-- ─── Class Attendance ─── -->
<div class="row mb-3">
    <div class="col-md-3 col-6 mb-2">
        <div class="att-summary-card">
            <div class="att-summary-label">Children</div>
            <div class="att-summary-value"><?= (int)count($kids) ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="att-summary-card">
            <div class="att-summary-label">Sessions</div>
            <div class="att-summary-value"><?= (int)$classSessionCount ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="att-summary-card">
            <div class="att-summary-label">Present</div>
            <div class="att-summary-value"><?= (int)$presentCount ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="att-summary-card">
            <div class="att-summary-label">Absent / Late</div>
            <div class="att-summary-value"><?= (int)($absentCount + $lateCount) ?></div>
        </div>
    </div>
</div>
<div class="alert alert-light border mb-3">
    <strong>Status guide:</strong>
    <span class="badge badge-success ml-2">Present</span>
    <span class="badge badge-danger ml-1">Absent</span>
    <span class="badge badge-warning ml-1">Late</span>
</div>
<div class="card shadow">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">My Children Attendance History</h6></div>
    <div class="card-body">
    <?php if (empty($gridRows)): ?>
        <p class="text-muted mb-0">No class attendance records found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-hover">
                <thead class="thead-light">
                <tr>
                    <th style="width:60px;">#</th>
                    <th>Child Name</th>
                    <th style="width:130px;">Student ID</th>
                    <th>Class</th>
                    <?php foreach ($dateHeaders as $hdr): ?>
                        <?php
                            $d = (string)($hdr['date'] ?? '');
                            $t = (string)($hdr['time'] ?? '');
                            $timeLabel = $t ? date('h:i A', strtotime($t)) : '--:--';
                        ?>
                        <th style="min-width:140px;">
                            <div><?= h(date('d M Y', strtotime($d))) ?></div>
                            <div style="font-size:.72rem;color:#6c757d;line-height:1.2;"><?= h($timeLabel) ?></div>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php $rowNum = 1; foreach ($gridRows as $gr): ?>
                <tr>
                    <td><?= $rowNum++ ?></td>
                    <td><strong><?= h($gr['student_name']) ?></strong></td>
                    <td><?= h($gr['student_id']) ?></td>
                    <td><?= h($gr['class_name'] ?: '-') ?></td>
                    <?php foreach ($dateHeaders as $hdr): ?>
                        <?php $sessionKey = (string)($hdr['key'] ?? ''); ?>
                        <td class="text-center">
                            <?php if (!empty($gr['cells'][$sessionKey])): ?>
                                <?php
                                    $cell = $gr['cells'][$sessionKey];
                                    $sc = strtolower((string)($cell['status'] ?? ''));
                                    $bc = $sc==='present'?'success':($sc==='absent'?'danger':($sc==='late'?'warning':'secondary'));
                                    $tip = trim((string)($cell['marked_by'] ?? 'Teacher'));
                                    $notes = trim((string)($cell['notes'] ?? ''));
                                    if ($notes !== '') $tip .= ' | ' . $notes;
                                ?>
                                <span class="badge badge-<?= $bc ?>" title="<?= h($tip) ?>"><?= h($cell['status'] ?: 'Unknown') ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<div class="card shadow mt-4">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Absence Records</h6></div>
    <div class="card-body">
    <?php if (empty($absenceRecords)): ?>
        <p class="text-muted mb-0">No absence records.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-hover">
                <thead class="thead-light"><tr><th>Date</th><th>Child Name</th><th>Class</th><th>Status</th><th>Marked By</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($absenceRecords as $a): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($a['attendance_date'])) ?></td>
                    <td><?= h($a['student_name']) ?></td>
                    <td><?= h($a['class_name'] ?? '-') ?></td>
                    <td><span class="badge badge-danger">Absent</span></td>
                    <td>
                        <span class="badge badge-<?= strtolower((string)($a['marked_by'] ?? 'teacher')) === 'parent' ? 'danger' : 'primary' ?>">
                            <?= h($a['marked_by'] ?? 'Teacher') ?>
                        </span>
                    </td>
                    <td><?= h(($a['notes'] ?? '') !== '' ? $a['notes'] : ($a['remarks'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'kiosk'): ?>
<!-- ─── Kiosk Log ─── -->
<div class="card shadow">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Kiosk Sign In / Out Records</h6></div>
    <div class="card-body">
    <?php if (empty($kioskLog)): ?>
        <p class="text-muted mb-0">No kiosk records yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-hover">
                <thead class="thead-light"><tr><th>Date</th><th>Child</th><th>Sign In</th><th>Sign Out</th><th>Method</th></tr></thead>
                <tbody>
                <?php foreach ($kioskLog as $k): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($k['log_date'])) ?></td>
                    <td><?= h($k['student_name']) ?></td>
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

<?php elseif ($tab === 'absence'): ?>
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
                <button type="submit" class="btn btn-primary" id="absBtn"><i class="fas fa-paper-plane mr-1"></i>Submit Request</button>
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
<?php endif; ?>

</div>
</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>
</body>
</html>
