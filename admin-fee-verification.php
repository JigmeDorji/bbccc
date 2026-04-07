<?php
// admin-fee-verification.php — Admin verifies or rejects parent fee payment proofs
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_once "include/notifications.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;

// ── POST: verify or reject ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verify','reject'])) {
    verify_csrf();
    $fid    = (int)($_POST['fee_id'] ?? 0);
    $action = $_POST['action'];
    $reason = trim($_POST['reject_reason'] ?? '');

    $row = $pdo->prepare("
        SELECT f.*, s.student_name, p.full_name AS parent_name, p.email AS parent_email
        FROM pcm_fee_payments f
        JOIN students s ON s.id = f.student_id
        JOIN parents  p ON p.id = f.parent_id
        WHERE f.id = :id LIMIT 1
    ");
    $row->execute([':id'=>$fid]);
    $fee = $row->fetch();

    if (!$fee) {
        $flash = 'Record not found.';
    } elseif ($fee['status'] !== 'Pending') {
        $flash = 'This payment is not awaiting review.';
    } else {
        $newStatus = ($action === 'verify') ? 'Verified' : 'Rejected';
        $reviewer  = $_SESSION['username'] ?? 'admin';

        $upd = $pdo->prepare("
            UPDATE pcm_fee_payments
            SET status=:st, verified_by=:vb, verified_at=NOW(),
                reject_reason = CASE WHEN :st2='Rejected' THEN :rr ELSE NULL END,
                paid_amount   = CASE WHEN :st3='Verified' THEN due_amount ELSE 0 END
            WHERE id=:id
        ");
        $upd->execute([':st'=>$newStatus, ':vb'=>$reviewer, ':st2'=>$newStatus, ':rr'=>$reason?:null, ':st3'=>$newStatus, ':id'=>$fid]);

        pcm_notify_parent_fee($fee['parent_email'], $fee['parent_name'], $fee['student_name'], $fee['instalment_label'], $newStatus);
        bbcc_notify_username(
            $pdo,
            (string)$fee['parent_email'],
            'Fee Payment ' . $newStatus . ' for ' . (string)$fee['student_name'],
            'Your payment proof for ' . (string)$fee['instalment_label'] . ' is now marked as ' . $newStatus . '.',
            'parent-payments'
        );
        $flash = "Payment <strong>{$newStatus}</strong> — {$fee['student_name']} ({$fee['instalment_label']}).";
        $ok = true;
    }
}

// ── Fetch payments awaiting verification + recent history ──
$payments = $pdo->query("
    SELECT f.*, s.student_name, s.student_id AS stu_code, p.full_name AS parent_name, p.email AS parent_email
    FROM pcm_fee_payments f
    JOIN students s ON s.id = f.student_id
    JOIN parents  p ON p.id = f.parent_id
    WHERE f.status IN ('Pending','Verified','Rejected')
    ORDER BY FIELD(f.status,'Pending','Rejected','Verified'), f.submitted_at DESC
    LIMIT 200
")->fetchAll();

$pendingCount  = count(array_filter($payments, fn($r)=>$r['status']==='Pending'));
$verifiedCount = count(array_filter($payments, fn($r)=>$r['status']==='Verified'));
$rejectedCount = count(array_filter($payments, fn($r)=>$r['status']==='Rejected'));

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
    <title>Fee Verification</title>
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
    <?= $ok ? ".then(()=>window.location='admin-fee-verification.php')" : "" ?>;
});
</script>
<?php endif; ?>

<!-- Summary -->
<div class="row mb-3">
    <div class="col-md-4 mb-3">
        <div class="card border-left-warning shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Review</div><div class="h5 mb-0 font-weight-bold"><?= $pendingCount ?></div></div></div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card border-left-success shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-success text-uppercase mb-1">Verified</div><div class="h5 mb-0 font-weight-bold"><?= $verifiedCount ?></div></div></div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card border-left-danger shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Rejected</div><div class="h5 mb-0 font-weight-bold"><?= $rejectedCount ?></div></div></div>
    </div>
