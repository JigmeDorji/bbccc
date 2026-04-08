<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/role_helpers.php";
require_once "include/pcm_helpers.php";
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

function bbcc_ensure_class_campus_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $stmt = $pdo->query("SHOW COLUMNS FROM classes LIKE 'campus_key'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE classes ADD COLUMN campus_key VARCHAR(20) NOT NULL DEFAULT 'c1' AFTER class_name");
    }
    $done = true;
}

bbcc_ensure_class_campus_column($pdo);
$campusChoices = pcm_campus_choice_labels();
$validCampusKeys = array_keys($campusChoices);

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
            $campusKey = trim((string)($_POST['campus_key'] ?? ''));
            if ($studentId === 0 || $classId === 0 || $campusKey === '') throw new Exception("Campus, student and class are required.");
            if (!in_array($campusKey, $validCampusKeys, true)) throw new Exception("Please select a valid campus.");

            $classRow = $pdo->prepare("SELECT id, campus_key FROM classes WHERE id = :id AND active = 1 LIMIT 1");
            $classRow->execute([':id' => $classId]);
            $classRow = $classRow->fetch(PDO::FETCH_ASSOC);
            if (!$classRow) throw new Exception("Selected class is not available.");
            if (strtolower((string)($classRow['campus_key'] ?? '')) !== strtolower($campusKey)) {
                throw new Exception("Selected class does not belong to selected campus.");
            }

            $stuRow = $pdo->prepare("
                SELECT s.id, e.campus_preference, ca.id AS assignment_id
                FROM students s
                LEFT JOIN class_assignments ca ON ca.student_id = s.id
                LEFT JOIN pcm_enrolments e ON e.student_id = s.id AND e.status = 'Approved'
                WHERE s.id = :sid AND s.approval_status = 'Approved'
                LIMIT 1
            ");
            $stuRow->execute([':sid' => $studentId]);
            $stuRow = $stuRow->fetch(PDO::FETCH_ASSOC);
            if (!$stuRow) throw new Exception("Selected student is not eligible.");
            if (!empty($stuRow['assignment_id'])) throw new Exception("Selected student is already assigned.");

            $studentCampus = pcm_normalize_campus_selection((string)($stuRow['campus_preference'] ?? ''));
            if (!in_array($campusKey, $studentCampus, true)) {
                throw new Exception("Selected student is not eligible for selected campus.");
            }

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

        // ── Assign selected (bulk from campus table) ─────────
        } elseif ($action === 'assign_selected') {
            $campusKey = trim((string)($_POST['campus_key'] ?? ''));
            $selectedStudentIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_students'] ?? [])))));
            $classMap = (array)($_POST['class_map'] ?? []);

            if ($campusKey === '' || !in_array($campusKey, $validCampusKeys, true)) {
                throw new Exception("Please select a valid campus.");
            }
            if (empty($selectedStudentIds)) {
                throw new Exception("Select at least one student to assign.");
            }

            $pdo->beginTransaction();
            $assignedCount = 0;
            $classCampusCache = [];

            foreach ($selectedStudentIds as $studentId) {
                $classId = (int)($classMap[$studentId] ?? 0);
                if ($classId <= 0) {
                    throw new Exception("Please choose class for each selected student.");
                }

                if (!isset($classCampusCache[$classId])) {
                    $classRow = $pdo->prepare("SELECT id, campus_key FROM classes WHERE id = :id AND active = 1 LIMIT 1");
                    $classRow->execute([':id' => $classId]);
                    $classCampusCache[$classId] = $classRow->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $classRow = $classCampusCache[$classId];
                if (!$classRow) throw new Exception("One of the selected classes is not available.");
                if (strtolower((string)($classRow['campus_key'] ?? '')) !== strtolower($campusKey)) {
                    throw new Exception("One of the selected classes does not belong to selected campus.");
                }

                $stuRow = $pdo->prepare("
                    SELECT s.id, e.campus_preference, ca.id AS assignment_id
                    FROM students s
                    LEFT JOIN class_assignments ca ON ca.student_id = s.id
                    LEFT JOIN pcm_enrolments e ON e.student_id = s.id AND e.status = 'Approved'
                    WHERE s.id = :sid AND s.approval_status = 'Approved'
                    LIMIT 1
                ");
                $stuRow->execute([':sid' => $studentId]);
                $stuRow = $stuRow->fetch(PDO::FETCH_ASSOC);
                if (!$stuRow) throw new Exception("One selected student is not eligible.");
                if (!empty($stuRow['assignment_id'])) throw new Exception("One selected student is already assigned. Refresh and try again.");

                $studentCampus = pcm_normalize_campus_selection((string)($stuRow['campus_preference'] ?? ''));
                if (!in_array($campusKey, $studentCampus, true)) {
                    throw new Exception("One selected student is not eligible for selected campus.");
                }

                $pdo->prepare("INSERT INTO class_assignments (class_id, student_id, assigned_by) VALUES (:cid,:sid,:by)")
                    ->execute([
                        ':cid' => $classId,
                        ':sid' => $studentId,
                        ':by'  => $_SESSION['userid'] ?? null
                    ]);
                $assignedCount++;
            }

            $pdo->commit();
            $msg = $assignedCount . " student(s) assigned successfully.";

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
$allClasses = $pdo->query("SELECT id, class_name, campus_key FROM classes WHERE active=1 ORDER BY class_name")->fetchAll();

$unassignedStudents = $pdo->query(
    "SELECT s.id, s.student_name, s.student_id, e.campus_preference
     FROM students s
     LEFT JOIN class_assignments ca ON ca.student_id = s.id
     LEFT JOIN pcm_enrolments e ON e.student_id = s.id AND e.status = 'Approved'
     WHERE s.approval_status='Approved' AND ca.id IS NULL ORDER BY s.student_name"
)->fetchAll();

$classesByCampus = [];
foreach ($validCampusKeys as $ck) {
    $classesByCampus[$ck] = [];
}
foreach ($allClasses as $c) {
    $ck = strtolower((string)($c['campus_key'] ?? 'c1'));
    if (!isset($classesByCampus[$ck])) $classesByCampus[$ck] = [];
    $classesByCampus[$ck][] = [
        'id' => (int)$c['id'],
        'name' => (string)$c['class_name'],
    ];
}

$studentsByCampus = [];
foreach ($validCampusKeys as $ck) {
    $studentsByCampus[$ck] = [];
}
foreach ($unassignedStudents as $s) {
    $campusKeys = pcm_normalize_campus_selection((string)($s['campus_preference'] ?? ''));
    foreach ($campusKeys as $ck) {
        if (!isset($studentsByCampus[$ck])) $studentsByCampus[$ck] = [];
        $studentsByCampus[$ck][] = [
            'id' => (int)$s['id'],
            'name' => (string)$s['student_name'],
            'sid' => (string)($s['student_id'] ?? ''),
        ];
    }
}

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
                                    <div class="form-row align-items-end mb-3">
                                        <div class="form-group col-md-5">
                                            <label><i class="fas fa-map-marker-alt mr-1" style="color:var(--brand);font-size:.7rem;"></i> Campus <span class="text-danger">*</span></label>
                                            <select id="assignCampus" class="form-control" required>
                                                <option value="">— Select campus —</option>
                                                <?php foreach ($campusChoices as $ck => $cl): ?>
                                                    <option value="<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($cl) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted" id="assignStudentHelp"><?= count($unassignedStudents) ?> student(s) without a class</small>
                                        </div>
                                    </div>

                                    <div id="assignCampusEmpty" class="alert alert-light" style="border-radius:10px;">
                                        Select a campus to load unassigned students.
                                    </div>
                                    <form method="POST" id="assignSelectedForm">
                                        <input type="hidden" name="action" value="assign_selected">
                                        <input type="hidden" name="campus_key" id="assignSelectedCampus" value="">
                                        <div id="assignCampusTableWrap" style="display:none;">
                                            <div class="d-flex align-items-center justify-content-between mb-2">
                                                <div>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary mr-1" id="assignSelectAllBtn" style="border-radius:8px;">Select All</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="assignDeselectAllBtn" style="border-radius:8px;">Deselect All</button>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-primary px-3" id="assignSelectedSubmit" style="border-radius:10px;">
                                                    <i class="fas fa-check mr-1"></i> Assign Selected
                                                </button>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-hover mb-0">
                                                    <thead class="thead-light">
                                                    <tr>
                                                        <th style="width:60px;">#</th>
                                                        <th style="width:90px;">Select</th>
                                                        <th>Student Name</th>
                                                        <th style="width:180px;">Student ID</th>
                                                        <th>Class to be Assigned</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody id="assignCampusTableBody"></tbody>
                                                </table>
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
var studentsByCampus = <?= json_encode($studentsByCampus) ?>;
var classesByCampus = <?= json_encode($classesByCampus) ?>;
var campusChoices = <?= json_encode($campusChoices) ?>;

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

    // Assign tab campus-driven table
    function escapeHtml(text) {
        return $('<span>').text(text == null ? '' : String(text)).html();
    }

    function refreshAssignOptions() {
        var campus = $('#assignCampus').val() || '';
        var $tableWrap = $('#assignCampusTableWrap');
        var $tableBody = $('#assignCampusTableBody');
        var $empty = $('#assignCampusEmpty');
        var $help = $('#assignStudentHelp');
        var $campusHidden = $('#assignSelectedCampus');
        var $submitBtn = $('#assignSelectedSubmit');

        $tableBody.empty();
        $campusHidden.val(campus);
        $submitBtn.prop('disabled', true);

        if (!campus) {
            $tableWrap.hide();
            $empty.show().text('Select a campus to load unassigned students.');
            return;
        }

        var students = studentsByCampus[campus] || [];
        var classes = classesByCampus[campus] || [];
        var campusName = campusChoices[campus] || campus;

        $help.text(students.length + ' unassigned student(s) for ' + campusName + '.');

        if (students.length === 0) {
            $tableWrap.hide();
            $empty.show().html('<i class="fas fa-inbox mr-1"></i> No unassigned students for ' + escapeHtml(campusName) + '.');
            return;
        }

        var classOptionsHtml = '<option value="">— Select class —</option>';
        $.each(classes, function(_, c){
            classOptionsHtml += '<option value="' + parseInt(c.id, 10) + '">' + escapeHtml(c.name || '') + '</option>';
        });

        $.each(students, function(i, s){
            var sid = s.sid ? '<code>' + escapeHtml(s.sid) + '</code>' : '—';
            var studentId = parseInt(s.id, 10);
            var row = ''
                + '<tr>'
                +   '<td>' + (i + 1) + '</td>'
                +   '<td class="text-center"><input type="checkbox" class="assign-select-one" name="selected_students[]" value="' + studentId + '" checked></td>'
                +   '<td>' + escapeHtml(s.name || '') + '</td>'
                +   '<td>' + sid + '</td>'
                +   '<td>'
                +       '<select name="class_map[' + studentId + ']" class="form-control form-control-sm assign-class-select" style="min-width:230px;" data-student-id="' + studentId + '">'
                +         classOptionsHtml
                +       '</select>'
                +       '<small class="text-danger d-none assign-class-error" data-student-id="' + studentId + '">Select class</small>'
                +   '</td>'
                + '</tr>';
            $tableBody.append(row);
        });

        $empty.hide();
        $tableWrap.show();
        $submitBtn.prop('disabled', students.length === 0);
    }

    $('#assignCampus').on('change', refreshAssignOptions);
    refreshAssignOptions();

    $('#assignSelectAllBtn').on('click', function () {
        $('.assign-select-one').prop('checked', true);
    });

    $('#assignDeselectAllBtn').on('click', function () {
        $('.assign-select-one').prop('checked', false);
    });

    $('#assignSelectedForm').on('submit', function (e) {
        var selectedCount = $('.assign-select-one:checked').length;
        if (!selectedCount) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Select at least one student.', timer: 1800, showConfirmButton: false });
            return;
        }

        var missingClass = false;
        $('.assign-class-error').addClass('d-none');

        $('.assign-select-one:checked').each(function () {
            var studentId = parseInt($(this).val(), 10) || 0;
            var classVal = $('select.assign-class-select[data-student-id="' + studentId + '"]').val();
            if (!classVal) {
                missingClass = true;
                $('.assign-class-error[data-student-id="' + studentId + '"]').removeClass('d-none');
            }
        });

        if (missingClass) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Choose class for all selected students.', timer: 2000, showConfirmButton: false });
            return;
        }
    });

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
