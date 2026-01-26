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
$reload = false;

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
    if ($planType === 'Term-wise') return 'TERM1';
    if ($planType === 'Half-yearly') return 'HALF1';
    return 'YEARLY';
}

function installment_label(string $code): string {
    return match ($code) {
        'TERM1' => 'Term 1',
        'TERM2' => 'Term 2',
        'TERM3' => 'Term 3',
        'TERM4' => 'Term 4',
        'HALF1' => 'Half 1',
        'HALF2' => 'Half 2',
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

function default_due_amount(string $planType, string $code): float {
    if ($planType === 'Term-wise') return 65.00;
    if ($planType === 'Half-yearly') return 125.00;
    if ($planType === 'Yearly') return 250.00;
    return 0.00;
}

/**
 * Due date rules requested:
 * TERM1 = HALF1 = YEARLY -> due_term1
 * TERM3 = HALF2 -> due_term3
 * TERM2 -> due_term2
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

function pretty_date(?string $d): string {
    if (!$d) return '-';
    return htmlspecialchars($d);
}

/**
 * Parent-side ensure fee rows exist for THEIR students.
 * Sync FIRST installment from enrollment proof + enrollment approval.
 */
function ensure_fee_rows_for_student(PDO $pdo, array $student): void {
    $studentDbId = (string)$student['student_db_id'];
    $planType = (string)$student['payment_plan'];
    $approval = strtolower(trim((string)($student['approval_status'] ?? '')));
    $enrollProof = trim((string)($student['payment_proof'] ?? ''));

    $installments = plan_installments($planType);
    if (!$installments) return;

    $firstCode = first_installment_code($planType);

    foreach ($installments as $code) {
        $stmt = $pdo->prepare("SELECT * FROM fees_payments WHERE student_id = :sid AND installment_code = :code LIMIT 1");
        $stmt->execute([':sid' => $studentDbId, ':code' => $code]);
        $row = $stmt->fetch();

        if (!$row) {
            $due = default_due_amount($planType, $code);

            $status = 'Pending';
            $proof = null;

            if ($code === $firstCode && $enrollProof !== '') {
                $proof = $enrollProof;
                if ($approval === 'approved') $status = 'Approved';
            }

            $ins = $pdo->prepare("
                INSERT INTO fees_payments (student_id, plan_type, installment_code, due_amount, status, proof_path)
                VALUES (:sid, :plan, :code, :due, :st, :proof)
            ");
            $ins->execute([
                ':sid' => $studentDbId,
                ':plan' => $planType,
                ':code' => $code,
                ':due' => $due,
                ':st' => $status,
                ':proof' => $proof
            ]);
        } else {
            if ($code === $firstCode) {
                $curProof = trim((string)($row['proof_path'] ?? ''));
                $curStatus = strtolower((string)($row['status'] ?? 'pending'));

                $needProofSync = ($curProof === '' && $enrollProof !== '');
                $needApproveSync = ($curStatus !== 'approved' && $approval === 'approved' && $enrollProof !== '');

                if ($needProofSync || $needApproveSync) {
                    $newStatus = $needApproveSync ? 'Approved' : ($row['status'] ?? 'Pending');

                    $upd = $pdo->prepare("
                        UPDATE fees_payments
                        SET proof_path = CASE WHEN (proof_path IS NULL OR proof_path = '') THEN :proof ELSE proof_path END,
                            status = :st
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':proof' => $enrollProof,
                        ':st' => $newStatus,
                        ':id' => (int)$row['id']
                    ]);
                }
            }
        }
    }
}

// ---------------- GET LOGGED-IN PARENT ----------------
$sessionEmail = strtolower(trim($_SESSION['username'] ?? ''));
if ($sessionEmail === '') die("Session username missing. Please log out and log in again.");

$stmtParent = $pdo->prepare("SELECT id, full_name, email FROM parents WHERE LOWER(email)=:e LIMIT 1");
$stmtParent->execute([':e' => $sessionEmail]);
$parent = $stmtParent->fetch();
if (!$parent) die("No parent record found for email: <strong>" . htmlspecialchars($sessionEmail) . "</strong>");

$parentId = (int)$parent['id'];

// ---------------- LOAD FEES SETTINGS (BANK + DUE DATES) ----------------
$stmtSet = $pdo->query("SELECT * FROM fees_settings WHERE id = 1 LIMIT 1");
$feesSettings = $stmtSet->fetch() ?: [];

// ---------------- HANDLE UPLOAD ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fee_id'])) {
    try {
        $feeId = (int)($_POST['fee_id'] ?? 0);
        if ($feeId <= 0) throw new Exception("Invalid fee record.");

        $stmt = $pdo->prepare("
            SELECT fp.*, s.parentId
            FROM fees_payments fp
            JOIN students s ON s.id = fp.student_id
            WHERE fp.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $feeId]);
        $fee = $stmt->fetch();

        if (!$fee) throw new Exception("Fee record not found.");
        if ((int)$fee['parentId'] !== $parentId) throw new Exception("You don't have permission to upload for this student.");

        $planType = (string)$fee['plan_type'];
        $code = (string)$fee['installment_code'];
        $firstCode = first_installment_code($planType);

        if ($code === $firstCode) {
            throw new Exception("First payment is handled during enrollment. You cannot upload proof for this installment.");
        }

        $status = strtolower((string)$fee['status']);
        if ($status === 'approved') throw new Exception("This installment is already Approved.");

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

        $newRel = $relativeDir . time() . "_fee_" . $feeId . "_" . $safeBase . "." . $ext;
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
        $upd->execute([':p' => $newRel, ':id' => $feeId]);

        $message = "Proof uploaded successfully. Waiting for admin verification.";
        $success = true;
        $reload = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reload = false;
    }
}

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
    ensure_fee_rows_for_student($pdo, $st);
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
        $feeMap[(string)$fr['student_id']][(string)$fr['installment_code']] = $fr;
    }
}

