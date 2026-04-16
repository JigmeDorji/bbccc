<?php
/**
 * verify-email.php — AJAX endpoint for email OTP verification
 * ────────────────────────────────────────────────────────────
 * POST actions:
 *   action=send   → send a 6-digit OTP to the given email
 *   action=verify → check the submitted OTP
 *
 * Returns JSON: { "ok": true/false, "message": "..." }
 */

require_once "include/config.php";
require_once "include/csrf.php";
require_once "include/email_verification.php";
require_once "include/pcm_helpers.php";

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Validate CSRF
$submitted = $_POST['_csrf'] ?? '';
if (!hash_equals(csrf_token(), $submitted)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$email  = strtolower(trim($_POST['email'] ?? ''));
$purpose = 'signup';

if (!in_array($action, ['send', 'verify'], true)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid action.']);
    exit;
}

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
} catch (PDOException $e) {
    error_log("[verify-email] DB error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Service temporarily unavailable.']);
    exit;
}

/* ── SEND CODE ────────────────────────────────────────────── */
if ($action === 'send') {
    // Check if email is already registered (no point verifying)
    $stmtCheck = $pdo->prepare("SELECT id FROM parents WHERE LOWER(email) = LOWER(:e) LIMIT 1");
    $stmtCheck->execute([':e' => $email]);
    if ($stmtCheck->fetchColumn()) {
        echo json_encode(['ok' => false, 'message' => 'This email is already registered. Please use a different email or login.']);
        exit;
    }

    $stmtCheckUser = $pdo->prepare("SELECT userid FROM `user` WHERE LOWER(username) = LOWER(:u) LIMIT 1");
    $stmtCheckUser->execute([':u' => $email]);
    if ($stmtCheckUser->fetchColumn()) {
        echo json_encode(['ok' => false, 'message' => 'This email is already registered. Please use a different email or login.']);
        exit;
    }

    $result = bbcc_send_verification_code($pdo, $email, $purpose);
    echo json_encode($result);
    exit;
}

/* ── VERIFY CODE ──────────────────────────────────────────── */
if ($action === 'verify') {
    $code = trim($_POST['code'] ?? '');
    $result = bbcc_verify_email_code($pdo, $email, $code, $purpose);

    if ($result['ok']) {
        // Store in session so parentAccountSetup.php can trust the verification
        $_SESSION['verified_email'] = $email;
        $_SESSION['verified_email_at'] = time();
    }

    echo json_encode($result);
    exit;
}
