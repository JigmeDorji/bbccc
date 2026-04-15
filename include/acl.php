<?php
require_once __DIR__ . '/role_helpers.php';
require_once __DIR__ . '/module_access.php';

function bbcc_acl_current_route_key(): string {
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $path = trim($path, '/');

    if ($path === '') {
        return 'index';
    }

    $base = strtolower(basename($path));
    if (substr($base, -4) === '.php') {
        $base = substr($base, 0, -4);
    }
    return $base;
}

function bbcc_acl_role(): string {
    return strtolower(trim((string)($_SESSION['role'] ?? '')));
}

function bbcc_acl_detect_parent_profile(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = false;

    try {
        global $DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME;
        $pdo = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
            $DB_USER,
            $DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $username = (string)($_SESSION['username'] ?? '');
        if ($username !== '') {
            $stmt = $pdo->prepare("SELECT id FROM parents WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $username]);
            $cached = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function bbcc_acl_detect_teacher_profile(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = false;

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
        $cached = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function bbcc_acl_is_mixed_profile_user(): bool {
    return bbcc_acl_detect_parent_profile() && bbcc_acl_detect_teacher_profile();
}

function bbcc_acl_active_portal(): string {
    $portal = strtolower(trim((string)($_SESSION['active_portal'] ?? '')));
    if (in_array($portal, ['parent', 'teacher'], true)) {
        return $portal;
    }
    // Backward-compatible default for mixed accounts.
    return 'teacher';
}

function bbcc_acl_has_capability(string $cap): bool {
    $cap = strtolower(trim($cap));
    $role = bbcc_acl_role();
    $isMixed = bbcc_acl_is_mixed_profile_user();
    $activePortal = bbcc_acl_active_portal();

    if ($cap === 'authenticated') return isset($_SESSION['userid']);
    if ($cap === 'admin') return is_admin_role();
    if ($cap === 'website_admin') return is_website_admin_role();
    if ($cap === 'parent') {
        $hasParent = is_parent_role() || bbcc_acl_detect_parent_profile();
        if (!$hasParent) return false;
        if ($isMixed && $activePortal !== 'parent') return false;
        return true;
    }
    if ($cap === 'teacher') {
        $hasTeacher = is_teacher_role() || bbcc_acl_detect_teacher_profile();
        if (!$hasTeacher) return false;
        if ($isMixed && $activePortal !== 'teacher') return false;
        return true;
    }
    if ($cap === 'patron') return $role === 'patron';

    return false;
}

function bbcc_acl_page_rules(): array {
    return [
        // Shared authenticated landing/profile pages
        'index-admin' => ['authenticated'],
        'notifications' => ['authenticated'],
        'switch-portal' => ['authenticated'],
        'adminprofile' => ['admin', 'teacher', 'patron', 'website_admin'],
        'teacherprofile' => ['admin', 'teacher'],

        // Admin operations
        'usersetup' => ['admin'],
        'user-profile-view' => ['admin'],
        'admin-class-setup' => ['admin'],
        'admin-assign-class' => ['admin'],
        'admin-enrolments' => ['admin'],
        'dzoclassmanagement' => ['admin'],
        'feessetting' => ['admin'],
        'admin-fee-verification' => ['admin'],
        'admin-parent-pins' => ['admin'],
        'admin-bank-settings' => ['admin'],
        'mail-test' => ['admin'],
        'run-migration' => ['admin'],
        'bannersetup' => ['admin', 'website_admin'],
        'aboutpagesetup' => ['admin', 'website_admin'],
        'servicesetup' => ['admin', 'website_admin'],
        'ourteamsetup' => ['admin', 'website_admin'],
        'viewfeedback' => ['admin', 'website_admin'],
        'eventmanagement' => ['admin', 'website_admin'],
        'bookingmanagement' => ['admin', 'website_admin'],
        'generatestatement' => ['admin'],
        'companysetup' => ['admin'],
        'projectsetup' => ['admin'],
        'admin-teacher-setup' => ['admin'],
        'process-mail-queue' => ['admin'],
        'parent-email' => ['admin', 'teacher'],
        'dzongkha-classroom' => ['admin', 'teacher', 'parent'],
        'admin-student-approvals' => ['admin'],
        'exportbookings' => ['admin'],
        'acl-debug' => ['admin'],
        'audit-logs' => ['admin'],
        'module-access' => ['admin'],

        // Teacher/admin shared
        'teacher-attendance' => ['admin', 'teacher'],
        'attendancemanagement' => ['admin', 'teacher'],
        'feesmanagement' => ['admin', 'teacher'],
        'admin-attendance' => ['admin'],

        // Parent-only flows
        'parent-children' => ['parent'],
        'parentprofile' => ['parent'],
        'children-enrollment' => ['parent'],
        'parent-fees' => ['parent'],
        'parent-fees-pay' => ['parent'],
        'parentfeespayment' => ['parent'],
        'parent-payments' => ['parent'],
        'parent-students' => ['parent'],
        'parent-signinout' => ['parent'],
        'mark-absenteeism' => ['parent'],
        'attendanceparent' => ['parent'],
        'parent-attendance' => ['parent'],

        // Patron-only
        'patron-dashboard' => ['patron'],

        // Shared portal pages
        'attendance-records' => ['admin', 'teacher', 'parent'],
    ];
}

function bbcc_acl_route_module_rules(): array {
    return [
        // Shared authenticated pages
        'index-admin' => ['reports_settings', 'view'],
        'notifications' => ['communication', 'view'],
        'switch-portal' => ['reports_settings', 'view'],
        'adminprofile' => ['reports_settings', 'view'],
        'teacherprofile' => ['reports_settings', 'view'],
        'parentprofile' => ['reports_settings', 'view'],
        'patron-dashboard' => ['reports_settings', 'view'],

        // Website / CMS
        'bannersetup' => ['website', 'manage'],
        'aboutpagesetup' => ['website', 'manage'],
        'servicesetup' => ['website', 'manage'],
        'ourteamsetup' => ['website', 'manage'],
        'viewfeedback' => ['website', 'manage'],
        'eventmanagement' => ['website', 'manage'],
        'bookingmanagement' => ['website', 'manage'],

        // Users & access
        'usersetup' => ['users_access', 'manage'],
        'user-profile-view' => ['users_access', 'manage'],
        'module-access' => ['users_access', 'manage'],

        // Enrollment flows
        'dzoclassmanagement' => ['enrollment', 'approve'],
        'admin-enrolments' => ['enrollment', 'approve'],
        'admin-student-approvals' => ['enrollment', 'approve'],
        'children-enrollment' => ['enrollment', 'submit'],
        'parent-children' => ['enrollment', 'view'],
        'parent-students' => ['enrollment', 'view'],

        // Classes & attendance
        'admin-class-setup' => ['classes_attendance', 'manage'],
        'admin-teacher-setup' => ['classes_attendance', 'manage'],
        'admin-assign-class' => ['classes_attendance', 'manage'],
        'teacher-attendance' => ['classes_attendance', 'mark'],
        'attendancemanagement' => ['classes_attendance', 'edit'],
        'attendance-records' => ['classes_attendance', 'view'],
        'mark-absenteeism' => ['classes_attendance', 'mark'],
        'attendanceparent' => ['classes_attendance', 'mark'],
        'parent-attendance' => ['classes_attendance', 'mark'],

        // Fees and payments
        'feessetting' => ['fees_payments', 'manage'],
        'admin-bank-settings' => ['fees_payments', 'manage'],
        'admin-fee-verification' => ['fees_payments', 'verify'],
        'feesmanagement' => ['fees_payments', 'view'],
        'parent-fees' => ['fees_payments', 'view'],
        'parent-fees-pay' => ['fees_payments', 'submit'],
        'parentfeespayment' => ['fees_payments', 'submit'],
        'parent-payments' => ['fees_payments', 'view'],

        // Communication
        'parent-email' => ['communication', 'send'],
        'dzongkha-classroom' => ['communication', 'view'],
        'mail-test' => ['communication', 'manage'],
        'process-mail-queue' => ['communication', 'manage'],

        // Kiosk
        'admin-attendance' => ['kiosk', 'manage'],
        'admin-parent-pins' => ['kiosk', 'manage'],
        'parent-signinout' => ['kiosk', 'use'],

        // Reports & system settings
        'generatestatement' => ['reports_settings', 'manage'],
        'exportbookings' => ['reports_settings', 'export'],
        'run-migration' => ['reports_settings', 'manage'],
        'audit-logs' => ['reports_settings', 'manage'],
        'acl-debug' => ['reports_settings', 'manage'],
        'companysetup' => ['reports_settings', 'manage'],
        'projectsetup' => ['reports_settings', 'manage'],
    ];
}

function bbcc_acl_log_denied(string $routeKey, array $allowedCaps): void {
    $line = sprintf(
        "[ACL] DENIED route=%s user=%s role=%s allowed=%s ip=%s\n",
        $routeKey,
        (string)($_SESSION['username'] ?? $_SESSION['userid'] ?? 'guest'),
        (string)($_SESSION['role'] ?? ''),
        implode(',', $allowedCaps),
        (string)($_SERVER['REMOTE_ADDR'] ?? '-')
    );
    error_log($line);
    if (function_exists('bbcc_audit_log')) {
        bbcc_audit_log('acl_denied', 'security', [
            'route' => $routeKey,
            'allowed' => $allowedCaps,
        ], 'warning');
    }
}

function bbcc_acl_log_module_denied(string $routeKey, string $moduleKey, string $actionKey): void {
    $line = sprintf(
        "[ACL] MODULE_DENIED route=%s user=%s role=%s module=%s action=%s ip=%s\n",
        $routeKey,
        (string)($_SESSION['username'] ?? $_SESSION['userid'] ?? 'guest'),
        (string)($_SESSION['role'] ?? ''),
        $moduleKey,
        $actionKey,
        (string)($_SERVER['REMOTE_ADDR'] ?? '-')
    );
    error_log($line);
    if (function_exists('bbcc_audit_log')) {
        bbcc_audit_log('acl_module_denied', 'security', [
            'route' => $routeKey,
            'module' => $moduleKey,
            'action' => $actionKey,
        ], 'warning');
    }
}

function bbcc_acl_enforce_current_page(): void {
    if (!isset($_SESSION['userid'])) {
        return;
    }

    $route = bbcc_acl_current_route_key();
    $rules = bbcc_acl_page_rules();
    if (!isset($rules[$route])) {
        return; // Unmapped pages stay in compatibility mode.
    }

    $allowedCaps = (array)$rules[$route];
    foreach ($allowedCaps as $cap) {
        if (bbcc_acl_has_capability($cap)) {
            $moduleRules = bbcc_acl_route_module_rules();
            if (!isset($moduleRules[$route])) {
                return;
            }

            [$moduleKey, $actionKey] = $moduleRules[$route];
            if (!function_exists('bbcc_can') || bbcc_can((string)$moduleKey, (string)$actionKey)) {
                return;
            }

            bbcc_acl_log_module_denied($route, (string)$moduleKey, (string)$actionKey);
            header("Location: unauthorized");
            exit;
        }
    }

    bbcc_acl_log_denied($route, $allowedCaps);
    header("Location: unauthorized");
    exit;
}
