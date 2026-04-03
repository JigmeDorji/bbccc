<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/role_helpers.php";
require_login();
allowRoles(['Administrator', 'Admin', 'Company Admin', 'System_owner', 'Staff']);

// ─── DB ──────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    bbcc_fail_db($e);
}

// ─── POST handler with PRG pattern ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $status = 'success';
    $msg    = '';

    try {
        // ── Assign student (new or update) ──────────────────
        if ($action === 'assign') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $classId   = (int)($_POST['class_id']   ?? 0);
            if ($studentId === 0 || $classId === 0) throw new Exception("Student and class are required.");

            $exist = $pdo->prepare("SELECT id FROM class_assignments WHERE student_id = :sid");
            $exist->execute([':sid' => $studentId]);
            if ($exist->fetch()) {
                $pdo->prepare("UPDATE class_assignments SET class_id=:cid, assigned_by=:by, assigned_at=NOW() WHERE student_id=:sid")
                    ->execute([':cid' => $classId, ':by' => $_SESSION['userid'] ?? null, ':sid' => $studentId]);
                $msg = "Student re-assigned to class successfully.";
            } else {
                $pdo->prepare("INSERT INTO class_assignments (class_id, student_id, assigned_by) VALUES (:cid,:sid,:by)")
                    ->execute([':cid' => $classId, ':sid' => $studentId, ':by' => $_SESSION['userid'] ?? null]);
                $msg = "Student assigned to class successfully.";
            }

        // ── Bulk transfer ───────────────────────────────────
        } elseif ($action === 'transfer_bulk') {
            $fromClassId = (int)($_POST['from_class_id'] ?? 0);
            $toClassId   = (int)($_POST['to_class_id']   ?? 0);
            $studentIds  = array_filter(array_map('intval', (array)($_POST['student_ids'] ?? [])));
            if ($fromClassId === 0 || $toClassId === 0) throw new Exception("Source and target classes are required.");
            if ($fromClassId === $toClassId) throw new Exception("Source and target classes must be different.");
            if (empty($studentIds)) throw new Exception("Select at least one student to transfer.");

            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $params = array_merge([$toClassId, $_SESSION['userid'] ?? null, $fromClassId], array_values($studentIds));
            $pdo->prepare("UPDATE class_assignments SET class_id=?, assigned_by=?, assigned_at=NOW() WHERE class_id=? AND student_id IN ($placeholders)")
                ->execute($params);
            $msg = count($studentIds) . " student(s) transferred successfully.";

        // ── Remove assignment ───────────────────────────────
        } elseif ($action === 'remove_assignment') {
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            if ($assignmentId === 0) throw new Exception("Invalid assignment.");
            $pdo->prepare("DELETE FROM class_assignments WHERE id=:id")->execute([':id' => $assignmentId]);
            $msg = "Assignment removed.";

        } else {
            throw new Exception("Unknown action.");
        }
    } catch (Exception $e) {
        $status = 'error';
        $msg    = $e->getMessage();
    }

    $_SESSION['assign_flash'] = ['status' => $status, 'msg' => $msg];
    header("Location: admin-assign-class");
    exit;
}

// ─── Flash ──────────────────────────────────────────────
$flash = $_SESSION['assign_flash'] ?? null;
unset($_SESSION['assign_flash']);

// ─── Data ────────────────────────────────────────────────
$allClasses = $pdo->query("SELECT id, class_name FROM classes WHERE active=1 ORDER BY class_name")->fetchAll();

$unassignedStudents = $pdo->query(
    "SELECT s.id, s.student_name, s.student_id FROM students s
     LEFT JOIN class_assignments ca ON ca.student_id = s.id
     WHERE s.approval_status='Approved' AND ca.id IS NULL ORDER BY s.student_name"
)->fetchAll();

$assignedStudents = $pdo->query(
    "SELECT s.id, s.student_name, s.student_id, c.class_name, c.id AS class_id
     FROM students s
     INNER JOIN class_assignments ca ON ca.student_id = s.id
     INNER JOIN classes c ON c.id = ca.class_id
     WHERE s.approval_status='Approved' ORDER BY c.class_name, s.student_name"
)->fetchAll();

