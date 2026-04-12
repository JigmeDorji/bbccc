<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

// Use REQUEST_URI so clean URLs (without .php) also work via router.php
$_uriBase    = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$currentPage = (str_ends_with($_uriBase, '.php') ? $_uriBase : $_uriBase . '.php');

function isSystemOwner() { return ($_SESSION['role'] ?? '') === 'Administrator'; }
function isCompanyAdmin() { return ($_SESSION['role'] ?? '') === 'company_admin'; }
function hasParentProfile() {
    if (strtolower($_SESSION['role'] ?? '') === 'parent') return true;
    static $checkedParent = null;
    if ($checkedParent !== null) return $checkedParent;
    $checkedParent = false;
    try {
        global $DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME;
        $pdo = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
            $DB_USER,
            $DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $uname = (string)($_SESSION['username'] ?? '');
        if ($uname !== '') {
            $stmt = $pdo->prepare("SELECT id FROM parents WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $uname]);
            $checkedParent = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $checkedParent = false;
    }
    return $checkedParent;
}
function hasTeacherProfile() {
    if (strtolower($_SESSION['role'] ?? '') === 'teacher') return true;
    static $checked = null;
    if ($checked !== null) return $checked;
    $checked = false;
    try {
        global $DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME;
        $pdo = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
            $DB_USER,
            $DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $uid = (string)($_SESSION['userid'] ?? '');
        $uname = (string)($_SESSION['username'] ?? '');
        $stmt = $pdo->prepare("
            SELECT id
            FROM teachers
            WHERE (user_id = :uid AND :uid <> '')
               OR LOWER(email) = LOWER(:em)
            LIMIT 1
        ");
        $stmt->execute([':uid' => $uid, ':em' => $uname]);
        $checked = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $checked = false;
    }
    return $checked;
}
function isParent() { return hasParentProfile(); }
function isTeacher() { return hasTeacherProfile(); }
function isPatron() { return strtolower($_SESSION['role'] ?? '') === 'patron'; }

$hasParentProfile = hasParentProfile();
$hasTeacherProfile = hasTeacherProfile();
$isMixedPortal = $hasParentProfile && $hasTeacherProfile;
$activePortal = strtolower(trim((string)($_SESSION['active_portal'] ?? '')));
if ($isMixedPortal && !in_array($activePortal, ['parent', 'teacher'], true)) {
    $activePortal = strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'teacher' ? 'teacher' : 'parent';
    $_SESSION['active_portal'] = $activePortal;
}
if (!$isMixedPortal) {
    if ($hasTeacherProfile) $activePortal = 'teacher';
    if ($hasParentProfile) $activePortal = 'parent';
}
$portalMode = strtolower(trim((string)($_GET['as'] ?? $activePortal)));
$showTeacherPortal = $hasTeacherProfile && (!$isMixedPortal || $activePortal === 'teacher');
$showParentPortal = $hasParentProfile && (!$isMixedPortal || $activePortal === 'parent');
?>

<style>
/* ════════════════════════════════════════════════════════════════
   BBCC ADMIN SIDEBAR — Professional Mobile-First Responsive Design
   ════════════════════════════════════════════════════════════════ */

/* ── 1. PREVENT SB-Admin-2 icon-only "toggled" mode from firing ── */
body.sidebar-toggled #accordionSidebar            { transform: translateX(-100%) !important; }
body:not(.sidebar-toggled) #accordionSidebar .nav-item .nav-link span,
#accordionSidebar .nav-item .nav-link span        { display: inline !important; }
#accordionSidebar .sidebar-brand-text             { display: inline !important; }

/* ── 2. SIDEBAR BASE (shared desktop + mobile) ──────────────────── */
#accordionSidebar {
    position: fixed !important;
    top: 0; left: 0;
    height: 100% !important;
    width: 260px !important;
    background: linear-gradient(180deg, #881b12 0%, #6b140d 100%) !important;
    z-index: 2000;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.2) transparent;
}
#accordionSidebar::-webkit-scrollbar { width: 4px; }
#accordionSidebar::-webkit-scrollbar-track { background: transparent; }
#accordionSidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

/* ── 3. NAV ITEMS — 52px touch targets, full text always visible ─── */
#accordionSidebar .nav-item .nav-link {
    display: flex !important;
    align-items: center !important;
    gap: 12px;
    padding: 14px 20px !important;
    color: rgba(255,255,255,0.82) !important;
    font-size: 0.875rem !important;
    font-weight: 500;
    min-height: 52px;
    border-radius: 0;
    transition: background 0.18s, color 0.18s;
    white-space: nowrap;
    text-decoration: none !important;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    text-align: left !important;  /* Override SB Admin 2 base: text-align: center */
    width: 100% !important;        /* Override SB Admin 2 base: width: 6.5rem */
}
/* Icons inside nav links — target all `i` to cover fas/far/fab/fa-fw combos */
#accordionSidebar .nav-item .nav-link > i,
#accordionSidebar .nav-item .nav-link i[class*="fa-"] {
    width: 22px !important;
    min-width: 22px !important;
    text-align: center !important;
    flex-shrink: 0 !important;
    font-size: 1rem !important;
    color: rgba(255,255,255,0.85) !important;
    visibility: visible !important;
    display: inline-block !important;
    margin-right: 0 !important;
    line-height: 1;
}
#accordionSidebar .nav-item .nav-link span {
    font-size: 0.875rem !important;  /* Override SB Admin 2 base: 0.65rem */
    display: inline !important;       /* Override SB Admin 2 base: display: block */
}
#accordionSidebar .nav-item .nav-link:hover   { background: rgba(255,255,255,0.10) !important; color: #fff !important; }
#accordionSidebar .nav-item.active .nav-link  { background: rgba(255,255,255,0.15) !important; color: #fff !important; font-weight: 600; }

