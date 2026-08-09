<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/pcm_helpers.php";
require_once "include/csrf.php";
require_once "include/fee_audit.php";
require_once "include/notifications.php";
require_once "include/mailer.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') {
    header("Location: index-admin");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$message = "";
$success = false;
$reload  = false;

// ---------------- DB CONNECTION ----------------
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    bbcc_fail_db($e);
}

// ---------------- LOAD FEES SETTINGS ----------------
$stmtSet = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
$feesSettings = $stmtSet->fetch() ?: [];

// ---------------- HELPERS ----------------
// h() comes from include/pcm_helpers.php
// pcm_ensure_class_charge_schema() / pcm_apply_class_charge() come from include/pcm_helpers.php
// pcm_badge() comes from include/pcm_helpers.php (status -> Bootstrap badge class)

function proof_type(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return ($ext === 'pdf') ? 'pdf' : 'img';
}

function pretty_date(?string $d): string {
    return $d ? htmlspecialchars($d) : '-';
}

pcm_ensure_class_charge_schema($pdo);
$hasStartTerm = $pdo->query("SHOW COLUMNS FROM pcm_enrolments LIKE 'start_term'")->fetch(PDO::FETCH_ASSOC);
if (!$hasStartTerm) {
    $pdo->exec("ALTER TABLE pcm_enrolments ADD COLUMN start_term TINYINT NOT NULL DEFAULT 1");
}

