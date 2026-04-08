<?php

function bbcc_normalize_access_role($role) {
    $r = strtolower(trim((string)$role));
    $r = str_replace('_', ' ', $r);
    $r = preg_replace('/\s+/', ' ', $r);
    return $r;
}

function allowRoles($allowedRoles = []) {
    $role = bbcc_normalize_access_role($_SESSION['role'] ?? '');
    $allowed = array_map('bbcc_normalize_access_role', $allowedRoles);
    if (!in_array($role, $allowed, true)) {
        header("Location: unauthorized");
        exit;
    }
}
?>
