<?php
// parent-fees.php — View fee schedule & upload payment proofs per instalment
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_parent_role()) { header("Location: unauthorized"); exit; }

$pdo      = pcm_pdo();
$parent   = pcm_current_parent($pdo);
if (!$parent) die("Parent account not found.");
$parentId = (int)$parent['id'];
$flash    = '';
$ok       = false;

// ── Bank details ──
$banks = $pdo->query("SELECT * FROM pcm_bank_accounts WHERE is_active=1 ORDER BY id")->fetchAll();

// ── POST: upload proof for a specific instalment ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    verify_csrf();
    $feeId = (int)($_POST['fee_id'] ?? 0);
    $ref   = trim($_POST['payment_ref'] ?? '');

    // Verify ownership
    $row = $pdo->prepare("SELECT * FROM pcm_fee_payments WHERE id=:id AND parent_id=:pid LIMIT 1");
    $row->execute([':id'=>$feeId, ':pid'=>$parentId]);
    $fee = $row->fetch();

    if (!$fee) {
        $flash = 'Payment record not found.';
    } elseif (!in_array($fee['status'], ['Unpaid','Rejected'])) {
        $flash = 'This instalment is already submitted or verified.';
    } elseif (empty($_FILES['proof']['name']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        $flash = 'Please select a valid proof file.';
    } else {
        $allowed = ['jpg','jpeg','png','pdf'];
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $flash = 'File must be JPG, PNG, or PDF.';
        } elseif ($_FILES['proof']['size'] > 5*1024*1024) {
            $flash = 'File must be under 5 MB.';
        } else {
            $dir = 'uploads/fees';
            pcm_ensure_dir($dir);
            $filename = 'fee_' . $feeId . '_' . time() . '.' . $ext;
            $path = $dir . '/' . $filename;
            if (move_uploaded_file($_FILES['proof']['tmp_name'], $path)) {
                $upd = $pdo->prepare("
                    UPDATE pcm_fee_payments
                    SET proof_path=:p, payment_ref=:ref, paid_amount=due_amount,
                        status='Pending', submitted_at=NOW(), reject_reason=NULL
                    WHERE id=:id
                ");
                $upd->execute([':p'=>$path, ':ref'=>$ref?:null, ':id'=>$feeId]);
                $flash = 'Payment proof uploaded. Awaiting admin verification.';
                $ok = true;
            } else {
                $flash = 'Upload failed. Try again.';
            }
        }
    }
}

// ── Fetch approved enrolments with fee rows ──
$enrolments = $pdo->prepare("
    SELECT e.id AS eid, e.fee_plan, s.student_name, s.student_id AS stu_code
    FROM pcm_enrolments e
    JOIN students s ON s.id = e.student_id
    WHERE e.parent_id = :pid AND e.status = 'Approved'
    ORDER BY s.student_name
");
$enrolments->execute([':pid'=>$parentId]);
$enrolments = $enrolments->fetchAll();

// Pre-load fees grouped by enrolment
$feesByEnrolment = [];
if ($enrolments) {
    $ids = array_column($enrolments, 'eid');
    $in  = implode(',', array_map('intval', $ids));
    $fees = $pdo->query("SELECT * FROM pcm_fee_payments WHERE enrolment_id IN ({$in}) ORDER BY id")->fetchAll();
    foreach ($fees as $f) {
        $feesByEnrolment[$f['enrolment_id']][] = $f;
    }
}

$pageScripts = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Fees &amp; Payments</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
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
    <?= $ok ? ".then(()=>window.location='parent-fees.php')" : "" ?>;
});
</script>
<?php endif; ?>

