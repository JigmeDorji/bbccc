<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'parent') {
    header("Location: index-admin.php");
    exit;
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
    die("DB connection failed: " . $e->getMessage());
}

// ---------------- HELPERS ----------------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safe_mkdir(string $path): void {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new Exception("Unable to create upload folder. Check permissions: " . $path);
        }
    }
    if (!is_writable($path)) {
        throw new Exception("Upload folder is not writable. Please chmod/chown this folder: " . $path);
    }
}

function upload_error_message(int $code): string {
    $map = [
        UPLOAD_ERR_OK => 'OK',
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE from form',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk (permissions)',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by a PHP extension',
    ];
    return $map[$code] ?? 'Unknown upload error';
}

function plan_installments(string $plan): array {
    $p = strtolower(trim($plan));
    if ($p === 'term-wise') return ['TERM1','TERM2','TERM3','TERM4'];
    if ($p === 'half-yearly') return ['HALF1','HALF2'];
    if ($p === 'yearly') return ['YEARLY'];
    return [];
}

function first_installment_code(string $planType): string {
    return match ($planType) {
        'Term-wise' => 'TERM1',
        'Half-yearly' => 'HALF1',
        'Yearly' => 'YEARLY',
        default => 'TERM1'
    };
}

function installment_label(string $code): string {
    return match ($code) {
        'TERM1' => 'Term 1',
        'TERM2' => 'Term 2',
        'TERM3' => 'Term 3',
        'TERM4' => 'Term 4',
        'HALF1' => 'Half-year 1',
        'HALF2' => 'Half-year 2',
        'YEARLY' => 'Yearly',
        default => $code
    };
}

function badge_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'approved') return 'success';
    if ($s === 'rejected') return 'danger';
    return 'warning';
}

function normalize_status($v): string { return strtolower(trim((string)$v)); }

function proof_type(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return ($ext === 'pdf') ? 'pdf' : 'img';
}

/**
 * Due date rules:
 * TERM1 = HALF1 = YEARLY -> due_term1
 * TERM2 -> due_term2
 * TERM3 = HALF2 -> due_term3
 * TERM4 -> due_term4
 */
function installment_due_date(array $settings, string $installmentCode): ?string {
    return match ($installmentCode) {
        'TERM1','HALF1','YEARLY' => ($settings['due_term1'] ?? null),
        'TERM2' => ($settings['due_term2'] ?? null),
        'TERM3','HALF2' => ($settings['due_term3'] ?? null),
        'TERM4' => ($settings['due_term4'] ?? null),
        default => null
    };
}

function money_fmt($v): string {
    $n = is_numeric($v) ? (float)$v : 0.0;
    return number_format($n, 2);
}

function student_initials(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return 'XX';
    $parts = explode(' ', $name);
    $a = strtoupper(substr($parts[0], 0, 1));
    $b = strtoupper(substr(($parts[1] ?? $parts[0]), 0, 1));
    return preg_replace('/[^A-Z]/', '', $a . $b) ?: 'XX';
}

function random_letters(int $len = 3): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $out = '';
    for ($i=0; $i<$len; $i++) $out .= $chars[random_int(0, strlen($chars)-1)];
    return $out;
}

function inst_short(string $code): string {
    return match ($code) {
        'TERM1' => 'term1',
        'TERM2' => 'term2',
        'TERM3' => 'term3',
        'TERM4' => 'term4',
        'HALF1' => 'half1',
        'HALF2' => 'half2',
        'YEARLY' => 'yearly',
        default => strtolower($code)
    };
}

/** Amounts from settings */
function plan_amount_from_settings(array $settings, string $planType): float {
    return match ($planType) {
        'Term-wise' => (float)($settings['amount_termwise'] ?? 65.00),
        'Half-yearly' => (float)($settings['amount_halfyearly'] ?? 125.00),
        'Yearly' => (float)($settings['amount_yearly'] ?? 250.00),
        default => 0.00
    };
}

/**
 * Ensure fee rows exist and reference exists.
 * (First installment proof/status synced from enrollment.)
 */
