<?php
// admin-donation-verification.php — Admin verifies or rejects public donation submissions
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

$pdo   = pcm_pdo();
$flash = '';
$ok    = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['donation_verification_flash'])) {
    $saved = (array)$_SESSION['donation_verification_flash'];
    unset($_SESSION['donation_verification_flash']);
    $flash = (string)($saved['message'] ?? '');
    $ok = !empty($saved['ok']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verify', 'reject'], true)) {
    verify_csrf();
    $did    = (int)($_POST['donation_id'] ?? 0);
    $action = $_POST['action'];
    $reason = trim((string)($_POST['reject_reason'] ?? ''));

    $row = $pdo->prepare("SELECT * FROM donations WHERE id = :id LIMIT 1");
    $row->execute([':id' => $did]);
    $donation = $row->fetch();

    if (!$donation) {
        $flash = 'Record not found.';
    } elseif ($donation['status'] !== 'Pending') {
        $flash = 'This donation is not awaiting review.';
    } elseif ($action === 'reject' && $reason === '') {
        $flash = 'A rejection reason is required.';
    } else {
        $newStatus = ($action === 'verify') ? 'Verified' : 'Rejected';
        $reviewer  = $_SESSION['username'] ?? 'admin';

        $pdo->prepare("
            UPDATE donations
            SET status = :st, verified_by = :vb, verified_at = NOW(),
                reject_reason = CASE WHEN :st2 = 'Rejected' THEN :rr ELSE NULL END
            WHERE id = :id
        ")->execute([
            ':st' => $newStatus, ':vb' => $reviewer, ':st2' => $newStatus,
            ':rr' => $reason ?: null, ':id' => $did,
        ]);

        if (!empty($donation['donor_email'])) {
            $bodyExtra = ($newStatus === 'Rejected' && $reason !== '')
                ? "<p style='margin:0 0 14px;'>Reason: " . htmlspecialchars($reason) . "</p>"
                : "";
            $html = pcm_email_wrap('Donation ' . $newStatus, "
                <p style='margin:0 0 14px;'>Dear " . htmlspecialchars($donation['donor_name']) . ",</p>
                <p style='margin:0 0 14px;'>Your donation of <strong>\$" . number_format((float)$donation['amount'], 2) . "</strong> has been <strong>{$newStatus}</strong>.</p>
                {$bodyExtra}
                <p style='margin:0;'>Thank you for supporting the Bhutanese Buddhist and Cultural Centre.</p>
            ");
            bbcc_queue_mail($donation['donor_email'], $donation['donor_name'], 'Donation ' . $newStatus, $html);
        }

        $flash = "Donation <strong>{$newStatus}</strong> — " . h($donation['donor_name']) . " (\$" . number_format((float)$donation['amount'], 2) . ").";
        $ok = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['donation_verification_flash'] = ['message' => $flash, 'ok' => $ok];
    header('Location: admin-donation-verification');
    exit;
}

$donations = $pdo->query("
    SELECT * FROM donations
    ORDER BY FIELD(status,'Pending','Rejected','Verified'), submitted_at DESC
    LIMIT 200
")->fetchAll();

$pendingCount  = count(array_filter($donations, fn($r) => $r['status'] === 'Pending'));
$verifiedCount = count(array_filter($donations, fn($r) => $r['status'] === 'Verified'));
$rejectedCount = count(array_filter($donations, fn($r) => $r['status'] === 'Rejected'));

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
    <title>Donation Verification</title>
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
    <?= $ok ? ".then(()=>window.location='admin-donation-verification.php')" : "" ?>;
});
</script>
<?php endif; ?>

<h1 class="h3 mb-4 text-gray-800">Donation Verification</h1>

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
        <table id="donationTable" class="table table-bordered table-hover" style="width:100%">
            <thead class="thead-light">
                <tr><th>#</th><th>Donor</th><th>Email</th><th>Phone</th><th>Amount</th><th>Ref</th><th>Proof</th><th>Status</th><th>Submitted</th><th style="width:180px">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($donations as $i => $d): ?>
            <tr data-status="<?= h($d['status']) ?>">
                <td><?= $i + 1 ?></td>
                <td><?= h($d['donor_name']) ?><?php if (!empty($d['message'])): ?><br><small class="text-muted"><?= h(mb_strimwidth($d['message'], 0, 60, '...')) ?></small><?php endif; ?></td>
                <td><?= h($d['donor_email'] ?? '—') ?></td>
                <td><?= h($d['donor_phone'] ?? '—') ?></td>
                <td class="font-weight-bold">$<?= number_format((float)$d['amount'], 2) ?></td>
                <td><?= h($d['payment_ref'] ?? '—') ?></td>
                <td><?= $d['proof_path'] ? '<a href="' . h($d['proof_path']) . '" target="_blank">View</a>' : '—' ?></td>
                <td><span class="badge badge-<?= pcm_badge($d['status']) ?>"><?= h($d['status']) ?></span>
                    <?php if ($d['reject_reason']): ?><br><small class="text-danger"><?= h($d['reject_reason']) ?></small><?php endif; ?>
                </td>
                <td><?= $d['submitted_at'] ? date('d M Y', strtotime($d['submitted_at'])) : '—' ?></td>
                <td>
                    <?php if ($d['status'] === 'Pending'): ?>
                    <form method="POST" class="d-inline" data-confirm="Verify this donation?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>">
                        <button class="btn btn-success btn-sm"><i class="fas fa-check mr-1"></i>Verify</button>
                    </form>
                    <button class="btn btn-danger btn-sm" data-toggle="modal" data-target="#rejectDonation<?= (int)$d['id'] ?>"><i class="fas fa-times mr-1"></i>Reject</button>

                    <div class="modal fade" id="rejectDonation<?= (int)$d['id'] ?>" tabindex="-1">
                        <div class="modal-dialog"><div class="modal-content">
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>">
                                <div class="modal-header bg-danger text-white"><h5 class="modal-title">Reject Donation</h5><button class="close text-white" data-dismiss="modal">&times;</button></div>
                                <div class="modal-body">
                                    <p><?= h($d['donor_name']) ?> — $<?= number_format((float)$d['amount'], 2) ?></p>
                                    <div class="form-group"><label>Reason</label><textarea name="reject_reason" class="form-control" rows="2" required></textarea></div>
                                </div>
                                <div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Reject</button></div>
                            </form>
                        </div></div>
                    </div>
                    <?php else: ?>
                        <span class="text-muted small"><?= h($d['verified_by'] ?? '') ?></span>
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
    var dt = $('#donationTable').DataTable({pageLength:25, order:[[8,'desc']]});
    $('.filter-btn').on('click',function(){
        $('.filter-btn').removeClass('active'); $(this).addClass('active');
        var status = ($(this).data('filter') || 'all').toString();
        dt.column(7).search(status === 'all' ? '' : '^' + status + '$', true, false);
        dt.draw();
    });
});
</script>
</body>
</html>
