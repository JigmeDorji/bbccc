<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index-admin');
    exit;
}

verify_csrf();

$portal = strtolower(trim((string)($_POST['portal'] ?? '')));
$returnTo = 'index-admin';
$portalState = bbcc_acl_portal_state();
$hasParent = $portalState['has_parent_profile'];
$hasTeacher = $portalState['has_teacher_profile'];

if ($hasParent && $hasTeacher && in_array($portal, ['parent', 'teacher'], true)) {
    $_SESSION['active_portal'] = $portal;
} elseif ($hasTeacher && !$hasParent) {
    $_SESSION['active_portal'] = 'teacher';
} elseif ($hasParent && !$hasTeacher) {
    $_SESSION['active_portal'] = 'parent';
}

header('Location: ' . $returnTo);
exit;
