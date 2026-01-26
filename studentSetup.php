<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'parent') {
    die("This page is only for Parents to add student enrollments.");
}

$message = "";
$reloadPage = false;
$success = false;
$focusField = "";

// ---------------- DB CONNECTION ----------------
try {
    $pdo = new PDO(
        "mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4",
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
function plan_amount(string $plan): float {
    $plan = strtolower(trim($plan));
    if ($plan === 'term-wise') return 65.00;
    if ($plan === 'half-yearly') return 125.00;
    if ($plan === 'yearly') return 250.00;
    return 0.00;
}

function clean_ref_text(string $text): string {
    $text = trim($text);
    $text = preg_replace('/\s+/', '_', $text);
    $text = preg_replace('/[^a-zA-Z0-9_]/', '', $text);
    return $text;
}

function generateNextStudentId(PDO $pdo): string {
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(student_id, 5) AS UNSIGNED)) AS max_num
        FROM students
        WHERE student_id LIKE 'BLCS%'
    ");
    $row = $stmt->fetch();
    $next = ((int)($row['max_num'] ?? 0)) + 1;
    return 'BLCS' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
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

// ---------------- AUTO VALUES ----------------
$autoRegDate = date('Y-m-d');
$autoApprovalStatus = "Pending";

// ---------------- GET LOGGED-IN PARENT (EMAIL-AS-USERNAME) ----------------
$sessionLoginEmail = strtolower(trim($_SESSION['username'] ?? ''));
if ($sessionLoginEmail === '') {
    die("Session username missing. Please log out and log in again.");
}

$stmtParent = $pdo->prepare("
    SELECT id, full_name, email
    FROM parents
    WHERE LOWER(email) = :e
    LIMIT 1
");
$stmtParent->execute([':e' => $sessionLoginEmail]);
$parentRow = $stmtParent->fetch();

if (!$parentRow) {
    die(
        "No matching parent record found for email: <strong>" . htmlspecialchars($sessionLoginEmail) . "</strong><br><br>" .
        "Fix: Ensure the parent's <strong>email</strong> in the parents table matches the login email exactly."
    );
}

$autoParentId = (int)$parentRow['id'];
$autoParentLabel = trim(($parentRow['full_name'] ?? '') . ' - ' . ($parentRow['email'] ?? ''));

// ---------------- FORM STATE (STICKY VALUES) ----------------
$old = [
    'student_name'  => '',
    'dob'           => '',
    'gender'        => '',
    'medical_issue' => '',
    'class_option'  => '',
    'payment_plan'  => '',
];

$existing_payment_proof = null;

// ---------------- EDIT (optional) ----------------
if (isset($_GET['edit'])) {
    try {
        $id = (int)$_GET['edit'];

        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = :id AND parentId = :pid");
        $stmt->execute([':id' => $id, ':pid' => $autoParentId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Student not found or you don't have permission to edit this record.");
        }

        if (strtolower($row['approval_status'] ?? '') === 'approved') {
            throw new Exception("This enrollment is already Approved. Please contact admin for changes.");
        }

        $old['student_name']  = $row['student_name'] ?? '';
        $old['dob']           = $row['dob'] ?? '';
        $old['gender']        = $row['gender'] ?? '';
        $old['medical_issue'] = $row['medical_issue'] ?? '';
        $old['class_option']  = $row['class_option'] ?? '';
        $old['payment_plan']  = $row['payment_plan'] ?? '';
        $existing_payment_proof = $row['payment_proof'] ?? null;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
    }
}

// ---------------- SAVE (ADD / UPDATE) ----------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $student_name  = trim($_POST['student_name'] ?? '');
        $dob           = trim($_POST['dob'] ?? '');
        $gender        = trim($_POST['gender'] ?? '');
        $medical_issue = trim($_POST['medical_issue'] ?? '');
        $class_option  = trim($_POST['class_option'] ?? '');
        $payment_plan  = trim($_POST['payment_plan'] ?? '');

        // sticky
        $old['student_name']  = $student_name;
        $old['dob']           = $dob;
        $old['gender']        = $gender;
        $old['medical_issue'] = $medical_issue;
        $old['class_option']  = $class_option;
        $old['payment_plan']  = $payment_plan;

        // Validate
        if ($student_name === "") { $focusField = "student_name"; throw new Exception("Student Name is required."); }
        if ($dob === "")          { $focusField = "dob";          throw new Exception("DOB is required."); }
        if ($gender === "")       { $focusField = "gender";       throw new Exception("Gender is required."); }
        if ($class_option === "") { $focusField = "class_option"; throw new Exception("Please select a venue/class session."); }
        if ($payment_plan === "") { $focusField = "payment_plan"; throw new Exception("Please select a payment plan."); }

        $validClass = [
            "Morning Class (Woden Campus)",
            "Afternoon Class (Belconnen Campus)",
            "Both"
        ];
        if (!in_array($class_option, $validClass, true)) {
            $focusField = "class_option";
            throw new Exception("Invalid class selection.");
        }

        $validPlan = ["Term-wise", "Half-yearly", "Yearly"];
        if (!in_array($payment_plan, $validPlan, true)) {
            $focusField = "payment_plan";
            throw new Exception("Invalid payment plan selection.");
        }

        // Amount + reference
        $amount = plan_amount($payment_plan);
        if ($amount <= 0) throw new Exception("Payment amount could not be calculated.");

        $ref = clean_ref_text($student_name) . "_" . clean_ref_text($payment_plan) . "_" . clean_ref_text((string)(int)$amount);

        $isEdit = isset($_GET['edit']) && ((int)$_GET['edit'] > 0);
        $proofPath = $existing_payment_proof ?: null;

        // Require proof for NEW enrollments
        if (!$isEdit) {
            $err = $_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) {
                $focusField = "payment_proof";
                throw new Exception("Payment proof is required. Upload error: " . upload_error_message((int)$err));
            }
        }

        // Upload (if provided)
        if (isset($_FILES['payment_proof']) && ($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $allowed = ['jpg','jpeg','png','pdf'];
            $origName = $_FILES['payment_proof']['name'] ?? '';
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed, true)) {
                $focusField = "payment_proof";
                throw new Exception("Invalid file type. Only JPG, PNG or PDF allowed.");
            }

            $size = (int)($_FILES['payment_proof']['size'] ?? 0);
            if ($size > 5 * 1024 * 1024) {
                $focusField = "payment_proof";
                throw new Exception("File too large. Maximum 5MB allowed.");
            }

            $relativeDir = "uploads/payments/";
            $absDir = __DIR__ . "/" . $relativeDir;
            safe_mkdir($absDir);

            $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
            $safeBase = substr($safeBase, 0, 80);

            $newRel = $relativeDir . time() . "_" . $safeBase . "." . $ext;
            $newAbs = __DIR__ . "/" . $newRel;

            if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $newAbs)) {
                throw new Exception("Failed to upload payment proof (check folder permissions).");
            }

            $proofPath = $newRel;
        }

        $parentId = $autoParentId;

        if ($isEdit) {
            $id = (int)$_GET['edit'];

            $stmtCheck = $pdo->prepare("SELECT approval_status, payment_proof FROM students WHERE id = :id AND parentId = :pid LIMIT 1");
            $stmtCheck->execute([':id' => $id, ':pid' => $parentId]);
            $current = $stmtCheck->fetch();

            if (!$current) throw new Exception("You don't have permission to update this record.");
            if (strtolower($current['approval_status'] ?? '') === 'approved') {
                throw new Exception("This enrollment is already Approved. Please contact admin for changes.");
            }

            if (!$proofPath && !empty($current['payment_proof'])) {
                $proofPath = $current['payment_proof'];
            }

            $stmt = $pdo->prepare("
                UPDATE students
                SET student_name = :student_name,
                    dob = :dob,
                    gender = :gender,
                    medical_issue = :medical_issue,
                    class_option = :class_option,
                    payment_plan = :payment_plan,
                    payment_amount = :payment_amount,
                    payment_reference = :payment_reference,
                    payment_proof = :payment_proof
                WHERE id = :id AND parentId = :parentId
            ");
            $stmt->execute([
                ':student_name' => $student_name,
                ':dob' => $dob,
                ':gender' => $gender,
                ':medical_issue' => ($medical_issue === "" ? null : $medical_issue),
                ':class_option' => $class_option,
                ':payment_plan' => $payment_plan,
                ':payment_amount' => $amount,
                ':payment_reference' => $ref,
                ':payment_proof' => $proofPath,
                ':id' => $id,
                ':parentId' => $parentId
            ]);

            $message = "Enrollment updated successfully.";
            $success = true;
            $reloadPage = true;

        } else {
            $student_id = generateNextStudentId($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO students
                    (student_id, student_name, dob, gender, medical_issue, class_option,
                     payment_plan, payment_amount, payment_reference, payment_proof,
                     registration_date, approval_status, parentId)
                VALUES
                    (:student_id, :student_name, :dob, :gender, :medical_issue, :class_option,
                     :payment_plan, :payment_amount, :payment_reference, :payment_proof,
                     :registration_date, :approval_status, :parentId)
            ");
            $stmt->execute([
                ':student_id' => $student_id,
                ':student_name' => $student_name,
                ':dob' => $dob,
                ':gender' => $gender,
                ':medical_issue' => ($medical_issue === "" ? null : $medical_issue),
                ':class_option' => $class_option,
                ':payment_plan' => $payment_plan,
                ':payment_amount' => $amount,
                ':payment_reference' => $ref,
                ':payment_proof' => $proofPath,
                ':registration_date' => $autoRegDate,
                ':approval_status' => $autoApprovalStatus,
                ':parentId' => $parentId
            ]);

            $message = "Enrollment submitted successfully. (Pending approval)";
            $success = true;
            $reloadPage = true;
        }

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reloadPage = false;
    }
}

