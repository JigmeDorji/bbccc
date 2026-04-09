<?php
// include/admin-header.php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/notifications.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
$profileUrl = ($role === 'parent')
    ? 'parentProfile.php'
    : (($role === 'teacher') ? 'teacherProfile.php' : 'adminProfile.php');
$notificationsUrl = 'notifications.php';
$unreadNotifications = 0;
try {
    global $DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME;
    $hdrPdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $unreadNotifications = bbcc_unread_notifications_count(
        $hdrPdo,
        (string)($_SESSION['username'] ?? ''),
        (string)($_SESSION['role'] ?? '')
    );
} catch (Throwable $e) {
    $unreadNotifications = 0;
}

// ── Derive display name & initials ──
$_displayName  = htmlspecialchars(logged_in_username() ?? 'User', ENT_QUOTES, 'UTF-8');
$_displayRole  = htmlspecialchars(ucfirst(logged_in_user_role() ?? 'User'), ENT_QUOTES, 'UTF-8');
$_nameParts    = explode(' ', $_displayName);
$_initials     = strtoupper(substr($_nameParts[0], 0, 1) . (isset($_nameParts[1]) ? substr($_nameParts[1], 0, 1) : ''));

// ── Page title from filename ──
$_pageFile  = basename($_SERVER['PHP_SELF'], '.php');
$_pageTitles = [
    'index-admin'          => 'Dashboard',
    'bannerSetup'          => 'Banner Setup',
    'aboutPageSetup'       => 'About Page',
    'serviceSetup'         => 'Post Event',
    'ourTeamSetup'         => 'Team Setup',
    'viewFeedback'         => 'Contact Messages',
    'dzoClassManagement'   => 'Child Registration',
    'feesManagement'       => 'Fees Management',
    'feesSetting'          => 'Fees Settings',
    'attendanceManagement' => 'Attendance',
    'attendance-records'  => 'Attendance Records',
    'eventManagement'      => 'Manage Events',
    'bookingManagement'    => 'Booking Requests',
    'companySetup'         => 'Company Setup',
    'projectSetup'         => 'Project Setup',
    'userSetup'            => 'User Setup',
    'user-profile-view'    => 'User Profile Details',
    'parent-email'         => 'Parent Email',
    'acl-debug'            => 'ACL Debug',
    'audit-logs'           => 'Audit Logs',
    'createAccHead'        => 'Account Heads',
    'createSubAccHead'     => 'Sub Account Heads',
    'createJournalEntry'   => 'Journal Entry',
    'generateStatement'    => 'Financial Statement',
    'parentProfile'        => 'My Profile',
    'adminProfile'         => 'My Profile',
    'teacherProfile'       => 'My Profile',
    'studentSetup'         => 'Student Enrollment',
    'attendanceParent'     => 'Mark Absenteeism',
    'parentFeesPayment'    => 'Fees Payments',
    'parent-payments'      => 'Payment History',
    'parent-students'      => 'My Students',
    'parent-children'      => 'My Children',
    'parent-enrolment'     => 'Enrolment',
    'children-enrollment'  => 'Enrolment',
    'notifications'        => 'Notifications',
    'parent-fees'          => 'Fees & Payments',
    'parent-attendance'    => 'Mark Absenteeism',
    'mark-absenteeism'     => 'Mark Absenteeism',
    'admin-enrolments'     => 'Enrollment',
    'admin-fee-verification' => 'Fee Verification',
    'admin-bank-settings'  => 'Bank Settings',
    'admin-attendance'     => 'Kiosk & Absence',
    'admin-parent-pins'    => 'Parent Kiosk PINs',
];
$_pageTitle = $_pageTitles[$_pageFile] ?? ucwords(str_replace(['-', '_'], ' ', $_pageFile));
?>

<!-- ═══ Favicon ═══ -->
<script>if(!document.querySelector('link[rel="icon"]')){var l=document.createElement('link');l.rel='icon';l.type='image/jpeg';l.href='bbccassests/img/logo/logo5.jpg';document.head.appendChild(l);}</script>

