<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$currentPage = basename($_SERVER['PHP_SELF']);

function isSystemOwner() { return ($_SESSION['role'] ?? '') === 'Administrator'; }
function isCompanyAdmin() { return ($_SESSION['role'] ?? '') === 'company_admin'; }
function isParent() { return strtolower($_SESSION['role'] ?? '') === 'parent'; }
?>

<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index-admin.php">
        <div class="sidebar-brand-icon">
            <img src="bbccassests/img/logo/logo5.jpg" alt="Bhutanese Centre Logo" class="img-thumbnail">
        </div>
        <div class="sidebar-brand-text mx-3">Bhutanese Centre</div>
    </a>

    <hr class="sidebar-divider my-0">

    <li class="nav-item <?= ($currentPage == 'index-admin.php') ? 'active' : '' ?>">
        <a class="nav-link" href="index-admin.php">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
    </li>

    <?php if (!isParent()) { ?>

        <hr class="sidebar-divider">

        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseWebsite"
               aria-expanded="true" aria-controls="collapseWebsite">
                <i class="fas fa-cogs"></i>
                <span>Website Settings</span>
            </a>
            <div id="collapseWebsite" class="collapse <?= in_array($currentPage, ['bannerSetup.php','aboutPageSetup.php','serviceSetup.php','ourTeamSetup.php','viewFeedback.php']) ? 'show' : '' ?>">
                <div class="bg-white py-2 collapse-inner rounded">
                    <a class="collapse-item <?= ($currentPage == 'bannerSetup.php') ? 'active' : '' ?>" href="bannerSetup.php">Setup Banner</a>
                    <a class="collapse-item <?= ($currentPage == 'aboutPageSetup.php') ? 'active' : '' ?>" href="aboutPageSetup.php">Setup About Page</a>
                    <a class="collapse-item <?= ($currentPage == 'serviceSetup.php') ? 'active' : '' ?>" href="serviceSetup.php">Post Event</a>
                    <a class="collapse-item <?= ($currentPage == 'ourTeamSetup.php') ? 'active' : '' ?>" href="ourTeamSetup.php">Team Setup</a>
                    <a class="collapse-item <?= ($currentPage == 'viewFeedback.php') ? 'active' : '' ?>" href="viewFeedback.php">Contact Messages</a>
                </div>
            </div>
        </li>

        <!-- Dzo Class Management -->
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseOrders"
               aria-expanded="true" aria-controls="collapseOrders">
                <i class="fas fa-box"></i>
                <span>Dzo Class Management</span>
            </a>

            <div id="collapseOrders" class="collapse <?= in_array($currentPage, ['dzoClassManagement.php','attendanceManagement.php']) ? 'show' : '' ?>">
                <div class="bg-white py-2 collapse-inner rounded">
                    <a class="collapse-item <?= ($currentPage == 'dzoClassManagement.php') ? 'active' : '' ?>" href="dzoClassManagement.php">Enrollments</a>
                    <a class="collapse-item <?= ($currentPage == 'feesManagement.php') ? 'active' : '' ?>" href="feesManagement.php">
                        Fees Management
                    </a>
                    <a class="collapse-item <?= ($currentPage == 'attendanceManagement.php') ? 'active' : '' ?>" href="attendanceManagement.php">Attendance</a>
                </div>
            </div>
        </li>

        <!-- Event Management -->
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseEvents"
               aria-expanded="true" aria-controls="collapseEvents">
                <i class="fas fa-calendar-alt"></i>
                <span>Event Management</span>
            </a>
            <div id="collapseEvents" class="collapse <?= in_array($currentPage, ['eventManagement.php','bookingManagement.php']) ? 'show' : '' ?>">
                <div class="bg-white py-2 collapse-inner rounded">
                    <a class="collapse-item <?= ($currentPage == 'eventManagement.php') ? 'active' : '' ?>" href="eventManagement.php">Manage Events</a>
                    <a class="collapse-item <?= ($currentPage == 'bookingManagement.php') ? 'active' : '' ?>" href="bookingManagement.php">Booking Requests</a>
                </div>
            </div>
        </li>

    <?php } ?>

    <!-- Parent-only menu -->
    <?php if (isParent()) { ?>
        <hr class="sidebar-divider">
        <li class="nav-item <?= ($currentPage == 'parentProfile.php') ? 'active' : '' ?>">
            <a class="nav-link" href="parentProfile.php">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
        </li>
        
        <li class="nav-item <?= ($currentPage == 'studentSetup.php') ? 'active' : '' ?>">
            <a class="nav-link" href="studentSetup.php">
                <i class="fas fa-user-graduate"></i>
                <span>Add Student</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'attendanceParent.php') ? 'active' : '' ?>">
            <a class="nav-link" href="attendanceParent.php">
                <i class="fas fa-clipboard-check"></i>
                <span>Attendance</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'parentFeesPayment.php') ? 'active' : '' ?>">
            <a class="nav-link" href="parentFeesPayment.php">
                <i class="fas fa-money-check-alt"></i>
                <span>Fees Payments</span>
            </a>
        </li>


    <?php } ?>

</ul>
