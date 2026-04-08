<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/role_helpers.php";
require_login();

$message = "";
$isAdmin = is_admin_role();

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
} catch (Exception $e) {
    bbcc_fail_db($e);
}

function bbcc_ensure_attendance_batch_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $stmt = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'batch_id'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN batch_id VARCHAR(48) NULL AFTER marked_at");
    }
    try {
        $idx = $pdo->query("SHOW INDEX FROM attendance WHERE Key_name = 'uniq_attendance_day'");
        if ($idx && $idx->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE attendance DROP INDEX uniq_attendance_day");
        }
    } catch (Throwable $e) {
        error_log('[BBCC] attendance index migration skipped: ' . $e->getMessage());
    }
    $done = true;
}
bbcc_ensure_attendance_batch_column($pdo);

$teacherId = null;
$classes = [];
$sessionUserId = (string)($_SESSION['userid'] ?? '');
$sessionUsername = (string)($_SESSION['username'] ?? '');

$teacherStmt = $pdo->prepare("
    SELECT id, full_name
    FROM teachers
    WHERE (user_id = :uid AND :uid <> '')
       OR LOWER(email) = LOWER(:em)
    ORDER BY id ASC
    LIMIT 1
");
$teacherStmt->execute([':uid' => $sessionUserId, ':em' => $sessionUsername]);
$teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);

if (!$isAdmin && !$teacher) {
    header("Location: unauthorized");
    exit;
}

