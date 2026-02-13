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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_payment') {
    try {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);

        $stmt = $pdo->prepare("SELECT id, student_name FROM students WHERE id = :id AND parent_id = :parent_id");
        $stmt->execute([':id' => $studentId, ':parent_id' => $parentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            throw new Exception("Invalid student selected.");
        }

        if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please upload a valid payment proof file.");
        }

        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $fileInfo = pathinfo($_FILES['proof']['name']);
        $ext = strtolower($fileInfo['extension'] ?? '');

        if (!in_array($ext, $allowed, true)) {
            throw new Exception("Only JPG, PNG, or PDF files are allowed.");
        }

        if ($_FILES['proof']['size'] > 5 * 1024 * 1024) {
            throw new Exception("File size must be under 5MB.");
        }

        $safeName = 'payment_' . $parentId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $uploadDir = __DIR__ . '/uploads/fees/';
        $uploadPath = $uploadDir . $safeName;

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (!move_uploaded_file($_FILES['proof']['tmp_name'], $uploadPath)) {
            throw new Exception("Upload failed. Please try again.");
        }

        $relativePath = 'uploads/fees/' . $safeName;

        $stmt = $pdo->prepare(
            "INSERT INTO payments (student_id, parent_id, amount, proof_path, status)
             VALUES (:student_id, :parent_id, :amount, :proof_path, 'Pending')"
        );
        $stmt->execute([
            ':student_id' => $studentId,
            ':parent_id' => $parentId,
            ':amount' => $amount,
            ':proof_path' => $relativePath
        ]);

        $message = "Payment proof uploaded successfully. Awaiting approval.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$stmt = $pdo->prepare("SELECT id, student_name FROM students WHERE parent_id = :parent_id ORDER BY student_name ASC");
$stmt->execute([':parent_id' => $parentId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT p.*, s.student_name
     FROM payments p
     INNER JOIN students s ON s.id = p.student_id
     WHERE p.parent_id = :parent_id
     ORDER BY p.uploaded_at DESC"
);
$stmt->execute([':parent_id' => $parentId]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Fee Payments</title>
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
                <h1 class="h3 mb-4 text-gray-800">Fee Payments</h1>

                <?php if ($message): ?>
                    <div class="alert <?php echo (stripos($message, 'Error') === 0) ? 'alert-danger' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Upload Payment Proof</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_payment">

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
                                <label>Amount Paid (AUD)</label>
                                <input type="number" class="form-control" name="amount" step="0.01" min="0">
                            </div>

                            <div class="form-group">
                                <label>Payment Screenshot (JPG/PNG/PDF)</label>
                                <input type="file" class="form-control" name="proof" required>
                            </div>

                            <button type="submit" class="btn btn-primary">Upload Proof</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Payment History</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Uploaded</th>
                                    <th>Proof</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($payment['student_name']); ?></td>
                                        <td><?php echo number_format((float)$payment['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($payment['status']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['uploaded_at']); ?></td>
                                        <td>
                                            <a href="<?php echo htmlspecialchars($payment['proof_path']); ?>" target="_blank">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payments)): ?>
                                    <tr><td colspan="5" class="text-center">No payments uploaded yet.</td></tr>
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
