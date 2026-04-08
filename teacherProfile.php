<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/notifications.php";
require_login();

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
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

function tp_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$sessionUsername = (string)($_SESSION['username'] ?? '');
if ($sessionUsername === '') {
    die("Session username missing. Please log in again.");
}

$userStmt = $pdo->prepare("SELECT userid, username, role, password, createdDate FROM user WHERE LOWER(username)=LOWER(:u) LIMIT 1");
$userStmt->execute([':u' => $sessionUsername]);
$user = $userStmt->fetch();
if (!$user) {
    die("User account not found.");
}

$userId = (string)($user['userid'] ?? '');
$teacherStmt = $pdo->prepare("
    SELECT *
    FROM teachers
    WHERE (user_id = :uid AND :uid <> '')
       OR LOWER(email) = LOWER(:em)
    ORDER BY id ASC
    LIMIT 1
");
$teacherStmt->execute([':uid' => $userId, ':em' => $sessionUsername]);
$teacher = $teacherStmt->fetch();
if (!$teacher) {
    header("Location: unauthorized");
    exit;
}

$parentStmt = $pdo->prepare("
    SELECT *
    FROM parents
    WHERE ((user_id = :uid AND :uid <> '')
        OR LOWER(email) = LOWER(:em)
        OR LOWER(username) = LOWER(:un))
    ORDER BY id ASC
    LIMIT 1
");
$parentStmt->execute([':uid' => $userId, ':em' => $sessionUsername, ':un' => $sessionUsername]);
$linkedParent = $parentStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_teacher_profile') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $syncParent = isset($_POST['sync_parent']) && $linkedParent;

        try {
            if ($fullName === '') throw new Exception("Full name is required.");
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Valid email is required.");

            $dupUser = $pdo->prepare("SELECT userid FROM user WHERE LOWER(username)=LOWER(:u) AND userid<>:id LIMIT 1");
            $dupUser->execute([':u' => $email, ':id' => $userId]);
            if ($dupUser->fetch()) throw new Exception("This email is already used by another user.");

            $dupTeacher = $pdo->prepare("SELECT id FROM teachers WHERE LOWER(email)=LOWER(:e) AND id<>:id LIMIT 1");
            $dupTeacher->execute([':e' => $email, ':id' => (int)$teacher['id']]);
            if ($dupTeacher->fetch()) throw new Exception("This email is already used by another teacher.");

            if ($syncParent) {
                $dupParent = $pdo->prepare("SELECT id FROM parents WHERE LOWER(email)=LOWER(:e) AND id<>:id LIMIT 1");
                $dupParent->execute([':e' => $email, ':id' => (int)$linkedParent['id']]);
                if ($dupParent->fetch()) throw new Exception("This email is already used by another parent account.");
            }

            $pdo->beginTransaction();

            $pdo->prepare("UPDATE teachers SET full_name=:n, email=:e, phone=:p WHERE id=:id")
                ->execute([':n' => $fullName, ':e' => $email, ':p' => ($phone === '' ? null : $phone), ':id' => (int)$teacher['id']]);

            $pdo->prepare("UPDATE user SET username=:u WHERE userid=:id")
                ->execute([':u' => $email, ':id' => $userId]);

            if ($syncParent) {
                $pdo->prepare("UPDATE parents SET full_name=:n, email=:e, phone=:p, username=:u WHERE id=:id")
                    ->execute([
                        ':n' => $fullName,
                        ':e' => $email,
                        ':p' => ($phone === '' ? null : $phone),
                        ':u' => $email,
                        ':id' => (int)$linkedParent['id']
                    ]);
            }

            $pdo->commit();

            $_SESSION['username'] = $email;
            $_SESSION['email'] = $email;
            bbcc_notify_username($pdo, $email, 'Teacher Profile Updated', 'Your teacher profile details were updated successfully.', 'teacherProfile');

            $_SESSION['teacher_profile_flash'] = ['status' => 'success', 'msg' => 'Profile updated successfully.'];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['teacher_profile_flash'] = ['status' => 'error', 'msg' => $e->getMessage()];
        }

        header("Location: teacherProfile");
        exit;
    }

    if ($action === 'change_password') {
        $currentPwd = (string)($_POST['current_password'] ?? '');
        $newPwd = (string)($_POST['new_password'] ?? '');
        $confirmPwd = (string)($_POST['confirm_password'] ?? '');

        try {
            if ($currentPwd === '' || $newPwd === '' || $confirmPwd === '') throw new Exception("All password fields are required.");
            if (strlen($newPwd) < 8) throw new Exception("New password must be at least 8 characters.");
            if ($newPwd !== $confirmPwd) throw new Exception("New passwords do not match.");
            if (!password_verify($currentPwd, (string)$user['password'])) throw new Exception("Current password is incorrect.");

            $pdo->prepare("UPDATE user SET password=:pw WHERE userid=:id")
                ->execute([':pw' => password_hash($newPwd, PASSWORD_DEFAULT), ':id' => $userId]);

            bbcc_notify_username($pdo, (string)($_SESSION['username'] ?? ''), 'Password Changed', 'Your teacher account password was updated successfully.', 'teacherProfile');
            $_SESSION['teacher_profile_flash'] = ['status' => 'success', 'msg' => 'Password changed successfully.'];
        } catch (Exception $e) {
            $_SESSION['teacher_profile_flash'] = ['status' => 'error', 'msg' => $e->getMessage()];
        }

        header("Location: teacherProfile");
        exit;
    }
}

