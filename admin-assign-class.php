<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/role_helpers.php";
require_login();
allowRoles(['Administrator', 'Admin', 'Company Admin', 'System_owner', 'Staff']);

$message = "";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);

        if ($studentId === 0 || $classId === 0) {
            throw new Exception("Student and class are required.");
        }

        $stmt = $pdo->prepare("SELECT id FROM class_assignments WHERE student_id = :student_id");
        $stmt->execute([':student_id' => $studentId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE class_assignments SET class_id = :class_id, assigned_by = :assigned_by, assigned_at = NOW() WHERE student_id = :student_id");
            $stmt->execute([
                ':class_id' => $classId,
                ':assigned_by' => $_SESSION['userid'] ?? null,
                ':student_id' => $studentId
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO class_assignments (class_id, student_id, assigned_by) VALUES (:class_id, :student_id, :assigned_by)");
            $stmt->execute([
                ':class_id' => $classId,
                ':student_id' => $studentId,
                ':assigned_by' => $_SESSION['userid'] ?? null
            ]);
        }

        $message = "Class assignment saved.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$students = $pdo->query(
    "SELECT id, student_name, student_id
     FROM students
     WHERE approval_status = 'Approved'
     ORDER BY student_name"
)->fetchAll(PDO::FETCH_ASSOC);

$classes = $pdo->query(
    "SELECT id, class_name
     FROM classes
     WHERE active = 1
     ORDER BY class_name"
)->fetchAll(PDO::FETCH_ASSOC);

$assignments = $pdo->query(
    "SELECT ca.id, s.student_name, s.student_id, c.class_name, ca.assigned_at
     FROM class_assignments ca
     INNER JOIN students s ON s.id = ca.student_id
     INNER JOIN classes c ON c.id = ca.class_id
     ORDER BY ca.assigned_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Assign Class</title>
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
                <h1 class="h3 mb-4 text-gray-800">Assign Class</h1>

                <?php if ($message): ?>
                    <div class="alert <?php echo (stripos($message, 'Error') === 0) ? 'alert-danger' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Assign Student to Class</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="form-group">
                                <label>Student</label>
                                <select name="student_id" class="form-control" required>
                                    <option value="">-- Select Student --</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo (int)$student['id']; ?>">
                                            <?php echo htmlspecialchars($student['student_name']); ?> (<?php echo htmlspecialchars($student['student_id']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Class</label>
                                <select name="class_id" class="form-control" required>
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo (int)$class['id']; ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary">Assign</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Recent Assignments</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Assigned At</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($assignment['student_name']); ?> (<?php echo htmlspecialchars($assignment['student_id']); ?>)</td>
                                        <td><?php echo htmlspecialchars($assignment['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['assigned_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($assignments)): ?>
                                    <tr><td colspan="3" class="text-center">No assignments yet.</td></tr>
                                <?php endif; ?>
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
</body>
</html>
