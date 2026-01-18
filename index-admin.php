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
$totalStudents = 0;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    /* =========================
       ✅ PARENT DASHBOARD
       Link using parents.username = session username
       ========================= */
    if ($role === 'parent') {

        $sessionUsername = $_SESSION['username'] ?? '';
        if ($sessionUsername === '') {
            throw new Exception("Session username missing. Please logout and login again.");
        }

        $stmtParent = $pdo->prepare("
            SELECT id, full_name, email
            FROM parents
            WHERE username = :u
            LIMIT 1
        ");
        $stmtParent->execute([':u' => $sessionUsername]);
        $parentProfile = $stmtParent->fetch(PDO::FETCH_ASSOC);

        // ✅ IMPORTANT: No fallback to first parent
        if ($parentProfile) {
            $parentDbId = (int)$parentProfile['id'];

            $stmtKids = $pdo->prepare("
                SELECT id, student_id, student_name, dob, gender, registration_date, approval_status,
                       class_option, payment_plan, payment_amount, payment_reference, payment_proof
                FROM students
                WHERE parentId = :pid
                ORDER BY id DESC
            ");
            $stmtKids->execute([':pid' => $parentDbId]);
            $myChildren = $stmtKids->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /* =========================
       ✅ ADMIN OVERVIEW
       Approval moved to dzoClassManagement.php
       ========================= */
    if ($role !== 'parent') {
        $stmtCount = $pdo->query("SELECT COUNT(*) FROM students");
        $totalStudents = (int)$stmtCount->fetchColumn();
    }

    /* =========================
       ✅ YOUR FINANCE CODE (unchanged)
       ========================= */
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

    if ($filterCompanyID) {
        $query .= " AND companyID = ?";
        $params[] = $filterCompanyID;
    }

    if ($filterProjectID) {
        $query .= " AND projectID = ?";
        $params[] = $filterProjectID;
    }

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
                $groupedReport[$typeName][] = [
                    'name' => $head['accountHeadName'],
                    'amount' => $amount
                ];
                $total += $amount;
            }
        }
        $totals[strtoupper(trim($typeName))] = $total;
    }

    $income = $totals['INCOME/RECEIPTS'] ?? 0;
    $directExpenses = $totals['DIRECT EXPENSES'] ?? 0;
    $indirectExpenses = $totals['INDIRECT EXPENSES'] ?? 0;
    $remaining = $income - ($directExpenses + $indirectExpenses);

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Excel export (unchanged)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="account_summary_'.date('Y-m-d').'.xls"');

    echo "<table border='1'>";
    echo "<tr>
            <th>Sl.No</th>
            <th>Particulars</th>
            <th>Net Value (Nu.)</th>
            <th>Total (Nu.)</th>
          </tr>";

    $sectionLabel = 'A';
    foreach ($groupedReport as $typeName => $items) {
        $isIncome = strtoupper($typeName) === 'INCOME/RECEIPTS';

        echo "<tr style='background-color:".($isIncome ? '#cfe2ff' : '#f8d7da')."'>";
        echo "<td>".$sectionLabel."</td>";
        echo "<td colspan='3'><strong>".htmlspecialchars($typeName)."</strong></td>";
        echo "</tr>";

        $i = 1;
        foreach ($items as $entry) {
            echo "<tr>";
            echo "<td>".$i++."</td>";
            echo "<td>".htmlspecialchars($entry['name'])."</td>";
            echo "<td>".number_format($entry['amount'], 2)."</td>";
            echo "<td></td>";
            echo "</tr>";
        }

        echo "<tr style='background-color:".($isIncome ? '#d1e7dd' : '#fff3cd').";font-weight:bold;'>";
        echo "<td colspan='3' style='text-align:right;'>Total</td>";
        echo "<td>".number_format($totals[strtoupper(trim($typeName))] ?? 0, 2)."</td>";
        echo "</tr>";

        $sectionLabel = chr(ord($sectionLabel) + 1);
    }

    echo "<tr style='background-color:#e7f1ff;font-weight:bold;'>";
    echo "<td>".$sectionLabel."</td>";
    echo "<td>Remaining Fund Balance</td>";
    echo "<td></td>";
    echo "<td>".number_format($remaining, 2)."</td>";
    echo "</tr>";

    echo "</table>";
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
    <title>Dashboard</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>

<body id="page-top">
<div id="wrapper">

    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">

            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

                <?php if ($role === 'parent'): ?>

                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">My Children Enrollments</h6>
                            <a href="studentSetup.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Add New Student
                            </a>
                        </div>

                        <div class="card-body">

                            <?php if (!$parentProfile): ?>
                                <div class="alert alert-warning mb-0">
                                    <strong>Account not linked:</strong> We could not find your parent profile in the system.
                                    <br>
                                    Please contact admin to link your login (<strong><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></strong>) to a parent record.
                                </div>

                            <?php elseif (empty($myChildren)): ?>
                                <div class="alert alert-light mb-0">
                                    No enrollments yet. Click <strong>Add New Student</strong>.
                                </div>

                            <?php else: ?>
                                <div class="alert alert-info">
                                    <strong>Note:</strong> After an enrollment is <strong>Approved</strong>, you cannot edit or delete it.
                                    Please contact admin for changes.
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%" cellspacing="0">
                                        <thead>
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Student Name</th>
                                            <th>Class</th>
                                            <th>Plan</th>
                                            <th>Amount</th>
                                            <th>Reference</th>
                                            <th>Proof</th>
                                            <th>Reg Date</th>
                                            <th>Status</th>
                                            <th style="width:190px;">Action</th>
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
                                                <td><?php echo htmlspecialchars($c['student_name'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($c['class_option'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($c['payment_plan'] ?? '-'); ?></td>
                                                <td><?php echo isset($c['payment_amount']) ? '$'.htmlspecialchars($c['payment_amount']) : '-'; ?></td>
                                                <td style="max-width:220px; white-space:normal;">
                                                    <?php echo htmlspecialchars($c['payment_reference'] ?? '-'); ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($c['payment_proof'])): ?>
                                                        <a href="<?php echo htmlspecialchars($c['payment_proof']); ?>" target="_blank">View</a>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($c['registration_date'] ?? ''); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo badge_class($c['approval_status']); ?>" style="padding:8px 10px;">
                                                        <?php echo htmlspecialchars($c['approval_status'] ?? ''); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($isApproved): ?>
                                                        <span class="badge badge-success" style="padding:8px 10px;">Approved</span>
                                                    <?php else: ?>
                                                        <a class="btn btn-info btn-sm" href="studentSetup.php?edit=<?php echo (int)$c['id']; ?>">Edit</a>
                                                        <?php if ($isPending): ?>
                                                            <a class="btn btn-danger btn-sm"
                                                               href="studentSetup.php?delete=<?php echo (int)$c['id']; ?>"
                                                               onclick="return confirm('Delete this enrollment?');">
                                                                Delete
                                                            </a>
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

                    <div class="row">
                        <div class="col-lg-4 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-body">
                                    <h6 class="text-primary font-weight-bold">Total Children Registered</h6>
                                    <div class="h2 mb-0"><?php echo (int)$totalStudents; ?></div>
                                    <div class="mt-3">
                                        <a href="dzoClassManagement.php" class="btn btn-primary btn-sm">
                                            Go to Dzo Class Management
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>

                <!-- Keep your finance UI below, if you want to display it here -->
                <!-- ... -->

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
