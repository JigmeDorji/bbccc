<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'parent') {
    header("Location: index-admin.php");
    exit;
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

$sessionUsername = $_SESSION['username'] ?? '';
if ($sessionUsername === '') die("Session username missing. Please log out and log in again.");

$stmtParent = $pdo->prepare("SELECT id, full_name, email FROM parents WHERE username = :u LIMIT 1");
$stmtParent->execute([':u' => $sessionUsername]);
$parent = $stmtParent->fetch(PDO::FETCH_ASSOC);
if (!$parent) die("Parent record not found. Please contact admin.");

$parentId = (int)$parent['id'];

$stmt = $pdo->prepare("
    SELECT s.student_name, s.student_id, a.attendance_date, a.session, a.status, a.remarks
    FROM students s
    LEFT JOIN attendance a ON a.student_pk = s.id
    WHERE s.parentId = :pid
    ORDER BY s.student_name ASC, a.attendance_date DESC
");
$stmt->execute([':pid' => $parentId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Attendance</title>
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
                <h1 class="h3 mb-2 text-gray-800">Attendance</h1>

                <div class="alert alert-info">
                    Attendance will appear here after the admin marks it for the class session.
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Your Children Attendance</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Date</th>
                                    <th>Session</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(($r['student_name'] ?? '').' ('.($r['student_id'] ?? '').')'); ?></td>
                                        <td><?php echo htmlspecialchars($r['attendance_date'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($r['session'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($r['status'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($r['remarks'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
