<?php

function normalize_role($role) {
    return strtolower(trim($role ?? ''));
}

function is_parent_role() {
    return normalize_role($_SESSION['role'] ?? '') === 'parent';
}

function is_teacher_role() {
    return normalize_role($_SESSION['role'] ?? '') === 'teacher';
}

function is_admin_role() {
    $role = normalize_role($_SESSION['role'] ?? '');
    return in_array($role, ['administrator', 'admin', 'company admin', 'system_owner', 'system owner', 'staff'], true);
}
