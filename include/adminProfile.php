<?php
// adminProfile.php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') {
    header("Location: parentProfile.php");
    exit;
}

$username = logged_in_username();
$userRole = logged_in_user_role();

// If you later store admin info in DB, you can fetch it here.
// For now we display session/auth details only.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Admin Profile</title>

    <!-- SB Admin 2 CSS -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>

<body id="page-top">
<div id="wrapper">

    <?php include 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <h1 class="h3 mb-3 text-gray-800">My Profile</h1>

                <div class="row">
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Account Details</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <img class="img-profile rounded-circle mr-3" style="width:56px;height:56px;"
                                         src="assets/images/undraw_profile.svg" alt="profile">
                                    <div>
                                        <div class="font-weight-bold"><?php echo htmlentities($username); ?></div>
                                        <div class="text-muted small"><?php echo htmlentities($userRole); ?></div>
                                    </div>
                                </div>

                                <table class="table table-sm table-bordered">
                                    <tbody>
                                        <tr>
                                            <th style="width: 35%;">Username</th>
                                            <td><?php echo htmlentities($username); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Role</th>
                                            <td><?php echo htmlentities($userRole); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Session ID</th>
                                            <td class="text-monospace"><?php echo htmlentities(session_id()); ?></td>
                                        </tr>
                                    </tbody>
                                </table>

                                <div class="alert alert-info mb-0">
                                    This is a basic admin profile page. If you want, I can connect it to your database
                                    (e.g., admin name, phone, last login) and add “Change Password”.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <div class="card shadow">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">Quick Actions</h6>
                            </div>
                            <div class="card-body">
                                <a href="index-admin.php" class="btn btn-primary btn-sm mr-2 mb-2">
                                    <i class="fas fa-tachometer-alt"></i> Dashboard
                                </a>
                                <a href="dzoClassManagement.php" class="btn btn-success btn-sm mr-2 mb-2">
                                    <i class="fas fa-users"></i> Enrollments
                                </a>
                                <a href="attendanceManagement.php" class="btn btn-info btn-sm mr-2 mb-2">
                                    <i class="fas fa-clipboard-check"></i> Attendance
                                </a>

                                <div class="mt-3">
                                    <form action="logout.php" method="POST" style="display:inline;">
                                        <button type="submit" class="btn btn-danger btn-sm mb-2">
                                            <i class="fas fa-sign-out-alt"></i> Logout
                                        </button>
                                    </form>
                                </div>

                                <hr>
                                <div class="small text-muted">
                                    Tip: If you want “Change Password”, tell me which table stores admin users
                                    (e.g., <code>users</code>) and what the columns are.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php
        // dashboard uses charts; profile doesn't need them
        $loadCharts = false;
        include 'include/admin-footer.php';
        ?>
    </div>
</div>

</body>
</html>
