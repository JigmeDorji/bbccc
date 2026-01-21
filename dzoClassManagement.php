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
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
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
        $viewStudent = $stmtView->fetch();
    }
}

function badge_class($st) {
    $st = strtolower($st ?? '');
    if ($st === 'pending') return 'warning';
    if ($st === 'approved') return 'success';
    if ($st === 'rejected') return 'danger';
    return 'secondary';
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
$students = $stmt->fetchAll();

/**
 * ✅ Load page-specific scripts via admin-footer.php (no duplicate jQuery/Bootstrap)
 */
$pageScripts = [
    "https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js",
    "https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js",
    "https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js",
    "https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js",
    "https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js",
    "https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js",
    "https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dzo Class Management</title>

    <!-- SB Admin 2 CSS -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <!-- DataTables (Bootstrap 4) + Buttons -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">

    <!-- SweetAlert -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .summary-card .label { font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .summary-card .value { font-size: 30px; font-weight: 800; line-height: 1.1; }

        .filters-box { background:#f8f9fc; border:1px solid #e3e6f0; padding:12px; border-radius:8px; }
        .filters-box label { font-size: 12px; font-weight: 700; margin-bottom: 6px; }

        .status-tabs .btn { margin-right: 6px; }
        .status-tabs .btn.active { box-shadow: inset 0 0 0 2px rgba(0,0,0,.08); }

        .dt-buttons .btn { margin-right: 6px; margin-bottom: 6px; }

        td.wrap { white-space: normal !important; max-width: 240px; }

        /* Ensure topbar dropdown stays above tables */
        .topbar { position: relative; z-index: 2000; }
        .dropdown-menu { z-index: 3000; }
    </style>
</head>

<body id="page-top">
<div id="wrapper">

    <?php include 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <h1 class="h3 mb-2 text-gray-800">Dzo Class Management</h1>

                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const msg = <?php echo json_encode($message); ?>;
                    const reload = <?php echo $reloadPage ? 'true' : 'false'; ?>;
                    if (msg) {
                        Swal.fire({
                            icon: msg.toLowerCase().startsWith('error') ? 'error' : 'success',
                            title: msg,
                            showConfirmButton: false,
                            timer: 1400
                        }).then(() => {
                            if (reload) window.location.href = 'dzoClassManagement.php';
                        });
                    }
                });
                </script>

                <!-- Summary Cards (OVERALL TOTALS) -->
                <div class="row mb-3">
                    <div class="col-lg-3 mb-3">
                        <div class="card shadow summary-card">
                            <div class="card-body">
                                <div class="label text-primary">Total</div>
                                <div class="value" id="sumTotal">0</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 mb-3">
                        <div class="card shadow summary-card">
                            <div class="card-body">
                                <div class="label text-warning">Pending</div>
                                <div class="value" id="sumPending">0</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 mb-3">
                        <div class="card shadow summary-card">
                            <div class="card-body">
                                <div class="label text-success">Approved</div>
                                <div class="value" id="sumApproved">0</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 mb-3">
                        <div class="card shadow summary-card">
                            <div class="card-body">
                                <div class="label text-danger">Rejected</div>
                                <div class="value" id="sumRejected">0</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-box mb-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between">
                        <div class="status-tabs mb-2">
                            <button class="btn btn-sm btn-primary active" type="button" data-status="all">All</button>
                            <button class="btn btn-sm btn-warning" type="button" data-status="pending">Pending</button>
                            <button class="btn btn-sm btn-success" type="button" data-status="approved">Approved</button>
                            <button class="btn btn-sm btn-danger" type="button" data-status="rejected">Rejected</button>
                        </div>

                        <div class="mb-2">
                            <a href="attendanceManagement.php" class="btn btn-success btn-sm">
                                <i class="fas fa-clipboard-check"></i> Attendance
                            </a>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <label>Search Column</label>
                            <select class="form-control" id="colSelect">
                                <option value="-1">All Columns</option>
                                <option value="2">Student ID</option>
                                <option value="3">Student Name</option>
                                <option value="11">Parent</option>
                                <option value="7">Reference</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-2">
                            <label>Search</label>
                            <input type="text" class="form-control" id="searchBox" placeholder="Type anything... (instant)">
                        </div>

                        <div class="col-md-3 mb-2">
                            <label>&nbsp;</label>
                            <button class="btn btn-secondary btn-block" id="resetBtn" type="button">
                                Reset Filters
                            </button>
                        </div>
                    </div>

                    <div class="small text-muted mt-2">
                        Tip: Use the export buttons (Copy/Excel/CSV/Print) above the table.
                    </div>
                </div>

                <!-- Table -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Enrollments</h6>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="enrollTable" class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>DB ID</th>
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
                                        <td><?php echo (int)($i + 1); ?></td>
                                        <td><?php echo (int)($s['id'] ?? 0); ?></td>
                                        <td><?php echo htmlspecialchars($s['student_id'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['student_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($s['class_option'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($s['payment_plan'] ?? '-'); ?></td>
                                        <td><?php echo isset($s['payment_amount']) ? '$' . htmlspecialchars($s['payment_amount']) : '-'; ?></td>
                                        <td class="wrap"><?php echo htmlspecialchars($s['payment_reference'] ?? '-'); ?></td>
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

                <!-- View details -->
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
                                        <?php else: ?>-<?php endif; ?>
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

        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>

<script>
function updateSummaryOverall(dt) {
    // Overall totals: ignore filtering/search
    const rows = dt.rows({ search: 'none' }).data();

    let total = rows.length;
    let pending = 0, approved = 0, rejected = 0;

    for (let i = 0; i < rows.length; i++) {
        const statusHtml = rows[i][10] || "";
        const text = $("<div>").html(statusHtml).text().trim().toLowerCase();

        if (text === 'pending') pending++;
        else if (text === 'approved') approved++;
        else if (text === 'rejected') rejected++;
    }

    $('#sumTotal').text(total);
    $('#sumPending').text(pending);
    $('#sumApproved').text(approved);
    $('#sumRejected').text(rejected);
}

$(document).ready(function () {

    const dt = $('#enrollTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[1, 'desc']],
        columnDefs: [
            { targets: [1], visible: false },
            { targets: [12], orderable: false }
        ],
        dom: "<'row mb-2'<'col-md-6'B><'col-md-6 text-md-right'l>>" +
             "<'row'<'col-12'tr>>" +
             "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: [
            { extend: 'copyHtml5', className: 'btn btn-sm btn-outline-primary' },
            { extend: 'csvHtml5', className: 'btn btn-sm btn-outline-primary' },
            { extend: 'excelHtml5', className: 'btn btn-sm btn-outline-primary' },
            { extend: 'print', className: 'btn btn-sm btn-outline-primary' }
        ]
    });

    // Calculate totals ONCE (keep constant)
    updateSummaryOverall(dt);

    // Search box
    $('#searchBox').on('input', function () {
        const col = parseInt($('#colSelect').val(), 10);
        const val = this.value;

        dt.columns().search('');

        if (col === -1) {
            dt.search(val).draw();
        } else {
            dt.search('');
            dt.column(col).search(val).draw();
        }
    });

    $('#colSelect').on('change', function () {
        $('#searchBox').trigger('input');
    });

    // Status tabs (column 10)
    $('.status-tabs button').on('click', function () {
        $('.status-tabs button').removeClass('active');
        $(this).addClass('active');

        const st = ($(this).data('status') || 'all').toLowerCase();

        dt.search('');
        dt.columns().search('');
        $('#searchBox').val('');

        if (st === 'all') {
            dt.column(10).search('').draw();
        } else {
            dt.column(10).search(st, true, false).draw();
        }
    });

    // Reset
    $('#resetBtn').on('click', function () {
        $('.status-tabs button').removeClass('active');
        $('.status-tabs button[data-status="all"]').addClass('active');

        $('#colSelect').val('-1');
        $('#searchBox').val('');

        dt.search('');
        dt.columns().search('');
        dt.column(10).search('');
        dt.draw();
    });

    // SweetAlert Delete
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
