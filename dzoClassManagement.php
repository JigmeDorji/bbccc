<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');

// Recommended: parents should not access this management page
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

// ---------------- VIEW STUDENT DETAILS ----------------
$viewStudent = null;
if (isset($_GET['view'])) {
    try {
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
    } catch (Exception $e) {
        $message = "Error loading student details: " . $e->getMessage();
    }
}

// ---------------- FETCH ALL STUDENTS ----------------
$students = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*,
               p.full_name AS parent_name,
               p.email AS parent_email
        FROM students s
        LEFT JOIN parents p ON p.id = s.parentId
        ORDER BY s.id DESC
    ");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error fetching students: " . $e->getMessage();
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

                <div class="alert alert-info shadow-sm">
                    <strong>Info:</strong>
                    <ul class="mb-0">
                        <li>This page shows <strong>all enrolled students</strong>.</li>
                        <li><strong>View</strong> shows full details. <strong>Edit</strong> opens the student form. <strong>Delete</strong> removes the enrollment.</li>
                        <li>Approved students can still be managed by admin. (Parents are restricted.)</li>
                    </ul>
                </div>

                <?php if (!empty($message)): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                icon: 'info',
                                title: <?php echo json_encode($message); ?>,
                                showConfirmButton: true
                            });
                        });
                    </script>
                <?php endif; ?>

                <!-- ✅ ALL STUDENTS TABLE -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">All Enrolled Students</h6>
                        <a href="studentSetup.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add New Student
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
                                    <th>DOB</th>
                                    <th>Gender</th>
                                    <th>Reg Date</th>
                                    <th>Status</th>
                                    <th>Parent</th>
                                    <th style="width:220px;">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($students as $i => $s): ?>
                                    <?php
                                        $st = strtolower($s['approval_status'] ?? '');
                                        $badge = 'secondary';
                                        if ($st === 'pending') $badge = 'warning';
                                        if ($st === 'approved') $badge = 'success';
                                        if ($st === 'rejected') $badge = 'danger';
                                    ?>
                                    <tr>
                                        <td><?php echo (int)($i + 1); ?></td>
                                        <td><?php echo htmlspecialchars($s['student_id'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['student_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['dob'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['gender'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['registration_date'] ?? ''); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $badge; ?>" style="padding:8px 10px;">
                                                <?php echo htmlspecialchars($s['approval_status'] ?? ''); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                                $pn = $s['parent_name'] ?? '';
                                                $pe = $s['parent_email'] ?? '';
                                                echo $pn ? htmlspecialchars($pn . ($pe ? " ($pe)" : "")) : "-";
                                            ?>
                                        </td>
                                        <td>
                                            <a class="btn btn-info btn-sm" href="dzoClassManagement.php?view=<?php echo (int)$s['id']; ?>">
                                                View
                                            </a>
                                            <a class="btn btn-primary btn-sm" href="studentSetup.php?edit=<?php echo (int)$s['id']; ?>">
                                                Edit
                                            </a>
                                            <a class="btn btn-danger btn-sm delete-btn" href="#" data-id="<?php echo (int)$s['id']; ?>">
                                                Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ✅ VIEW DETAILS PANEL -->
                <?php if ($viewStudent): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Student Details</h6>
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
                                    <p><strong>Reg Date:</strong> <?php echo htmlspecialchars($viewStudent['registration_date'] ?? '-'); ?></p>
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

                            <a class="btn btn-primary" href="studentSetup.php?edit=<?php echo (int)$viewStudent['id']; ?>">
                                Edit
                            </a>
                            <a class="btn btn-danger delete-btn" href="#" data-id="<?php echo (int)$viewStudent['id']; ?>">
                                Delete
                            </a>
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
                    window.location.href = "studentSetup.php?delete=" + id;
                }
            });
        });
    });
});
</script>

</body>
</html>
