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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    try {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $approval = $_POST['approval_status'] ?? 'Pending';
        $note = trim($_POST['approval_note'] ?? '');
        $status = ($approval === 'Approved') ? 'Active' : 'Pending';

        if ($approval === 'Approved') {
            $stmt = $pdo->prepare(
                "SELECT status FROM payments WHERE student_id = :student_id ORDER BY uploaded_at DESC LIMIT 1"
            );
            $stmt->execute([':student_id' => $studentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment || ($payment['status'] ?? '') !== 'Approved') {
                throw new Exception("Payment must be approved before student approval.");
            }
        }

        $stmt = $pdo->prepare(
            "UPDATE students
             SET approval_status = :approval_status,
                 status = :status,
                 approved_by = :approved_by,
                 approved_at = NOW(),
                 approval_note = :approval_note
             WHERE id = :id"
        );
        $stmt->execute([
            ':approval_status' => $approval,
            ':status' => $status,
            ':approved_by' => $_SESSION['userid'] ?? null,
            ':approval_note' => $note === '' ? null : $note,
            ':id' => $studentId
        ]);

        $message = "Student approval updated.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_payment') {
    try {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $status = $_POST['payment_status'] ?? 'Pending';
        $note = trim($_POST['payment_note'] ?? '');

        $stmt = $pdo->prepare(
            "UPDATE payments
             SET status = :status,
                 reviewed_by = :reviewed_by,
                 reviewed_at = NOW(),
                 notes = :notes
             WHERE id = :id"
        );
        $stmt->execute([
            ':status' => $status,
            ':reviewed_by' => $_SESSION['userid'] ?? null,
            ':notes' => $note === '' ? null : $note,
            ':id' => $paymentId
        ]);

        $message = "Payment status updated.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$students = $pdo->query(
    "SELECT s.*, p.full_name AS parent_name, p.email AS parent_email
     FROM students s
     INNER JOIN parents p ON p.id = s.parent_id
     ORDER BY s.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$payments = $pdo->query(
    "SELECT p.*, s.student_name, pr.full_name AS parent_name
     FROM payments p
     INNER JOIN students s ON s.id = p.student_id
     INNER JOIN parents pr ON pr.id = p.parent_id
     ORDER BY p.uploaded_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Approvals & Payments</title>
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
                <h1 class="h3 mb-4 text-gray-800">Student Approvals & Payments</h1>

                <?php if ($message): ?>
                    <div class="alert <?php echo (stripos($message, 'Error') === 0) ? 'alert-danger' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Student Approvals</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Parent</th>
                                    <th>Status</th>
                                    <th>Approval</th>
                                    <th>Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                        <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['parent_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['status']); ?></td>
                                        <td><?php echo htmlspecialchars($student['approval_status']); ?></td>
                                        <td>
                                            <form method="POST" class="form-inline">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="student_id" value="<?php echo (int)$student['id']; ?>">
                                                <select name="approval_status" class="form-control form-control-sm mr-2">
                                                    <option value="Pending" <?php echo ($student['approval_status'] === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="Approved" <?php echo ($student['approval_status'] === 'Approved') ? 'selected' : ''; ?>>Approved</option>
                                                    <option value="Rejected" <?php echo ($student['approval_status'] === 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                                                </select>
                                                <input type="text" name="approval_note" class="form-control form-control-sm mr-2" placeholder="Note">
                                                <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($students)): ?>
                                    <tr><td colspan="6" class="text-center">No students found.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Payment Proofs</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Parent</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Uploaded</th>
                                    <th>Proof</th>
                                    <th>Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($payment['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['parent_name']); ?></td>
                                        <td><?php echo number_format((float)$payment['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($payment['status']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['uploaded_at']); ?></td>
                                        <td><a href="<?php echo htmlspecialchars($payment['proof_path']); ?>" target="_blank">View</a></td>
                                        <td>
                                            <form method="POST" class="form-inline">
                                                <input type="hidden" name="action" value="update_payment">
                                                <input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>">
                                                <select name="payment_status" class="form-control form-control-sm mr-2">
                                                    <option value="Pending" <?php echo ($payment['status'] === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="Approved" <?php echo ($payment['status'] === 'Approved') ? 'selected' : ''; ?>>Approved</option>
                                                    <option value="Rejected" <?php echo ($payment['status'] === 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                                                </select>
                                                <input type="text" name="payment_note" class="form-control form-control-sm mr-2" placeholder="Note">
                                                <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payments)): ?>
                                    <tr><td colspan="7" class="text-center">No payments found.</td></tr>
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
