<?php
// admin-parents-children.php — Admin view of parents and their linked children
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$pdo = pcm_pdo();
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

$childrenByParent = [];
foreach ($students as $student) {
    $pid = (int)($student['parent_id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    if (!isset($childrenByParent[$pid])) {
        $childrenByParent[$pid] = [];
    }
    $childrenByParent[$pid][] = $student;
}

$totalParents = count($parents);
$parentsWithChildren = 0;
$totalChildren = 0;
$activeChildren = 0;
foreach ($parents as $parent) {
    $pid = (int)$parent['id'];
    $kids = $childrenByParent[$pid] ?? [];
    if (!empty($kids)) {
        $parentsWithChildren++;
    }
    foreach ($kids as $kid) {
        $totalChildren++;
        if (strtolower((string)($kid['student_status'] ?? 'active')) !== 'past') {
            $activeChildren++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Parents & Children</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .family-card { border-left: 4px solid #881b12; }
        .parent-meta { font-size: .86rem; color: #6c757d; }
        .child-table th { white-space: nowrap; }
        .child-name { font-weight: 700; color: #2f2f2f; }
        .summary-tile { border-radius: 8px; border: 1px solid #e3e6f0; background: #fff; }
        .summary-value { font-size: 1.35rem; font-weight: 800; color: #4e332f; line-height: 1; }
        .summary-label { font-size: .76rem; text-transform: uppercase; letter-spacing: .04em; color: #858796; font-weight: 700; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid py-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-1 text-gray-800">Parents & Children</h1>
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
        <div class="col-md-3 mb-2">
            <div class="summary-tile p-3">
                <div class="summary-label">Parents</div>
                <div class="summary-value"><?= (int)$totalParents ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="summary-tile p-3">
                <div class="summary-label">With Children</div>
                <div class="summary-value"><?= (int)$parentsWithChildren ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="summary-tile p-3">
                <div class="summary-label">Children</div>
                <div class="summary-value"><?= (int)$totalChildren ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="summary-tile p-3">
                <div class="summary-label">Active Children</div>
                <div class="summary-value"><?= (int)$activeChildren ?></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <label class="small font-weight-bold text-muted mb-1" for="familySearch">Search Parent or Child</label>
            <input type="text" id="familySearch" class="form-control" placeholder="Type parent name, email, phone, or child name...">
        </div>
    </div>

    <?php if (empty($parents)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center text-muted py-5">No parent accounts found.</div>
        </div>
    <?php else: ?>
        <div id="familyList">
            <?php foreach ($parents as $parent): ?>
                <?php
                    $pid = (int)$parent['id'];
                    $kids = $childrenByParent[$pid] ?? [];
                    $searchText = strtolower(trim(
                        (string)($parent['full_name'] ?? '') . ' ' .
                        (string)($parent['email'] ?? '') . ' ' .
                        (string)($parent['phone'] ?? '') . ' ' .
                        implode(' ', array_map(function ($kid) {
                            return (string)($kid['student_name'] ?? '') . ' ' . (string)($kid['student_id'] ?? '');
                        }, $kids))
                    ));
                ?>
                <div class="card shadow-sm mb-3 family-card" data-search="<?= h($searchText) ?>">
                    <div class="card-header bg-white d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                        <div>
                            <div class="h6 mb-1 font-weight-bold text-primary"><?= h((string)($parent['full_name'] ?? 'Unnamed parent')) ?></div>
                            <div class="parent-meta">
                                <i class="fas fa-envelope mr-1"></i><?= h((string)($parent['email'] ?? '')) ?>
                                <span class="mx-2">|</span>
                                <i class="fas fa-phone mr-1"></i><?= h((string)($parent['phone'] ?? '—')) ?>
                            </div>
                        </div>
                        <div class="mt-2 mt-md-0">
                            <span class="badge badge-<?= strtolower((string)($parent['status'] ?? 'active')) === 'active' ? 'success' : 'secondary' ?> mr-1">
                                <?= h((string)($parent['status'] ?? 'Active')) ?>
                            </span>
                            <span class="badge badge-info"><?= count($kids) ?> child<?= count($kids) === 1 ? '' : 'ren' ?></span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($kids)): ?>
                            <div class="p-3 text-muted">No children linked to this parent.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0 child-table">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Child</th>
                                            <th>Registration</th>
                                            <th>Enrollment</th>
                                            <th>Fee Plan</th>
                                            <th>Class</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($kids as $kid): ?>
                                            <?php
                                                $approval = (string)($kid['approval_status'] ?? 'Pending');
                                                $enrolment = trim((string)($kid['enrolment_status'] ?? ''));
                                                $studentStatus = (string)($kid['student_status'] ?? 'Active');
                                            ?>
                                            <tr>
                                                <td><code><?= h((string)($kid['student_id'] ?? '')) ?></code></td>
                                                <td class="child-name"><?= h((string)($kid['student_name'] ?? '')) ?></td>
                                                <td><span class="badge badge-<?= pcm_badge($approval) ?>"><?= h($approval) ?></span></td>
                                                <td>
                                                    <?php if ($enrolment !== ''): ?>
                                                        <span class="badge badge-<?= pcm_badge($enrolment) ?>"><?= h($enrolment) ?></span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Please enroll</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h((string)($kid['fee_plan'] ?? '—')) ?></td>
                                                <td><?= h((string)($kid['class_name'] ?? 'Not assigned')) ?></td>
                                                <td><span class="badge badge-<?= strtolower($studentStatus) === 'past' ? 'secondary' : 'success' ?>"><?= h($studentStatus) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="noFamilyMatches" class="card shadow-sm d-none">
            <div class="card-body text-center text-muted py-4">No matching parent or child found.</div>
        </div>
    <?php endif; ?>
</div>

</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const search = document.getElementById('familySearch');
    const cards = Array.from(document.querySelectorAll('.family-card'));
    const empty = document.getElementById('noFamilyMatches');
    if (!search) return;
    search.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        let shown = 0;
        cards.forEach(function(card) {
            const matched = q === '' || (card.getAttribute('data-search') || '').includes(q);
            card.style.display = matched ? '' : 'none';
            if (matched) shown++;
        });
        if (empty) {
            empty.classList.toggle('d-none', shown !== 0);
        }
    });
});
</script>
</body>
</html>