// ---------------- DELETE ----------------
if (isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];

        $stmtCheck = $pdo->prepare("SELECT approval_status FROM students WHERE id = :id AND parentId = :pid LIMIT 1");
        $stmtCheck->execute([':id' => $id, ':pid' => $autoParentId]);
        $current = $stmtCheck->fetch();

        if (!$current) throw new Exception("You don't have permission to delete this record.");
        if (strtolower($current['approval_status'] ?? '') === 'approved') {
            throw new Exception("This enrollment is already Approved. Please contact admin for changes.");
        }

        $stmt = $pdo->prepare("DELETE FROM students WHERE id = :id AND parentId = :pid");
        $stmt->execute([':id' => $id, ':pid' => $autoParentId]);

        $message = "Enrollment deleted successfully.";
        $success = true;
        $reloadPage = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reloadPage = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo isset($_GET['edit']) ? "Edit Enrollment" : "Add Student Enrollment"; ?></title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .bank-box { background:#f8f9fc; border:1px solid #e3e6f0; padding:15px; border-radius:8px; }
        .ref-box { background:#fff; border:1px dashed #bbb; padding:10px; border-radius:6px; }
        .small-help { font-size: 12px; color:#6c757d; }
        .preview-box img { max-width: 100%; border:1px solid #e3e6f0; border-radius:10px; padding:6px; }
        .step-badge{
            display:inline-block;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            background:#eaf2ff;
            color:#1b4fd6;
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
                <h1 class="h3 mb-2 text-gray-800"><?php echo isset($_GET['edit']) ? "Edit Enrollment" : "Add Student Enrollment"; ?></h1>

                <div class="alert alert-info shadow-sm">
                    <strong>Important:</strong>
                    <ul class="mb-0">
                        <li><strong>Parent</strong> is linked to your login: <strong><?php echo htmlspecialchars($autoParentLabel); ?></strong></li>
                        <li><strong>Status</strong> stays <strong>Pending</strong> until admin verifies payment & approves enrollment.</li>
                        <li>Please follow the steps below: fill details → pay → upload proof → submit.</li>
                    </ul>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const msg = <?php echo json_encode($message); ?>;
                        const isSuccess = <?php echo $success ? 'true' : 'false'; ?>;
                        const reload = <?php echo $reloadPage ? 'true' : 'false'; ?>;
                        const focusField = <?php echo json_encode($focusField); ?>;

                        if (msg) {
                            Swal.fire({
                                icon: isSuccess ? 'success' : 'error',
                                title: msg,
                                showConfirmButton: true,
                                timer: isSuccess ? 1800 : 6000
                            }).then(() => {
                                if (reload && isSuccess) {
                                    window.location.href = 'index-admin.php';
                                    return;
                                }
                                if (!isSuccess && focusField) {
                                    const el = document.querySelector(`[name="${focusField}"]`) || document.getElementById(focusField);
                                    if (el) el.focus();
                                }
                            });
                        }
                    });
                </script>

                <!-- ✅ ONE COLUMN, STEP BY STEP -->
                <div class="row">
                    <div class="col-lg-12">

                        <form action="studentSetup.php<?php echo isset($_GET['edit']) ? '?edit='.(int)$_GET['edit'] : ''; ?>"
                              method="POST" enctype="multipart/form-data" id="enrollmentForm">

                            <!-- STEP 1 -->
                            <div class="card shadow mb-4" id="step1">
                                <div class="card-header py-3 d-flex align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-primary">Step 1 — Student & Class Details</h6>
                                    <span class="step-badge">Step 1 of 3</span>
                                </div>
                                <div class="card-body">

                                    <div class="form-group">
                                        <label>Student Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="student_name" id="student_name"
                                               value="<?php echo htmlspecialchars($old['student_name']); ?>" required
                                               placeholder="Enter student full name">
                                    </div>

                                    <div class="form-group">
                                        <label>DOB <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="dob" id="dob"
                                               value="<?php echo htmlspecialchars($old['dob']); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Gender <span class="text-danger">*</span></label>
                                        <select class="form-control" name="gender" id="gender" required>
                                            <option value="">-- Select --</option>
                                            <?php
                                            $genders = ["Male", "Female", "Other"];
                                            foreach ($genders as $g) {
                                                $selected = ($old['gender'] === $g) ? "selected" : "";
                                                echo "<option value='".htmlspecialchars($g)."' $selected>".htmlspecialchars($g)."</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Any Medical Issue (Optional)</label>
                                        <textarea class="form-control" name="medical_issue" rows="3"
                                                  placeholder="Optional (e.g., allergies)."><?php echo htmlspecialchars($old['medical_issue']); ?></textarea>
                                    </div>

                                    <hr>

                                    <div class="form-group">
                                        <label>Select Venue <span class="text-danger">*</span></label>
                                        <select class="form-control" name="class_option" id="class_option" required>
                                            <option value="">-- Select --</option>
                                            <option value="Morning Class (Woden Campus)" <?php echo ($old['class_option']==="Morning Class (Woden Campus)")?'selected':''; ?>>
                                                Morning Class (Woden Campus) - Alfred Deakin High School - 10am to 12pm
                                            </option>
                                            <option value="Afternoon Class (Belconnen Campus)" <?php echo ($old['class_option']==="Afternoon Class (Belconnen Campus)")?'selected':''; ?>>
                                                Afternoon Class (Belconnen Campus) - Hawker College - 1pm to 3pm
                                            </option>
                                            <option value="Both" <?php echo ($old['class_option']==="Both")?'selected':''; ?>>Both</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Payment Plan <span class="text-danger">*</span></label>
                                        <select class="form-control" name="payment_plan" id="payment_plan" required>
                                            <option value="">-- Select --</option>
                                            <option value="Term-wise" <?php echo ($old['payment_plan'] === "Term-wise") ? 'selected' : ''; ?>>Term-wise ($65)</option>
                                            <option value="Half-yearly" <?php echo ($old['payment_plan'] === "Half-yearly") ? 'selected' : ''; ?>>Half-yearly ($125)</option>
                                            <option value="Yearly" <?php echo ($old['payment_plan'] === "Yearly") ? 'selected' : ''; ?>>Yearly ($250)</option>
                                        </select>
                                        <div class="small-help mt-1">Amount is calculated automatically based on your payment plan.</div>
                                    </div>

                                    <button type="button" class="btn btn-outline-primary" id="goToPayment">
                                        Continue to Payment (Step 2)
                                    </button>
                                </div>
                            </div>

                            <!-- STEP 2 -->
                            <div class="card shadow mb-4" id="step2">
                                <div class="card-header py-3 d-flex align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-primary">Step 2 — Make Payment & Use Reference</h6>
                                    <span class="step-badge">Step 2 of 3</span>
                                </div>
                                <div class="card-body">

                                    <div class="alert alert-info mb-4">
                                        Please pay the <strong>calculated amount</strong> and use the <strong>payment reference</strong> exactly.
                                    </div>

                                    <div class="mb-4">
                                        <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-university"></i> Bank Details</h6>
                                        <div class="bank-box">
                                            <p class="mb-1"><strong>Account Name:</strong> Bhutanese Centre Canberra</p>
                                            <p class="mb-1"><strong>BSB:</strong> 000-000</p>
                                            <p class="mb-1"><strong>Account Number:</strong> 00000000</p>
                                            <hr>
                                            <p class="mb-0 small-help">
                                                Enrollment will be confirmed only after payment verification.
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-dollar-sign"></i> Calculated Amount</h6>
                                        <div class="alert alert-light mb-0">
                                            <strong>Amount to pay:</strong> <span id="payAmount">$0</span>
                                        </div>
                                    </div>

                                    <div class="mb-2">
                                        <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-receipt"></i> Payment Reference</h6>
                                        <div class="ref-box">
                                            <div class="small-help mb-2">Copy and paste this reference into your bank transfer:</div>
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="payRef" readonly value="">
                                                <div class="input-group-append">
                                                    <button type="button" class="btn btn-success" id="copyBtn">Copy</button>
                                                </div>
                                            </div>
                                            <div class="small-help mt-2">Format: <code>ChildName_PLAN_Amount</code></div>
                                        </div>
                                    </div>

                                    <button type="button" class="btn btn-outline-primary mt-4" id="goToUpload">
                                        Continue to Upload Proof (Step 3)
                                    </button>
                                </div>
                            </div>

                            <!-- STEP 3 -->
                            <div class="card shadow mb-4" id="step3">
                                <div class="card-header py-3 d-flex align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-primary">Step 3 — Upload Proof & Submit Enrollment</h6>
                                    <span class="step-badge">Step 3 of 3</span>
                                </div>
                                <div class="card-body">

                                    <div class="form-group">
                                        <label>
                                            Upload Payment Screenshot / Proof
                                            <?php echo (!isset($_GET['edit'])) ? '<span class="text-danger">*</span>' : ''; ?>
                                        </label>

                                        <div class="custom-file">
                                            <input type="file"
                                                   class="custom-file-input"
                                                   name="payment_proof"
                                                   id="payment_proof"
                                                   accept=".jpg,.jpeg,.png,.pdf,image/*,application/pdf"
                                                <?php echo (!isset($_GET['edit'])) ? 'required' : ''; ?>>
                                            <label class="custom-file-label" for="payment_proof">Choose image or PDF...</label>
                                        </div>

                                        <?php if (!empty($existing_payment_proof)): ?>
                                            <div class="small-help mt-2">
                                                Existing proof uploaded:
                                                <a href="<?php echo htmlspecialchars($existing_payment_proof); ?>" target="_blank">View file</a>
                                            </div>
                                            <div class="small-help mt-1">Upload a new file only if you want to replace it.</div>
                                        <?php endif; ?>

                                        <div class="small-help mt-2">Allowed: JPG, PNG, PDF (Max 5MB)</div>
                                        <div class="small-help mt-1"><strong>Note:</strong> If there is an error, your browser may ask you to choose the file again.</div>

                                        <div id="filePreview" class="preview-box mt-3" style="display:none;"></div>
                                    </div>

                                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                                        <?php echo isset($_GET['edit']) ? "Update Enrollment" : "Submit Enrollment"; ?>
                                    </button>
                                    <a href="index-admin.php" class="btn btn-secondary ml-2">Back to Dashboard</a>
                                </div>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<script>
function cleanRef(text) {
    return (text || '').trim().replace(/\s+/g, '_').replace(/[^a-zA-Z0-9_]/g, '');
}

function planAmount(plan) {
    const p = (plan || '').toLowerCase();
    if (p === 'term-wise') return 65;
    if (p === 'half-yearly') return 125;
    if (p === 'yearly') return 250;
    return 0;
}

function updatePayInfo() {
    const name = document.getElementById('student_name')?.value || '';
    const plan = document.getElementById('payment_plan')?.value || '';
    const amt = planAmount(plan);

    const payAmount = document.getElementById('payAmount');
    const payRef = document.getElementById('payRef');

    if (payAmount) payAmount.textContent = '$' + amt;

    const ref = cleanRef(name) + '_' + cleanRef(plan) + '_' + cleanRef(String(amt));
    if (payRef) payRef.value = (name && plan && amt) ? ref : '';

    validateReadyToSubmit();
}

function validateReadyToSubmit() {
    const isEdit = <?php echo isset($_GET['edit']) ? 'true' : 'false'; ?>;

    const name = (document.getElementById('student_name')?.value || '').trim();
    const plan = (document.getElementById('payment_plan')?.value || '').trim();
    const venue = (document.getElementById('class_option')?.value || '').trim();
    const gender = (document.getElementById('gender')?.value || '').trim();
    const dob = (document.getElementById('dob')?.value || '').trim();

    const fileInput = document.getElementById('payment_proof');
    const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;

    // New enrollment must have a file; edit can submit without new upload
    const okFile = isEdit ? true : hasFile;

    const ok = !!(name && plan && venue && gender && dob && okFile);

    const btn = document.getElementById('submitBtn');
    if (btn) btn.disabled = !ok;
}

document.addEventListener("DOMContentLoaded", function() {
    updatePayInfo();
    validateReadyToSubmit();

    // Update amount/reference live
    document.getElementById('student_name')?.addEventListener('input', updatePayInfo);
    document.getElementById('payment_plan')?.addEventListener('change', updatePayInfo);
    document.getElementById('class_option')?.addEventListener('change', validateReadyToSubmit);
    document.getElementById('gender')?.addEventListener('change', validateReadyToSubmit);
    document.getElementById('dob')?.addEventListener('change', validateReadyToSubmit);

    // Smooth step navigation
    document.getElementById('goToPayment')?.addEventListener('click', function() {
        document.getElementById('step2')?.scrollIntoView({behavior:'smooth'});
    });
    document.getElementById('goToUpload')?.addEventListener('click', function() {
        document.getElementById('step3')?.scrollIntoView({behavior:'smooth'});
    });

    // Copy reference
    document.getElementById('copyBtn')?.addEventListener('click', function() {
        const ref = document.getElementById('payRef');
        if (!ref || !ref.value) return;
        ref.select();
        ref.setSelectionRange(0, 99999);
        document.execCommand("copy");
        Swal.fire({ icon:'success', title:'Reference copied!', showConfirmButton:false, timer:900 });
    });

    // Friendly file UI: label + preview + validation
    const input = document.getElementById("payment_proof");
    const label = document.querySelector('label.custom-file-label[for="payment_proof"]');
    const preview = document.getElementById("filePreview");

    if (input) {
        input.addEventListener("change", function () {
            validateReadyToSubmit();

            if (!input.files || !input.files.length) return;

            const file = input.files[0];
            if (label) label.textContent = file.name;

            if (!preview) return;
            preview.style.display = "block";
            preview.innerHTML = "";

            const ext = (file.name.split('.').pop() || "").toLowerCase();

            if (["jpg","jpeg","png"].includes(ext)) {
                const img = document.createElement("img");
                img.src = URL.createObjectURL(file);
                preview.appendChild(img);
            } else if (ext === "pdf") {
                preview.innerHTML = `<div class="alert alert-light mb-0">
                    <i class="fas fa-file-pdf"></i> PDF selected: <strong>${file.name}</strong>
                </div>`;
            } else {
                preview.innerHTML = `<div class="alert alert-warning mb-0">Unsupported file selected.</div>`;
            }
        });
    }
});
</script>

</body>
</html>