// Refresh records after any redirect-safe actions
$userStmt->execute([':u' => (string)($_SESSION['username'] ?? $sessionUsername)]);
$user = $userStmt->fetch();
$sessionUsername = (string)($user['username'] ?? $sessionUsername);
$userId = (string)($user['userid'] ?? $userId);

$teacherStmt->execute([':uid' => $userId, ':em' => $sessionUsername]);
$teacher = $teacherStmt->fetch() ?: $teacher;
$parentStmt->execute([':uid' => $userId, ':em' => $sessionUsername, ':un' => $sessionUsername]);
$linkedParent = $parentStmt->fetch() ?: null;
$assignedClasses = [];
if (!empty($teacher['id'])) {
    $classStmt = $pdo->prepare("
        SELECT class_name, active, schedule_text
        FROM classes
        WHERE teacher_id = :tid
        ORDER BY active DESC, class_name ASC
    ");
    $classStmt->execute([':tid' => (int)$teacher['id']]);
    $assignedClasses = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$flash = $_SESSION['teacher_profile_flash'] ?? null;
unset($_SESSION['teacher_profile_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Teacher Profile</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <?php if ($flash): ?>
                <script>
                document.addEventListener('DOMContentLoaded', function(){
                    Swal.fire({icon: <?= json_encode($flash['status']) ?>, title: <?= json_encode($flash['msg']) ?>, timer: 2200, showConfirmButton: false});
                });
                </script>
                <?php endif; ?>

                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">Teacher Profile</h1>
                        <p class="text-muted mb-0" style="font-size:.88rem;">Manage your teacher account and linked parent details.</p>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user mr-1"></i>Teacher Details</h6></div>
                            <div class="card-body">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_teacher_profile">
                                    <div class="form-group">
                                        <label>Full Name</label>
                                        <input type="text" class="form-control" name="full_name" required value="<?= tp_h($teacher['full_name'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Email (Login Username)</label>
                                        <input type="email" class="form-control" name="email" required value="<?= tp_h($teacher['email'] ?? $sessionUsername) ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <input type="text" class="form-control" name="phone" value="<?= tp_h($teacher['phone'] ?? '') ?>">
                                    </div>
                                    <?php if ($linkedParent): ?>
                                    <div class="custom-control custom-checkbox mb-3">
                                        <input type="checkbox" class="custom-control-input" id="syncParent" name="sync_parent" checked>
                                        <label class="custom-control-label" for="syncParent">This teacher is also a parent (sync parent details too)</label>
                                    </div>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Profile</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-key mr-1"></i>Change Password</h6></div>
                            <div class="card-body">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="form-group">
                                        <label>Current Password</label>
                                        <input type="password" class="form-control" name="current_password" required autocomplete="current-password">
                                    </div>
                                    <div class="form-group">
                                        <label>New Password</label>
                                        <input type="password" class="form-control" name="new_password" required autocomplete="new-password">
                                    </div>
                                    <div class="form-group">
                                        <label>Confirm New Password</label>
                                        <input type="password" class="form-control" name="confirm_password" required autocomplete="new-password">
                                    </div>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Update Password</button>
                                </form>
                            </div>
                        </div>

                        <div class="card shadow">
                            <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-link mr-1"></i>Parent Link Status</h6></div>
                            <div class="card-body">
                                <?php if ($linkedParent): ?>
                                    <div class="text-success font-weight-bold mb-2"><i class="fas fa-check-circle mr-1"></i>Linked Parent Account Found</div>
                                    <div class="small"><strong>Parent Name:</strong> <?= tp_h($linkedParent['full_name'] ?? '') ?></div>
                                    <div class="small"><strong>Parent Email:</strong> <?= tp_h($linkedParent['email'] ?? '') ?></div>
                                    <div class="small"><strong>Parent Phone:</strong> <?= tp_h($linkedParent['phone'] ?? '') ?></div>
                                <?php else: ?>
                                    <div class="text-muted">No linked parent account found for this teacher login email.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-chalkboard mr-1"></i>Assigned Classes</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($assignedClasses)): ?>
                            <p class="text-muted mb-0">No classes assigned yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="thead-light">
                                        <tr><th>Class</th><th>Status</th><th>Schedule</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($assignedClasses as $c): ?>
                                        <tr>
                                            <td><?= tp_h((string)($c['class_name'] ?? '')) ?></td>
                                            <td>
                                                <?php if ((int)($c['active'] ?? 0) === 1): ?>
                                                    <span class="badge badge-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= tp_h((string)($c['schedule_text'] ?? '-')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
