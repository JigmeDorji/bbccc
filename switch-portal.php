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
$returnTo = (string)($_POST['return_to'] ?? 'index-admin');

if ($returnTo === '' || preg_match('/^https?:\/\//i', $returnTo) || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) {
    $returnTo = 'index-admin';
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

$username = (string)($_SESSION['username'] ?? '');
$userId = (string)($_SESSION['userid'] ?? '');

$parentId = 0;
$teacherId = 0;

if ($username !== '') {
    $stmtParent = $pdo->prepare("SELECT id FROM parents WHERE username = :u LIMIT 1");
    $stmtParent->execute([':u' => $username]);
    $parentId = (int)$stmtParent->fetchColumn();
}

$stmtTeacher = $pdo->prepare("
    SELECT id
    FROM teachers
    WHERE (user_id = :uid AND :uid <> '')
       OR LOWER(email) = LOWER(:em)
    ORDER BY id ASC
    LIMIT 1
");
$stmtTeacher->execute([':uid' => $userId, ':em' => $username]);
$teacherId = (int)$stmtTeacher->fetchColumn();

$hasParent = $parentId > 0 || is_parent_role();
$hasTeacher = $teacherId > 0 || is_teacher_role();

if ($hasParent && $hasTeacher && in_array($portal, ['parent', 'teacher'], true)) {
    $_SESSION['active_portal'] = $portal;
} elseif ($hasTeacher && !$hasParent) {
    $_SESSION['active_portal'] = 'teacher';
} elseif ($hasParent && !$hasTeacher) {
    $_SESSION['active_portal'] = 'parent';
}

header('Location: ' . $returnTo);
exit;

