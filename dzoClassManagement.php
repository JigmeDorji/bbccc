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
$studentParentColumn = pcm_students_parent_column($pdo);
$campusChoices = pcm_campus_choice_labels();
$flash = '';
$ok    = false;

$hasParentIdNew = (bool)$pdo->query("SHOW COLUMNS FROM students LIKE 'parent_id'")->fetch(PDO::FETCH_ASSOC);
$hasParentIdLegacy = (bool)$pdo->query("SHOW COLUMNS FROM students LIKE 'parentId'")->fetch(PDO::FETCH_ASSOC);
$studentParentExpr = $hasParentIdNew && $hasParentIdLegacy
    ? "COALESCE(NULLIF(parent_id,0), NULLIF(parentId,0))"
    : ($hasParentIdNew ? "parent_id" : "parentId");
$studentParentJoinExpr = $hasParentIdNew && $hasParentIdLegacy
    ? "COALESCE(NULLIF(s.parent_id,0), NULLIF(s.parentId,0))"
    : ($hasParentIdNew ? "s.parent_id" : "s.parentId");

// ── POST: approve / reject / delete ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $studentDbId = (int)($_POST['student_id'] ?? 0);

    if ($studentDbId > 0 && in_array($action, ['approve','reject','delete','admin_update_enrolment'])) {
        try {
            $reviewer = $_SESSION['username'] ?? 'admin';

            if ($action === 'admin_update_enrolment') {
                $plan = trim((string)($_POST['fee_plan'] ?? 'Term-wise'));
                $allowedPlans = ['Term-wise', 'Half-yearly', 'Yearly'];
                if (!in_array($plan, $allowedPlans, true)) {
                    throw new Exception("Invalid fee plan.");
                }
                $campusSelection = $_POST['campus_choice'] ?? [];
                if (!is_array($campusSelection)) $campusSelection = [];
                $campusSelection = array_values(array_unique(array_filter(array_map('strval', $campusSelection))));
                $allowedCampusChoices = array_keys($campusChoices);
                if (empty($campusSelection) || array_diff($campusSelection, $allowedCampusChoices)) {
                    throw new Exception("Please select at least one valid campus.");
                }
                $campusStored = implode(',', $campusSelection);
                $amount = (float)($_POST['fee_amount'] ?? pcm_plan_amount($plan));
                if ($amount < 0) $amount = 0;
                $ref = trim((string)($_POST['payment_ref'] ?? ''));
                $note = trim((string)($_POST['admin_note'] ?? ''));

                $stu = $pdo->prepare("SELECT id, student_name, {$studentParentExpr} AS parent_id FROM students WHERE id = :id LIMIT 1");
                $stu->execute([':id' => $studentDbId]);
                $student = $stu->fetch(PDO::FETCH_ASSOC);
                if (!$student) throw new Exception("Student not found.");
                $parentId = (int)($student['parent_id'] ?? 0);
                if ($parentId <= 0) throw new Exception("Parent link missing for this child.");

                $existing = $pdo->prepare("SELECT id FROM pcm_enrolments WHERE student_id = :sid LIMIT 1");
                $existing->execute([':sid' => $studentDbId]);
                $row = $existing->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $eid = (int)$row['id'];
                    $upd = $pdo->prepare("
                        UPDATE pcm_enrolments
                        SET fee_plan=:plan, campus_preference=:campus, fee_amount=:amt, payment_ref=:ref, admin_note=:note
                        WHERE id=:id
                    ");
                    $upd->execute([
                        ':plan' => $plan, ':campus' => $campusStored, ':amt' => $amount,
                        ':ref' => ($ref !== '' ? $ref : null), ':note' => ($note !== '' ? $note : null), ':id' => $eid
                    ]);
                    pcm_log_enrolment_event($pdo, $studentDbId, $eid, 'admin_enrolment_updated', (string)$reviewer, 'Updated from child registration page.');
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO pcm_enrolments (student_id, parent_id, fee_plan, campus_preference, fee_amount, payment_ref, status, admin_note, submitted_at)
                        VALUES (:sid,:pid,:plan,:campus,:amt,:ref,'Pending',:note,NOW())
                    ");
                    $ins->execute([
                        ':sid' => $studentDbId, ':pid' => $parentId, ':plan' => $plan, ':campus' => $campusStored,
                        ':amt' => $amount, ':ref' => ($ref !== '' ? $ref : null), ':note' => ($note !== '' ? $note : null)
                    ]);
                    $eid = (int)$pdo->lastInsertId();
                    pcm_log_enrolment_event($pdo, $studentDbId, $eid, 'admin_enrolment_created_from_child_reg', (string)$reviewer, 'Created from child registration page.');
                }
                $flash = 'Enrollment updated for <strong>' . h((string)$student['student_name']) . '</strong>.';
                $ok = true;
            } elseif ($action === 'delete') {
                $stu = $pdo->prepare("SELECT student_name FROM students WHERE id = :id LIMIT 1");
                $stu->execute([':id' => $studentDbId]);
                $student = $stu->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    throw new Exception("Student not found.");
                }

                $pdo->beginTransaction();
                // Delete fees (both old + PCM)
                $pdo->prepare("DELETE FROM fees_payments WHERE student_id = :sid")->execute([':sid' => (string)$studentDbId]);
                $pdo->prepare("DELETE FROM pcm_fee_payments WHERE student_id = :sid")->execute([':sid' => $studentDbId]);
                // Delete enrolment record
                $pdo->prepare("DELETE FROM pcm_enrolments WHERE student_id = :sid")->execute([':sid' => $studentDbId]);
                // Delete student
                $pdo->prepare("DELETE FROM students WHERE id = :id")->execute([':id' => $studentDbId]);
                $pdo->commit();
                $flash = 'Student record deleted.';
                $ok = true;

            } else {
                $note = trim($_POST['admin_note'] ?? '');
                $result = pcm_process_enrolment_decision($pdo, $studentDbId, $action, $reviewer, $note);
                pcm_log_enrolment_event(
                    $pdo,
                    $studentDbId,
                    (int)($result['enrolment_id'] ?? 0),
                    $result['new_status'] === 'Approved' ? 'child_registration_approved' : 'child_registration_rejected',
                    (string)($_SESSION['username'] ?? 'admin'),
                    $note
                );

                // Email parent if we have their email
                if (!empty($result['parent_email'])) {
                    // On child-registration approval, ask parent to complete enrollment.
                    if ($result['new_status'] === 'Approved') {
                        pcm_notify_parent_payment_required(
                            $pdo,
                            (string)$result['parent_email'],
                            (string)$result['parent_name'],
                            (string)$result['student_name'],
                            (string)$result['fee_plan'],
                            (float)$result['fee_amount']
                        );
                        bbcc_notify_username(
                            $pdo,
                            (string)$result['parent_email'],
                            'Child Registration Approved for ' . (string)$result['student_name'],
                            'Your child registration is approved. Please complete enrollment by selecting campus and payment plan.',
                            'children-enrollment'
                        );
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
                            'Child Registration Rejected for ' . (string)$result['student_name'],
                            'Your child registration was not approved. Please review admin notes and contact admin if needed.',
                            'parent-children'
                        );
                    }
                }

                $flash = "Enrolment <strong>{$result['new_status']}</strong> for " . h($result['student_name']) . ".";
                $ok = true;
            }
        } catch (Exception $ex) {
            $flash = 'Error: ' . $ex->getMessage();
        }
    }
}

