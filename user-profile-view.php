<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

function upv_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    bbcc_fail_db($e);
}

$type = strtolower(trim((string)($_GET['type'] ?? 'user')));
$userid = trim((string)($_GET['userid'] ?? ''));
$teacherId = (int)($_GET['teacher_id'] ?? 0);
$parentId = (int)($_GET['parent_id'] ?? 0);

$baseUser = null;
$teacher = null;
$parent = null;
$title = 'User Profile Details';
$error = '';

try {
    if ($teacherId > 0) {
        $title = 'Teacher Profile Details';
        $st = $pdo->prepare("
            SELECT t.*, u.userid, u.username, u.role, u.createdDate
            FROM teachers t
            LEFT JOIN user u ON u.userid = t.user_id
            WHERE t.id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $teacherId]);
        $teacher = $st->fetch() ?: null;

        if (!$teacher) {
            throw new Exception('Teacher profile not found.');
        }

        $email = trim((string)($teacher['email'] ?? ''));
        $uid = trim((string)($teacher['user_id'] ?? ''));
        if ($uid !== '') {
            $stU = $pdo->prepare("SELECT userid, username, role, createdDate FROM user WHERE userid = :uid LIMIT 1");
            $stU->execute([':uid' => $uid]);
            $baseUser = $stU->fetch() ?: null;
        }
        if (!$baseUser && $email !== '') {
            $stU = $pdo->prepare("SELECT userid, username, role, createdDate FROM user WHERE LOWER(username)=LOWER(:u) LIMIT 1");
            $stU->execute([':u' => $email]);
            $baseUser = $stU->fetch() ?: null;
        }
    } elseif ($parentId > 0) {
        $title = 'Parent Profile Details';
        $st = $pdo->prepare("
            SELECT p.*, u.userid, u.username AS user_username, u.role, u.createdDate
            FROM parents p
            LEFT JOIN user u ON u.userid = p.user_id
            WHERE p.id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $parentId]);
        $parent = $st->fetch() ?: null;

        if (!$parent) {
            throw new Exception('Parent profile not found.');
        }

        $email = trim((string)($parent['email'] ?? ''));
        $uid = trim((string)($parent['user_id'] ?? ''));
        if ($uid !== '') {
            $stU = $pdo->prepare("SELECT userid, username, role, createdDate FROM user WHERE userid = :uid LIMIT 1");
            $stU->execute([':uid' => $uid]);
            $baseUser = $stU->fetch() ?: null;
        }
        if (!$baseUser && $email !== '') {
            $stU = $pdo->prepare("SELECT userid, username, role, createdDate FROM user WHERE LOWER(username)=LOWER(:u) LIMIT 1");
            $stU->execute([':u' => $email]);
            $baseUser = $stU->fetch() ?: null;
        }
    } else {
        if ($userid === '') {
            throw new Exception('Missing user id.');
        }
        $stU = $pdo->prepare("SELECT userid, username, role, createdDate FROM user WHERE userid = :uid LIMIT 1");
        $stU->execute([':uid' => $userid]);
        $baseUser = $stU->fetch() ?: null;
        if (!$baseUser) {
            throw new Exception('User account not found.');
        }

        $username = trim((string)($baseUser['username'] ?? ''));
        $stT = $pdo->prepare("
            SELECT *
            FROM teachers
            WHERE (user_id = :uid AND :uid <> '')
               OR LOWER(email) = LOWER(:em)
            ORDER BY id ASC
            LIMIT 1
        ");
        $stT->execute([':uid' => $userid, ':em' => $username]);
        $teacher = $stT->fetch() ?: null;

        $stP = $pdo->prepare("
            SELECT *
            FROM parents
            WHERE (user_id = :uid AND :uid <> '')
               OR LOWER(email) = LOWER(:em)
               OR LOWER(username) = LOWER(:em)
            ORDER BY id ASC
            LIMIT 1
        ");
        $stP->execute([':uid' => $userid, ':em' => $username]);
        $parent = $stP->fetch() ?: null;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= upv_h($title) ?></title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .meta-card { border: 1px solid #eceff3; border-radius: 12px; }
        .meta-row { display: flex; justify-content: space-between; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f1f3f5; }
        .meta-row:last-child { border-bottom: none; }
        .meta-key { color: #6b7280; font-weight: 600; }
        .meta-val { color: #111827; text-align: right; word-break: break-word; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>
            <div class="container-fluid py-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h4 mb-0"><?= upv_h($title) ?></h1>
                    <a href="userSetup" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to User Management</a>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= upv_h($error) ?></div>
                <?php else: ?>
                    <?php if ($baseUser): ?>
                        <div class="card shadow meta-card mb-3">
                            <div class="card-header py-2"><strong>Account</strong></div>
                            <div class="card-body">
                                <div class="meta-row"><div class="meta-key">User ID</div><div class="meta-val"><code><?= upv_h($baseUser['userid'] ?? '') ?></code></div></div>
                                <div class="meta-row"><div class="meta-key">Username</div><div class="meta-val"><?= upv_h($baseUser['username'] ?? '') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Role</div><div class="meta-val"><?= upv_h($baseUser['role'] ?? '') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Created</div><div class="meta-val"><?= !empty($baseUser['createdDate']) ? upv_h($baseUser['createdDate']) : '—' ?></div></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($teacher): ?>
                        <div class="card shadow meta-card mb-3">
                            <div class="card-header py-2"><strong>Teacher Profile</strong></div>
                            <div class="card-body">
                                <div class="meta-row"><div class="meta-key">Teacher ID</div><div class="meta-val"><code><?= upv_h($teacher['id'] ?? '') ?></code></div></div>
                                <div class="meta-row"><div class="meta-key">Full Name</div><div class="meta-val"><?= upv_h($teacher['full_name'] ?? '') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Email</div><div class="meta-val"><?= upv_h($teacher['email'] ?? '') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Phone</div><div class="meta-val"><?= upv_h($teacher['phone'] ?? '—') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Active</div><div class="meta-val"><?= ((int)($teacher['active'] ?? 0) === 1) ? 'Yes' : 'No' ?></div></div>
                                <div class="meta-row"><div class="meta-key">Created</div><div class="meta-val"><?= !empty($teacher['created_at']) ? upv_h($teacher['created_at']) : '—' ?></div></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($parent): ?>
                        <div class="card shadow meta-card mb-3">
                            <div class="card-header py-2"><strong>Parent Profile</strong></div>
                            <div class="card-body">
                                <div class="meta-row"><div class="meta-key">Parent ID</div><div class="meta-val"><code><?= upv_h($parent['id'] ?? '') ?></code></div></div>
                                <div class="meta-row"><div class="meta-key">Full Name</div><div class="meta-val"><?= upv_h($parent['full_name'] ?? '') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Email</div><div class="meta-val"><?= upv_h($parent['email'] ?? '') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Username</div><div class="meta-val"><?= upv_h($parent['username'] ?? '—') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Phone</div><div class="meta-val"><?= upv_h($parent['phone'] ?? '—') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Status</div><div class="meta-val"><?= upv_h($parent['status'] ?? '—') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Address</div><div class="meta-val"><?= upv_h($parent['address'] ?? '—') ?></div></div>
                                <div class="meta-row"><div class="meta-key">Created</div><div class="meta-val"><?= !empty($parent['created_at']) ? upv_h($parent['created_at']) : '—' ?></div></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$baseUser && !$teacher && !$parent): ?>
                        <div class="alert alert-warning mb-0">No profile details found for this record.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>

