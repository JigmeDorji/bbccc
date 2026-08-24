<?php
// dzoClassManagement.php — Child Registration Management
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/csrf.php";
require_once "include/role_helpers.php";
require_once "include/pcm_helpers.php";
require_once "include/notifications.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$pdo   = pcm_pdo();
pcm_ensure_enrolment_start_term($pdo);
$flash = '';
$ok    = false;

$studentParentExpr = "parent_id";
$studentParentJoinExpr = "s.parent_id";
$joinParentForId = (int)($_GET['join_parent_for'] ?? 0);

// ── POST: record-maintenance actions (registration/enrollment review now lives on admin-enrolments.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $studentDbId = (int)($_POST['student_id'] ?? 0);

    if ($studentDbId > 0 && in_array($action, ['delete','move_past','restore_active','admin_update_child_details','admin_reassign_parent'])) {
        try {
            $reviewer = $_SESSION['username'] ?? 'admin';

            if ($action === 'admin_reassign_parent') {
                $targetParentId = (int)($_POST['target_parent_id'] ?? 0);
                if ($targetParentId <= 0) {
                    throw new Exception("Please choose a valid parent to join.");
                }

                $stu = $pdo->prepare("SELECT id, student_name, {$studentParentExpr} AS parent_id FROM students WHERE id = :id LIMIT 1");
                $stu->execute([':id' => $studentDbId]);
                $student = $stu->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    throw new Exception("Student not found.");
                }

                $currentParentId = (int)($student['parent_id'] ?? 0);
                if ($currentParentId > 0 && $currentParentId === $targetParentId) {
                    throw new Exception("Student is already linked to the selected parent.");
                }

                $targetParentStmt = $pdo->prepare("SELECT id, full_name FROM parents WHERE id = :id LIMIT 1");
                $targetParentStmt->execute([':id' => $targetParentId]);
                $targetParent = $targetParentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$targetParent) {
                    throw new Exception("Target parent not found.");
                }

                $pdo->beginTransaction();
                $pdo->prepare("UPDATE students SET parent_id = :pid WHERE id = :sid")
                    ->execute([':pid' => $targetParentId, ':sid' => $studentDbId]);

                $pdo->prepare("UPDATE pcm_enrolments SET parent_id = :pid WHERE student_id = :sid")
                    ->execute([':pid' => $targetParentId, ':sid' => $studentDbId]);
                $pdo->prepare("UPDATE pcm_fee_payments SET parent_id = :pid WHERE student_id = :sid")
                    ->execute([':pid' => $targetParentId, ':sid' => $studentDbId]);
                $pdo->prepare("UPDATE payments SET parent_id = :pid WHERE student_id = :sid")
                    ->execute([':pid' => $targetParentId, ':sid' => $studentDbId]);
                $pdo->commit();

                pcm_log_enrolment_event(
                    $pdo,
                    $studentDbId,
                    null,
                    'admin_child_joined_to_parent',
                    (string)$reviewer,
                    'Joined to parent #' . $targetParentId . ' (' . (string)($targetParent['full_name'] ?? '') . ')'
                );
                $flash = 'Child <strong>' . h((string)$student['student_name']) . '</strong> joined to parent <strong>' . h((string)($targetParent['full_name'] ?? '')) . '</strong>.';
                $ok = true;
            } elseif ($action === 'admin_update_child_details') {
                $result = pcm_admin_update_child_details($pdo, $studentDbId, $_POST, (string)$reviewer);
                $flash = $result['flash'];
                $ok = $result['ok'];
            } elseif ($action === 'move_past') {
                $stu = $pdo->prepare("SELECT student_name FROM students WHERE id = :id LIMIT 1");
                $stu->execute([':id' => $studentDbId]);
                $student = $stu->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    throw new Exception("Student not found.");
                }

                $pdo->beginTransaction();
                $pdo->prepare("UPDATE students SET status = 'Past' WHERE id = :id")->execute([':id' => $studentDbId]);
                $pdo->prepare("DELETE FROM class_assignments WHERE student_id = :sid")->execute([':sid' => $studentDbId]);
                $pdo->commit();

                pcm_log_enrolment_event($pdo, $studentDbId, null, 'student_moved_to_past', (string)$reviewer, 'Student moved to past students and removed from active class assignment.');
                $flash = 'Student <strong>' . h((string)$student['student_name']) . '</strong> moved to past students.';
                $ok = true;

            } elseif ($action === 'restore_active') {
                $stu = $pdo->prepare("SELECT student_name FROM students WHERE id = :id LIMIT 1");
                $stu->execute([':id' => $studentDbId]);
                $student = $stu->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    throw new Exception("Student not found.");
                }

                $pdo->prepare("UPDATE students SET status = 'Active' WHERE id = :id")->execute([':id' => $studentDbId]);
                pcm_log_enrolment_event($pdo, $studentDbId, null, 'student_restored_active', (string)$reviewer, 'Student restored from past students.');
                $flash = 'Student <strong>' . h((string)$student['student_name']) . '</strong> restored to active students.';
                $ok = true;

            } elseif ($action === 'delete') {
                $result = pcm_delete_student($pdo, $studentDbId, (string)$reviewer);
                $flash = $result['message'];
                $ok = $result['ok'];
            }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flash = 'Error: ' . $ex->getMessage();
        }
    }
}