// ---------------- CLASS-BASED ADDITIONAL CHARGES ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['class_charge_action'])) {
    try {
        $act = trim((string)($_POST['class_charge_action'] ?? ''));
        if ($act === 'add') {
            $classId = (int)($_POST['charge_class_id'] ?? 0);
            $title = trim((string)($_POST['charge_title'] ?? ''));
            $amount = (float)($_POST['charge_amount'] ?? 0);
            $dueDate = trim((string)($_POST['charge_due_date'] ?? ''));
            $desc = trim((string)($_POST['charge_description'] ?? ''));

            if ($classId <= 0) throw new Exception("Please select a class.");
            if ($title === '') throw new Exception("Charge name is required.");
            if ($amount <= 0) throw new Exception("Amount must be greater than zero.");
            $dueDate = $dueDate === '' ? null : $dueDate;

            $dup = $pdo->prepare("
                SELECT id
                FROM pcm_class_fee_charges
                WHERE class_id = :cid
                  AND LOWER(charge_title) = LOWER(:title)
                LIMIT 1
            ");
            $dup->execute([':cid' => $classId, ':title' => $title]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("A charge with this name already exists for this class.");
            }

            $pdo->beginTransaction();
            $insCharge = $pdo->prepare("
                INSERT INTO pcm_class_fee_charges
                    (class_id, charge_title, amount, description, due_date, is_active, created_by)
                VALUES
                    (:cid, :title, :amount, :descr, :due_date, 1, :by)
            ");
            $insCharge->execute([
                ':cid' => $classId,
                ':title' => $title,
                ':amount' => $amount,
                ':descr' => ($desc === '' ? null : $desc),
                ':due_date' => $dueDate,
                ':by' => (string)($_SESSION['username'] ?? 'admin'),
            ]);
            $newChargeId = (int)$pdo->lastInsertId();
            $applied = pcm_apply_class_charge($pdo, $newChargeId);
            $pdo->commit();

            $message = "New class charge created and applied to {$applied} student(s).";
            $success = true;
            $reload = true;
        } elseif ($act === 'apply') {
            $chargeId = (int)($_POST['charge_id'] ?? 0);
            if ($chargeId <= 0) throw new Exception("Invalid charge.");
            $applied = pcm_apply_class_charge($pdo, $chargeId);
            $message = "Charge applied to {$applied} missing student(s).";
            $success = true;
            $reload = true;
        } elseif ($act === 'toggle') {
            $chargeId = (int)($_POST['charge_id'] ?? 0);
            if ($chargeId <= 0) throw new Exception("Invalid charge.");
            $pdo->prepare("UPDATE pcm_class_fee_charges SET is_active = IF(is_active=1,0,1) WHERE id=:id")->execute([':id' => $chargeId]);
            $message = "Charge status updated.";
            $success = true;
            $reload = true;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- QUICK STATUS CHANGE ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fee_id'])) {
    try {
        $feeId = (int)($_POST['update_fee_id'] ?? 0);
        $newStatus = trim((string)($_POST['new_status'] ?? ''));
        $reviewer = (string)($_SESSION['username'] ?? 'admin');

        $result = pcm_admin_update_fee_status($pdo, $feeId, $newStatus, $reviewer);
        $message = $result['flash'];
        $success = true;
        $reload  = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload  = false;
    }
}

// ---------------- EDIT A FEE ROW (due/paid/ref/status) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_payment_row') {
    try {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $reviewer = (string)($_SESSION['username'] ?? 'admin');

        $result = pcm_admin_update_fee_row($pdo, $pid, $_POST, $reviewer);
        $message = $result['flash'];
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- BULK STATUS UPDATE ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_update_payments') {
    try {
        $ids = $_POST['payment_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $st = trim((string)($_POST['bulk_status'] ?? ''));
        $setPaidToDue = isset($_POST['set_paid_to_due']) && (string)$_POST['set_paid_to_due'] === '1';
        $allowed = ['Unpaid','Pending','Verified','Rejected'];

        if (empty($ids)) throw new Exception("Please select at least one payment row.");
        if (!in_array($st, $allowed, true)) throw new Exception("Please choose a valid status.");

        $reviewer = (string)($_SESSION['username'] ?? 'admin');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $beforeRows = [];
        foreach ($ids as $id) {
            $beforeRows[$id] = bbcc_fee_payment_snapshot($pdo, $id);
        }

        if (in_array($st, ['Verified', 'Rejected'], true)) {
            $sql = "
                UPDATE pcm_fee_payments
                SET status = ?,
                    paid_amount = CASE WHEN ? = 1 THEN due_amount ELSE paid_amount END,
                    verified_by = ?,
                    verified_at = NOW()
                WHERE id IN ({$in})
            ";
            $params = array_merge([$st, $setPaidToDue ? 1 : 0, $reviewer], $ids);
        } else {
            $sql = "
                UPDATE pcm_fee_payments
                SET status = ?,
                    paid_amount = CASE WHEN ? = 1 THEN due_amount ELSE paid_amount END,
                    verified_by = NULL,
                    verified_at = NULL
                WHERE id IN ({$in})
            ";
            $params = array_merge([$st, $setPaidToDue ? 1 : 0], $ids);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($ids as $id) {
            bbcc_audit_fee_payment_change(
                $pdo,
                $id,
                'bulk_status_update',
                $beforeRows[$id] ?? null,
                bbcc_fee_payment_snapshot($pdo, $id)
            );
        }

        $message = count($ids) . " payment row(s) updated successfully.";
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- MARK PAID (record a manual/cash payment) — admin-tier only ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_mark_paid') {
    try {
        if (!is_admin_role()) {
            throw new Exception('You do not have permission to record manual payments.');
        }

        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $paidAmount = (float)($_POST['paid_amount'] ?? 0);
        $paymentRef = trim((string)($_POST['payment_ref'] ?? ''));

        if ($paymentId <= 0) {
            throw new Exception('Invalid payment row.');
        }
        if ($paidAmount < 0) {
            throw new Exception('Paid amount cannot be negative.');
        }

        $rowStmt = $pdo->prepare("SELECT due_amount FROM pcm_fee_payments WHERE id = :id LIMIT 1");
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
        $before = bbcc_fee_payment_snapshot($pdo, $paymentId);
        $upd = $pdo->prepare("
            UPDATE pcm_fee_payments
            SET paid_amount = :paid,
                payment_ref = :ref,
                status = 'Verified',
                submitted_at = COALESCE(submitted_at, NOW()),
                verified_by = :by,
                verified_at = NOW(),
                reject_reason = NULL
            WHERE id = :id
        ");
        $upd->execute([
            ':paid' => $paidAmount,
            ':ref' => ($paymentRef !== '' ? $paymentRef : null),
            ':by' => $reviewer,
            ':id' => $paymentId,
        ]);
        $after = bbcc_fee_payment_snapshot($pdo, $paymentId);
        bbcc_audit_fee_payment_change($pdo, $paymentId, 'manual_mark_paid', $before, $after);

        $message = 'Manual payment saved successfully.';
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- VERIFY / REJECT a pending proof — admin-only ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verify', 'reject'], true)) {
    try {
        if (!is_admin_role()) {
            throw new Exception('You do not have permission to verify or reject payments.');
        }

        $fid    = (int)($_POST['fee_id'] ?? 0);
        $action = (string)$_POST['action'];
        $reason = trim((string)($_POST['reject_reason'] ?? ''));

        $row = $pdo->prepare("
            SELECT f.*, s.student_name, p.full_name AS parent_name, p.email AS parent_email
            FROM pcm_fee_payments f
            JOIN students s ON s.id = f.student_id
            JOIN parents  p ON p.id = f.parent_id
            WHERE f.id = :id LIMIT 1
        ");
        $row->execute([':id' => $fid]);
        $fee = $row->fetch();

        if (!$fee) {
            throw new Exception('Record not found.');
        }
        if ($fee['status'] !== 'Pending') {
            throw new Exception('This payment is not awaiting review.');
        }
        if ($action === 'reject' && $reason === '') {
            throw new Exception('A rejection reason is required.');
        }

        $newStatus = ($action === 'verify') ? 'Verified' : 'Rejected';
        $reviewer  = (string)($_SESSION['username'] ?? 'admin');

        $before = bbcc_fee_payment_snapshot($pdo, $fid);
        $upd = $pdo->prepare("
            UPDATE pcm_fee_payments
            SET status=:st, verified_by=:vb, verified_at=NOW(),
                reject_reason = CASE WHEN :st2='Rejected' THEN :rr ELSE NULL END,
                paid_amount   = CASE WHEN :st3='Verified' THEN due_amount ELSE 0 END
            WHERE id=:id
        ");
        $upd->execute([':st'=>$newStatus, ':vb'=>$reviewer, ':st2'=>$newStatus, ':rr'=>$reason?:null, ':st3'=>$newStatus, ':id'=>$fid]);
        $after = bbcc_fee_payment_snapshot($pdo, $fid);
        bbcc_audit_fee_payment_change($pdo, $fid, strtolower($newStatus), $before, $after, $reason);

        pcm_notify_parent_fee($fee['parent_email'], $fee['parent_name'], $fee['student_name'], $fee['instalment_label'], $newStatus);
        bbcc_notify_username(
            $pdo,
            (string)$fee['parent_email'],
            'Fee Payment ' . $newStatus . ' for ' . (string)$fee['student_name'],
            'Your payment proof for ' . (string)$fee['instalment_label'] . ' is now marked as ' . $newStatus . '.',
            'parent-payments'
        );

        $message = "Payment {$newStatus} — {$fee['student_name']} ({$fee['instalment_label']}).";
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- SEND CUSTOM EMAIL TO PARENT — admin-only ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_custom_email') {
    try {
        if (!is_admin_role()) {
            throw new Exception('You do not have permission to email parents from this page.');
        }

        $fid = (int)($_POST['fee_id'] ?? 0);
        $subjectTpl = trim((string)($_POST['email_subject'] ?? 'Fee Update for {child_name}'));
        $bodyTpl = trim((string)($_POST['email_body'] ?? 'Dear {parent_name},'));

        if ($fid <= 0) throw new Exception('Invalid fee record.');
        if ($subjectTpl === '' || $bodyTpl === '') throw new Exception('Subject and message are required.');

        $row = $pdo->prepare("
            SELECT f.*, s.student_name, p.full_name AS parent_name, p.email AS parent_email
            FROM pcm_fee_payments f
            JOIN students s ON s.id = f.student_id
            JOIN parents p ON p.id = f.parent_id
            WHERE f.id = :id LIMIT 1
        ");
        $row->execute([':id' => $fid]);
        $fee = $row->fetch(PDO::FETCH_ASSOC);
        if (!$fee) throw new Exception('Record not found.');
        $toEmail = trim((string)($fee['parent_email'] ?? ''));
        if ($toEmail === '') throw new Exception('Parent email not available.');

        $vars = [
            '{parent_name}' => (string)($fee['parent_name'] ?? 'Parent'),
            '{child_name}' => (string)($fee['student_name'] ?? 'Student'),
            '{plan_type}' => (string)($fee['plan_type'] ?? ''),
            '{instalment_label}' => (string)($fee['instalment_label'] ?? ''),
            '{due_amount}' => number_format((float)($fee['due_amount'] ?? 0), 2),
            '{paid_amount}' => number_format((float)($fee['paid_amount'] ?? 0), 2),
            '{status}' => (string)($fee['status'] ?? ''),
        ];
        $subject = strtr($subjectTpl, $vars);
        $bodyText = strtr($bodyTpl, $vars);
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;">' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</div>';
        $sent = @send_mail($toEmail, (string)($fee['parent_name'] ?? 'Parent'), $subject, $html, 8);
        if (!$sent) throw new Exception('Email send failed.');

        $message = 'Email sent successfully to ' . $toEmail . '.';
        $success = true;
        $reload = true;
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

// ---------------- LOAD UNIFIED PAYMENTS TABLE (flat, one row per instalment) ----------------
$latestClassJoin = "
    LEFT JOIN (
        SELECT ca1.student_id, ca1.class_id
        FROM class_assignments ca1
        INNER JOIN (
            SELECT ca2.student_id, MAX(ca2.id) AS assignment_id
            FROM class_assignments ca2
            INNER JOIN classes c2 ON c2.id = ca2.class_id AND c2.active = 1
            GROUP BY ca2.student_id
        ) latest_ca ON latest_ca.assignment_id = ca1.id
    ) current_ca ON current_ca.student_id = s.id
    LEFT JOIN classes current_class ON current_class.id = current_ca.class_id AND current_class.active = 1
";
$attendanceTotalJoin = "
    LEFT JOIN (
        SELECT student_id,
               SUM(CASE WHEN LOWER(COALESCE(status, '')) = 'present' THEN 1 ELSE 0 END) AS total_attendance
        FROM attendance
        GROUP BY student_id
    ) attendance_totals ON attendance_totals.student_id = s.id
";

$stmtPayments = $pdo->prepare("
    SELECT
        f.id, f.enrolment_id, f.student_id, f.parent_id, f.plan_type, f.instalment_label,
        f.due_amount, f.paid_amount, f.payment_ref, f.proof_path, f.status, f.reject_reason,
        f.submitted_at, f.verified_by, f.verified_at,
        s.student_name, s.student_id AS stu_code,
        current_class.class_name,
        COALESCE(attendance_totals.total_attendance, 0) AS total_attendance,
        p.full_name AS parent_name, p.email AS parent_email, p.phone AS parent_phone
    FROM pcm_fee_payments f
    JOIN students s ON s.id = f.student_id
    {$latestClassJoin}
    {$attendanceTotalJoin}
    LEFT JOIN parents p ON p.id = f.parent_id
    WHERE f.plan_type IN ('Term-wise','Half-yearly','Yearly','Additional')
    ORDER BY FIELD(f.status,'Pending','Rejected','Unpaid','Verified'), f.submitted_at DESC, f.id DESC
    LIMIT 1000
");
$stmtPayments->execute();
$payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

$updateCounts = ['Pending' => 0, 'Verified' => 0, 'Rejected' => 0, 'Unpaid' => 0];
foreach ($payments as $row) {
    $k = (string)($row['status'] ?? '');
    if (isset($updateCounts[$k])) $updateCounts[$k]++;
}

$classOptions = $pdo->query("SELECT id, class_name FROM classes WHERE active = 1 ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$classCharges = $pdo->query("
    SELECT cc.*,
           c.class_name,
           (
             SELECT COUNT(*)
             FROM pcm_fee_payments fp
             WHERE fp.class_charge_id = cc.id
           ) AS applied_students
    FROM pcm_class_fee_charges cc
    LEFT JOIN classes c ON c.id = cc.class_id
    ORDER BY cc.created_at DESC, cc.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$bankName = $feesSettings['bank_name'] ?? '';
$accName  = $feesSettings['account_name'] ?? '';
$bsb      = $feesSettings['bsb'] ?? '';
$accNo    = $feesSettings['account_number'] ?? '';
$notes    = $feesSettings['bank_notes'] ?? '';

$isAdminTier = is_admin_role();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Manage Payments</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .mini { font-size:12px; color:#6c757d; }
        .nowrap { white-space:nowrap; }
        td.wrap { white-space: normal !important; max-width: 260px; }

        .summary-box { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:14px; }
        .due-pill { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#1b4fd6; font-size:11px; font-weight:700; margin-right:6px; margin-bottom:6px; }
        .kv strong { display:inline-block; min-width:120px; }

        .proof-thumb { display:inline-flex; align-items:center; gap:8px; cursor:pointer; text-decoration:none; }
        .thumb-img { width:42px; height:42px; object-fit:cover; border-radius:8px; border:1px solid #e3e6f0; background:#fff; }
        .thumb-icon { width:42px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:8px; border:1px solid #e3e6f0; background:#fff; color:#d93025; font-size:18px; }

        .swal2-popup { width: 920px !important; max-width: 96vw !important; }
        .proof-stage { width: 100%; max-height: 72vh; overflow: auto; border: 1px solid #e3e6f0; border-radius: 12px; padding: 10px; background: #fafbff; display: flex; align-items: center; justify-content: center; }
        .proof-img { display:block; transform-origin: top left; border-radius: 10px; border: 1px solid #e3e6f0; background:#fff; max-width: 100%; max-height: calc(72vh - 24px); width: auto; height: auto; }
        .swal-toolbar { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-top:10px; }

        thead th { vertical-align: middle !important; }
        thead .mini { font-size:11px; font-weight:700; }
        .ref-col { max-width: 220px; }

        .payments-table { min-width: 1300px; }
        .payments-table tbody tr.status-pending { background:#fff8e1; }
        .payments-table tbody tr.status-rejected { background:#fff1f0; }
        .payments-table tbody tr.status-verified { background:#eefaf4; }

        .payment-summary-grid { display:grid; grid-template-columns:repeat(4, minmax(120px, 1fr)); gap:10px; margin-bottom:16px; }
        .payment-summary-item { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #e3e6f0; border-radius:10px; background:#fff; }
        .payment-summary-icon { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; flex:0 0 34px; }
        .summary-pending .payment-summary-icon { background:#fff4cf; color:#b77900; }
        .summary-verified .payment-summary-icon { background:#def7ec; color:#087f5b; }
        .summary-rejected .payment-summary-icon { background:#fde8e7; color:#c53030; }
        .summary-unpaid .payment-summary-icon { background:#edf0f5; color:#5a5c69; }
        .payment-summary-value { font-size:1.15rem; font-weight:800; line-height:1; color:#2e3440; }
        .payment-summary-label { margin-top:4px; font-size:.69rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#858796; }

        .update-payments-toolbar { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:8px; padding:12px; margin-bottom:14px; }
        .update-payments-toolbar .form-control, .update-payments-toolbar .btn { min-height:38px; }

        .payment-filter-panel { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:10px; padding:14px; margin-bottom:14px; }
        .payment-filter-panel .form-control { min-height:40px; border-color:#d9deea; }
        .payment-filter-panel .input-group .btn { min-height:40px; }

        .status-filter-btn.active { color:#fff !important; }
        .status-filter-btn[data-status="Pending"].active { background:#f6c23e; border-color:#f6c23e; color:#1f2933 !important; }
        .status-filter-btn[data-status="Verified"].active { background:#1cc88a; border-color:#1cc88a; }
        .status-filter-btn[data-status="Rejected"].active { background:#e74a3b; border-color:#e74a3b; }
        .status-filter-btn[data-status="Unpaid"].active { background:#5a5c69; border-color:#5a5c69; }

        .method-tabs-wrap { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:12px; padding:14px 16px; margin-bottom:16px; }
        .method-pill { border-radius:20px !important; font-weight:600; font-size:.82rem; padding:6px 16px; border:2px solid transparent; margin-right:6px; margin-bottom:6px; }
        .method-pill.is-active-all { background:#881b12; color:#fff; border-color:#881b12; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h1 class="h3 text-gray-800 mb-0">Manage Payments</h1>
                    <a href="feesManagement" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-table"></i> Fees Overview
                    </a>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const msg = <?php echo json_encode($message); ?>;
                        const ok  = <?php echo $success ? 'true' : 'false'; ?>;
                        const reload = <?php echo $reload ? 'true' : 'false'; ?>;

                        if (msg) {
                            Swal.fire({
                                icon: ok ? 'success' : 'error',
                                title: msg,
                                showConfirmButton: true,
                                timer: ok ? 1400 : 6000
                            }).then(()=> { if (ok && reload) window.location.href = 'update-payments.php'; });
                        }
                    });
                </script>

                <!-- Bank & Due Dates reference -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-info-circle"></i> Current Bank & Due Dates Summary</h6>
                        <span class="mini">These values come from Fees Settings</span>
                    </div>
                    <div class="card-body">
                        <div class="summary-box">
                            <div class="row">
                                <div class="col-lg-6 mb-3 mb-lg-0">
                                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-university"></i> Bank Details</h6>
                                    <div class="kv mini"><strong>Bank:</strong> <?php echo h($bankName ?: '-'); ?></div>
                                    <div class="kv mini"><strong>Account Name:</strong> <?php echo h($accName ?: '-'); ?></div>
                                    <div class="kv mini"><strong>BSB:</strong> <?php echo h($bsb ?: '-'); ?></div>
                                    <div class="kv mini"><strong>Account No:</strong> <?php echo h($accNo ?: '-'); ?></div>
                                    <?php if (!empty($notes)): ?>
                                        <div class="mini mt-2"><strong>Notes:</strong> <?php echo h($notes); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-lg-6">
                                    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-calendar-alt"></i> Due Dates</h6>
                                    <div class="mini mb-2">
                                        <span class="due-pill">TERM1 / HALF1 / YEARLY: <?php echo pretty_date($feesSettings['due_term1'] ?? null); ?></span>
                                        <span class="due-pill">TERM2: <?php echo pretty_date($feesSettings['due_term2'] ?? null); ?></span>
                                        <span class="due-pill">TERM3 / HALF2: <?php echo pretty_date($feesSettings['due_term3'] ?? null); ?></span>
                                        <span class="due-pill">TERM4: <?php echo pretty_date($feesSettings['due_term4'] ?? null); ?></span>
                                    </div>
                                    <div class="mini text-muted">Rule applied: Term1 = Half1 = Yearly, and Term3 = Half2.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($isAdminTier): ?>
                <!-- Class-Based Additional Charges -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-book mr-1"></i>Class-Based Additional Charges</h6>
                        <span class="mini">Example: Textbook charge by class</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="class_charge_action" value="add">
                            <div class="form-row">
                                <div class="form-group col-lg-3">
                                    <label class="mb-1">Class</label>
                                    <select name="charge_class_id" class="form-control" required>
                                        <option value="">Select class...</option>
                                        <?php foreach ($classOptions as $co): ?>
                                            <option value="<?php echo (int)$co['id']; ?>"><?php echo h($co['class_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-lg-3">
                                    <label class="mb-1">Charge Name</label>
                                    <input type="text" name="charge_title" class="form-control" maxlength="120" placeholder="Textbook Charge" required>
                                </div>
                                <div class="form-group col-lg-2">
                                    <label class="mb-1">Amount</label>
                                    <input type="number" step="0.01" min="0.01" name="charge_amount" class="form-control" required>
                                </div>
                                <div class="form-group col-lg-2">
                                    <label class="mb-1">Due Date</label>
                                    <input type="date" name="charge_due_date" class="form-control">
                                </div>
                                <div class="form-group col-lg-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-plus-circle mr-1"></i>Add & Apply</button>
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="mb-1">Description (optional)</label>
                                <input type="text" name="charge_description" class="form-control" maxlength="500" placeholder="Optional note shown for admin reference">
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th><th>Class</th><th>Charge</th><th>Amount</th><th>Due Date</th><th>Applied Students</th><th>Status</th><th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($classCharges)): ?>
                                    <tr><td colspan="8" class="text-center text-muted">No class-based charges added yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($classCharges as $i => $cc): ?>
                                        <tr>
                                            <td><?php echo (int)$i + 1; ?></td>
                                            <td><?php echo h((string)($cc['class_name'] ?? 'Unknown Class')); ?></td>
                                            <td><?php echo h((string)$cc['charge_title']); ?></td>
                                            <td>$<?php echo number_format((float)$cc['amount'], 2); ?></td>
                                            <td><?php echo h((string)($cc['due_date'] ?? '-')); ?></td>
                                            <td><?php echo (int)($cc['applied_students'] ?? 0); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo ((int)$cc['is_active'] === 1) ? 'success' : 'secondary'; ?>">
                                                    <?php echo ((int)$cc['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="nowrap">
                                                <form method="POST" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="class_charge_action" value="apply">
                                                    <input type="hidden" name="charge_id" value="<?php echo (int)$cc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Apply Missing</button>
                                                </form>
                                                <form method="POST" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="class_charge_action" value="toggle">
                                                    <input type="hidden" name="charge_id" value="<?php echo (int)$cc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        <?php echo ((int)$cc['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Unified payments table -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-money-check-alt mr-1"></i>All Payments</h6>
                    </div>
                    <div class="card-body">
                        <div class="payment-summary-grid">
                            <div class="payment-summary-item summary-pending"><span class="payment-summary-icon"><i class="fas fa-clock"></i></span><div><div class="payment-summary-value"><?= (int)$updateCounts['Pending'] ?></div><div class="payment-summary-label">Pending</div></div></div>
                            <div class="payment-summary-item summary-verified"><span class="payment-summary-icon"><i class="fas fa-check"></i></span><div><div class="payment-summary-value"><?= (int)$updateCounts['Verified'] ?></div><div class="payment-summary-label">Verified</div></div></div>
                            <div class="payment-summary-item summary-rejected"><span class="payment-summary-icon"><i class="fas fa-times"></i></span><div><div class="payment-summary-value"><?= (int)$updateCounts['Rejected'] ?></div><div class="payment-summary-label">Rejected</div></div></div>
                            <div class="payment-summary-item summary-unpaid"><span class="payment-summary-icon"><i class="fas fa-wallet"></i></span><div><div class="payment-summary-value"><?= (int)$updateCounts['Unpaid'] ?></div><div class="payment-summary-label">Unpaid</div></div></div>
                        </div>

                        <form method="POST" id="bulkPaymentForm" class="update-payments-toolbar">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="bulk_update_payments">
                            <div class="row align-items-end">
                                <div class="col-lg-3 col-md-6 mb-2">
                                    <label class="mini font-weight-bold text-uppercase mb-1">Bulk status</label>
                                    <select name="bulk_status" class="form-control form-control-sm" required>
                                        <option value="">Choose status</option>
                                        <option value="Verified">Verified</option>
                                        <option value="Pending">Pending</option>
                                        <option value="Rejected">Rejected</option>
                                        <option value="Unpaid">Unpaid</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-2">
                                    <label class="mini font-weight-bold text-uppercase mb-1">Amount helper</label>
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="bulkSetPaidToDue" name="set_paid_to_due" value="1">
                                        <label class="custom-control-label" for="bulkSetPaidToDue">Set paid amount to due</label>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-2">
                                    <label class="mini font-weight-bold text-uppercase mb-1">Selected rows</label>
                                    <div><strong id="selectedPaymentCount">0</strong> selected</div>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-2 text-lg-right">
                                    <button type="button" class="btn btn-sm btn-outline-secondary mr-1" id="selectVisiblePayments">Select visible</button>
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Apply selected</button>
                                </div>
                            </div>
                        </form>

                        <div class="payment-filter-panel">
                            <div class="form-row align-items-end">
                                <div class="col-lg-3 col-md-6 mb-2">
                                    <label class="mini font-weight-bold text-uppercase mb-1">Status</label><br>
                                    <button class="btn btn-sm btn-primary status-filter-btn active" data-status="all" type="button">All</button>
                                    <button class="btn btn-sm btn-outline-secondary status-filter-btn" data-status="Pending" type="button">Pending</button>
                                    <button class="btn btn-sm btn-outline-secondary status-filter-btn" data-status="Verified" type="button">Verified</button>
                                    <button class="btn btn-sm btn-outline-secondary status-filter-btn" data-status="Rejected" type="button">Rejected</button>
                                    <button class="btn btn-sm btn-outline-secondary status-filter-btn" data-status="Unpaid" type="button">Unpaid</button>
                                </div>
                                <div class="col-lg-2 col-md-6 mb-2">
                                    <label for="updateClassFilter" class="mini font-weight-bold text-uppercase mb-1">Class</label>
                                    <select id="updateClassFilter" class="form-control form-control-sm">
                                        <option value="all">All classes</option>
                                        <?php foreach ($classOptions as $classOption): ?>
                                            <option value="<?= h((string)$classOption['class_name']) ?>"><?= h((string)$classOption['class_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6 mb-2">
                                    <label for="updatePlanFilter" class="mini font-weight-bold text-uppercase mb-1">Plan</label>
                                    <select id="updatePlanFilter" class="form-control form-control-sm">
                                        <option value="">All Plans</option>
                                        <option value="Term-wise">Term-wise</option>
                                        <option value="Half-yearly">Half-yearly</option>
                                        <option value="Yearly">Yearly</option>
                                        <option value="Additional">Additional</option>
                                    </select>
                                </div>
                                <div class="col-lg-5 col-md-6 mb-2">
                                    <label for="updatePaymentSearch" class="mini font-weight-bold text-uppercase mb-1">Search payments</label>
                                    <input type="search" id="updatePaymentSearch" class="form-control form-control-sm" placeholder="Child, student ID, parent, email, phone or reference">
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table id="paymentsTable" class="table table-bordered table-hover payments-table" width="100%">
                                <thead class="thead-light">
                                <tr>
                                    <th></th>
                                    <th>#</th>
                                    <th>Child</th>
                                    <th>Class</th>
                                    <th>Parent</th>
                                    <th>Plan</th>
                                    <th>Instalment</th>
                                    <th>Due</th>
                                    <th>Paid</th>
                                    <th>Ref</th>
                                    <th>Proof</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th style="min-width:220px;">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($payments as $i => $f): ?>
                                    <?php
                                        $proof = trim((string)($f['proof_path'] ?? ''));
                                        $ptype = $proof !== '' ? proof_type($proof) : '';
                                        $rowStatus = strtolower((string)$f['status']);
                                    ?>
                                    <tr class="status-<?= h($rowStatus) ?>"
                                        data-class="<?= h((string)($f['class_name'] ?? '')) ?>"
                                        data-plan="<?= h((string)$f['plan_type']) ?>"
                                        data-status="<?= h((string)$f['status']) ?>"
                                        data-search="<?= h(strtolower(($f['student_name'] ?? '') . ' ' . ($f['stu_code'] ?? '') . ' ' . ($f['parent_name'] ?? '') . ' ' . ($f['parent_email'] ?? '') . ' ' . ($f['parent_phone'] ?? '') . ' ' . ($f['payment_ref'] ?? ''))) ?>">
                                        <td><input type="checkbox" class="payment-row-check" name="payment_ids[]" value="<?= (int)$f['id'] ?>" form="bulkPaymentForm"></td>
                                        <td><?= $i + 1 ?></td>
                                        <td class="wrap"><?= h($f['student_name']) ?> <small class="text-muted">(<?= h($f['stu_code']) ?>)</small></td>
                                        <td><?= $f['class_name'] ? h($f['class_name']) : '<span class="text-muted">-</span>' ?></td>
                                        <td class="wrap"><?= h($f['parent_name'] ?: '-') ?><br><span class="mini"><?= h($f['parent_email'] ?: '-') ?></span></td>
                                        <td><?= h($f['plan_type']) ?></td>
                                        <td class="font-weight-bold"><?= h($f['instalment_label']) ?></td>
                                        <td>$<?= number_format((float)$f['due_amount'], 2) ?></td>
                                        <td>$<?= number_format((float)$f['paid_amount'], 2) ?></td>
                                        <td><?= h($f['payment_ref'] ?? '-') ?></td>
                                        <td>
                                            <?php if ($proof !== ''): ?>
                                                <a href="javascript:void(0)" class="mini proof-thumb" data-proof="<?= h($proof) ?>" data-type="<?= h($ptype) ?>" data-name="<?= h(basename($proof)) ?>"><i class="fas fa-eye"></i> View</a>
                                            <?php else: ?>
                                                <span class="mini text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= pcm_badge((string)$f['status']) ?>"><?= h($f['status']) ?></span>
                                            <?php if (!empty($f['reject_reason'])): ?><br><small class="text-danger"><?= h($f['reject_reason']) ?></small><?php endif; ?>
                                        </td>
                                        <td class="nowrap"><?= $f['submitted_at'] ? date('d M Y', strtotime($f['submitted_at'])) : '-' ?></td>
                                        <td class="nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary js-edit-btn" title="Edit"
                                                    data-id="<?= (int)$f['id'] ?>"
                                                    data-due="<?= h((string)$f['due_amount']) ?>"
                                                    data-paid="<?= h((string)$f['paid_amount']) ?>"
                                                    data-ref="<?= h((string)($f['payment_ref'] ?? '')) ?>"
                                                    data-status="<?= h((string)$f['status']) ?>"
                                                    data-label="<?= h((string)$f['instalment_label']) ?>"
                                                    data-child="<?= h((string)$f['student_name']) ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($isAdminTier && in_array($f['status'], ['Unpaid', 'Rejected'], true)): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success js-markpaid-btn" title="Mark Paid"
                                                        data-id="<?= (int)$f['id'] ?>"
                                                        data-due="<?= h((string)$f['due_amount']) ?>"
                                                        data-child="<?= h((string)$f['student_name']) ?>"
                                                        data-label="<?= h((string)$f['instalment_label']) ?>">
                                                    <i class="fas fa-hand-holding-usd"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($isAdminTier && $f['status'] === 'Pending'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success js-verify-btn" title="Verify"
                                                        data-id="<?= (int)$f['id'] ?>"
                                                        data-child="<?= h((string)$f['student_name']) ?>"
                                                        data-label="<?= h((string)$f['instalment_label']) ?>">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger js-reject-btn" title="Reject"
                                                        data-id="<?= (int)$f['id'] ?>"
                                                        data-child="<?= h((string)$f['student_name']) ?>"
                                                        data-label="<?= h((string)$f['instalment_label']) ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($isAdminTier): ?>
                                                <button type="button" class="btn btn-sm btn-outline-info js-email-btn" title="Email parent"
                                                        data-id="<?= (int)$f['id'] ?>"
                                                        data-child="<?= h((string)$f['student_name']) ?>">
                                                    <i class="fas fa-envelope"></i>
                                                </button>
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

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<!-- Shared modal: Edit fee row -->
<div class="modal fade" id="editPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_payment_row">
                <input type="hidden" name="payment_id" id="editPaymentId" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="small mb-2"><strong id="editPaymentChild"></strong> - <span id="editPaymentLabel"></span></div>
                    <div class="form-group"><label>Due Amount</label><input type="number" min="0" step="0.01" name="due_amount" id="editPaymentDue" class="form-control" required></div>
                    <div class="form-group"><label>Paid Amount</label><input type="number" min="0" step="0.01" name="paid_amount" id="editPaymentPaid" class="form-control" required></div>
                    <div class="form-group"><label>Reference</label><input type="text" name="payment_ref" id="editPaymentRef" class="form-control" placeholder="Payment reference"></div>
                    <div class="form-group mb-0">
                        <label>Status</label>
                        <select name="status" id="editPaymentStatus" class="form-control">
                            <option value="Unpaid">Unpaid</option>
                            <option value="Pending">Pending</option>
                            <option value="Verified">Verified</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Mark Paid -->
<div class="modal fade" id="markPaidModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="manual_mark_paid">
                <input type="hidden" name="payment_id" id="markPaidId" value="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Record Manual Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="small mb-2"><strong id="markPaidChild"></strong> - <span id="markPaidLabel"></span></div>
                    <div class="form-group"><label>Paid Amount</label><input type="number" min="0" step="0.01" name="paid_amount" id="markPaidAmount" class="form-control" required></div>
                    <div class="form-group mb-0"><label>Reference</label><input type="text" name="payment_ref" class="form-control" placeholder="Payment reference"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Verify -->
<div class="modal fade" id="verifyPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="fee_id" id="verifyPaymentId" value="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Verify Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p>Confirm <strong id="verifyPaymentChild"></strong> — <span id="verifyPaymentLabel"></span> has been paid?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check mr-1"></i>Verify</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Shared modal: Reject -->
<div class="modal fade" id="rejectPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="fee_id" id="rejectPaymentId" value="">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p><strong id="rejectPaymentChild"></strong> — <span id="rejectPaymentLabel"></span></p>
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

<!-- Shared modal: Email parent -->
<div class="modal fade" id="emailPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_custom_email">
                <input type="hidden" name="fee_id" id="emailPaymentId" value="">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Send Parent Email</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="mini mb-2">Variables: {parent_name}, {child_name}, {plan_type}, {instalment_label}, {due_amount}, {paid_amount}, {status}</div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="email_subject" class="form-control" value="Fee Update for {child_name}" required>
                    </div>
                    <div class="form-group mb-0">
                        <label>Message</label>
                        <textarea name="email_body" class="form-control" rows="6" required>Dear {parent_name},

This is an update for {child_name}:
- Plan: {plan_type}
- Instalment: {instalment_label}
- Due: ${due_amount}
- Paid: ${paid_amount}
- Status: {status}

Thank you.</textarea>
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

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    let dt = null;
    if (document.getElementById('paymentsTable') && window.jQuery && jQuery.fn && typeof jQuery.fn.DataTable === 'function') {
        dt = jQuery('#paymentsTable').DataTable({
            pageLength: 25,
            order: [[12, 'desc']],
            columnDefs: [{ orderable: false, searchable: false, targets: [0, 13] }]
        });
    }

    function applyFilters() {
        if (!dt) return;
        const status = (document.querySelector('.status-filter-btn.active') || {}).dataset ? document.querySelector('.status-filter-btn.active').dataset.status : 'all';
        dt.column(11).search(status === 'all' ? '' : '^' + status.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', true, false);

        const cls = document.getElementById('updateClassFilter').value;
        dt.column(3).search(cls === 'all' ? '' : '^' + cls.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', true, false);

        const plan = document.getElementById('updatePlanFilter').value;
        dt.column(5).search(plan === '' ? '' : '^' + plan.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', true, false);

        const search = document.getElementById('updatePaymentSearch').value;
        dt.search(search);

        dt.draw();
    }

    document.querySelectorAll('.status-filter-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.status-filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            applyFilters();
        });
    });
    document.getElementById('updateClassFilter').addEventListener('change', applyFilters);
    document.getElementById('updatePlanFilter').addEventListener('change', applyFilters);
    document.getElementById('updatePaymentSearch').addEventListener('keyup', applyFilters);

    function updateSelectedCount() {
        const count = document.querySelectorAll('.payment-row-check:checked').length;
        document.getElementById('selectedPaymentCount').textContent = String(count);
    }
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('payment-row-check')) updateSelectedCount();
    });
    document.getElementById('selectVisiblePayments').addEventListener('click', function () {
        document.querySelectorAll('#paymentsTable tbody tr').forEach(row => {
            if (row.style.display !== 'none') {
                const cb = row.querySelector('.payment-row-check');
                if (cb) cb.checked = true;
            }
        });
        updateSelectedCount();
    });

    // Row action buttons + proof viewer: delegated on document, since DataTables
    // recreates row DOM nodes on paginate/search/sort, which would silently
    // orphan any listener bound directly to a button at initial page load.
    document.addEventListener('click', function (e) {
        const editBtn = e.target.closest('.js-edit-btn');
        if (editBtn) {
            document.getElementById('editPaymentId').value = editBtn.dataset.id;
            document.getElementById('editPaymentDue').value = editBtn.dataset.due;
            document.getElementById('editPaymentPaid').value = editBtn.dataset.paid;
            document.getElementById('editPaymentRef').value = editBtn.dataset.ref;
            document.getElementById('editPaymentStatus').value = editBtn.dataset.status;
            document.getElementById('editPaymentChild').textContent = editBtn.dataset.child;
            document.getElementById('editPaymentLabel').textContent = editBtn.dataset.label;
            jQuery('#editPaymentModal').modal('show');
            return;
        }

        const markPaidBtn = e.target.closest('.js-markpaid-btn');
        if (markPaidBtn) {
            document.getElementById('markPaidId').value = markPaidBtn.dataset.id;
            document.getElementById('markPaidAmount').value = markPaidBtn.dataset.due;
            document.getElementById('markPaidChild').textContent = markPaidBtn.dataset.child;
            document.getElementById('markPaidLabel').textContent = markPaidBtn.dataset.label;
            jQuery('#markPaidModal').modal('show');
            return;
        }

        const verifyBtn = e.target.closest('.js-verify-btn');
        if (verifyBtn) {
            document.getElementById('verifyPaymentId').value = verifyBtn.dataset.id;
            document.getElementById('verifyPaymentChild').textContent = verifyBtn.dataset.child;
            document.getElementById('verifyPaymentLabel').textContent = verifyBtn.dataset.label;
            jQuery('#verifyPaymentModal').modal('show');
            return;
        }

        const rejectBtn = e.target.closest('.js-reject-btn');
        if (rejectBtn) {
            document.getElementById('rejectPaymentId').value = rejectBtn.dataset.id;
            document.getElementById('rejectPaymentChild').textContent = rejectBtn.dataset.child;
            document.getElementById('rejectPaymentLabel').textContent = rejectBtn.dataset.label;
            jQuery('#rejectPaymentModal').modal('show');
            return;
        }

        const emailBtn = e.target.closest('.js-email-btn');
        if (emailBtn) {
            document.getElementById('emailPaymentId').value = emailBtn.dataset.id;
            jQuery('#emailPaymentModal').modal('show');
            return;
        }

        const proofEl = e.target.closest('.proof-thumb');
        if (proofEl) {
            const proof = proofEl.dataset.proof;
            const type = proofEl.dataset.type;
            const name = proofEl.dataset.name;
            let html;
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
});
</script>
</body>
</html>
