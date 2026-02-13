<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/role_helpers.php";
require_login();
allowRoles(['teacher']);

$message = "";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

$teacherUserId = $_SESSION['userid'] ?? null;
$stmt = $pdo->prepare("SELECT id, full_name FROM teachers WHERE user_id = :user_id");
$stmt->execute([':user_id' => $teacherUserId]);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$teacher) {
    die("Teacher record not found.");
}

$teacherId = (int)$teacher['id'];

$stmt = $pdo->prepare(
    "SELECT c.id, c.class_name
     FROM classes c
     WHERE c.teacher_id = :teacher_id AND c.active = 1
     ORDER BY c.class_name"
);
$stmt->execute([':teacher_id' => $teacherId]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allowedClassIds = array_map(function ($class) {
    return (int)$class['id'];
}, $classes);

$attendanceLockDays = 0;
$today = new DateTimeImmutable('today');
$lockDate = $today->modify(sprintf('-%d days', $attendanceLockDays))->format('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_attendance') {
    try {
        $classId = (int)($_POST['class_id'] ?? 0);
        $attendanceDate = $_POST['attendance_date'] ?? date('Y-m-d');
        $statuses = $_POST['status'] ?? [];

        if ($classId === 0) {
            throw new Exception("Class is required.");
        }

        if (!in_array($classId, $allowedClassIds, true)) {
            throw new Exception("You do not have access to this class.");
        }

        if ($attendanceDate < $lockDate) {
            throw new Exception("Attendance is locked for dates before $lockDate.");
        }

        $stmt = $pdo->prepare(
            "SELECT s.id, s.student_name
             FROM class_assignments ca
             INNER JOIN students s ON s.id = ca.student_id
             WHERE ca.class_id = :class_id AND s.approval_status = 'Approved'
             ORDER BY s.student_name"
        );
        $stmt->execute([':class_id' => $classId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($students as $student) {
            $studentId = (int)$student['id'];
            $status = $statuses[$studentId] ?? 'Absent';

            $stmtCheck = $pdo->prepare(
                "SELECT id FROM attendance WHERE class_id = :class_id AND student_id = :student_id AND attendance_date = :attendance_date"
            );
            $stmtCheck->execute([
                ':class_id' => $classId,
                ':student_id' => $studentId,
                ':attendance_date' => $attendanceDate
            ]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE attendance
                     SET status = :status, marked_at = NOW()
                     WHERE id = :id"
                );
                $stmtUpdate->execute([
                    ':status' => $status,
                    ':id' => $existing['id']
                ]);
            } else {
                $stmtInsert = $pdo->prepare(
                    "INSERT INTO attendance (class_id, student_id, teacher_id, attendance_date, status)
                     VALUES (:class_id, :student_id, :teacher_id, :attendance_date, :status)"
                );
                $stmtInsert->execute([
                    ':class_id' => $classId,
                    ':student_id' => $studentId,
                    ':teacher_id' => $teacherId,
                    ':attendance_date' => $attendanceDate,
                    ':status' => $status
                ]);
            }
        }

        $message = "Attendance saved.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$studentList = [];

if ($selectedClassId > 0 && !in_array($selectedClassId, $allowedClassIds, true)) {
    $selectedClassId = 0;
    $message = "You do not have access to that class.";
}

if ($selectedDate < $lockDate) {
    $selectedClassId = 0;
    $message = "Attendance is locked for dates before $lockDate.";
}

if ($selectedClassId > 0) {
    $stmt = $pdo->prepare(
        "SELECT s.id, s.student_name,
                COALESCE(a.status, 'Absent') AS attendance_status
         FROM class_assignments ca
         INNER JOIN students s ON s.id = ca.student_id
         LEFT JOIN attendance a
            ON a.class_id = ca.class_id AND a.student_id = s.id AND a.attendance_date = :attendance_date
         WHERE ca.class_id = :class_id AND s.approval_status = 'Approved'
         ORDER BY s.student_name"
    );
    $stmt->execute([
        ':class_id' => $selectedClassId,
        ':attendance_date' => $selectedDate
    ]);
    $studentList = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <h1 class="h3 mb-4 text-gray-800">Attendance</h1>

                <?php if ($message): ?>
                    <div class="alert <?php echo (stripos($message, 'Error') === 0) ? 'alert-danger' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Select Class</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET">
                            <div class="form-row row">
                                <div class="form-group col-md-5">
                                    <label>Class</label>
                                    <select name="class_id" class="form-control" required>
                                        <option value="">-- Select Class --</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo (int)$class['id']; ?>" <?php echo ($selectedClassId === (int)$class['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group col-md-4">
                                    <label>Date</label>
                                    <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                                </div>

                                <div class="form-group col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary btn-block">Load Students</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($selectedClassId > 0): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Mark Attendance</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="save_attendance">
                                <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
                                <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($selectedDate); ?>">

                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%" cellspacing="0">
                                        <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Status</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($studentList as $student): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                                <td>
                                                    <select name="status[<?php echo (int)$student['id']; ?>]" class="form-control">
                                                        <option value="Present" <?php echo ($student['attendance_status'] === 'Present') ? 'selected' : ''; ?>>Present</option>
                                                        <option value="Absent" <?php echo ($student['attendance_status'] === 'Absent') ? 'selected' : ''; ?>>Absent</option>
                                                        <option value="Late" <?php echo ($student['attendance_status'] === 'Late') ? 'selected' : ''; ?>>Late</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($studentList)): ?>
                                            <tr><td colspan="2" class="text-center">No students assigned to this class.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <button type="submit" class="btn btn-primary">Save Attendance</button>
                            </form>
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
