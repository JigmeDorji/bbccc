<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/parent_helpers.php";
require_once "include/notifications.php";
require_login();
allowRoles(['parent']);

function bbcc_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
} catch (Exception $e) {
    bbcc_fail_db($e);
}

$parent = fetch_parent_record($pdo);
if (!$parent) {
    die("Parent account not found. Please contact admin.");
}

$parentId = (int)$parent['id'];
$studentParentColumn = 'parent_id';
$colStmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'parent_id'");
if (!$colStmt || !$colStmt->fetch(PDO::FETCH_ASSOC)) {
    $studentParentColumn = 'parentId';
}

$stats = [
    'students' => 0,
    'pending' => 0,
    'approved' => 0,
    'payments_pending' => 0,
    'notifications_unread' => 0
];
$recentClassroomActivity = [];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE {$studentParentColumn} = ?");
$stmt->execute([$parentId]);
$stats['students'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE {$studentParentColumn} = ? AND approval_status = 'Pending'");
$stmt->execute([$parentId]);
$stats['pending'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE {$studentParentColumn} = ? AND approval_status = 'Approved'");
$stmt->execute([$parentId]);
$stats['approved'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE parent_id = ? AND status = 'Pending'");
$stmt->execute([$parentId]);
$stats['payments_pending'] = (int)$stmt->fetchColumn();

$stats['notifications_unread'] = bbcc_unread_notifications_count(
    $pdo,
    (string)($_SESSION['username'] ?? ''),
    (string)($_SESSION['role'] ?? 'parent')
);

if (
    bbcc_table_exists($pdo, 'classroom_announcements') &&
    bbcc_table_exists($pdo, 'classroom_announcement_classes') &&
    bbcc_table_exists($pdo, 'classroom_reports')
) {
    $stmtActA = $pdo->prepare("
        SELECT a.created_at, a.title, a.category
        FROM classroom_announcements a
        WHERE a.scope_type = 'all_classes'
           OR EXISTS (
                SELECT 1
                FROM classroom_announcement_classes ac
                INNER JOIN class_assignments ca ON ca.class_id = ac.class_id
                INNER JOIN students s ON s.id = ca.student_id
                WHERE ac.announcement_id = a.id
                  AND s.parentId = :pid
           )
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $stmtActA->execute([':pid' => $parentId]);
    foreach ($stmtActA->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentClassroomActivity[] = [
            'type' => 'announcement',
            'title' => (string)($row['title'] ?? 'Classroom Announcement'),
            'detail' => (string)($row['category'] ?? 'Announcement'),
            'at' => (string)($row['created_at'] ?? ''),
            'url' => 'dzongkha-classroom?tab=announcements&as=parent',
        ];
    }

    $stmtActR = $pdo->prepare("
        SELECT r.created_at, r.report_title, s.student_name
        FROM classroom_reports r
        INNER JOIN students s ON s.id = r.student_id
        WHERE s.parentId = :pid
        ORDER BY r.created_at DESC
        LIMIT 8
    ");
    $stmtActR->execute([':pid' => $parentId]);
    foreach ($stmtActR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentClassroomActivity[] = [
            'type' => 'report',
            'title' => 'Report for ' . (string)($row['student_name'] ?? 'Student'),
            'detail' => (string)($row['report_title'] ?? 'Student report updated'),
            'at' => (string)($row['created_at'] ?? ''),
            'url' => 'dzongkha-classroom?tab=reports&as=parent',
        ];
    }
}

usort($recentClassroomActivity, static function (array $a, array $b): int {
    return strtotime((string)$b['at']) <=> strtotime((string)$a['at']);
});
$recentClassroomActivity = array_slice($recentClassroomActivity, 0, 8);
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

                    <div class="col-md-3 mb-4">
                        <div class="card shadow h-100 py-2">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Unread Notifications</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['notifications_unread']; ?></div>
                                <div class="mt-2">
                                    <a href="notifications" class="small">Open notifications</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <a href="parent-students" class="btn btn-primary mr-2">Manage Students</a>
                        <a href="parent-payments" class="btn btn-secondary mr-2">Upload Payment</a>
                        <a href="parent-signinout" class="btn btn-info">Sign In/Out</a>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Recent Classroom Activity</h6>
                        <a href="dzongkha-classroom?as=parent" class="small">Open Classroom</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recentClassroomActivity)): ?>
                            <div class="p-4 text-center text-muted">No recent classroom activity yet.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                    <tr>
                                        <th class="pl-3">Type</th>
                                        <th>Activity</th>
                                        <th>Details</th>
                                        <th class="pr-3">Time</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recentClassroomActivity as $act): ?>
                                        <?php
                                            $type = strtolower((string)($act['type'] ?? 'activity'));
                                            $icon = 'fa-bullhorn';
                                            if ($type === 'report') $icon = 'fa-file-alt';
                                        ?>
                                        <tr>
                                            <td class="pl-3"><i class="fas <?= $icon ?> text-primary mr-1"></i><?= htmlspecialchars(ucfirst($type)); ?></td>
                                            <td><a href="<?= htmlspecialchars((string)($act['url'] ?? 'dzongkha-classroom?as=parent')); ?>"><?= htmlspecialchars((string)($act['title'] ?? 'Activity')); ?></a></td>
                                            <td><?= htmlspecialchars((string)($act['detail'] ?? '-')); ?></td>
                                            <td class="pr-3"><?= !empty($act['at']) ? date('d M Y, h:i A', strtotime((string)$act['at'])) : '-'; ?></td>
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

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