/* Collapse chevron — use Unicode directly (no FA dependency) */
#accordionSidebar .nav-link[data-toggle="collapse"]::after {
    content: '›';
    font-family: inherit;
    font-weight: 700;
    font-size: 1.1rem;
    margin-left: auto;
    opacity: 0.55;
    color: rgba(255,255,255,0.8);
    transition: transform 0.22s ease;
    line-height: 1;
    display: inline-block;
    transform: rotate(90deg);
}
#accordionSidebar .nav-link[data-toggle="collapse"]:not(.collapsed)::after {
    transform: rotate(270deg);
}

/* ── CRITICAL: Override SB-Admin-2 base absolute positioning of submenus ── */
/* Without this, submenus float as popup cards to the right of the sidebar on mobile */
#accordionSidebar .nav-item .collapse,
#accordionSidebar .nav-item .collapsing {
    position: relative !important;
    left: auto !important;
    top: auto !important;
    z-index: auto !important;
}
#accordionSidebar .nav-item .collapsing {
    display: block !important;        /* Override SB Admin 2 base: display:none */
    transition: height 0.2s ease !important;
    overflow: hidden;
}

/* ── 4. SUBMENU (collapse-inner) — inline dark theme, no floating box ── */
#accordionSidebar .collapse-inner {
    background: rgba(0,0,0,0.18) !important;
    border-radius: 0 !important;
    padding: 4px 0 8px !important;
    box-shadow: none !important;
}
#accordionSidebar .collapse-item {
    display: flex !important;
    align-items: center !important;
    gap: 8px;
    padding: 11px 20px 11px 52px !important;
    font-size: 0.82rem !important;
    font-weight: 500;
    color: rgba(255,255,255,0.72) !important;
    background: transparent !important;
    border-radius: 0 !important;
    min-height: 44px;
    text-decoration: none !important;
    transition: background 0.15s, color 0.15s;
    -webkit-tap-highlight-color: transparent;
    white-space: nowrap;
}
#accordionSidebar .collapse-item:hover { background: rgba(255,255,255,0.09) !important; color: #fff !important; }
#accordionSidebar .collapse-item.active { background: rgba(255,255,255,0.14) !important; color: #fff !important; font-weight: 600; }
#accordionSidebar .collapse-item i,
#accordionSidebar .collapse-item i[class*="fa-"] { color: rgba(255,255,255,0.55) !important; font-size: 0.78rem !important; visibility: visible !important; display: inline-block !important; }
#accordionSidebar .collapse-header {
    padding: 10px 20px 3px 52px !important;
    color: rgba(255,255,255,0.38) !important;
    font-size: 0.68rem !important;
    letter-spacing: 0.09em;
    text-transform: uppercase;
    font-weight: 700;
    margin: 0;
}

