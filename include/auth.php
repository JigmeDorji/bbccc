<?php
require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/module_access.php';

/**
 * Log in a particular user and store extra info
 */

function login($userid, $username, $role, $email = null) {
    $_SESSION['userid'] = $userid;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $role;

    if ($email !== null) {
        $_SESSION['email'] = $email;
    }
    bbcc_audit_log('login_success', 'auth', [
        'userid' => (string)$userid,
        'username' => (string)$username,
        'role' => (string)$role,
    ], 'success');
}

/**
 * Log out the current user
 */
function logout() {
    bbcc_audit_log('logout', 'auth', [
        'userid' => (string)($_SESSION['userid'] ?? ''),
        'username' => (string)($_SESSION['username'] ?? ''),
    ], 'success');
    session_unset();
    session_destroy();
}

/**
 * Return whether a user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['userid']);
}

/**
 * Return whether the logged-in user is an admin
 * (Adjust this condition to your needs)
 */
function is_admin() {
    return is_logged_in() && (logged_in_userid() === 'Admin');
}

/**
 * Get the current logged-in userid
 */
function logged_in_userid() {
    return $_SESSION['userid'] ?? null;
}

/**
 * Get the current logged-in username
 */
function logged_in_username() {
    return $_SESSION['username'] ?? null;
}

function logged_in_user_role() {
    return $_SESSION['role'] ?? null;
}

/**
 * Resolve the logged-in user's actual full name, not their login
 * identifier -- $_SESSION['username'] is the login email (see
 * login.php: "Email as Username"), so displaying it directly as a
 * "name" in the UI shows an email address instead of a person's name.
 * Looks up the real name from the role-appropriate table using the
 * same match rules each profile page already uses (parents/teachers
 * matched by user_id or email; admins by admin_profiles.user_id), and
 * falls back to the session username only if no name is on file yet.
 */
function bbcc_current_display_name(PDO $pdo): string {
    $sessionUserId = trim((string)($_SESSION['userid'] ?? ''));
    $sessionUsername = trim((string)($_SESSION['username'] ?? ''));
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    $fullName = '';

    try {
        if ($role === 'parent') {
            $stmt = $pdo->prepare("
                SELECT full_name FROM parents
                WHERE (user_id = :uid AND :uid <> '') OR LOWER(email) = LOWER(:em)
                ORDER BY (user_id = :uid) DESC, id ASC
                LIMIT 1
            ");
            $stmt->execute([':uid' => $sessionUserId, ':em' => $sessionUsername]);
            $fullName = (string)($stmt->fetchColumn() ?: '');
        } elseif ($role === 'teacher') {
            $stmt = $pdo->prepare("
                SELECT full_name FROM teachers
                WHERE (user_id = :uid AND :uid <> '') OR LOWER(email) = LOWER(:em)
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([':uid' => $sessionUserId, ':em' => $sessionUsername]);
            $fullName = (string)($stmt->fetchColumn() ?: '');
        } else {
            $stmt = $pdo->prepare("SELECT full_name FROM admin_profiles WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $sessionUserId]);
            $fullName = (string)($stmt->fetchColumn() ?: '');
        }
    } catch (Throwable $e) {
        $fullName = '';
    }

    $fullName = trim($fullName);
    return $fullName !== '' ? $fullName : $sessionUsername;
}

/**
 * Redirect if not logged in
 */
function require_login() {
    if (!is_logged_in()) {
        header('Location: index');
        exit;
    }
    bbcc_audit_capture_request_once();
    bbcc_acl_enforce_current_page();
}
?>
