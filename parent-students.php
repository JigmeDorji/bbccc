<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/parent_helpers.php";
require_login();
allowRoles(['parent']);

$message = "";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

$parent = fetch_parent_record($pdo);
if (!$parent) {
    die("Parent account not found. Please contact admin.");
}

$parentId = (int)$parent['id'];

function generate_next_student_id(PDO $pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(student_id, 5) AS UNSIGNED)) AS max_num FROM students WHERE student_id LIKE 'BLCS%'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = ((int)($row['max_num'] ?? 0)) + 1;
    return 'BLCS' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student') {
    try {
        $student_name = trim($_POST['student_name'] ?? '');
        $dob = $_POST['dob'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $medical_issue = trim($_POST['medical_issue'] ?? '');

        if ($student_name === '' || empty($dob) || empty($gender)) {
            throw new Exception("Please fill in all required fields.");
        }

        $student_id = generate_next_student_id($pdo);
        $registration_date = date('Y-m-d');
        $approval_status = 'Pending';
        $status = 'Pending';

        $stmt = $pdo->prepare(
            "INSERT INTO students (student_id, parent_id, student_name, dob, gender, medical_issue, registration_date, approval_status, status)
             VALUES (:student_id, :parent_id, :student_name, :dob, :gender, :medical_issue, :registration_date, :approval_status, :status)"
        );
        $stmt->execute([
            ':student_id' => $student_id,
            ':parent_id' => $parentId,
            ':student_name' => $student_name,
            ':dob' => $dob,
            ':gender' => $gender,
            ':medical_issue' => $medical_issue === '' ? null : $medical_issue,
            ':registration_date' => $registration_date,
            ':approval_status' => $approval_status,
            ':status' => $status
        ]);

        $message = "Student added successfully. Awaiting admin approval.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$stmt = $pdo->prepare(
    "SELECT s.*, c.class_name,
            (SELECT status FROM payments p WHERE p.student_id = s.id ORDER BY uploaded_at DESC LIMIT 1) AS payment_status
     FROM students s
     LEFT JOIN class_assignments ca ON ca.student_id = s.id
     LEFT JOIN classes c ON c.id = ca.class_id
     WHERE s.parent_id = :parent_id
     ORDER BY s.id DESC"
);
$stmt->execute([':parent_id' => $parentId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>My Students</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <h1 class="h3 mb-4 text-gray-800">My Students</h1>

                <?php if ($message): ?>
                    <div class="alert <?php echo (stripos($message, 'Error') === 0) ? 'alert-danger' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Student List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>DOB</th>
                                    <th>Gender</th>
                                    <th>Status</th>
                                    <th>Approval</th>
                                    <th>Class</th>
                                    <th>Payment</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                        <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['dob']); ?></td>
                                        <td><?php echo htmlspecialchars($student['gender']); ?></td>
                                        <td><?php echo htmlspecialchars($student['status']); ?></td>
                                        <td><?php echo htmlspecialchars($student['approval_status']); ?></td>
                                        <td><?php echo htmlspecialchars($student['class_name'] ?? 'Not Assigned'); ?></td>
                                        <td><?php echo htmlspecialchars($student['payment_status'] ?? 'Not Uploaded'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($students)): ?>
                                    <tr><td colspan="8" class="text-center">No students added yet.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Add Student</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_student">

                            <div class="form-group">
                                <label>Student Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="student_name" required>
                            </div>

                            <div class="form-group">
                                <label>Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="dob" required>
                            </div>

                            <div class="form-group">
                                <label>Gender <span class="text-danger">*</span></label>
                                <select class="form-control" name="gender" required>
                                    <option value="">-- Select --</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Medical Issue (Optional)</label>
                                <textarea class="form-control" name="medical_issue" rows="3"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">Submit</button>
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
