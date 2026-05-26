<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/module_access.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role() && !is_website_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$canClassesManage = function_exists('bbcc_can') ? bbcc_can('classes_attendance', 'manage') : false;
$canEnrollmentApprove = function_exists('bbcc_can') ? bbcc_can('enrollment', 'approve') : false;
$canFeesManage = function_exists('bbcc_can') ? bbcc_can('fees_payments', 'manage') : false;
$canCommunicationManage = function_exists('bbcc_can') ? bbcc_can('communication', 'manage') : false;
$canKioskManage = function_exists('bbcc_can') ? bbcc_can('kiosk', 'manage') : false;
$canAttendanceView = function_exists('bbcc_can')
    ? (bbcc_can('classes_attendance', 'view') || bbcc_can('classes_attendance', 'mark') || bbcc_can('classes_attendance', 'edit') || bbcc_can('classes_attendance', 'manage'))
    : $canClassesManage;
$canAccessDzoDashboard = ($canClassesManage || $canEnrollmentApprove || $canFeesManage || $canCommunicationManage || $canKioskManage);

if (!$canAccessDzoDashboard) {
    header("Location: unauthorized");
    exit;
}

function dzo_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dzo_badge_class($status): string {
    $status = strtolower(trim((string)$status));
    if ($status === 'pending') return 'warning';
    if ($status === 'verified' || $status === 'approved') return 'success';
    if ($status === 'rejected') return 'danger';
    if ($status === 'unpaid') return 'secondary';
    return 'secondary';
}

function dzo_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :table_name");
        $stmt->execute([':table_name' => $table]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        return false;
    }
}

