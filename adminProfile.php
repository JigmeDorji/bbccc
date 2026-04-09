<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/notifications.php";
require_once "include/csrf.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role === 'parent') {
    header("Location: parentProfile");
    exit;
}

// ── DB ──────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    bbcc_fail_db($e);
}

$sessionUsername = logged_in_username();

function ap_ensure_profile_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_profiles (
            user_id VARCHAR(50) NOT NULL PRIMARY KEY,
            full_name VARCHAR(150) NULL,
            title VARCHAR(120) NULL,
            phone VARCHAR(40) NULL,
            address VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

// ── Fetch current user ──────────────────────────────────────────
$userStmt = $pdo->prepare("SELECT userid, username, role, createdDate FROM user WHERE username = :u LIMIT 1");
$userStmt->execute([':u' => $sessionUsername]);
$userRow = $userStmt->fetch();
ap_ensure_profile_table($pdo);

$profile = ['full_name' => '', 'title' => '', 'phone' => '', 'address' => ''];
if (!empty($userRow['userid'])) {
    $profStmt = $pdo->prepare("SELECT full_name, title, phone, address FROM admin_profiles WHERE user_id = :uid LIMIT 1");
    $profStmt->execute([':uid' => (string)$userRow['userid']]);
    $p = $profStmt->fetch();
    if ($p) $profile = array_merge($profile, $p);
}

// ── POST handlers ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'change_password');
    $status = 'error';
    $msg    = '';

    try {
        if ($action === 'update_profile') {
            if (empty($userRow['userid'])) throw new Exception("User not found.");
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $title    = trim((string)($_POST['title'] ?? ''));
            $phone    = trim((string)($_POST['phone'] ?? ''));
            $address  = trim((string)($_POST['address'] ?? ''));
            if ($fullName === '') throw new Exception("Full name is required.");

            $stmt = $pdo->prepare("
                INSERT INTO admin_profiles (user_id, full_name, title, phone, address)
                VALUES (:uid, :full_name, :title, :phone, :address)
                ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    title = VALUES(title),
                    phone = VALUES(phone),
                    address = VALUES(address)
            ");
            $stmt->execute([
                ':uid' => (string)$userRow['userid'],
                ':full_name' => $fullName,
                ':title' => $title !== '' ? $title : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':address' => $address !== '' ? $address : null,
            ]);
            $status = 'success';
            $msg = 'Profile details updated successfully.';
        } else {
            $currentPwd = $_POST['current_password'] ?? '';
            $newPwd     = $_POST['new_password'] ?? '';
            $confirmPwd = $_POST['confirm_password'] ?? '';
            if ($currentPwd === '' || $newPwd === '' || $confirmPwd === '') {
                throw new Exception("All password fields are required.");
            }
            if (strlen($newPwd) < 8) {
                throw new Exception("New password must be at least 8 characters.");
            }
            if ($newPwd !== $confirmPwd) {
                throw new Exception("New passwords do not match.");
            }
            $stmt = $pdo->prepare("SELECT password FROM user WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $sessionUsername]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($currentPwd, $row['password'])) {
                throw new Exception("Current password is incorrect.");
            }
            $pdo->prepare("UPDATE user SET password = :pw WHERE username = :u")
                ->execute([':pw' => password_hash($newPwd, PASSWORD_DEFAULT), ':u' => $sessionUsername]);
            bbcc_notify_username(
                $pdo,
                $sessionUsername,
                'Password Changed',
                'Your account password was updated successfully.',
                'adminProfile'
            );
            $status = 'success';
            $msg    = 'Password changed successfully.';
        }
    } catch (Exception $e) {
        $msg = $e->getMessage();
    }

    $_SESSION['profile_flash'] = ['status' => $status, 'msg' => $msg];
    header("Location: adminProfile");
    exit;
}

$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

$userStmt->execute([':u' => $sessionUsername]);
$userRow = $userStmt->fetch();
$profile = ['full_name' => '', 'title' => '', 'phone' => '', 'address' => ''];
if (!empty($userRow['userid'])) {
    $profStmt = $pdo->prepare("SELECT full_name, title, phone, address FROM admin_profiles WHERE user_id = :uid LIMIT 1");
    $profStmt->execute([':uid' => (string)$userRow['userid']]);
    $p = $profStmt->fetch();
    if ($p) $profile = array_merge($profile, $p);
}
$displayName = trim((string)($profile['full_name'] ?? ''));
if ($displayName === '') $displayName = (string)$sessionUsername;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>My Profile — Admin</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand:#881b12; }
        .card { border:none !important; border-radius:14px !important; }
        .avatar-circle {
            width:72px; height:72px; border-radius:50%;
            background:var(--brand); color:#fff;
            display:flex; align-items:center; justify-content:center;
            font-size:1.8rem; font-weight:700; flex-shrink:0;
        }
        .role-badge { border-radius:10px; padding:4px 12px; font-size:.78rem; font-weight:600; background:#fef3f2; color:var(--brand); border:1px solid #f7c6c3; }
        .info-row { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f0f0f0; font-size:.93rem; }
        .info-row:last-child { border-bottom:none; }
        .info-label { color:#888; font-weight:500; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>
            <div class="container-fluid">

                <?php if ($flash): ?>
                <script>
                document.addEventListener('DOMContentLoaded', () => {
                    Swal.fire({
                        icon: <?= json_encode($flash['status']) ?>,
                        title: <?= json_encode($flash['msg']) ?>,
                        timer: 2800, showConfirmButton: false
                    });
                });
                </script>
                <?php endif; ?>

                <div class="d-flex align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">My Profile</h1>
                        <p class="text-muted mb-0" style="font-size:.88rem;">Manage your account details and password.</p>
                    </div>
                </div>

                <div class="row">

                    <!-- Account Info -->
                    <div class="col-lg-5 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center mb-4">
                                    <div class="avatar-circle mr-3">
                                        <?= strtoupper(substr($displayName, 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-weight-bold" style="font-size:1.05rem;"><?= htmlspecialchars($displayName) ?></div>
                                        <span class="role-badge mt-1 d-inline-block"><?= htmlspecialchars($userRow['role'] ?? $_SESSION['role'] ?? '') ?></span>
                                    </div>
                                </div>

                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-at mr-1"></i> Username</span>
                                    <span class="font-weight-bold"><?= htmlspecialchars($userRow['username'] ?? $sessionUsername) ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-user mr-1"></i> Full Name</span>
                                    <span><?= htmlspecialchars($profile['full_name'] ?? '—') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-id-badge mr-1"></i> Title</span>
                                    <span><?= htmlspecialchars($profile['title'] ?? '—') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-phone mr-1"></i> Phone</span>
                                    <span><?= htmlspecialchars($profile['phone'] ?? '—') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-map-marker-alt mr-1"></i> Address</span>
                                    <span><?= htmlspecialchars($profile['address'] ?? '—') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-user-tag mr-1"></i> Role</span>
                                    <span><?= htmlspecialchars($userRow['role'] ?? '') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-calendar-alt mr-1"></i> Account Created</span>
                                    <span><?= !empty($userRow['createdDate']) ? date('d M Y', strtotime($userRow['createdDate'])) : '—' ?></span>
                                </div>

                                <div class="mt-4 d-flex flex-wrap">
                                    <a href="index-admin" class="btn btn-sm btn-primary mr-2 mb-2" style="border-radius:8px;">
                                        <i class="fas fa-tachometer-alt mr-1"></i> Dashboard
                                    </a>
                                    <form action="logout" method="POST" style="display:inline;">
                                        <button type="submit" class="btn btn-sm btn-outline-danger mb-2" style="border-radius:8px;">
                                            <i class="fas fa-sign-out-alt mr-1"></i> Logout
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Change Password -->
                    <div class="col-lg-7 mb-4">
                        <div class="card shadow mb-4">
                            <div class="card-body p-4">
                                <h5 class="font-weight-bold mb-1" style="color:var(--brand);">
                                    <i class="fas fa-id-card mr-2"></i>Update Profile Details
                                </h5>
                                <p class="text-muted mb-4" style="font-size:.85rem;">Update your name and contact details.</p>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_profile">
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label style="font-size:.88rem;font-weight:600;">Full Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="full_name" value="<?= htmlspecialchars((string)($profile['full_name'] ?? '')) ?>" required>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label style="font-size:.88rem;font-weight:600;">Title</label>
                                            <input type="text" class="form-control" name="title" value="<?= htmlspecialchars((string)($profile['title'] ?? '')) ?>" placeholder="e.g. Program Coordinator">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label style="font-size:.88rem;font-weight:600;">Phone</label>
                                            <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars((string)($profile['phone'] ?? '')) ?>">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label style="font-size:.88rem;font-weight:600;">Address</label>
                                            <input type="text" class="form-control" name="address" value="<?= htmlspecialchars((string)($profile['address'] ?? '')) ?>">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary" style="border-radius:10px;min-width:160px;">
                                        <i class="fas fa-save mr-1"></i> Save Details
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card shadow">
                            <div class="card-body p-4">
                                <h5 class="font-weight-bold mb-1" style="color:var(--brand);">
                                    <i class="fas fa-key mr-2"></i>Change Password
                                </h5>
                                <p class="text-muted mb-4" style="font-size:.85rem;">Enter your current password to set a new one.</p>

                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="form-group">
                                        <label style="font-size:.88rem;font-weight:600;">Current Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" name="current_password" id="curPwd" placeholder="Your current password" required autocomplete="current-password">
                                            <div class="input-group-append">
                                                <span class="input-group-text" style="cursor:pointer;" onclick="togglePwd('curPwd',this)">
                                                    <i class="fas fa-eye-slash"></i>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label style="font-size:.88rem;font-weight:600;">New Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" name="new_password" id="newPwd" placeholder="Min 8 characters" required autocomplete="new-password">
                                            <div class="input-group-append">
                                                <span class="input-group-text" style="cursor:pointer;" onclick="togglePwd('newPwd',this)">
                                                    <i class="fas fa-eye-slash"></i>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label style="font-size:.88rem;font-weight:600;">Confirm New Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" name="confirm_password" id="conPwd" placeholder="Repeat new password" required autocomplete="new-password">
                                            <div class="input-group-append">
                                                <span class="input-group-text" style="cursor:pointer;" onclick="togglePwd('conPwd',this)">
                                                    <i class="fas fa-eye-slash"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <small class="form-text" id="matchHint"></small>
                                    </div>
                                    <button type="submit" class="btn btn-primary" style="border-radius:10px;min-width:160px;">
                                        <i class="fas fa-save mr-1"></i> Update Password
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
function togglePwd(id, el) {
    var inp = document.getElementById(id);
    var ico = el.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text'; ico.classList.replace('fa-eye-slash','fa-eye');
    } else {
        inp.type = 'password'; ico.classList.replace('fa-eye','fa-eye-slash');
    }
}
document.getElementById('conPwd').addEventListener('input', function () {
    var hint = document.getElementById('matchHint');
    if (!this.value) { hint.textContent = ''; return; }
    if (this.value === document.getElementById('newPwd').value) {
        hint.innerHTML = '<span class="text-success"><i class="fas fa-check mr-1"></i>Passwords match</span>';
    } else {
        hint.innerHTML = '<span class="text-danger"><i class="fas fa-times mr-1"></i>Passwords do not match</span>';
    }
});
</script>
</body>
</html>
