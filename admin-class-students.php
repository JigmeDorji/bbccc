<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/pcm_helpers.php";
require_once "include/csrf.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

[$campusOneName, $campusTwoName] = pcm_campus_names();
$campusChoices = [
    'c1' => $campusOneName,
    'c2' => $campusTwoName,
];

$flash = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_student_class') {
    verify_csrf();
    $studentDbId = (int)($_POST['student_db_id'] ?? 0);
    $newClassId = (int)($_POST['new_class_id'] ?? 0);
    if ($studentDbId <= 0 || $newClassId <= 0) {
        $flash = 'Invalid student or class selected.';
    } else {
        try {
            $checkClass = $pdo->prepare("SELECT id FROM classes WHERE id=:id AND active=1 LIMIT 1");
            $checkClass->execute([':id' => $newClassId]);
            if (!$checkClass->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Selected class is not active.');
            }

            $exists = $pdo->prepare("SELECT id FROM class_assignments WHERE student_id=:sid LIMIT 1");
            $exists->execute([':sid' => $studentDbId]);
            $row = $exists->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $upd = $pdo->prepare("UPDATE class_assignments SET class_id=:cid, assigned_by=:by, assigned_at=NOW() WHERE student_id=:sid");
                $upd->execute([
                    ':cid' => $newClassId,
                    ':by' => ($_SESSION['userid'] ?? null),
                    ':sid' => $studentDbId,
                ]);
            } else {
                $ins = $pdo->prepare("INSERT INTO class_assignments (class_id, student_id, assigned_by) VALUES (:cid,:sid,:by)");
                $ins->execute([
                    ':cid' => $newClassId,
                    ':sid' => $studentDbId,
                    ':by' => ($_SESSION['userid'] ?? null),
                ]);
            }
            $flash = 'Student class updated successfully.';
            $ok = true;
        } catch (Throwable $e) {
            $flash = 'Error: ' . $e->getMessage();
        }
    }
}