function proof_type(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return ($ext === 'pdf') ? 'pdf' : 'img';
}
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
        .proof-link { font-size:12px; }
        .upload-box { background:#f8f9fc; border:1px solid #e3e6f0; border-radius:10px; padding:12px; }

        .bank-box { background:#f8f9fc; border:1px solid #e3e6f0; padding:15px; border-radius:10px; }
        .due-pill { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#1b4fd6; font-size:11px; font-weight:700; }

        /* Proof UI */
        .proof-thumb {
            display:inline-flex;
            align-items:center;
            gap:8px;
            cursor:pointer;
            text-decoration:none;
        }
        .thumb-img {
            width:42px;
            height:42px;
            object-fit:cover;
            border-radius:8px;
            border:1px solid #e3e6f0;
            background:#fff;
        }
        .thumb-icon {
            width:42px;
            height:42px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:8px;
            border:1px solid #e3e6f0;
            background:#fff;
            color:#d93025;
            font-size:18px;
        }

        /* SweetAlert modal sizing */
        .swal2-popup { width: 920px !important; max-width: 96vw !important; }
        .proof-frame { width: 100%; height: 70vh; border: 1px solid #e3e6f0; border-radius: 12px; }

        .proof-stage {
            width: 100%;
            max-height: 72vh;
            overflow: auto;
            border: 1px solid #e3e6f0;
            border-radius: 12px;
            padding: 10px;
            background: #fafbff;
        }
        .proof-img {
            display:block;
            transform-origin: top left;
            border-radius: 10px;
            border: 1px solid #e3e6f0;
            background:#fff;
        }
        .swal-toolbar {
            display:flex;
            gap:8px;
            justify-content:center;
            flex-wrap:wrap;
            margin-top:10px;
        }
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

                <div class="alert alert-info shadow-sm">
                    <strong>Note:</strong>
                    <ul class="mb-0">
                        <li><strong>First payment</strong> is confirmed during enrollment based on the plan selected.</li>
                        <li>Upload proofs only for the <strong>remaining installments</strong>.</li>
                        <li>Admin will verify and mark them as <strong>Approved</strong>.</li>
                    </ul>
                </div>

                <!-- Bank Details from Admin Settings -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-university"></i> Bank Details</h6>
                        <span class="mini">Updated by Admin</span>
                    </div>
                    <div class="card-body">
                        <div class="bank-box">
                            <p class="mb-1"><strong>Bank:</strong> <?php echo htmlspecialchars($feesSettings['bank_name'] ?? '-'); ?></p>
                            <p class="mb-1"><strong>Account Name:</strong> <?php echo htmlspecialchars($feesSettings['account_name'] ?? '-'); ?></p>
                            <p class="mb-1"><strong>BSB:</strong> <?php echo htmlspecialchars($feesSettings['bsb'] ?? '-'); ?></p>
                            <p class="mb-1"><strong>Account Number:</strong> <?php echo htmlspecialchars($feesSettings['account_number'] ?? '-'); ?></p>
                            <?php if (!empty($feesSettings['bank_notes'])): ?>
                                <hr>
                                <p class="mb-0 mini"><?php echo htmlspecialchars($feesSettings['bank_notes']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
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
                                timer: ok ? 1800 : 6000
                            }).then(()=> { if (ok && reload) window.location.href = 'parentFees.php'; });
                        }
                    });
                </script>

                <?php if (!$students): ?>
                    <div class="alert alert-warning">No enrolled students found yet.</div>
                <?php else: ?>
                    <?php foreach ($students as $st): ?>
                        <?php
                        $plan = (string)$st['payment_plan'];
                        $installments = plan_installments($plan);
                        $firstCode = first_installment_code($plan);
                        $sid = (string)$st['student_db_id'];
                        ?>
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><?php echo htmlspecialchars($st['student_name']); ?></h6>
                                <div class="mini">
                                    Student ID: <?php echo htmlspecialchars($st['student_id']); ?> |
                                    Plan: <strong><?php echo htmlspecialchars($plan); ?></strong> |
                                    Enrollment:
                                    <span class="badge badge-<?php echo badge_class($st['approval_status'] ?? 'Pending'); ?>">
                                        <?php echo htmlspecialchars($st['approval_status'] ?? 'Pending'); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="card-body">
                                <?php if (!$installments): ?>
                                    <div class="alert alert-warning mb-0">Invalid payment plan.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered" width="100%">
                                            <thead class="thead-light">
                                            <tr>
                                                <?php foreach ($installments as $code): ?>
                                                    <th class="nowrap"><?php echo htmlspecialchars(installment_label($code)); ?></th>
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
                                                    $feeId = (int)($row['id'] ?? 0);
                                                    $isFirst = ($code === $firstCode);
                                                    $due = installment_due_date($feesSettings, $code);
                                                    ?>
                                                    <td>
                                                        <div class="mb-2">
                                                            <span class="badge badge-<?php echo badge_class($status); ?>">
                                                                <?php echo htmlspecialchars($status); ?>
                                                            </span>
                                                        </div>

                                                        <div class="mini mb-2">
                                                            <span class="due-pill">Due: <?php echo pretty_date($due); ?></span>
                                                        </div>

                                                        <?php if ($proof !== ''): ?>
                                                            <?php $type = proof_type($proof); ?>
                                                            <div class="proof-link mb-2">
                                                                <a href="javascript:void(0)"
                                                                   class="proof-thumb"
                                                                   data-proof="<?php echo htmlspecialchars($proof); ?>"
                                                                   data-type="<?php echo htmlspecialchars($type); ?>"
                                                                   data-name="<?php echo htmlspecialchars(basename($proof)); ?>">
                                                                    <?php if ($type === 'img'): ?>
                                                                        <img class="thumb-img" src="<?php echo htmlspecialchars($proof); ?>" alt="proof">
                                                                    <?php else: ?>
                                                                        <span class="thumb-icon"><i class="fas fa-file-pdf"></i></span>
                                                                    <?php endif; ?>
                                                                    <span><i class="fas fa-eye"></i> View Proof</span>
                                                                </a>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="mini text-muted mb-2">No proof uploaded</div>
                                                        <?php endif; ?>

                                                        <?php if (!$row): ?>
                                                            <div class="mini text-muted">Fee record missing.</div>

                                                        <?php elseif ($isFirst): ?>
                                                            <div class="mini text-muted">First payment handled during enrollment.</div>

                                                        <?php else: ?>
                                                            <?php if (strtolower($status) === 'approved'): ?>
                                                                <div class="mini text-success"><i class="fas fa-check-circle"></i> Approved</div>
                                                            <?php else: ?>
                                                                <div class="upload-box">
                                                                    <form method="POST" enctype="multipart/form-data">
                                                                        <input type="hidden" name="fee_id" value="<?php echo (int)$feeId; ?>">
                                                                        <div class="form-group mb-2">
                                                                            <label class="mini mb-1">Upload proof (JPG/PNG/PDF, max 5MB)</label>
                                                                            <input type="file" name="proof" class="form-control-file" required
                                                                                   accept=".jpg,.jpeg,.png,.pdf,image/*,application/pdf">
                                                                        </div>
                                                                        <button type="submit" class="btn btn-sm btn-primary">
                                                                            <i class="fas fa-upload"></i> Upload
                                                                        </button>
                                                                    </form>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
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
                        <a class="btn btn-sm btn-success" href="${path}" download="${filename}">
                            <i class="fas fa-download"></i> Download
                        </a>
                        <a class="btn btn-sm btn-primary" href="${path}" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Open
                        </a>
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

                    function applyScale() {
                        img.style.transform = `scale(${scale})`;
                    }

                    document.getElementById('zoomInBtn')?.addEventListener('click', () => {
                        scale = Math.min(5, +(scale + 0.25).toFixed(2));
                        applyScale();
                    });

                    document.getElementById('zoomOutBtn')?.addEventListener('click', () => {
                        scale = Math.max(0.25, +(scale - 0.25).toFixed(2));
                        applyScale();
                    });

                    document.getElementById('resetZoomBtn')?.addEventListener('click', () => {
                        scale = 1;
                        applyScale();
                    });
                }
            });

        } else {
            Swal.fire({
                title: 'Payment Proof (PDF)',
                html: `
                    <iframe class="proof-frame" src="${path}#toolbar=1&navpanes=0&scrollbar=1"></iframe>
                    <div class="swal-toolbar">
                        <a class="btn btn-sm btn-success" href="${path}" download="${filename}">
                            <i class="fas fa-download"></i> Download
                        </a>
                        <a class="btn btn-sm btn-primary" href="${path}" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Open PDF
                        </a>
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
