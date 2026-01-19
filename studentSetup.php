<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');

// Parent only
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
        "mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME,
        $DB_USER,
        $DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

// ---------------- GET LOGGED-IN PARENT (EMAIL-AS-USERNAME) ----------------
$autoParentId = null;
$autoParentLabel = "";

$sessionLoginEmail = strtolower(trim($_SESSION['username'] ?? '')); // email-as-username

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
$parentRow = $stmtParent->fetch(PDO::FETCH_ASSOC);

if (!$parentRow) {
    die(
        "No matching parent record found for email: <strong>" . htmlspecialchars($sessionLoginEmail) . "</strong><br><br>" .
        "Fix: Ensure the parent's <strong>email</strong> in the parents table matches the login email exactly."
    );
}

$autoParentId = (int)$parentRow['id'];
$autoParentLabel = trim(($parentRow['full_name'] ?? '') . ' - ' . ($parentRow['email'] ?? ''));

// ---------------- AUTO VALUES ----------------
$autoRegDate = date('Y-m-d');
$autoApprovalStatus = "Pending";

// Payment plan amounts
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
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = ((int)($row['max_num'] ?? 0)) + 1;
    return 'BLCS' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

// ---------------- FORM STATE (STICKY VALUES) ----------------
$old = [
    'student_name'  => '',
    'dob'           => '',
    'gender'        => '',
    'medical_issue' => '',
    'class_option'  => '',
    'payment_plan'  => '',
];

// Existing proof path for edit mode
$existing_payment_proof = null;

// ---------------- EDIT (optional) ----------------
if (isset($_GET['edit'])) {
    try {
        $id = (int)$_GET['edit'];

        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = :id AND parentId = :pid");
        $stmt->execute([':id' => $id, ':pid' => $autoParentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("Student not found or you don't have permission to edit this record.");
        }

        if (strtolower($row['approval_status'] ?? '') === 'approved') {
            throw new Exception("This enrollment is already Approved. Please contact admin for changes.");
        }

        // ✅ Prefill sticky values from DB
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
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Read inputs
        $student_name  = trim($_POST['student_name'] ?? '');
        $dob           = trim($_POST['dob'] ?? '');
        $gender        = trim($_POST['gender'] ?? '');
        $medical_issue = trim($_POST['medical_issue'] ?? '');
        $class_option  = trim($_POST['class_option'] ?? '');
        $payment_plan  = trim($_POST['payment_plan'] ?? '');

        // ✅ Sticky values always kept on error
        $old['student_name']  = $student_name;
        $old['dob']           = $dob;
        $old['gender']        = $gender;
        $old['medical_issue'] = $medical_issue;
        $old['class_option']  = $class_option;
        $old['payment_plan']  = $payment_plan;

        // Validate required fields (medical optional)
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

        // Calculate amount + reference
        $amount = plan_amount($payment_plan);
        if ($amount <= 0) {
            throw new Exception("Payment amount could not be calculated.");
        }

        $ref = clean_ref_text($student_name) . "_" . clean_ref_text($payment_plan) . "_" . clean_ref_text((string)(int)$amount);

        // Payment proof upload handling
        $proofPath = $existing_payment_proof ?: null;

        $isEdit = isset($_GET['edit']) && ((int)$_GET['edit'] > 0);

        // ✅ For NEW enrollments proof is required
        if (!$isEdit && (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== 0)) {
            $focusField = "payment_proof";
            throw new Exception("Payment proof is required for new enrollments. Please upload a screenshot/PDF.");
        }

        // If file provided, validate + store
        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === 0) {
            $allowed = ['jpg','jpeg','png','pdf'];
            $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed, true)) {
                $focusField = "payment_proof";
                throw new Exception("Invalid file type. Only JPG, PNG or PDF allowed.");
            }
            if ($_FILES['payment_proof']['size'] > 5 * 1024 * 1024) {
                $focusField = "payment_proof";
                throw new Exception("File too large. Maximum 5MB allowed.");
            }

            $dir = "uploads/payments/";
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($_FILES['payment_proof']['name'], PATHINFO_FILENAME));
            $newFile = $dir . time() . "_" . $safeName . "." . $ext;

            if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $newFile)) {
                throw new Exception("Failed to upload payment proof.");
            }

            $proofPath = $newFile;
        }

        // Auto fields
        $parentId = $autoParentId;
        $registration_date = $autoRegDate;
        $approval_status = $autoApprovalStatus;

        if ($isEdit) {
            $id = (int)$_GET['edit'];

            // Safety: ensure record belongs to parent and not approved
            $stmtCheck = $pdo->prepare("SELECT approval_status, payment_proof FROM students WHERE id = :id AND parentId = :pid LIMIT 1");
            $stmtCheck->execute([':id' => $id, ':pid' => $parentId]);
            $current = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$current) throw new Exception("You don't have permission to update this record.");
            if (strtolower($current['approval_status'] ?? '') === 'approved') {
                throw new Exception("This enrollment is already Approved. Please contact admin for changes.");
            }

            // Keep existing proof if user did not upload again
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
                ':registration_date' => $registration_date,
                ':approval_status' => $approval_status,
                ':parentId' => $parentId
            ]);

            $message = "Enrollment submitted successfully. Please complete payment and upload proof. (Pending approval)";
            $success = true;
            $reloadPage = true;
        }

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
        $reloadPage = false; // ✅ do NOT redirect on error (keeps sticky values)
    }
}

