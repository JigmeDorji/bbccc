<?php
require_once __DIR__ . '/role_helpers.php';

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
        'adminprofile' => ['admin', 'teacher', 'patron'],
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
        'parent-email' => ['admin', 'teacher'],
        'dzongkha-classroom' => ['admin', 'teacher', 'parent'],
        'admin-student-approvals' => ['admin'],
        'exportbookings' => ['admin'],
        'acl-debug' => ['admin'],
        'audit-logs' => ['admin'],

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
            return;
        }
    }

    bbcc_acl_log_denied($route, $allowedCaps);
    header("Location: unauthorized");
    exit;
}
