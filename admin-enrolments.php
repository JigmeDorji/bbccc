<?php
// admin-enrolments.php — Review / Approve / Reject parent enrolment requests
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized.php"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;

// ── POST: approve or reject ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve','reject'])) {
    verify_csrf();
    $eid    = (int)($_POST['enrolment_id'] ?? 0);
    $note   = trim($_POST['admin_note'] ?? '');
    $action = $_POST['action'];

    $row = $pdo->prepare("
        SELECT e.*, s.student_name, p.full_name AS parent_name, p.email AS parent_email
        FROM pcm_enrolments e
        JOIN students s ON s.id = e.student_id
        JOIN parents  p ON p.id = e.parent_id
        WHERE e.id = :id LIMIT 1
    ");
    $row->execute([':id'=>$eid]);
    $en = $row->fetch();

    if (!$en) {
        $flash = 'Enrolment not found.';
    } elseif ($en['status'] !== 'Pending') {
        $flash = 'Already processed.';
    } else {
        $newStatus  = ($action === 'approve') ? 'Approved' : 'Rejected';
        $reviewer   = $_SESSION['username'] ?? 'admin';

        $pdo->beginTransaction();
        try {
            // Update enrolment
            $upd = $pdo->prepare("
                UPDATE pcm_enrolments SET status=:st, admin_note=:n, reviewed_by=:rb, reviewed_at=NOW() WHERE id=:id
            ");
            $upd->execute([':st'=>$newStatus, ':n'=>$note?:null, ':rb'=>$reviewer, ':id'=>$eid]);

            // Update student approval_status to match
            $updStu = $pdo->prepare("UPDATE students SET approval_status=:st WHERE id=:sid");
            $updStu->execute([':st'=>$newStatus, ':sid'=>$en['student_id']]);

            if ($newStatus === 'Approved') {
                // Create fee instalment rows
                pcm_create_fee_rows($pdo, $eid, (int)$en['student_id'], (int)$en['parent_id'], $en['fee_plan'], $en['proof_path']);
            }

            $pdo->commit();

            // Email parent
            pcm_notify_parent_enrolment($en['parent_email'], $en['parent_name'], $en['student_name'], $newStatus, $note);

            $flash = "Enrolment <strong>{$newStatus}</strong> for {$en['student_name']}.";
            $ok = true;
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash = 'Error: ' . $ex->getMessage();
        }
    }
}

// ── Fetch all enrolments ──
$all = $pdo->query("
    SELECT e.*, s.student_id AS stu_code, s.student_name, s.dob, s.class_option,
           p.full_name AS parent_name, p.email AS parent_email, p.phone AS parent_phone
    FROM pcm_enrolments e
    JOIN students s ON s.id = e.student_id
    JOIN parents  p ON p.id = e.parent_id
    ORDER BY FIELD(e.status,'Pending','Approved','Rejected'), e.submitted_at DESC
")->fetchAll();

// Counts
$total   = count($all);
$pending = count(array_filter($all, fn($r)=>$r['status']==='Pending'));
$approved= count(array_filter($all, fn($r)=>$r['status']==='Approved'));
$rejected= count(array_filter($all, fn($r)=>$r['status']==='Rejected'));

$pageScripts = [
    "https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js",
    "https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Enrolment Management</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid py-3">

<?php if ($flash): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({icon:'<?= $ok?"success":"error" ?>',html:<?= json_encode($flash) ?>,timer:2500,showConfirmButton:false})
    <?= $ok ? ".then(()=>window.location='admin-enrolments.php')" : "" ?>;
});
</script>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row mb-3">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-primary shadow h-100 py-2"><div class="card-body"><div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total ?></div></div></div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-warning shadow h-100 py-2"><div class="card-body"><div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?= $pending ?></div></div></div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-success shadow h-100 py-2"><div class="card-body"><div class="text-xs font-weight-bold text-success text-uppercase mb-1">Approved</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?= $approved ?></div></div></div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-danger shadow h-100 py-2"><div class="card-body"><div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Rejected</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?= $rejected ?></div></div></div>
    </div>
</div>

<!-- Status Filter Buttons -->
<div class="mb-3">
    <button class="btn btn-sm btn-primary filter-btn active" data-filter="all">All</button>
    <button class="btn btn-sm btn-warning filter-btn" data-filter="Pending">Pending</button>
    <button class="btn btn-sm btn-success filter-btn" data-filter="Approved">Approved</button>
    <button class="btn btn-sm btn-danger filter-btn"  data-filter="Rejected">Rejected</button>
</div>

<!-- Enrolments Table -->
<div class="card shadow mb-4">
    <div class="card-body">
        <div class="table-responsive">
            <table id="enrolTable" class="table table-bordered table-hover" style="width:100%">
                <thead class="thead-light">
                    <tr>
                        <th>#</th><th>Child</th><th>Student ID</th><th>Parent</th><th>Phone</th>
                        <th>Plan</th><th>Amount</th><th>Ref</th><th>Proof</th><th>Status</th><th>Submitted</th><th style="width:200px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all as $i => $e): ?>
                <tr data-status="<?= $e['status'] ?>">
                    <td><?= $i+1 ?></td>
                    <td><?= h($e['student_name']) ?></td>
                    <td><code><?= h($e['stu_code']) ?></code></td>
                    <td><?= h($e['parent_name']) ?><br><small class="text-muted"><?= h($e['parent_email']) ?></small></td>
                    <td><?= h($e['parent_phone'] ?? '-') ?></td>
                    <td><?= h($e['fee_plan']) ?></td>
                    <td>$<?= number_format($e['fee_amount'],2) ?></td>
                    <td><?= h($e['payment_ref'] ?? '—') ?></td>
                    <td><?= $e['proof_path'] ? '<a href="'.h($e['proof_path']).'" target="_blank">View</a>' : '—' ?></td>
                    <td><span class="badge badge-<?= pcm_badge($e['status']) ?>"><?= h($e['status']) ?></span>
                        <?php if ($e['admin_note']): ?><br><small><?= h($e['admin_note']) ?></small><?php endif; ?>
                    </td>
                    <td><?= date('d M Y', strtotime($e['submitted_at'])) ?></td>
                    <td>
                        <?php if ($e['status'] === 'Pending'): ?>
                        <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#approveModal<?= $e['id'] ?>"><i class="fas fa-check mr-1"></i>Approve</button>
                        <button class="btn btn-danger btn-sm"  data-toggle="modal" data-target="#rejectModal<?= $e['id'] ?>"><i class="fas fa-times mr-1"></i>Reject</button>

                        <!-- Approve Modal -->
                        <div class="modal fade" id="approveModal<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="enrolment_id" value="<?= $e['id'] ?>">
                                    <div class="modal-header bg-success text-white"><h5 class="modal-title">Approve Enrolment</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
                                    <div class="modal-body">
                                        <p>Approve enrolment for <strong><?= h($e['student_name']) ?></strong>?</p>
                                        <p class="small text-muted">This will also create fee instalment records and approve the first payment if proof is attached.</p>
                                        <div class="form-group"><label>Note (optional)</label><textarea name="admin_note" class="form-control" rows="2"></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Approve</button></div>
                                </form>
                            </div></div>
                        </div>

                        <!-- Reject Modal -->
                        <div class="modal fade" id="rejectModal<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="enrolment_id" value="<?= $e['id'] ?>">
                                    <div class="modal-header bg-danger text-white"><h5 class="modal-title">Reject Enrolment</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
                                    <div class="modal-body">
                                        <p>Reject enrolment for <strong><?= h($e['student_name']) ?></strong>?</p>
                                        <div class="form-group"><label>Reason / Note</label><textarea name="admin_note" class="form-control" rows="2" required></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Reject</button></div>
                                </form>
                            </div></div>
                        </div>
                        <?php else: ?>
                            <span class="text-muted small"><?= h($e['reviewed_by'] ?? '') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
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
$(function(){
    var dt = $('#enrolTable').DataTable({pageLength:25, order:[[10,'desc']]});

    // Status filter buttons
    $('.filter-btn').on('click', function(){
        $('.filter-btn').removeClass('active');
        $(this).addClass('active');
        var f = $(this).data('filter');
        if(f === 'all') {
            dt.column(9).search('').draw();
        } else {
            dt.column(9).search('^'+f+'$', true, false).draw();
        }
    });
});
</script>
</body>
</html>
