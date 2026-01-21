<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') {
    header("Location: index-admin.php");
    exit;
}

$message = "";
$reload = false;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

$date = $_GET['date'] ?? date('Y-m-d');
$session = $_GET['session'] ?? 'AM';
if (!in_array($session, ['AM','PM'], true)) $session = 'AM';

// Save attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $date = $_POST['date'] ?? date('Y-m-d');
        $session = $_POST['session'] ?? 'AM';
        if (!in_array($session, ['AM','PM'], true)) $session = 'AM';

        $rows = $_POST['att'] ?? [];
        $remarks = $_POST['remarks'] ?? [];

        $stmtUpsert = $pdo->prepare("
            INSERT INTO attendance (student_pk, attendance_date, session, status, remarks, marked_by)
            VALUES (:student_pk, :d, :s, :st, :rm, :mb)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                remarks = VALUES(remarks),
                marked_by = VALUES(marked_by)
        ");

        foreach ($rows as $student_pk => $status) {
            $student_pk = (int)$student_pk;
            if ($student_pk <= 0) continue;

            if (!in_array($status, ['Present','Absent','Late'], true)) continue;

            $rm = isset($remarks[$student_pk]) ? trim($remarks[$student_pk]) : null;
            $rm = ($rm === '') ? null : $rm;

            $stmtUpsert->execute([
                ':student_pk' => $student_pk,
                ':d' => $date,
                ':s' => $session,
                ':st' => $status,
                ':rm' => $rm,
                ':mb' => $_SESSION['username'] ?? ($_SESSION['userid'] ?? 'admin')
            ]);
        }

        $message = "Attendance saved successfully.";
        $reload = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Approved students list + existing attendance for date/session
$stmtStudents = $pdo->prepare("
    SELECT s.id, s.student_id, s.student_name, s.class_option, p.full_name AS parent_name
    FROM students s
    LEFT JOIN parents p ON p.id = s.parentId
    WHERE LOWER(s.approval_status) = 'approved'
    ORDER BY s.student_name ASC
");
$stmtStudents->execute();
$students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

$stmtExisting = $pdo->prepare("
    SELECT student_pk, status, remarks
    FROM attendance
    WHERE attendance_date = :d AND session = :s
");
$stmtExisting->execute([':d' => $date, ':s' => $session]);
$existing = [];
foreach ($stmtExisting->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $existing[(int)$r['student_pk']] = $r;
}
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <h1 class="h3 mb-2 text-gray-800">Attendance</h1>

                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const msg = <?php echo json_encode($message); ?>;
                    const reload = <?php echo $reload ? 'true' : 'false'; ?>;
                    if (msg) {
                        Swal.fire({ icon: msg.toLowerCase().startsWith('error') ? 'error':'success', title: msg, showConfirmButton:false, timer: 1400 })
                        .then(() => { if (reload) window.location.href = 'attendanceManagement.php?date=<?php echo htmlspecialchars($date); ?>&session=<?php echo htmlspecialchars($session); ?>'; });
                    }
                });
                </script>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Select Date & Session</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="form-inline">
                            <label class="mr-2">Date</label>
                            <input type="date" class="form-control mr-3" name="date" value="<?php echo htmlspecialchars($date); ?>">
                            <label class="mr-2">Session</label>
                            <select class="form-control mr-3" name="session">
                                <option value="AM" <?php echo $session==='AM'?'selected':''; ?>>AM</option>
                                <option value="PM" <?php echo $session==='PM'?'selected':''; ?>>PM</option>
                            </select>
                            <button class="btn btn-primary" type="submit">Load</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Mark Attendance (Approved Students)</h6>
                        <a href="dzoClassManagement.php" class="btn btn-secondary btn-sm">Back to Enrollments</a>
                    </div>

                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
                            <input type="hidden" name="session" value="<?php echo htmlspecialchars($session); ?>">

                            <div class="table-responsive">
                                <table class="table table-bordered" width="100%" cellspacing="0">
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Parent</th>
                                        <th>Status</th>
                                        <th>Remarks (optional)</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($students as $i => $s): ?>
                                        <?php
                                            $pk = (int)$s['id'];
                                            $cur = $existing[$pk]['status'] ?? '';
                                            $rm  = $existing[$pk]['remarks'] ?? '';
                                        ?>
                                        <tr>
                                            <td><?php echo (int)($i+1); ?></td>
                                            <td><?php echo htmlspecialchars(($s['student_name'] ?? '').' ('.($s['student_id'] ?? '').')'); ?></td>
                                            <td><?php echo htmlspecialchars($s['class_option'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($s['parent_name'] ?? '-'); ?></td>
                                            <td>
                                                <select class="form-control" name="att[<?php echo $pk; ?>]" required>
                                                    <option value="">-- Select --</option>
                                                    <option value="Present" <?php echo $cur==='Present'?'selected':''; ?>>Present</option>
                                                    <option value="Absent" <?php echo $cur==='Absent'?'selected':''; ?>>Absent</option>
                                                    <option value="Late" <?php echo $cur==='Late'?'selected':''; ?>>Late</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control" name="remarks[<?php echo $pk; ?>]" value="<?php echo htmlspecialchars($rm); ?>" placeholder="Optional">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <button class="btn btn-success" type="submit">
                                <i class="fas fa-save"></i> Save Attendance
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