if ($isAdmin) {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.class_name
         FROM classes c
         WHERE c.active = 1
         ORDER BY c.class_name"
    );
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $teacherId = (int)$teacher['id'];

    $stmt = $pdo->prepare(
        "SELECT c.id, c.class_name
         FROM classes c
         WHERE c.teacher_id = :teacher_id AND c.active = 1
         ORDER BY c.class_name"
    );
    $stmt->execute([':teacher_id' => $teacherId]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
            "SELECT DISTINCT s.id, s.student_name
             FROM class_assignments ca
             INNER JOIN students s ON s.id = ca.student_id
             WHERE ca.class_id = :class_id AND s.approval_status = 'Approved'
             ORDER BY s.student_name"
        );
        $stmt->execute([':class_id' => $classId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $recordedAt = date('Y-m-d H:i:s');
        $batchId = uniqid('att_', true);

        foreach ($students as $student) {
            $studentId = (int)$student['id'];
            $status = trim((string)($statuses[$studentId] ?? ''));
            if (!in_array($status, ['Present', 'Absent', 'Late'], true)) {
                throw new Exception("Please select Present, Absent, or Late for each student before saving.");
            }
            $stmtInsert = $pdo->prepare(
                "INSERT INTO attendance (class_id, student_id, teacher_id, attendance_date, status, marked_at, batch_id)
                 VALUES (:class_id, :student_id, :teacher_id, :attendance_date, :status, :marked_at, :batch_id)"
            );
            $stmtInsert->execute([
                ':class_id' => $classId,
                ':student_id' => $studentId,
                ':teacher_id' => $teacherId,
                ':attendance_date' => $attendanceDate,
                ':status' => $status,
                ':marked_at' => $recordedAt,
                ':batch_id' => $batchId
            ]);
        }

        $message = "Attendance saved.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$studentList = [];
$selectedClassMeta = null;

if ($selectedClassId > 0 && !in_array($selectedClassId, $allowedClassIds, true)) {
    $selectedClassId = 0;
    $message = "You do not have access to that class.";
}

if ($selectedDate < $lockDate) {
    $selectedClassId = 0;
    $message = "Attendance is locked for dates before $lockDate.";
}

if ($selectedClassId > 0) {
    $metaStmt = $pdo->prepare(
        "SELECT c.class_name, t.full_name AS teacher_name
         FROM classes c
         LEFT JOIN teachers t ON t.id = c.teacher_id
         WHERE c.id = :class_id
         LIMIT 1"
    );
    $metaStmt->execute([':class_id' => $selectedClassId]);
    $selectedClassMeta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.id, s.student_name,
                CASE WHEN ar.child_id IS NOT NULL THEN 'Absent' ELSE '' END AS attendance_status,
                CASE WHEN ar.child_id IS NOT NULL THEN 1 ELSE 0 END AS has_absence_request
         FROM class_assignments ca
         INNER JOIN students s ON s.id = ca.student_id
         LEFT JOIN (
            SELECT child_id, absence_date, status
            FROM pcm_absence_requests
            WHERE status <> 'Rejected'
         ) ar ON ar.child_id = s.id AND ar.absence_date = :attendance_date
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
    <style>
    .att-choice-cell { min-width: 96px; text-align: center; vertical-align: middle !important; }
    .att-choice-cell .choice-wrap { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; }
    .att-choice-cell .att-check { transform: scale(1.45); cursor: pointer; }
    .att-choice-cell .att-check.present-check { accent-color: #28a745; }
    .att-choice-cell .att-check.absent-check { accent-color: #dc3545; }
    .att-choice-cell .att-check.late-check { accent-color: #f0ad4e; }
    .att-choice-cell.present { background: #f0fff4; }
    .att-choice-cell.absent { background: #fff5f5; }
    .att-choice-cell.late { background: #fffaf0; }
    .att-choice-cell.active.present { background: #d4edda; }
    .att-choice-cell.active.absent { background: #f8d7da; }
    .att-choice-cell.active.late { background: #fff3cd; }
    </style>
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
                        <?php if ($selectedClassId > 0): ?>
                            <div class="mt-2">
                                <?php
                                $historyBase = $isAdmin ? 'attendance-records' : 'attendance-records?as=teacher';
                                $historyUrl = $historyBase
                                    . (str_contains($historyBase, '?') ? '&' : '?')
                                    . 'class_id=' . (int)$selectedClassId
                                    . '&from_date=' . urlencode((string)$selectedDate)
                                    . '&to_date=' . urlencode((string)$selectedDate);
                                ?>
                                <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($historyUrl, ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-edit mr-1"></i> Edit Attendance History
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($selectedClassId > 0): ?>
                    <div class="alert alert-info">
                        <strong>Class:</strong> <?php echo htmlspecialchars((string)($selectedClassMeta['class_name'] ?? 'Selected Class')); ?>
                        &nbsp;|&nbsp;
                        <strong>Assigned Teacher:</strong> <?php echo htmlspecialchars((string)($selectedClassMeta['teacher_name'] ?? 'Not Assigned')); ?>
                    </div>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Mark Attendance</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="teacherAttendanceForm">
                                <input type="hidden" name="action" value="save_attendance">
                                <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
                                <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($selectedDate); ?>">

                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%" cellspacing="0">
                                        <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th class="text-center text-success">Present</th>
                                            <th class="text-center text-danger">Absent</th>
                                            <th class="text-center text-warning">Late</th>
                                            <th style="width:220px;">Absence Request</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($studentList as $student): ?>
                                            <?php
                                                $hasAbsence = (int)($student['has_absence_request'] ?? 0) === 1;
                                                $isAbsentNow = ((string)($student['attendance_status'] ?? '') === 'Absent');
                                                $sid = (int)$student['id'];
                                            ?>
                                            <tr class="<?= ($hasAbsence && $isAbsentNow) ? 'table-danger' : '' ?>">
                                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                                <input type="hidden" name="status[<?php echo $sid; ?>]" value="<?php echo htmlspecialchars((string)$student['attendance_status']); ?>" class="att-hidden-status" data-student="<?= $sid ?>">
                                                <td class="att-choice-cell present <?= ($student['attendance_status'] === 'Present') ? 'active' : '' ?>">
                                                    <label class="choice-wrap mb-0 text-success">
                                                        <input type="checkbox" class="att-check present-check" data-student="<?= $sid ?>" data-status="Present" <?php echo ($student['attendance_status'] === 'Present') ? 'checked' : ''; ?>>
                                                        <span>P</span>
                                                    </label>
                                                </td>
                                                <td class="att-choice-cell absent <?= ($student['attendance_status'] === 'Absent') ? 'active' : '' ?>">
                                                    <label class="choice-wrap mb-0 text-danger">
                                                        <input type="checkbox" class="att-check absent-check" data-student="<?= $sid ?>" data-status="Absent" <?php echo ($student['attendance_status'] === 'Absent') ? 'checked' : ''; ?>>
                                                        <span>A</span>
                                                    </label>
                                                </td>
                                                <td class="att-choice-cell late <?= ($student['attendance_status'] === 'Late') ? 'active' : '' ?>">
                                                    <label class="choice-wrap mb-0 text-warning">
                                                        <input type="checkbox" class="att-check late-check" data-student="<?= $sid ?>" data-status="Late" <?php echo ($student['attendance_status'] === 'Late') ? 'checked' : ''; ?>>
                                                        <span>L</span>
                                                    </label>
                                                </td>
                                                <td>
                                                    <?php if ($hasAbsence): ?>
                                                        <span class="badge badge-danger">Parent Marked Absent</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($studentList)): ?>
                                            <tr><td colspan="5" class="text-center">No students assigned to this class.</td></tr>
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
<script src="vendor/jquery/jquery.min.js"></script>
<script>
$(function () {
    function setRowStatus($row, studentId, status) {
        $row.find('.att-check').prop('checked', false);
        $row.find('.att-check[data-status="' + status + '"]').prop('checked', true);
        $row.find('.att-hidden-status[data-student="' + studentId + '"]').val(status);
        $row.find('.att-choice-cell').removeClass('active');
        if (status === 'Present') {
            $row.find('.att-choice-cell.present').addClass('active');
        } else if (status === 'Absent') {
            $row.find('.att-choice-cell.absent').addClass('active');
        } else if (status === 'Late') {
            $row.find('.att-choice-cell.late').addClass('active');
        }
    }

    $(document).on('change', '.att-check', function () {
        var studentId = $(this).data('student');
        var status = $(this).data('status');
        var $row = $(this).closest('tr');

        if ($(this).is(':checked')) {
            setRowStatus($row, studentId, status);
        } else {
            var current = $row.find('.att-hidden-status[data-student="' + studentId + '"]').val() || 'Absent';
            setRowStatus($row, studentId, current);
        }
    });

    $('#teacherAttendanceForm').on('submit', function (e) {
        var hasMissing = false;
        $('.att-hidden-status').each(function () {
            var val = ($(this).val() || '').trim();
            if (val !== 'Present' && val !== 'Absent' && val !== 'Late') {
                hasMissing = true;
                return false;
            }
        });
        if (hasMissing) {
            e.preventDefault();
            alert('Please select Present, Absent, or Late for each student before saving.');
        }
    });
});
</script>
</html>