/* ── 5. BRAND + DIVIDERS + HEADINGS ─────────────────────────────── */
.sidebar-brand { padding: 18px 20px !important; }
.sidebar-brand-icon img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; }
.sidebar-brand-text { font-size: 0.82rem !important; font-weight: 700 !important; color: #fff !important; letter-spacing: 0.04em; }
#accordionSidebar .sidebar-divider  { border-color: rgba(255,255,255,0.14) !important; margin: 4px 0 !important; }
#accordionSidebar .sidebar-heading  { color: rgba(255,255,255,0.38) !important; font-size: 0.68rem !important; letter-spacing: 0.09em; padding: 8px 20px 4px !important; }

/* ── 6. DESKTOP ≥992px — fixed sidebar, content offset ─────────── */
@media (min-width: 992px) {
    #accordionSidebar {
        transform: translateX(0) !important;
        transition: none !important;
        box-shadow: 2px 0 12px rgba(0,0,0,0.12);
    }
    #content-wrapper { margin-left: 260px !important; }
    #sidebarBackdrop { display: none !important; }
    .drawer-close-btn { display: none !important; }
}

/* ── 7. MOBILE <992px — off-canvas drawer ───────────────────────── */
@media (max-width: 991.98px) {
    body.sidebar-toggled #accordionSidebar { transform: translateX(-100%) !important; }

    #accordionSidebar {
        transform: translateX(-100%);
        transition: transform 0.3s cubic-bezier(.4, 0, .2, 1);
        box-shadow: none;
        width: 280px !important;
    }
    #accordionSidebar.drawer-open {
        transform: translateX(0) !important;
        box-shadow: 8px 0 40px rgba(0,0,0,0.4);
    }
    #content-wrapper {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100vw;
    }
    /* Dim + blur backdrop */
    #sidebarBackdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
        z-index: 1999;
        cursor: pointer;
        transition: opacity 0.3s;
    }
    #sidebarBackdrop.show { display: block; }

    /* Drawer header strip with close button */
    .drawer-close-btn {
        display: flex !important;
        align-items: center;
        justify-content: space-between;
        padding: 14px 16px 8px;
        border-bottom: 1px solid rgba(255,255,255,0.12);
        flex-shrink: 0;
    }
    .drawer-close-btn .brand-label {
        color: rgba(255,255,255,0.85);
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }
    .drawer-close-btn button {
        background: rgba(255,255,255,0.14);
        border: none;
        color: #fff;
        width: 36px; height: 36px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 1rem;
        display: flex; align-items: center; justify-content: center;
        transition: background 0.2s;
        -webkit-tap-highlight-color: transparent;
        flex-shrink: 0;
    }
    .drawer-close-btn button:active { background: rgba(255,255,255,0.28); }
}

/* ── 8. REDUCED MOTION ──────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    #accordionSidebar { transition: none !important; }
}

/* ── 9. WRAPPER: prevent flex layout from offsetting content on mobile ── */
@media (max-width: 991.98px) {
    #wrapper {
        display: block !important;
        overflow-x: hidden;
    }
    #content-wrapper {
        overflow-x: hidden;
        max-width: 100vw;
    }
    /* Ensure content doesn't scroll behind open drawer */
    body.drawer-active {
        overflow: hidden;
        position: relative;
    }
}
</style>