</div>

<!-- Filter -->
<div class="mb-3">
    <button class="btn btn-sm btn-primary filter-btn active" data-filter="all">All</button>
    <button class="btn btn-sm btn-warning filter-btn" data-filter="Pending">Pending</button>
    <button class="btn btn-sm btn-success filter-btn" data-filter="Verified">Verified</button>
    <button class="btn btn-sm btn-danger filter-btn"  data-filter="Rejected">Rejected</button>
</div>

<!-- Table -->
<div class="card shadow mb-4">
    <div class="card-body">
    <div class="table-responsive">
        <table id="feeTable" class="table table-bordered table-hover" style="width:100%">
            <thead class="thead-light">
                <tr><th>#</th><th>Child</th><th>Parent</th><th>Plan</th><th>Instalment</th><th>Due</th><th>Paid</th><th>Ref</th><th>Proof</th><th>Status</th><th>Submitted</th><th style="width:180px">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $i => $f): ?>
            <tr data-status="<?= $f['status'] ?>">
                <td><?= $i+1 ?></td>
                <td><?= h($f['student_name']) ?> <small class="text-muted">(<?= h($f['stu_code']) ?>)</small></td>
                <td><?= h($f['parent_name']) ?></td>
                <td><?= h($f['plan_type']) ?></td>
                <td class="font-weight-bold"><?= h($f['instalment_label']) ?></td>
                <td>$<?= number_format($f['due_amount'],2) ?></td>
                <td>$<?= number_format($f['paid_amount'],2) ?></td>
                <td><?= h($f['payment_ref'] ?? '—') ?></td>
                <td><?= $f['proof_path'] ? '<a href="'.h($f['proof_path']).'" target="_blank">View</a>' : '—' ?></td>
                <td><span class="badge badge-<?= pcm_badge($f['status']) ?>"><?= h($f['status']) ?></span>
                    <?php if ($f['reject_reason']): ?><br><small class="text-danger"><?= h($f['reject_reason']) ?></small><?php endif; ?>
                </td>
                <td><?= $f['submitted_at'] ? date('d M Y', strtotime($f['submitted_at'])) : '—' ?></td>
                <td>
                    <?php if ($f['status'] === 'Pending'): ?>
                    <form method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="fee_id" value="<?= $f['id'] ?>">
                        <button class="btn btn-success btn-sm" onclick="return confirm('Verify this payment?')"><i class="fas fa-check mr-1"></i>Verify</button>
                    </form>
                    <button class="btn btn-danger btn-sm" data-toggle="modal" data-target="#rejectFee<?= $f['id'] ?>"><i class="fas fa-times mr-1"></i>Reject</button>

                    <div class="modal fade" id="rejectFee<?= $f['id'] ?>" tabindex="-1">
                        <div class="modal-dialog"><div class="modal-content">
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="fee_id" value="<?= $f['id'] ?>">
                                <div class="modal-header bg-danger text-white"><h5 class="modal-title">Reject Payment</h5><button class="close text-white" data-dismiss="modal">&times;</button></div>
                                <div class="modal-body">
                                    <p><?= h($f['student_name']) ?> — <?= h($f['instalment_label']) ?></p>
                                    <div class="form-group"><label>Reason</label><textarea name="reject_reason" class="form-control" rows="2" required></textarea></div>
                                </div>
                                <div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Reject</button></div>
                            </form>
                        </div></div>
                    </div>
                    <?php else: ?>
                        <span class="text-muted small"><?= h($f['verified_by'] ?? '') ?></span>
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
    var dt = $('#feeTable').DataTable({pageLength:25, order:[[10,'desc']]});
    $('.filter-btn').on('click',function(){
        $('.filter-btn').removeClass('active'); $(this).addClass('active');
        var f = $(this).data('filter');
        dt.column(9).search(f==='all'?'':'^'+f+'$', true, false).draw();
    });
});
</script>
</body>
</html>
