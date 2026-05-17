<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$message = "";
$success = false;
$reload = false;

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

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mp_badge_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'verified') return 'success';
    if ($s === 'rejected') return 'danger';
    if ($s === 'pending') return 'warning';
    return 'secondary';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_mark_paid') {
    verify_csrf();
    try {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $paidAmount = (float)($_POST['paid_amount'] ?? 0);
        $paymentRef = trim((string)($_POST['payment_ref'] ?? ''));

        if ($paymentId <= 0) {
            throw new Exception('Invalid payment row.');
        }
        if ($paidAmount < 0) {
            throw new Exception('Paid amount cannot be negative.');
        }

        $rowStmt = $pdo->prepare("SELECT due_amount FROM pcm_fee_payments WHERE id=:id LIMIT 1");
        $rowStmt->execute([':id' => $paymentId]);
        $row = $rowStmt->fetch();
        if (!$row) {
            throw new Exception('Payment row not found.');
        }

        $dueAmount = (float)($row['due_amount'] ?? 0);
        if ($paidAmount <= 0) {
            $paidAmount = $dueAmount;
        }

        $reviewer = (string)($_SESSION['username'] ?? 'admin');
        $upd = $pdo->prepare("\n            UPDATE pcm_fee_payments\n            SET paid_amount = :paid,\n                payment_ref = :ref,\n                status = 'Verified',\n                submitted_at = COALESCE(submitted_at, NOW()),\n                verified_by = :by,\n                verified_at = NOW(),\n                reject_reason = NULL\n            WHERE id = :id\n        ");
        $upd->execute([
            ':paid' => $paidAmount,
            ':ref' => ($paymentRef !== '' ? $paymentRef : null),
            ':by' => $reviewer,
            ':id' => $paymentId,
        ]);

        $message = 'Manual payment saved successfully.';
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

$rowsStmt = $pdo->query("\n    SELECT f.id, f.plan_type, f.instalment_label, f.due_amount, f.paid_amount, f.payment_ref, f.status, f.submitted_at,\n           s.id AS student_db_id, s.student_name, s.student_id AS stu_code,\n           p.full_name AS parent_name\n    FROM pcm_fee_payments f\n    INNER JOIN students s ON s.id = f.student_id\n    LEFT JOIN parents p ON p.id = f.parent_id\n    ORDER BY s.student_name ASC, f.id ASC\n");
$payments = $rowsStmt->fetchAll();

$grouped = [];
foreach ($payments as $r) {
    $sid = (int)($r['student_db_id'] ?? 0);
    if ($sid <= 0) continue;
    if (!isset($grouped[$sid])) {
        $grouped[$sid] = [
            'student_name' => (string)($r['student_name'] ?? ''),
            'stu_code' => (string)($r['stu_code'] ?? ''),
            'parent_name' => (string)($r['parent_name'] ?? ''),
            'Term-wise' => [],
            'Half-yearly' => [],
            'Yearly' => [],
            'Additional' => [],
        ];
    }
    $plan = (string)($r['plan_type'] ?? '');
    if (!isset($grouped[$sid][$plan])) {
        $grouped[$sid][$plan] = [];
    }
    $grouped[$sid][$plan][] = $r;
}

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
    <title>Manual Payments</title>
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

<?php if ($message): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({icon:'<?= $success ? "success" : "error" ?>',text:<?= json_encode($message) ?>,timer:2200,showConfirmButton:false})
    <?= $reload ? ".then(()=>window.location='manual-payments.php')" : "" ?>;
});
</script>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-hand-holding-usd mr-1"></i>Manual Payments</h6>
        <small class="text-muted">Child + parent with all plans and additional payments</small>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <button type="button" class="btn btn-sm btn-primary plan-filter-btn active" data-plan="all">All</button>
            <button type="button" class="btn btn-sm btn-outline-primary plan-filter-btn" data-plan="Term-wise">Term-wise</button>
            <button type="button" class="btn btn-sm btn-outline-info plan-filter-btn" data-plan="Half-yearly">Half-yearly</button>
            <button type="button" class="btn btn-sm btn-outline-success plan-filter-btn" data-plan="Yearly">Yearly</button>
            <button type="button" class="btn btn-sm btn-outline-dark plan-filter-btn" data-plan="Additional">Additional</button>
        </div>
        <div class="table-responsive">
            <table id="manualPaymentsTable" class="table table-bordered table-hover" style="width:100%">
                <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>Child</th>
                        <th>Parent</th>
                        <th>Term-wise</th>
                        <th>Half-yearly</th>
                        <th>Yearly</th>
                        <th>Additional Payments</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($grouped)): ?>
                    <tr><td colspan="7" class="text-muted">No payment rows found.</td></tr>
                <?php else: $i = 0; foreach ($grouped as $row): $i++; ?>
                    <tr>
                        <td><?= $i ?></td>
                        <td><?= h($row['student_name']) ?> <small class="text-muted">(<?= h($row['stu_code']) ?>)</small></td>
                        <td><?= h($row['parent_name'] ?: '-') ?></td>
                        <?php foreach (['Term-wise','Half-yearly','Yearly','Additional'] as $planCol): ?>
                            <td>
                                <?php $items = $row[$planCol] ?? []; ?>
                                <?php if (empty($items)): ?>
                                    <span class="text-muted small">-</span>
                                <?php else: foreach ($items as $idx => $item): ?>
                                    <?php
                                        $paidVal = (float)($item['paid_amount'] ?? 0);
                                        $isPaid = $paidVal > 0 || strtolower((string)($item['status'] ?? '')) === 'verified';
                                        $btnClass = $isPaid ? 'btn-primary' : 'btn-success';
                                        $btnText = $isPaid ? 'Update Payment' : 'Make Payment';
                                        $modalId = 'updatePaymentModal' . (int)$item['id'];
                                    ?>
                                    <form method="POST" class="d-inline-flex align-items-center mb-1" style="gap:4px;white-space:nowrap;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="manual_mark_paid">
                                        <input type="hidden" name="payment_id" value="<?= (int)$item['id'] ?>">
                                        <span class="small font-weight-bold"><?= h($item['instalment_label']) ?></span>
                                        <span class="small">$<?= number_format((float)$item['due_amount'], 2) ?>/<?= number_format((float)$item['paid_amount'], 2) ?></span>
                                        <span class="badge badge-<?= h(mp_badge_class((string)$item['status'])) ?>"><?= h($item['status']) ?></span>
                                        <input type="number" min="0" step="0.01" name="paid_amount" class="form-control form-control-sm" style="width:78px;" value="<?= h((string)$item['paid_amount']) ?>" title="Paid amount" <?= $isPaid ? 'readonly' : '' ?>>
                                        <input type="text" name="payment_ref" class="form-control form-control-sm" style="width:92px;" value="<?= h((string)($item['payment_ref'] ?? '')) ?>" placeholder="Ref" <?= $isPaid ? 'readonly' : '' ?>>
                                        <?php if ($isPaid): ?>
                                            <button class="btn btn-sm <?= h($btnClass) ?>" type="button" data-toggle="modal" data-target="#<?= h($modalId) ?>">Update Payment</button>
                                        <?php else: ?>
                                            <button class="btn btn-sm <?= h($btnClass) ?>" type="submit"><?= h($btnText) ?></button>
                                        <?php endif; ?>
                                    </form><?= $idx < count($items)-1 ? '<span class="mx-1 text-muted">|</span>' : '' ?>
                                    <?php if ($isPaid): ?>
                                        <div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1" role="dialog" aria-hidden="true">
                                            <div class="modal-dialog" role="document">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="manual_mark_paid">
                                                        <input type="hidden" name="payment_id" value="<?= (int)$item['id'] ?>">
                                                        <div class="modal-header bg-primary text-white">
                                                            <h5 class="modal-title">Update Payment</h5>
                                                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                                                <span aria-hidden="true">&times;</span>
                                                            </button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="small mb-2"><strong><?= h($row['student_name']) ?></strong> - <?= h($item['instalment_label']) ?></div>
                                                            <div class="form-group">
                                                                <label>Paid Amount</label>
                                                                <input type="number" min="0" step="0.01" name="paid_amount" class="form-control" value="<?= h((string)$item['paid_amount']) ?>" required>
                                                            </div>
                                                            <div class="form-group mb-0">
                                                                <label>Reference</label>
                                                                <input type="text" name="payment_ref" class="form-control" value="<?= h((string)($item['payment_ref'] ?? '')) ?>" placeholder="Payment reference">
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Save Update</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; endif; ?>
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
    var dt = $('#manualPaymentsTable').DataTable({
        pageLength: 25,
        order: [[0,'asc']]
    });

    function showPlanColumns(plan) {
        var planCols = { 'Term-wise': 3, 'Half-yearly': 4, 'Yearly': 5, 'Additional': 6 };
        if (plan === 'all') {
            dt.column(3).visible(true, false);
            dt.column(4).visible(true, false);
            dt.column(5).visible(true, false);
            dt.column(6).visible(true, false);
        } else {
            dt.column(3).visible(false, false);
            dt.column(4).visible(false, false);
            dt.column(5).visible(false, false);
            dt.column(6).visible(false, false);
            if (planCols[plan] !== undefined) {
                dt.column(planCols[plan]).visible(true, false);
            }
        }
        dt.columns.adjust().draw(false);
    }

    $('.plan-filter-btn').on('click', function () {
        $('.plan-filter-btn').removeClass('active');
        $(this).addClass('active');
        showPlanColumns(($(this).data('plan') || 'all').toString());
    });

});
</script>
</body>
</html>
