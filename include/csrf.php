<?php
// include/csrf.php — Simple CSRF token helpers
// Usage:  require_once 'include/csrf.php';
//         echo csrf_field();      // inside <form>
//         verify_csrf();          // on POST handler

/**
 * Return the current CSRF token (generates one if missing).
 */
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/**
 * Output a hidden <input> for the CSRF token.
 */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validate the submitted CSRF token. Dies on failure.
 */
function verify_csrf(): void {
    $submitted = $_POST['_csrf'] ?? '';
    if (!hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        die('Invalid security token. Please refresh the page and try again.');
    }
}

/**
 * One-time nonce per form scope to prevent duplicate submissions.
 */
function bbcc_form_nonce(string $scope = 'default'): string {
    if (!isset($_SESSION['_form_nonce']) || !is_array($_SESSION['_form_nonce'])) {
        $_SESSION['_form_nonce'] = [];
    }
    if (empty($_SESSION['_form_nonce'][$scope])) {
        $_SESSION['_form_nonce'][$scope] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['_form_nonce'][$scope];
}

function bbcc_form_nonce_field(string $scope = 'default', string $field = '_form_nonce'): string {
    return '<input type="hidden" name="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars(bbcc_form_nonce($scope), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify and consume nonce. Returns true when valid, false when duplicate/invalid.
 */
function bbcc_verify_form_nonce_once(string $scope = 'default', string $field = '_form_nonce'): bool {
    $submitted = (string)($_POST[$field] ?? '');
    $current = (string)($_SESSION['_form_nonce'][$scope] ?? '');
    if ($submitted === '' || $current === '' || !hash_equals($current, $submitted)) {
        return false;
    }
    $_SESSION['_form_nonce'][$scope] = bin2hex(random_bytes(16));
    return true;
}
