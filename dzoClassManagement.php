<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') {
    header("Location: index-admin.php");
    exit;
}

$message = "";
$reloadPage = false;

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

// DELETE (admin)
if (isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM students WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $message = "Enrollment deleted successfully.";
            $reloadPage = true;
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// APPROVE / REJECT
if (isset($_GET['action'], $_GET['student'])) {
    $action = strtolower($_GET['action']);
    $studentId = (int)$_GET['student'];

    if ($studentId > 0 && in_array($action, ['approve', 'reject'], true)) {
        $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';
        $stmt = $pdo->prepare("UPDATE students SET approval_status = :st WHERE id = :id");
        $stmt->execute([':st' => $newStatus, ':id' => $studentId]);

        $message = "Enrollment {$newStatus} successfully.";
        $reloadPage = true;
    }
}

// VIEW DETAILS
$viewStudent = null;
if (isset($_GET['view'])) {
    $viewId = (int)$_GET['view'];
    if ($viewId > 0) {
        $stmtView = $pdo->prepare("
            SELECT s.*,
                   p.full_name AS parent_name,
                   p.email AS parent_email,
                   p.phone AS parent_phone,
                   p.address AS parent_address,
                   p.occupation AS parent_occupation
            FROM students s
            LEFT JOIN parents p ON p.id = s.parentId
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmtView->execute([':id' => $viewId]);
        $viewStudent = $stmtView->fetch(PDO::FETCH_ASSOC);
    }
}

// FETCH ALL
$stmt = $pdo->prepare("
    SELECT s.*,
           p.full_name AS parent_name,
           p.email AS parent_email,
           p.phone AS parent_phone
    FROM students s
    LEFT JOIN parents p ON p.id = s.parentId
    ORDER BY s.id DESC
");
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

function badge_class($st) {
    $st = strtolower($st ?? '');
    if ($st === 'pending') return 'warning';
    if ($st === 'approved') return 'success';
    if ($st === 'rejected') return 'danger';
    return 'secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dzo Class Management</title>

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
                <h1 class="h3 mb-2 text-gray-800">Dzo Class Management</h1>

                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const msg = <?php echo json_encode($message); ?>;
                    const reload = <?php echo $reloadPage ? 'true' : 'false'; ?>;
                    if (msg) {
                        Swal.fire({ icon: msg.toLowerCase().startsWith('error') ? 'error':'success', title: msg, showConfirmButton: false, timer: 1400 })
                        .then(() => { if (reload) window.location.href = 'dzoClassManagement.php'; });
                    }
                });
                </script>

                <div class="alert alert-info shadow-sm">
                    <strong>Note:</strong>
                    <ul class="mb-0">
                        <li>Approve only after checking payment proof/reference.</li>
                        <li>Parents can edit/delete only while Pending.</li>
                        <li>Use Attendance menu to mark attendance for Approved students.</li>
                    </ul>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">All Enrollments</h6>
                        <a href="attendanceManagement.php" class="btn btn-success btn-sm">
                            <i class="fas fa-clipboard-check"></i> Attendance
                        </a>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Class</th>
                                    <th>Plan</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                    <th>Proof</th>
                                    <th>Reg Date</th>
                                    <th>Status</th>
                                    <th>Parent</th>
                                    <th style="width:280px;">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($students as $i => $s): ?>
                                    <?php $st = strtolower($s['approval_status'] ?? ''); ?>
                                    <tr>
                                        <td><?php echo (int)($i+1); ?></td>
                                        <td><?php echo htmlspecialchars($s['student_id'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['student_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['class_option'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($s['payment_plan'] ?? '-'); ?></td>
                                        <td><?php echo isset($s['payment_amount']) ? '$'.htmlspecialchars($s['payment_amount']) : '-'; ?></td>
                                        <td style="max-width:220px; white-space:normal;">
                                            <?php echo htmlspecialchars($s['payment_reference'] ?? '-'); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($s['payment_proof'])): ?>
                                                <a href="<?php echo htmlspecialchars($s['payment_proof']); ?>" target="_blank">View</a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($s['registration_date'] ?? ''); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo badge_class($s['approval_status']); ?>" style="padding:8px 10px;">
                                                <?php echo htmlspecialchars($s['approval_status'] ?? ''); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                                $pn = $s['parent_name'] ?? '-';
                                                $pe = $s['parent_email'] ?? '';
                                                echo htmlspecialchars($pn . ($pe ? " ($pe)" : ""));
                                            ?>
                                        </td>
                                        <td>
                                            <a class="btn btn-info btn-sm" href="dzoClassManagement.php?view=<?php echo (int)$s['id']; ?>">View</a>

                                            <a class="btn btn-danger btn-sm delete-btn" href="#" data-id="<?php echo (int)$s['id']; ?>">Delete</a>

                                            <?php if ($st === 'pending'): ?>
                                                <a class="btn btn-success btn-sm"
                                                   href="dzoClassManagement.php?action=approve&student=<?php echo (int)$s['id']; ?>"
                                                   onclick="return confirm('Approve this enrollment?');">
                                                    Approve
                                                </a>
                                                <a class="btn btn-warning btn-sm"
                                                   href="dzoClassManagement.php?action=reject&student=<?php echo (int)$s['id']; ?>"
                                                   onclick="return confirm('Reject this enrollment?');">
                                                    Reject
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if ($viewStudent): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Enrollment Details</h6>
                            <a href="dzoClassManagement.php" class="btn btn-secondary btn-sm">Close</a>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="font-weight-bold mb-3">Student</h6>
                                    <p><strong>Student ID:</strong> <?php echo htmlspecialchars($viewStudent['student_id'] ?? '-'); ?></p>
                                    <p><strong>Name:</strong> <?php echo htmlspecialchars($viewStudent['student_name'] ?? '-'); ?></p>
                                    <p><strong>DOB:</strong> <?php echo htmlspecialchars($viewStudent['dob'] ?? '-'); ?></p>
                                    <p><strong>Gender:</strong> <?php echo htmlspecialchars($viewStudent['gender'] ?? '-'); ?></p>
                                    <p><strong>Medical Issue:</strong> <?php echo htmlspecialchars($viewStudent['medical_issue'] ?? '-'); ?></p>
                                    <hr>
                                    <p><strong>Class:</strong> <?php echo htmlspecialchars($viewStudent['class_option'] ?? '-'); ?></p>
                                    <p><strong>Payment Plan:</strong> <?php echo htmlspecialchars($viewStudent['payment_plan'] ?? '-'); ?></p>
                                    <p><strong>Amount:</strong> <?php echo isset($viewStudent['payment_amount']) ? '$'.htmlspecialchars($viewStudent['payment_amount']) : '-'; ?></p>
                                    <p><strong>Reference:</strong> <?php echo htmlspecialchars($viewStudent['payment_reference'] ?? '-'); ?></p>
                                    <p><strong>Proof:</strong>
                                        <?php if (!empty($viewStudent['payment_proof'])): ?>
                                            <a href="<?php echo htmlspecialchars($viewStudent['payment_proof']); ?>" target="_blank">View proof</a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </p>
                                    <p><strong>Status:</strong> <?php echo htmlspecialchars($viewStudent['approval_status'] ?? '-'); ?></p>
                                </div>

                                <div class="col-md-6">
                                    <h6 class="font-weight-bold mb-3">Parent</h6>
                                    <p><strong>Name:</strong> <?php echo htmlspecialchars($viewStudent['parent_name'] ?? '-'); ?></p>
                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($viewStudent['parent_email'] ?? '-'); ?></p>
                                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($viewStudent['parent_phone'] ?? '-'); ?></p>
                                    <p><strong>Address:</strong> <?php echo htmlspecialchars($viewStudent['parent_address'] ?? '-'); ?></p>
                                    <p><strong>Occupation:</strong> <?php echo htmlspecialchars($viewStudent['parent_occupation'] ?? '-'); ?></p>
                                </div>
                            </div>

                            <hr>
                            <?php $st2 = strtolower($viewStudent['approval_status'] ?? ''); ?>
                            <?php if ($st2 === 'pending'): ?>
                                <a class="btn btn-success"
                                   href="dzoClassManagement.php?action=approve&student=<?php echo (int)$viewStudent['id']; ?>"
                                   onclick="return confirm('Approve this enrollment?');">Approve</a>
                                <a class="btn btn-warning"
                                   href="dzoClassManagement.php?action=reject&student=<?php echo (int)$viewStudent['id']; ?>"
                                   onclick="return confirm('Reject this enrollment?');">Reject</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".delete-btn").forEach(btn => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            const id = this.getAttribute("data-id");

            Swal.fire({
                title: "Are you sure?",
                text: "This enrollment will be permanently deleted.",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#d33",
                cancelButtonColor: "#3085d6",
                confirmButtonText: "Yes, delete",
                cancelButtonText: "Cancel"
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = "dzoClassManagement.php?delete=" + id;
                }
            });
        });
    });
});
</script>

</body>
</html>
