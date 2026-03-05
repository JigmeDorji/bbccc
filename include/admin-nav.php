<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$currentPage = basename($_SERVER['PHP_SELF']);

function isSystemOwner() { return ($_SESSION['role'] ?? '') === 'Administrator'; }
function isCompanyAdmin() { return ($_SESSION['role'] ?? '') === 'company_admin'; }
function isParent() { return strtolower($_SESSION['role'] ?? '') === 'parent'; }
?>

<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar" role="navigation" aria-label="Main sidebar navigation">
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
                    <a class="collapse-item <?= ($currentPage == 'bannerSetup.php') ? 'active' : '' ?>" href="bannerSetup.php"><i class="fas fa-image fa-sm mr-1 text-muted"></i> Setup Banner</a>
                    <a class="collapse-item <?= ($currentPage == 'aboutPageSetup.php') ? 'active' : '' ?>" href="aboutPageSetup.php"><i class="fas fa-info-circle fa-sm mr-1 text-muted"></i> Setup About Page</a>
                    <a class="collapse-item <?= ($currentPage == 'serviceSetup.php') ? 'active' : '' ?>" href="serviceSetup.php"><i class="fas fa-bullhorn fa-sm mr-1 text-muted"></i> Post Event</a>
                    <a class="collapse-item <?= ($currentPage == 'ourTeamSetup.php') ? 'active' : '' ?>" href="ourTeamSetup.php"><i class="fas fa-users fa-sm mr-1 text-muted"></i> Team Setup</a>
                    <a class="collapse-item <?= ($currentPage == 'viewFeedback.php') ? 'active' : '' ?>" href="viewFeedback.php"><i class="fas fa-envelope fa-sm mr-1 text-muted"></i> Contact Messages</a>
                </div>
            </div>
        </li>

        <!-- Dzo Class Management -->
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseOrders"
               aria-expanded="true" aria-controls="collapseOrders">
                <i class="fas fa-graduation-cap"></i>
                <span>Dzo Class Mgmt</span>
            </a>

            <div id="collapseOrders" class="collapse <?= in_array($currentPage, ['dzoClassManagement.php','admin-enrolments.php','feesManagement.php','admin-fee-verification.php','attendanceManagement.php','admin-attendance.php','admin-class-setup.php','admin-teacher-setup.php','admin-assign-class.php','feesSetting.php','admin-bank-settings.php','admin-parent-pins.php']) ? 'show' : '' ?>">
                <div class="bg-white py-2 collapse-inner rounded">
                    <h6 class="collapse-header">Setup</h6>
                    <a class="collapse-item <?= in_array($currentPage, ['admin-class-setup.php','admin-teacher-setup.php']) ? 'active' : '' ?>" href="admin-class-setup.php"><i class="fas fa-chalkboard fa-sm mr-1 text-muted"></i> Classes & Teachers</a>
                    <a class="collapse-item <?= ($currentPage == 'admin-assign-class.php') ? 'active' : '' ?>" href="admin-assign-class.php"><i class="fas fa-user-plus fa-sm mr-1 text-muted"></i> Assign Students</a>
                    <a class="collapse-item <?= ($currentPage == 'feesSetting.php') ? 'active' : '' ?>" href="feesSetting.php"><i class="fas fa-dollar-sign fa-sm mr-1 text-muted"></i> Fees Settings</a>
                    <a class="collapse-item <?= ($currentPage == 'admin-bank-settings.php') ? 'active' : '' ?>" href="admin-bank-settings.php"><i class="fas fa-university fa-sm mr-1 text-muted"></i> Bank Settings</a>
                    <a class="collapse-item <?= ($currentPage == 'admin-parent-pins.php') ? 'active' : '' ?>" href="admin-parent-pins.php"><i class="fas fa-key fa-sm mr-1 text-muted"></i> Parent Kiosk PINs</a>
                    <h6 class="collapse-header">Operations</h6>
                    <a class="collapse-item <?= in_array($currentPage, ['dzoClassManagement.php','admin-enrolments.php']) ? 'active' : '' ?>" href="dzoClassManagement.php"><i class="fas fa-file-signature fa-sm mr-1 text-muted"></i> Enrolments</a>
                    <a class="collapse-item <?= in_array($currentPage, ['feesManagement.php','admin-fee-verification.php']) ? 'active' : '' ?>" href="feesManagement.php"><i class="fas fa-money-check-alt fa-sm mr-1 text-muted"></i> Fees</a>
                    <a class="collapse-item <?= ($currentPage == 'attendanceManagement.php') ? 'active' : '' ?>" href="attendanceManagement.php"><i class="fas fa-clipboard-check fa-sm mr-1 text-muted"></i> Attendance</a>
                    <a class="collapse-item <?= ($currentPage == 'admin-attendance.php') ? 'active' : '' ?>" href="admin-attendance.php"><i class="fas fa-door-open fa-sm mr-1 text-muted"></i> Kiosk Sign In/Out</a>
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
                    <a class="collapse-item <?= ($currentPage == 'eventManagement.php') ? 'active' : '' ?>" href="eventManagement.php"><i class="fas fa-calendar-plus fa-sm mr-1 text-muted"></i> Manage Events</a>
                    <a class="collapse-item <?= ($currentPage == 'bookingManagement.php') ? 'active' : '' ?>" href="bookingManagement.php"><i class="fas fa-ticket-alt fa-sm mr-1 text-muted"></i> Booking Requests</a>
                </div>
            </div>
        </li>

    <?php } ?>

    <!-- Parent-only menu -->
    <?php if (isParent()) { ?>
        <hr class="sidebar-divider">
        <div class="sidebar-heading">Parent Portal</div>

        <li class="nav-item <?= ($currentPage == 'parentProfile.php') ? 'active' : '' ?>">
            <a class="nav-link" href="parentProfile.php">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
        </li>

        <li class="nav-item <?= in_array($currentPage, ['parent-children.php','parent-enrolment.php']) ? 'active' : '' ?>">
            <a class="nav-link" href="parent-enrolment.php">
                <i class="fas fa-file-signature"></i>
                <span>Children & Enrolment</span>
            </a>
        </li>

        <li class="nav-item <?= in_array($currentPage, ['parent-fees.php','parentFeesPayment.php']) ? 'active' : '' ?>">
            <a class="nav-link" href="parent-fees.php">
                <i class="fas fa-money-check-alt"></i>
                <span>Fees & Payments</span>
            </a>
        </li>

        <li class="nav-item <?= in_array($currentPage, ['parent-attendance.php','attendanceParent.php']) ? 'active' : '' ?>">
            <a class="nav-link" href="parent-attendance.php">
                <i class="fas fa-clipboard-check"></i>
                <span>Attendance</span>
            </a>
        </li>

    <?php } ?>

</ul>
