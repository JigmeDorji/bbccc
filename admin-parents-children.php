<?php
// admin-parents-children.php — Admin view of parents and their linked children
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/pcm_helpers.php";
require_once "include/csrf.php";
require_once "include/notifications.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$pdo = pcm_pdo();
$currentActor = (string)($_SESSION['username'] ?? 'admin');
$flash = '';
$flashOk = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_approve_enrolment') {
    verify_csrf();
    $studentDbId = (int)($_POST['student_id'] ?? 0);
    if ($studentDbId <= 0) {
        $flash = 'Invalid student.';
    } else {
        try {
            $statusStmt = $pdo->prepare("SELECT status FROM pcm_enrolments WHERE student_id = :sid LIMIT 1");
            $statusStmt->execute([':sid' => $studentDbId]);
            $currentStatus = (string)($statusStmt->fetchColumn() ?: '');
            if ($currentStatus !== 'Pending') {
                $flash = 'This enrolment is no longer Pending (already ' . h($currentStatus ?: 'unset') . ').';
            } else {
                $result = pcm_process_enrolment_decision($pdo, $studentDbId, 'approve', $currentActor, 'Approved from Parents & Children page.');
                pcm_log_enrolment_event($pdo, $studentDbId, (int)($result['enrolment_id'] ?? 0), 'enrolment_approved', $currentActor, 'Approved from Parents & Children page.');
                if (!empty($result['parent_email'])) {
                    pcm_notify_parent_enrolment_confirmed(
                        (string)$result['parent_email'],
                        (string)$result['parent_name'],
                        (string)$result['student_name']
                    );
                    bbcc_notify_username(
                        $pdo,
                        (string)$result['parent_email'],
                        'Enrollment Approved for ' . (string)$result['student_name'],
                        'Your child enrollment has been approved. Thank you for completing the enrollment process.',
                        'children-enrollment'
                    );
                }
                $flash = 'Enrolment approved for <strong>' . h((string)$result['student_name']) . '</strong>.';
                $flashOk = true;
            }
        } catch (Throwable $e) {
            $flash = 'Error: ' . $e->getMessage();
        }
    }
}

$studentParentExpr = pcm_students_parent_expr($pdo, 's');
$latestClassJoin = pcm_latest_class_assignment_join('s.id', 'ca', 'c');

