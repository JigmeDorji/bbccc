<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

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

// ✅ Auto-populate FIRST parent from dropdown list
if (empty($parents)) {
    die("No parent record found. Please create a parent first.");
}
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

// ---------------- FETCH STUDENTS LIST ----------------
$students = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, p.full_name AS parent_name, p.email AS parent_email
        FROM students s
        LEFT JOIN parents p ON p.id = s.parentId
        ORDER BY s.id DESC
    ");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error fetching students: " . $e->getMessage();
}

// ---------------- EDIT STUDENT ----------------
if (isset($_GET['edit'])) {
    try {
        $id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("Student not found.");
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
        // Visible inputs
        $student_name = trim($_POST['student_name'] ?? '');
        $dob = $_POST['dob'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $medical_issue = trim($_POST['medical_issue'] ?? '');

        // ✅ REQUIRED: Student Name, DOB, Gender
        if ($student_name === "" || empty($dob) || empty($gender)) {
            throw new Exception("Please fill in all required fields (Student Name, DOB and Gender).");
        }

        // Normalize empty to NULL (medical issue optional)
        $dob = ($dob === "") ? null : $dob;
        $gender = ($gender === "") ? null : $gender;
        $medical_issue = ($medical_issue === "") ? null : $medical_issue;

        // ✅ Auto fields (hidden from user)
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

            $message = "Student added successfully. (Pending approval)";
        }

        $reloadPage = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// ---------------- DELETE STUDENT ----------------
if (isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];
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

    <title>Student Setup</title>

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

                <h1 class="h3 mb-2 text-gray-800">Student Setup</h1>

                <!-- ✅ Guidance for users -->
                <div class="alert alert-info shadow-sm">
                    <strong>Important:</strong>
                    <ul class="mb-0">
                        <li><strong>Student ID</strong> is auto-generated (e.g. <code>BLCS0001</code>, <code>BLCS0002</code>).</li>
                        <li><strong>Parent</strong> is auto-linked using the first parent record: <strong><?php echo htmlspecialchars($autoParentLabel); ?></strong></li>
                        <li><strong>Registration Date</strong> is set automatically to today: <strong><?php echo htmlspecialchars($autoRegDate); ?></strong></li>
                        <li><strong>Status</strong> is set to <strong>Pending</strong> until an admin approves it.</li>
                        <li><strong>Required fields:</strong> Student Name, DOB, Gender. (Medical Issue is optional)</li>
                    </ul>
                </div>

                <!-- SweetAlert message -->
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const msg = <?php echo json_encode($message); ?>;
                        const reload = <?php echo $reloadPage ? 'true' : 'false'; ?>;

                        if (msg) {
                            Swal.fire({
                                icon: (msg.toLowerCase().includes('success') || msg.toLowerCase().includes('added') || msg.toLowerCase().includes('updated') || msg.toLowerCase().includes('deleted'))
                                    ? 'success' : 'error',
                                title: msg,
                                showConfirmButton: false,
                                timer: 1600
                            }).then(() => {
                                if (reload) window.location.href = 'studentSetup.php';
                            });
                        }
                    });
                </script>

                <!-- Student List -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Student List</h6>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student Id</th>
                                    <th>Student Name</th>
                                    <th>DOB</th>
                                    <th>Gender</th>
                                    <th>Medical Issue</th>
                                    <th>Reg Date</th>
                                    <th>Status</th>
                                    <th>Parent</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($students as $s): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($s['id']); ?></td>
                                        <td><?php echo htmlspecialchars($s['student_id'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['student_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['dob'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['gender'] ?? ''); ?></td>
                                        <td style="max-width:220px; white-space:normal;">
                                            <?php echo htmlspecialchars($s['medical_issue'] ?? ''); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($s['registration_date'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['approval_status'] ?? ''); ?></td>
                                        <td>
                                            <?php
                                                $pn = $s['parent_name'] ?? '';
                                                $pe = $s['parent_email'] ?? '';
                                                echo $pn ? htmlspecialchars($pn . ($pe ? " ($pe)" : "")) : "-";
                                            ?>
                                        </td>
                                        <td>
                                            <a href="studentSetup.php?edit=<?php echo (int)$s['id']; ?>" class="btn btn-info btn-sm">Edit</a>
                                            <a href="#" class="btn btn-danger btn-sm delete-student-btn" data-id="<?php echo (int)$s['id']; ?>">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Student Form -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <?php echo isset($_GET['edit']) ? "Edit Student" : "Add Student"; ?>
                        </h6>
                    </div>

                    <div class="card-body">
                        <p class="text-muted mb-3">
                            Please fill the required student details. Student ID, Parent, Registration Date and Status are automatically set.
                        </p>

                        <form action="studentSetup.php<?php echo isset($_GET['edit']) ? '?edit='.(int)$_GET['edit'] : ''; ?>" method="POST">

                            <!-- Hidden auto fields (not shown to user) -->
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
                                    <small class="text-muted">Required</small>
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
                                    <small class="text-muted">Required</small>
                                </div>
                            </div>

                            <div class="form-group row">
                                <div class="col-md-3"><label class="col-form-label">Any Medical Issue</label></div>
                                <div class="col-md-9">
                                    <textarea class="form-control" name="medical_issue" rows="4"
                                              placeholder="Optional. Include any relevant information (e.g., allergies)."><?php echo htmlspecialchars($existing_medical_issue); ?></textarea>
                                    <small class="text-muted">Optional</small>
                                </div>
                            </div>

                            <div class="form-group row">
                                <div class="col-md-3"></div>
                                <div class="col-md-9">
                                    <button type="submit" class="btn btn-primary">
                                        <?php echo isset($_GET['edit']) ? "Update" : "Submit"; ?>
                                    </button>
                                    <?php if (isset($_GET['edit'])): ?>
                                        <a href="studentSetup.php" class="btn btn-secondary ml-2">Cancel</a>
                                    <?php endif; ?>
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
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".delete-student-btn").forEach(btn => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            const id = this.getAttribute("data-id");

            Swal.fire({
                title: "Are you sure?",
                text: "This student will be permanently deleted.",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#d33",
                cancelButtonColor: "#3085d6",
                confirmButtonText: "Yes, delete",
                cancelButtonText: "Cancel"
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = "studentSetup.php?delete=" + id;
                }
            });
        });
    });
});
</script>

</body>
</html>