function dzo_scalar(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

$today = date('Y-m-d');
$stats = [
    'pending_enrolments' => 0,
    'approved_students' => 0,
    'active_classes' => 0,
    'today_attendance_marks' => 0,
    'pending_fee_verifications' => 0,
    'unpaid_fee_items' => 0,
];

if (dzo_table_exists($pdo, 'pcm_enrolments')) {
    $stats['pending_enrolments'] = dzo_scalar(
        $pdo,
        "SELECT COUNT(*) FROM pcm_enrolments WHERE LOWER(COALESCE(status,'')) = 'pending'"
    );
}

if (dzo_table_exists($pdo, 'students')) {
    $stats['approved_students'] = dzo_scalar(
        $pdo,
        "SELECT COUNT(*) FROM students WHERE LOWER(COALESCE(approval_status,'')) = 'approved'"
    );
}

if (dzo_table_exists($pdo, 'classes')) {
    $stats['active_classes'] = dzo_scalar(
        $pdo,
        "SELECT COUNT(*) FROM classes WHERE COALESCE(active, 1) = 1"
    );
}

if (dzo_table_exists($pdo, 'attendance')) {
    $stats['today_attendance_marks'] = dzo_scalar(
        $pdo,
        "SELECT COUNT(*) FROM attendance WHERE attendance_date = :today",
        [':today' => $today]
    );
}

if (dzo_table_exists($pdo, 'pcm_fee_payments')) {
    $stats['pending_fee_verifications'] = dzo_scalar(
        $pdo,
        "SELECT COUNT(*) FROM pcm_fee_payments WHERE LOWER(COALESCE(status,'')) = 'pending'"
    );
    $stats['unpaid_fee_items'] = dzo_scalar(
        $pdo,
        "SELECT COUNT(*) FROM pcm_fee_payments WHERE LOWER(COALESCE(status,'')) IN ('unpaid','rejected')"
    );
}

$summaryDate = date('Y-m-d');
if (isset($_GET['summary_date'])) {
    $candidateDate = trim((string)$_GET['summary_date']);
    $dt = DateTime::createFromFormat('Y-m-d', $candidateDate);
    if ($dt && $dt->format('Y-m-d') === $candidateDate) {
        $summaryDate = $candidateDate;
    }
}

$campusLabels = function_exists('pcm_campus_choice_labels')
    ? pcm_campus_choice_labels()
    : ['c1' => 'Campus 1', 'c2' => 'Campus 2'];

$campusAttendanceSummary = [
    'c1' => [
        'label' => (string)($campusLabels['c1'] ?? 'Campus 1'),
        'rows' => [],
        'totals' => ['present' => 0, 'absent' => 0, 'grand' => 0],
    ],
    'c2' => [
        'label' => (string)($campusLabels['c2'] ?? 'Campus 2'),
        'rows' => [],
        'totals' => ['present' => 0, 'absent' => 0, 'grand' => 0],
    ],
];
$campusAttendanceOverall = ['present' => 0, 'absent' => 0, 'grand' => 0];

if (dzo_table_exists($pdo, 'attendance') && dzo_table_exists($pdo, 'classes')) {
    $stmtCampusAttendance = $pdo->prepare("
        SELECT
            LOWER(COALESCE(NULLIF(TRIM(c.campus_key), ''), 'c1')) AS campus_key,
            COALESCE(NULLIF(TRIM(c.class_name), ''), 'Unassigned Class') AS class_name,
            SUM(CASE WHEN LOWER(COALESCE(a.status, '')) = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN LOWER(COALESCE(a.status, '')) = 'absent' THEN 1 ELSE 0 END) AS absent_count
        FROM attendance a
        LEFT JOIN classes c ON c.id = a.class_id
        WHERE DATE(a.attendance_date) = :summary_date
        GROUP BY campus_key, class_name
        ORDER BY campus_key ASC, class_name ASC
    ");
    $stmtCampusAttendance->execute([':summary_date' => $summaryDate]);
    $campusRows = $stmtCampusAttendance->fetchAll(PDO::FETCH_ASSOC);

    foreach ($campusRows as $row) {
        $campusKey = strtolower((string)($row['campus_key'] ?? 'c1'));
        if (!isset($campusAttendanceSummary[$campusKey])) continue;

        $present = (int)($row['present_count'] ?? 0);
        $absent = (int)($row['absent_count'] ?? 0);
        $grand = $present + $absent;

        $campusAttendanceSummary[$campusKey]['rows'][] = [
            'class_name' => (string)($row['class_name'] ?? 'Class'),
            'present' => $present,
            'absent' => $absent,
            'grand' => $grand,
        ];
        $campusAttendanceSummary[$campusKey]['totals']['present'] += $present;
        $campusAttendanceSummary[$campusKey]['totals']['absent'] += $absent;
        $campusAttendanceSummary[$campusKey]['totals']['grand'] += $grand;
    }

    foreach ($campusAttendanceSummary as $summary) {
        $campusAttendanceOverall['present'] += (int)($summary['totals']['present'] ?? 0);
        $campusAttendanceOverall['absent'] += (int)($summary['totals']['absent'] ?? 0);
        $campusAttendanceOverall['grand'] += (int)($summary['totals']['grand'] ?? 0);
    }
}

$campusKioskSummary = [
    'c1' => [
        'label' => (string)($campusLabels['c1'] ?? 'Campus 1'),
        'rows' => [],
        'totals' => ['registered' => 0, 'sign_in' => 0, 'sign_out' => 0, 'grand' => 0],
    ],
    'c2' => [
        'label' => (string)($campusLabels['c2'] ?? 'Campus 2'),
        'rows' => [],
        'totals' => ['registered' => 0, 'sign_in' => 0, 'sign_out' => 0, 'grand' => 0],
    ],
];
$campusKioskOverall = ['registered' => 0, 'sign_in' => 0, 'sign_out' => 0, 'grand' => 0];

if (
    dzo_table_exists($pdo, 'classes') &&
    dzo_table_exists($pdo, 'class_assignments') &&
    dzo_table_exists($pdo, 'pcm_kiosk_log')
) {
    $stmtCampusKiosk = $pdo->prepare("
        SELECT
            LOWER(COALESCE(NULLIF(TRIM(c.campus_key), ''), 'c1')) AS campus_key,
            COALESCE(NULLIF(TRIM(c.class_name), ''), 'Unassigned Class') AS class_name,
            COUNT(DISTINCT ca.student_id) AS registered_count,
            SUM(CASE WHEN k.time_in IS NOT NULL THEN 1 ELSE 0 END) AS sign_in_count,
            SUM(CASE WHEN k.time_out IS NOT NULL THEN 1 ELSE 0 END) AS sign_out_count
        FROM classes c
        LEFT JOIN class_assignments ca ON ca.class_id = c.id
        LEFT JOIN pcm_kiosk_log k
            ON k.child_id = ca.student_id
           AND k.log_date = :summary_date
        GROUP BY c.id, campus_key, class_name
        ORDER BY campus_key ASC, class_name ASC
    ");
    $stmtCampusKiosk->execute([':summary_date' => $summaryDate]);
    $kioskRows = $stmtCampusKiosk->fetchAll(PDO::FETCH_ASSOC);

    foreach ($kioskRows as $row) {
        $campusKey = strtolower((string)($row['campus_key'] ?? 'c1'));
        if (!isset($campusKioskSummary[$campusKey])) continue;

        $registered = (int)($row['registered_count'] ?? 0);
        $signIn = (int)($row['sign_in_count'] ?? 0);
        $signOut = (int)($row['sign_out_count'] ?? 0);
        $grand = $signIn + $signOut;

        $campusKioskSummary[$campusKey]['rows'][] = [
            'class_name' => (string)($row['class_name'] ?? 'Class'),
            'registered' => $registered,
            'sign_in' => $signIn,
            'sign_out' => $signOut,
            'grand' => $grand,
        ];
        $campusKioskSummary[$campusKey]['totals']['registered'] += $registered;
        $campusKioskSummary[$campusKey]['totals']['sign_in'] += $signIn;
        $campusKioskSummary[$campusKey]['totals']['sign_out'] += $signOut;
        $campusKioskSummary[$campusKey]['totals']['grand'] += $grand;
    }

    foreach ($campusKioskSummary as $summary) {
        $campusKioskOverall['registered'] += (int)($summary['totals']['registered'] ?? 0);
        $campusKioskOverall['sign_in'] += (int)($summary['totals']['sign_in'] ?? 0);
        $campusKioskOverall['sign_out'] += (int)($summary['totals']['sign_out'] ?? 0);
        $campusKioskOverall['grand'] += (int)($summary['totals']['grand'] ?? 0);
    }
}

$feeCollected = 0.0;
$feePending = 0.0;
$feeTotal = 0.0;
$feePendingCount = 0;
$feeUnpaidCount = 0;
$feeRejectedCount = 0;
$feeVerifiedCount = 0;
$feeTotalItems = 0;
$dashboardFeePayments = [];
$recentAttendanceRecords = [];

if (dzo_table_exists($pdo, 'pcm_fee_payments')) {
    $stmtFees = $pdo->query("
        SELECT
            COALESCE(SUM(due_amount), 0) AS total_due,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'verified' THEN paid_amount ELSE 0 END), 0) AS collected,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(status,'')) IN ('unpaid','pending','rejected') THEN due_amount ELSE 0 END), 0) AS pending,
            SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'verified' THEN 1 ELSE 0 END) AS verified_count,
            SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
            SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            COUNT(*) AS total_items
        FROM pcm_fee_payments
        WHERE COALESCE(due_amount, 0) > 0
    ");
    $feeRow = $stmtFees->fetch(PDO::FETCH_ASSOC) ?: [];
    $feeTotal = (float)($feeRow['total_due'] ?? 0);
    $feeCollected = (float)($feeRow['collected'] ?? 0);
    $feePending = (float)($feeRow['pending'] ?? 0);
    $feeVerifiedCount = (int)($feeRow['verified_count'] ?? 0);
    $feePendingCount = (int)($feeRow['pending_count'] ?? 0);
    $feeUnpaidCount = (int)($feeRow['unpaid_count'] ?? 0);
    $feeRejectedCount = (int)($feeRow['rejected_count'] ?? 0);
    $feeTotalItems = (int)($feeRow['total_items'] ?? 0);

    $stmtFeeTable = $pdo->query("
        SELECT
            f.id,
            s.student_name,
            COALESCE(s.student_id, CONCAT('ID-', s.id)) AS public_student_id,
            f.instalment_label,
            COALESCE(f.due_amount, 0) AS due_amount,
            f.status
        FROM pcm_fee_payments f
        LEFT JOIN students s ON s.id = f.student_id
        WHERE COALESCE(f.due_amount, 0) > 0
        ORDER BY f.id DESC
        LIMIT 8
    ");
    $dashboardFeePayments = $stmtFeeTable->fetchAll(PDO::FETCH_ASSOC);
}

