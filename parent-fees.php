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
$campusChoices = pcm_campus_choice_labels();
[$campusOneName, $campusTwoName] = pcm_campus_names();
pcm_ensure_enrolment_campus_preference($pdo);
$parent   = pcm_current_parent($pdo);
if (!$parent) die("Parent account not found.");
$parentId = (int)$parent['id'];
$flash    = '';
$ok       = false;

// ── Bank details from fees_settings (single source of truth) ──
$_fs = $pdo->query("SELECT bank_name, account_name, bsb, account_number, bank_notes FROM fees_settings WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$banks = (!empty($_fs['bank_name'])) ? [[
    'bank_name'      => $_fs['bank_name'],
    'account_name'   => $_fs['account_name'],
    'bsb'            => $_fs['bsb'],
    'account_number' => $_fs['account_number'],
    'reference_hint' => $_fs['bank_notes'],
]] : [];

// ── POST: upload proof for a specific instalment ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    verify_csrf();
    $feeId = (int)($_POST['fee_id'] ?? 0);
    $ref   = trim($_POST['payment_ref'] ?? '');
    $confirmPaid = (int)($_POST['confirm_paid'] ?? 0);
    $campusSelection = $_POST['campus_choice'] ?? [];
    if (!is_array($campusSelection)) {
        $campusSelection = [];
    }
    $campusSelection = array_values(array_unique(array_filter(array_map('strval', $campusSelection))));
    $allowedCampusChoices = array_keys($campusChoices);

    // Verify ownership
    $row = $pdo->prepare("SELECT * FROM pcm_fee_payments WHERE id=:id AND parent_id=:pid LIMIT 1");
    $row->execute([':id'=>$feeId, ':pid'=>$parentId]);
    $fee = $row->fetch();

    if (!$fee) {
        $flash = 'Payment record not found.';
    } elseif (empty($campusSelection)) {
        $flash = 'Please select at least one campus.';
    } elseif (array_diff($campusSelection, $allowedCampusChoices)) {
        $flash = 'Please select valid campus choices.';
    } elseif ($confirmPaid !== 1) {
        $flash = 'Please confirm that you paid the amount based on your selected payment plan.';
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
                $campusStored = implode(',', $campusSelection);
                $updCampus = $pdo->prepare("
                    UPDATE pcm_enrolments
                    SET campus_preference = :campus
                    WHERE id = :eid AND parent_id = :pid
                    LIMIT 1
                ");
                $updCampus->execute([
                    ':campus' => $campusStored,
                    ':eid' => (int)($fee['enrolment_id'] ?? 0),
                    ':pid' => $parentId
                ]);

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
    SELECT e.id AS eid, e.fee_plan, e.campus_preference, s.student_name, s.student_id AS stu_code,
           c.class_name AS campus_name
    FROM pcm_enrolments e
    JOIN students s ON s.id = e.student_id
    LEFT JOIN class_assignments ca ON ca.student_id = e.student_id
    LEFT JOIN classes c ON c.id = ca.class_id
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
                    <tr><th>Campus</th><th>Plan</th><th>Preference</th><th>Instalment</th><th>Due</th><th>Paid</th><th>Reference</th><th>Proof</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($fees as $f): ?>
                <tr>
                    <td><?= h($en['campus_name'] ?? 'Main Campus') ?></td>
                    <td><?= h($en['fee_plan']) ?></td>
                    <td><?= h(pcm_campus_selection_label((string)($en['campus_preference'] ?? ''))) ?></td>
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
                        <?php
                            $planShort = strtolower(trim((string)$en['fee_plan'])) === 'half-yearly' ? 'hy' : (strtolower(trim((string)$en['fee_plan'])) === 'yearly' ? 'y' : 'tw');
                            $nameCompact = preg_replace('/[^A-Za-z0-9]/', '', (string)$en['student_name']);
                            $suggestRef = $nameCompact . '_' . $planShort;
                        ?>
                        <button class="btn btn-primary btn-sm"
                                data-toggle="modal"
                                data-target="#uploadModal<?= $f['id'] ?>"
                                data-ref="<?= h($suggestRef) ?>"
                                data-due="<?= number_format((float)$f['due_amount'], 2, '.', '') ?>"
                                data-plan="<?= h($en['fee_plan']) ?>"
                                data-campus="<?= h($en['campus_name'] ?? 'Main Campus') ?>">
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
                                            <label><i class="fas fa-map-marker-alt mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Campus</label>
                                            <input type="text" class="form-control" value="<?= h($en['campus_name'] ?? 'Main Campus') ?>" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label><i class="fas fa-school mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Campus Preference (select one or both)</label>
                                            <?php $selectedCampus = pcm_normalize_campus_selection((string)($en['campus_preference'] ?? '')); ?>
                                            <div class="custom-control custom-checkbox mb-1">
                                                <input type="checkbox" class="custom-control-input" id="campusC1<?= $f['id'] ?>" name="campus_choice[]" value="c1" <?= in_array('c1', $selectedCampus, true) ? 'checked' : '' ?>>
                                                <label class="custom-control-label" for="campusC1<?= $f['id'] ?>"><?= h($campusOneName) ?></label>
                                            </div>
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="campusC2<?= $f['id'] ?>" name="campus_choice[]" value="c2" <?= in_array('c2', $selectedCampus, true) ? 'checked' : '' ?>>
                                                <label class="custom-control-label" for="campusC2<?= $f['id'] ?>"><?= h($campusTwoName) ?></label>
                                            </div>
                                            <small class="text-muted">You can choose one campus or both campuses.</small>
                                        </div>
                                        <div class="form-group">
                                            <label><i class="fas fa-tags mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Fee Plan</label>
                                            <input type="text" class="form-control" value="<?= h($en['fee_plan']) ?>" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label><i class="fas fa-hashtag mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Payment Reference</label>
                                            <div class="input-group">
                                                <input type="text" name="payment_ref" class="form-control ref-auto-field" maxlength="150" value="<?= h($suggestRef) ?>" placeholder="Bank transfer reference number">
                                                <div class="input-group-append">
                                                    <button type="button" class="btn btn-outline-secondary btn-copy-ref">Copy</button>
                                                </div>
                                            </div>
                                            <small class="text-muted">Suggested format: ChildName + plan code (e.g. TenzinWangmo_hy or TenzinWangmo_y)</small>
                                        </div>
                                        <div class="form-group">
                                            <label><i class="fas fa-file-upload mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Proof File <span class="text-danger">*</span></label>
                                            <input type="file" name="proof" class="form-control-file" accept=".jpg,.jpeg,.png,.pdf" required>
                                            <small class="text-muted">JPG / PNG / PDF — max 5 MB</small>
                                        </div>
                                        <div class="form-group mb-0">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="confirmPaid<?= $f['id'] ?>" name="confirm_paid" value="1" required>
                                                <label class="custom-control-label" for="confirmPaid<?= $f['id'] ?>">
                                                    I confirm I have paid <strong>$<?= number_format($f['due_amount'],2) ?></strong> based on this payment plan.
                                                </label>
                                            </div>
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
<script>
document.addEventListener('click', function(e){
    if (e.target.classList.contains('btn-copy-ref')) {
        const modal = e.target.closest('.modal-content');
        const input = modal ? modal.querySelector('.ref-auto-field') : null;
        if (!input) return;
        navigator.clipboard.writeText(input.value || '').then(() => {
            Swal.fire({icon:'success', title:'Reference copied', timer:900, showConfirmButton:false});
        });
    }
});
</script>
</body>
</html>