<!-- ═══ BBCC Unified Form Styles ═══ -->
<link rel="stylesheet" href="css/bbcc-forms.css">

<!-- ═══ Admin Header Styles ═══ -->
<style>
/* ── Global Admin Typography ─────────────────────────────── */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    font-size: 0.85rem !important;
    background: #f4f6f9 !important;
}

/* ── Sticky footer: always pinned to viewport bottom ────────── */
#content-wrapper {
    display: flex !important;
    flex-direction: column !important;
    min-height: 100vh;
}
#content {
    flex: 1 0 auto;
}
.sticky-footer {
    flex-shrink: 0;
    padding: 14px 0;
    border-top: 1px solid #e9ecef;
    font-size: 0.78rem;
    color: #888;
}
table, input, select, label, .form-control, .btn, .card, .accordion { font-size: 0.85rem !important; }
h1, h6 { font-size: 1rem !important; }

/* ── Accessibility: Skip to content ─────────────────────── */
.skip-to-content {
    position: absolute;
    top: -100%;
    left: 16px;
    z-index: 9999;
    background: #881b12;
    color: #fff;
    padding: 10px 20px;
    border-radius: 0 0 8px 8px;
    font-weight: 600;
    text-decoration: none;
    transition: top .2s;
}
.skip-to-content:focus {
    top: 0;
    color: #fff;
    outline: 3px solid #c9a84c;
    outline-offset: 2px;
}

