<?php
// student-profile.php — Full profile view for a single student: bio data,
// parent details, enrollment/class, fees payment history, attendance
// history, and assessment/progress reports, with an edit option.
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$pdo = pcm_pdo();
$studentDbId = (int)($_GET['id'] ?? 0);
if ($studentDbId <= 0) {
    header("Location: dzoClassManagement");
    exit;
}

$flash = '';
$ok = false;
$reviewer = (string)($_SESSION['username'] ?? 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_update_child_details') {
    verify_csrf();
    try {
        $result = pcm_admin_update_child_details($pdo, (int)($_POST['student_id'] ?? 0), $_POST, $reviewer);
        $flash = $result['flash'];
        $ok = $result['ok'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flash = 'Error: ' . $e->getMessage();
        $ok = false;
    }
    $_SESSION['student_profile_flash'] = ['message' => $flash, 'ok' => $ok];
    header('Location: student-profile?id=' . $studentDbId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_fee_row') {
    verify_csrf();
    try {
        $result = pcm_admin_update_fee_row($pdo, (int)($_POST['payment_id'] ?? 0), $_POST, $reviewer);
        $flash = $result['flash'];
        $ok = $result['ok'];
    } catch (Throwable $e) {
        $flash = 'Error: ' . $e->getMessage();
        $ok = false;
    }
    $_SESSION['student_profile_flash'] = ['message' => $flash, 'ok' => $ok];
    header('Location: student-profile?id=' . $studentDbId);
    exit;
}

if (isset($_SESSION['student_profile_flash'])) {
    $saved = (array)$_SESSION['student_profile_flash'];
    unset($_SESSION['student_profile_flash']);
    $flash = (string)($saved['message'] ?? '');
    $ok = !empty($saved['ok']);
}

$campusChoices = pcm_campus_choice_labels();
$latestClassJoin = pcm_latest_class_assignment_join('s.id', 'ca', 'c');
$allClasses = $pdo->query("SELECT id, class_name FROM classes WHERE active = 1 ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT s.*,
           p.id AS parent_db_id, p.full_name AS parent_name, p.email AS parent_email,
           p.phone AS parent_phone, p.address AS parent_address, p.status AS parent_status,
           e.id AS enrolment_id, e.fee_plan AS enrolment_fee_plan, e.campus_preference,
           e.start_term AS enrolment_start_term, e.fee_amount, e.status AS enrolment_status,
           e.payment_ref, e.submitted_at AS enrolment_submitted_at,
           ca.class_id AS assigned_class_id, c.class_name
    FROM students s
    LEFT JOIN parents p ON p.id = s.parent_id
    LEFT JOIN pcm_enrolments e ON e.student_id = s.id
    {$latestClassJoin}
    WHERE s.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $studentDbId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: dzoClassManagement");
    exit;
}

// Fee payment history
$feePayments = $pdo->prepare("
    SELECT * FROM pcm_fee_payments WHERE student_id = :id ORDER BY id ASC
");
$feePayments->execute([':id' => $studentDbId]);
$feePayments = $feePayments->fetchAll(PDO::FETCH_ASSOC);
$totalDue = 0.0;
$totalPaid = 0.0;
foreach ($feePayments as $fp) {
    $totalDue += (float)($fp['due_amount'] ?? 0);
    $totalPaid += (float)($fp['paid_amount'] ?? 0);
}

// Attendance history + summary
$attendanceRows = $pdo->prepare("
    SELECT a.*, c.class_name
    FROM attendance a
    LEFT JOIN classes c ON c.id = a.class_id
    WHERE a.student_id = :id
    ORDER BY a.attendance_date DESC
    LIMIT 60
");
$attendanceRows->execute([':id' => $studentDbId]);
$attendanceRows = $attendanceRows->fetchAll(PDO::FETCH_ASSOC);

$attendanceSummary = $pdo->prepare("
    SELECT status, COUNT(*) AS total FROM attendance WHERE student_id = :id GROUP BY status
");
$attendanceSummary->execute([':id' => $studentDbId]);
$presentCount = 0;
$absentCount = 0;
$otherCount = 0;
foreach ($attendanceSummary->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $s = strtolower((string)($row['status'] ?? ''));
    if ($s === 'present') $presentCount = (int)$row['total'];
    elseif ($s === 'absent') $absentCount = (int)$row['total'];
    else $otherCount += (int)$row['total'];
}
$attendanceTotal = $presentCount + $absentCount + $otherCount;
$attendanceRate = $attendanceTotal > 0 ? round(($presentCount / $attendanceTotal) * 100, 1) : null;

// Assessment / progress reports
$reports = $pdo->prepare("
    SELECT r.*, c.class_name
    FROM classroom_reports r
    LEFT JOIN classes c ON c.id = r.class_id
    WHERE r.student_id = :id
    ORDER BY r.created_at DESC
");
$reports->execute([':id' => $studentDbId]);
$reports = $reports->fetchAll(PDO::FETCH_ASSOC);

$approval = (string)($student['approval_status'] ?? 'Pending');
$lifeStatus = (string)($student['status'] ?? 'Active');
$isPastStudent = strtolower($lifeStatus) === 'past';
$hasEnrolment = !empty($student['enrolment_id']);
$curStartTerm = pcm_normalize_start_term($student['enrolment_start_term'] ?? 1);
$curPlan = (string)($student['enrolment_fee_plan'] ?? '');
$allowedTerms = [];
for ($t = 1; $t <= 4; $t++) {
    if (pcm_plan_allowed_for_start_term($curPlan, $t)) $allowedTerms[] = $t;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Student Profile — <?= h((string)($student['student_name'] ?? '')) ?></title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .profile-header { border-left: 4px solid #881b12; }
        .dl-row { display: flex; padding: 6px 0; border-bottom: 1px solid #f1f1f1; font-size: .88rem; }
        .dl-row:last-child { border-bottom: 0; }
        .dl-label { flex: 0 0 160px; font-weight: 600; color: #6c757d; }
        .dl-value { flex: 1; color: #2f2f2f; }
        .section-card .card-header { background: #fff; border-bottom: 2px solid #f1f3f9; }
        .stat-mini { text-align: center; padding: 10px; }
        .stat-mini .num { font-size: 1.4rem; font-weight: 800; color: #4e332f; line-height: 1; }
        .stat-mini .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #858796; font-weight: 700; margin-top: 4px; }
        .report-card { border-left: 3px solid #4e73df; background: #f8f9fc; border-radius: 6px; padding: 12px 16px; margin-bottom: 10px; }
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
    Swal.fire({icon:'<?= $ok ? "success" : "error" ?>',html:<?= json_encode($flash) ?>,timer:2500,showConfirmButton:false});
});
</script>
<?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
    <a href="dzoClassManagement" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left mr-1"></i> Back to Manage Children
    </a>
    <button type="button" class="btn btn-sm btn-primary mt-2 mt-md-0" id="openEditBtn">
        <i class="fas fa-user-edit mr-1"></i> Edit Details
    </button>
</div>

<div class="card shadow-sm profile-header mb-4">
    <div class="card-body">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h1 class="h4 mb-1 text-gray-800"><?= h((string)($student['student_name'] ?? '')) ?></h1>
                <div class="text-muted small">
                    <code><?= h((string)($student['student_id'] ?? '')) ?></code>
                    &nbsp;&middot;&nbsp; <?= h((string)($student['class_name'] ?? 'No class assigned')) ?>
                    &nbsp;&middot;&nbsp; <?= h(pcm_campus_selection_label((string)($student['campus_preference'] ?? ''))) ?>
                </div>
            </div>
            <div class="mt-2 mt-md-0">
                <span class="badge badge-<?= pcm_badge($approval) ?> mr-1"><?= h($approval) ?></span>
                <?php if ($isPastStudent): ?>
                    <span class="badge badge-secondary">Past</span>
                <?php else: ?>
                    <span class="badge badge-success">Active</span>
                <?php endif; ?>
                <?php if ($hasEnrolment): ?>
                    <span class="badge badge-<?= pcm_badge((string)$student['enrolment_status']) ?>"><?= h((string)$student['enrolment_status']) ?> Enrolment</span>
                <?php endif; ?>
            </div>
        </div>
        <hr>
        <div class="row">
            <div class="col-6 col-md-3 stat-mini">
                <div class="num">$<?= number_format($totalPaid, 2) ?></div>
                <div class="lbl">Paid</div>
            </div>
            <div class="col-6 col-md-3 stat-mini">
                <div class="num">$<?= number_format($totalDue, 2) ?></div>
                <div class="lbl">Total Due</div>
            </div>
            <div class="col-6 col-md-3 stat-mini">
                <div class="num"><?= $attendanceRate !== null ? $attendanceRate . '%' : '—' ?></div>
                <div class="lbl">Attendance Rate</div>
            </div>
            <div class="col-6 col-md-3 stat-mini">
                <div class="num"><?= count($reports) ?></div>
                <div class="lbl">Reports</div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm section-card h-100">
            <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-graduate mr-1"></i>Bio Data</h6></div>
            <div class="card-body">
                <div class="dl-row"><div class="dl-label">Student ID</div><div class="dl-value"><?= h((string)($student['student_id'] ?? '—')) ?></div></div>
                <div class="dl-row"><div class="dl-label">Full Name</div><div class="dl-value"><?= h((string)($student['student_name'] ?? '—')) ?></div></div>
                <div class="dl-row"><div class="dl-label">Date of Birth</div><div class="dl-value"><?= !empty($student['dob']) ? date('d M Y', strtotime((string)$student['dob'])) : '—' ?></div></div>
                <div class="dl-row"><div class="dl-label">Gender</div><div class="dl-value"><?= h((string)($student['gender'] ?? '—')) ?></div></div>
                <div class="dl-row"><div class="dl-label">Medical</div><div class="dl-value"><?= h((string)($student['medical_issue'] ?? 'None')) ?></div></div>
                <div class="dl-row"><div class="dl-label">Registered</div><div class="dl-value"><?= !empty($student['registration_date']) ? date('d M Y', strtotime((string)$student['registration_date'])) : '—' ?></div></div>
                <div class="dl-row"><div class="dl-label">Approval Status</div><div class="dl-value"><span class="badge badge-<?= pcm_badge($approval) ?>"><?= h($approval) ?></span></div></div>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm section-card h-100">
            <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-friends mr-1"></i>Parent Details</h6></div>
            <div class="card-body">
                <div class="dl-row"><div class="dl-label">Name</div><div class="dl-value"><?= h((string)($student['parent_name'] ?? '—')) ?></div></div>
                <div class="dl-row"><div class="dl-label">Email</div><div class="dl-value"><?= h((string)($student['parent_email'] ?? '—')) ?></div></div>
                <div class="dl-row"><div class="dl-label">Phone</div><div class="dl-value"><?= h((string)($student['parent_phone'] ?? '—')) ?></div></div>
                <div class="dl-row"><div class="dl-label">Address</div><div class="dl-value"><?= h((string)($student['parent_address'] ?? '—')) ?></div></div>
                <div class="dl-row"><div class="dl-label">Account Status</div><div class="dl-value"><?= h((string)($student['parent_status'] ?? '—')) ?></div></div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm section-card h-100">
            <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-file-signature mr-1"></i>Enrollment &amp; Class</h6></div>
            <div class="card-body">
                <?php if (!$hasEnrolment): ?>
                    <p class="text-muted mb-0">No enrolment on file yet.</p>
                <?php else: ?>
                    <div class="dl-row"><div class="dl-label">Fee Plan</div><div class="dl-value"><?= h($curPlan) ?></div></div>
                    <div class="dl-row"><div class="dl-label">Starting Term</div><div class="dl-value">Term <?= (int)$curStartTerm ?></div></div>
                    <div class="dl-row"><div class="dl-label">Fee Amount</div><div class="dl-value">$<?= number_format((float)($student['fee_amount'] ?? 0), 2) ?></div></div>
                    <div class="dl-row"><div class="dl-label">Campus</div><div class="dl-value"><?= h(pcm_campus_selection_label((string)($student['campus_preference'] ?? ''))) ?></div></div>
                    <div class="dl-row"><div class="dl-label">Class</div><div class="dl-value"><?= h((string)($student['class_name'] ?? 'Not assigned')) ?></div></div>
                    <div class="dl-row"><div class="dl-label">Payment Ref</div><div class="dl-value"><?= h((string)($student['payment_ref'] ?? '—')) ?></div></div>
                    <div class="dl-row"><div class="dl-label">Enrolment Status</div><div class="dl-value"><span class="badge badge-<?= pcm_badge((string)$student['enrolment_status']) ?>"><?= h((string)$student['enrolment_status']) ?></span></div></div>
                    <div class="dl-row"><div class="dl-label">Submitted</div><div class="dl-value"><?= !empty($student['enrolment_submitted_at']) ? date('d M Y', strtotime((string)$student['enrolment_submitted_at'])) : '—' ?></div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm section-card h-100">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-calendar-check mr-1"></i>Attendance</h6>
                <a href="attendance-records" class="small">View Full Records</a>
            </div>
            <div class="card-body">
                <div class="row mb-2">
                    <div class="col-4 stat-mini"><div class="num text-success"><?= $presentCount ?></div><div class="lbl">Present</div></div>
                    <div class="col-4 stat-mini"><div class="num text-danger"><?= $absentCount ?></div><div class="lbl">Absent</div></div>
                    <div class="col-4 stat-mini"><div class="num"><?= $attendanceRate !== null ? $attendanceRate . '%' : '—' ?></div><div class="lbl">Rate</div></div>
                </div>
                <?php if (empty($attendanceRows)): ?>
                    <p class="text-muted mb-0">No attendance records yet.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height:260px;overflow-y:auto;">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="thead-light"><tr><th>Date</th><th>Class</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($attendanceRows as $ar): ?>
                                <?php
                                    $aStatus = strtolower((string)($ar['status'] ?? ''));
                                    $aBadge = $aStatus === 'present' ? 'success' : ($aStatus === 'absent' ? 'danger' : 'warning');
                                ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime((string)$ar['attendance_date'])) ?></td>
                                    <td><?= h((string)($ar['class_name'] ?? '—')) ?></td>
                                    <td><span class="badge badge-<?= $aBadge ?>"><?= h((string)($ar['status'] ?? '—')) ?></span></td>
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

<div class="card shadow-sm section-card mb-4">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-money-check-alt mr-1"></i>Fees Payment History</h6></div>
    <div class="card-body">
        <?php if (empty($feePayments)): ?>
            <p class="text-muted mb-0">No fee instalments on file yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="thead-light">
                        <tr><th>Instalment</th><th>Due</th><th>Paid</th><th>Reference</th><th>Proof</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($feePayments as $fp): ?>
                        <tr>
                            <td class="font-weight-bold"><?= h((string)($fp['instalment_label'] ?? '—')) ?></td>
                            <td>$<?= number_format((float)($fp['due_amount'] ?? 0), 2) ?></td>
                            <td>$<?= number_format((float)($fp['paid_amount'] ?? 0), 2) ?></td>
                            <td><?= h((string)($fp['payment_ref'] ?? '—')) ?></td>
                            <td><?= !empty($fp['proof_path']) ? '<a href="' . h((string)$fp['proof_path']) . '" target="_blank">View</a>' : '—' ?></td>
                            <td><span class="badge badge-<?= pcm_badge((string)($fp['status'] ?? '')) ?>"><?= h((string)($fp['status'] ?? '—')) ?></span></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary js-edit-fee-btn"
                                    data-id="<?= (int)$fp['id'] ?>"
                                    data-label="<?= h((string)($fp['instalment_label'] ?? '')) ?>"
                                    data-due="<?= h(number_format((float)($fp['due_amount'] ?? 0), 2, '.', '')) ?>"
                                    data-paid="<?= h(number_format((float)($fp['paid_amount'] ?? 0), 2, '.', '')) ?>"
                                    data-ref="<?= h((string)($fp['payment_ref'] ?? '')) ?>"
                                    data-status="<?= h((string)($fp['status'] ?? 'Unpaid')) ?>"
                                ><i class="fas fa-edit"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm section-card mb-4">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-clipboard-list mr-1"></i>Assessment / Progress Reports</h6></div>
    <div class="card-body">
        <?php if (empty($reports)): ?>
            <p class="text-muted mb-0">No progress reports yet.</p>
        <?php else: ?>
            <?php foreach ($reports as $rep): ?>
                <div class="report-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?= h((string)($rep['report_title'] ?? '')) ?></strong>
                            <span class="badge badge-info ml-1"><?= h((string)($rep['report_type'] ?? '')) ?></span>
                        </div>
                        <small class="text-muted"><?= date('d M Y', strtotime((string)$rep['created_at'])) ?></small>
                    </div>
                    <div class="small text-muted mb-1">
                        <?= h((string)($rep['class_name'] ?? '')) ?> &middot; by <?= h((string)($rep['created_by_name'] ?? 'Teacher')) ?>
                    </div>
                    <div style="white-space:pre-wrap;font-size:.9rem;"><?= h((string)($rep['feedback_text'] ?? '')) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</div>
</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editChildModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="editChildForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="admin_update_child_details">
                <input type="hidden" name="student_id" value="<?= (int)$studentDbId ?>">
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
                            <input type="text" class="form-control" value="<?= h((string)($student['student_id'] ?? '')) ?>" readonly>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Child Name</label>
                            <input type="text" class="form-control" name="student_name" value="<?= h((string)($student['student_name'] ?? '')) ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Date of Birth</label>
                            <input type="date" class="form-control" name="dob" value="<?= h((string)($student['dob'] ?? '')) ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Gender</label>
                            <?php $genderVal = (string)($student['gender'] ?? ''); ?>
                            <select class="form-control" name="gender">
                                <option value="" <?= $genderVal === '' ? 'selected' : '' ?>>--</option>
                                <option value="Male" <?= $genderVal === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $genderVal === 'Female' ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= $genderVal === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Medical</label>
                            <input type="text" class="form-control" name="medical_issue" value="<?= h((string)($student['medical_issue'] ?? '')) ?>" maxlength="500">
                        </div>
                    </div>

                    <?php if ($hasEnrolment): ?>
                    <hr>
                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-file-signature mr-1"></i>Enrollment &amp; Class</h6>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Fee Plan</label>
                            <select class="form-control" name="fee_plan" id="editFeePlan">
                                <?php foreach (['Term-wise', 'Half-yearly', 'Yearly'] as $planOpt): ?>
                                    <option value="<?= h($planOpt) ?>" <?= $curPlan === $planOpt ? 'selected' : '' ?>><?= h($planOpt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Starting Term</label>
                            <select class="form-control" name="start_term" id="editStartTerm">
                                <?php for ($termNo = 1; $termNo <= 4; $termNo++): ?>
                                    <option value="<?= $termNo ?>" <?= !in_array($termNo, $allowedTerms, true) ? 'hidden' : '' ?> <?= $curStartTerm === $termNo ? 'selected' : '' ?>>Term <?= $termNo ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <small class="form-text text-muted d-block mb-3">Changing plan or term recalculates the fee amount and regenerates the instalment schedule if no instalments have been paid or reviewed yet.</small>

                    <div class="form-group">
                        <label>Campus</label>
                        <?php $curCampus = array_filter(array_map('trim', explode(',', (string)($student['campus_preference'] ?? '')))); ?>
                        <?php foreach ($campusChoices as $ck => $cl): ?>
                            <div class="custom-control custom-checkbox custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="editCampus_<?= h($ck) ?>" name="campus_choice[]" value="<?= h($ck) ?>" <?= in_array($ck, $curCampus, true) ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="editCampus_<?= h($ck) ?>"><?= h($cl) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-group">
                        <label>Class</label>
                        <select class="form-control" name="class_id">
                            <option value="">— Not assigned —</option>
                            <?php foreach ($allClasses as $cl): ?>
                                <option value="<?= (int)$cl['id'] ?>" <?= (int)($student['assigned_class_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>><?= h((string)$cl['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <hr>
                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-user-friends mr-1"></i>Parent Contact Details</h6>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Parent Name</label>
                            <input type="text" class="form-control" name="parent_name" value="<?= h((string)($student['parent_name'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Parent Phone</label>
                            <input type="text" class="form-control" name="parent_phone" value="<?= h((string)($student['parent_phone'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Parent Email</label>
                            <input type="email" class="form-control" name="parent_email" value="<?= h((string)($student['parent_email'] ?? '')) ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Parent Address</label>
                            <input type="text" class="form-control" name="parent_address" value="<?= h((string)($student['parent_address'] ?? '')) ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save Details</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Fee Row Modal -->
<div class="modal fade" id="editFeeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="editFeeForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_fee_row">
                <input type="hidden" name="payment_id" id="editFeePaymentId" value="">
                <div class="modal-header">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-money-check-alt text-primary mr-2"></i>Edit Fee Instalment — <span id="editFeeLabel"></span></h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Due Amount</label>
                            <input type="number" min="0" step="0.01" class="form-control" name="due_amount" id="editFeeDue" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Paid Amount</label>
                            <input type="number" min="0" step="0.01" class="form-control" name="paid_amount" id="editFeePaid" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Payment Reference</label>
                        <input type="text" class="form-control" name="payment_ref" id="editFeeRef">
                    </div>
                    <div class="form-group mb-0">
                        <label>Status</label>
                        <select class="form-control" name="status" id="editFeeStatus">
                            <option value="Unpaid">Unpaid</option>
                            <option value="Pending">Pending</option>
                            <option value="Verified">Verified</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('openEditBtn').addEventListener('click', function() {
    $('#editChildModal').modal('show');
});

document.querySelectorAll('.js-edit-fee-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var d = this.dataset;
        document.getElementById('editFeePaymentId').value = d.id;
        document.getElementById('editFeeLabel').textContent = d.label || '';
        document.getElementById('editFeeDue').value = d.due || '0';
        document.getElementById('editFeePaid').value = d.paid || '0';
        document.getElementById('editFeeRef').value = d.ref || '';
        document.getElementById('editFeeStatus').value = d.status || 'Unpaid';
        $('#editFeeModal').modal('show');
    });
});

// Keep the Starting Term options in sync with the selected Fee Plan
// (same Term-wise/Half-yearly/Yearly x Term 1-4 matrix used across the
// enrollment flows -- see pcm_plan_allowed_for_start_term()).
(function() {
    var planSelect = document.getElementById('editFeePlan');
    var termSelect = document.getElementById('editStartTerm');
    if (!planSelect || !termSelect) return;

    var allowedByPlan = {
        'Term-wise': ['1', '2', '3', '4'],
        'Half-yearly': ['1', '3'],
        'Yearly': ['1', '2']
    };

    function applyAllowedTerms() {
        var allowed = allowedByPlan[planSelect.value] || ['1', '2', '3', '4'];
        var options = termSelect.querySelectorAll('option');
        var currentStillValid = false;
        options.forEach(function(opt) {
            var isAllowed = allowed.indexOf(opt.value) !== -1;
            opt.hidden = !isAllowed;
            if (isAllowed && opt.value === termSelect.value) currentStillValid = true;
        });
        if (!currentStillValid) {
            termSelect.value = allowed[0];
        }
    }

    planSelect.addEventListener('change', applyAllowedTerms);
})();
</script>
</body>
</html>
