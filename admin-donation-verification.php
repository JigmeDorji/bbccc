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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_custom_email') {
    verify_csrf();
    $did = (int)($_POST['donation_id'] ?? 0);
    $subjectTpl = trim((string)($_POST['email_subject'] ?? 'Thank you for your donation, {donor_name}'));
    $bodyTpl = trim((string)($_POST['email_body'] ?? 'Dear {donor_name},'));
    try {
        if ($did <= 0) throw new Exception('Invalid donation record.');
        if ($subjectTpl === '' || $bodyTpl === '') throw new Exception('Subject and message are required.');

        $row = $pdo->prepare("SELECT * FROM donations WHERE id = :id LIMIT 1");
        $row->execute([':id' => $did]);
        $donation = $row->fetch(PDO::FETCH_ASSOC);
        if (!$donation) throw new Exception('Record not found.');
        $toEmail = trim((string)($donation['donor_email'] ?? ''));
        if ($toEmail === '') throw new Exception('Donor email not available.');

        $vars = [
            '{donor_name}' => (string)($donation['donor_name'] ?? 'Donor'),
            '{amount}' => number_format((float)($donation['amount'] ?? 0), 2),
            '{status}' => (string)($donation['status'] ?? ''),
            '{payment_ref}' => (string)($donation['payment_ref'] ?? ''),
            '{message}' => (string)($donation['message'] ?? ''),
        ];
        $subject = strtr($subjectTpl, $vars);
        $bodyText = strtr($bodyTpl, $vars);
        $html = pcm_email_wrap($subject, '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;">' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</div>');
        $queued = bbcc_queue_mail($toEmail, (string)($donation['donor_name'] ?? 'Donor'), $subject, $html);
        if (!$queued) throw new Exception('Email could not be queued.');

        $flash = 'Email queued successfully to ' . h($toEmail) . '.';
        $ok = true;
    } catch (Throwable $e) {
        $flash = 'Error: ' . $e->getMessage();
        $ok = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['donation_verification_flash'] = ['message' => $flash, 'ok' => $ok];
    header('Location: admin-donation-verification');
    exit;
}

function donation_proof_type(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return ($ext === 'pdf') ? 'pdf' : 'img';
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
                <tr><th>#</th><th>Donor</th><th>Email</th><th>Phone</th><th>Amount</th><th>Ref</th><th>Proof</th><th>Status</th><th>Submitted</th><th style="width:130px">Actions</th></tr>
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
                <td>
                    <?php if ($d['proof_path']): ?>
                        <?php $ptype = donation_proof_type((string)$d['proof_path']); ?>
                        <a href="javascript:void(0)" class="mini proof-thumb" data-proof="<?= h((string)$d['proof_path']) ?>" data-type="<?= h($ptype) ?>" data-name="<?= h(basename((string)$d['proof_path'])) ?>"><i class="fas fa-eye"></i> View</a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-<?= pcm_badge($d['status']) ?>"><?= h($d['status']) ?></span>
                    <?php if ($d['reject_reason']): ?><br><small class="text-danger"><?= h($d['reject_reason']) ?></small><?php endif; ?>
                </td>
                <td><?= $d['submitted_at'] ? date('d M Y', strtotime($d['submitted_at'])) : '—' ?></td>
                <td class="nowrap">
                    <?php if ($d['status'] === 'Pending'): ?>
                    <form method="POST" class="d-inline" data-confirm="Verify this donation?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>">
                        <button class="btn btn-success btn-sm" title="Verify"><i class="fas fa-check"></i></button>
                    </form>
                    <button type="button" class="btn btn-danger btn-sm js-reject-donation-btn" title="Reject"
                            data-id="<?= (int)$d['id'] ?>" data-donor="<?= h($d['donor_name']) ?>" data-amount="<?= number_format((float)$d['amount'], 2) ?>">
                        <i class="fas fa-times"></i>
                    </button>
                    <?php else: ?>
                        <span class="text-muted small"><?= h($d['verified_by'] ?? '') ?></span>
                    <?php endif; ?>
                    <button type="button" class="btn btn-info btn-sm js-email-donation-btn" title="Send Email" data-id="<?= (int)$d['id'] ?>" data-donor="<?= h($d['donor_name']) ?>">
                        <i class="fas fa-envelope"></i>
                    </button>
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

<!-- Shared modal: Reject -->
<div class="modal fade" id="rejectDonationModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="donation_id" id="rejectDonationId" value="">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Donation</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p><strong id="rejectDonationDonor"></strong> — $<span id="rejectDonationAmount"></span></p>
                    <div class="form-group mb-0"><label>Reason</label><textarea name="reject_reason" class="form-control" rows="2" required></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Send Email -->
<div class="modal fade" id="emailDonationModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_custom_email">
                <input type="hidden" name="donation_id" id="emailDonationId" value="">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Send Donor Email</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="mini mb-2">Variables: {donor_name}, {amount}, {status}, {payment_ref}, {message}</div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="email_subject" class="form-control" value="Thank you for your donation, {donor_name}" required>
                    </div>
                    <div class="form-group mb-0">
                        <label>Message</label>
                        <textarea name="email_body" class="form-control" rows="8" required>Dear {donor_name},

Thank you for your generous donation of ${amount} to the Bhutanese Buddhist and Cultural Centre. Your donation has been {status}.

With gratitude,
BBCC</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">Send Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .proof-thumb { display:inline-flex; align-items:center; gap:6px; cursor:pointer; text-decoration:none; }
    .swal2-popup { width: 920px !important; max-width: 96vw !important; }
    .proof-frame { width: 100%; height: 70vh; border: 1px solid #e3e6f0; border-radius: 12px; }
    .proof-stage { width: 100%; max-height: 72vh; overflow: auto; border: 1px solid #e3e6f0; border-radius: 12px; padding: 10px; background: #fafbff; display: flex; align-items: center; justify-content: center; }
    .proof-img { display:block; border-radius: 10px; border: 1px solid #e3e6f0; background:#fff; max-width: 100%; max-height: calc(72vh - 24px); width: auto; height: auto; }
</style>

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

document.addEventListener('click', function (e) {
    var rejectBtn = e.target.closest('.js-reject-donation-btn');
    if (rejectBtn) {
        document.getElementById('rejectDonationId').value = rejectBtn.dataset.id;
        document.getElementById('rejectDonationDonor').textContent = rejectBtn.dataset.donor;
        document.getElementById('rejectDonationAmount').textContent = rejectBtn.dataset.amount;
        jQuery('#rejectDonationModal').modal('show');
        return;
    }

    var emailBtn = e.target.closest('.js-email-donation-btn');
    if (emailBtn) {
        document.getElementById('emailDonationId').value = emailBtn.dataset.id;
        jQuery('#emailDonationModal').modal('show');
        return;
    }

    var proofEl = e.target.closest('.proof-thumb');
    if (proofEl) {
        var proof = proofEl.dataset.proof;
        var type = proofEl.dataset.type;
        var name = proofEl.dataset.name;
        var html;
        if (type === 'img') {
            html = '<div class="proof-stage"><img class="proof-img" src="' + proof + '" alt="' + name + '"></div>';
        } else {
            html = '<iframe class="proof-frame" src="' + proof + '"></iframe>';
        }
        Swal.fire({
            title: name,
            html: html,
            showConfirmButton: true,
            confirmButtonText: 'Close',
            width: 920,
        });
    }
});
</script>
</body>
</html>
