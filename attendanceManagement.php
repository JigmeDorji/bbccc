<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') {
    header("Location: index-admin");
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

// Load classes for selector
$classesList = $pdo->query("SELECT id, class_name FROM classes WHERE active=1 ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

$date = $_GET['date'] ?? date('Y-m-d');
$classId = (int)($_GET['class_id'] ?? ($classesList[0]['id'] ?? 0));

// Resolve a teacher_id for admin marking (use teacher assigned to class, or first teacher)
$adminTeacherId = 0;
if ($classId) {
    $t = $pdo->prepare("SELECT teacher_id FROM classes WHERE id=:cid AND teacher_id IS NOT NULL");
    $t->execute([':cid'=>$classId]);
    $adminTeacherId = (int)$t->fetchColumn();
}
if (!$adminTeacherId) {
    $adminTeacherId = (int)$pdo->query("SELECT id FROM teachers LIMIT 1")->fetchColumn();
}

// Save attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $date = $_POST['date'] ?? date('Y-m-d');
        $classId = (int)($_POST['class_id'] ?? 0);
        if (!$classId) throw new Exception("Please select a class.");

        $rows = $_POST['att'] ?? [];
        $notes = $_POST['notes'] ?? [];

        // Resolve teacher_id for this class
        $t = $pdo->prepare("SELECT teacher_id FROM classes WHERE id=:cid AND teacher_id IS NOT NULL");
        $t->execute([':cid'=>$classId]);
        $teacherId = (int)$t->fetchColumn();
        if (!$teacherId) {
            $teacherId = (int)$pdo->query("SELECT id FROM teachers LIMIT 1")->fetchColumn();
        }
        if (!$teacherId) throw new Exception("No teacher found. Please set up a teacher first.");

        foreach ($rows as $studentId => $status) {
            $studentId = (int)$studentId;
            if ($studentId <= 0) continue;
            if (!in_array($status, ['Present','Absent','Late'], true)) continue;

            $note = isset($notes[$studentId]) ? trim($notes[$studentId]) : null;
            $note = ($note === '') ? null : $note;

            // Check if record exists
            $chk = $pdo->prepare("SELECT id FROM attendance WHERE class_id=:cid AND student_id=:sid AND attendance_date=:d");
            $chk->execute([':cid'=>$classId, ':sid'=>$studentId, ':d'=>$date]);
            $existId = $chk->fetchColumn();

            if ($existId) {
                $upd = $pdo->prepare("UPDATE attendance SET status=:st, notes=:n, marked_at=NOW() WHERE id=:id");
                $upd->execute([':st'=>$status, ':n'=>$note, ':id'=>$existId]);
            } else {
                $ins = $pdo->prepare("INSERT INTO attendance (class_id, student_id, teacher_id, attendance_date, status, notes) VALUES (:cid,:sid,:tid,:d,:st,:n)");
                $ins->execute([':cid'=>$classId, ':sid'=>$studentId, ':tid'=>$teacherId, ':d'=>$date, ':st'=>$status, ':n'=>$note]);
            }
        }

        $message = "Attendance saved successfully.";
        $reload = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Students assigned to selected class + existing attendance for date
$students = [];
$existing = [];
if ($classId) {
    $stmtStudents = $pdo->prepare("
        SELECT s.id, s.student_id, s.student_name, s.class_option, p.full_name AS parent_name
        FROM class_assignments ca
        JOIN students s ON s.id = ca.student_id
        LEFT JOIN parents p ON p.id = s.parentId
        WHERE ca.class_id = :cid AND LOWER(s.approval_status) = 'approved'
        ORDER BY s.student_name ASC
    ");
    $stmtStudents->execute([':cid' => $classId]);
    $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

    $stmtExisting = $pdo->prepare("
        SELECT student_id, status, notes
        FROM attendance
        WHERE class_id = :cid AND attendance_date = :d
    ");
    $stmtExisting->execute([':cid' => $classId, ':d' => $date]);
    foreach ($stmtExisting->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[(int)$r['student_id']] = $r;
    }
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
                        .then(() => { if (reload) window.location.href = 'attendanceManagement.php?date=<?php echo htmlspecialchars($date); ?>&class_id=<?php echo (int)$classId; ?>'; });
                    }
                });
                </script>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Select Class & Date</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="form-inline">
                            <label class="mr-2">Class</label>
                            <select class="form-control mr-3" name="class_id">
                                <?php foreach ($classesList as $cl): ?>
                                <option value="<?php echo (int)$cl['id']; ?>" <?php echo $classId==(int)$cl['id']?'selected':''; ?>><?php echo htmlspecialchars($cl['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="mr-2">Date</label>
                            <input type="date" class="form-control mr-3" name="date" value="<?php echo htmlspecialchars($date); ?>">
                            <button class="btn btn-primary" type="submit">Load</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Mark Attendance (Approved Students)</h6>
                        <a href="dzoClassManagement" class="btn btn-secondary btn-sm">Back to Enrollments</a>
                    </div>

                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
                            <input type="hidden" name="class_id" value="<?php echo (int)$classId; ?>">

                            <div class="table-responsive">
                                <table class="table table-bordered" width="100%" cellspacing="0">
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Parent</th>
                                        <th>Status</th>
                                        <th>Notes (optional)</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($students as $i => $s): ?>
                                        <?php
                                            $pk = (int)$s['id'];
                                            $cur = $existing[$pk]['status'] ?? '';
                                            $rm  = $existing[$pk]['notes'] ?? '';
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
                                                <input type="text" class="form-control" name="notes[<?php echo $pk; ?>]" value="<?php echo htmlspecialchars($rm); ?>" placeholder="Optional">
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
