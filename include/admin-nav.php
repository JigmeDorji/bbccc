<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$currentPage = basename($_SERVER['PHP_SELF']); // Detect current page name

// Sample user role (you should fetch this from the database/session)
$userRole = $_SESSION['role'] ?? ''; // Expected values: 'system_owner', 'company_admin'

// Utility functions
function isSystemOwner() {
    return ($_SESSION['role'] ?? '') === 'System_owner';
}

function isCompanyAdmin() {
    return ($_SESSION['role'] ?? '') === 'company_admin';
}
?>

<!-- Sidebar -->
<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index-admin.php">
    <div class="sidebar-brand-icon">
        <img src="bbccassests/img/logo5.jpg" alt="Bhutanese Centre Logo" style="height: 40px; width: auto;">
    </div>
    <div class="sidebar-brand-text mx-3">Bhutanese Centre</div>
</a>
    <!-- <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index-admin.php">
        <div class="sidebar-brand-icon rotate-n-15">
            <i class="fas fa-building"></i>
        </div>
        <div class="sidebar-brand-text mx-3">Bhutanese Centre</div>
    </a> -->

    <hr class="sidebar-divider my-0">

    <!-- Dashboard -->
    <li class="nav-item <?= ($currentPage == 'index-admin.php') ? 'active' : '' ?>">
        <a class="nav-link" href="index-admin.php">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
    </li>

    <hr class="sidebar-divider">


    <?php if (isSystemOwner()): ?>
        <!-- Website Settings -->
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseWebsite"
               aria-expanded="true" aria-controls="collapseWebsite">
                <i class="fas fa-cogs"></i>
                <span>Website Settings</span>
            </a>
            <div id="collapseWebsite" class="collapse <?= in_array($currentPage, ['bannerSetup.php', 'aboutPageSetup.php', 'menuSetup.php', 'ourTeamSetup.php']) ? 'show' : '' ?>">
                <div class="bg-white py-2 collapse-inner rounded">
                    <a class="collapse-item <?= ($currentPage == 'bannerSetup.php') ? 'active' : '' ?>" href="bannerSetup.php">Setup Banner</a>
                    <a class="collapse-item <?= ($currentPage == 'aboutPageSetup.php') ? 'active' : '' ?>" href="aboutPageSetup.php">Setup About Page</a>
                    <a class="collapse-item <?= ($currentPage == 'menuSetup.php') ? 'active' : '' ?>" href="menuSetup.php">Setup Our Services</a>
                    <a class="collapse-item <?= ($currentPage == 'ourTeamSetup.php') ? 'active' : '' ?>" href="ourTeamSetup.php">Our Team Setup</a>
                </div>
            </div>
        </li>

        <!-- Orders & Feedback -->
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseOrders"
               aria-expanded="true" aria-controls="collapseOrders">
                <i class="fas fa-box"></i>
                <span>Orders & Feedback</span>
            </a>
            <div id="collapseOrders" class="collapse <?= in_array($currentPage, ['viewOrder.php', 'viewFeedback.php']) ? 'show' : '' ?>">
                <div class="bg-white py-2 collapse-inner rounded">
                    <a class="collapse-item <?= ($currentPage == 'viewOrder.php') ? 'active' : '' ?>" href="viewOrder.php">View Order</a>
                    <a class="collapse-item <?= ($currentPage == 'viewFeedback.php') ? 'active' : '' ?>" href="viewFeedback.php">View Messages</a>
                </div>
            </div>
        </li>
    <?php endif; ?>

</ul>
<!-- End of Sidebar -->
