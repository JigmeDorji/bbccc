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

        $rows = $_POST['status'] ?? [];

        // Resolve teacher_id for this class
        $t = $pdo->prepare("SELECT teacher_id FROM classes WHERE id=:cid AND teacher_id IS NOT NULL");
        $t->execute([':cid'=>$classId]);
        $teacherId = (int)$t->fetchColumn();
        if (!$teacherId) {
            $teacherId = (int)$pdo->query("SELECT id FROM teachers LIMIT 1")->fetchColumn();
        }
        if (!$teacherId) throw new Exception("No teacher found. Please set up a teacher first.");
        $recordedAt = date('Y-m-d H:i:s');
        $batchId = uniqid('att_', true);

        foreach ($rows as $studentId => $status) {
            $studentId = (int)$studentId;
            if ($studentId <= 0) continue;
            $status = trim((string)$status);
            if (!in_array($status, ['Present','Absent','Late'], true)) {
                throw new Exception("Please select Present, Absent, or Late for each student before saving.");
            }

            $ins = $pdo->prepare("INSERT INTO attendance (class_id, student_id, teacher_id, attendance_date, status, marked_at, batch_id) VALUES (:cid,:sid,:tid,:d,:st,:m,:b)");
            $ins->execute([':cid'=>$classId, ':sid'=>$studentId, ':tid'=>$teacherId, ':d'=>$date, ':st'=>$status, ':m'=>$recordedAt, ':b'=>$batchId]);
        }

        $message = "Attendance saved successfully.";
        $reload = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Students assigned to selected class
$students = [];
$selectedClassMeta = null;
if ($classId) {
    $metaStmt = $pdo->prepare("
        SELECT c.class_name, t.full_name AS teacher_name
        FROM classes c
        LEFT JOIN teachers t ON t.id = c.teacher_id
        WHERE c.id = :cid
        LIMIT 1
    ");
    $metaStmt->execute([':cid' => $classId]);
    $selectedClassMeta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtStudents = $pdo->prepare("
        SELECT DISTINCT s.id, s.student_id, s.student_name, c.class_name,
               CASE WHEN ar.child_id IS NOT NULL THEN 'Absent' ELSE '' END AS attendance_status,
               CASE WHEN ar.child_id IS NOT NULL THEN 1 ELSE 0 END AS has_absence_request
        FROM class_assignments ca
        JOIN students s ON s.id = ca.student_id
        LEFT JOIN classes c ON c.id = ca.class_id
        LEFT JOIN (
            SELECT child_id, absence_date, status
            FROM pcm_absence_requests
            WHERE status <> 'Rejected'
        ) ar ON ar.child_id = s.id AND ar.absence_date = :d
        WHERE ca.class_id = :cid AND LOWER(s.approval_status) = 'approved'
        ORDER BY s.student_name ASC
    ");
    $stmtStudents->execute([':cid' => $classId, ':d' => $date]);
    $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);
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
                        <form method="GET">
                            <div class="form-row row">
                                <div class="form-group col-md-5">
                                    <label>Class</label>
                                    <select class="form-control" name="class_id" required>
                                        <option value="">-- Select Class --</option>
                                        <?php foreach ($classesList as $cl): ?>
                                            <option value="<?php echo (int)$cl['id']; ?>" <?php echo $classId==(int)$cl['id']?'selected':''; ?>>
                                                <?php echo htmlspecialchars($cl['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Date</label>
                                    <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($date); ?>">
                                </div>
                                <div class="form-group col-md-3 d-flex align-items-end">
                                    <button class="btn btn-primary btn-block" type="submit">Load Students</button>
                                </div>
                            </div>
                        </form>
                        <?php if ($classId > 0): ?>
                            <div class="mt-2">
                                <a class="btn btn-outline-primary btn-sm" href="attendance-records?as=teacher&class_id=<?= (int)$classId ?>&from_date=<?= htmlspecialchars($date) ?>&to_date=<?= htmlspecialchars($date) ?>">
                                    <i class="fas fa-edit mr-1"></i> Edit Attendance History
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($classId > 0): ?>
                <div class="alert alert-info">
                    <strong>Class:</strong> <?php echo htmlspecialchars((string)($selectedClassMeta['class_name'] ?? 'Selected Class')); ?>
                    &nbsp;|&nbsp;
                    <strong>Assigned Teacher:</strong> <?php echo htmlspecialchars((string)($selectedClassMeta['teacher_name'] ?? 'Not Assigned')); ?>
                </div>
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Mark Attendance</h6>
                        <a href="dzoClassManagement" class="btn btn-secondary btn-sm">Back to Enrollments</a>
                    </div>

                    <div class="card-body">
                        <form method="POST" id="adminAttendanceForm">
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
                            <input type="hidden" name="class_id" value="<?php echo (int)$classId; ?>">

                            <div class="table-responsive">
                                <table class="table table-bordered" width="100%" cellspacing="0">
                                    <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th class="text-center text-success">Present</th>
                                        <th class="text-center text-danger">Absent</th>
                                        <th class="text-center text-warning">Late</th>
                                        <th style="width:220px;">Absence Request</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($students as $s): ?>
                                        <?php
                                            $pk = (int)$s['id'];
                                            $cur = (string)($s['attendance_status'] ?? '');
                                            $hasAbsence = (int)($s['has_absence_request'] ?? 0) === 1;
                                            $isAbsentNow = ($cur === 'Absent');
                                        ?>
                                        <tr class="<?= ($hasAbsence && $isAbsentNow) ? 'table-danger' : '' ?>">
                                            <td><?php echo htmlspecialchars(($s['student_name'] ?? '') . (($s['student_id'] ?? '') !== '' ? ' ('.$s['student_id'].')' : '')); ?></td>
                                            <td><?php echo htmlspecialchars($s['class_name'] ?? '-'); ?></td>
                                            <input type="hidden" name="status[<?php echo $pk; ?>]" value="<?php echo htmlspecialchars($cur); ?>" class="att-hidden-status" data-student="<?= $pk ?>">
                                            <td class="att-choice-cell present <?= ($cur === 'Present') ? 'active' : '' ?>">
                                                <label class="choice-wrap mb-0 text-success">
                                                    <input type="checkbox" class="att-check present-check" data-student="<?= $pk ?>" data-status="Present" <?php echo ($cur === 'Present') ? 'checked' : ''; ?>>
                                                    <span>P</span>
                                                </label>
                                            </td>
                                            <td class="att-choice-cell absent <?= ($cur === 'Absent') ? 'active' : '' ?>">
                                                <label class="choice-wrap mb-0 text-danger">
                                                    <input type="checkbox" class="att-check absent-check" data-student="<?= $pk ?>" data-status="Absent" <?php echo ($cur === 'Absent') ? 'checked' : ''; ?>>
                                                    <span>A</span>
                                                </label>
                                            </td>
                                            <td class="att-choice-cell late <?= ($cur === 'Late') ? 'active' : '' ?>">
                                                <label class="choice-wrap mb-0 text-warning">
                                                    <input type="checkbox" class="att-check late-check" data-student="<?= $pk ?>" data-status="Late" <?php echo ($cur === 'Late') ? 'checked' : ''; ?>>
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
                                    <?php if (empty($students)): ?>
                                        <tr><td colspan="6" class="text-center">No students assigned to this class.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <button class="btn btn-success" type="submit">
                                <i class="fas fa-save"></i> Save Attendance
                            </button>
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
            var current = $row.find('.att-hidden-status[data-student="' + studentId + '"]').val() || '';
            if (current) setRowStatus($row, studentId, current);
        }
    });

    $('#adminAttendanceForm').on('submit', function (e) {
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
