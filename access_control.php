<?php

function allowRoles($allowedRoles = []) {
    $role = trim($_SESSION['role'] ?? '');
    // Case-insensitive comparison
    $allowed = array_map('strtolower', array_map('trim', $allowedRoles));
    if (!in_array(strtolower($role), $allowed, true)) {
        header("Location: unauthorized");
        exit;
    }
}
?>