// ---------------- DELETE (Parent only pending + own record) ----------------
if (isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];

        $stmtCheck = $pdo->prepare("SELECT approval_status FROM students WHERE id = :id AND parentId = :pid LIMIT 1");
        $stmtCheck->execute([':id' => $id, ':pid' => $autoParentId]);
        $current = $stmtCheck->fetch(PDO::FETCH_ASSOC);

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
    <title>Add Student</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .bank-box { background:#f8f9fc; border:1px solid #e3e6f0; padding:15px; border-radius:8px; }
        .ref-box { background:#fff; border:1px dashed #bbb; padding:10px; border-radius:6px; }
        .small-help { font-size: 12px; color:#6c757d; }
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
                        <li>Please choose your <strong>class session</strong> and <strong>payment plan</strong>, then pay and upload proof.</li>
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
                                timer: isSuccess ? 1800 : 4500
                            }).then(() => {
                                if (reload && isSuccess) {
                                    window.location.href = 'index-admin.php';
                                    return;
                                }

                                // Focus field on error
                                if (!isSuccess && focusField) {
                                    const el = document.querySelector(`[name="${focusField}"]`) || document.getElementById(focusField);
                                    if (el) el.focus();
                                }
                            });
                        }
                    });
                </script>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Enrollment Form</h6>
                            </div>
                            <div class="card-body">

                                <form action="studentSetup.php<?php echo isset($_GET['edit']) ? '?edit='.(int)$_GET['edit'] : ''; ?>" method="POST" enctype="multipart/form-data">

                                    <div class="form-group">
                                        <label>Student Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="student_name"
                                               value="<?php echo htmlspecialchars($old['student_name']); ?>" required
                                               placeholder="Enter student full name" id="student_name">
                                    </div>

                                    <div class="form-group">
                                        <label>DOB <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="dob"
                                               value="<?php echo htmlspecialchars($old['dob']); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Gender <span class="text-danger">*</span></label>
                                        <select class="form-control" name="gender" required>
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
                                            <option value="Term-wise" <?php echo ($old['payment_plan']==="Term-wise")?'selected':''; ?>>Term-wise ($65)</option>
                                            <option value="Half-yearly" <?php echo ($old['payment_plan']==="Half-yearly")?'selected':''; ?>>Half-yearly ($125)</option>
                                            <option value="Yearly" <?php echo ($old['payment_plan']==="Yearly")?'selected':''; ?>>Yearly ($250)</option>
                                        </select>
                                        <div class="small-help mt-1">Amount is calculated automatically based on your payment plan.</div>
                                    </div>

                                    <div class="form-group">
                                        <label>Upload Payment Screenshot / Proof <?php echo (empty($existing_payment_proof) && !isset($_GET['edit'])) ? '<span class="text-danger">*</span>' : ''; ?></label>
                                        <input type="file" class="form-control-file" name="payment_proof" id="payment_proof"
                                            <?php echo (!isset($_GET['edit'])) ? 'required' : ''; ?>>
                                        <?php if (!empty($existing_payment_proof)): ?>
                                            <div class="small-help mt-2">
                                                Existing proof uploaded: <a href="<?php echo htmlspecialchars($existing_payment_proof); ?>" target="_blank">View file</a>
                                            </div>
                                            <div class="small-help mt-1">If you want to replace it, upload a new file.</div>
                                        <?php endif; ?>
                                        <div class="small-help mt-1">Allowed: JPG, PNG, PDF (Max 5MB)</div>
                                        <div class="small-help mt-1"><strong>Note:</strong> If there is an error, your browser will ask you to choose the file again (security limitation).</div>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <?php echo isset($_GET['edit']) ? "Update Enrollment" : "Submit Enrollment"; ?>
                                    </button>
                                    <a href="index-admin.php" class="btn btn-secondary ml-2">Back to Dashboard</a>

                                </form>

                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">

                        <!-- Bank details -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Bank Details</h6>
                            </div>
                            <div class="card-body">
                                <div class="bank-box">
                                    <p class="mb-1"><strong>Account Name:</strong> Bhutanese Centre Canberra</p>
                                    <p class="mb-1"><strong>BSB:</strong> 000-000</p>
                                    <p class="mb-1"><strong>Account Number:</strong> 00000000</p>
                                    <hr>
                                    <p class="mb-0 small-help">
                                        Please use the generated payment reference exactly in your bank transfer.
                                        Enrollment will be confirmed only after payment verification.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Payment reference -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Payment Reference</h6>
                            </div>
                            <div class="card-body">
                                <div class="ref-box">
                                    <div class="small-help mb-2">Copy and paste this reference into your bank transfer:</div>
                                    <input type="text" class="form-control" id="payRef" readonly value="">
                                    <button type="button" class="btn btn-sm btn-success mt-2" id="copyBtn">
                                        Copy Reference
                                    </button>
                                    <div class="small-help mt-2">Format: <code>ChildName_PLAN_Amount</code></div>
                                </div>
                            </div>
                        </div>

                        <!-- Payment amount -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Calculated Amount</h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-light mb-0">
                                    <strong>Amount to pay:</strong> <span id="payAmount">$0</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<script>
