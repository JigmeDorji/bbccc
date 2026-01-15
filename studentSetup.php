<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');

$message = "";
$reloadPage = false;

// Existing values for edit mode
$existing_student_id = "";
$existing_student_name = "";
$existing_dob = "";
$existing_gender = "";
$existing_medical_issue = "";
$existing_registration_date = "";
$existing_approval_status = "Pending";
$existing_parentId = "";

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

// ---------------- FETCH PARENTS ----------------
// (Kept from your original logic. We auto-use the first parent record.)
$parents = [];
try {
    $stmtParents = $pdo->prepare("SELECT id, full_name, email FROM parents ORDER BY id ASC");
    $stmtParents->execute();
    $parents = $stmtParents->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error loading parents: " . $e->getMessage());
}

if (empty($parents)) {
    die("No parent record found. Please create a parent first.");
}

// ✅ Auto-populate FIRST parent
$autoParentId = (int)$parents[0]['id'];
$autoParentLabel = trim(($parents[0]['full_name'] ?? '') . ' - ' . ($parents[0]['email'] ?? ''));

// ---------------- AUTO VALUES ----------------
$autoRegDate = date('Y-m-d');
$autoApprovalStatus = "Pending";

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

// ---------------- EDIT (optional) ----------------
if (isset($_GET['edit'])) {
    try {
        $id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("Student not found.");
        }

        // ✅ Block parent editing approved record
        if ($role === 'parent' && strtolower($row['approval_status'] ?? '') === 'approved') {
            throw new Exception("This enrollment is already Approved. Please contact admin for changes.");
        }

        $existing_student_id = $row['student_id'] ?? "";
        $existing_student_name = $row['student_name'] ?? "";
        $existing_dob = $row['dob'] ?? "";
        $existing_gender = $row['gender'] ?? "";
        $existing_medical_issue = $row['medical_issue'] ?? "";
        $existing_registration_date = $row['registration_date'] ?? "";
        $existing_approval_status = $row['approval_status'] ?? "Pending";
        $existing_parentId = $row['parentId'] ?? "";

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// ---------------- SAVE (ADD / UPDATE) ----------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $student_name = trim($_POST['student_name'] ?? '');
        $dob = $_POST['dob'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $medical_issue = trim($_POST['medical_issue'] ?? '');

        // ✅ Required except medical issue
        if ($student_name === "" || empty($dob) || empty($gender)) {
            throw new Exception("Please fill in all required fields (Student Name, DOB and Gender).");
        }

        $dob = ($dob === "") ? null : $dob;
        $gender = ($gender === "") ? null : $gender;
        $medical_issue = ($medical_issue === "") ? null : $medical_issue;

        // Auto fields (hidden)
        $student_id = isset($_GET['edit'])
            ? ($existing_student_id ?: generateNextStudentId($pdo))
            : generateNextStudentId($pdo);

        $parentId = $autoParentId;

        $registration_date = isset($_GET['edit'])
            ? ($existing_registration_date ?: $autoRegDate)
            : $autoRegDate;

        $approval_status = $autoApprovalStatus;

        if (isset($_GET['edit'])) {
            $id = (int)$_GET['edit'];

            // Safety: block parent if approved
            if ($role === 'parent') {
                $stmtCheck = $pdo->prepare("SELECT approval_status FROM students WHERE id = :id LIMIT 1");
                $stmtCheck->execute([':id' => $id]);
                $current = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if ($current && strtolower($current['approval_status'] ?? '') === 'approved') {
                    throw new Exception("This enrollment is already Approved. Please contact admin for changes.");
                }
            }

            $stmt = $pdo->prepare("
                UPDATE students
                SET student_id = :student_id,
                    student_name = :student_name,
                    dob = :dob,
                    gender = :gender,
                    medical_issue = :medical_issue,
                    registration_date = :registration_date,
                    approval_status = :approval_status,
                    parentId = :parentId
                WHERE id = :id
            ");

            $stmt->execute([
                ':student_id' => $student_id,
                ':student_name' => $student_name,
                ':dob' => $dob,
                ':gender' => $gender,
                ':medical_issue' => $medical_issue,
                ':registration_date' => $registration_date,
                ':approval_status' => $approval_status,
                ':parentId' => $parentId,
                ':id' => $id
            ]);

            $message = "Student updated successfully.";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO students
                    (student_id, student_name, dob, gender, medical_issue, registration_date, approval_status, parentId)
                VALUES
                    (:student_id, :student_name, :dob, :gender, :medical_issue, :registration_date, :approval_status, :parentId)
            ");

            $stmt->execute([
                ':student_id' => $student_id,
                ':student_name' => $student_name,
                ':dob' => $dob,
                ':gender' => $gender,
                ':medical_issue' => $medical_issue,
                ':registration_date' => $registration_date,
                ':approval_status' => $approval_status,
                ':parentId' => $parentId
            ]);

            $message = "Student enrollment submitted successfully. (Pending approval)";
        }

        $reloadPage = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// ---------------- DELETE (kept only for safety / admin use) ----------------
if (isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];

        // Block parent delete approved
        if ($role === 'parent') {
            $stmtCheck = $pdo->prepare("SELECT approval_status FROM students WHERE id = :id LIMIT 1");
            $stmtCheck->execute([':id' => $id]);
            $current = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($current && strtolower($current['approval_status'] ?? '') === 'approved') {
                throw new Exception("This enrollment is already Approved. Please contact admin for changes.");
            }
        }

        $stmt = $pdo->prepare("DELETE FROM students WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $message = "Student deleted successfully.";
        $reloadPage = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
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
</head>

<body id="page-top">
<div id="wrapper">

    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">

            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

                <h1 class="h3 mb-2 text-gray-800"><?php echo isset($_GET['edit']) ? "Edit Student" : "Add Student"; ?></h1>

                <div class="alert alert-info shadow-sm">
                    <strong>Important:</strong>
                    <ul class="mb-0">
                        <li><strong>Student ID</strong> is auto-generated (example: <code>BLCS0001</code>).</li>
                        <li><strong>Parent</strong> is auto-linked using: <strong><?php echo htmlspecialchars($autoParentLabel); ?></strong></li>
                        <li><strong>Registration Date</strong> will be set to today: <strong><?php echo htmlspecialchars($autoRegDate); ?></strong></li>
                        <li><strong>Status</strong> will be <strong>Pending</strong> until admin approves.</li>
                        <li><strong>Required fields:</strong> Student Name, DOB, Gender (Medical Issue is optional).</li>
                    </ul>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const msg = <?php echo json_encode($message); ?>;
                        const reload = <?php echo $reloadPage ? 'true' : 'false'; ?>;

                        if (msg) {
                            Swal.fire({
                                icon: (msg.toLowerCase().includes('success') || msg.toLowerCase().includes('submitted') || msg.toLowerCase().includes('updated') || msg.toLowerCase().includes('deleted'))
                                    ? 'success' : 'error',
                                title: msg,
                                showConfirmButton: false,
                                timer: 1600
                            }).then(() => {
                                if (reload) window.location.href = 'index-admin.php'; // back to dashboard
                            });
                        }
                    });
                </script>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Student Enrollment Form</h6>
                    </div>

                    <div class="card-body">
                        <form action="studentSetup.php<?php echo isset($_GET['edit']) ? '?edit='.(int)$_GET['edit'] : ''; ?>" method="POST">

                            <!-- Hidden auto fields -->
                            <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($existing_student_id ?: ''); ?>">
                            <input type="hidden" name="parentId" value="<?php echo htmlspecialchars($autoParentId); ?>">
                            <input type="hidden" name="registration_date" value="<?php echo htmlspecialchars($existing_registration_date ?: $autoRegDate); ?>">
                            <input type="hidden" name="approval_status" value="Pending">

                            <div class="form-group row">
                                <div class="col-md-3"><label class="col-form-label">Student Name <span class="text-danger">*</span></label></div>
                                <div class="col-md-9">
                                    <input type="text" class="form-control" name="student_name"
                                           value="<?php echo htmlspecialchars($existing_student_name); ?>" required
                                           placeholder="Enter student full name">
                                </div>
                            </div>

                            <div class="form-group row">
                                <div class="col-md-3"><label class="col-form-label">DOB <span class="text-danger">*</span></label></div>
                                <div class="col-md-9">
                                    <input type="date" class="form-control" name="dob"
                                           value="<?php echo htmlspecialchars($existing_dob); ?>" required>
                                </div>
                            </div>

                            <div class="form-group row">
                                <div class="col-md-3"><label class="col-form-label">Gender <span class="text-danger">*</span></label></div>
                                <div class="col-md-9">
                                    <select class="form-control" name="gender" required>
                                        <option value="">-- Select --</option>
                                        <?php
                                        $genders = ["Male", "Female", "Other"];
                                        foreach ($genders as $g) {
                                            $selected = ($existing_gender === $g) ? "selected" : "";
                                            echo "<option value='".htmlspecialchars($g)."' $selected>".htmlspecialchars($g)."</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group row">
                                <div class="col-md-3"><label class="col-form-label">Any Medical Issue</label></div>
                                <div class="col-md-9">
                                    <textarea class="form-control" name="medical_issue" rows="4"
                                              placeholder="Optional (e.g., allergies)."><?php echo htmlspecialchars($existing_medical_issue); ?></textarea>
                                </div>
                            </div>

                            <div class="form-group row">
                                <div class="col-md-3"></div>
                                <div class="col-md-9">
                                    <button type="submit" class="btn btn-primary">
                                        <?php echo isset($_GET['edit']) ? "Update" : "Submit Enrollment"; ?>
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

</body>
</html>
