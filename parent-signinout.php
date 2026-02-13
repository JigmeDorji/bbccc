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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sign') {
    try {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $mode = $_POST['mode'] ?? 'in';

        $stmt = $pdo->prepare(
            "SELECT s.id, ca.class_id
             FROM students s
             LEFT JOIN class_assignments ca ON ca.student_id = s.id
             WHERE s.id = :student_id AND s.parent_id = :parent_id"
        );
        $stmt->execute([':student_id' => $studentId, ':parent_id' => $parentId]);
        $studentRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$studentRow) {
            throw new Exception("Invalid student selection.");
        }

        $classId = (int)($studentRow['class_id'] ?? 0);
        if ($classId === 0) {
            throw new Exception("Student is not assigned to a class yet.");
        }

        if ($mode === 'in') {
            $stmt = $pdo->prepare(
                "INSERT INTO sign_in_out (class_id, student_id, parent_id, signed_in_at, note)
                 VALUES (:class_id, :student_id, :parent_id, NOW(), :note)"
            );
            $stmt->execute([
                ':class_id' => $classId,
                ':student_id' => $studentId,
                ':parent_id' => $parentId,
                ':note' => $note === '' ? null : $note
            ]);
            $message = "Signed in successfully.";
        } else {
            $stmt = $pdo->prepare(
                "SELECT id FROM sign_in_out
                 WHERE student_id = :student_id AND parent_id = :parent_id AND signed_out_at IS NULL
                 ORDER BY signed_in_at DESC LIMIT 1"
            );
            $stmt->execute([':student_id' => $studentId, ':parent_id' => $parentId]);
            $openRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$openRow) {
                throw new Exception("No active sign-in found for this student.");
            }

            $stmt = $pdo->prepare(
                "UPDATE sign_in_out SET signed_out_at = NOW(), note = :note WHERE id = :id"
            );
            $stmt->execute([
                ':note' => $note === '' ? null : $note,
                ':id' => $openRow['id']
            ]);
            $message = "Signed out successfully.";
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$stmt = $pdo->prepare(
    "SELECT s.id, s.student_name
     FROM students s
     WHERE s.parent_id = :parent_id AND s.approval_status = 'Approved'
     ORDER BY s.student_name ASC"
);
$stmt->execute([':parent_id' => $parentId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT sio.*, s.student_name, c.class_name
     FROM sign_in_out sio
     INNER JOIN students s ON s.id = sio.student_id
     INNER JOIN classes c ON c.id = sio.class_id
     WHERE sio.parent_id = :parent_id
     ORDER BY sio.signed_in_at DESC"
);
$stmt->execute([':parent_id' => $parentId]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Sign In/Out</title>
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
                <h1 class="h3 mb-4 text-gray-800">Student Sign In/Out</h1>

                <?php if ($message): ?>
                    <div class="alert <?php echo (stripos($message, 'Error') === 0) ? 'alert-danger' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Sign In or Out</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="sign">

                            <div class="form-group">
                                <label>Select Student</label>
                                <select name="student_id" class="form-control" required>
                                    <option value="">-- Select Student --</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo (int)$student['id']; ?>">
                                            <?php echo htmlspecialchars($student['student_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Action</label>
                                <select name="mode" class="form-control">
                                    <option value="in">Sign In (Drop Off)</option>
                                    <option value="out">Sign Out (Pick Up)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Note (Optional)</label>
                                <input type="text" class="form-control" name="note" placeholder="Late drop-off, early pickup, etc.">
                            </div>

                            <button type="submit" class="btn btn-primary">Submit</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Sign In/Out History</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Signed In</th>
                                    <th>Signed Out</th>
                                    <th>Note</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($log['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($log['signed_in_at']); ?></td>
                                        <td><?php echo htmlspecialchars($log['signed_out_at'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($log['note'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($logs)): ?>
                                    <tr><td colspan="5" class="text-center">No sign in/out records yet.</td></tr>
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