/* ── Accessibility: Focus visible ────────────────────────── */
*:focus-visible {
    outline: 2px solid #881b12 !important;
    outline-offset: 2px !important;
    box-shadow: 0 0 0 4px rgba(136,27,18,.15) !important;
}
.btn:focus-visible,
.form-control:focus-visible,
.nav-link:focus-visible {
    outline: 2px solid #881b12 !important;
    outline-offset: 2px !important;
    box-shadow: 0 0 0 4px rgba(136,27,18,.2) !important;
}
.btn-danger:focus-visible { outline-color: #e74a3b !important; box-shadow: 0 0 0 4px rgba(231,74,59,.25) !important; }

/* ── Screen reader only ──────────────────────────────────── */
.sr-only {
    position: absolute;
    width: 1px; height: 1px;
    padding: 0; margin: -1px;
    overflow: hidden;
    clip: rect(0,0,0,0);
    white-space: nowrap;
    border: 0;
}

/* ── Reduced motion preference ───────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

/* ── Professional Header Bar ─────────────────────────────── */
.bbcc-admin-topbar {
    background: #fff;
    border-bottom: 1px solid #e9ecef;
    padding: 0 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 60px;
    position: sticky;
    top: 0;
    z-index: 1020;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    gap: 12px;
}

/* Left: Hamburger + Page title + Breadcrumb */
.topbar-left {
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0; /* allow text truncation */
}
.topbar-titles { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.topbar-page-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.topbar-breadcrumb {
    font-size: 0.75rem;
    color: #8c8c9e;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.topbar-breadcrumb a { color: #6c757d; text-decoration: none; transition: color 0.2s; }
.topbar-breadcrumb a:hover { color: #881b12; }
.topbar-breadcrumb .sep { margin: 0 4px; opacity: 0.4; }

/* Right: actions cluster */
.topbar-right {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

/* Date/Time badge */
.topbar-date {
    display: none;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #f0f2f5;
    border-radius: 8px;
    font-size: 0.75rem;
    color: #555;
    font-weight: 500;
    white-space: nowrap;
}
.topbar-date i { color: #881b12; font-size: 0.8rem; }
@media (min-width: 768px) { .topbar-date { display: flex; } }

/* Quick-action icon buttons */
.topbar-icon-btn {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: #f0f2f5;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #555;
    font-size: 0.92rem;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    text-decoration: none !important;
}
.topbar-icon-btn:hover {
    background: #e2e6ea;
    color: #1a1a2e;
    transform: translateY(-1px);
}
.topbar-icon-btn .badge-count {
    position: absolute;
    top: -3px; right: -3px;
    background: #e74a3b;
    color: #fff;
    font-size: 0.6rem;
    font-weight: 700;
    width: 18px; height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #fff;
    line-height: 1;
}

/* Divider line */
.topbar-divider {
    width: 1px;
    height: 32px;
    background: #e0e0e0;
    margin: 0 6px;
}

/* User menu */
.topbar-user {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 5px 8px 5px 12px;
    border-radius: 12px;
    cursor: pointer;
    transition: background 0.2s;
    text-decoration: none !important;
    position: relative;
}
.topbar-user:hover { background: #f0f2f5; }

.topbar-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #881b12, #6b140d);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.topbar-user-info {
    display: none;
    flex-direction: column;
    line-height: 1.2;
}
.topbar-user-name {
    font-size: 0.82rem;
    font-weight: 600;
    color: #1a1a2e;
}
.topbar-user-role {
    font-size: 0.7rem;
    color: #8c8c9e;
    font-weight: 500;
}

@media (min-width: 992px) {
    .topbar-user-info { display: flex; }
}

/* Dropdown styling */
.topbar-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.12);
    border: 1px solid #eee;
    min-width: 230px;
    z-index: 1050;
    padding: 6px 0;
    animation: dropSlide 0.18s ease;
}
.topbar-dropdown.show { display: block; }

@keyframes dropSlide {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
}

.dropdown-header-card {
    padding: 14px 16px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.dropdown-header-card .topbar-avatar {
    width: 40px; height: 40px;
    font-size: 1.1rem;
}
.dropdown-header-card .info .name {
    font-size: 0.88rem;
    font-weight: 700;
    color: #1a1a2e;
}
.dropdown-header-card .info .role {
    font-size: 0.72rem;
    color: #8c8c9e;
}

.topbar-dropdown a,
.topbar-dropdown button {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    font-size: 0.84rem;
    font-weight: 500;
    color: #444;
    text-decoration: none;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
    cursor: pointer;
    transition: background 0.15s;
}
.topbar-dropdown a:hover,
.topbar-dropdown button:hover {
    background: #f4f6f9;
    color: #1a1a2e;
}
.topbar-dropdown a i,
.topbar-dropdown button i {
    width: 28px; height: 28px;
    border-radius: 8px;
    background: #f0f2f5;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    color: #666;
    flex-shrink: 0;
}
.topbar-dropdown .dd-divider {
    height: 1px;
    background: #f0f0f0;
    margin: 4px 0;
}
.topbar-dropdown .logout-item { color: #e74a3b; }
.topbar-dropdown .logout-item i { background: #fce8e6; color: #e74a3b; }

/* ── Hamburger toggle button (mobile only) ───────────────── */
.topbar-hamburger {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: #f0f2f5;
    border: none;
    display: none; /* hidden on desktop */
    align-items: center;
    justify-content: center;
    color: #444;
    font-size: 1.1rem;
    cursor: pointer;
    transition: background 0.2s;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.topbar-hamburger:hover, .topbar-hamburger:active { background: #e2e6ea; color: #881b12; }
@media (max-width: 991.98px) {
    .topbar-hamburger { display: flex; }
}

/* ── Mobile topbar adjustments ───────────────────────────── */
@media (max-width: 767.98px) {
    .bbcc-admin-topbar    { padding: 0 12px; min-height: 56px; }
    .topbar-page-title    { font-size: 0.95rem; }
    .topbar-breadcrumb    { display: none; }
    .topbar-right         { gap: 4px; }
    .topbar-divider       { display: none; }
    .topbar-user-info     { display: none !important; }
}
@media (max-width: 374.98px) {
    .topbar-page-title    { font-size: 0.88rem; }
}
</style>

<!-- Skip to content link for keyboard accessibility -->
<a href="#main-content" class="skip-to-content">Skip to main content</a>

<!-- ═══ Professional Admin Header Bar ═══ -->
<nav class="bbcc-admin-topbar" role="navigation" aria-label="Admin top navigation">
    <!-- Left: Hamburger + Page Title + Breadcrumb -->
    <div class="topbar-left">
        <button class="topbar-hamburger" id="sidebarToggleBtn" aria-label="Toggle navigation menu" aria-controls="accordionSidebar" aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-titles">
            <h1 class="topbar-page-title"><?php echo $_pageTitle; ?></h1>
            <nav aria-label="Breadcrumb">
                <p class="topbar-breadcrumb">
                    <a href="index-admin">Dashboard</a>
                    <?php if ($_pageFile !== 'index-admin'): ?>
                        <span class="sep" aria-hidden="true">/</span>
                        <span aria-current="page"><?php echo $_pageTitle; ?></span>
                    <?php endif; ?>
                </p>
            </nav>
        </div>
    </div>

    <!-- Right: Actions -->
    <div class="topbar-right">
        <!-- Date -->
        <div class="topbar-date" aria-label="Today's date">
            <i class="fas fa-calendar-day" aria-hidden="true"></i>
            <span><?php echo date('D, d M Y'); ?></span>
        </div>

        <!-- Notifications -->
        <a href="<?php echo htmlspecialchars($notificationsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="topbar-icon-btn" title="Notifications" aria-label="Open notifications">
            <i class="fas fa-bell" aria-hidden="true"></i>
            <?php if ($unreadNotifications > 0): ?>
                <span class="badge-count"><?php echo (int)min(99, $unreadNotifications); ?></span>
            <?php endif; ?>
        </a>

        <!-- Visit Website -->
        <a href="index" target="_blank" class="topbar-icon-btn" title="View Website" aria-label="Open public website in new tab">
            <i class="fas fa-external-link-alt" aria-hidden="true"></i>
        </a>

        <!-- Divider -->
        <div class="topbar-divider" aria-hidden="true"></div>

        <!-- User Menu -->
        <div class="topbar-user" id="adminUserMenu" role="button" tabindex="0" aria-expanded="false" aria-haspopup="true" aria-label="User menu for <?php echo $_displayName; ?>"
             onclick="var dd=document.getElementById('adminDropdown'); dd.classList.toggle('show'); this.setAttribute('aria-expanded', dd.classList.contains('show'));"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault(); this.click();}">
            <div class="topbar-user-info">
                <span class="topbar-user-name"><?php echo $_displayName; ?></span>
                <span class="topbar-user-role"><?php echo $_displayRole; ?></span>
            </div>
            <div class="topbar-avatar" aria-hidden="true"><i class="fas fa-user"></i></div>

            <!-- Dropdown -->
            <div class="topbar-dropdown" id="adminDropdown" role="menu">
                <div class="dropdown-header-card">
                    <div class="topbar-avatar" aria-hidden="true"><i class="fas fa-user"></i></div>
                    <div class="info">
                        <div class="name"><?php echo $_displayName; ?></div>
                        <div class="role"><?php echo $_displayRole; ?></div>
                    </div>
                </div>

                <a href="<?php echo htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8'); ?>" role="menuitem">
                    <i class="fas fa-user" aria-hidden="true"></i>
                    My Profile
                </a>

                <a href="index-admin" role="menuitem">
                    <i class="fas fa-tachometer-alt" aria-hidden="true"></i>
                    Dashboard
                </a>

                <?php if ($role !== 'parent'): ?>
                <a href="viewFeedback" role="menuitem">
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                    Messages
                </a>
                <?php endif; ?>

                <div class="dd-divider" role="separator"></div>

                <form action="logout" method="POST" style="margin:0;">
                    <button type="submit" class="logout-item" role="menuitem">
                        <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                        Sign Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>

<!-- Main content landmark -->
<main id="main-content" role="main">

<!-- Close dropdown when clicking outside -->
<script>
document.addEventListener('click', function(e) {
    var dd = document.getElementById('adminDropdown');
    var menu = document.getElementById('adminUserMenu');
    if (dd && menu && !menu.contains(e.target)) {
        dd.classList.remove('show');
    }
});
</script>
