<?php
// dzoClassManagement.php — Unified Enrolment & Student Management
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') { header("Location: index-admin.php"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;

// ── POST: approve / reject / delete ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $studentDbId = (int)($_POST['student_id'] ?? 0);

    if ($studentDbId > 0 && in_array($action, ['approve','reject','delete'])) {
        try {
            // Load student
            $stu = $pdo->prepare("
                SELECT s.*, p.full_name AS parent_name, p.email AS parent_email
                FROM students s
                LEFT JOIN parents p ON p.id = s.parentId
                WHERE s.id = :id LIMIT 1
            ");
            $stu->execute([':id' => $studentDbId]);
            $student = $stu->fetch();

            if (!$student) throw new Exception("Student not found.");

            $reviewer = $_SESSION['username'] ?? 'admin';

            if ($action === 'delete') {
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
                $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';
                $note = trim($_POST['admin_note'] ?? '');
                $planType = $student['payment_plan'] ?? '';
                $proof = $student['payment_proof'] ?? null;

                $pdo->beginTransaction();

                // Update student approval status
                $pdo->prepare("UPDATE students SET approval_status = :st WHERE id = :id")
                     ->execute([':st' => $newStatus, ':id' => $studentDbId]);

                // Update PCM enrolment if exists
                $pcm = $pdo->prepare("SELECT id FROM pcm_enrolments WHERE student_id = :sid LIMIT 1");
                $pcm->execute([':sid' => $studentDbId]);
                $pcmRow = $pcm->fetch();

                if ($pcmRow) {
                    $pdo->prepare("
                        UPDATE pcm_enrolments SET status=:st, admin_note=:n, reviewed_by=:rb, reviewed_at=NOW()
                        WHERE student_id=:sid
                    ")->execute([':st'=>$newStatus, ':n'=>$note?:null, ':rb'=>$reviewer, ':sid'=>$studentDbId]);

                    if ($newStatus === 'Approved') {
                        pcm_create_fee_rows($pdo, (int)$pcmRow['id'], $studentDbId,
                            (int)($student['parentId'] ?? 0), $planType, $proof);
                    }
                }

                // Handle old-style fee rows too
                $oldFees = $pdo->prepare("SELECT COUNT(*) FROM fees_payments WHERE student_id = :sid");
                $oldFees->execute([':sid' => (string)$studentDbId]);
                if ((int)$oldFees->fetchColumn() > 0) {
                    if ($newStatus === 'Approved') {
                        // Approve first instalment
                        $firstCode = ($planType === 'Term-wise') ? 'TERM1' : (($planType === 'Half-yearly') ? 'HALF1' : 'YEARLY');
                        $pdo->prepare("
                            UPDATE fees_payments SET status='Approved', verified_by=:vb, verified_at=NOW()
                            WHERE student_id=:sid AND installment_code=:code
                        ")->execute([':vb'=>$reviewer, ':sid'=>(string)$studentDbId, ':code'=>$firstCode]);
                    } else {
                        $pdo->prepare("
                            UPDATE fees_payments SET status='Rejected', verified_by=:vb, verified_at=NOW()
                            WHERE student_id=:sid
                        ")->execute([':vb'=>$reviewer, ':sid'=>(string)$studentDbId]);
                    }
                }

                $pdo->commit();

                // Email parent if we have their email
                if (!empty($student['parent_email'])) {
                    pcm_notify_parent_enrolment(
                        $student['parent_email'],
                        $student['parent_name'] ?? 'Parent',
                        $student['student_name'] ?? 'Student',
                        $newStatus, $note
                    );
                }

                $flash = "Enrolment <strong>{$newStatus}</strong> for " . h($student['student_name'] ?? '') . ".";
                $ok = true;
            }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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
           e.id          AS pcm_enrolment_id,
           e.class_id    AS pcm_class_id,
           e.fee_plan    AS pcm_fee_plan,
           e.fee_amount  AS pcm_fee_amount,
           e.payment_ref AS pcm_payment_ref,
           e.proof_path  AS pcm_proof_path,
           e.status      AS pcm_status,
           e.admin_note  AS pcm_admin_note,
           e.reviewed_by AS pcm_reviewed_by,
           e.submitted_at AS pcm_submitted_at,
           cl.class_name AS pcm_class_name
    FROM students s
    LEFT JOIN parents p ON p.id = s.parentId
    LEFT JOIN pcm_enrolments e ON e.student_id = s.id
    LEFT JOIN classes cl ON cl.id = e.class_id
    ORDER BY s.id DESC
")->fetchAll();

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
    <title>Enrolment Management</title>
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
        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">Enrolment Management</h1>
        <p class="text-muted mb-0" style="font-size:.88rem;">Review, approve and manage all student enrolments in one place.</p>
    </div>
    <div>
        <a href="attendanceManagement.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
            <i class="fas fa-clipboard-check mr-1"></i> Attendance
        </a>
    </div>
</div>

<!-- ─── Summary Cards ─── -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card shadow-sm">
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
        <div class="card stat-card shadow-sm">
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
        <div class="card stat-card shadow-sm">
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
        <div class="card stat-card shadow-sm">
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
                <option value="8">Parent</option>
                <option value="5">Reference</option>
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
                        <th>Class / Venue</th>
                        <th>Plan</th>
                        <th>Reference</th>
                        <th>Proof</th>
                        <th>Status</th>
                        <th>Parent</th>
                        <th>Submitted</th>
                        <th style="width:130px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $i => $s):
                    $st = strtolower($s['approval_status'] ?? '');
                    $plan   = $s['pcm_fee_plan']   ?? $s['payment_plan']      ?? null;
                    $amount = $s['pcm_fee_amount']  ?? $s['payment_amount']    ?? 0;
                    $ref    = $s['pcm_payment_ref'] ?? $s['payment_reference'] ?? null;
                    $proof  = $s['pcm_proof_path']  ?? $s['payment_proof']     ?? null;
                    $submitted = $s['pcm_submitted_at'] ?? $s['registration_date'] ?? '';
                    $note = $s['pcm_admin_note'] ?? '';
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><code style="font-size:.82rem;background:#f0f0f0;padding:3px 8px;border-radius:4px;"><?= h($s['student_id'] ?? '') ?></code></td>
                    <td class="font-weight-bold"><?= h($s['student_name'] ?? '') ?></td>
                    <td><span class="text-muted" style="font-size:.82rem;"><?= h($s['pcm_class_name'] ?? $s['class_option'] ?? '—') ?></span></td>
                    <td>
                        <?= h($plan ?? '—') ?>
                        <?php if ($amount): ?><br><small class="text-muted">$<?= number_format((float)$amount, 2) ?></small><?php endif; ?>
                    </td>
                    <td style="max-width:150px;white-space:normal;font-size:.84rem;"><?= h($ref ?? '—') ?></td>
                    <td>
                        <?php if ($proof): ?>
                            <a href="<?= h($proof) ?>" target="_blank" class="btn btn-sm btn-outline-info" style="border-radius:6px;font-size:.75rem;">
                                <i class="fas fa-file-image mr-1"></i> View
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-pill-custom badge-<?= pcm_badge($s['approval_status'] ?? 'Pending') ?>">
                            <?= h($s['approval_status'] ?? 'Pending') ?>
                        </span>
                        <?php if ($note): ?><br><small class="text-muted" title="<?= h($note) ?>"><i class="fas fa-comment-alt"></i> <?= h(mb_strimwidth($note, 0, 30, '…')) ?></small><?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:.86rem;"><?= h($s['parent_name'] ?? '—') ?></div>
                        <small class="text-muted"><?= h($s['parent_email'] ?? '') ?></small>
                    </td>
                    <td style="font-size:.82rem;"><?= $submitted ? date('d M Y', strtotime($submitted)) : '—' ?></td>
                    <td>
                        <div class="act-group">
                            <button class="btn-act act-view toggle-detail" data-id="<?= (int)$s['id'] ?>" title="View details"><i class="fas fa-eye"></i></button>
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
    $plan   = $s['pcm_fee_plan']   ?? $s['payment_plan']      ?? null;
    $amount = $s['pcm_fee_amount']  ?? $s['payment_amount']    ?? 0;
    $ref    = $s['pcm_payment_ref'] ?? $s['payment_reference'] ?? null;
    $proof  = $s['pcm_proof_path']  ?? $s['payment_proof']     ?? null;
    $note = $s['pcm_admin_note'] ?? '';
?>
<div id="detailHtml-<?= (int)$s['id'] ?>" style="display:none;">
    <div class="detail-panel" style="display:block;">
        <div class="row">
            <div class="col-md-4">
                <h6 class="font-weight-bold mb-3" style="color:var(--brand);"><i class="fas fa-user-graduate mr-1"></i> Student Info</h6>
                <div class="dl-row"><div class="dl-label">Student ID</div><div class="dl-value"><?= h($s['student_id'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Full Name</div><div class="dl-value"><?= h($s['student_name'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Date of Birth</div><div class="dl-value"><?= h($s['dob'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Gender</div><div class="dl-value"><?= h($s['gender'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Medical</div><div class="dl-value"><?= h($s['medical_issue'] ?? 'None') ?></div></div>
            </div>
            <div class="col-md-4">
                <h6 class="font-weight-bold mb-3" style="color:var(--brand);"><i class="fas fa-university mr-1"></i> Enrolment</h6>
                <div class="dl-row"><div class="dl-label">Venue / Class</div><div class="dl-value"><?= h($s['pcm_class_name'] ?? $s['class_option'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Payment Plan</div><div class="dl-value"><?= h($plan ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Amount</div><div class="dl-value">$<?= number_format((float)$amount, 2) ?></div></div>
                <div class="dl-row"><div class="dl-label">Reference</div><div class="dl-value"><?= h($ref ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Proof</div><div class="dl-value"><?= $proof ? '<a href="'.h($proof).'" target="_blank">View file</a>' : '—' ?></div></div>
                <div class="dl-row"><div class="dl-label">Status</div><div class="dl-value"><span class="badge badge-<?= pcm_badge($s['approval_status'] ?? '') ?>"><?= h($s['approval_status'] ?? '—') ?></span></div></div>
            </div>
            <div class="col-md-4">
                <h6 class="font-weight-bold mb-3" style="color:var(--brand);"><i class="fas fa-user-friends mr-1"></i> Parent Info</h6>
                <div class="dl-row"><div class="dl-label">Name</div><div class="dl-value"><?= h($s['parent_name'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Email</div><div class="dl-value"><?= h($s['parent_email'] ?? '—') ?></div></div>
                <div class="dl-row"><div class="dl-label">Phone</div><div class="dl-value"><?= h($s['parent_phone'] ?? '—') ?></div></div>
                <?php if ($note): ?>
                <div class="dl-row"><div class="dl-label">Admin Note</div><div class="dl-value"><em><?= h($note) ?></em></div></div>
                <?php endif; ?>
                <?php if ($s['pcm_reviewed_by'] ?? ''): ?>
                <div class="dl-row"><div class="dl-label">Reviewed By</div><div class="dl-value"><?= h($s['pcm_reviewed_by']) ?></div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($st === 'pending'): ?>
<!-- Approve Modal -->
<div class="modal fade" id="approveModal<?= $s['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-check-circle text-success mr-2"></i>Approve Enrolment</h5>
                        <small class="text-muted">This will activate the student and create fee records.</small>
                    </div>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center p-3 mb-3" style="background:#f0faf5;border-radius:10px;">
                        <i class="fas fa-user-graduate text-success mr-3" style="font-size:1.4rem;"></i>
                        <div>
                            <strong><?= h($s['student_name'] ?? '') ?></strong><br>
                            <small class="text-muted"><?= h($s['payment_plan'] ?? '') ?> — $<?= number_format((float)$amount, 2) ?></small>
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
                    <button type="submit" class="btn btn-success"><i class="fas fa-check mr-1"></i> Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal<?= $s['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-times-circle text-danger mr-2"></i>Reject Enrolment</h5>
                        <small class="text-muted">The parent will be notified by email.</small>
                    </div>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center p-3 mb-3" style="background:#fef3f2;border-radius:10px;">
                        <i class="fas fa-user-graduate text-danger mr-3" style="font-size:1.4rem;"></i>
                        <div>
                            <strong><?= h($s['student_name'] ?? '') ?></strong><br>
                            <small class="text-muted"><?= h($s['payment_plan'] ?? '') ?> — $<?= number_format((float)$amount, 2) ?></small>
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
                    <button type="submit" class="btn btn-danger"><i class="fas fa-times mr-1"></i> Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
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
            { targets: [10], orderable: false },
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

    // Filter pills
    $('.filter-pill').on('click', function(){
        $('.filter-pill').removeClass('active-all active-pending active-approved active-rejected')
            .addClass(function(){ return 'btn-outline-' + ($(this).data('status')==='Pending'?'warning':$(this).data('status')==='Approved'?'success':$(this).data('status')==='Rejected'?'danger':'secondary'); });
        var s = $(this).data('status');
        $(this).removeClass('btn-outline-warning btn-outline-success btn-outline-danger btn-outline-secondary');
        $(this).addClass('active-' + s.toLowerCase());

        if (s === 'all') dt.column(7).search('').draw();
        else dt.column(7).search('^'+s+'$', true, false).draw();
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
});
</script>
</body>
</html>
