<?php
// include/admin-header.php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
$profileUrl = ($role === 'parent') ? 'parentProfile.php' : 'adminProfile.php';
?>
<style>
    body { font-size: 0.85rem !important; }
    table, input, select, label, .form-control, .btn, .card, .accordion { font-size: 0.85rem !important; }
    h1, h6 { font-size: 1rem !important; }
    .topbar .mx-auto { text-align: center; }
</style>

<nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow pt-4">
    <ul class="navbar-nav ml-auto">
        <li class="nav-item dropdown no-arrow">
            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
               data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">

                <span class="mr-2 d-none d-lg-inline text-gray-600 small">
                    You are logged in as:
                    <strong>
                        <?php echo htmlentities(logged_in_username()) . ' (Role: ' . htmlentities(logged_in_user_role()) . ')'; ?>
                    </strong>
                </span>

                <img class="img-profile rounded-circle" src="assets/images/undraw_profile.svg" alt="profile">
            </a>

            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
                <a class="dropdown-item" href="<?php echo htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                    My Profile
                </a>

                <div class="dropdown-divider"></div>

                <!-- Logout stays POST -->
                <form action="logout.php" method="POST" style="margin:0;">
                    <button type="submit" class="dropdown-item">
                        <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                        Logout
                    </button>
                </form>
            </div>
        </li>
    </ul>
</nav>