// ── Fetch all students (unified view) ───────────────────────────
$students = $pdo->query("
    SELECT s.*,
           p.full_name  AS parent_name,
           p.email       AS parent_email,
           p.phone       AS parent_phone,
           p.address     AS parent_address
    FROM students s
    LEFT JOIN parents p ON p.id = {$studentParentJoinExpr}
    ORDER BY s.id DESC
")->fetchAll();

$enrolByStudent = [];
$enrolRows = $pdo->query("SELECT id, student_id, fee_plan, campus_preference, fee_amount, payment_ref, admin_note FROM pcm_enrolments")->fetchAll(PDO::FETCH_ASSOC);
foreach ($enrolRows as $er) {
    $enrolByStudent[(int)$er['student_id']] = $er;
}

// Counts
$total    = count($students);
$pending  = count(array_filter($students, fn($r) => strtolower($r['approval_status'] ?? '') === 'pending'));
$approved = count(array_filter($students, fn($r) => strtolower($r['approval_status'] ?? '') === 'approved'));
$rejected = count(array_filter($students, fn($r) => strtolower($r['approval_status'] ?? '') === 'rejected'));

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
        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">Child Registration</h1>
        <p class="text-muted mb-0" style="font-size:.88rem;">Review and approve newly added children before parents can proceed to enrollment.</p>
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
</div>

<!-- ─── Filter Pills + Search ─── -->
<div class="search-row mb-4">
    <div class="d-flex flex-wrap align-items-center mb-3">
        <button class="btn filter-pill active-all"  data-status="all">All</button>
        <button class="btn filter-pill btn-outline-warning" data-status="Pending"><i class="fas fa-clock mr-1"></i> Pending</button>
        <button class="btn filter-pill btn-outline-success" data-status="Approved"><i class="fas fa-check mr-1"></i> Approved</button>
        <button class="btn filter-pill btn-outline-danger"  data-status="Rejected"><i class="fas fa-times mr-1"></i> Rejected</button>
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
                    $registered = $s['registration_date'] ?? '';
                ?>
                <tr data-status="<?= strtolower($st) ?>">
                    <td><?= $i + 1 ?></td>
                    <td><code style="font-size:.82rem;background:#f0f0f0;padding:3px 8px;border-radius:4px;"><?= h($s['student_id'] ?? '') ?></code></td>
                    <td class="font-weight-bold"><?= h($s['student_name'] ?? '') ?></td>
                    <td><?= !empty($s['dob']) ? date('d M Y', strtotime($s['dob'])) : '—' ?></td>
                    <td><?= h($s['gender'] ?? '—') ?></td>
                    <td style="max-width:160px;white-space:normal;font-size:.84rem;"><?= h($s['medical_issue'] ?? 'None') ?></td>
                    <td>
                        <span class="badge badge-pill-custom badge-<?= pcm_badge($s['approval_status'] ?? 'Pending') ?>">
                            <?= h($s['approval_status'] ?? 'Pending') ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-size:.86rem;"><?= h($s['parent_name'] ?? '—') ?></div>
                        <small class="text-muted"><?= h($s['parent_email'] ?? '') ?></small>
                    </td>
                    <td style="font-size:.82rem;"><?= $registered ? date('d M Y', strtotime($registered)) : '—' ?></td>
                    <td>
                        <div class="act-group">
                            <button class="btn-act act-view toggle-detail" data-id="<?= (int)$s['id'] ?>" title="View details"><i class="fas fa-eye"></i></button>
                            <button class="btn-mini-label" data-toggle="modal" data-target="#enrolModal<?= $s['id'] ?>" title="Create or Update Enrollment">
                                <i class="fas fa-file-signature mr-1"></i> Enroll
                            </button>
                            <?php if ($st === 'pending'): ?>
                            <button class="btn-act act-ok" data-toggle="modal" data-target="#approveModal<?= $s['id'] ?>" title="Approve"><i class="fas fa-check"></i></button>
                            <button class="btn-act act-no" data-toggle="modal" data-target="#rejectModal<?= $s['id'] ?>" title="Reject"><i class="fas fa-times"></i></button>
                            <?php endif; ?>
                            <button class="btn-act act-del delete-btn" data-id="<?= (int)$s['id'] ?>" data-name="<?= h($s['student_name'] ?? '') ?>" title="Delete"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ─── Detail Panels (hidden, injected via JS child rows) ─── -->
<?php foreach ($students as $i => $s):
    $st = strtolower($s['approval_status'] ?? '');
?>
<div id="detailHtml-<?= (int)$s['id'] ?>" style="display:none;">
    <div class="detail-panel" style="display:block;">
        <div class="row">
            <div class="col-md-6">
                <h6 class="font-weight-bold mb-3" style="color:var(--brand);"><i class="fas fa-user-graduate mr-1"></i> Student Info</h6>
                <div class="dl-row"><div class="dl-label">Student ID</div><div class="dl-value"><?= h($s['student_id'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Full Name</div><div class="dl-value"><?= h($s['student_name'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Date of Birth</div><div class="dl-value"><?= h($s['dob'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Gender</div><div class="dl-value"><?= h($s['gender'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Medical</div><div class="dl-value"><?= h($s['medical_issue'] ?? 'None') ?></div></div>
                <div class="dl-row"><div class="dl-label">Registered</div><div class="dl-value"><?= !empty($s['registration_date']) ? date('d M Y', strtotime($s['registration_date'])) : '—' ?></div></div>
                <div class="dl-row"><div class="dl-label">Status</div><div class="dl-value"><span class="badge badge-<?= pcm_badge($s['approval_status'] ?? '') ?>"><?= h($s['approval_status'] ?? '—') ?></span></div></div>
            </div>
            <div class="col-md-6">
                <h6 class="font-weight-bold mb-3" style="color:var(--brand);"><i class="fas fa-user-friends mr-1"></i> Parent Info</h6>
                <div class="dl-row"><div class="dl-label">Name</div><div class="dl-value"><?= h($s['parent_name'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Email</div><div class="dl-value"><?= h($s['parent_email'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Phone</div><div class="dl-value"><?= h($s['parent_phone'] ?? '—') ?></div></div>
            </div>
        </div>
    </div>
</div>

<?php if ($st === 'pending'): ?>
<!-- Approve Modal -->
<div class="modal fade" id="approveModal<?= $s['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" class="js-enrol-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-check-circle text-success mr-2"></i>Approve Child Registration</h5>
                        <small class="text-muted">This will activate the child so parent can complete enrollment.</small>
                    </div>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center p-3 mb-3" style="background:#f0faf5;border-radius:10px;">
                        <i class="fas fa-user-graduate text-success mr-3" style="font-size:1.4rem;"></i>
                        <div>
                            <strong><?= h($s['student_name'] ?? '') ?></strong><br>
                            <small class="text-muted">Student ID: <?= h($s['student_id'] ?? '') ?></small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="font-size:.82rem;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-sticky-note mr-1" style="color:var(--brand);"></i> Note (optional)
                        </label>
                        <textarea name="admin_note" class="form-control" rows="2" placeholder="e.g. Payment verified" style="border-radius:10px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success js-submit-action-btn"><i class="fas fa-check mr-1"></i> Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal<?= $s['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" class="js-enrol-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-times-circle text-danger mr-2"></i>Reject Child Registration</h5>
                        <small class="text-muted">The parent will be notified by email.</small>
                    </div>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center p-3 mb-3" style="background:#fef3f2;border-radius:10px;">
                        <i class="fas fa-user-graduate text-danger mr-3" style="font-size:1.4rem;"></i>
                        <div>
                            <strong><?= h($s['student_name'] ?? '') ?></strong><br>
                            <small class="text-muted">Student ID: <?= h($s['student_id'] ?? '') ?></small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="font-size:.82rem;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">
                            <i class="fas fa-comment-alt mr-1" style="color:var(--brand);"></i> Reason / Note <span class="text-danger">*</span>
                        </label>
                        <textarea name="admin_note" class="form-control" rows="2" required placeholder="e.g. Payment proof unclear, please re-upload" style="border-radius:10px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger js-submit-action-btn"><i class="fas fa-times mr-1"></i> Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php foreach ($students as $s):
    $existingEn = $enrolByStudent[(int)$s['id']] ?? null;
    $existingPlan = (string)($existingEn['fee_plan'] ?? 'Term-wise');
    $existingCampus = array_filter(array_map('trim', explode(',', (string)($existingEn['campus_preference'] ?? ''))));
    $existingAmt = (string)($existingEn['fee_amount'] ?? pcm_plan_amount($existingPlan));
?>
<div class="modal fade" id="enrolModal<?= $s['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" class="js-enrol-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="admin_update_enrolment">
                <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-file-signature text-primary mr-2"></i>Update Enrollment</h5>
                        <small class="text-muted"><?= h($s['student_name'] ?? '') ?> (<?= h($s['student_id'] ?? '') ?>)</small>
                    </div>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-user-friends mr-1"></i>Parent Details (Auto-Linked)</h6>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Parent Name</label>
                            <input type="text" class="form-control" value="<?= h((string)($s['parent_name'] ?? '')) ?>" readonly>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Parent Phone</label>
                            <input type="text" class="form-control" value="<?= h((string)($s['parent_phone'] ?? '')) ?>" readonly>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Parent Email</label>
                        <input type="text" class="form-control" value="<?= h((string)($s['parent_email'] ?? '')) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Parent Address</label>
                        <input type="text" class="form-control" value="<?= h((string)($s['parent_address'] ?? '')) ?>" readonly>
                    </div>

                    <hr>
                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-file-signature mr-1"></i>Enrollment Details</h6>
                    <div class="form-group">
                        <label>Fee Plan</label>
                        <select name="fee_plan" class="form-control" required>
                            <?php foreach (['Term-wise','Half-yearly','Yearly'] as $fp): ?>
                                <option value="<?= h($fp) ?>" <?= $existingPlan === $fp ? 'selected' : '' ?>><?= h($fp) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Campus</label>
                        <?php foreach ($campusChoices as $ck => $cl): ?>
                            <div class="custom-control custom-checkbox">
                                <input class="custom-control-input" type="checkbox" id="camp_<?= (int)$s['id'] ?>_<?= h($ck) ?>" name="campus_choice[]" value="<?= h($ck) ?>" <?= in_array($ck, $existingCampus, true) ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="camp_<?= (int)$s['id'] ?>_<?= h($ck) ?>"><?= h($cl) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Fee Amount</label>
                            <input type="number" min="0" step="0.01" class="form-control" name="fee_amount" value="<?= h($existingAmt) ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Reference</label>
                            <input type="text" class="form-control" name="payment_ref" value="<?= h((string)($existingEn['payment_ref'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label>Admin Note</label>
                        <textarea name="admin_note" class="form-control" rows="2"><?= h((string)($existingEn['admin_note'] ?? '')) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary js-submit-action-btn"><i class="fas fa-save mr-1"></i> Save Enrollment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

</div><!-- container-fluid -->
</div><!-- content -->

<?php include 'include/admin-footer.php'; ?>
</div>
</div>

<!-- Delete form (hidden, submitted via JS) -->
<form id="deleteForm" method="POST" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="student_id" id="deleteStudentId" value="">
</form>

<script>
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
        var rowStatus = String($(rowNode).attr('data-status') || '').toLowerCase();
        return rowStatus === String(activeStatusFilter).toLowerCase();
    });

    // Filter pills
    $('.filter-pill').on('click', function(){
        $('.filter-pill').removeClass('active-all active-pending active-approved active-rejected')
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
        $('.filter-pill').removeClass('active-all active-pending active-approved active-rejected')
            .addClass(function(){ return 'btn-outline-secondary'; });
        $('.filter-pill[data-status="all"]').removeClass('btn-outline-secondary').addClass('active-all');
        $('#colSelect').val('-1');
        $('#searchBox').val('');
        activeStatusFilter = 'all';
        dt.search('').columns().search('').draw();
    });

    // Toggle detail via DataTables child row
    $(document).on('click', '.toggle-detail', function(){
        var id = $(this).data('id');
        var tr = $(this).closest('tr');
        var row = dt.row(tr);
        if (row.child.isShown()) {
            row.child.hide();
            $(this).find('i').removeClass('fa-eye-slash').addClass('fa-eye');
        } else {
            var html = $('#detailHtml-' + id).html();
            row.child(html).show();
            $(this).find('i').removeClass('fa-eye').addClass('fa-eye-slash');
        }
    });

    // Delete with SweetAlert
    $(document).on('click', '.delete-btn', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        var name = $(this).data('name');
        Swal.fire({
            title: 'Delete Enrolment?',
            html: 'Permanently remove <strong>' + name + '</strong> and all related records?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            cancelButtonColor: '#858796',
            confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Delete',
            cancelButtonText: 'Cancel'
        }).then(function(result){
            if (result.isConfirmed) {
                $('#deleteStudentId').val(id);
                $('#deleteForm').submit();
            }
        });
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
});
</script>
</body>
</html>