$classOptions = $pdo->query("SELECT id, class_name FROM classes WHERE active=1 ORDER BY class_name ASC")->fetchAll();
$unallocatedStudents = (int)$pdo->query("
    SELECT COUNT(*)
    FROM students s
    LEFT JOIN class_assignments ca ON ca.student_id = s.id
    WHERE ca.id IS NULL
")->fetchColumn();
$unallocatedList = $pdo->query("
    SELECT s.id AS student_db_id, s.student_id, s.student_name
    FROM students s
    LEFT JOIN class_assignments ca ON ca.student_id = s.id
    WHERE ca.id IS NULL
    ORDER BY s.student_name ASC
")->fetchAll();

$rows = $pdo->query("
    SELECT
        c.id AS class_id,
        c.class_name,
        c.campus_key,
        t.full_name AS teacher_name,
        s.id AS student_db_id,
        s.student_id,
        s.student_name
    FROM classes c
    LEFT JOIN teachers t ON t.id = c.teacher_id
    LEFT JOIN class_assignments ca ON ca.class_id = c.id
    LEFT JOIN students s ON s.id = ca.student_id
    WHERE c.active = 1
    ORDER BY c.class_name ASC, s.student_name ASC
")->fetchAll();

$classData = [];
$studentRows = [];
foreach ($rows as $r) {
    $cid = (int)$r['class_id'];
    if (!isset($classData[$cid])) {
        $classData[$cid] = [
            'class_name' => (string)($r['class_name'] ?? ''),
            'campus_key' => (string)($r['campus_key'] ?? ''),
            'teacher_name' => (string)($r['teacher_name'] ?? ''),
            'students' => [],
        ];
    }
    if (!empty($r['student_db_id'])) {
        $student = [
            'student_db_id' => (int)($r['student_db_id'] ?? 0),
            'class_id' => (int)($r['class_id'] ?? 0),
            'class_name' => (string)($r['class_name'] ?? ''),
            'campus_key' => (string)($r['campus_key'] ?? ''),
            'teacher_name' => (string)($r['teacher_name'] ?? ''),
            'student_id' => (string)($r['student_id'] ?? ''),
            'student_name' => (string)($r['student_name'] ?? ''),
        ];
        $classData[$cid]['students'][] = $student;
        $studentRows[] = $student;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Class Student List</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        :root { --brand:#881b12; --brand-light:#a82218; --ink:#2f3542; }
        .page-title { font-weight:800; letter-spacing:.2px; color:var(--ink); }
        .page-sub { color:#6b7280; font-size:.92rem; }
        .summary-wrap .card { border:0; border-radius:14px; }
        .summary-icon {
            width:44px; height:44px; border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            background:rgba(136,27,18,.1); color:var(--brand);
            font-size:1.1rem;
        }
        .summary-val { font-size:1.45rem; font-weight:800; line-height:1.1; color:#1f2937; }
        .summary-lab { font-size:.74rem; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; font-weight:700; }
        .toolbar-box {
            border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:12px;
        }
        .table-card { border:0; border-radius:14px; overflow:hidden; }
        .table thead th {
            background:#f8f9fc; font-size:.74rem; text-transform:uppercase; letter-spacing:.5px; color:#6b7280;
            border-bottom:2px solid #e5e7eb; white-space:nowrap;
        }
        .chip {
            display:inline-flex; align-items:center; gap:6px;
            background:#fff5f3; color:#8a1d14; border:1px solid #f2d2cd;
            border-radius:999px; padding:3px 9px; font-size:.75rem; font-weight:700;
        }
        .teacher-badge {
            display:inline-block; background:#eef2ff; color:#4338ca; border:1px solid #dbe3ff;
            border-radius:8px; padding:4px 8px; font-size:.78rem; font-weight:600;
        }
        .student-pill {
            display:inline-block; margin:2px 4px 2px 0; padding:4px 8px;
            border-radius:8px; background:#f9fafb; border:1px solid #e5e7eb; font-size:.78rem;
        }
        .class-edit-select { min-width: 180px; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>
            <div class="container-fluid py-3">
                <?php if ($flash): ?>
                <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        Swal.fire({
                            icon: <?= $ok ? "'success'" : "'error'" ?>,
                            text: <?= json_encode($flash) ?>,
                            timer: 1800,
                            showConfirmButton: false
                        });
                    });
                </script>
                <?php endif; ?>
                <h1 class="h3 page-title mb-1">Class Student List</h1>
                <p class="page-sub mb-3">Students grouped by class with assigned class teacher.</p>

                <?php
                    $totalClasses = count($classData);
                    $totalStudents = 0;
                    $assignedTeachers = 0;
                    $classPanels = [];
                    foreach ($classData as $c) {
                        $classCount = count($c['students']);
                        $totalStudents += $classCount;
                        if (trim((string)$c['teacher_name']) !== '') $assignedTeachers++;
                        $classPanels[] = [
                            'class_name' => (string)($c['class_name'] ?? ''),
                            'count' => $classCount,
                        ];
                    }
                ?>

                <div class="row summary-wrap mb-3">
                    <div class="col-md-4 mb-2">
                        <div class="card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="summary-icon mr-3"><i class="fas fa-chalkboard"></i></div>
                                <div><div class="summary-val"><?= (int)$totalClasses ?></div><div class="summary-lab">Active Classes</div></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <div class="card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="summary-icon mr-3"><i class="fas fa-user-graduate"></i></div>
                                <div><div class="summary-val"><?= (int)$totalStudents ?></div><div class="summary-lab">Assigned Students</div></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <div class="card shadow-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="summary-icon mr-3"><i class="fas fa-chalkboard-teacher"></i></div>
                                <div><div class="summary-val"><?= (int)$assignedTeachers ?></div><div class="summary-lab">Classes With Teacher</div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php
                    $campusGroups = ['c1' => [], 'c2' => [], 'other' => []];
                    $campusTotals = ['c1' => 0, 'c2' => 0, 'other' => 0];
                    foreach ($classData as $c) {
                        $k = strtolower(trim((string)($c['campus_key'] ?? '')));
                        $entry = [
                            'class_name' => (string)($c['class_name'] ?? ''),
                            'count' => count($c['students']),
                            'teacher_name' => (string)($c['teacher_name'] ?? ''),
                        ];
                        if ($k === 'c1' || $k === 'c2') {
                            $campusGroups[$k][] = $entry;
                            $campusTotals[$k] += (int)$entry['count'];
                        } else {
                            $campusGroups['other'][] = $entry;
                            $campusTotals['other'] += (int)$entry['count'];
                        }
                    }
                ?>
                <div class="row mb-3">
                    <div class="col-lg-6 mb-2">
                        <div class="card shadow-sm h-100">
                            <div class="card-header py-2"><strong><?= htmlspecialchars($campusChoices['c1'] ?? 'Campus 1') ?></strong></div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="thead-light">
                                            <tr><th style="width:34px">#</th><th>Class</th><th>Teacher Assigned</th><th style="width:90px">Students</th></tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($campusGroups['c1'])): ?>
                                            <tr><td colspan="4" class="text-center text-muted small">No classes</td></tr>
                                        <?php else: foreach ($campusGroups['c1'] as $i => $cp): ?>
                                            <tr>
                                                <td><?= $i + 1 ?></td>
                                                <td><?= htmlspecialchars($cp['class_name']) ?></td>
                                                <td><?= htmlspecialchars(trim((string)$cp['teacher_name']) !== '' ? (string)$cp['teacher_name'] : 'Not Assigned') ?></td>
                                                <td><strong><?= (int)$cp['count'] ?></strong></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                        <tr class="table-light">
                                            <td colspan="3" class="text-right"><strong>Total</strong></td>
                                            <td><strong><?= (int)$campusTotals['c1'] ?></strong></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-2">
                        <div class="card shadow-sm h-100">
                            <div class="card-header py-2"><strong><?= htmlspecialchars($campusChoices['c2'] ?? 'Campus 2') ?></strong></div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="thead-light">
                                            <tr><th style="width:34px">#</th><th>Class</th><th>Teacher Assigned</th><th style="width:90px">Students</th></tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($campusGroups['c2']) && empty($campusGroups['other'])): ?>
                                            <tr><td colspan="4" class="text-center text-muted small">No classes</td></tr>
                                        <?php else: ?>
                                            <?php $j = 1; foreach (array_merge($campusGroups['c2'], $campusGroups['other']) as $cp): ?>
                                            <tr>
                                                <td><?= $j++ ?></td>
                                                <td><?= htmlspecialchars($cp['class_name']) ?></td>
                                                <td><?= htmlspecialchars(trim((string)$cp['teacher_name']) !== '' ? (string)$cp['teacher_name'] : 'Not Assigned') ?></td>
                                                <td><strong><?= (int)$cp['count'] ?></strong></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <tr class="table-light">
                                            <td colspan="3" class="text-right"><strong>Total</strong></td>
                                            <td><strong><?= (int)($campusTotals['c2'] + $campusTotals['other']) ?></strong></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="toolbar-box mb-3">
                    <div class="form-row">
                        <div class="col-md-6">
                            <label class="small text-muted mb-1">Search class, teacher, or student</label>
                            <input type="text" id="classStudentSearch" class="form-control" placeholder="Type to filter...">
                        </div>
                        <div class="col-md-4">
                            <label class="small text-muted mb-1">Filter by class</label>
                            <select id="classFilterSelect" class="form-control">
                                <option value="">All Classes</option>
                                <?php foreach ($classOptions as $co): ?>
                                <option value="<?= htmlspecialchars(strtolower((string)$co['class_name'])) ?>"><?= htmlspecialchars((string)$co['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-3">
                    <div class="card-header py-2 d-flex align-items-center justify-content-between">
                        <strong>Unallocated Students</strong>
                        <span class="badge badge-secondary"><?= (int)$unallocatedStudents ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:40px">#</th>
                                        <th>Student ID</th>
                                        <th>Student Name</th>
                                        <th style="width:280px">Assign Class</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($unallocatedList)): ?>
                                    <tr><td colspan="4" class="text-center text-muted">No unallocated students.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($unallocatedList as $idx => $u): ?>
                                    <tr>
                                        <td><?= (int)$idx + 1 ?></td>
                                        <td><code><?= htmlspecialchars((string)$u['student_id']) ?></code></td>
                                        <td class="font-weight-bold"><?= htmlspecialchars((string)$u['student_name']) ?></td>
                                        <td>
                                            <form method="POST" class="form-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_student_class">
                                                <input type="hidden" name="student_db_id" value="<?= (int)$u['student_db_id'] ?>">
                                                <select name="new_class_id" class="form-control form-control-sm mr-2 class-edit-select" required>
                                                    <option value="">Select class</option>
                                                    <?php foreach ($classOptions as $co): ?>
                                                        <option value="<?= (int)$co['id'] ?>"><?= htmlspecialchars((string)$co['class_name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-primary">Assign</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow table-card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0" id="classStudentTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:40px">#</th>
                                        <th>Student ID</th>
                                        <th>Student Name</th>
                                        <th>Class</th>
                                        <th>Campus</th>
                                        <th>Teacher</th>
                                        <th style="width:280px">Edit Class</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($studentRows)): ?>
                                    <tr><td colspan="7" class="text-center text-muted">No assigned students found.</td></tr>
                                <?php else: ?>
                                    <?php $i = 1; foreach ($studentRows as $st): ?>
                                    <tr
                                        data-search="<?= htmlspecialchars(strtolower($st['class_name'] . ' ' . $st['teacher_name'] . ' ' . $st['student_name'] . ' ' . $st['student_id'])) ?>"
                                        data-class-name="<?= htmlspecialchars(strtolower($st['class_name'])) ?>">
                                        <td><?= $i++ ?></td>
                                        <td><code><?= htmlspecialchars($st['student_id']) ?></code></td>
                                        <td class="font-weight-bold text-dark"><?= htmlspecialchars($st['student_name']) ?></td>
                                        <td><?= htmlspecialchars($st['class_name']) ?></td>
                                        <td>
                                            <span class="chip"><?= htmlspecialchars($st['campus_key'] !== '' ? strtoupper($st['campus_key']) : '-') ?></span>
                                        </td>
                                        <td>
                                            <span class="teacher-badge"><?= htmlspecialchars($st['teacher_name'] !== '' ? $st['teacher_name'] : 'Not Assigned') ?></span>
                                        </td>
                                        <td>
                                            <form method="POST" class="form-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_student_class">
                                                <input type="hidden" name="student_db_id" value="<?= (int)$st['student_db_id'] ?>">
                                                <select name="new_class_id" class="form-control form-control-sm mr-2 class-edit-select" required>
                                                    <?php foreach ($classOptions as $co): ?>
                                                        <option value="<?= (int)$co['id'] ?>" <?= ((int)$st['class_id'] === (int)$co['id']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars((string)$co['class_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('classStudentSearch');
    const classFilter = document.getElementById('classFilterSelect');
    const rows = document.querySelectorAll('#classStudentTable tbody tr');
    function applyFilters() {
        const q = (input.value || '').trim().toLowerCase();
        const cls = (classFilter.value || '').trim().toLowerCase();
        rows.forEach((row) => {
            const hay = row.getAttribute('data-search') || '';
            const rowCls = row.getAttribute('data-class-name') || '';
            const matchSearch = (!q || hay.includes(q));
            const matchClass = (!cls || rowCls === cls);
            row.style.display = (matchSearch && matchClass) ? '' : 'none';
        });
    }
    if (input) input.addEventListener('input', applyFilters);
    if (classFilter) classFilter.addEventListener('change', applyFilters);
});
</script>
</body>
</html>
