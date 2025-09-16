<?php
// This is view.php
require_once "include/config.php";
require_once "include/auth.php";

// you have to be logged in to view this page
// This function is in utils.php
require_login();

?>
<html>
<head>
    <style>
        body {
            font-size: 0.85rem !important; /* or smaller like 0.8rem */
        }

        table, input, select, label, .form-control, .btn, .card, .accordion {
            font-size: 0.85rem !important;
        }

        h1, h6 {
            font-size: 1rem !important;
        }
        .topbar .mx-auto {
            text-align: center;
        }
    </style>
</head>
</html>

<!-- Topbar -->
<!-- Topbar -->
<nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow pt-4">

    <!-- Centered Company Name and Project Switch -->
    <div class="mx-auto d-flex flex-column justify-content-center align-items-center">

        <h4 class="m-0 font-weight-light text-primary text-center">
            <?php echo htmlentities(logged_in_companyName()); ?>
        </h4>

        <?php if ($_SESSION['role'] === 'Company Admin'): ?>
            <form action="/include/switch_project.php" method="POST" class="form-inline mt-2">
                <div class="form-group d-flex align-items-center">
                    <label for="projectID" class="mr-2 mb-0 small text-primary font-weight-bold">Switch Project:</label>
                    <select name="projectID" id="projectID" onchange="this.form.submit()" class="form-control form-control-sm">
                        <?php foreach ($_SESSION['projects'] as $proj): ?>
                            <option value="<?= $proj['projectID'] ?>" <?= $proj['projectID'] == $_SESSION['projectID'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($proj['projectName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        <?php elseif ($_SESSION['role'] === 'Manager' || $_SESSION['role'] === 'Staff'): ?>
            <div class="mt-2">
                <span class="small text-primary font-weight-bold">Project:</span>
                <span class="small"><?= htmlspecialchars($_SESSION['projectName']) ?></span>
            </div>
        <?php endif; ?>

    </div>

    <!-- Topbar Navbar -->
    <ul class="navbar-nav ml-auto">
        <!-- Nav Item - User Information -->
        <li class="nav-item dropdown no-arrow">
            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
               data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
               <span class="mr-2 d-none d-lg-inline text-gray-600 small">
    You are logged in as:
    <strong><?php echo htmlentities(logged_in_username()) . ' with Role : ' . htmlentities(logged_in_user_role()) ?></strong>
</span>

                <img class="img-profile rounded-circle" src="assets/images/undraw_profile.svg">
            </a>

            <!-- Dropdown - User Information -->
            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in"
                 aria-labelledby="userDropdown">
                <div class="dropdown-divider"></div>
                <form action="logout.php" method="POST">
                    <button class="dropdown-item">
                        <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                        Logout
                    </button>
                </form>
            </div>
        </li>
    </ul>
</nav>

<!-- End of Topbar -->