// ── Fetch all students (unified view) ───────────────────────────
$students = $pdo->query("
    SELECT s.*,
           {$studentParentJoinExpr} AS linked_parent_id,
           p.full_name  AS parent_name,
           p.email       AS parent_email,
           p.phone       AS parent_phone,
           p.address     AS parent_address,
           p.id          AS parent_db_id,
           e.id          AS enrolment_id,
           e.start_term  AS enrolment_start_term,
           e.fee_plan    AS enrolment_fee_plan
    FROM students s
    LEFT JOIN parents p ON p.id = {$studentParentJoinExpr}
    LEFT JOIN pcm_enrolments e ON e.student_id = s.id
    ORDER BY s.id DESC
")->fetchAll();
$parentList = $pdo->query("
    SELECT id, full_name, email, phone
    FROM parents
    ORDER BY full_name ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Counts
$total    = count(array_filter($students, fn($r) => strtolower($r['status'] ?? '') !== 'past'));
$pending  = count(array_filter($students, fn($r) => strtolower($r['approval_status'] ?? '') === 'pending'));
$approved = count(array_filter($students, fn($r) => strtolower($r['approval_status'] ?? '') === 'approved'));
$rejected = count(array_filter($students, fn($r) => strtolower($r['approval_status'] ?? '') === 'rejected'));
$past     = count(array_filter($students, fn($r) => strtolower($r['status'] ?? '') === 'past'));
$pageScripts = [
    "https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js",
    "https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js",
    "https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js",
    "https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js",
    "https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js",
    "https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js",
    "https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Child Registration Management</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand:#881b12; --brand-light:#a82218; --brand-bg:#fef3f2; }

        /* Summary cards */
        .stat-card { border-radius:14px; overflow:hidden; border:none; transition:transform .15s; }
        .stat-card:hover { transform:translateY(-3px); }
        .stat-card.status-clickable { cursor:pointer; }
        .stat-icon { width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem; }
        .stat-number { font-size:1.8rem;font-weight:800;line-height:1; }
        .stat-label  { font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#6e7687; }

        /* Filter tabs */
        .filter-pill { border-radius:20px !important; font-weight:600; font-size:.8rem; padding:6px 18px; border:2px solid transparent; margin-right:6px; }
        .filter-pill.active-all      { background:var(--brand);color:#fff;border-color:var(--brand); }
        .filter-pill.active-pending   { background:#f6c23e;color:#000;border-color:#f6c23e; }
        .filter-pill.active-approved  { background:#1cc88a;color:#fff;border-color:#1cc88a; }
        .filter-pill.active-rejected  { background:#e74a3b;color:#fff;border-color:#e74a3b; }
        .filter-pill.active-past      { background:#6c757d;color:#fff;border-color:#6c757d; }

        /* Detail slide-down */
        .detail-panel { background:#f8f9fc; border-radius:10px; padding:24px; margin:12px 0; display:none; }
        .detail-panel .dl-row { display:flex; margin-bottom:8px; }
        .detail-panel .dl-label { width:160px; font-weight:700; font-size:.82rem; color:#5a5c69; text-transform:uppercase; letter-spacing:.4px; }
        .detail-panel .dl-value { flex:1; font-size:.92rem; color:#333; }

        /* Action buttons — compact icon buttons */
        .act-group { display:flex; gap:4px; flex-wrap:nowrap; align-items:center; }
        .btn-mini-label {
            height: 28px;
            padding: 0 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            font-size: .74rem;
            font-weight: 700;
            border: 1.5px solid #4e73df;
            color: #4e73df;
            background: #fff;
            transition: all .15s;
            white-space: nowrap;
        }
        .btn-mini-label:hover { background:#4e73df; color:#fff; text-decoration:none; }
        .btn-act { width:28px; height:28px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; font-size:.72rem; border:1.5px solid; transition:all .15s; }
        .btn-act:hover { transform:scale(1.1); }
        .btn-act.act-view   { color:#4e73df; border-color:#4e73df; background:transparent; }
        .btn-act.act-view:hover   { background:#4e73df; color:#fff; }
        .btn-act.act-ok     { color:#fff; border-color:#1cc88a; background:#1cc88a; }
        .btn-act.act-ok:hover     { background:#17a673; border-color:#17a673; }
        .btn-act.act-no     { color:#fff; border-color:#e74a3b; background:#e74a3b; }
        .btn-act.act-no:hover     { background:#c0392b; border-color:#c0392b; }
        .btn-act.act-del    { color:#e74a3b; border-color:#f5c6cb; background:transparent; }
        .btn-act.act-del:hover    { background:#e74a3b; color:#fff; border-color:#e74a3b; }

        /* Table tweaks */
        #enrolTable thead th { font-size:.75rem; text-transform:uppercase; letter-spacing:.5px; background:#f8f9fc; border-bottom:2px solid #e3e6f0; white-space:nowrap; }
        #enrolTable td { vertical-align:middle; font-size:.88rem; }
        .badge-pill-custom { padding:6px 14px; border-radius:20px; font-weight:700; font-size:.75rem; }

        /* Search row */
        .search-row { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:16px 20px; }
        .search-row label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#5a5c69; margin-bottom:4px; }
        .search-row .form-control { border-radius:8px; height:40px; font-size:.88rem; }

        /* Modal tweaks */
        .modal-content { border-radius:16px; border:none; overflow:hidden; }
        .modal-header { border-bottom:none; padding:20px 24px 10px; }
        .modal-body { padding:10px 24px 24px; }
        .modal-footer { border-top:none; padding:10px 24px 20px; }
        .modal-footer .btn { border-radius:10px; font-weight:600; min-width:110px; }

        /* DataTables export buttons — pill style like filter tabs */
        .dt-buttons .btn.btn-outline-secondary {
            border-radius:20px !important;
            font-weight:600 !important;
            font-size:.8rem !important;
            padding:6px 18px !important;
            border:none !important;
            color:var(--brand) !important;
            background:transparent !important;
        }
        .dt-buttons .btn.btn-outline-secondary:hover,
        .dt-buttons .btn.btn-outline-secondary:focus {
            background:var(--brand) !important;
            color:#fff !important;
        }

        /* Header quick links (Enrollment / Attendance) */
        .header-quick-links {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .header-quick-links .btn {
            border-radius: 8px;
            white-space: nowrap;
        }
        @media (max-width: 767.98px) {
            .header-quick-links {
                width: 100%;
                justify-content: stretch;
                margin-top: 10px;
            }
            .header-quick-links .btn {
                width: 100%;
            }
        }
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
document.addEventListener('DOMContentLoaded', () => {
    Swal.fire({
        icon: '<?= $ok ? "success" : "error" ?>',
        html: <?= json_encode($flash) ?>,
        timer: 2200,
        showConfirmButton: false
    }).then(() => {
        <?php if ($ok): ?>window.location = 'dzoClassManagement.php';<?php endif; ?>
    });
});
</script>
<?php endif; ?>

<!-- ─── Page Header ─── -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">Manage Children</h1>
        <p class="text-muted mb-0" style="font-size:.88rem;">Edit child details, reassign parents, and archive records. Reviewing new registrations and enrollments now happens on the Enrollment page.</p>
    </div>
    <div class="header-quick-links">
        <a href="admin-enrolments" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-file-signature mr-1"></i> Enrollment
        </a>
        <a href="attendanceManagement" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-clipboard-check mr-1"></i> Attendance
        </a>
    </div>
</div>

<!-- ─── Summary Cards ─── -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="all">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(136,27,18,.1);color:var(--brand);"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-number text-gray-800"><?= $total ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Pending">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(246,194,62,.15);color:#f6c23e;"><i class="fas fa-clock"></i></div>
                <div>
                    <div class="stat-number text-gray-800"><?= $pending ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Approved">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(28,200,138,.12);color:#1cc88a;"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="stat-number text-gray-800"><?= $approved ?></div>
                    <div class="stat-label">Approved</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Rejected">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(231,74,59,.1);color:#e74a3b;"><i class="fas fa-times-circle"></i></div>
                <div>
                    <div class="stat-number text-gray-800"><?= $rejected ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm status-clickable js-status-card" data-status="Past">
            <div class="card-body d-flex align-items-center py-3">
                <div class="stat-icon mr-3" style="background:rgba(108,117,125,.12);color:#6c757d;"><i class="fas fa-archive"></i></div>
                <div>
                    <div class="stat-number text-gray-800"><?= $past ?></div>
                    <div class="stat-label">Past Students</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ─── Filter Pills + Search ─── -->
<div class="search-row mb-4">
    <div class="d-flex flex-wrap align-items-center mb-3">
        <button class="btn filter-pill active-all"  data-status="all">All</button>
        <button class="btn filter-pill btn-outline-warning" data-status="Pending"><i class="fas fa-clock mr-1"></i> Pending</button>
        <button class="btn filter-pill btn-outline-success" data-status="Approved"><i class="fas fa-check mr-1"></i> Approved</button>
        <button class="btn filter-pill btn-outline-danger"  data-status="Rejected"><i class="fas fa-times mr-1"></i> Rejected</button>
        <button class="btn filter-pill btn-outline-secondary"  data-status="Past"><i class="fas fa-archive mr-1"></i> Past</button>
    </div>
    <div class="row">
        <div class="col-md-3">
            <label>Search Column</label>
            <select class="form-control" id="colSelect">
                <option value="-1">All Columns</option>
                <option value="1">Student ID</option>
                <option value="2">Name</option>
                <option value="6">Parent</option>
            </select>
        </div>
        <div class="col-md-6">
            <label>Quick Search</label>
            <input type="text" class="form-control" id="searchBox" placeholder="Type to search instantly…">
        </div>
        <div class="col-md-3">
            <label>&nbsp;</label>
            <button class="btn btn-outline-secondary btn-block" id="resetBtn" style="border-radius:8px;height:40px;">
                <i class="fas fa-undo mr-1"></i> Reset
            </button>
        </div>
    </div>
</div>

<!-- ─── Enrolments Table ─── -->
<div class="card shadow mb-4" style="border-radius:14px;border:none;">
    <div class="card-body p-0">
        <div class="table-responsive" style="padding:20px;">
            <table id="enrolTable" class="table table-hover" style="width:100%">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>DOB</th>
                        <th>Gender</th>
                        <th>Medical</th>
                        <th>Status</th>
                        <th>Parent</th>
                        <th>Registered</th>
                        <th style="width:130px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $i => $s):
                    $st = strtolower($s['approval_status'] ?? '');
                    $lifeStatus = strtolower((string)($s['status'] ?? 'active'));
                    $isPastStudent = ($lifeStatus === 'past');
                    $registered = $s['registration_date'] ?? '';
                ?>
                <tr data-status="<?= strtolower($st) ?>" data-life-status="<?= h($lifeStatus) ?>">
                    <td><?= $i + 1 ?></td>
                    <td><code style="font-size:.82rem;background:#f0f0f0;padding:3px 8px;border-radius:4px;"><?= h($s['student_id'] ?? '') ?></code></td>
                    <td class="font-weight-bold"><a href="student-profile?id=<?= (int)$s['id'] ?>"><?= h($s['student_name'] ?? '') ?></a></td>
                    <td><?= !empty($s['dob']) ? date('d M Y', strtotime($s['dob'])) : '—' ?></td>
                    <td><?= h($s['gender'] ?? '—') ?></td>
                    <td style="max-width:160px;white-space:normal;font-size:.84rem;"><?= h($s['medical_issue'] ?? 'None') ?></td>
                    <td>
                        <span class="badge badge-pill-custom badge-<?= pcm_badge($s['approval_status'] ?? 'Pending') ?>">
                            <?= h($s['approval_status'] ?? 'Pending') ?>
                        </span>
                        <?php if ($isPastStudent): ?>
                            <span class="badge badge-secondary ml-1">Past</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:.86rem;"><?= h($s['parent_name'] ?? '—') ?></div>
                        <small class="text-muted"><?= h($s['parent_email'] ?? '') ?></small>
                    </td>
                    <td style="font-size:.82rem;"><?= $registered ? date('d M Y', strtotime($registered)) : '—' ?></td>
                    <td>
                        <div class="act-group">
                            <button type="button" class="btn-act act-view toggle-detail"
                                data-id="<?= (int)$s['id'] ?>"
                                data-student-code="<?= h((string)($s['student_id'] ?? '')) ?>"
                                data-student-name="<?= h((string)($s['student_name'] ?? '')) ?>"
                                data-dob="<?= !empty($s['dob']) ? h(date('d M Y', strtotime((string)$s['dob']))) : '' ?>"
                                data-gender="<?= h((string)($s['gender'] ?? '')) ?>"
                                data-medical="<?= h((string)($s['medical_issue'] ?? 'None')) ?>"
                                data-registered="<?= $registered ? h(date('d M Y', strtotime((string)$registered))) : '' ?>"
                                data-approval-status="<?= h((string)($s['approval_status'] ?? 'Pending')) ?>"
                                data-approval-badge="<?= h(pcm_badge($s['approval_status'] ?? 'Pending')) ?>"
                                data-parent-name="<?= h((string)($s['parent_name'] ?? '')) ?>"
                                data-parent-email="<?= h((string)($s['parent_email'] ?? '')) ?>"
                                data-parent-phone="<?= h((string)($s['parent_phone'] ?? '')) ?>"
                                title="View details"><i class="fas fa-eye"></i></button>
                            <?php
                                $rowHasEnrolment = !empty($s['enrolment_id']);
                                $rowStartTerm = pcm_normalize_start_term($s['enrolment_start_term'] ?? 1);
                                $rowPlan = (string)($s['enrolment_fee_plan'] ?? '');
                                $rowAllowedTerms = [];
                                for ($t = 1; $t <= 4; $t++) {
                                    if (pcm_plan_allowed_for_start_term($rowPlan, $t)) $rowAllowedTerms[] = $t;
                                }
                            ?>
                            <button type="button" class="btn-act act-view js-edit-child-btn"
                                data-id="<?= (int)$s['id'] ?>"
                                data-student-code="<?= h((string)($s['student_id'] ?? '')) ?>"
                                data-student-name="<?= h((string)($s['student_name'] ?? '')) ?>"
                                data-dob="<?= h((string)($s['dob'] ?? '')) ?>"
                                data-gender="<?= h((string)($s['gender'] ?? '')) ?>"
                                data-medical="<?= h((string)($s['medical_issue'] ?? '')) ?>"
                                data-has-enrolment="<?= $rowHasEnrolment ? '1' : '0' ?>"
                                data-plan="<?= h($rowPlan) ?>"
                                data-start-term="<?= $rowStartTerm ?>"
                                data-allowed-terms="<?= h(implode(',', $rowAllowedTerms)) ?>"
                                data-parent-name="<?= h((string)($s['parent_name'] ?? '')) ?>"
                                data-parent-email="<?= h((string)($s['parent_email'] ?? '')) ?>"
                                data-parent-phone="<?= h((string)($s['parent_phone'] ?? '')) ?>"
                                data-parent-address="<?= h((string)($s['parent_address'] ?? '')) ?>"
                                title="Edit child and parent details"><i class="fas fa-edit"></i></button>
                            <button type="button" class="btn-act act-view js-join-parent-btn"
                                data-id="<?= (int)$s['id'] ?>"
                                data-student-code="<?= h((string)($s['student_id'] ?? '')) ?>"
                                data-student-name="<?= h((string)($s['student_name'] ?? '')) ?>"
                                data-current-parent-id="<?= (int)($s['linked_parent_id'] ?? 0) ?>"
                                data-current-parent-name="<?= h((string)($s['parent_name'] ?? '—')) ?>"
                                data-current-parent-email="<?= h((string)($s['parent_email'] ?? '')) ?>"
                                title="Join child to another parent"><i class="fas fa-link"></i></button>
                            <a href="admin-enrolments" class="btn-mini-label" title="Review registration or enrollment on the Enrollment page">
                                <i class="fas fa-file-signature mr-1"></i> Enrollment
                            </a>
                            <?php if ($isPastStudent): ?>
                                <button class="btn-act act-ok restore-btn" data-id="<?= (int)$s['id'] ?>" data-name="<?= h($s['student_name'] ?? '') ?>" title="Restore to active"><i class="fas fa-undo"></i></button>
                            <?php else: ?>
                                <button class="btn-act act-del move-past-btn" data-id="<?= (int)$s['id'] ?>" data-name="<?= h($s['student_name'] ?? '') ?>" title="Move to past students"><i class="fas fa-archive"></i></button>
                            <?php endif; ?>
                            <button class="btn-act act-del delete-btn" data-id="<?= (int)$s['id'] ?>" data-name="<?= h($s['student_name'] ?? '') ?>" title="Delete permanently"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="editChildModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="editChildForm" class="js-enrol-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="admin_update_child_details">
                <input type="hidden" name="student_id" id="editChildStudentId" value="">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-user-edit text-primary mr-2"></i>Edit Child Details</h5>
                        <small class="text-muted">Update child profile and parent contact details</small>
                    </div>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-user-graduate mr-1"></i>Child Details</h6>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Student ID</label>
                            <input type="text" class="form-control" id="editChildStudentCode" readonly>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Child Name</label>
                            <input type="text" class="form-control" name="student_name" id="editChildName" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Date of Birth</label>
                            <input type="date" class="form-control" name="dob" id="editChildDob">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Gender</label>
                            <select class="form-control" name="gender" id="editChildGender">
                                <option value="">--</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Medical</label>
                            <input type="text" class="form-control" name="medical_issue" id="editChildMedical" maxlength="500">
                        </div>
                    </div>

                    <div id="editChildEnrolmentSection" style="display:none;">
                        <hr>
                        <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-file-signature mr-1"></i>Enrollment</h6>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Starting Term</label>
                                <select class="form-control" name="start_term" id="editChildStartTerm">
                                    <option value="1">Term 1</option>
                                    <option value="2">Term 2</option>
                                    <option value="3">Term 3</option>
                                    <option value="4">Term 4</option>
                                </select>
                                <small class="form-text text-muted">Only terms valid for the current plan are shown. Changing this recalculates the fee amount and instalment schedule if no instalments have been paid or reviewed yet.</small>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-user-friends mr-1"></i>Parent Contact Details</h6>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Parent Name</label>
                            <input type="text" class="form-control" name="parent_name" id="editChildParentName" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Parent Phone</label>
                            <input type="text" class="form-control" name="parent_phone" id="editChildParentPhone">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Parent Email</label>
                            <input type="email" class="form-control" name="parent_email" id="editChildParentEmail">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Parent Address</label>
                            <input type="text" class="form-control" name="parent_address" id="editChildParentAddress">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary js-submit-action-btn"><i class="fas fa-save mr-1"></i> Save Details</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="joinParentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="joinParentForm" class="js-enrol-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="admin_reassign_parent">
                <input type="hidden" name="student_id" id="joinParentStudentId" value="">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-link text-primary mr-2"></i>Join Child to Parent</h5>
                        <small class="text-muted">Re-link child, enrollment, and payment records to another parent account.</small>
                    </div>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border mb-3" id="joinParentInfo"></div>
                    <div class="form-group mb-0">
                        <label>Select New Parent</label>
                        <select name="target_parent_id" class="form-control" id="joinParentSelect" required>
                            <option value="">Choose parent account</option>
                            <?php foreach ($parentList as $p): ?>
                                <?php $candidatePid = (int)($p['id'] ?? 0); if ($candidatePid <= 0) continue; ?>
                                <option value="<?= $candidatePid ?>" data-parent-id="<?= $candidatePid ?>">
                                    <?= h((string)($p['full_name'] ?? 'Parent')) ?><?= !empty($p['email']) ? ' - ' . h((string)$p['email']) : '' ?><?= !empty($p['phone']) ? ' (' . h((string)$p['phone']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary js-submit-action-btn"><i class="fas fa-link mr-1"></i> Join Parent</button>
                </div>
            </form>
        </div>
    </div>
</div>


</div><!-- container-fluid -->
</div><!-- content -->

<?php include 'include/admin-footer.php'; ?>
</div>
</div>

<!-- Student lifecycle forms (hidden, submitted via JS) -->
<form id="movePastForm" method="POST" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="move_past">
    <input type="hidden" name="student_id" id="movePastStudentId" value="">
</form>
<form id="restoreActiveForm" method="POST" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="restore_active">
    <input type="hidden" name="student_id" id="restoreActiveStudentId" value="">
</form>
<form id="deleteForm" method="POST" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="student_id" id="deleteStudentId" value="">
</form>

<script>
// Reusable "type the name to confirm" guard for destructive actions.
function bbccTypedConfirm(opts) {
    var expected = String(opts.expected || '');
    Swal.fire({
        title: opts.title,
        html: opts.warningHtml +
            '<div class="mt-3 text-left"><label class="small font-weight-bold mb-1">Type <code>' +
            $('<div>').text(expected).html() + '</code> to confirm:</label>' +
            '<input id="typedConfirmInput" class="swal2-input" style="margin:0;" autocomplete="off"></div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: opts.confirmColor || '#e74a3b',
        cancelButtonColor: '#858796',
        confirmButtonText: opts.confirmText || '<i class="fas fa-trash-alt mr-1"></i> Delete',
        cancelButtonText: 'Cancel',
        focusConfirm: false,
        preConfirm: function () {
            var val = (document.getElementById('typedConfirmInput').value || '').trim();
            if (val !== expected) {
                Swal.showValidationMessage('Type the name exactly to confirm.');
                return false;
            }
            return true;
        }
    }).then(function (result) {
        if (result.isConfirmed && typeof opts.onConfirm === 'function') opts.onConfirm();
    });
}

$(function(){
    // DataTable
    var dt = $('#enrolTable').DataTable({
        pageLength: 15,
        lengthMenu: [[15, 25, 50, -1], [15, 25, 50, "All"]],
        order: [[0, 'asc']],
        columnDefs: [
            { targets: [9], orderable: false },
            { targets: '_all', className: 'align-middle' }
        ],
        dom: "<'row mb-2'<'col-md-6'B><'col-md-6 text-md-right'l>>" +
             "<'row'<'col-12'tr>>" +
             "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: [
            { extend: 'copyHtml5', className: 'btn btn-sm btn-outline-secondary', text: '<i class="fas fa-copy mr-1"></i> Copy' },
            { extend: 'csvHtml5',  className: 'btn btn-sm btn-outline-secondary', text: '<i class="fas fa-file-csv mr-1"></i> CSV' },
            { extend: 'excelHtml5',className: 'btn btn-sm btn-outline-secondary', text: '<i class="fas fa-file-excel mr-1"></i> Excel' },
            { extend: 'print',     className: 'btn btn-sm btn-outline-secondary', text: '<i class="fas fa-print mr-1"></i> Print' }
        ]
    });

    // Robust row-level status filtering (independent of badge HTML content)
    var activeStatusFilter = 'all';
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
        if (settings.nTable !== dt.table().node()) return true;
        if (activeStatusFilter === 'all') return true;
        var rowNode = dt.row(dataIndex).node();
        if (!rowNode) return true;
        var rowLifeStatus = String($(rowNode).attr('data-life-status') || '').toLowerCase();
        if (String(activeStatusFilter).toLowerCase() === 'past') {
            return rowLifeStatus === 'past';
        }
        if (rowLifeStatus === 'past') {
            return false;
        }
        var rowStatus = String($(rowNode).attr('data-status') || '').toLowerCase();
        return rowStatus === String(activeStatusFilter).toLowerCase();
    });

    // Filter pills
    $('.filter-pill').on('click', function(){
        $('.filter-pill').removeClass('active-all active-pending active-approved active-rejected')
            .removeClass('active-past')
            .addClass(function(){ return 'btn-outline-' + ($(this).data('status')==='Pending'?'warning':$(this).data('status')==='Approved'?'success':$(this).data('status')==='Rejected'?'danger':'secondary'); });
        var s = $(this).data('status');
        $(this).removeClass('btn-outline-warning btn-outline-success btn-outline-danger btn-outline-secondary');
        $(this).addClass('active-' + s.toLowerCase());
        activeStatusFilter = s;
        dt.draw();
    });

    // Column search
    $('#searchBox').on('input', function(){
        var col = parseInt($('#colSelect').val(), 10);
        var val = this.value;
        dt.columns().search('');
        if (col === -1) dt.search(val).draw();
        else { dt.search(''); dt.column(col).search(val).draw(); }
    });
    $('#colSelect').on('change', function(){ $('#searchBox').trigger('input'); });

    // Reset
    $('#resetBtn').on('click', function(){
        $('.filter-pill').removeClass('active-all active-pending active-approved active-rejected active-past')
            .addClass(function(){ return 'btn-outline-secondary'; });
        $('.filter-pill[data-status="all"]').removeClass('btn-outline-secondary').addClass('active-all');
        $('#colSelect').val('-1');
        $('#searchBox').val('');
        activeStatusFilter = 'all';
        dt.search('').columns().search('').draw();
    });

    // Toggle detail via DataTables child row -- content built from the
    // button's own data-* attributes rather than a pre-rendered per-row
    // hidden block, so the page doesn't ship a detail panel for every
    // student whether or not it's ever opened.
    function bbccEscape(v) {
        return $('<div/>').text(v == null ? '' : v).html();
    }
    $(document).on('click', '.toggle-detail', function(){
        var $btn = $(this);
        var tr = $btn.closest('tr');
        var row = dt.row(tr);
        if (row.child.isShown()) {
            row.child.hide();
            $btn.find('i').removeClass('fa-eye-slash').addClass('fa-eye');
        } else {
            var d = $btn.data();
            var html = '' +
                '<div class="detail-panel" style="display:block;">' +
                '<div class="row">' +
                '<div class="col-md-6">' +
                '<h6 class="font-weight-bold mb-3" style="color:var(--brand);"><i class="fas fa-user-graduate mr-1"></i> Student Info</h6>' +
                '<div class="dl-row"><div class="dl-label">Student ID</div><div class="dl-value">' + bbccEscape(d.studentCode || '—') + '</div></div>' +
                '<div class="dl-row"><div class="dl-label">Full Name</div><div class="dl-value">' + bbccEscape(d.studentName || '—') + '</div></div>' +
                '<div class="dl-row"><div class="dl-label">Date of Birth</div><div class="dl-value">' + bbccEscape(d.dob || '—') + '</div></div>' +
                '<div class="dl-row"><div class="dl-label">Gender</div><div class="dl-value">' + bbccEscape(d.gender || '—') + '</div></div>' +
                '<div class="dl-row"><div class="dl-label">Medical</div><div class="dl-value">' + bbccEscape(d.medical || 'None') + '</div></div>' +
                '<div class="dl-row"><div class="dl-label">Registered</div><div class="dl-value">' + bbccEscape(d.registered || '—') + '</div></div>' +
                '<div class="dl-row"><div class="dl-label">Status</div><div class="dl-value"><span class="badge badge-' + bbccEscape(d.approvalBadge || 'secondary') + '">' + bbccEscape(d.approvalStatus || '—') + '</span></div></div>' +
                '</div>' +
                '<div class="col-md-6">' +
                '<h6 class="font-weight-bold mb-3" style="color:var(--brand);"><i class="fas fa-user-friends mr-1"></i> Parent Info</h6>' +
                '<div class="dl-row"><div class="dl-label">Name</div><div class="dl-value">' + bbccEscape(d.parentName || '—') + '</div></div>' +
                '<div class="dl-row"><div class="dl-label">Email</div><div class="dl-value">' + bbccEscape(d.parentEmail || '—') + '</div></div>' +
                '<div class="dl-row"><div class="dl-label">Phone</div><div class="dl-value">' + bbccEscape(d.parentPhone || '—') + '</div></div>' +
                '</div>' +
                '</div>' +
                '</div>';
            row.child(html).show();
            $btn.find('i').removeClass('fa-eye').addClass('fa-eye-slash');
        }
    });

    // Move to past students
    $(document).on('click', '.move-past-btn', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        var name = $(this).data('name');
        Swal.fire({
            title: 'Move to Past Students?',
            html: '<strong>' + name + '</strong> will be removed from their current class but history will be kept.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#6c757d',
            cancelButtonColor: '#858796',
            confirmButtonText: '<i class="fas fa-archive mr-1"></i> Move to Past',
            cancelButtonText: 'Cancel'
        }).then(function(result){
            if (result.isConfirmed) {
                $('#movePastStudentId').val(id);
                $('#movePastForm').submit();
            }
        });
    });

    // Restore from past students
    $(document).on('click', '.restore-btn', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        var name = $(this).data('name');
        Swal.fire({
            title: 'Restore Student?',
            html: 'Restore <strong>' + name + '</strong> to active students? You can assign a class again afterward.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#1cc88a',
            cancelButtonColor: '#858796',
            confirmButtonText: '<i class="fas fa-undo mr-1"></i> Restore',
            cancelButtonText: 'Cancel'
        }).then(function(result){
            if (result.isConfirmed) {
                $('#restoreActiveStudentId').val(id);
                $('#restoreActiveForm').submit();
            }
        });
    });

    // Delete with typed-name confirmation
    $(document).on('click', '.delete-btn', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        var name = $(this).data('name');
        bbccTypedConfirm({
            title: 'Delete Student?',
            expected: name,
            warningHtml: 'This permanently removes <strong>' + $('<div>').text(name).html() + '</strong> and all related records ' +
                '(fees, attendance, enrollment, payments, class assignment, sign-in history). This cannot be undone.',
            onConfirm: function () {
                $('#deleteStudentId').val(id);
                $('#deleteForm').submit();
            }
        });
    });

    // Edit child + parent details (shared modal, populated from the row button's data-*)
    $(document).on('click', '.js-edit-child-btn', function(){
        var d = $(this).data();
        var $form = $('#editChildForm')[0];
        if ($form) $form.reset();

        $('#editChildStudentId').val(d.id);
        $('#editChildStudentCode').val(d.studentCode || '');
        $('#editChildName').val(d.studentName || '');
        $('#editChildDob').val(d.dob || '');
        $('#editChildGender').val(d.gender || '');
        $('#editChildMedical').val(d.medical || '');
        $('#editChildParentName').val(d.parentName || '');
        $('#editChildParentEmail').val(d.parentEmail || '');
        $('#editChildParentPhone').val(d.parentPhone || '');
        $('#editChildParentAddress').val(d.parentAddress || '');

        var hasEnrolment = String(d.hasEnrolment) === '1';
        $('#editChildEnrolmentSection').toggle(hasEnrolment);
        if (hasEnrolment) {
            var allowed = String(d.allowedTerms || '').split(',').filter(Boolean);
            var $termSelect = $('#editChildStartTerm');
            $termSelect.find('option').each(function(){
                $(this).prop('hidden', allowed.indexOf($(this).val()) === -1);
            });
            $termSelect.val(String(d.startTerm || '1'));
        }

        $('#editChildModal').modal('show');
    });

    // Join child to a different parent (shared modal)
    $(document).on('click', '.js-join-parent-btn', function(){
        var d = $(this).data();
        $('#joinParentStudentId').val(d.id);

        var infoHtml = '<strong>Child:</strong> ' + bbccEscape(d.studentName) + ' (' + bbccEscape(d.studentCode) + ')<br>' +
            '<strong>Current Parent:</strong> ' + bbccEscape(d.currentParentName || '—') +
            (d.currentParentEmail ? ' - ' + bbccEscape(d.currentParentEmail) : '');
        $('#joinParentInfo').html(infoHtml);

        var currentPid = String(d.currentParentId || '');
        var $select = $('#joinParentSelect');
        $select.find('option[data-parent-id]').each(function(){
            $(this).prop('hidden', String($(this).data('parent-id')) === currentPid);
        });
        $select.val('');

        $('#joinParentModal').modal('show');
    });

    // Close approve/reject modal immediately on submit so UI does not appear stuck
    $(document).on('submit', 'form.js-enrol-action-form', function(){
        var $form = $(this);
        var $btn = $form.find('.js-submit-action-btn');
        $btn.prop('disabled', true);
        var originalText = $btn.text();
        $btn.text('Processing...');
        $form.closest('.modal').modal('hide');
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open').css('padding-right', '');
        // keep original text only if browser prevented submission for any reason
        setTimeout(function(){
            if (!$form[0].checkValidity || $form[0].checkValidity()) return;
            $btn.prop('disabled', false).text(originalText);
        }, 300);
    });

    // Click summary card to jump-filter list
    $('.js-status-card').on('click', function(){
        var status = $(this).data('status');
        $('.filter-pill[data-status="' + status + '"]').trigger('click');
        var top = $('#enrolTable').closest('.card').offset().top - 90;
        window.scrollTo({ top: top, behavior: 'smooth' });
    });

    <?php if ($joinParentForId > 0): ?>
    // Deep link from the Parents & Children page: open Join to Parent for a specific child
    (function(){
        var $btn = $('.js-join-parent-btn[data-id="<?= $joinParentForId ?>"]');
        if (!$btn.length) return;
        dt.search($btn.data('student-code') || $btn.data('student-name') || '').draw();
        setTimeout(function(){ $btn.trigger('click'); }, 100);
    })();
    <?php endif; ?>
});
</script>
</body>
</html>
