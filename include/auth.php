<?php

/**
 * Log in a particular user and store extra info
 */
function login($userid, $username, $companyID, $projectID,$companyName,$projectName,$role) {
    $_SESSION['userid'] = $userid;
    $_SESSION['username'] = $username;
    $_SESSION['companyID'] = $companyID;
    $_SESSION['projectID'] = $projectID;
    $_SESSION['companyName'] = $companyName;
    $_SESSION['projectName'] = $projectName;
    $_SESSION['role'] = $role;
}

/**
 * Log out the current user
 */
function logout() {
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
    return is_logged_in() && (logged_in_userid() === 'admin');
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

/**
 * Get the company ID of the logged-in user
 */
function logged_in_company() {
    return $_SESSION['companyID'] ?? null;
}

/**
 * Get the project ID of the logged-in user
 */
function logged_in_project() {
    return $_SESSION['projectID'] ?? null;
}

function logged_in_companyName() {
    return $_SESSION['companyName'] ?? null;
}

function logged_in_user_role() {
    return $_SESSION['role'] ?? null;
}

/**
 * Redirect if not logged in
 */
function require_login() {
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}
?>