<!-- Bank Details -->
<?php if (!empty($banks)): ?>
<div class="card shadow mb-4" style="border-left:4px solid var(--brand) !important;">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold" style="color:var(--brand);"><i class="fas fa-university mr-1"></i>Bank Details for Payment</h6></div>
    <div class="card-body" style="background:linear-gradient(135deg,#f8f9fc,#eaecf4);">
        <div class="row">
        <?php foreach ($banks as $b): ?>
            <div class="col-md-6 mb-3">
                <div class="p-3 bg-white rounded shadow-sm" style="border-radius:12px !important;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong style="font-size:0.9rem;"><?= h($b['bank_name']) ?></strong>
                        <span class="copy-btn" style="cursor:pointer;color:var(--brand);font-size:0.78rem;" onclick="navigator.clipboard.writeText('<?= h($b['account_name'].' | BSB: '.$b['bsb'].' | Acc: '.$b['account_number']) ?>').then(()=>Swal.fire({icon:'success',title:'Copied!',timer:800,showConfirmButton:false}))">
                            <i class="fas fa-copy mr-1"></i>Copy
                        </span>
                    </div>
                    <div class="profile-label">Account Name</div>
                    <div class="profile-value mb-1"><?= h($b['account_name']) ?></div>
                    <div class="row">
                        <div class="col-6">
                            <div class="profile-label">BSB</div>
                            <div class="profile-value"><?= h($b['bsb']) ?></div>
                        </div>
                        <div class="col-6">
                            <div class="profile-label">Account #</div>
                            <div class="profile-value"><?= h($b['account_number']) ?></div>
                        </div>
                    </div>
                    <?= $b['reference_hint'] ? '<div class="mt-2" style="font-size:0.78rem;color:#858796;"><i class="fas fa-info-circle mr-1"></i>'.h($b['reference_hint']).'</div>' : '' ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($enrolments)): ?>
    <div class="card shadow"><div class="card-body text-muted">No approved enrolments yet. Once your enrolment is approved, fee instalments will appear here.</div></div>
<?php endif; ?>

<?php foreach ($enrolments as $en): ?>
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">
            <i class="fas fa-user-graduate mr-1"></i><?= h($en['student_name']) ?>
            <small class="text-muted ml-2">(<?= h($en['stu_code']) ?>) — <?= h($en['fee_plan']) ?></small>
        </h6>
    </div>
    <div class="card-body">
        <?php $fees = $feesByEnrolment[$en['eid']] ?? []; ?>
        <?php if (empty($fees)): ?>
            <p class="text-muted">Fee records are being generated. Check back shortly.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr><th>Instalment</th><th>Due</th><th>Paid</th><th>Reference</th><th>Proof</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($fees as $f): ?>
                <tr>
                    <td class="font-weight-bold"><?= h($f['instalment_label']) ?></td>
                    <td>$<?= number_format($f['due_amount'],2) ?></td>
                    <td>$<?= number_format($f['paid_amount'],2) ?></td>
                    <td><?= h($f['payment_ref'] ?? '—') ?></td>
                    <td><?= $f['proof_path'] ? '<a href="'.h($f['proof_path']).'" target="_blank">View</a>' : '—' ?></td>
                    <td><span class="badge badge-<?= pcm_badge($f['status']) ?>"><?= h($f['status']) ?></span>
                        <?php if ($f['status'] === 'Rejected' && $f['reject_reason']): ?>
                            <br><small class="text-danger"><?= h($f['reject_reason']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (in_array($f['status'], ['Unpaid','Rejected'])): ?>
                        <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#uploadModal<?= $f['id'] ?>">
                            <i class="fas fa-upload mr-1"></i>Pay
                        </button>

                        <!-- Upload Modal -->
                        <div class="modal fade" id="uploadModal<?= $f['id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST" enctype="multipart/form-data">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="upload_proof">
                                    <input type="hidden" name="fee_id" value="<?= $f['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-upload mr-2" style="color:var(--brand);"></i>Upload Proof — <?= h($f['instalment_label']) ?></h5>
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="info-box">
                                            <i class="fas fa-dollar-sign"></i>
                                            Amount due: <strong>$<?= number_format($f['due_amount'],2) ?></strong>
                                        </div>
                                        <div class="form-group">
                                            <label><i class="fas fa-hashtag mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Payment Reference</label>
                                            <input type="text" name="payment_ref" class="form-control" maxlength="150" placeholder="Bank transfer reference number">
                                        </div>
                                        <div class="form-group">
                                            <label><i class="fas fa-file-upload mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Proof File <span class="text-danger">*</span></label>
                                            <input type="file" name="proof" class="form-control-file" accept=".jpg,.jpeg,.png,.pdf" required>
                                            <small class="text-muted">JPG / PNG / PDF — max 5 MB</small>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i>Submit Payment</button>
                                    </div>
                                </form>
                            </div></div>
                        </div>
                        <?php elseif ($f['status'] === 'Pending'): ?>
                            <span class="text-muted small">Awaiting review</span>
                        <?php else: ?>
                            <span class="text-success small"><i class="fas fa-check"></i></span>
                        <?php endif; ?>
                    </td>
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
</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>
</body>
</html>
