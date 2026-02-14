<?php

require_once "include/config.php";
require_once "include/auth.php";
require_login();

$filterCompanyID = $_SESSION['companyID'] ?? null;
$filterProjectID = $_SESSION['projectID'] ?? null;

$role = strtolower($_SESSION['role'] ?? '');

// Parent dashboard data
$parentDbId = null;
$parentProfile = null;
$myChildren = [];

// Admin overview data
$totalStudents    = 0;
$pendingStudents  = 0;
$approvedStudents = 0;
$totalParents     = 0;
$totalEvents      = 0;
$upcomingEvents   = [];
$bookedEvents     = 0;
$availableEvents  = 0;
$pendingBookings  = 0;
$totalBookings    = 0;
$recentStudents   = [];
$recentBookings   = [];
$contactMessages  = 0;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    /* ═══ PARENT DASHBOARD ═══ */
    if ($role === 'parent') {
        $sessionUsername = $_SESSION['username'] ?? '';
        if ($sessionUsername === '') {
            throw new Exception("Session username missing. Please logout and login again.");
        }

        $stmtParent = $pdo->prepare("SELECT id, full_name, email FROM parents WHERE username = :u LIMIT 1");
        $stmtParent->execute([':u' => $sessionUsername]);
        $parentProfile = $stmtParent->fetch(PDO::FETCH_ASSOC);

        if ($parentProfile) {
            $parentDbId = (int)$parentProfile['id'];
            $stmtKids = $pdo->prepare("
                SELECT id, student_id, student_name, dob, gender, registration_date, approval_status,
                       class_option, payment_plan, payment_amount, payment_reference, payment_proof
                FROM students WHERE parentId = :pid ORDER BY id DESC
            ");
            $stmtKids->execute([':pid' => $parentDbId]);
            $myChildren = $stmtKids->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /* ═══ ADMIN DASHBOARD STATS ═══ */
    if ($role !== 'parent') {
        // Students
        $totalStudents   = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $pendingStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE approval_status='Pending'")->fetchColumn();
        $approvedStudents= (int)$pdo->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'")->fetchColumn();

        // Parents
        $totalParents = (int)$pdo->query("SELECT COUNT(*) FROM parents")->fetchColumn();

        // Events
        $totalEvents    = (int)$pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
        $bookedEvents   = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='Booked'")->fetchColumn();
        $availableEvents= (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='Available'")->fetchColumn();

        // Upcoming events (next 5)
        $stmtUp = $pdo->prepare("SELECT id, title, event_date, start_time, location, status FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5");
        $stmtUp->execute();
        $upcomingEvents = $stmtUp->fetchAll(PDO::FETCH_ASSOC);

        // Bookings
        $totalBookings  = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
        $pendingBookings= (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='Pending'")->fetchColumn();

        // Recent students (last 5)
        $stmtRecSt = $pdo->query("SELECT student_id, student_name, class_option, approval_status, registration_date FROM students ORDER BY id DESC LIMIT 5");
        $recentStudents = $stmtRecSt->fetchAll(PDO::FETCH_ASSOC);

        // Recent bookings (last 5)
        $stmtRecBk = $pdo->prepare("SELECT b.id, b.name, b.email, b.status, b.created_at, e.title AS event_title FROM bookings b LEFT JOIN events e ON b.event_id = e.id ORDER BY b.id DESC LIMIT 5");
        $stmtRecBk->execute();
        $recentBookings = $stmtRecBk->fetchAll(PDO::FETCH_ASSOC);

        // Contact messages
        $contactMessages = (int)$pdo->query("SELECT COUNT(*) FROM contact")->fetchColumn();
    }

    /* ═══ FINANCE DATA ═══ */
    $stmtTypes = $pdo->query("SELECT id, typeName FROM account_head_type");
    $accountTypes = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);

    $stmtHeads = $pdo->prepare("SELECT id, accountHeadName, accountHeadTypeID FROM account_head WHERE companyID = ? AND projectID = ?");
    $stmtHeads->execute([$filterCompanyID, $filterProjectID]);
    $accountHeads = [];
    while ($row = $stmtHeads->fetch(PDO::FETCH_ASSOC)) {
        $accountHeads[$row['accountHeadTypeID']][] = $row;
    }

    $query = "SELECT accountHeadID, SUM(amount) as total FROM journal_entry WHERE 1=1";
    $params = [];
    if ($filterCompanyID) { $query .= " AND companyID = ?"; $params[] = $filterCompanyID; }
    if ($filterProjectID) { $query .= " AND projectID = ?"; $params[] = $filterProjectID; }
    $query .= " GROUP BY accountHeadID";
    $stmtAmounts = $pdo->prepare($query);
    $stmtAmounts->execute($params);
    $amounts = $stmtAmounts->fetchAll(PDO::FETCH_KEY_PAIR);

    $groupedReport = [];
    $totals = [];
    foreach ($accountTypes as $type) {
        $typeID = $type['id'];
        $typeName = $type['typeName'];
        $groupedReport[$typeName] = [];
        $total = 0;
        if (!empty($accountHeads[$typeID])) {
            foreach ($accountHeads[$typeID] as $head) {
                $amount = isset($amounts[$head['id']]) ? $amounts[$head['id']] : 0;
                $groupedReport[$typeName][] = ['name' => $head['accountHeadName'], 'amount' => $amount];
                $total += $amount;
            }
        }
        $totals[strtoupper(trim($typeName))] = $total;
    }

    $income           = $totals['INCOME/RECEIPTS'] ?? 0;
    $directExpenses   = $totals['DIRECT EXPENSES'] ?? 0;
    $indirectExpenses = $totals['INDIRECT EXPENSES'] ?? 0;
    $remaining        = $income - ($directExpenses + $indirectExpenses);

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="account_summary_'.date('Y-m-d').'.xls"');
    echo "<table border='1'><tr><th>Sl.No</th><th>Particulars</th><th>Net Value (Nu.)</th><th>Total (Nu.)</th></tr>";
    $sectionLabel = 'A';
    foreach ($groupedReport as $typeName => $items) {
        $isIncome = strtoupper($typeName) === 'INCOME/RECEIPTS';
        echo "<tr style='background-color:".($isIncome ? '#cfe2ff' : '#f8d7da')."'><td>".$sectionLabel."</td><td colspan='3'><strong>".htmlspecialchars($typeName)."</strong></td></tr>";
        $i = 1;
        foreach ($items as $entry) {
            echo "<tr><td>".$i++."</td><td>".htmlspecialchars($entry['name'])."</td><td>".number_format($entry['amount'], 2)."</td><td></td></tr>";
        }
        echo "<tr style='background-color:".($isIncome ? '#d1e7dd' : '#fff3cd').";font-weight:bold;'><td colspan='3' style='text-align:right;'>Total</td><td>".number_format($totals[strtoupper(trim($typeName))] ?? 0, 2)."</td></tr>";
        $sectionLabel = chr(ord($sectionLabel) + 1);
    }
    echo "<tr style='background-color:#e7f1ff;font-weight:bold;'><td>".$sectionLabel."</td><td>Remaining Fund Balance</td><td></td><td>".number_format($remaining, 2)."</td></tr></table>";
    exit;
}

function badge_class($st) {
    $st = strtolower($st ?? '');
    if ($st === 'pending') return 'warning';
    if ($st === 'approved') return 'success';
    if ($st === 'rejected') return 'danger';
    return 'secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dashboard - BBCC Admin</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <style>
    /* ═══ Dashboard Styles ═══ */
    .dash-card {
        border: none;
        border-radius: 14px;
        overflow: hidden;
        transition: all 0.3s ease;
        height: 100%;
    }
    .dash-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 35px rgba(0,0,0,0.1) !important;
    }
    .dash-stat-card {
        position: relative;
        padding: 24px;
    }
    .dash-stat-card .stat-icon {
        width: 52px; height: 52px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        color: #fff;
        position: absolute;
        top: 24px; right: 24px;
    }
    .dash-stat-card .stat-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #8c8c9e;
        margin-bottom: 8px;
    }
    .dash-stat-card .stat-value {
        font-size: 2rem;
        font-weight: 800;
        color: #1a1a2e;
        line-height: 1;
        margin-bottom: 10px;
    }
    .dash-stat-card .stat-footer {
        font-size: 0.78rem;
        color: #666;
    }
    .dash-stat-card .stat-footer a {
        color: #4e73df;
        font-weight: 600;
        text-decoration: none;
    }
    .dash-stat-card .stat-footer a:hover { text-decoration: underline; }

    /* Color variants */
    .stat-icon.bg-students   { background: linear-gradient(135deg, #4e73df, #224abe); }
    .stat-icon.bg-parents    { background: linear-gradient(135deg, #1cc88a, #13855c); }
    .stat-icon.bg-events     { background: linear-gradient(135deg, #f6c23e, #dda20a); }
    .stat-icon.bg-bookings   { background: linear-gradient(135deg, #e74a3b, #be2617); }
    .stat-icon.bg-income     { background: linear-gradient(135deg, #1cc88a, #13855c); }
    .stat-icon.bg-expenses   { background: linear-gradient(135deg, #e74a3b, #be2617); }
    .stat-icon.bg-balance    { background: linear-gradient(135deg, #4e73df, #224abe); }
    .stat-icon.bg-messages   { background: linear-gradient(135deg, #36b9cc, #258391); }

    /* Welcome banner */
    .welcome-banner {
        background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
        border-radius: 16px;
        padding: 30px 32px;
        color: #fff;
        position: relative;
        overflow: hidden;
        margin-bottom: 24px;
    }
    .welcome-banner::before {
        content: '';
        position: absolute;
        top: -40%; right: -10%;
        width: 300px; height: 300px;
        background: rgba(255,255,255,0.06);
        border-radius: 50%;
    }
    .welcome-banner::after {
        content: '';
        position: absolute;
        bottom: -50%; right: 15%;
        width: 200px; height: 200px;
        background: rgba(255,255,255,0.04);
        border-radius: 50%;
    }
    .welcome-banner h2 {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 0 6px;
        position: relative; z-index: 1;
    }
    .welcome-banner p {
        opacity: 0.85;
        margin: 0;
        font-size: 0.92rem;
        position: relative; z-index: 1;
    }
    .welcome-banner .quick-actions {
        margin-top: 18px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        position: relative; z-index: 1;
    }
    .welcome-banner .quick-actions a {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 18px;
        background: rgba(255,255,255,0.15);
        color: #fff;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s;
        backdrop-filter: blur(4px);
        border: 1px solid rgba(255,255,255,0.1);
    }
    .welcome-banner .quick-actions a:hover {
        background: rgba(255,255,255,0.25);
        transform: translateY(-1px);
    }

    /* Section title */
    .section-title {
        font-size: 0.88rem;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .section-title a {
        font-size: 0.78rem;
        font-weight: 600;
        color: #4e73df;
        text-decoration: none;
    }
    .section-title a:hover { text-decoration: underline; }

    /* Events list */
    .event-list-item {
        display: flex;
        align-items: center;
        padding: 14px 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .event-list-item:last-child { border-bottom: none; }
    .event-date-badge {
        width: 50px; height: 54px;
        background: #f0f2ff;
        border-radius: 10px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        margin-right: 14px;
        flex-shrink: 0;
    }
    .event-date-badge .day {
        font-size: 1.15rem;
        font-weight: 800;
        color: #4e73df;
        line-height: 1;
    }
    .event-date-badge .month {
        font-size: 0.65rem;
        font-weight: 600;
        color: #8c8c9e;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .event-info { flex: 1; }
    .event-info .title {
        font-size: 0.85rem;
        font-weight: 600;
        color: #1a1a2e;
        margin: 0;
    }
    .event-info .meta {
        font-size: 0.75rem;
        color: #999;
        margin: 2px 0 0;
    }
    .event-info .meta i { margin-right: 3px; }
    .event-status-dot {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .dot-booked    { background: #e8f5e9; color: #2e7d32; }
    .dot-available { background: #fff3e0; color: #e65100; }
    .dot-pending   { background: #fff8e1; color: #f57f17; }

    /* Finance widget */
    .finance-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid #f5f5f5;
    }
    .finance-row:last-child { border-bottom: none; }
    .finance-row .label {
        font-size: 0.84rem;
        font-weight: 500;
        color: #555;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .finance-row .label .dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .finance-row .val {
        font-size: 0.9rem;
        font-weight: 700;
        color: #1a1a2e;
    }
    .finance-bar {
        height: 8px;
        border-radius: 4px;
        background: #f0f2f5;
        margin-top: 12px;
        overflow: hidden;
        display: flex;
    }
    .finance-bar .seg {
        height: 100%;
        transition: width 0.6s ease;
    }

    /* Recent table */
    .dash-table {
        width: 100%;
        font-size: 0.82rem;
    }
    .dash-table th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
        color: #8c8c9e;
        padding: 8px 12px;
        border-bottom: 2px solid #f0f0f0;
    }
    .dash-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #f5f5f5;
        color: #444;
        vertical-align: middle;
    }
    .dash-table tr:last-child td { border-bottom: none; }
    .dash-table .badge {
        font-size: 0.68rem;
        padding: 4px 10px;
        border-radius: 6px;
        font-weight: 600;
    }

    /* Parent Dashboard Cards */
    .parent-stat-card {
        background: #fff;
        border-radius: 14px;
        padding: 22px;
        text-align: center;
        box-shadow: 0 2px 15px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
    }
    .parent-stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    .parent-stat-card .icon {
        width: 48px; height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        font-size: 1.1rem;
        color: #fff;
    }
    .parent-stat-card .number {
        font-size: 1.6rem;
        font-weight: 800;
        color: #1a1a2e;
    }
    .parent-stat-card .label {
        font-size: 0.75rem;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }
    </style>
</head>

<body id="page-top">
<div id="wrapper">

    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">

            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

            <?php if ($role === 'parent'): ?>
                <!-- ═══════════════════════════════════════════ -->
                <!-- ═══ PARENT DASHBOARD ═══ -->
                <!-- ═══════════════════════════════════════════ -->

                <!-- Welcome -->
                <div class="welcome-banner" style="background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);">
                    <h2><i class="fas fa-namaste"></i> Welcome, <?php echo htmlspecialchars($parentProfile['full_name'] ?? $_SESSION['username'] ?? 'Parent'); ?>!</h2>
                    <p>Manage your children's enrollments, track attendance, and make fee payments from your dashboard.</p>
                    <div class="quick-actions">
                        <a href="studentSetup.php"><i class="fas fa-plus"></i> Add Student</a>
                        <a href="parentFeesPayment.php"><i class="fas fa-money-check-alt"></i> Pay Fees</a>
                        <a href="attendanceParent.php"><i class="fas fa-clipboard-check"></i> Attendance</a>
                    </div>
                </div>

                <!-- Parent Stats Row -->
                <?php
                    $totalKids = count($myChildren);
                    $approvedKids = count(array_filter($myChildren, fn($c) => strtolower($c['approval_status'] ?? '') === 'approved'));
                    $pendingKids  = count(array_filter($myChildren, fn($c) => strtolower($c['approval_status'] ?? '') === 'pending'));
                ?>
                <div class="row mb-4">
                    <div class="col-md-4 col-6 mb-3">
                        <div class="parent-stat-card">
                            <div class="icon" style="background: linear-gradient(135deg, #4e73df, #224abe);"><i class="fas fa-user-graduate"></i></div>
                            <div class="number"><?php echo $totalKids; ?></div>
                            <div class="label">Total Enrolled</div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6 mb-3">
                        <div class="parent-stat-card">
                            <div class="icon" style="background: linear-gradient(135deg, #1cc88a, #13855c);"><i class="fas fa-check-circle"></i></div>
                            <div class="number"><?php echo $approvedKids; ?></div>
                            <div class="label">Approved</div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6 mb-3">
                        <div class="parent-stat-card">
                            <div class="icon" style="background: linear-gradient(135deg, #f6c23e, #dda20a);"><i class="fas fa-clock"></i></div>
                            <div class="number"><?php echo $pendingKids; ?></div>
                            <div class="label">Pending</div>
                        </div>
                    </div>
                </div>

                <!-- Children Table -->
                <div class="card dash-card shadow mb-4">
                    <div class="card-body p-0">
                        <div class="p-3 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid #f0f0f0;">
                            <div class="section-title mb-0">My Children Enrollments</div>
                            <a href="studentSetup.php" class="btn btn-primary btn-sm" style="border-radius:8px;">
                                <i class="fas fa-plus"></i> Add New Student
                            </a>
                        </div>

                        <?php if (!$parentProfile): ?>
                            <div class="p-4">
                                <div class="alert alert-warning mb-0">
                                    <strong>Account not linked.</strong> Please contact admin to link your login
                                    (<strong><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></strong>).
                                </div>
                            </div>
                        <?php elseif (empty($myChildren)): ?>
                            <div class="p-4 text-center" style="color:#999;">
                                <i class="fas fa-inbox fa-3x mb-3" style="opacity:0.3;"></i>
                                <p>No enrollments yet. Click <strong>Add New Student</strong> to get started.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="dash-table">
                                    <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Class</th>
                                        <th>Plan</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                        <th>Proof</th>
                                        <th>Reg Date</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($myChildren as $c): ?>
                                        <?php
                                            $st = strtolower($c['approval_status'] ?? '');
                                            $isApproved = ($st === 'approved');
                                            $isPending = ($st === 'pending');
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($c['student_id'] ?? ''); ?></td>
                                            <td><strong><?php echo htmlspecialchars($c['student_name'] ?? ''); ?></strong></td>
                                            <td><?php echo htmlspecialchars($c['class_option'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($c['payment_plan'] ?? '-'); ?></td>
                                            <td><?php echo isset($c['payment_amount']) ? '$'.htmlspecialchars($c['payment_amount']) : '-'; ?></td>
                                            <td style="max-width:180px; white-space:normal;"><?php echo htmlspecialchars($c['payment_reference'] ?? '-'); ?></td>
                                            <td>
                                                <?php if (!empty($c['payment_proof'])): ?>
                                                    <a href="<?php echo htmlspecialchars($c['payment_proof']); ?>" target="_blank" style="color:#4e73df;">View</a>
                                                <?php else: ?>-<?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($c['registration_date'] ?? ''); ?></td>
                                            <td><span class="badge badge-<?php echo badge_class($c['approval_status']); ?>"><?php echo htmlspecialchars($c['approval_status'] ?? ''); ?></span></td>
                                            <td>
                                                <?php if ($isApproved): ?>
                                                    <span class="badge badge-success">Approved</span>
                                                <?php else: ?>
                                                    <a class="btn btn-info btn-sm" style="border-radius:6px;font-size:0.75rem;" href="studentSetup.php?edit=<?php echo (int)$c['id']; ?>">Edit</a>
                                                    <?php if ($isPending): ?>
                                                        <a class="btn btn-danger btn-sm" style="border-radius:6px;font-size:0.75rem;"
                                                           href="studentSetup.php?delete=<?php echo (int)$c['id']; ?>"
                                                           onclick="return confirm('Delete this enrollment?');">Delete</a>
                                                    <?php endif; ?>
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


            <?php else: ?>
                <!-- ═══════════════════════════════════════════ -->
                <!-- ═══ ADMIN DASHBOARD ═══ -->
                <!-- ═══════════════════════════════════════════ -->

                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?>!</h2>
                    <p>Here's an overview of your community centre. Today is <?php echo date('l, d F Y'); ?>.</p>
                    <div class="quick-actions">
                        <a href="dzoClassManagement.php"><i class="fas fa-user-graduate"></i> Enrollments</a>
                        <a href="eventManagement.php"><i class="fas fa-calendar-alt"></i> Events</a>
                        <a href="bookingManagement.php"><i class="fas fa-bookmark"></i> Bookings</a>
                        <a href="createJournalEntry.php"><i class="fas fa-receipt"></i> Journal Entry</a>
                    </div>
                </div>

                <!-- ── Stats Cards Row ── -->
                <div class="row mb-2">
                    <!-- Total Students -->
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-students"><i class="fas fa-user-graduate"></i></div>
                                <div class="stat-label">Total Students</div>
                                <div class="stat-value"><?php echo $totalStudents; ?></div>
                                <div class="stat-footer">
                                    <span style="color:#1cc88a;"><i class="fas fa-check-circle"></i> <?php echo $approvedStudents; ?> approved</span>
                                    &nbsp;&middot;&nbsp;
                                    <span style="color:#f6c23e;"><i class="fas fa-clock"></i> <?php echo $pendingStudents; ?> pending</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Registered Parents -->
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-parents"><i class="fas fa-users"></i></div>
                                <div class="stat-label">Registered Parents</div>
                                <div class="stat-value"><?php echo $totalParents; ?></div>
                                <div class="stat-footer">
                                    <a href="dzoClassManagement.php">View all families <i class="fas fa-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Events -->
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-events"><i class="fas fa-calendar-alt"></i></div>
                                <div class="stat-label">Total Events</div>
                                <div class="stat-value"><?php echo $totalEvents; ?></div>
                                <div class="stat-footer">
                                    <span style="color:#1cc88a;"><i class="fas fa-check"></i> <?php echo $bookedEvents; ?> booked</span>
                                    &nbsp;&middot;&nbsp;
                                    <span style="color:#e65100;"><i class="fas fa-clock"></i> <?php echo $availableEvents; ?> available</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Bookings -->
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-bookings"><i class="fas fa-bookmark"></i></div>
                                <div class="stat-label">Booking Requests</div>
                                <div class="stat-value"><?php echo $totalBookings; ?></div>
                                <div class="stat-footer">
                                    <?php if ($pendingBookings > 0): ?>
                                        <a href="bookingManagement.php" style="color:#e74a3b;"><i class="fas fa-exclamation-circle"></i> <?php echo $pendingBookings; ?> pending approval</a>
                                    <?php else: ?>
                                        <span style="color:#1cc88a;"><i class="fas fa-check-circle"></i> All handled</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Row 2: Upcoming Events + Finance ── -->
                <div class="row mb-2">
                    <!-- Upcoming Events -->
                    <div class="col-lg-7 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body">
                                <div class="section-title">
                                    <span><i class="fas fa-calendar-day mr-2" style="color:#4e73df;"></i>Upcoming Events</span>
                                    <a href="eventManagement.php">View All</a>
                                </div>

                                <?php if (empty($upcomingEvents)): ?>
                                    <div class="text-center py-4" style="color:#ccc;">
                                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                        <p class="mb-0" style="font-size:0.85rem;">No upcoming events</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($upcomingEvents as $ev): ?>
                                        <?php
                                            $evDate = strtotime($ev['event_date']);
                                            $evDay  = date('d', $evDate);
                                            $evMon  = date('M', $evDate);
                                            $evTime = $ev['start_time'] ? date('g:i A', strtotime($ev['start_time'])) : '';
                                            $evStatusClass = strtolower($ev['status']) === 'booked' ? 'dot-booked' : (strtolower($ev['status']) === 'available' ? 'dot-available' : 'dot-pending');
                                        ?>
                                        <div class="event-list-item">
                                            <div class="event-date-badge">
                                                <span class="day"><?php echo $evDay; ?></span>
                                                <span class="month"><?php echo $evMon; ?></span>
                                            </div>
                                            <div class="event-info">
                                                <p class="title"><?php echo htmlspecialchars($ev['title']); ?></p>
                                                <p class="meta">
                                                    <?php if ($evTime): ?><i class="far fa-clock"></i> <?php echo $evTime; ?> &nbsp;<?php endif; ?>
                                                    <?php if ($ev['location']): ?><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($ev['location']); ?><?php endif; ?>
                                                </p>
                                            </div>
                                            <span class="event-status-dot <?php echo $evStatusClass; ?>"><?php echo htmlspecialchars($ev['status']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Summary -->
                    <div class="col-lg-5 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body">
                                <div class="section-title">
                                    <span><i class="fas fa-chart-pie mr-2" style="color:#1cc88a;"></i>Financial Summary</span>
                                    <a href="generateStatement.php">Full Report</a>
                                </div>

                                <div class="finance-row">
                                    <span class="label"><span class="dot" style="background:#1cc88a;"></span> Income / Receipts</span>
                                    <span class="val" style="color:#1cc88a;">$<?php echo number_format($income, 2); ?></span>
                                </div>
                                <div class="finance-row">
                                    <span class="label"><span class="dot" style="background:#e74a3b;"></span> Direct Expenses</span>
                                    <span class="val" style="color:#e74a3b;">$<?php echo number_format($directExpenses, 2); ?></span>
                                </div>
                                <div class="finance-row">
                                    <span class="label"><span class="dot" style="background:#f6c23e;"></span> Indirect Expenses</span>
                                    <span class="val" style="color:#f6c23e;">$<?php echo number_format($indirectExpenses, 2); ?></span>
                                </div>

                                <?php
                                    $totalExp = $directExpenses + $indirectExpenses;
                                    $totalAll = $income + $totalExp;
                                    $incPct = $totalAll > 0 ? round(($income / $totalAll) * 100) : 50;
                                    $dirPct = $totalAll > 0 ? round(($directExpenses / $totalAll) * 100) : 25;
                                    $indPct = 100 - $incPct - $dirPct;
                                ?>
                                <div class="finance-bar">
                                    <div class="seg" style="width:<?php echo $incPct; ?>%; background:#1cc88a;"></div>
                                    <div class="seg" style="width:<?php echo $dirPct; ?>%; background:#e74a3b;"></div>
                                    <div class="seg" style="width:<?php echo $indPct; ?>%; background:#f6c23e;"></div>
                                </div>

                                <div style="margin-top:18px; padding:16px; background:linear-gradient(135deg,#f0f4ff,#e8ecff); border-radius:10px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <span style="font-size:0.84rem; font-weight:600; color:#555;">
                                            <i class="fas fa-wallet mr-1" style="color:#4e73df;"></i> Remaining Balance
                                        </span>
                                        <span style="font-size:1.3rem; font-weight:800; color:<?php echo $remaining >= 0 ? '#1cc88a' : '#e74a3b'; ?>;">
                                            $<?php echo number_format($remaining, 2); ?>
                                        </span>
                                    </div>
                                </div>

                                <div style="margin-top:14px; text-align:center;">
                                    <a href="?export=excel" class="btn btn-sm" style="background:#f0f2f5; border-radius:8px; font-size:0.78rem; font-weight:600; color:#555;">
                                        <i class="fas fa-file-excel mr-1" style="color:#1cc88a;"></i> Export to Excel
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Row 3: Recent Students + Recent Bookings ── -->
                <div class="row mb-2">
                    <!-- Recent Students -->
                    <div class="col-lg-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body p-0">
                                <div class="p-3">
                                    <div class="section-title mb-0">
                                        <span><i class="fas fa-user-graduate mr-2" style="color:#4e73df;"></i>Recent Enrollments</span>
                                        <a href="dzoClassManagement.php">View All</a>
                                    </div>
                                </div>
                                <?php if (empty($recentStudents)): ?>
                                    <div class="p-4 text-center" style="color:#ccc;">
                                        <i class="fas fa-inbox fa-2x mb-2"></i>
                                        <p class="mb-0" style="font-size:0.85rem;">No enrollments yet</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="dash-table">
                                            <thead>
                                            <tr><th>ID</th><th>Name</th><th>Class</th><th>Date</th><th>Status</th></tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($recentStudents as $rs): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($rs['student_id'] ?? ''); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($rs['student_name'] ?? ''); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($rs['class_option'] ?? '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($rs['registration_date'] ?? ''); ?></td>
                                                    <td><span class="badge badge-<?php echo badge_class($rs['approval_status']); ?>"><?php echo htmlspecialchars($rs['approval_status'] ?? ''); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Bookings -->
                    <div class="col-lg-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body p-0">
                                <div class="p-3">
                                    <div class="section-title mb-0">
                                        <span><i class="fas fa-bookmark mr-2" style="color:#e74a3b;"></i>Recent Booking Requests</span>
                                        <a href="bookingManagement.php">View All</a>
                                    </div>
                                </div>
                                <?php if (empty($recentBookings)): ?>
                                    <div class="p-4 text-center" style="color:#ccc;">
                                        <i class="fas fa-inbox fa-2x mb-2"></i>
                                        <p class="mb-0" style="font-size:0.85rem;">No booking requests yet</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="dash-table">
                                            <thead>
                                            <tr><th>Name</th><th>Event</th><th>Date</th><th>Status</th></tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($recentBookings as $rb): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($rb['name'] ?? ''); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($rb['event_title'] ?? '-'); ?></td>
                                                    <td><?php echo $rb['created_at'] ? date('d M Y', strtotime($rb['created_at'])) : '-'; ?></td>
                                                    <td><span class="badge badge-<?php echo badge_class($rb['status']); ?>"><?php echo htmlspecialchars($rb['status'] ?? ''); ?></span></td>
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

                <!-- ── Row 4: Quick Stats bar ── -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card" style="padding: 20px 24px;">
                                <div class="stat-icon bg-messages" style="width:42px;height:42px;border-radius:10px;font-size:1rem;"><i class="fas fa-envelope"></i></div>
                                <div class="stat-label">Contact Messages</div>
                                <div class="stat-value" style="font-size:1.6rem;"><?php echo $contactMessages; ?></div>
                                <div class="stat-footer"><a href="viewFeedback.php">View messages <i class="fas fa-arrow-right"></i></a></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card" style="padding: 20px 24px;">
                                <div class="stat-icon bg-income" style="width:42px;height:42px;border-radius:10px;font-size:1rem;"><i class="fas fa-dollar-sign"></i></div>
                                <div class="stat-label">Total Income</div>
                                <div class="stat-value" style="font-size:1.6rem;">$<?php echo number_format($income, 0); ?></div>
                                <div class="stat-footer"><a href="generateStatement.php">View statement <i class="fas fa-arrow-right"></i></a></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card" style="padding: 20px 24px;">
                                <div class="stat-icon bg-balance" style="width:42px;height:42px;border-radius:10px;font-size:1rem;"><i class="fas fa-wallet"></i></div>
                                <div class="stat-label">Fund Balance</div>
                                <div class="stat-value" style="font-size:1.6rem; color:<?php echo $remaining >= 0 ? '#1cc88a' : '#e74a3b'; ?>;">$<?php echo number_format($remaining, 0); ?></div>
                                <div class="stat-footer"><a href="?export=excel">Export report <i class="fas fa-arrow-right"></i></a></div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
