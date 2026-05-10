<?php
// admin-enrolments.php — Review / Approve / Reject parent enrolment requests
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_once "include/notifications.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;
pcm_ensure_enrolment_campus_preference($pdo);
$currentActor = (string)($_SESSION['username'] ?? 'admin');
$campusChoices = pcm_campus_choice_labels();
$allClasses = $pdo->query("SELECT id, class_name FROM classes WHERE active=1 ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

function bbcc_tokens(string $text): array {
    $parts = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [];
    $stop = ['campus','college','high','school','hs','the','and','of'];
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || strlen($p) < 4 || in_array($p, $stop, true)) continue;
        $out[$p] = true;
    }
    return array_keys($out);
}

function bbcc_class_matches_campus(string $className, array $campusLabels): bool {
    if (empty($campusLabels)) return true;
    $hay = strtolower($className);
    $tokens = [];
    foreach ($campusLabels as $label) {
        $tokens = array_merge($tokens, bbcc_tokens((string)$label));
    }
    $tokens = array_unique($tokens);
    if (empty($tokens)) return true;
    foreach ($tokens as $t) {
        if (strpos($hay, $t) !== false) return true;
    }
    return false;
}

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve','reject','request_changes','assign_class','manual_enrol'], true)) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'manual_enrol') {
        $parentName = trim((string)($_POST['parent_name'] ?? ''));
        $parentEmail = strtolower(trim((string)($_POST['parent_email'] ?? '')));
        $parentPhone = trim((string)($_POST['parent_phone'] ?? ''));
        $parentAddress = trim((string)($_POST['parent_address'] ?? ''));
        $childName = trim((string)($_POST['child_name'] ?? ''));
        $childDob = trim((string)($_POST['child_dob'] ?? ''));
        $childGender = trim((string)($_POST['child_gender'] ?? ''));
        $plan = trim((string)($_POST['fee_plan'] ?? 'Term-wise'));
        $ref = trim((string)($_POST['payment_ref'] ?? ''));
        $campusSelection = $_POST['campus_choice'] ?? [];
        if (!is_array($campusSelection)) $campusSelection = [];
        $campusSelection = array_values(array_unique(array_filter(array_map('strval', $campusSelection))));
        $allowedCampusChoices = array_keys($campusChoices);

        if ($parentName === '' || $childName === '') {
            $flash = 'Parent name and child name are required.';
        } elseif (!filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Please provide a valid parent email.';
        } elseif ($parentPhone === '') {
            $flash = 'Parent phone is required.';
        } elseif (!in_array($plan, ['Term-wise', 'Half-yearly', 'Yearly'], true)) {
            $flash = 'Invalid fee plan selected.';
        } elseif (empty($campusSelection) || array_diff($campusSelection, $allowedCampusChoices)) {
            $flash = 'Please select at least one valid campus.';
        } else {
            try {
                $pdo->beginTransaction();
                $parentId = 0;

                $parentFind = $pdo->prepare("SELECT id FROM parents WHERE LOWER(email)=:e LIMIT 1");
                $parentFind->execute([':e' => $parentEmail]);
                $parentRow = $parentFind->fetch(PDO::FETCH_ASSOC);
                if ($parentRow) {
                    $parentId = (int)$parentRow['id'];
                    $pdo->prepare("
                        UPDATE parents
                        SET full_name = :n, phone = :ph, address = :ad, username = COALESCE(NULLIF(username,''), :un)
                        WHERE id = :id
                    ")->execute([
                        ':n' => $parentName,
                        ':ph' => $parentPhone,
                        ':ad' => ($parentAddress !== '' ? $parentAddress : null),
                        ':un' => $parentEmail,
                        ':id' => $parentId
                    ]);
                } else {
                    $insParent = $pdo->prepare("
                        INSERT INTO parents (full_name, email, phone, address, username, status)
                        VALUES (:n, :e, :ph, :ad, :un, 'Active')
                    ");
                    $insParent->execute([
                        ':n' => $parentName,
                        ':e' => $parentEmail,
                        ':ph' => $parentPhone,
                        ':ad' => ($parentAddress !== '' ? $parentAddress : null),
                        ':un' => $parentEmail
                    ]);
                    $parentId = (int)$pdo->lastInsertId();
                }

                if ($parentId <= 0) {
                    throw new Exception('Failed to create/find parent.');
                }

                $studentCode = pcm_next_student_id($pdo);
                $studentParentCol = pcm_students_parent_column($pdo);
                $insStudent = $pdo->prepare("
                    INSERT INTO students (student_id, student_name, dob, gender, approval_status, parentId, parent_id, status)
                    VALUES (:scode, :sname, :dob, :gender, 'Pending', :pid1, :pid2, 'Active')
                ");
                $insStudent->execute([
                    ':scode' => $studentCode,
                    ':sname' => $childName,
                    ':dob' => ($childDob !== '' ? $childDob : null),
                    ':gender' => ($childGender !== '' ? $childGender : null),
                    ':pid1' => $parentId,
                    ':pid2' => $parentId
                ]);
                $studentDbId = (int)$pdo->lastInsertId();

                $campusStored = implode(',', $campusSelection);
                $feeAmount = pcm_plan_amount($plan);
                $insEnrol = $pdo->prepare("
                    INSERT INTO pcm_enrolments
                    (student_id, parent_id, fee_plan, campus_preference, fee_amount, payment_ref, proof_path, status, admin_note, reviewed_by, reviewed_at, submitted_at)
                    VALUES
                    (:sid, :pid, :plan, :campus, :amt, :ref, NULL, 'Pending', NULL, NULL, NULL, NOW())
                ");
                $insEnrol->execute([
                    ':sid' => $studentDbId,
                    ':pid' => $parentId,
                    ':plan' => $plan,
                    ':campus' => $campusStored,
                    ':amt' => $feeAmount,
                    ':ref' => ($ref !== '' ? $ref : null)
                ]);
                $enrolmentId = (int)$pdo->lastInsertId();

                pcm_log_enrolment_event($pdo, $studentDbId, $enrolmentId, 'manual_enrolment_created', $currentActor, 'Created manually by admin.');
                $pdo->commit();

                bbcc_notify_username(
                    $pdo,
                    $parentEmail,
                    'Enrollment Draft Created for ' . $childName,
                    'An enrollment record was created by admin. You can log in to Parent Portal to track status and upload payment proof if needed.',
                    'children-enrollment'
                );

                $flash = 'Manual enrollment created for <strong>' . h($childName) . '</strong> (' . h($studentCode) . ').';
                $ok = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'assign_class') {
        $eid = (int)($_POST['enrolment_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($eid <= 0 || $classId <= 0) {
            $flash = 'Please select a valid class.';
        } else {
                $row = $pdo->prepare("
                SELECT e.id, e.student_id, e.status, s.student_name, p.email AS parent_email
                FROM pcm_enrolments e
                JOIN students s ON s.id = e.student_id
                LEFT JOIN parents p ON p.id = e.parent_id
                WHERE e.id = :id LIMIT 1
            ");
            $row->execute([':id' => $eid]);
            $en = $row->fetch();
            if (!$en) {
                $flash = 'Enrolment not found.';
            } elseif (!in_array((string)($en['status'] ?? ''), ['Approved', 'Pending'], true)) {
                $flash = 'Class can be assigned only for approved or pending enrollments.';
            } else {
                $exist = $pdo->prepare("SELECT id FROM class_assignments WHERE student_id = :sid LIMIT 1");
                $exist->execute([':sid' => (int)$en['student_id']]);
                if ($exist->fetch()) {
                    $pdo->prepare("UPDATE class_assignments SET class_id=:cid, assigned_by=:by, assigned_at=NOW() WHERE student_id=:sid")
                        ->execute([':cid' => $classId, ':by' => $_SESSION['userid'] ?? null, ':sid' => (int)$en['student_id']]);
                } else {
                    $pdo->prepare("INSERT INTO class_assignments (class_id, student_id, assigned_by) VALUES (:cid, :sid, :by)")
                        ->execute([':cid' => $classId, ':sid' => (int)$en['student_id'], ':by' => $_SESSION['userid'] ?? null]);
                }
                $classNameStmt = $pdo->prepare("SELECT class_name FROM classes WHERE id=:id LIMIT 1");
                $classNameStmt->execute([':id' => $classId]);
                $className = (string)($classNameStmt->fetchColumn() ?: '');
                pcm_log_enrolment_event($pdo, (int)$en['student_id'], $eid, 'class_assigned', $currentActor, 'Class assigned: ' . $className);
                if (!empty($en['parent_email'])) {
                    bbcc_notify_username(
                        $pdo,
                        (string)$en['parent_email'],
                        'Class Assigned for ' . (string)$en['student_name'],
                        'A class has been assigned for your child. Please check your enrollment details.',
                        'children-enrollment'
                    );
                }
                $flash = 'Class assigned for <strong>' . h((string)$en['student_name']) . '</strong>.';
                $ok = true;
            }
        }
    } else {
        $eid    = (int)($_POST['enrolment_id'] ?? 0);
        $note   = trim($_POST['admin_note'] ?? '');

        $row = $pdo->prepare("
            SELECT e.*, s.student_name, p.full_name AS parent_name, p.email AS parent_email
            FROM pcm_enrolments e
            JOIN students s ON s.id = e.student_id
            JOIN parents  p ON p.id = e.parent_id
            WHERE e.id = :id LIMIT 1
        ");
        $row->execute([':id'=>$eid]);
        $en = $row->fetch();

        if (!$en) {
            $flash = 'Enrolment not found.';
        } elseif (!in_array((string)$en['status'], ['Pending','Needs Update'], true) && $action === 'request_changes') {
            $flash = 'Request changes can be sent only for pending submissions.';
        } elseif ($en['status'] !== 'Pending' && in_array($action, ['approve','reject'], true)) {
            $flash = 'Already processed.';
        } else {
            $newStatus  = ($action === 'approve') ? 'Approved' : 'Rejected';
            $reviewer   = $_SESSION['username'] ?? 'admin';
            try {
                if ($action === 'request_changes') {
                    if ($note === '') {
                        throw new Exception('Please provide a note for requested changes.');
                    }
                    $updNeed = $pdo->prepare("
                        UPDATE pcm_enrolments
                        SET status='Needs Update', admin_note=:n, reviewed_by=:rb, reviewed_at=NOW()
                        WHERE id=:id
                    ");
                    $updNeed->execute([':n' => $note, ':rb' => $reviewer, ':id' => $eid]);
                    pcm_notify_parent_enrolment_changes_requested(
                        (string)$en['parent_email'],
                        (string)$en['parent_name'],
                        (string)$en['student_name'],
                        $note
                    );
                    bbcc_notify_username(
                        $pdo,
                        (string)$en['parent_email'],
                        'Enrollment Update Needed for ' . (string)$en['student_name'],
                        'Admin requested updates on your enrollment submission. Please review the note and resubmit.',
                        'children-enrollment'
                    );
                    pcm_log_enrolment_event($pdo, (int)$en['student_id'], (int)$en['id'], 'changes_requested', $currentActor, $note);
                    $flash = "Changes requested for <strong>{$en['student_name']}</strong>.";
                    $ok = true;
                } else {
                    $result = pcm_process_enrolment_decision($pdo, (int)$en['student_id'], $action, $reviewer, $note);

                    if ($result['new_status'] === 'Approved') {
                        pcm_notify_parent_enrolment_confirmed(
                            (string)$result['parent_email'],
                            (string)$result['parent_name'],
                            (string)$result['student_name']
                        );
                        bbcc_notify_username(
                            $pdo,
                            (string)$result['parent_email'],
                            'Enrollment Approved for ' . (string)$result['student_name'],
                            'Your child enrollment has been approved. Thank you for completing the enrollment process.',
                            'children-enrollment'
                        );
                        pcm_log_enrolment_event($pdo, (int)$en['student_id'], (int)$en['id'], 'enrolment_approved', $currentActor, $note);
                    } else {
                        pcm_notify_parent_enrolment(
                            $result['parent_email'],
                            $result['parent_name'],
                            $result['student_name'],
                            $result['new_status'],
                            $note
                        );
                        bbcc_notify_username(
                            $pdo,
                            (string)$result['parent_email'],
                            'Enrollment Rejected for ' . (string)$result['student_name'],
                            'Your enrollment submission was not approved. Please review admin notes and submit again.',
                            'children-enrollment'
                        );
                        pcm_log_enrolment_event($pdo, (int)$en['student_id'], (int)$en['id'], 'enrolment_rejected', $currentActor, $note);
                    }

                    $flash = "Enrolment <strong>{$result['new_status']}</strong> for {$result['student_name']}.";
                    $ok = true;
                }
            } catch (Exception $ex) {
                $flash = 'Error: ' . $ex->getMessage();
            }
        }
    }
}

// ── Fetch all enrolments ──
$all = $pdo->query("
    SELECT e.*, s.student_id AS stu_code, s.student_name, s.dob, s.class_option,
           p.full_name AS parent_name, p.email AS parent_email, p.phone AS parent_phone,
           ca.class_id AS assigned_class_id, c.class_name AS assigned_class_name
    FROM pcm_enrolments e
    JOIN students s ON s.id = e.student_id
    JOIN parents  p ON p.id = e.parent_id
    LEFT JOIN class_assignments ca ON ca.student_id = e.student_id
    LEFT JOIN classes c ON c.id = ca.class_id
    ORDER BY FIELD(e.status,'Pending','Approved','Rejected'), e.submitted_at DESC
")->fetchAll();

$auditRows = [];
$allEnrolmentIds = array_map(static fn($r) => (int)($r['id'] ?? 0), $all);
$allEnrolmentIds = array_values(array_filter($allEnrolmentIds));
if (!empty($allEnrolmentIds)) {
    pcm_ensure_enrolment_audit_table($pdo);
    $in = implode(',', array_map('intval', $allEnrolmentIds));
    $auditRows = $pdo->query("
        SELECT *
        FROM pcm_enrolment_audit
        WHERE enrolment_id IN ({$in})
        ORDER BY created_at DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
$auditByEnrolment = [];
foreach ($auditRows as $ar) {
    $k = (int)($ar['enrolment_id'] ?? 0);
    if ($k <= 0) continue;
    $auditByEnrolment[$k][] = $ar;
}

// Counts
$total   = count($all);
$pending = count(array_filter($all, fn($r)=>$r['status']==='Pending'));
$needsUpdate = count(array_filter($all, fn($r)=>$r['status']==='Needs Update'));
$approved= count(array_filter($all, fn($r)=>$r['status']==='Approved'));
$rejected= count(array_filter($all, fn($r)=>$r['status']==='Rejected'));

$pageScripts = [
    "https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js",
    "https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Enrolment Management</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand:#881b12; --brand-light:#a82218; --brand-bg:#fef3f2; }
        .stat-card { border-radius:14px; overflow:hidden; border:none; transition:transform .15s; }
        .stat-card:hover { transform:translateY(-3px); }
        .stat-card.status-clickable { cursor:pointer; }
        .stat-icon { width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem; }
        .stat-number { font-size:1.8rem;font-weight:800;line-height:1; }
        .stat-label  { font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#6e7687; }
        .filter-pill { border-radius:20px !important; font-weight:600; font-size:.8rem; padding:6px 18px; border:2px solid transparent; margin-right:6px; }
        .filter-pill.active-all { background:var(--brand);color:#fff;border-color:var(--brand); }
        .filter-pill.active-pending { background:#f6c23e;color:#000;border-color:#f6c23e; }
        .filter-pill.active-needs-update { background:#36b9cc;color:#fff;border-color:#36b9cc; }
        .filter-pill.active-approved { background:#1cc88a;color:#fff;border-color:#1cc88a; }
        .filter-pill.active-rejected { background:#e74a3b;color:#fff;border-color:#e74a3b; }
        .search-row { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:16px 20px; }
        .search-row label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#5a5c69; margin-bottom:4px; }
        .search-row .form-control { border-radius:8px; height:40px; font-size:.88rem; }
        #enrolTable thead th { font-size:.75rem; text-transform:uppercase; letter-spacing:.5px; background:#f8f9fc; border-bottom:2px solid #e3e6f0; white-space:nowrap; }
        #enrolTable td { vertical-align:middle; font-size:.88rem; }
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
    Swal.fire({icon:'<?= $ok?"success":"error" ?>',html:<?= json_encode($flash) ?>,timer:2500,showConfirmButton:false})
    <?= $ok ? ".then(()=>window.location='admin-enrolments.php')" : "" ?>;
});
</script>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">Enrollment Management</h1>
        <p class="text-muted mb-0" style="font-size:.88rem;">Review, approve, reject, or request updates on parent enrollment submissions.</p>
    </div>
    <div class="d-flex align-items-center">
        <button type="button" class="btn btn-sm btn-primary mr-2" style="border-radius:8px;" data-toggle="modal" data-target="#manualEnrolModal">
            <i class="fas fa-plus-circle mr-1"></i> Manual Enrollment
        </button>
        <a href="dzoClassManagement" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
            <i class="fas fa-user-plus mr-1"></i> Child Registration
        </a>
    </div>
</div>

<div class="modal fade" id="manualEnrolModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="manualEnrolForm" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="manual_enrol">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Manual Enrollment (Admin)</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Parent Full Name</label>
                            <input type="text" class="form-control" name="parent_name" required maxlength="150">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Parent Email</label>
                            <input type="email" class="form-control" name="parent_email" required maxlength="150" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="parent@example.com">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Parent Phone</label>
                            <input type="text" class="form-control" name="parent_phone" required maxlength="50">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Parent Address</label>
                            <input type="text" class="form-control" name="parent_address" maxlength="255">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Child Name</label>
                            <input type="text" class="form-control" name="child_name" required maxlength="150">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Child DOB</label>
                            <input type="date" class="form-control" name="child_dob" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Child Gender</label>
                            <select class="form-control" name="child_gender">
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Fee Plan</label>
                            <select class="form-control" name="fee_plan" required>
                                <option value="Term-wise">Term-wise</option>
                                <option value="Half-yearly">Half-yearly</option>
                                <option value="Yearly">Yearly</option>
                            </select>
                        </div>
                        <div class="col-md-8 form-group">
                            <label>Payment Reference (optional)</label>
                            <input type="text" class="form-control" name="payment_ref" maxlength="150" placeholder="e.g. ChildName_TERM1">
                        </div>
                        <div class="col-md-12 form-group mb-1">
                            <label class="d-block">Campus Selection</label>
                            <div class="custom-control custom-checkbox custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="manualCampusC1" name="campus_choice[]" value="c1">
                                <label class="custom-control-label" for="manualCampusC1"><?= h($campusChoices['c1'] ?? 'Campus 1') ?></label>
                            </div>
                            <div class="custom-control custom-checkbox custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="manualCampusC2" name="campus_choice[]" value="c2">
                                <label class="custom-control-label" for="manualCampusC2"><?= h($campusChoices['c2'] ?? 'Campus 2') ?></label>
                            </div>
                            <small class="form-text text-muted">Select one or both campuses.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Enrollment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="all">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(136,27,18,.1);color:var(--brand);"><i class="fas fa-users"></i></div>
                <div><div class="stat-number text-gray-800"><?= $total ?></div><div class="stat-label">Total</div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Pending">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(246,194,62,.15);color:#f6c23e;"><i class="fas fa-clock"></i></div>
                <div><div class="stat-number text-gray-800"><?= $pending ?></div><div class="stat-label">Pending Review</div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Needs Update">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(54,185,204,.15);color:#36b9cc;"><i class="fas fa-edit"></i></div>
                <div><div class="stat-number text-gray-800"><?= $needsUpdate ?></div><div class="stat-label">Needs Update</div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Approved">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(28,200,138,.12);color:#1cc88a;"><i class="fas fa-check-circle"></i></div>
                <div><div class="stat-number text-gray-800"><?= $approved ?></div><div class="stat-label">Approved</div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Rejected">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(231,74,59,.1);color:#e74a3b;"><i class="fas fa-times-circle"></i></div>
                <div><div class="stat-number text-gray-800"><?= $rejected ?></div><div class="stat-label">Rejected</div></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter + Search -->
<div class="search-row mb-4">
    <div class="d-flex flex-wrap align-items-center mb-3">
        <button class="btn filter-pill active-all" data-filter="all">All</button>
        <button class="btn filter-pill btn-outline-warning" data-filter="Pending"><i class="fas fa-clock mr-1"></i> Pending</button>
        <button class="btn filter-pill btn-outline-info" data-filter="Needs Update"><i class="fas fa-edit mr-1"></i> Needs Update</button>
        <button class="btn filter-pill btn-outline-success" data-filter="Approved"><i class="fas fa-check mr-1"></i> Approved</button>
        <button class="btn filter-pill btn-outline-danger" data-filter="Rejected"><i class="fas fa-times mr-1"></i> Rejected</button>
    </div>
    <div class="row">
        <div class="col-md-3">
            <label>Search Column</label>
            <select class="form-control" id="colSelect">
                <option value="-1">All Columns</option>
                <option value="1">Student ID</option>
                <option value="2">Child Name</option>
                <option value="3">Campus</option>
                <option value="4">Class</option>
                <option value="5">Phone</option>
                <option value="6">Plan</option>
                <option value="10">Parent</option>
                <option value="11">Status</option>
            </select>
        </div>
        <div class="col-md-6">
            <label>Quick Search</label>
            <input type="text" class="form-control" id="searchBox" placeholder="Type to search instantly...">
        </div>
        <div class="col-md-3">
            <label>&nbsp;</label>
            <button class="btn btn-outline-secondary btn-block" id="resetBtn" style="border-radius:8px;height:40px;">
                <i class="fas fa-undo mr-1"></i> Reset
            </button>
        </div>
    </div>
</div>

<!-- Enrolments Table -->
<div class="card shadow mb-4">
    <div class="card-body">
        <div class="table-responsive">
            <table id="enrolTable" class="table table-bordered table-hover" style="width:100%">
                <thead class="thead-light">
                    <tr>
                        <th>#</th><th>Student ID</th><th>Child Name</th><th>Campus</th><th>Class</th><th>Phone</th>
                        <th>Plan</th><th>Amount</th><th>Ref</th><th>Proof</th><th>Parent</th><th>Status</th><th>Submitted</th><th style="width:300px">Actions</th><th>History</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all as $i => $e): ?>
                <tr data-status="<?= $e['status'] ?>">
                    <td><?= $i+1 ?></td>
                    <td><code><?= h($e['stu_code']) ?></code></td>
                    <td><?= h($e['student_name']) ?></td>
                    <td><?= h(pcm_campus_selection_label((string)($e['campus_preference'] ?? ''))) ?></td>
                    <td><?= h($e['assigned_class_name'] ?? 'Not assigned') ?></td>
                    <td><?= h($e['parent_phone'] ?? '-') ?></td>
                    <td><?= h($e['fee_plan']) ?></td>
                    <td>$<?= number_format($e['fee_amount'],2) ?></td>
                    <td><?= h($e['payment_ref'] ?? '—') ?></td>
                    <td>
                        <?php if (!empty($e['proof_path'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-info js-proof-btn" data-proof="<?= h($e['proof_path']) ?>" data-child="<?= h($e['student_name']) ?>">
                                View
                            </button>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= h($e['parent_name']) ?><br><small class="text-muted"><?= h($e['parent_email']) ?></small></td>
                    <td><span class="badge badge-<?= pcm_badge($e['status']) ?>"><?= h($e['status']) ?></span>
                        <?php if ($e['admin_note']): ?><br><small><?= h($e['admin_note']) ?></small><?php endif; ?>
                    </td>
                    <td><?= date('d M Y', strtotime($e['submitted_at'])) ?></td>
                    <td>
                        <?php if ($e['status'] === 'Pending'): ?>
                        <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#approveModal<?= $e['id'] ?>"><i class="fas fa-check mr-1"></i>Approve</button>
                        <button class="btn btn-danger btn-sm"  data-toggle="modal" data-target="#rejectModal<?= $e['id'] ?>"><i class="fas fa-times mr-1"></i>Reject</button>
                        <button class="btn btn-warning btn-sm" data-toggle="modal" data-target="#changesModal<?= $e['id'] ?>"><i class="fas fa-edit mr-1"></i>Request Changes</button>
                        <?php
                            $selectedCampusKeys = pcm_normalize_campus_selection((string)($e['campus_preference'] ?? ''));
                            $selectedCampusLabels = [];
                            foreach ($selectedCampusKeys as $ck) {
                                if (isset($campusChoices[$ck])) $selectedCampusLabels[] = $campusChoices[$ck];
                            }
                            $matchingClasses = array_values(array_filter($allClasses, function($cl) use ($selectedCampusLabels) {
                                return bbcc_class_matches_campus((string)($cl['class_name'] ?? ''), $selectedCampusLabels);
                            }));
                            if (empty($matchingClasses)) {
                                $matchingClasses = $allClasses;
                            }
                        ?>
                        <form method="POST" class="form-inline mt-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="assign_class">
                            <input type="hidden" name="enrolment_id" value="<?= (int)$e['id'] ?>">
                            <select name="class_id" class="form-control form-control-sm mr-1" style="min-width:155px;" required>
                                <option value="">Assign class</option>
                                <?php foreach ($matchingClasses as $cl): ?>
                                    <option value="<?= (int)$cl['id'] ?>" <?= ((int)$e['assigned_class_id'] === (int)$cl['id']) ? 'selected' : '' ?>>
                                        <?= h($cl['class_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-sync-alt mr-1"></i>Update Class
                            </button>
                        </form>

                        <!-- Approve Modal -->
                        <div class="modal fade" id="approveModal<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST" class="js-enrol-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="enrolment_id" value="<?= $e['id'] ?>">
                                    <div class="modal-header bg-success text-white"><h5 class="modal-title">Approve Enrolment</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
                                    <div class="modal-body">
                                        <p>Approve enrolment for <strong><?= h($e['student_name']) ?></strong>?</p>
                                        <p class="small text-muted">This will also create fee instalment records and approve the first payment if proof is attached.</p>
                                        <div class="form-group"><label>Note (optional)</label><textarea name="admin_note" class="form-control" rows="2"></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success js-submit-action-btn">Approve</button></div>
                                </form>
                            </div></div>
                        </div>

                        <!-- Reject Modal -->
                        <div class="modal fade" id="rejectModal<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST" class="js-enrol-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="enrolment_id" value="<?= $e['id'] ?>">
                                    <div class="modal-header bg-danger text-white"><h5 class="modal-title">Reject Enrolment</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
                                    <div class="modal-body">
                                        <p>Reject enrolment for <strong><?= h($e['student_name']) ?></strong>?</p>
                                        <div class="form-group"><label>Reason / Note</label><textarea name="admin_note" class="form-control" rows="2" required></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger js-submit-action-btn">Reject</button></div>
                                </form>
                            </div></div>
                        </div>

                        <!-- Request Changes Modal -->
                        <div class="modal fade" id="changesModal<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST" class="js-enrol-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_changes">
                                    <input type="hidden" name="enrolment_id" value="<?= $e['id'] ?>">
                                    <div class="modal-header bg-warning text-dark"><h5 class="modal-title">Request Changes</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                                    <div class="modal-body">
                                        <p>Ask parent to update submission for <strong><?= h($e['student_name']) ?></strong>.</p>
                                        <div class="form-group"><label>Required update note</label><textarea name="admin_note" class="form-control" rows="2" required placeholder="e.g. Please upload clearer payment proof"></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning js-submit-action-btn">Send Request</button></div>
                                </form>
                            </div></div>
                        </div>
                        <?php else: ?>
                            <?php if ($e['status'] === 'Approved'): ?>
                                <?php
                                    $selectedCampusKeys = pcm_normalize_campus_selection((string)($e['campus_preference'] ?? ''));
                                    $selectedCampusLabels = [];
                                    foreach ($selectedCampusKeys as $ck) {
                                        if (isset($campusChoices[$ck])) $selectedCampusLabels[] = $campusChoices[$ck];
                                    }
                                    $matchingClasses = array_values(array_filter($allClasses, function($cl) use ($selectedCampusLabels) {
                                        return bbcc_class_matches_campus((string)($cl['class_name'] ?? ''), $selectedCampusLabels);
                                    }));
                                    if (empty($matchingClasses)) {
                                        $matchingClasses = $allClasses;
                                    }
                                ?>
                                <form method="POST" class="form-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign_class">
                                    <input type="hidden" name="enrolment_id" value="<?= (int)$e['id'] ?>">
                                    <select name="class_id" class="form-control form-control-sm mr-1" style="min-width:155px;" required>
                                        <option value="">Assign class</option>
                                        <?php foreach ($matchingClasses as $cl): ?>
                                            <option value="<?= (int)$cl['id'] ?>" <?= ((int)$e['assigned_class_id'] === (int)$cl['id']) ? 'selected' : '' ?>>
                                                <?= h($cl['class_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-sync-alt mr-1"></i>Update Class
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted small"><?= h($e['reviewed_by'] ?? '') ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                            $historyItems = $auditByEnrolment[(int)$e['id']] ?? [];
                            $historyCount = count($historyItems);
                        ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="modal" data-target="#historyModal<?= (int)$e['id'] ?>">
                            View (<?= $historyCount ?>)
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php foreach ($all as $e): ?>
<?php $historyItems = $auditByEnrolment[(int)$e['id']] ?? []; ?>
<div class="modal fade" id="historyModal<?= (int)$e['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Audit History — <?= h($e['student_name']) ?></h5>
            <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
            <?php if (count($historyItems) === 0): ?>
                <p class="text-muted mb-0">No audit history yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="thead-light">
                            <tr><th style="width:170px">Time</th><th style="width:170px">Event</th><th style="width:160px">Actor</th><th>Details</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($historyItems as $h): ?>
                            <tr>
                                <td><?= date('d M Y H:i', strtotime((string)$h['created_at'])) ?></td>
                                <td><?= h((string)$h['event_type']) ?></td>
                                <td><?= h((string)($h['actor'] ?? '')) ?></td>
                                <td><?= h((string)($h['details'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div></div>
</div>
<?php endforeach; ?>

</div>
</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>

<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment Proof</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body text-center">
                <img id="proofImage" src="" alt="Payment Proof" style="max-width:100%;height:auto;display:none;">
                <iframe id="proofFrame" src="" style="width:100%;height:65vh;border:0;display:none;"></iframe>
                <div id="proofFallback" style="display:none;">
                    <p class="mb-2">Preview not available for this file type.</p>
                    <a id="proofOpenLink" href="#" target="_blank" class="btn btn-primary btn-sm">Open File</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(function(){
    var dt = $('#enrolTable').DataTable({pageLength:25, order:[[12,'desc']]});
    var activeStatus = 'all';
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
        if (settings.nTable.id !== 'enrolTable') return true;
        if (activeStatus === 'all') return true;
        var rowNode = settings.aoData[dataIndex] && settings.aoData[dataIndex].nTr ? settings.aoData[dataIndex].nTr : null;
        if (!rowNode) return true;
        var rowStatus = String($(rowNode).attr('data-status') || '').trim();
        return rowStatus === activeStatus;
    });

    function statusClassKey(status) {
        var s = String(status || '').toLowerCase();
        if (s === 'needs update') return 'needs-update';
        return s || 'all';
    }

    function setPillActive(status) {
        $('.filter-pill').removeClass('active-all active-pending active-needs-update active-approved active-rejected');
        $('.filter-pill').each(function(){
            if ($(this).data('filter') === status) {
                $(this).addClass('active-' + statusClassKey(status));
            }
        });
    }

    function applyStatusFilter(status) {
        activeStatus = status;
        dt.draw();
        setPillActive(status);
    }

    $('.filter-pill').on('click', function(){
        applyStatusFilter($(this).data('filter'));
    });

    $('.js-status-card').on('click', function(){
        applyStatusFilter($(this).data('status'));
    });

    $('#searchBox').on('keyup', function(){
        var term = this.value;
        var col = parseInt($('#colSelect').val(), 10);
        if (col === -1) {
            dt.search(term).draw();
        } else {
            dt.search('');
            dt.columns().search('');
            dt.column(col).search(term).draw();
        }
    });

    $('#colSelect').on('change', function(){
        $('#searchBox').trigger('keyup');
    });

    $('#resetBtn').on('click', function(){
        $('#searchBox').val('');
        $('#colSelect').val('-1');
        dt.search('');
        dt.columns().search('');
        applyStatusFilter('all');
    });

    applyStatusFilter('all');

    // Close approve/reject modal immediately on submit so UI does not appear stuck
    $(document).on('submit', 'form.js-enrol-action-form', function(){
        var $form = $(this);
        var $btn = $form.find('.js-submit-action-btn');
        $btn.prop('disabled', true).text('Processing...');
        $form.closest('.modal').modal('hide');
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open').css('padding-right', '');
    });

    // Proof popup modal
    $(document).on('click', '.js-proof-btn', function(){
        var path = $(this).data('proof') || '';
        var child = $(this).data('child') || 'Student';
        var lower = String(path).toLowerCase();
        var isImg = /\.(jpg|jpeg|png|gif|webp)$/i.test(lower);
        var isPdf = /\.pdf$/i.test(lower);

        $('#proofModal .modal-title').text('Payment Proof - ' + child);
        $('#proofImage, #proofFrame, #proofFallback').hide();
        $('#proofImage').attr('src', '');
        $('#proofFrame').attr('src', '');
        $('#proofOpenLink').attr('href', path);

        if (isImg) {
            $('#proofImage').attr('src', path).show();
        } else if (isPdf) {
            $('#proofFrame').attr('src', path).show();
        } else {
            $('#proofFallback').show();
        }
        $('#proofModal').modal('show');
    });

    $('#manualEnrolModal').on('shown.bs.modal', function(){
        var form = document.getElementById('manualEnrolForm');
        if (!form) return;
        form.reset();
        var emailInput = form.querySelector('input[name="parent_email"]');
        if (emailInput) emailInput.value = '';
    });
});
</script>
</body>
</html>
