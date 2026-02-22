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