function cleanRef(text) {
    return text.trim().replace(/\s+/g, '_').replace(/[^a-zA-Z0-9_]/g, '');
}

function planAmount(plan) {
    const p = (plan || '').toLowerCase();
    if (p === 'term-wise') return 65;
    if (p === 'half-yearly') return 125;
    if (p === 'yearly') return 250;
    return 0;
}

function updatePayInfo() {
    const name = document.getElementById('student_name').value || '';
    const plan = document.getElementById('payment_plan').value || '';
    const amt = planAmount(plan);

    document.getElementById('payAmount').textContent = '$' + amt;

    const ref = cleanRef(name) + '_' + cleanRef(plan) + '_' + cleanRef(String(amt));
    document.getElementById('payRef').value = (name && plan && amt) ? ref : '';
}

document.addEventListener("DOMContentLoaded", function() {
    updatePayInfo();

    document.getElementById('student_name').addEventListener('input', updatePayInfo);
    document.getElementById('payment_plan').addEventListener('change', updatePayInfo);

    document.getElementById('copyBtn').addEventListener('click', function() {
        const ref = document.getElementById('payRef');
        if (!ref.value) return;

        ref.select();
        ref.setSelectionRange(0, 99999);

        document.execCommand("copy");
        Swal.fire({ icon:'success', title:'Copied!', showConfirmButton:false, timer:900 });
    });
});
</script>
</body>
</html>