function ensure_fee_rows_for_student(PDO $pdo, array $student, array $feesSettings): void {
    $studentDbId = (string)$student['student_db_id'];
    $planType = (string)$student['payment_plan'];
    $approval = strtolower(trim((string)($student['approval_status'] ?? '')));
    $enrollProof = trim((string)($student['payment_proof'] ?? ''));
    $studentName = (string)($student['student_name'] ?? '');

    $installments = plan_installments($planType);
    if (!$installments) return;

    $firstCode = first_installment_code($planType);
    $dueAmount = plan_amount_from_settings($feesSettings, $planType);

    foreach ($installments as $code) {
        $stmt = $pdo->prepare("SELECT * FROM fees_payments WHERE student_id = :sid AND installment_code = :code LIMIT 1");
        $stmt->execute([':sid' => $studentDbId, ':code' => $code]);
        $row = $stmt->fetch();

        $status = 'Pending';
        $proof = null;

        if ($code === $firstCode && $enrollProof !== '') {
            $proof = $enrollProof;
            if ($approval === 'approved') $status = 'Approved';
        }

        // reference format: AB_term2_xyz
        $ref = student_initials($studentName) . '_' . inst_short($code) . '_' . random_letters(3);

        if (!$row) {
            $ins = $pdo->prepare("
                INSERT INTO fees_payments (student_id, plan_type, installment_code, due_amount, status, proof_path, payment_reference)
                VALUES (:sid, :plan, :code, :due, :st, :proof, :ref)
            ");
            $ins->execute([
                ':sid' => $studentDbId,
                ':plan' => $planType,
                ':code' => $code,
                ':due' => $dueAmount,
                ':st' => $status,
                ':proof' => $proof,
                ':ref' => $ref
            ]);
        } else {
            $needRef = (trim((string)($row['payment_reference'] ?? '')) === '');
            $needAmount = ((float)($row['due_amount'] ?? 0) <= 0);

            $needProofSync = false;
            $needApproveSync = false;

            if ($code === $firstCode) {
                $curProof = trim((string)($row['proof_path'] ?? ''));
                $curStatus = strtolower((string)($row['status'] ?? 'pending'));

                $needProofSync = ($curProof === '' && $enrollProof !== '');
                $needApproveSync = ($curStatus !== 'approved' && $approval === 'approved' && $enrollProof !== '');
            }

            if ($needProofSync || $needApproveSync || $needRef || $needAmount) {
                $newStatus = $needApproveSync ? 'Approved' : ($row['status'] ?? 'Pending');

                $upd = $pdo->prepare("
                    UPDATE fees_payments
                    SET proof_path = CASE WHEN (proof_path IS NULL OR proof_path = '') THEN :proof ELSE proof_path END,
                        status = :st,
                        payment_reference = CASE WHEN (payment_reference IS NULL OR payment_reference = '') THEN :ref ELSE payment_reference END,
                        due_amount = CASE WHEN (due_amount IS NULL OR due_amount <= 0) THEN :due ELSE due_amount END
                    WHERE id = :id
                ");
                $upd->execute([
                    ':proof' => $enrollProof,
                    ':st' => $newStatus,
                    ':ref' => $ref,
                    ':due' => $dueAmount,
                    ':id' => (int)$row['id']
                ]);
            }
        }
    }
}

// ---------------- LOAD FEES SETTINGS ----------------
$stmtSet = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
$feesSettings = $stmtSet->fetch() ?: [];

// ---------------- GET LOGGED-IN PARENT ----------------
$sessionEmail = strtolower(trim($_SESSION['username'] ?? ''));
if ($sessionEmail === '') die("Session username missing. Please log out and log in again.");

$stmtParent = $pdo->prepare("SELECT id, full_name, email FROM parents WHERE LOWER(email)=:e LIMIT 1");
$stmtParent->execute([':e' => $sessionEmail]);
$parent = $stmtParent->fetch();
if (!$parent) die("No parent record found for email: <strong>" . htmlspecialchars($sessionEmail) . "</strong>");

$parentId = (int)$parent['id'];

// ---------------- LOAD STUDENTS (for this parent) ----------------
$stmtStudents = $pdo->prepare("
    SELECT
        s.id AS student_db_id,
        s.student_id,
        s.student_name,
        s.payment_plan,
        s.approval_status,
        s.payment_proof,
        s.registration_date
    FROM students s
    WHERE s.parentId = :pid
      AND s.payment_plan IN ('Term-wise','Half-yearly','Yearly')
    ORDER BY s.id DESC
");
$stmtStudents->execute([':pid' => $parentId]);
$students = $stmtStudents->fetchAll();

// Ensure fee rows exist for their students
foreach ($students as $st) {
    ensure_fee_rows_for_student($pdo, $st, $feesSettings);
}

// Load fee rows for their students
$feeMap = [];
if ($students) {
    $ids = array_map(fn($r) => (string)$r['student_db_id'], $students);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmtFees = $pdo->prepare("SELECT * FROM fees_payments WHERE student_id IN ($in)");
    $stmtFees->execute($ids);
    $fees = $stmtFees->fetchAll();

    foreach ($fees as $fr) {
        $sid = (string)$fr['student_id'];
        $code = (string)$fr['installment_code'];
        $feeMap[$sid][$code] = $fr;
    }
}

// ---------------- PAY (UPLOAD PROOF) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_student_id'], $_POST['pay_installment'])) {
    try {
        $studentDbId = (int)($_POST['pay_student_id'] ?? 0);
        $installment = trim((string)($_POST['pay_installment'] ?? ''));

        if ($studentDbId <= 0) throw new Exception("Invalid student.");
        if ($installment === '') throw new Exception("Please select an installment to pay.");

        // Verify student belongs to this parent and get plan
        $stmtS = $pdo->prepare("SELECT id, student_name, payment_plan FROM students WHERE id=:id AND parentId=:pid LIMIT 1");
        $stmtS->execute([':id'=>$studentDbId, ':pid'=>$parentId]);
        $student = $stmtS->fetch();
        if (!$student) throw new Exception("Student not found or you don't have permission.");

        $planType = (string)$student['payment_plan'];
        $installments = plan_installments($planType);
        if (!in_array($installment, $installments, true)) throw new Exception("Invalid installment selection.");

        $firstCode = first_installment_code($planType);
        if ($installment === $firstCode) throw new Exception("First payment is handled during enrollment.");

        // Fee row
        $stmtF = $pdo->prepare("SELECT * FROM fees_payments WHERE student_id=:sid AND installment_code=:c LIMIT 1");
        $stmtF->execute([':sid'=>$studentDbId, ':c'=>$installment]);
        $fee = $stmtF->fetch();
        if (!$fee) throw new Exception("Fee record not found. Please contact admin.");
        if (normalize_status($fee['status'] ?? '') === 'approved') throw new Exception("This installment is already approved.");

        // Upload proof
        $err = $_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) throw new Exception("Proof upload required. Upload error: " . upload_error_message((int)$err));

        $allowed = ['jpg','jpeg','png','pdf'];
        $origName = $_FILES['proof']['name'] ?? '';
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) throw new Exception("Invalid file type. Only JPG, PNG or PDF allowed.");

        $size = (int)($_FILES['proof']['size'] ?? 0);
        if ($size > 5 * 1024 * 1024) throw new Exception("File too large. Maximum 5MB allowed.");

        $relativeDir = "uploads/fees/";
        $absDir = __DIR__ . "/" . $relativeDir;
        safe_mkdir($absDir);

        $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
        $safeBase = substr($safeBase, 0, 80);

        $newRel = $relativeDir . time() . "_fee_" . (int)$fee['id'] . "_" . $safeBase . "." . $ext;
        $newAbs = __DIR__ . "/" . $newRel;

        if (!move_uploaded_file($_FILES['proof']['tmp_name'], $newAbs)) {
            throw new Exception("Failed to upload proof (check folder permissions).");
        }

        $upd = $pdo->prepare("
            UPDATE fees_payments
            SET proof_path = :p,
                status = 'Pending',
                verified_by = NULL,
                verified_at = NULL
            WHERE id = :id
        ");
        $upd->execute([':p' => $newRel, ':id' => (int)$fee['id']]);

        $message = "Payment proof uploaded successfully. Waiting for admin verification.";
        $success = true;
        $reload  = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload  = false;
    }
}

if ($reload) {
    header("Location: parentFees.php?msg=" . urlencode($message) . "&ok=" . ($success ? "1" : "0"));
    exit;
}
if (isset($_GET['msg'])) {
    $message = (string)$_GET['msg'];
    $success = (($_GET['ok'] ?? '0') === '1');
}

// Bank details
$bankName = $feesSettings['bank_name'] ?? '';
$accName  = $feesSettings['account_name'] ?? '';
$bsb      = $feesSettings['bsb'] ?? '';
$accNo    = $feesSettings['account_number'] ?? '';
$notes    = $feesSettings['bank_notes'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>My Fees Payments</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .mini { font-size:12px; color:#6c757d; }
        .nowrap { white-space:nowrap; }
        .bank-box { background:#f8f9fc; border:1px solid #e3e6f0; padding:15px; border-radius:10px; }
        .copy-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px dashed #e3e6f0; }
        .copy-row:last-child { border-bottom:none; }
        .copy-val { font-weight:700; color:#111; word-break:break-word; }

        .pay-card { border:1px solid #e3e6f0; border-radius:12px; padding:14px; background:#fff; }
        .ref-box { background:#fff; border:1px dashed #adb5bd; padding:10px; border-radius:10px; }
        .ref-input { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 13px; }
        .payAmountText { letter-spacing: 0.2px; }

        /* Proof UI */
        .proof-thumb { display:inline-flex; align-items:center; gap:8px; cursor:pointer; text-decoration:none; }
        .thumb-img { width:42px; height:42px; object-fit:cover; border-radius:8px; border:1px solid #e3e6f0; background:#fff; }
        .thumb-icon { width:42px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:8px; border:1px solid #e3e6f0; background:#fff; color:#d93025; font-size:18px; }

        /* SweetAlert modal sizing */
        .swal2-popup { width: 920px !important; max-width: 96vw !important; }
        .proof-frame { width: 100%; height: 70vh; border: 1px solid #e3e6f0; border-radius: 12px; }
        .proof-stage { width: 100%; max-height: 72vh; overflow: auto; border: 1px solid #e3e6f0; border-radius: 12px; padding: 10px; background: #fafbff; }
        .proof-img { display:block; transform-origin: top left; border-radius: 10px; border: 1px solid #e3e6f0; background:#fff; }
        .swal-toolbar { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-top:10px; }

        thead th { vertical-align: middle !important; }
        thead .mini { font-size:11px; font-weight:700; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <h1 class="h3 mb-3 text-gray-800">My Fees Payments</h1>

                <?php if ($message): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                icon: <?php echo $success ? "'success'" : "'error'"; ?>,
                                title: <?php echo json_encode($message); ?>,
                                showConfirmButton: true,
                                timer: <?php echo $success ? '1800' : '6000'; ?>
                            });
                        });
                    </script>
                <?php endif; ?>

                <div class="alert alert-info shadow-sm">
                    <strong>Important:</strong>
                    <ul class="mb-0">
                        <li><strong>First payment</strong> is confirmed during enrollment based on the plan selected.</li>
                        <li>Upload proof only for <strong>remaining installments</strong> shown in the Pay Fees dropdown.</li>
                        <li>If you want to <strong>change the payment plan</strong>, please email us.</li>
                    </ul>
                </div>

                <!-- ✅ BANK DETAILS (COPYABLE) -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-university"></i> Bank Details</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="copyAllBank">
                            <i class="fas fa-copy"></i> Copy All
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="bank-box">
                            <div class="copy-row">
                                <div>
                                    <div class="mini">Account Name</div>
                                    <div class="copy-val" id="accNameVal"><?php echo h($accName ?: '-'); ?></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-success copy-btn" data-copy="<?php echo h($accName); ?>">Copy</button>
                            </div>

                            <div class="copy-row">
                                <div>
                                    <div class="mini">BSB</div>
                                    <div class="copy-val" id="bsbVal"><?php echo h($bsb ?: '-'); ?></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-success copy-btn" data-copy="<?php echo h($bsb); ?>">Copy</button>
                            </div>

                            <div class="copy-row">
                                <div>
                                    <div class="mini">Account Number</div>
                                    <div class="copy-val" id="accNoVal"><?php echo h($accNo ?: '-'); ?></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-success copy-btn" data-copy="<?php echo h($accNo); ?>">Copy</button>
                            </div>

                            <?php if (!empty($bankName) || !empty($notes)): ?>
                                <hr>
                                <?php if (!empty($bankName)): ?>
                                    <div class="mini"><strong>Bank:</strong> <?php echo h($bankName); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($notes)): ?>
                                    <div class="mini"><strong>Notes:</strong> <?php echo h($notes); ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!$students): ?>
                    <div class="alert alert-warning">No enrolled students found yet.</div>
                <?php else: ?>

                    <?php foreach ($students as $st): ?>
                        <?php
                            $plan = (string)$st['payment_plan'];
                            $installments = plan_installments($plan);
                            $firstCode = first_installment_code($plan);
                            $sid = (string)$st['student_db_id'];

                            // Build dropdown options:
                            // - exclude first installment
                            // - exclude Approved installments
                            // - include reference + amount from DB row
                            $payOptions = [];
                            foreach ($installments as $code) {
                                if ($code === $firstCode) continue;

                                $row = $feeMap[$sid][$code] ?? null;
                                if (!$row) continue;

                                $status = normalize_status($row['status'] ?? 'pending');
                                if ($status === 'approved') continue;

                                $payOptions[] = [
                                    'code' => $code,
                                    'ref' => (string)($row['payment_reference'] ?? ''),
                                    'amount' => (string)($row['due_amount'] ?? '0'),
                                    'due' => installment_due_date($feesSettings, $code) ?? '',
                                ];
                            }

                            $isYearly = (strtolower($plan) === 'yearly');
                        ?>

                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><?php echo h($st['student_name']); ?></h6>
                                <div class="mini">
                                    Student ID: <?php echo h($st['student_id']); ?> |
                                    Plan: <strong><?php echo h($plan); ?></strong>
                                </div>
                            </div>

                            <div class="card-body">
                                <!-- ✅ PAY FEES -->
                                <div class="pay-card mb-3">
                                    <h6 class="font-weight-bold text-primary mb-2">
                                        <i class="fas fa-money-check-alt"></i> Pay Fees (Upload proof)
                                    </h6>

                                    <?php if ($isYearly): ?>
                                        <div class="alert alert-light mb-0">
                                            Yearly payment is handled during enrollment (no further installments).
                                        </div>

                                    <?php elseif (empty($payOptions)): ?>
                                        <div class="alert alert-light mb-0">
                                            No pending installments available to pay right now.
                                        </div>

                                    <?php else: ?>
                                        <form method="POST" enctype="multipart/form-data" class="payForm">
                                            <input type="hidden" name="pay_student_id" value="<?php echo (int)$sid; ?>">

                                            <div class="form-row">
                                                <div class="form-group col-md-4">
                                                    <label class="mini font-weight-bold mb-1">Select installment</label>
                                                    <select name="pay_installment" class="form-control payInstallment" required>
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($payOptions as $opt): ?>
                                                            <option
                                                                value="<?php echo h($opt['code']); ?>"
                                                                data-ref="<?php echo h($opt['ref']); ?>"
                                                                data-amount="<?php echo h($opt['amount']); ?>"
                                                                data-due="<?php echo h($opt['due']); ?>"
                                                            >
                                                                <?php echo h(installment_label($opt['code'])); ?>
                                                                (Due: <?php echo $opt['due'] ? h($opt['due']) : '-'; ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <!-- ✅ UPDATED DISPLAY: Amount shown (no copy), and only after selecting -->
                                                <div class="form-group col-md-5">
                                                    <label class="mini font-weight-bold mb-1">Payment details</label>

                                                    <div class="ref-box mb-2 payAmountWrap d-none">
                                                        <div class="mini text-muted mb-1">Amount</div>
                                                        <div class="h5 mb-0 font-weight-bold text-gray-900 payAmountText">$0.00</div>
                            
                                                    </div>

                                                    <div class="ref-box payRefWrap d-none">
                                                        <div class="mini text-muted mb-1">Reference (copy and use in bank transfer)</div>
                                                        <div class="input-group">
                                                            <div class="input-group-prepend">
                                                                <span class="input-group-text"><strong>Reference</strong></span>
                                                            </div>
                                                            <input type="text" class="form-control ref-input payRef" readonly value="">
                                                            <div class="input-group-append">
                                                                <button type="button" class="btn btn-success copyRefBtn">
                                                                    <i class="fas fa-copy"></i> Copy
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="form-group col-md-3">
                                                    <label class="mini font-weight-bold mb-1">Upload proof</label>
                                                    <input type="file" name="proof" class="form-control-file" required
                                                           accept=".jpg,.jpeg,.png,.pdf,image/*,application/pdf">
                                                    <button type="submit" class="btn btn-primary btn-block mt-2 uploadBtn" disabled>
                                                        <i class="fas fa-upload"></i> Submit Proof
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <!-- ✅ TABLE (due dates in header) -->
                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%">
                                        <thead class="thead-light">
                                        <tr>
                                            <?php foreach ($installments as $code): ?>
                                                <?php $dueHeader = installment_due_date($feesSettings, $code); ?>
                                                <th class="nowrap text-center">
                                                    <div><?php echo h(installment_label($code)); ?></div>
                                                    <div class="mini text-muted">Due: <?php echo $dueHeader ? h($dueHeader) : '-'; ?></div>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <tr>
                                            <?php foreach ($installments as $code): ?>
                                                <?php
                                                    $row = $feeMap[$sid][$code] ?? null;
                                                    $status = $row['status'] ?? 'Pending';
                                                    $proof = trim((string)($row['proof_path'] ?? ''));
                                                    $ref   = trim((string)($row['payment_reference'] ?? ''));
                                                    $amt   = (string)($row['due_amount'] ?? '');
                                                    $isFirst = ($code === $firstCode);
                                                ?>
                                                <td>
                                                    <div class="mb-1">
                                                        <span class="badge badge-<?php echo badge_class($status); ?>">
                                                            <?php echo h($status); ?>
                                                        </span>
                                                    </div>

                                                    <div class="mini mb-1"><strong>Amount:</strong> $<?php echo h(money_fmt($amt)); ?></div>

                                                    <?php if ($ref !== ''): ?>
                                                        <div class="mini mb-2"><strong>Ref:</strong> <?php echo h($ref); ?></div>
                                                    <?php else: ?>
                                                        <div class="mini text-muted mb-2">Ref: -</div>
                                                    <?php endif; ?>

                                                    <?php if ($proof !== ''): ?>
                                                        <?php $type = proof_type($proof); ?>
                                                        <div class="mb-1">
                                                            <a href="javascript:void(0)"
                                                               class="proof-thumb"
                                                               data-proof="<?php echo h($proof); ?>"
                                                               data-type="<?php echo h($type); ?>"
                                                               data-name="<?php echo h(basename($proof)); ?>">
                                                                <?php if ($type === 'img'): ?>
                                                                    <img class="thumb-img" src="<?php echo h($proof); ?>" alt="proof">
                                                                <?php else: ?>
                                                                    <span class="thumb-icon"><i class="fas fa-file-pdf"></i></span>
                                                                <?php endif; ?>
                                                                <span class="mini"><i class="fas fa-eye"></i> View Proof</span>
                                                            </a>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="mini text-muted">No proof uploaded</div>
                                                    <?php endif; ?>

                                                    <?php if ($isFirst): ?>
                                                        <div class="mini text-muted mt-2">First payment handled during enrollment.</div>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>

                    <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    async function copyText(text) {
        text = (text || '').trim();
        if (!text || text === '-') return false;

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (e) {}

        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, 99999);
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    }

    function toast(msg, ok=true) {
        Swal.fire({ icon: ok ? 'success' : 'error', title: msg, showConfirmButton:false, timer: 900 });
    }

    // Copy buttons (bank)
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', async function () {
            const t = this.getAttribute('data-copy') || '';
            const ok = await copyText(t);
            toast(ok ? 'Copied!' : 'Nothing to copy', ok);
        });
    });

    // Copy all bank details
    document.getElementById('copyAllBank')?.addEventListener('click', async () => {
        const accName = document.getElementById('accNameVal')?.textContent || '';
        const bsb = document.getElementById('bsbVal')?.textContent || '';
        const accNo = document.getElementById('accNoVal')?.textContent || '';

        const all = `Account Name: ${accName}\nBSB: ${bsb}\nAccount Number: ${accNo}`;
        const ok = await copyText(all);
        toast(ok ? 'Bank details copied!' : 'Nothing to copy', ok);
    });

    // ✅ Pay form: show Amount (no copy) + Reference only after selecting installment
    document.querySelectorAll('.payForm').forEach(form => {
        const sel = form.querySelector('.payInstallment');

        const refInput = form.querySelector('.payRef');
        const uploadBtn = form.querySelector('.uploadBtn');

        const amountWrap = form.querySelector('.payAmountWrap');
        const amountText = form.querySelector('.payAmountText');

        const refWrap = form.querySelector('.payRefWrap');
        const copyRefBtn = form.querySelector('.copyRefBtn');

        function updateFields() {
            const opt = sel?.selectedOptions?.[0];
            const code = sel?.value || '';

            if (!code || !opt) {
                refInput.value = '';
                amountText.textContent = '$0.00';

                amountWrap?.classList.add('d-none');
                refWrap?.classList.add('d-none');

                uploadBtn.disabled = true;
                return;
            }

            const ref = opt.getAttribute('data-ref') || '';
            const amt = opt.getAttribute('data-amount') || '0';

            refInput.value = ref;
            amountText.textContent = '$' + (parseFloat(amt || '0').toFixed(2));

            amountWrap?.classList.remove('d-none');
            refWrap?.classList.remove('d-none');

            uploadBtn.disabled = false;
        }

        sel?.addEventListener('change', updateFields);
        updateFields();

        copyRefBtn?.addEventListener('click', async () => {
            const ok = await copyText(refInput?.value || '');
            toast(ok ? 'Reference copied!' : 'Nothing to copy', ok);
        });
    });

    // Proof modal
    function openProofModal(path, type, filename) {
        filename = filename || 'proof';

        if (type === 'img') {
            let scale = 1;

            Swal.fire({
                title: 'Payment Proof',
                html: `
                    <div class="proof-stage">
                        <img id="swalProofImg" class="proof-img" src="${path}" alt="Proof" />
                    </div>
                    <div class="swal-toolbar">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="zoomInBtn"><i class="fas fa-search-plus"></i> Zoom In</button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="zoomOutBtn"><i class="fas fa-search-minus"></i> Zoom Out</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="resetZoomBtn"><i class="fas fa-undo"></i> Reset</button>
                        <a class="btn btn-sm btn-primary" href="${path}" target="_blank"><i class="fas fa-external-link-alt"></i> Open</a>
                    </div>
                `,
                showCloseButton: true,
                showConfirmButton: false,
                didOpen: () => {
                    const img = document.getElementById('swalProofImg');
                    if (!img) return;

                    img.onload = () => {
                        img.style.width = img.naturalWidth + 'px';
                        img.style.height = 'auto';
                        applyScale();
                    };

                    function applyScale() { img.style.transform = `scale(${scale})`; }

                    document.getElementById('zoomInBtn')?.addEventListener('click', () => {
                        scale = Math.min(5, +(scale + 0.25).toFixed(2));
                        applyScale();
                    });
                    document.getElementById('zoomOutBtn')?.addEventListener('click', () => {
                        scale = Math.max(0.25, +(scale - 0.25).toFixed(2));
                        applyScale();
                    });
                    document.getElementById('resetZoomBtn')?.addEventListener('click', () => {
                        scale = 1; applyScale();
                    });
                }
            });
        } else {
            Swal.fire({
                title: 'Payment Proof (PDF)',
                html: `
                    <iframe class="proof-frame" src="${path}#toolbar=1&navpanes=0&scrollbar=1"></iframe>
                    <div class="swal-toolbar">
                        <a class="btn btn-sm btn-primary" href="${path}" target="_blank"><i class="fas fa-external-link-alt"></i> Open PDF</a>
                    </div>
                `,
                showCloseButton: true,
                showConfirmButton: false
            });
        }
    }

    document.querySelectorAll('.proof-thumb').forEach(el => {
        el.addEventListener('click', function () {
            const path = this.getAttribute('data-proof');
            const type = this.getAttribute('data-type');
            const name = this.getAttribute('data-name') || 'proof';
            if (!path) return;
            openProofModal(path, type, name);
        });
    });

});
</script>

</body>
</html>
