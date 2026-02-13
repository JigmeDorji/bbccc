<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/parent_helpers.php";
require_login();
allowRoles(['parent']);

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

$parent = fetch_parent_record($pdo);
if (!$parent) {
    die("Parent account not found. Please contact admin.");
}

$parentId = (int)$parent['id'];

$stats = [
    'students' => 0,
    'pending' => 0,
    'approved' => 0,
    'payments_pending' => 0
];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE parent_id = ?");
$stmt->execute([$parentId]);
$stats['students'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE parent_id = ? AND approval_status = 'Pending'");
$stmt->execute([$parentId]);
$stats['pending'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE parent_id = ? AND approval_status = 'Approved'");
$stmt->execute([$parentId]);
$stats['approved'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE parent_id = ? AND status = 'Pending'");
$stmt->execute([$parentId]);
$stats['payments_pending'] = (int)$stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Parent Dashboard</title>
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
                <h1 class="h3 mb-4 text-gray-800">Welcome, <?php echo htmlspecialchars($parent['full_name']); ?></h1>

                <div class="row">
                    <div class="col-md-3 mb-4">
                        <div class="card shadow h-100 py-2">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Students</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['students']; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-4">
                        <div class="card shadow h-100 py-2">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Approvals</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['pending']; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-4">
                        <div class="card shadow h-100 py-2">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Approved Students</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['approved']; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-4">
                        <div class="card shadow h-100 py-2">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Pending Payments</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['payments_pending']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <a href="parent-students.php" class="btn btn-primary mr-2">Manage Students</a>
                        <a href="parent-payments.php" class="btn btn-secondary mr-2">Upload Payment</a>
                        <a href="parent-signinout.php" class="btn btn-info">Sign In/Out</a>
                    </div>
                </div>
            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