<!-- Backdrop overlay -->
<div id="sidebarBackdrop"></div>

<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar" role="navigation" aria-label="Main sidebar navigation">

    <!-- Close button strip (visible inside mobile drawer only) -->
    <div class="drawer-close-btn">
        <span class="brand-label">Navigation</span>
        <button id="drawerCloseBtn" aria-label="Close navigation menu"><i class="fas fa-times"></i></button>
    </div>
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index-admin">
        <div class="sidebar-brand-icon">
            <img src="bbccassests/img/logo/logo5.jpg" alt="Bhutanese Centre Logo" class="img-thumbnail">
        </div>
        <div class="sidebar-brand-text mx-3">Bhutanese Centre</div>
    </a>

    <hr class="sidebar-divider my-0">

    <li class="nav-item <?= ($currentPage == 'index-admin.php') ? 'active' : '' ?>">
        <a class="nav-link" href="index-admin">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
    </li>

    <li class="nav-item <?= ($currentPage == 'notifications.php') ? 'active' : '' ?>">
        <a class="nav-link" href="notifications">
            <i class="fas fa-bell"></i>
            <span>Notifications</span>
        </a>
    </li>

    <?php if (!isParent() && !isTeacher() && !isPatron()) { ?>

        <hr class="sidebar-divider">

        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseWebsite"
               aria-expanded="true" aria-controls="collapseWebsite">
                <i class="fas fa-cogs"></i>
                <span>Website Settings</span>
            </a>
            <div id="collapseWebsite" class="collapse <?= in_array($currentPage, ['bannerSetup.php','aboutPageSetup.php','serviceSetup.php','ourTeamSetup.php','viewFeedback.php']) ? 'show' : '' ?>">
                <div class="bg-white py-2 collapse-inner rounded">
                    <a class="collapse-item <?= ($currentPage == 'bannerSetup.php') ? 'active' : '' ?>" href="bannerSetup"><i class="fas fa-image fa-sm mr-1 text-muted"></i> Setup Banner</a>
                    <a class="collapse-item <?= ($currentPage == 'aboutPageSetup.php') ? 'active' : '' ?>" href="aboutPageSetup"><i class="fas fa-info-circle fa-sm mr-1 text-muted"></i> Setup About Page</a>
                    <a class="collapse-item <?= ($currentPage == 'serviceSetup.php') ? 'active' : '' ?>" href="serviceSetup"><i class="fas fa-bullhorn fa-sm mr-1 text-muted"></i> Post Event</a>
                    <a class="collapse-item <?= ($currentPage == 'ourTeamSetup.php') ? 'active' : '' ?>" href="ourTeamSetup"><i class="fas fa-users fa-sm mr-1 text-muted"></i> Team Setup</a>
                    <a class="collapse-item <?= ($currentPage == 'viewFeedback.php') ? 'active' : '' ?>" href="viewFeedback"><i class="fas fa-envelope fa-sm mr-1 text-muted"></i> Contact Messages</a>
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

            <div id="collapseOrders" class="collapse <?= in_array($currentPage, ['dzoClassManagement.php','admin-enrolments.php','feesManagement.php','admin-fee-verification.php','attendanceManagement.php','attendance-records.php','dzongkha-classroom.php','parent-email.php','admin-attendance.php','admin-class-setup.php','admin-assign-class.php','feesSetting.php','admin-parent-pins.php']) ? 'show' : '' ?>">
                <div class="bg-white py-2 collapse-inner rounded">
                    <h6 class="collapse-header">Operations</h6>
                    <a class="collapse-item <?= ($currentPage === 'dzoClassManagement.php') ? 'active' : '' ?>" href="dzoClassManagement"><i class="fas fa-user-plus fa-sm mr-1 text-muted"></i> Child Registration</a>
                    <a class="collapse-item <?= ($currentPage === 'admin-enrolments.php') ? 'active' : '' ?>" href="admin-enrolments"><i class="fas fa-file-signature fa-sm mr-1 text-muted"></i> Enrollment</a>
                    <a class="collapse-item <?= in_array($currentPage, ['feesManagement.php','admin-fee-verification.php']) ? 'active' : '' ?>" href="feesManagement"><i class="fas fa-money-check-alt fa-sm mr-1 text-muted"></i> Fees</a>
                    <a class="collapse-item <?= ($currentPage == 'dzongkha-classroom.php') ? 'active' : '' ?>" href="dzongkha-classroom"><i class="fas fa-bullhorn fa-sm mr-1 text-muted"></i> Dzongkha Classroom</a>
                    <a class="collapse-item <?= ($currentPage == 'attendanceManagement.php') ? 'active' : '' ?>" href="attendanceManagement"><i class="fas fa-clipboard-check fa-sm mr-1 text-muted"></i> Attendance</a>
                    <a class="collapse-item <?= ($currentPage == 'attendance-records.php') ? 'active' : '' ?>" href="attendance-records"><i class="fas fa-table fa-sm mr-1 text-muted"></i> Attendance Records</a>
                    <a class="collapse-item <?= ($currentPage == 'parent-email.php') ? 'active' : '' ?>" href="parent-email"><i class="fas fa-envelope-open-text fa-sm mr-1 text-muted"></i> Send Parent Email</a>
                    <a class="collapse-item <?= ($currentPage == 'admin-attendance.php') ? 'active' : '' ?>" href="admin-attendance"><i class="fas fa-door-open fa-sm mr-1 text-muted"></i> Kiosk Sign In/Out</a>
                    <h6 class="collapse-header">Setup</h6>
                    <a class="collapse-item <?= ($currentPage === 'admin-class-setup.php') ? 'active' : '' ?>" href="admin-class-setup"><i class="fas fa-chalkboard fa-sm mr-1 text-muted"></i> Classes & Teachers</a>
                    <a class="collapse-item <?= ($currentPage == 'admin-assign-class.php') ? 'active' : '' ?>" href="admin-assign-class"><i class="fas fa-user-plus fa-sm mr-1 text-muted"></i> Assign Students</a>
                    <a class="collapse-item <?= ($currentPage == 'feesSetting.php') ? 'active' : '' ?>" href="feesSetting"><i class="fas fa-dollar-sign fa-sm mr-1 text-muted"></i> Fees Settings</a>
                    <a class="collapse-item <?= ($currentPage == 'admin-parent-pins.php') ? 'active' : '' ?>" href="admin-parent-pins"><i class="fas fa-key fa-sm mr-1 text-muted"></i> Parent Kiosk PINs</a>
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
                    <a class="collapse-item <?= ($currentPage == 'eventManagement.php') ? 'active' : '' ?>" href="eventManagement"><i class="fas fa-calendar-plus fa-sm mr-1 text-muted"></i> Manage Events</a>
                    <a class="collapse-item <?= ($currentPage == 'bookingManagement.php') ? 'active' : '' ?>" href="bookingManagement"><i class="fas fa-ticket-alt fa-sm mr-1 text-muted"></i> Booking Requests</a>
                </div>
            </div>
        </li>

        <!-- Admin Settings -->
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseAdmin"
               aria-expanded="true" aria-controls="collapseAdmin">
                <i class="fas fa-user-cog"></i>
                <span>Admin Settings</span>
            </a>
            <div id="collapseAdmin" class="collapse <?= in_array($currentPage, ['userSetup.php','adminProfile.php','acl-debug.php','audit-logs.php','run-migration.php']) ? 'show' : '' ?>">
                <div class="bg-white py-2 collapse-inner rounded">
                    <a class="collapse-item <?= ($currentPage == 'userSetup.php') ? 'active' : '' ?>" href="userSetup"><i class="fas fa-users-cog fa-sm mr-1 text-muted"></i> User Management</a>
                    <a class="collapse-item <?= ($currentPage == 'adminProfile.php') ? 'active' : '' ?>" href="adminProfile"><i class="fas fa-id-badge fa-sm mr-1 text-muted"></i> My Profile</a>
                    <a class="collapse-item <?= ($currentPage == 'audit-logs.php') ? 'active' : '' ?>" href="audit-logs"><i class="fas fa-clipboard-list fa-sm mr-1 text-muted"></i> Audit Logs</a>
                    <a class="collapse-item <?= ($currentPage == 'acl-debug.php') ? 'active' : '' ?>" href="acl-debug"><i class="fas fa-shield-alt fa-sm mr-1 text-muted"></i> ACL Debug</a>
                    <a class="collapse-item <?= ($currentPage == 'run-migration.php') ? 'active' : '' ?>" href="run-migration"><i class="fas fa-database fa-sm mr-1 text-muted"></i> Run Migrations</a>
                </div>
            </div>
        </li>

    <?php } ?>

    <!-- Patron-only menu -->
    <?php if (isPatron()) { ?>
        <hr class="sidebar-divider">
        <div class="sidebar-heading">Patron Portal</div>

        <li class="nav-item <?= ($currentPage == 'patron-dashboard.php') ? 'active' : '' ?>">
            <a class="nav-link" href="patron-dashboard">
                <i class="fas fa-hands-helping"></i>
                <span>Patron Dashboard</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'teacherProfile.php') ? 'active' : '' ?>">
            <a class="nav-link" href="teacherProfile">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
        </li>
    <?php } ?>

    <!-- Teacher-only menu -->
    <?php if ($showTeacherPortal) { ?>
        <hr class="sidebar-divider">
        <div class="sidebar-heading">Teacher Portal</div>

        <li class="nav-item <?= ($currentPage == 'teacher-attendance.php') ? 'active' : '' ?>">
            <a class="nav-link" href="teacher-attendance">
                <i class="fas fa-clipboard-check"></i>
                <span>Take Attendance</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'attendance-records.php' && $portalMode === 'teacher') ? 'active' : '' ?>">
            <a class="nav-link <?= ($currentPage == 'attendance-records.php' && $portalMode === 'teacher') ? 'active' : '' ?>" href="attendance-records?as=teacher">
                <i class="fas fa-table"></i>
                <span>Attendance Records</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'parent-email.php') ? 'active' : '' ?>">
            <a class="nav-link" href="parent-email">
                <i class="fas fa-envelope-open-text"></i>
                <span>Send Parent Email</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'dzongkha-classroom.php' && $portalMode !== 'parent') ? 'active' : '' ?>">
            <a class="nav-link" href="dzongkha-classroom?as=teacher">
                <i class="fas fa-bullhorn"></i>
                <span>Dzongkha Classroom</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'adminProfile.php') ? 'active' : '' ?>">
            <a class="nav-link" href="adminProfile">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
        </li>
    <?php } ?>

    <!-- Parent-only menu -->
    <?php if ($showParentPortal) { ?>
        <hr class="sidebar-divider">
        <div class="sidebar-heading">Parent Portal</div>

        <li class="nav-item <?= ($currentPage == 'parentProfile.php') ? 'active' : '' ?>">
            <a class="nav-link" href="parentProfile">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'parent-children.php') ? 'active' : '' ?>">
            <a class="nav-link" href="parent-children">
                <i class="fas fa-file-signature"></i>
                <span>Children</span>
            </a>
        </li>

        <li class="nav-item <?= in_array($currentPage, ['children-enrollment.php','parent-enrolment.php']) ? 'active' : '' ?>">
            <a class="nav-link" href="children-enrollment">
                <i class="fas fa-clipboard-list"></i>
                <span>Enrollment</span>
            </a>
        </li>

        <li class="nav-item <?= in_array($currentPage, ['parent-fees.php','parentFeesPayment.php']) ? 'active' : '' ?>">
            <a class="nav-link" href="parent-fees">
                <i class="fas fa-money-check-alt"></i>
                <span>Fees & Payments</span>
            </a>
        </li>

        <li class="nav-item <?= in_array($currentPage, ['mark-absenteeism.php','parent-attendance.php','attendanceParent.php']) ? 'active' : '' ?>">
            <a class="nav-link" href="mark-absenteeism">
                <i class="fas fa-clipboard-check"></i>
                <span>Mark Absenteeism</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'attendance-records.php' && $portalMode !== 'teacher') ? 'active' : '' ?>">
            <a class="nav-link <?= ($currentPage == 'attendance-records.php' && $portalMode !== 'teacher') ? 'active' : '' ?>" href="attendance-records?as=parent">
                <i class="fas fa-table"></i>
                <span>Student Attendance Record</span>
            </a>
        </li>

        <li class="nav-item <?= ($currentPage == 'dzongkha-classroom.php' && $portalMode !== 'teacher') ? 'active' : '' ?>">
            <a class="nav-link" href="dzongkha-classroom?as=parent">
                <i class="fas fa-bullhorn"></i>
                <span>Dzongkha Classroom</span>
            </a>
        </li>

    <?php } ?>

