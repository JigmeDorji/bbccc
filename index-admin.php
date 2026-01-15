<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$filterCompanyID = $_SESSION['companyID'] ?? null;
$filterProjectID = $_SESSION['projectID'] ?? null;

$role = strtolower($_SESSION['role'] ?? '');

// ✅ Parent dashboard data
$parentDbId = null;
$parentProfile = null;
$myChildren = [];

// ✅ Admin dashboard data
$totalStudents = 0;
$pendingStudents = [];
$viewStudent = null;

// ✅ action feedback
$actionMessage = "";
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* =========================
       ✅ ADMIN: Approve / Reject
       ========================= */
    if ($role !== 'parent' && isset($_GET['action'], $_GET['student'])) {
        $action = strtolower(trim($_GET['action']));
        $studentId = (int)$_GET['student'];

        if ($studentId > 0 && in_array($action, ['approve', 'reject'], true)) {
            $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';

            $stmt = $pdo->prepare("UPDATE students SET approval_status = :st WHERE id = :id");
            $stmt->execute([':st' => $newStatus, ':id' => $studentId]);

            $actionMessage = "Enrollment " . ($action === 'approve' ? "approved" : "rejected") . " successfully.";
            $reloadPage = true;
        }
    }

    /* =========================
       ✅ ADMIN: View student detail
       ========================= */
    if ($role !== 'parent' && isset($_GET['view'])) {
        $viewId = (int)$_GET['view'];
        if ($viewId > 0) {
            $stmtView = $pdo->prepare("
                SELECT s.*,
                       p.full_name AS parent_name,
                       p.email AS parent_email,
                       p.phone AS parent_phone,
                       p.address AS parent_address,
                       p.occupation AS parent_occupation
                FROM students s
                LEFT JOIN parents p ON p.id = s.parentId
                WHERE s.id = :id
                LIMIT 1
            ");
            $stmtView->execute([':id' => $viewId]);
            $viewStudent = $stmtView->fetch(PDO::FETCH_ASSOC);
        }
    }

    /* =========================
       ✅ PARENT: Load parent + children
       IMPORTANT FIX:
       - Try match session username/email
       - If not found, fallback to first parent (same as studentSetup.php)
       ========================= */
    if ($role === 'parent') {

        $sessionUsername = $_SESSION['username'] ?? '';
        $sessionEmail    = $_SESSION['email'] ?? '';

        // Try match logged-in user
        $stmtParent = $pdo->prepare("
            SELECT id, full_name, email
            FROM parents
            WHERE (username = :u AND :u <> '')
               OR (email = :e AND :e <> '')
            LIMIT 1
        ");
        $stmtParent->execute([
            ':u' => $sessionUsername,
            ':e' => $sessionEmail
        ]);

        $parentProfile = $stmtParent->fetch(PDO::FETCH_ASSOC);

        // ✅ Fallback: first parent record (same logic as your studentSetup.php)
        if (!$parentProfile) {
            $stmtFirstParent = $pdo->query("SELECT id, full_name, email FROM parents ORDER BY id ASC LIMIT 1");
            $parentProfile = $stmtFirstParent->fetch(PDO::FETCH_ASSOC);
        }

        if ($parentProfile) {
            $parentDbId = (int)$parentProfile['id'];

            $stmtKids = $pdo->prepare("
                SELECT id, student_id, student_name, dob, gender, registration_date, approval_status
                FROM students
                WHERE parentId = :pid
                ORDER BY id DESC
            ");
            $stmtKids->execute([':pid' => $parentDbId]);
            $myChildren = $stmtKids->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /* =========================
       ✅ ADMIN: Stats + pending list
       ========================= */
    if ($role !== 'parent') {

        $stmtCount = $pdo->query("SELECT COUNT(*) FROM students");
        $totalStudents = (int)$stmtCount->fetchColumn();

        $stmtPending = $pdo->prepare("
            SELECT s.id, s.student_id, s.student_name, s.dob, s.gender, s.registration_date, s.approval_status,
                   p.full_name AS parent_name, p.email AS parent_email, p.phone AS parent_phone
            FROM students s
            LEFT JOIN parents p ON p.id = s.parentId
            WHERE s.approval_status = 'Pending'
            ORDER BY s.id DESC
            LIMIT 50
        ");
        $stmtPending->execute();
        $pendingStudents = $stmtPending->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================
       ✅ YOUR EXISTING FINANCE CODE (unchanged)
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
}

/* =========================
   Excel export (unchanged)
   ========================= */
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

                <!-- ✅ approve/reject message -->
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const msg = <?php echo json_encode($actionMessage); ?>;
                        const reload = <?php echo $reloadPage ? 'true' : 'false'; ?>;
                        if (msg) {
                            Swal.fire({
                                icon: 'success',
                                title: msg,
                                showConfirmButton: false,
                                timer: 1500
                            }).then(() => {
                                if (reload) window.location.href = 'index-admin.php';
                            });
                        }
                    });
                </script>

                <!-- =========================
                     ✅ PARENT DASHBOARD TABLE
                     ========================= -->
                <?php if ($role === 'parent'): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">My Children Enrollments</h6>
                            <a href="studentSetup.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Add New Student
                            </a>
                        </div>

                        <div class="card-body">

                            <div class="alert alert-info">
                                <strong>Note:</strong> Once enrollment is <strong>Approved</strong>, you cannot edit or delete it.
                                Please contact admin for changes.
                            </div>

                            <!-- Helpful info so you can see what's linked -->
                            <div class="alert alert-light">
                                <strong>Dashboard linked parent:</strong>
                                <?php
                                    if ($parentProfile) {
                                        echo htmlspecialchars(($parentProfile['full_name'] ?? '') . ' - ' . ($parentProfile['email'] ?? ''));
                                    } else {
                                        echo "Not found";
                                    }
                                ?>
                                <br>
                                <small class="text-muted">
                                    Session username: <?php echo htmlspecialchars($_SESSION['username'] ?? '(empty)'); ?> |
                                    Session email: <?php echo htmlspecialchars($_SESSION['email'] ?? '(empty)'); ?>
                                </small>
                            </div>

                            <?php if (!$parentProfile): ?>
                                <div class="alert alert-warning mb-0">
                                    No parent record found. Please create a parent first.
                                </div>
                            <?php elseif (empty($myChildren)): ?>
                                <div class="alert alert-light mb-0">
                                    No enrollments yet. Click <strong>Add New Student</strong>.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%" cellspacing="0">
                                        <thead>
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Student Name</th>
                                            <th>DOB</th>
                                            <th>Gender</th>
                                            <th>Reg Date</th>
                                            <th>Status</th>
                                            <th style="width:170px;">Action</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($myChildren as $c): ?>
                                            <?php
                                                $st = strtolower($c['approval_status'] ?? '');
                                                $isApproved = ($st === 'approved');

                                                $badge = 'secondary';
                                                if ($st === 'pending') $badge = 'warning';
                                                if ($st === 'approved') $badge = 'success';
                                                if ($st === 'rejected') $badge = 'danger';
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($c['student_id'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($c['student_name'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($c['dob'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($c['gender'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($c['registration_date'] ?? ''); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $badge; ?>" style="padding:8px 10px;">
                                                        <?php echo htmlspecialchars($c['approval_status'] ?? ''); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($isApproved): ?>
                                                        <span class="badge badge-success" style="padding:8px 10px;">Approved</span>
                                                    <?php else: ?>
                                                        <a class="btn btn-info btn-sm" href="studentSetup.php?edit=<?php echo (int)$c['id']; ?>">Edit</a>
                                                        <a class="btn btn-danger btn-sm"
                                                           href="studentSetup.php?delete=<?php echo (int)$c['id']; ?>"
                                                           onclick="return confirm('Delete this enrollment?');">
                                                            Delete
                                                        </a>
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
                <?php endif; ?>

                <!-- =========================
                     ✅ ADMIN DASHBOARD PENDING
                     ========================= -->
                <?php if ($role !== 'parent'): ?>

                    <div class="row">
                        <div class="col-lg-4 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-body">
                                    <h6 class="text-primary font-weight-bold">Total Children Registered</h6>
                                    <div class="h2 mb-0"><?php echo (int)$totalStudents; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Pending Enrollments</h6>
                            <span class="badge badge-warning"><?php echo count($pendingStudents); ?> Pending</span>
                        </div>
                        <div class="card-body">

                            <?php if (empty($pendingStudents)): ?>
                                <div class="alert alert-light mb-0">No pending enrollments.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%" cellspacing="0">
                                        <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Student ID</th>
                                            <th>Student Name</th>
                                            <th>Parent Name</th>
                                            <th>Parent Email</th>
                                            <th>Parent Phone</th>
                                            <th>Reg Date</th>
                                            <th>Status</th>
                                            <th>View</th>
                                            <th>Verify</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($pendingStudents as $i => $p): ?>
                                            <tr>
                                                <td><?php echo (int)($i + 1); ?></td>
                                                <td><?php echo htmlspecialchars($p['student_id'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($p['student_name'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($p['parent_name'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($p['parent_email'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($p['parent_phone'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($p['registration_date'] ?? ''); ?></td>
                                                <td><span class="badge badge-warning" style="padding:8px 10px;">Pending</span></td>
                                                <td>
                                                    <a class="btn btn-info btn-sm" href="index-admin.php?view=<?php echo (int)$p['id']; ?>">View</a>
                                                </td>
                                                <td>
                                                    <a class="btn btn-success btn-sm"
                                                       href="index-admin.php?action=approve&student=<?php echo (int)$p['id']; ?>"
                                                       onclick="return confirm('Approve this enrollment?');">
                                                        Approve
                                                    </a>
                                                    <a class="btn btn-danger btn-sm"
                                                       href="index-admin.php?action=reject&student=<?php echo (int)$p['id']; ?>"
                                                       onclick="return confirm('Reject this enrollment?');">
                                                        Reject
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>

                    <?php if ($viewStudent): ?>
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">Child Details</h6>
                                <a href="index-admin.php" class="btn btn-secondary btn-sm">Close</a>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="font-weight-bold mb-3">Student</h6>
                                        <p><strong>Student ID:</strong> <?php echo htmlspecialchars($viewStudent['student_id'] ?? '-'); ?></p>
                                        <p><strong>Name:</strong> <?php echo htmlspecialchars($viewStudent['student_name'] ?? '-'); ?></p>
                                        <p><strong>DOB:</strong> <?php echo htmlspecialchars($viewStudent['dob'] ?? '-'); ?></p>
                                        <p><strong>Gender:</strong> <?php echo htmlspecialchars($viewStudent['gender'] ?? '-'); ?></p>
                                        <p><strong>Medical Issue:</strong> <?php echo htmlspecialchars($viewStudent['medical_issue'] ?? '-'); ?></p>
                                        <p><strong>Reg Date:</strong> <?php echo htmlspecialchars($viewStudent['registration_date'] ?? '-'); ?></p>
                                        <p><strong>Status:</strong> <?php echo htmlspecialchars($viewStudent['approval_status'] ?? '-'); ?></p>
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="font-weight-bold mb-3">Parent</h6>
                                        <p><strong>Name:</strong> <?php echo htmlspecialchars($viewStudent['parent_name'] ?? '-'); ?></p>
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($viewStudent['parent_email'] ?? '-'); ?></p>
                                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($viewStudent['parent_phone'] ?? '-'); ?></p>
                                        <p><strong>Address:</strong> <?php echo htmlspecialchars($viewStudent['parent_address'] ?? '-'); ?></p>
                                        <p><strong>Occupation:</strong> <?php echo htmlspecialchars($viewStudent['parent_occupation'] ?? '-'); ?></p>
                                    </div>
                                </div>

                                <hr>

                                <a class="btn btn-success"
                                   href="index-admin.php?action=approve&student=<?php echo (int)$viewStudent['id']; ?>"
                                   onclick="return confirm('Approve this enrollment?');">
                                    Approve
                                </a>
                                <a class="btn btn-danger"
                                   href="index-admin.php?action=reject&student=<?php echo (int)$viewStudent['id']; ?>"
                                   onclick="return confirm('Reject this enrollment?');">
                                    Reject
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

                <!-- ✅ Your finance UI can remain below as before -->

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