if (
    $canAttendanceView &&
    dzo_table_exists($pdo, 'attendance') &&
    dzo_table_exists($pdo, 'students')
) {
    $stmtRecentAttendance = $pdo->query("
        SELECT a.attendance_date, s.student_name, s.student_id, c.class_name, a.status
        FROM attendance a
        INNER JOIN students s ON s.id = a.student_id
        LEFT JOIN classes c ON c.id = a.class_id
        ORDER BY a.attendance_date DESC, a.id DESC
        LIMIT 15
    ");
    $recentAttendanceRecords = $stmtRecentAttendance->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dzo Class Dashboard</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .dzo-stat-card { border-left: 0.28rem solid #881b12; }
        .dzo-stat-label { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; color: #6c757d; }
        .dzo-stat-value { font-size: 1.65rem; font-weight: 700; color: #1f2937; line-height: 1.15; }
        .dzo-section-title { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .dzo-section-title span { font-size: 0.95rem; font-weight: 700; color: #1a1a2e; }
        .dzo-section-title a { font-size: 0.8rem; font-weight: 600; color: #4e73df; text-decoration: none; }
        .dzo-section-title a:hover { text-decoration: underline; }
        .dzo-mini-meta { font-size: 0.78rem; color: #7a7c84; }
        .dzo-summary-table thead th { font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.02em; color: #6c757d; border-top: 0; }
        .dzo-summary-table td { font-size: 0.84rem; vertical-align: middle; }
        .dzo-fee-panel { border: 1px solid #e8ecf4; background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%); }
        .dzo-fee-hero { display: flex; align-items: center; gap: 16px; }
        .dzo-fee-ring {
            --pct: 0;
            width: 108px;
            height: 108px;
            border-radius: 50%;
            background: conic-gradient(#1cc88a calc(var(--pct) * 1%), #f1f5f9 0);
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .dzo-fee-ring-inner {
            width: 82px;
            height: 82px;
            border-radius: 50%;
            background: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 0 0 1px #edf0f6;
        }
        .dzo-fee-ring-pct { font-size: 1.15rem; font-weight: 800; color: #183056; line-height: 1; }
        .dzo-fee-ring-label { margin-top: 2px; font-size: 0.66rem; letter-spacing: 0.04em; text-transform: uppercase; color: #7b8798; font-weight: 700; }
        .dzo-fee-hero-copy .label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #7b8798; font-weight: 700; }
        .dzo-fee-hero-copy .amount { font-size: 1.4rem; color: #1cc88a; font-weight: 800; line-height: 1.15; }
        .dzo-fee-hero-copy .meta { font-size: 0.78rem; color: #7a7c84; margin-top: 2px; }
        .dzo-fee-balance-track { width: 100%; height: 12px; border-radius: 999px; overflow: hidden; background: #eef2f7; display: flex; margin-top: 14px; }
        .dzo-fee-balance-track .seg-collected { background: #1cc88a; }
        .dzo-fee-balance-track .seg-outstanding { background: #e74a3b; }
        .dzo-fee-kpi-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 14px; }
        .dzo-fee-kpi {
            border: 1px solid #e7ebf2;
            border-radius: 10px;
            background: #fff;
            padding: 10px 12px;
        }
        .dzo-fee-kpi .kpi-label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; color: #7b8798; font-weight: 700; }
        .dzo-fee-kpi .kpi-value { font-size: 1.02rem; font-weight: 800; color: #1f2937; margin-top: 3px; line-height: 1.2; }
        .dzo-fee-kpi .kpi-value.kpi-money { font-size: 0.96rem; }
        .dzo-fee-mix { margin-top: 14px; }
        .dzo-fee-mix-row { margin-bottom: 9px; }
        .dzo-fee-mix-head { display: flex; justify-content: space-between; align-items: center; font-size: 0.76rem; margin-bottom: 4px; color: #4e5667; }
        .dzo-fee-mix-track { width: 100%; height: 8px; border-radius: 999px; background: #eef2f7; overflow: hidden; }
        .dzo-fee-mix-fill { height: 100%; border-radius: 999px; }
        .dzo-fee-mix-verified { background: #1cc88a; }
        .dzo-fee-mix-pending { background: #f6c23e; }
        .dzo-fee-mix-unpaid { background: #6c757d; }
        .dzo-fee-mix-rejected { background: #e74a3b; }
        .dzo-fee-table-wrap { border: 1px solid #e8ecf4; border-radius: 10px; background: #fff; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>

            <div class="container-fluid py-3">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
                    <h1 class="h4 mb-2 mb-md-0">Dzo Class Dashboard</h1>
                    <span class="text-muted small">Snapshot for <?= dzo_h(date('d M Y')) ?></span>
                </div>

                <div class="row">
                    <div class="col-md-6 col-xl-4 mb-3">
                        <div class="card shadow-sm dzo-stat-card h-100">
                            <div class="card-body">
                                <div class="dzo-stat-label">Pending Enrolments</div>
                                <div class="dzo-stat-value"><?= dzo_h(number_format($stats['pending_enrolments'])) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4 mb-3">
                        <div class="card shadow-sm dzo-stat-card h-100">
                            <div class="card-body">
                                <div class="dzo-stat-label">Approved Students</div>
                                <div class="dzo-stat-value"><?= dzo_h(number_format($stats['approved_students'])) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4 mb-3">
                        <div class="card shadow-sm dzo-stat-card h-100">
                            <div class="card-body">
                                <div class="dzo-stat-label">Active Classes</div>
                                <div class="dzo-stat-value"><?= dzo_h(number_format($stats['active_classes'])) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4 mb-3">
                        <div class="card shadow-sm dzo-stat-card h-100">
                            <div class="card-body">
                                <div class="dzo-stat-label">Today's Attendance Marks</div>
                                <div class="dzo-stat-value"><?= dzo_h(number_format($stats['today_attendance_marks'])) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4 mb-3">
                        <div class="card shadow-sm dzo-stat-card h-100">
                            <div class="card-body">
                                <div class="dzo-stat-label">Pending Fee Verifications</div>
                                <div class="dzo-stat-value"><?= dzo_h(number_format($stats['pending_fee_verifications'])) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4 mb-3">
                        <div class="card shadow-sm dzo-stat-card h-100">
                            <div class="card-body">
                                <div class="dzo-stat-label">Unpaid Fee Items</div>
                                <div class="dzo-stat-value"><?= dzo_h(number_format($stats['unpaid_fee_items'])) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mt-4">
                    <div class="card-body">
                        <div class="dzo-section-title mb-2">
                            <span><i class="fas fa-school mr-2" style="color:#4e73df;"></i>Attendance Summary By Campus</span>
                            <a href="attendance-records">View Records</a>
                        </div>
                        <form method="GET" class="d-flex align-items-center mb-2" style="gap:8px;">
                            <label for="summaryDate" class="mb-0 dzo-mini-meta">Date:</label>
                            <input type="date" id="summaryDate" name="summary_date" class="form-control form-control-sm" style="max-width:180px;" value="<?= dzo_h($summaryDate) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Load</button>
                        </form>
                        <div class="dzo-mini-meta mb-3">
                            For: <strong><?= dzo_h(date('d M Y', strtotime($summaryDate))) ?></strong>
                            &nbsp;&middot;&nbsp;
                            Grand Total: <strong><?= (int)$campusAttendanceOverall['grand'] ?></strong>
                            &nbsp;&middot;&nbsp;
                            Present: <strong style="color:#1cc88a;"><?= (int)$campusAttendanceOverall['present'] ?></strong>
                            &nbsp;&middot;&nbsp;
                            Absent: <strong style="color:#e74a3b;"><?= (int)$campusAttendanceOverall['absent'] ?></strong>
                        </div>

                        <div class="row">
                            <?php foreach (['c1', 'c2'] as $campusKey): ?>
                                <?php $summary = $campusAttendanceSummary[$campusKey] ?? ['label' => 'Campus', 'rows' => [], 'totals' => ['present' => 0, 'absent' => 0, 'grand' => 0]]; ?>
                                <div class="col-lg-6 mb-3 mb-lg-0">
                                    <h6 class="font-weight-bold"><?= dzo_h((string)$summary['label']) ?></h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered dzo-summary-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Class</th>
                                                    <th>Present</th>
                                                    <th>Absent</th>
                                                    <th>Grand Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php if (empty($summary['rows'])): ?>
                                                <tr><td colspan="4" class="text-muted">No attendance records yet.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($summary['rows'] as $row): ?>
                                                    <tr>
                                                        <td><strong><?= dzo_h((string)$row['class_name']) ?></strong></td>
                                                        <td><span style="color:#1cc88a;font-weight:700;"><?= (int)$row['present'] ?></span></td>
                                                        <td><span style="color:#e74a3b;font-weight:700;"><?= (int)$row['absent'] ?></span></td>
                                                        <td><strong><?= (int)$row['grand'] ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr style="background:#fafbff;">
                                                    <td><strong>Total</strong></td>
                                                    <td><strong style="color:#1cc88a;"><?= (int)$summary['totals']['present'] ?></strong></td>
                                                    <td><strong style="color:#e74a3b;"><?= (int)$summary['totals']['absent'] ?></strong></td>
                                                    <td><strong><?= (int)$summary['totals']['grand'] ?></strong></td>
                                                </tr>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mt-4">
                    <div class="card-body">
                        <div class="dzo-section-title mb-2">
                            <span><i class="fas fa-door-open mr-2" style="color:#36b9cc;"></i>Kiosk Sign In/Out Summary By Campus</span>
                            <a href="admin-attendance?tab=kiosk">View Kiosk Records</a>
                        </div>
                        <div class="dzo-mini-meta mb-3">
                            For: <strong><?= dzo_h(date('d M Y', strtotime($summaryDate))) ?></strong>
                            &nbsp;&middot;&nbsp;
                            Registered: <strong style="color:#4e73df;"><?= (int)$campusKioskOverall['registered'] ?></strong>
                            &nbsp;&middot;&nbsp;
                            Grand Total: <strong><?= (int)$campusKioskOverall['grand'] ?></strong>
                            &nbsp;&middot;&nbsp;
                            Sign In: <strong style="color:#1cc88a;"><?= (int)$campusKioskOverall['sign_in'] ?></strong>
                            &nbsp;&middot;&nbsp;
                            Sign Out: <strong style="color:#e74a3b;"><?= (int)$campusKioskOverall['sign_out'] ?></strong>
                        </div>

                        <div class="row">
                            <?php foreach (['c1', 'c2'] as $campusKey): ?>
                                <?php $summary = $campusKioskSummary[$campusKey] ?? ['label' => 'Campus', 'rows' => [], 'totals' => ['registered' => 0, 'sign_in' => 0, 'sign_out' => 0, 'grand' => 0]]; ?>
                                <div class="col-lg-6 mb-3 mb-lg-0">
                                    <h6 class="font-weight-bold"><?= dzo_h((string)$summary['label']) ?></h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered dzo-summary-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Class</th>
                                                    <th>Registered</th>
                                                    <th>Sign In</th>
                                                    <th>Sign Out</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php if (empty($summary['rows'])): ?>
                                                <tr><td colspan="5" class="text-muted">No class records yet.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($summary['rows'] as $row): ?>
                                                    <tr>
                                                        <td><strong><?= dzo_h((string)$row['class_name']) ?></strong></td>
                                                        <td><span style="color:#4e73df;font-weight:700;"><?= (int)$row['registered'] ?></span></td>
                                                        <td><span style="color:#1cc88a;font-weight:700;"><?= (int)$row['sign_in'] ?></span></td>
                                                        <td><span style="color:#e74a3b;font-weight:700;"><?= (int)$row['sign_out'] ?></span></td>
                                                        <td><strong><?= (int)$row['grand'] ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr style="background:#fafbff;">
                                                    <td><strong>Total</strong></td>
                                                    <td><strong style="color:#4e73df;"><?= (int)$summary['totals']['registered'] ?></strong></td>
                                                    <td><strong style="color:#1cc88a;"><?= (int)$summary['totals']['sign_in'] ?></strong></td>
                                                    <td><strong style="color:#e74a3b;"><?= (int)$summary['totals']['sign_out'] ?></strong></td>
                                                    <td><strong><?= (int)$summary['totals']['grand'] ?></strong></td>
                                                </tr>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mt-4 mb-2 dzo-fee-panel">
                    <div class="card-body">
                        <div class="dzo-section-title mb-3">
                            <span><i class="fas fa-money-check-alt mr-2" style="color:#1cc88a;"></i>Fee Collection</span>
                            <a href="feesManagement">View All</a>
                        </div>

                        <?php
                            $collPct = $feeTotal > 0 ? (int)round(($feeCollected / $feeTotal) * 100) : 0;
                            $outPct = max(0, 100 - $collPct);
                            $unresolvedCount = $feePendingCount + $feeUnpaidCount + $feeRejectedCount;
                            $verifiedMixPct = $feeTotalItems > 0 ? (int)round(($feeVerifiedCount / $feeTotalItems) * 100) : 0;
                            $pendingMixPct = $feeTotalItems > 0 ? (int)round(($feePendingCount / $feeTotalItems) * 100) : 0;
                            $unpaidMixPct = $feeTotalItems > 0 ? (int)round(($feeUnpaidCount / $feeTotalItems) * 100) : 0;
                            $rejectedMixPct = $feeTotalItems > 0 ? (int)round(($feeRejectedCount / $feeTotalItems) * 100) : 0;
                        ?>
                        <div class="row">
                            <div class="col-lg-4 mb-3 mb-lg-0">
                                <div class="dzo-fee-hero">
                                    <div class="dzo-fee-ring" style="--pct:<?= $collPct ?>;">
                                        <div class="dzo-fee-ring-inner">
                                            <div class="dzo-fee-ring-pct"><?= (int)$collPct ?>%</div>
                                            <div class="dzo-fee-ring-label">Collected</div>
                                        </div>
                                    </div>
                                    <div class="dzo-fee-hero-copy">
                                        <div class="label">Collected Amount</div>
                                        <div class="amount">$<?= number_format($feeCollected, 2) ?></div>
                                        <div class="meta">Outstanding: $<?= number_format($feePending, 2) ?></div>
                                    </div>
                                </div>
                                <div class="dzo-fee-balance-track">
                                    <div class="seg-collected" style="width:<?= $collPct ?>%;"></div>
                                    <div class="seg-outstanding" style="width:<?= $outPct ?>%;"></div>
                                </div>
                                <div class="dzo-mini-meta mt-2">Collected vs outstanding balance</div>

                                <div class="dzo-fee-kpi-grid">
                                    <div class="dzo-fee-kpi">
                                        <div class="kpi-label">Total Expected</div>
                                        <div class="kpi-value kpi-money">$<?= number_format($feeTotal, 2) ?></div>
                                    </div>
                                    <div class="dzo-fee-kpi">
                                        <div class="kpi-label">Payment Items</div>
                                        <div class="kpi-value"><?= (int)$feeTotalItems ?></div>
                                    </div>
                                    <div class="dzo-fee-kpi">
                                        <div class="kpi-label">Verified Items</div>
                                        <div class="kpi-value" style="color:#1cc88a;"><?= (int)$feeVerifiedCount ?></div>
                                    </div>
                                    <div class="dzo-fee-kpi">
                                        <div class="kpi-label">Needs Action</div>
                                        <div class="kpi-value" style="color:#e74a3b;"><?= (int)$unresolvedCount ?></div>
                                    </div>
                                </div>

                                <div class="dzo-fee-mix">
                                    <div class="dzo-fee-mix-row">
                                        <div class="dzo-fee-mix-head"><span>Verified</span><strong><?= (int)$feeVerifiedCount ?></strong></div>
                                        <div class="dzo-fee-mix-track"><div class="dzo-fee-mix-fill dzo-fee-mix-verified" style="width:<?= $verifiedMixPct ?>%;"></div></div>
                                    </div>
                                    <div class="dzo-fee-mix-row">
                                        <div class="dzo-fee-mix-head"><span>Pending</span><strong><?= (int)$feePendingCount ?></strong></div>
                                        <div class="dzo-fee-mix-track"><div class="dzo-fee-mix-fill dzo-fee-mix-pending" style="width:<?= $pendingMixPct ?>%;"></div></div>
                                    </div>
                                    <div class="dzo-fee-mix-row">
                                        <div class="dzo-fee-mix-head"><span>Unpaid</span><strong><?= (int)$feeUnpaidCount ?></strong></div>
                                        <div class="dzo-fee-mix-track"><div class="dzo-fee-mix-fill dzo-fee-mix-unpaid" style="width:<?= $unpaidMixPct ?>%;"></div></div>
                                    </div>
                                    <div class="dzo-fee-mix-row mb-0">
                                        <div class="dzo-fee-mix-head"><span>Rejected</span><strong><?= (int)$feeRejectedCount ?></strong></div>
                                        <div class="dzo-fee-mix-track"><div class="dzo-fee-mix-fill dzo-fee-mix-rejected" style="width:<?= $rejectedMixPct ?>%;"></div></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-8">
                                <div class="dzo-fee-table-wrap p-2">
                                    <div class="dzo-mini-meta px-1 pb-2">Latest payment obligations</div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered dzo-summary-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Student</th>
                                                    <th>Installment</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php if (empty($dashboardFeePayments)): ?>
                                                <tr><td colspan="4" class="text-muted">No payment records found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($dashboardFeePayments as $fp): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= dzo_h((string)($fp['student_name'] ?? 'Student')) ?></strong>
                                                            <div class="dzo-mini-meta"><?= dzo_h((string)($fp['public_student_id'] ?? '')) ?></div>
                                                        </td>
                                                        <td><?= dzo_h((string)($fp['instalment_label'] ?? '-')) ?></td>
                                                        <td>$<?= number_format((float)($fp['due_amount'] ?? 0), 2) ?></td>
                                                        <td><span class="badge badge-<?= dzo_badge_class((string)($fp['status'] ?? 'Pending')) ?>"><?= dzo_h((string)($fp['status'] ?? 'Pending')) ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($canAttendanceView): ?>
                <div class="card shadow-sm mt-4 mb-2">
                    <div class="card-body">
                        <div class="dzo-section-title mb-3">
                            <span><i class="fas fa-clipboard-check mr-2" style="color:#36b9cc;"></i>Recent Attendance Records</span>
                            <a href="attendanceManagement">View All</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered dzo-summary-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($recentAttendanceRecords)): ?>
                                    <tr><td colspan="4" class="text-muted">No attendance records yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentAttendanceRecords as $ar): ?>
                                        <?php
                                            $attStatus = strtolower((string)($ar['status'] ?? ''));
                                            $attBadge = 'secondary';
                                            if ($attStatus === 'present') $attBadge = 'success';
                                            elseif ($attStatus === 'absent') $attBadge = 'danger';
                                            elseif ($attStatus === 'late') $attBadge = 'warning';
                                        ?>
                                        <tr>
                                            <td><?= !empty($ar['attendance_date']) ? dzo_h(date('d M Y', strtotime((string)$ar['attendance_date']))) : '-' ?></td>
                                            <td>
                                                <strong><?= dzo_h((string)($ar['student_name'] ?? '-')) ?></strong>
                                                <?php if (!empty($ar['student_id'])): ?>
                                                    <div class="dzo-mini-meta"><?= dzo_h((string)$ar['student_id']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= dzo_h((string)($ar['class_name'] ?? '-')) ?></td>
                                            <td><span class="badge badge-<?= dzo_h($attBadge) ?>"><?= dzo_h((string)($ar['status'] ?? 'Unknown')) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
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