</ul>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar   = document.getElementById('accordionSidebar');
    var backdrop  = document.getElementById('sidebarBackdrop');
    var closeBtn  = document.getElementById('drawerCloseBtn');
    var toggleBtn = document.getElementById('sidebarToggleBtn');

    function isMobile() { return window.innerWidth < 992; }

    function openDrawer() {
        if (!isMobile()) return;
        sidebar.classList.add('drawer-open');
        backdrop.classList.add('show');
        document.body.classList.add('drawer-active');
        document.body.style.overflow = 'hidden';
        if (toggleBtn) { toggleBtn.querySelector('i').className = 'fas fa-times'; toggleBtn.setAttribute('aria-expanded', 'true'); }
        // Focus first focusable item inside sidebar
        var first = sidebar.querySelector('a, button');
        if (first) setTimeout(function(){ first.focus(); }, 50);
    }

    function closeDrawer() {
        sidebar.classList.remove('drawer-open');
        backdrop.classList.remove('show');
        document.body.classList.remove('drawer-active');
        document.body.style.overflow = '';
        if (toggleBtn) { toggleBtn.querySelector('i').className = 'fas fa-bars'; toggleBtn.setAttribute('aria-expanded', 'false'); }
    }

    if (toggleBtn) toggleBtn.addEventListener('click', function (e) { e.stopPropagation(); isMobile() ? openDrawer() : null; });
    if (closeBtn)  closeBtn.addEventListener('click', closeDrawer);
    if (backdrop)  backdrop.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isMobile()) closeDrawer(); });

    // Auto-close on nav link tap (mobile only, not collapse toggles)
    sidebar.querySelectorAll('a[href]:not([data-toggle])').forEach(function (a) {
        a.addEventListener('click', function () { if (isMobile()) setTimeout(closeDrawer, 120); });
    });

    // Swipe-left to close the drawer
    var touchStartX = 0;
    sidebar.addEventListener('touchstart', function (e) { touchStartX = e.touches[0].clientX; }, { passive: true });
    sidebar.addEventListener('touchend', function (e) {
        if (touchStartX - e.changedTouches[0].clientX > 60) closeDrawer();
    }, { passive: true });

    // Reset on resize to desktop
    window.addEventListener('resize', function () {
        if (!isMobile()) {
            sidebar.classList.remove('drawer-open');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
            if (toggleBtn) { toggleBtn.querySelector('i').className = 'fas fa-bars'; toggleBtn.setAttribute('aria-expanded', 'false'); }
        }
    });
});
</script>