$assignments = $pdo->query(
    "SELECT ca.id, s.student_name, s.student_id, c.class_name, ca.assigned_at
     FROM class_assignments ca
     INNER JOIN students s ON s.id = ca.student_id
     INNER JOIN classes c  ON c.id = ca.class_id
     ORDER BY c.class_name, s.student_name"
)->fetchAll();

// Build class → students map for bulk JS
$classMembersMap = [];
foreach ($assignedStudents as $row) {
    $classMembersMap[$row['class_id']][] = [
        'id' => $row['id'], 'name' => $row['student_name'], 'sid' => $row['student_id'] ?? ''
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Class Assignments</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand:#881b12; }
        .card { border:none !important; border-radius:14px !important; }
        .nav-tabs .nav-link.active { border-bottom:3px solid var(--brand); color:var(--brand); font-weight:600; }
        .nav-tabs .nav-link { color:#555; }
        #bulkStudentList .form-check { padding:6px 10px; border-radius:8px; }
        #bulkStudentList .form-check:hover { background:#fef3f2; }
        .badge-class { background:#fef3f2; color:var(--brand); border:1px solid #f7c6c3; border-radius:10px; padding:3px 10px; font-size:.78rem; font-weight:600; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>
            <div class="container-fluid">

                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">Class Assignments</h1>
                        <p class="text-muted mb-0" style="font-size:.88rem;">Assign, transfer, and manage student class placements.</p>
                    </div>
                    <a href="admin-class-setup" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
                        <i class="fas fa-cog mr-1"></i> Classes & Teachers
                    </a>
                </div>

                <?php if ($flash): ?>
                <script>
                document.addEventListener('DOMContentLoaded', () => {
                    Swal.fire({
                        icon: <?= json_encode($flash['status']) ?>,
                        title: <?= json_encode($flash['msg']) ?>,
                        timer: 2800, showConfirmButton: false
                    });
                });
                </script>
                <?php endif; ?>

                <!-- Tabs -->
                <ul class="nav nav-tabs mb-4" id="assignTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-toggle="tab" href="#pane-assign" role="tab">
                            <i class="fas fa-user-plus mr-1"></i> Assign
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#pane-transfer-bulk" role="tab">
                            <i class="fas fa-layer-group mr-1"></i> Bulk Transfer
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#pane-list" role="tab">
                            <i class="fas fa-list mr-1"></i> All Assignments
                            <span class="badge badge-secondary ml-1"><?= count($assignments) ?></span>
                        </a>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- ═ Assign ═ -->
                    <div class="tab-pane fade show active" id="pane-assign" role="tabpanel">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-plus mr-1"></i> Assign Student to Class</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($unassignedStudents)): ?>
                                    <div class="alert alert-info mb-0" style="border-radius:10px;">
                                        <i class="fas fa-check-circle mr-1"></i> All students already have a class assignment. Use the <strong>Bulk Transfer</strong> tab to move them.
                                    </div>
                                <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="assign">
                                    <div class="form-row align-items-end">
                                        <div class="form-group col-md-5">
                                            <label><i class="fas fa-user-graduate mr-1" style="color:var(--brand);font-size:.7rem;"></i> Unassigned Student <span class="text-danger">*</span></label>
                                            <select name="student_id" class="form-control" required>
                                                <option value="">— Select student —</option>
                                                <?php foreach ($unassignedStudents as $s): ?>
                                                    <option value="<?= (int)$s['id'] ?>">
                                                        <?= htmlspecialchars($s['student_name']) ?>
                                                        <?= $s['student_id'] ? '(' . htmlspecialchars($s['student_id']) . ')' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted"><?= count($unassignedStudents) ?> student(s) without a class</small>
                                        </div>
                                        <div class="form-group col-md-5">
                                            <label><i class="fas fa-chalkboard mr-1" style="color:var(--brand);font-size:.7rem;"></i> Class <span class="text-danger">*</span></label>
                                            <select name="class_id" class="form-control" required>
                                                <option value="">— Select class —</option>
                                                <?php foreach ($allClasses as $c): ?>
                                                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-2">
                                            <button type="submit" class="btn btn-primary btn-block" style="border-radius:10px;">
                                                <i class="fas fa-check mr-1"></i> Assign
                                            </button>
                                        </div>
                                    </div>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ═ Bulk Transfer ═ -->
                    <div class="tab-pane fade" id="pane-transfer-bulk" role="tabpanel">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-layer-group mr-1"></i> Bulk Transfer</h6>
                            </div>
                            <div class="card-body">
                                <p class="text-muted mb-3" style="font-size:.87rem;">Select a source and target class, load the students, choose who to move, then confirm.</p>
                                <form method="POST" id="bulkTransferForm">
                                    <input type="hidden" name="action" value="transfer_bulk">
                                    <div class="form-row align-items-end mb-3">
                                        <div class="form-group col-md-5">
                                            <label><i class="fas fa-chalkboard mr-1" style="color:var(--brand);font-size:.7rem;"></i> From Class <span class="text-danger">*</span></label>
                                            <select name="from_class_id" class="form-control" id="bulkFromClass" required>
                                                <option value="">— Source class —</option>
                                                <?php foreach ($allClasses as $c): ?>
                                                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-5">
                                            <label><i class="fas fa-arrow-right mr-1" style="color:var(--brand);font-size:.7rem;"></i> To Class <span class="text-danger">*</span></label>
                                            <select name="to_class_id" class="form-control" id="bulkToClass" required>
                                                <option value="">— Target class —</option>
                                                <?php foreach ($allClasses as $c): ?>
                                                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-2">
                                            <button type="button" id="bulkLoadBtn" class="btn btn-outline-primary btn-block" style="border-radius:10px;">
                                                <i class="fas fa-search mr-1"></i> Load Students
                                            </button>
                                        </div>
                                    </div>

                                    <div id="bulkStudentPanel" style="display:none;">
                                        <hr>
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="font-weight-bold"><i class="fas fa-users mr-1" style="color:var(--brand);"></i> Students in source class:</span>
                                            <div>
                                                <button type="button" class="btn btn-sm btn-outline-secondary mr-1" id="selectAllBtn" style="border-radius:8px;">Select All</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllBtn" style="border-radius:8px;">Deselect All</button>
                                            </div>
                                        </div>
                                        <div id="bulkStudentList" style="max-height:240px;overflow-y:auto;border:1px solid #e3e6f0;border-radius:10px;padding:10px;" class="mb-3"></div>
                                        <div id="bulkNoStudents" class="text-muted text-center py-3" style="display:none;"><i class="fas fa-inbox mr-1"></i> No students in this class.</div>
                                        <button type="submit" id="bulkSubmitBtn" class="btn btn-warning text-white px-4" style="border-radius:10px;">
                                            <i class="fas fa-layer-group mr-1"></i> Transfer Selected Students
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- ═ All Assignments ═ -->
                    <div class="tab-pane fade" id="pane-list" role="tabpanel">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list mr-1"></i> All Assignments</h6>
                                <span class="badge badge-primary" style="border-radius:10px;padding:5px 12px;"><?= count($assignments) ?> total</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="assignmentsTable" width="100%">
                                        <thead style="background:#f8f9fc;">
                                        <tr>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Student</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Student ID</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Class</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Assigned</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;width:60px;text-align:center;">Remove</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($assignments as $a): ?>
                                            <tr>
                                                <td class="font-weight-bold"><?= htmlspecialchars($a['student_name']) ?></td>
                                                <td><code><?= htmlspecialchars($a['student_id'] ?? '—') ?></code></td>
                                                <td><span class="badge-class"><?= htmlspecialchars($a['class_name']) ?></span></td>
                                                <td style="font-size:.84rem;color:#888;"><?= htmlspecialchars(date('d M Y, g:ia', strtotime($a['assigned_at']))) ?></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-assignment"
                                                        data-id="<?= (int)$a['id'] ?>"
                                                        data-name="<?= htmlspecialchars($a['student_name'], ENT_QUOTES) ?>"
                                                        style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;" title="Remove">
                                                        <i class="fas fa-times" style="font-size:.75rem;"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($assignments)): ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-inbox mr-1"></i> No assignments yet.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /tab-content -->
            </div>
        </div>
        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<!-- Hidden remove form -->
<form id="removeAssignmentForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="remove_assignment">
    <input type="hidden" name="assignment_id" id="remove_assignment_id">
</form>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/datatables/jquery.dataTables.min.js"></script>
<script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
<script>
var classMembersMap = <?= json_encode($classMembersMap) ?>;

$(function () {
    // Restore active tab from URL hash
    var hash = window.location.hash || '#pane-assign';
    $('#assignTabs a[href="' + hash + '"]').tab('show');
    $('#assignTabs a').on('shown.bs.tab', function (e) {
        history.replaceState(null, null, e.target.hash);
    });

    // DataTable
    if ($('#assignmentsTable tbody tr td').length > 1) {
        $('#assignmentsTable').DataTable({
            pageLength: 25,
            order: [[2, 'asc'], [0, 'asc']],
            columnDefs: [{ orderable: false, targets: 4 }],
            language: { searchPlaceholder: 'Search...' }
        });
    }

    // Bulk — Load students
    $('#bulkLoadBtn').on('click', function () {
        var fromId = parseInt($('#bulkFromClass').val()) || 0;
        var toId   = parseInt($('#bulkToClass').val())   || 0;
        if (!fromId) {
            Swal.fire({ icon: 'warning', title: 'Select a source class first.', timer: 1800, showConfirmButton: false }); return;
        }
        if (toId && fromId === toId) {
            Swal.fire({ icon: 'warning', title: 'Source and target classes must be different.', timer: 1800, showConfirmButton: false }); return;
        }
        var students = classMembersMap[fromId] || [];
        $('#bulkStudentPanel').show();
        $('#bulkStudentList').empty();
        if (students.length === 0) {
            $('#bulkStudentList').hide(); $('#bulkNoStudents').show(); $('#bulkSubmitBtn').prop('disabled', true);
        } else {
            $('#bulkStudentList').show(); $('#bulkNoStudents').hide(); $('#bulkSubmitBtn').prop('disabled', false);
            $.each(students, function (i, st) {
                var safeId = 'chk_' + st.id;
                var safeName = $('<span>').text(st.name).html();
                var safeSid  = st.sid ? $('<span>').text(st.sid).html() : '';
                $('#bulkStudentList').append(
                    '<div class="form-check">' +
                    '<input class="form-check-input bulk-chk" type="checkbox" name="student_ids[]" value="' + st.id + '" id="' + safeId + '" checked>' +
                    '<label class="form-check-label" for="' + safeId + '" style="cursor:pointer;">' +
                    '<i class="fas fa-user-graduate mr-1" style="color:var(--brand);font-size:.75rem;"></i>' +
                    '<strong>' + safeName + '</strong>' +
                    (safeSid ? ' <small class="text-muted">(' + safeSid + ')</small>' : '') +
                    '</label></div>'
                );
            });
        }
    });

    $('#selectAllBtn').on('click',   function () { $('.bulk-chk').prop('checked', true); });
    $('#deselectAllBtn').on('click', function () { $('.bulk-chk').prop('checked', false); });

    // Bulk submit confirm
    $('#bulkTransferForm').on('submit', function (e) {
        e.preventDefault();
        var checked = $('.bulk-chk:checked').length;
        var toName  = $('<span>').text($('#bulkToClass option:selected').text()).html();
        if (!checked) {
            Swal.fire({ icon: 'warning', title: 'Select at least one student.', timer: 1800, showConfirmButton: false }); return;
        }
        if (!parseInt($('#bulkToClass').val())) {
            Swal.fire({ icon: 'warning', title: 'Select a target class.', timer: 1800, showConfirmButton: false }); return;
        }
        Swal.fire({
            title: 'Confirm Bulk Transfer',
            html: 'Transfer <strong>' + checked + '</strong> student(s) to <strong>' + toName + '</strong>?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#881b12',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-layer-group mr-1"></i> Yes, transfer',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then(function (r) { if (r.isConfirmed) document.getElementById('bulkTransferForm').submit(); });
    });

    // Remove assignment
    $(document).on('click', '.btn-remove-assignment', function () {
        var id = $(this).data('id'), name = $('<span>').text($(this).data('name')).html();
        Swal.fire({
            title: 'Remove Assignment?',
            html: 'Remove <strong>' + name + '</strong> from their current class?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#881b12',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, remove',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then(function (r) {
            if (r.isConfirmed) {
                $('#remove_assignment_id').val(id);
                $('#removeAssignmentForm').submit();
            }
        });
    });
});
</script>
</body>
</html>
