<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_login();

$message = "";
$ok = false;

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]
    );
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

$isAdmin = is_admin_role();
$teacherId = 0;
$parentId = 0;
$parentChildren = [];
$canEdit = false;
$viewMode = 'none';
$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$requestedAs = strtolower(trim((string)($_GET['as'] ?? ($_SESSION['active_portal'] ?? ''))));

// Determine mode by active role first.
if ($isAdmin) {
    $viewMode = 'admin';
    $canEdit = true;
} elseif ($currentRole === 'parent') {
    $stmtParent = $pdo->prepare("SELECT id FROM parents WHERE username = :u LIMIT 1");
    $stmtParent->execute([':u' => (string)($_SESSION['username'] ?? '')]);
    $parentId = (int)$stmtParent->fetchColumn();
    if ($parentId > 0) {
        $viewMode = 'parent';
        $canEdit = false;
    }
}

// Detect teacher profile (needed for mixed accounts + explicit teacher override).
$sessionUserId = (string)($_SESSION['userid'] ?? '');
$sessionUsername = (string)($_SESSION['username'] ?? '');
$stmtTeacher = $pdo->prepare("
    SELECT id
    FROM teachers
    WHERE (user_id = :uid AND :uid <> '')
       OR LOWER(email) = LOWER(:em)
    ORDER BY id ASC
    LIMIT 1
");
$stmtTeacher->execute([':uid' => $sessionUserId, ':em' => $sessionUsername]);
$teacherId = (int)$stmtTeacher->fetchColumn();

if ($viewMode === 'none' && $teacherId > 0) {
    $viewMode = 'teacher';
    $canEdit = true;
}

// Mixed account support: allow explicit teacher-mode override when teacher profile exists.
if ($requestedAs === 'teacher' && $teacherId > 0) {
    $viewMode = 'teacher';
    $canEdit = true;
}
if ($requestedAs === 'parent') {
    if ($parentId <= 0) {
        $stmtParent = $pdo->prepare("SELECT id FROM parents WHERE username = :u LIMIT 1");
        $stmtParent->execute([':u' => (string)($_SESSION['username'] ?? '')]);
        $parentId = (int)$stmtParent->fetchColumn();
    }
    if ($parentId > 0) {
        $viewMode = 'parent';
        $canEdit = false;
    }
}

if ($viewMode === 'none' || is_patron_role()) {
    header("Location: unauthorized");
    exit;
}

if (in_array($viewMode, ['teacher', 'parent'], true)) {
    $_SESSION['active_portal'] = $viewMode;
}

if ($viewMode === 'parent') {
    $stmtChildren = $pdo->prepare("
        SELECT id, student_name, student_id
        FROM students
        WHERE parentId = :pid
        ORDER BY student_name
    ");
    $stmtChildren->execute([':pid' => $parentId]);
    $parentChildren = $stmtChildren->fetchAll(PDO::FETCH_ASSOC);
}

// Allowed classes for filtering/edit access
if ($viewMode === 'admin') {
    $classes = $pdo->query("SELECT id, class_name FROM classes WHERE active = 1 ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($viewMode === 'teacher') {
    $stmtClasses = $pdo->prepare("SELECT id, class_name FROM classes WHERE active = 1 AND teacher_id = :tid ORDER BY class_name");
    $stmtClasses->execute([':tid' => $teacherId]);
    $classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtClasses = $pdo->prepare("
        SELECT DISTINCT c.id, c.class_name
        FROM attendance a
        INNER JOIN students s ON s.id = a.student_id
        LEFT JOIN classes c ON c.id = a.class_id
        WHERE s.parentId = :pid
        ORDER BY c.class_name
    ");
    $stmtClasses->execute([':pid' => $parentId]);
    $classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);
}
$allowedClassIds = array_map('intval', array_column($classes, 'id'));

// Update one attendance record
if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_record') {
    try {
        $attendanceId = (int)($_POST['attendance_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        $attendanceDate = trim((string)($_POST['attendance_date'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $notes = $notes === '' ? null : $notes;

        if ($attendanceId <= 0) {
            throw new Exception("Invalid attendance record.");
        }
        if (!in_array($status, ['Present', 'Absent', 'Late'], true)) {
            throw new Exception("Please select a valid status.");
        }
        if ($attendanceDate === '') {
            throw new Exception("Attendance date is required.");
        }

        if ($viewMode === 'admin') {
            $ownStmt = $pdo->prepare("SELECT a.id FROM attendance a WHERE a.id = :id LIMIT 1");
            $ownStmt->execute([':id' => $attendanceId]);
        } else {
            $ownStmt = $pdo->prepare("
                SELECT a.id
                FROM attendance a
                LEFT JOIN classes c ON c.id = a.class_id
                WHERE a.id = :id
                  AND (
                        c.teacher_id = :tid
                        OR a.teacher_id = :tid
                      )
                LIMIT 1
            ");
            $ownStmt->execute([':id' => $attendanceId, ':tid' => $teacherId]);
        }
        if (!$ownStmt->fetchColumn()) {
            throw new Exception("You are not allowed to edit this attendance record.");
        }

        $upd = $pdo->prepare("
            UPDATE attendance
            SET status = :status, attendance_date = :attendance_date, notes = :notes, marked_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            ':status' => $status,
            ':attendance_date' => $attendanceDate,
            ':notes' => $notes,
            ':id' => $attendanceId,
        ]);

        $message = "Attendance record updated.";
        $ok = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$filterClassId = (int)($_GET['class_id'] ?? 0);
$filterChildId = (int)($_GET['child_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));

if ($filterClassId > 0 && !$isAdmin && !in_array($filterClassId, $allowedClassIds, true)) {
    $filterClassId = 0;
}
if ($viewMode === 'parent' && $filterChildId > 0) {
    $allowedChildIds = array_map('intval', array_column($parentChildren, 'id'));
    if (!in_array($filterChildId, $allowedChildIds, true)) {
        $filterChildId = 0;
    }
}

$where = [];
$params = [];

if ($viewMode === 'admin') {
    $where[] = "1=1";
} elseif ($viewMode === 'teacher') {
    $where[] = "c.teacher_id = :teacher_id";
    $params[':teacher_id'] = $teacherId;
} else {
    $where[] = "s.parentId = :parent_id";
    $params[':parent_id'] = $parentId;
}

if ($viewMode === 'parent' && $filterChildId > 0) {
    $where[] = "a.student_id = :child_id";
    $params[':child_id'] = $filterChildId;
} elseif ($filterClassId > 0) {
    $where[] = "a.class_id = :class_id";
    $params[':class_id'] = $filterClassId;
}
if ($fromDate !== '') {
    $where[] = "a.attendance_date >= :from_date";
    $params[':from_date'] = $fromDate;
}
if ($toDate !== '') {
    $where[] = "a.attendance_date <= :to_date";
    $params[':to_date'] = $toDate;
}

$sql = "
    SELECT
        a.id,
        a.attendance_date,
        a.status,
        a.notes,
        a.marked_at,
        a.batch_id,
        a.class_id,
        s.id AS student_pk,
        s.student_name,
        s.student_id,
        c.class_name
    FROM attendance a
    INNER JOIN students s ON s.id = a.student_id
    LEFT JOIN classes c ON c.id = a.class_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY a.attendance_date DESC, a.id DESC
    LIMIT 1000
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dateHeadersMap = [];
$gridRows = [];
foreach ($rows as $r) {
    $dateKey = (string)($r['attendance_date'] ?? '');
    $markedAt = (string)($r['marked_at'] ?? '');
    $sessionKey = '';
    if ($dateKey !== '') {
        $batchId = (string)($r['batch_id'] ?? '');
        $sessionKey = $batchId !== '' ? ('batch:' . $batchId) : ($dateKey . '|' . $markedAt);
        $dateHeadersMap[$sessionKey] = [
            'date' => $dateKey,
            'time' => $markedAt,
            'key'  => $sessionKey,
        ];
    }

    $rowKey = (int)($r['class_id'] ?? 0) . ':' . (int)($r['student_pk'] ?? 0);
    if (!isset($gridRows[$rowKey])) {
        $gridRows[$rowKey] = [
            'student_name' => (string)($r['student_name'] ?? ''),
            'student_id'   => (string)($r['student_id'] ?? ''),
            'class_name'   => (string)($r['class_name'] ?? ''),
            'cells'        => [],
        ];
    }

    if ($sessionKey !== '') {
        $gridRows[$rowKey]['cells'][$sessionKey] = [
            'id'     => (int)$r['id'],
            'status' => (string)($r['status'] ?? ''),
            'notes'  => (string)($r['notes'] ?? ''),
            'date'   => $dateKey,
            'time'   => $markedAt,
        ];
    }
}

$dateHeaders = array_values($dateHeadersMap);
usort($dateHeaders, function ($a, $b) {
    $ta = strtotime((string)($a['time'] ?? '') ?: (string)($a['date'] ?? ''));
    $tb = strtotime((string)($b['time'] ?? '') ?: (string)($b['date'] ?? ''));
    if ($ta === $tb) return 0;
    return ($ta > $tb) ? -1 : 1;
});
if (count($dateHeaders) > 31) {
    $dateHeaders = array_slice($dateHeaders, 0, 31);
}
uasort($gridRows, function ($a, $b) {
    return strcasecmp((string)$a['student_name'], (string)$b['student_name']);
});

$parentChildCount = 0;
$parentSessionCount = 0;
$parentMarkedCount = 0;
$parentPresentCount = 0;
if ($viewMode === 'parent') {
    $parentChildCount = count($gridRows);
    $parentSessionCount = count($dateHeaders);
    foreach ($gridRows as $gr) {
        foreach (($gr['cells'] ?? []) as $cell) {
            $st = strtolower((string)($cell['status'] ?? ''));
            if (in_array($st, ['present', 'absent', 'late'], true)) {
                $parentMarkedCount++;
                if ($st === 'present') $parentPresentCount++;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Attendance Records</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    .att-summary-card {
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 12px 14px;
        background: #fff;
    }
    .att-summary-label { font-size: .75rem; color: #6c757d; text-transform: uppercase; letter-spacing: .03em; }
    .att-summary-value { font-size: 1.2rem; font-weight: 700; color: #1f2937; line-height: 1.2; }
    .att-cell-wrap { display: inline-flex; align-items: center; gap: 6px; }
    .att-edit-btn {
        border: 1px solid #d1d5db;
        background: #fff;
        color: #374151;
        border-radius: 4px;
        padding: 2px 6px;
        font-size: .72rem;
        line-height: 1.2;
        cursor: pointer;
    }
    .att-edit-btn:hover { background: #f3f4f6; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <h1 class="h3 mb-3 text-gray-800">
                    <?= $viewMode === 'parent' ? 'My Children Attendance Records' : 'Attendance Records' ?>
                </h1>

                <?php if ($message !== ''): ?>
                    <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        Swal.fire({
                            icon: <?= json_encode($ok ? 'success' : 'error') ?>,
                            title: <?= json_encode($message) ?>,
                            timer: 1800,
                            showConfirmButton: false
                        });
                    });
                    </script>
                <?php endif; ?>

                <?php if ($viewMode === 'parent'): ?>
                    <div class="row mb-3">
                        <div class="col-md-3 col-6 mb-2">
                            <div class="att-summary-card">
                                <div class="att-summary-label">Children</div>
                                <div class="att-summary-value"><?= (int)$parentChildCount ?></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="att-summary-card">
                                <div class="att-summary-label">Sessions</div>
                                <div class="att-summary-value"><?= (int)$parentSessionCount ?></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="att-summary-card">
                                <div class="att-summary-label">Marked</div>
                                <div class="att-summary-value"><?= (int)$parentMarkedCount ?></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="att-summary-card">
                                <div class="att-summary-label">Present</div>
                                <div class="att-summary-value"><?= (int)$parentPresentCount ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-light border mb-3">
                        <strong>Status guide:</strong>
                        <span class="badge badge-success ml-2">Present</span>
                        <span class="badge badge-danger ml-1">Absent</span>
                        <span class="badge badge-warning ml-1">Late</span>
                    </div>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                    <div class="alert alert-light border mb-3">
                        Click the <strong>Edit</strong> button in any date cell to update attendance.
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><?= $viewMode === 'parent' ? 'Filter My Children Records' : 'Filter Records' ?></h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="form-row">
                            <?php if ($viewMode === 'parent'): ?>
                                <div class="form-group col-md-4">
                                    <label>Child</label>
                                    <select name="child_id" class="form-control">
                                        <option value="0">All children</option>
                                        <?php foreach ($parentChildren as $ch): ?>
                                            <option value="<?= (int)$ch['id'] ?>" <?= $filterChildId === (int)$ch['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(($ch['student_name'] ?? '') . ' (' . ($ch['student_id'] ?? '-') . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <div class="form-group col-md-4">
                                    <label>Class</label>
                                    <select name="class_id" class="form-control">
                                        <option value="0">All classes</option>
                                        <?php foreach ($classes as $cl): ?>
                                            <option value="<?= (int)$cl['id'] ?>" <?= $filterClassId === (int)$cl['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cl['class_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <div class="form-group col-md-3">
                                <label>From Date</label>
                                <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label>To Date</label>
                                <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
                            </div>
                            <div class="form-group col-md-2 d-flex align-items-end">
                                <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-filter mr-1"></i>Apply</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <?= $viewMode === 'parent' ? 'Children Attendance (Date-wise)' : 'Attendance Records (Date-wise)' ?>
                        </h6>
                        <span class="badge badge-secondary"><?= count($rows) ?> record(s)</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($gridRows)): ?>
                            <div class="text-muted">No attendance records found for selected filters.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="attendanceRecordsTable" width="100%">
                                    <thead class="thead-light">
                                    <tr>
                                        <th style="width:60px;">#</th>
                                        <th><?= $viewMode === 'parent' ? 'Child Name' : 'Name' ?></th>
                                        <th style="width:140px;">Student ID</th>
                                        <th>Class</th>
                                        <?php foreach ($dateHeaders as $hdr): ?>
                                            <?php
                                                $d = (string)($hdr['date'] ?? '');
                                                $timeRaw = (string)($hdr['time'] ?? '');
                                                $timeLabel = $timeRaw ? date('h:i A', strtotime($timeRaw)) : '--:--';
                                            ?>
                                            <th style="min-width:140px;">
                                                <div><?= htmlspecialchars(date('d M Y', strtotime($d))) ?></div>
                                                <div style="font-size:.72rem;color:#6c757d;line-height:1.2;"><?= htmlspecialchars($timeLabel) ?></div>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php $rowNum = 1; foreach ($gridRows as $gr): ?>
                                        <tr>
                                            <td><?= $rowNum++ ?></td>
                                            <td><strong><?= htmlspecialchars($gr['student_name'] ?: '-') ?></strong></td>
                                            <td><?= htmlspecialchars($gr['student_id'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($gr['class_name'] ?: '-') ?></td>
                                            <?php foreach ($dateHeaders as $hdr): ?>
                                                <?php
                                                    $sessionKey = (string)($hdr['key'] ?? '');
                                                ?>
                                                <td class="text-center">
                                                    <?php if (!empty($gr['cells'][$sessionKey])): ?>
                                                        <?php
                                                        $cell = $gr['cells'][$sessionKey];
                                                        $status = strtolower((string)($cell['status'] ?? ''));
                                                        $badge = $status === 'present' ? 'success' : ($status === 'absent' ? 'danger' : ($status === 'late' ? 'warning' : 'secondary'));
                                                        ?>
                                                        <?php if ($canEdit): ?>
                                                            <span class="att-cell-wrap">
                                                                <span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($cell['status'] ?: 'Unknown') ?></span>
                                                                <button
                                                                    type="button"
                                                                    class="att-edit-btn btn-edit-att"
                                                                    data-id="<?= (int)$cell['id'] ?>"
                                                                    data-name="<?= htmlspecialchars($gr['student_name'] ?? '', ENT_QUOTES) ?>"
                                                                    data-date="<?= htmlspecialchars($cell['date'] ?? '', ENT_QUOTES) ?>"
                                                                    data-status="<?= htmlspecialchars($cell['status'] ?? '', ENT_QUOTES) ?>"
                                                                    data-notes="<?= htmlspecialchars($cell['notes'] ?? '', ENT_QUOTES) ?>"
                                                                    title="Edit attendance"
                                                                >
                                                                    Edit
                                                                </button>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge badge-<?= $badge ?>" title="<?= htmlspecialchars($cell['notes'] ?? '') ?>"><?= htmlspecialchars($cell['status'] ?: 'Unknown') ?></span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
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

<form id="editAttendanceForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="update_record">
    <input type="hidden" name="attendance_id" id="edit_attendance_id">
    <input type="hidden" name="attendance_date" id="edit_attendance_date">
    <input type="hidden" name="status" id="edit_status">
    <input type="hidden" name="notes" id="edit_notes">
</form>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
$(function () {
    $(document).on('click', '.btn-edit-att', function () {
        var id = $(this).data('id');
        var studentName = $(this).data('name') || '';
        var dateVal = $(this).data('date') || '';
        var statusVal = $(this).data('status') || 'Absent';
        var notesVal = $(this).data('notes') || '';

        Swal.fire({
            title: 'Edit Attendance',
            html:
                '<div class="text-left mb-2"><strong>' + $('<span>').text(studentName).html() + '</strong></div>' +
                '<label class="text-left d-block mb-1">Date</label>' +
                '<input id="swal_att_date" type="date" class="swal2-input" style="width:100%;margin:.2rem 0 .75rem;" value="' + $('<span>').text(dateVal).html() + '">' +
                '<label class="text-left d-block mb-1">Status</label>' +
                '<select id="swal_att_status" class="swal2-select" style="width:100%;margin:.2rem 0 .75rem;">' +
                    '<option value="Present">Present</option>' +
                    '<option value="Absent">Absent</option>' +
                    '<option value="Late">Late</option>' +
                '</select>' +
                '<label class="text-left d-block mb-1">Notes</label>' +
                '<textarea id="swal_att_notes" class="swal2-textarea" style="width:100%;margin:.2rem 0 0;" placeholder="Optional notes"></textarea>',
            didOpen: function () {
                $('#swal_att_status').val(statusVal);
                $('#swal_att_notes').val(notesVal);
            },
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            preConfirm: function () {
                var d = $('#swal_att_date').val();
                var s = $('#swal_att_status').val();
                var n = $('#swal_att_notes').val();
                if (!d) {
                    Swal.showValidationMessage('Date is required.');
                    return false;
                }
                if (!s) {
                    Swal.showValidationMessage('Status is required.');
                    return false;
                }
                return { date: d, status: s, notes: n };
            }
        }).then(function (result) {
            if (!result.isConfirmed || !result.value) return;
            $('#edit_attendance_id').val(id);
            $('#edit_attendance_date').val(result.value.date);
            $('#edit_status').val(result.value.status);
            $('#edit_notes').val(result.value.notes || '');
            $('#editAttendanceForm').trigger('submit');
        });
    });
});
</script>
</body>
</html>
