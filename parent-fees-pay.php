<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_login();

if (!is_parent_role()) { header("Location: unauthorized"); exit; }

$pdo = pcm_pdo();
pcm_ensure_enrolment_campus_preference($pdo);
$parent = pcm_current_parent($pdo);
if (!$parent) die("Parent account not found.");
$parentId = (int)$parent['id'];

$feeId = (int)($_GET['fee_id'] ?? $_POST['fee_id'] ?? 0);
if ($feeId <= 0) {
    header("Location: parent-fees");
    exit;
}

function pf_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$_fs = $pdo->query("SELECT bank_name, account_name, bsb, account_number, bank_notes FROM fees_settings WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$hasBankCore =
    !empty(trim((string)($_fs['account_name'] ?? ''))) ||
    !empty(trim((string)($_fs['bsb'] ?? ''))) ||
    !empty(trim((string)($_fs['account_number'] ?? '')));
$bank = $hasBankCore ? [
    'bank_name'      => trim((string)($_fs['bank_name'] ?? '')) !== '' ? $_fs['bank_name'] : 'Bank Transfer',
    'account_name'   => (string)($_fs['account_name'] ?? ''),
    'bsb'            => (string)($_fs['bsb'] ?? ''),
    'account_number' => (string)($_fs['account_number'] ?? ''),
    'reference_hint' => (string)($_fs['bank_notes'] ?? ''),
] : null;

$stmt = $pdo->prepare("
    SELECT
        f.*,
        e.fee_plan,
        e.campus_preference,
        e.id AS enrolment_id,
        s.student_name,
        s.student_id AS student_code,
        c.class_name AS campus_name
    FROM pcm_fee_payments f
    INNER JOIN pcm_enrolments e ON e.id = f.enrolment_id
    INNER JOIN students s ON s.id = f.student_id
    LEFT JOIN class_assignments ca ON ca.student_id = s.id
    LEFT JOIN classes c ON c.id = ca.class_id
    WHERE f.id = :fid
      AND f.parent_id = :pid
    LIMIT 1
");
$stmt->execute([':fid' => $feeId, ':pid' => $parentId]);
$fee = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$fee) {
    header("Location: parent-fees");
    exit;
}

$status = (string)($fee['status'] ?? '');
if (!in_array($status, ['Unpaid', 'Rejected'], true)) {
    header("Location: parent-fees");
    exit;
}

$plan = trim((string)($fee['fee_plan'] ?? 'Term-wise'));
$planShort = strtolower($plan) === 'half-yearly' ? 'hy' : (strtolower($plan) === 'yearly' ? 'y' : 'tw');
$nameCompact = preg_replace('/[^A-Za-z0-9]/', '', (string)($fee['student_name'] ?? 'Student'));
$suggestRef = $nameCompact . '_' . $planShort;

$flash = '';
$ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_payment') {
    verify_csrf();

    $ref = trim((string)($_POST['payment_ref'] ?? ''));
    $confirmPaid = (int)($_POST['confirm_paid'] ?? 0);

    if ($confirmPaid !== 1) {
        $flash = 'Please confirm that you have paid this instalment.';
    } elseif (empty($_FILES['proof']['name']) || (int)($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flash = 'Please upload a valid proof file.';
    } else {
        $allowed = ['jpg','jpeg','png','pdf'];
        $ext = strtolower(pathinfo((string)$_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $flash = 'File must be JPG, PNG, or PDF.';
        } elseif ((int)($_FILES['proof']['size'] ?? 0) > 5 * 1024 * 1024) {
            $flash = 'File must be under 5 MB.';
        } else {
            $dir = 'uploads/fees';
            pcm_ensure_dir($dir);
            $filename = 'fee_' . $feeId . '_' . time() . '.' . $ext;
            $path = $dir . '/' . $filename;
            if (!move_uploaded_file($_FILES['proof']['tmp_name'], $path)) {
                $flash = 'Upload failed. Please try again.';
            } else {
                $upd = $pdo->prepare("
                    UPDATE pcm_fee_payments
                    SET proof_path=:p, payment_ref=:ref, paid_amount=due_amount,
                        status='Pending', submitted_at=NOW(), reject_reason=NULL
                    WHERE id=:id AND parent_id=:pid
                    LIMIT 1
                ");
                $upd->execute([
                    ':p' => $path,
                    ':ref' => ($ref === '' ? null : $ref),
                    ':id' => $feeId,
                    ':pid' => $parentId
                ]);

                $ok = true;
                $flash = 'Payment proof uploaded. Awaiting admin verification.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Submit Fee Payment</title>
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
                <?php if ($flash !== ''): ?>
                    <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        Swal.fire({
                            icon: <?= json_encode($ok ? 'success' : 'error') ?>,
                            title: <?= json_encode($flash) ?>,
                            timer: <?= $ok ? '1500' : '0' ?>,
                            showConfirmButton: <?= $ok ? 'false' : 'true' ?>
                        }).then(function () {
                            <?php if ($ok): ?>
                            window.location = 'parent-fees';
                            <?php endif; ?>
                        });
                    });
                    </script>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h4 mb-0">Submit Payment Proof</h1>
                    <a href="parent-fees" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i>Back</a>
                </div>

                <div class="card shadow">
                    <div class="card-body">
                        <div class="mb-3 p-2 rounded bg-light">
                            <div><strong>Student:</strong> <?= pf_h((string)$fee['student_name']) ?> (<?= pf_h((string)$fee['student_code']) ?>)</div>
                            <div><strong>Instalment:</strong> <?= pf_h((string)$fee['instalment_label']) ?></div>
                            <div><strong>Amount Due:</strong> $<?= number_format((float)$fee['due_amount'], 2) ?></div>
                            <div><strong>Plan:</strong> <?= pf_h($plan) ?></div>
                            <div><strong>Campus:</strong> <?= pf_h((string)($fee['campus_name'] ?? 'Main Campus')) ?></div>
                        </div>

                        <form method="POST" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="submit_payment">
                            <input type="hidden" name="fee_id" value="<?= (int)$feeId ?>">

                            <div class="form-group">
                                <label><i class="fas fa-university mr-1"></i>Payment Bank Details</label>
                                <?php if ($bank): ?>
                                    <div class="border rounded p-2 bg-light">
                                        <div class="mb-2"><strong><?= pf_h((string)$bank['bank_name']) ?></strong></div>
                                        <div class="small mb-1"><strong>Account Name:</strong> <?= pf_h((string)$bank['account_name']) ?> <button type="button" class="btn btn-link btn-sm p-0 ml-1 btn-copy-bank" data-copy="<?= pf_h((string)$bank['account_name']) ?>">Copy</button></div>
                                        <div class="small mb-1"><strong>BSB:</strong> <?= pf_h((string)$bank['bsb']) ?> <button type="button" class="btn btn-link btn-sm p-0 ml-1 btn-copy-bank" data-copy="<?= pf_h((string)$bank['bsb']) ?>">Copy</button></div>
                                        <div class="small mb-0"><strong>Account #:</strong> <?= pf_h((string)$bank['account_number']) ?> <button type="button" class="btn btn-link btn-sm p-0 ml-1 btn-copy-bank" data-copy="<?= pf_h((string)$bank['account_number']) ?>">Copy</button></div>
                                        <?php if (!empty($bank['reference_hint'])): ?>
                                            <div class="small text-muted mt-2"><i class="fas fa-info-circle mr-1"></i><?= pf_h((string)$bank['reference_hint']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0 py-2">Bank details are not configured yet. Please contact admin.</div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label>Payment Reference</label>
                                <div class="input-group">
                                    <input type="text" name="payment_ref" class="form-control ref-auto-field" maxlength="150" value="<?= pf_h($suggestRef) ?>" required>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-secondary btn-copy-ref">Copy</button>
                                    </div>
                                </div>
                                <small class="text-muted">Suggested format: ChildName + plan code (e.g. TenzinWangmo_hy or TenzinWangmo_y)</small>
                            </div>

                            <div class="form-group">
                                <label>Proof File <span class="text-danger">*</span></label>
                                <input type="file" name="proof" class="form-control-file" accept=".jpg,.jpeg,.png,.pdf" required>
                                <small class="text-muted">JPG / PNG / PDF — max 5 MB</small>
                            </div>

                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="confirmPaid" name="confirm_paid" value="1" required>
                                    <label class="custom-control-label" for="confirmPaid">
                                        I confirm I have paid <strong>$<?= number_format((float)$fee['due_amount'],2) ?></strong> for this instalment.
                                    </label>
                                </div>
                            </div>

                            <div class="d-flex">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i>Submit Payment</button>
                                <a href="parent-fees" class="btn btn-light ml-2">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>
<script>
document.addEventListener('click', function(e){
    if (e.target.classList.contains('btn-copy-ref')) {
        var input = document.querySelector('.ref-auto-field');
        if (!input) return;
        navigator.clipboard.writeText(input.value || '').then(function () {
            Swal.fire({icon:'success', title:'Reference copied', timer:900, showConfirmButton:false});
        });
    }
    if (e.target.classList.contains('btn-copy-bank')) {
        var text = e.target.getAttribute('data-copy') || '';
        if (!text) return;
        navigator.clipboard.writeText(text).then(function () {
            Swal.fire({icon:'success', title:'Copied', timer:900, showConfirmButton:false});
        });
    }
});
</script>
</body>
</html>