$parents = $pdo->query("
    SELECT
        p.id,
        p.full_name,
        p.email,
        p.phone,
        p.status
    FROM parents p
    ORDER BY p.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
$parentsById = [];
foreach ($parents as $parent) {
    $parentsById[(int)$parent['id']] = $parent;
}

$students = $pdo->query("
    SELECT
        s.id AS student_db_id,
        s.student_id,
        s.student_name,
        s.approval_status,
        s.status AS student_status,
        {$studentParentExpr} AS parent_id,
        e.status AS enrolment_status,
        e.fee_plan,
        c.class_name
    FROM students s
    LEFT JOIN pcm_enrolments e ON e.student_id = s.id
    {$latestClassJoin}
    WHERE {$studentParentExpr} IS NOT NULL
      AND {$studentParentExpr} > 0
    ORDER BY s.student_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$totalParents = count($parents);
$parentsWithChildren = 0;
$totalChildren = count($students);
$activeChildren = 0;
$parentsSeen = [];
$totalChildCountByParent = [];
$activeChildCountByParent = [];
foreach ($students as $student) {
    $pid = (int)($student['parent_id'] ?? 0);
    if ($pid > 0 && !isset($parentsSeen[$pid])) {
        $parentsSeen[$pid] = true;
        $parentsWithChildren++;
    }
    if ($pid > 0) {
        $totalChildCountByParent[$pid] = ($totalChildCountByParent[$pid] ?? 0) + 1;
    }
    $isActive = strtolower((string)($student['student_status'] ?? 'active')) !== 'past';
    if ($isActive) {
        $activeChildren++;
        if ($pid > 0) {
            $activeChildCountByParent[$pid] = ($activeChildCountByParent[$pid] ?? 0) + 1;
        }
    }
}

$parentsWithoutActiveChildren = [];
foreach ($parents as $parent) {
    $pid = (int)$parent['id'];
    if (($activeChildCountByParent[$pid] ?? 0) === 0) {
        $parentsWithoutActiveChildren[] = $parent + [
            'total_children' => $totalChildCountByParent[$pid] ?? 0,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Parents &amp; Children</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/rowgroup/1.4.1/css/rowGroup.bootstrap4.min.css" rel="stylesheet">
    <style>
        .child-name { font-weight: 700; color: #2f2f2f; }
        tr.parent-group-row > td { background: #f8f9fc; border-top: 2px solid #e3e6f0; padding: 10px 12px; }
        tr.parent-group-row .parent-name { color: #881b12; font-size: .95rem; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<?php if ($flash !== ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: <?= $flashOk ? "'success'" : "'error'" ?>,
        title: <?= json_encode(strip_tags($flash)) ?>,
        showConfirmButton: true,
        timer: <?= $flashOk ? 1600 : 6000 ?>
    });
});
</script>
<?php endif; ?>

<div class="container-fluid py-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-1 text-gray-800">Parents &amp; Children</h1>
            <p class="text-muted mb-0">View each parent account with linked children, enrollment, and class status.</p>
        </div>
        <div class="mt-2 mt-sm-0">
            <a href="exportStudentPaymentDetails.php" class="btn btn-sm btn-warning mr-2">
                <i class="fas fa-file-excel mr-1"></i> Export Payments Excel
            </a>
            <a href="exportStudents.php" class="btn btn-sm btn-success mr-2">
                <i class="fas fa-download mr-1"></i> Export Students
            </a>
            <a href="admin-enrolments" class="btn btn-sm btn-primary">
                <i class="fas fa-file-signature mr-1"></i> Enrollment
            </a>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-3 mb-3">
            <div class="card border-left-primary shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Parents</div><div class="h5 mb-0 font-weight-bold"><?= (int)$totalParents ?></div></div></div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-left-info shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-info text-uppercase mb-1">With Children</div><div class="h5 mb-0 font-weight-bold"><?= (int)$parentsWithChildren ?></div></div></div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-left-warning shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Children</div><div class="h5 mb-0 font-weight-bold"><?= (int)$totalChildren ?></div></div></div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-left-success shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-success text-uppercase mb-1">Active Children</div><div class="h5 mb-0 font-weight-bold"><?= (int)$activeChildren ?></div></div></div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center" data-toggle="collapse" data-target="#noActiveChildrenBody" role="button" style="cursor:pointer;">
            <h6 class="m-0 font-weight-bold text-primary">
                Parents Without Active Children
                <span class="badge badge-warning ml-1"><?= count($parentsWithoutActiveChildren) ?></span>
            </h6>
            <small class="text-muted">Includes parents with zero children on record</small>
        </div>
        <div id="noActiveChildrenBody" class="collapse<?= !empty($parentsWithoutActiveChildren) ? ' show' : '' ?>">
            <div class="card-body">
                <?php if (empty($parentsWithoutActiveChildren)): ?>
                    <p class="text-muted mb-0">Every parent has at least one active child.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th><th>Parent</th><th>Email</th><th>Phone</th><th>Parent Status</th><th>Children on Record</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($parentsWithoutActiveChildren as $i => $p): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= h((string)($p['full_name'] ?? '')) ?></td>
                                <td><?= h((string)($p['email'] ?? '')) ?></td>
                                <td><?= h((string)($p['phone'] ?? '')) ?></td>
                                <td><span class="badge badge-<?= strtolower((string)($p['status'] ?? 'Active')) === 'active' ? 'success' : 'secondary' ?>"><?= h((string)($p['status'] ?? 'Active')) ?></span></td>
                                <td><?= (int)($p['total_children'] ?? 0) === 0 ? '<span class="text-muted">None</span>' : (int)$p['total_children'] . ' (none active)' ?></td>
                                <td>
                                    <a href="admin-enrolments?add_child_for=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-plus-circle mr-1"></i> Add Child
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="mb-3">
        <button class="btn btn-sm btn-primary filter-btn active" data-filter="all">All</button>
        <button class="btn btn-sm btn-outline-success filter-btn" data-filter="Active">Active</button>
        <button class="btn btn-sm btn-outline-secondary filter-btn" data-filter="Past">Past</button>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body">
        <div class="table-responsive">
            <table id="familyTable" class="table table-bordered table-hover" style="width:100%">
                <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Child</th>
                        <th>Parent ID</th>
                        <th>Parent</th>
                        <th>Parent Email</th>
                        <th>Parent Phone</th>
                        <th>Parent Status</th>
                        <th>Registration</th>
                        <th>Enrollment</th>
                        <th>Fee Plan</th>
                        <th>Class</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $i => $kid): ?>
                    <?php
                        $pid = (int)($kid['parent_id'] ?? 0);
                        $parent = $parentsById[$pid] ?? [];
                        $approval = (string)($kid['approval_status'] ?? 'Pending');
                        $enrolment = trim((string)($kid['enrolment_status'] ?? ''));
                        $studentStatus = (string)($kid['student_status'] ?? 'Active');
                        $studentStatusLower = strtolower($studentStatus);
                        $studentStatusBadge = $studentStatusLower === 'past' ? 'secondary' : ($studentStatusLower === 'pending' ? 'warning' : 'success');
                    ?>
                    <tr data-status="<?= h($studentStatus) ?>">
                        <td><?= $i + 1 ?></td>
                        <td><code><?= h((string)($kid['student_id'] ?? '')) ?></code></td>
                        <td class="child-name"><?= h((string)($kid['student_name'] ?? '')) ?></td>
                        <td><?= $pid ?></td>
                        <td><?= h((string)($parent['full_name'] ?? 'Unnamed parent')) ?></td>
                        <td><?= h((string)($parent['email'] ?? '')) ?></td>
                        <td><?= h((string)($parent['phone'] ?? '')) ?></td>
                        <td><?= h((string)($parent['status'] ?? 'Active')) ?></td>
                        <td><span class="badge badge-<?= pcm_badge($approval) ?>"><?= h($approval) ?></span></td>
                        <td>
                            <?php if ($enrolment !== ''): ?>
                                <span class="badge badge-<?= pcm_badge($enrolment) ?>"><?= h($enrolment) ?></span>
                                <?php if ($enrolment === 'Pending'): ?>
                                    <form method="POST" class="d-inline" data-confirm="Approve this enrolment for <?= h((string)($kid['student_name'] ?? 'this child')) ?>?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="quick_approve_enrolment">
                                        <input type="hidden" name="student_id" value="<?= (int)($kid['student_db_id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-outline-success btn-sm py-0 px-1 ml-1" title="Approve this enrolment"><i class="fas fa-check"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-warning">Please enroll</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h((string)($kid['fee_plan'] ?? '—')) ?></td>
                        <td><?= h((string)($kid['class_name'] ?? 'Not assigned')) ?></td>
                        <td><span class="badge badge-<?= $studentStatusBadge ?>"><?= h($studentStatus) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($students)): ?>
                    <tr><td colspan="12" class="text-center text-muted">No children found.</td></tr>
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

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/rowgroup/1.4.1/js/dataTables.rowGroup.min.js"></script>
<script>
$(function(){
    var dt = $('#familyTable').DataTable({
        pageLength: 25,
        order: [[3, 'asc'], [2, 'asc']],
        columnDefs: [
            { orderable: false, targets: 0 },
            { visible: false, targets: [3, 4, 5, 6, 7] }
        ],
        rowGroup: {
            // Group by the parent's actual account ID, not their name --
            // several different parents can share the same common name
            // (e.g. multiple "Sonam Tobgay" accounts), and grouping by
            // name text merged those distinct families into one row,
            // hiding all but one parent's contact details.
            dataSrc: 3,
            startRender: function(rows, group) {
                var d = rows.data()[0];
                var parentName = d[4] || 'Unnamed parent';
                var email = d[5] || '';
                var phone = d[6] || '';
                var pStatus = d[7] || 'Active';
                var badgeClass = pStatus.toLowerCase() === 'active' ? 'success' : 'secondary';
                var count = rows.count();

                var $tr = $('<tr class="parent-group-row"/>');
                var $td = $('<td colspan="8"/>').appendTo($tr);
                var $wrap = $('<div class="d-flex flex-wrap align-items-center justify-content-between"/>').appendTo($td);
                var $left = $('<div/>').appendTo($wrap);
                $('<strong class="parent-name"/>').text(parentName).appendTo($left);
                if (email) {
                    $left.append(' ');
                    $('<i class="fas fa-envelope text-muted small ml-2 mr-1"></i>').appendTo($left);
                    $('<span class="small text-muted"/>').text(email).appendTo($left);
                }
                if (phone) {
                    $left.append(' ');
                    $('<i class="fas fa-phone text-muted small ml-2 mr-1"></i>').appendTo($left);
                    $('<span class="small text-muted"/>').text(phone).appendTo($left);
                }
                var $right = $('<div/>').appendTo($wrap);
                $('<span class="badge mr-1"/>').addClass('badge-' + badgeClass).text(pStatus).appendTo($right);
                $('<span class="badge badge-info"/>').text(count + (count === 1 ? ' child' : ' children')).appendTo($right);
                return $tr;
            }
        }
    });
    $('.filter-btn').on('click', function(){
        $('.filter-btn').removeClass('active'); $(this).addClass('active');
        var status = ($(this).data('filter') || 'all').toString();
        dt.column(12).search(status === 'all' ? '' : '^' + status + '$', true, false);
        dt.draw();
    });
});
</script>
</body>
</html>
