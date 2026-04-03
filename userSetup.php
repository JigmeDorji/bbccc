<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/role_helpers.php";
require_login();
allowRoles(['Administrator', 'Admin', 'Company Admin', 'System_owner']);

// ─── DB ──────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    bbcc_fail_db($e);
}

// ─── POST handler (PRG) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $status = 'success';
    $msg    = '';

    try {

        // ── Create Admin user ────────────────────────────
        if ($action === 'create_admin') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = $_POST['role'] ?? 'Admin';

            $fullName = trim($_POST['full_name'] ?? '');
            if ($username === '' || $password === '') throw new Exception("Username and password are required.");
            if (!filter_var($username, FILTER_VALIDATE_EMAIL)) throw new Exception("Username must be a valid email address.");
            if (!in_array($role, ['Admin', 'teacher'])) throw new Exception("Invalid role.");
            if ($role === 'teacher' && $fullName === '') throw new Exception("Full name is required for Teacher accounts.");

            $chk = $pdo->prepare("SELECT userid FROM user WHERE username = :u");
            $chk->execute([':u' => $username]);
            if ($chk->fetch()) throw new Exception("Username already exists.");

            $row = $pdo->query("SELECT MAX(CAST(userid AS UNSIGNED)) AS m FROM user")->fetch();
            $uid = (string)((int)($row['m'] ?? 0) + 1);

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO user (userid,username,password,role,createdDate) VALUES (:uid,:u,:pw,:role,:dt)")
                ->execute([':uid' => $uid, ':u' => $username, ':pw' => password_hash($password, PASSWORD_DEFAULT), ':role' => $role, ':dt' => date('Y-m-d H:i:s')]);

            // Auto-create teachers record for teacher accounts
            if ($role === 'teacher') {
                $pdo->prepare("INSERT INTO teachers (user_id, full_name, email) VALUES (:uid, :name, :email)")
                    ->execute([':uid' => $uid, ':name' => $fullName, ':email' => (filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : null)]);
            }
            $pdo->commit();

            $msg = ($role === 'teacher' ? "Teacher" : "Admin") . " account '{$username}' created successfully.";

        // ── Edit user ────────────────────────────────────
        } elseif ($action === 'edit_user') {
            $userId  = trim($_POST['userid'] ?? '');
            $role    = $_POST['role'] ?? '';
            $password = $_POST['password'] ?? '';

            if ($userId === '' || $role === '') throw new Exception("User ID and role are required.");

            if ($password !== '') {
                $pdo->prepare("UPDATE user SET role=:role, password=:pw WHERE userid=:uid")
                    ->execute([':role' => $role, ':pw' => password_hash($password, PASSWORD_DEFAULT), ':uid' => $userId]);
            } else {
                $pdo->prepare("UPDATE user SET role=:role WHERE userid=:uid")
                    ->execute([':role' => $role, ':uid' => $userId]);
            }
            $msg = "User updated successfully.";

        // ── Toggle active (parents & teachers via their profile tables) ──
        } elseif ($action === 'toggle_parent_status') {
            $parentId = (int)($_POST['parent_id'] ?? 0);
            if ($parentId === 0) throw new Exception("Invalid parent.");
            $pdo->prepare("UPDATE parents SET status = IF(status='Active','Inactive','Active') WHERE id=:id")
                ->execute([':id' => $parentId]);
            $msg = "Parent status toggled.";

        } elseif ($action === 'toggle_teacher_active') {
            $teacherId = (int)($_POST['teacher_id'] ?? 0);
            if ($teacherId === 0) throw new Exception("Invalid teacher.");
            $pdo->prepare("UPDATE teachers SET active = IF(active=1,0,1) WHERE id=:id")
                ->execute([':id' => $teacherId]);
            $msg = "Teacher status toggled.";

        // ── Delete admin user ────────────────────────────
        } elseif ($action === 'delete_user') {
            $userId = trim($_POST['userid'] ?? '');
            if ($userId === '') throw new Exception("Invalid user.");
            if ($userId === '1') throw new Exception("The root admin account cannot be deleted.");

            // If linked to a teacher record, remove teacher  row too
            $pdo->prepare("DELETE FROM teachers WHERE user_id=:uid")->execute([':uid' => $userId]);
            $pdo->prepare("DELETE FROM user WHERE userid=:uid")->execute([':uid' => $userId]);
            $msg = "User deleted successfully.";

        } else {
            throw new Exception("Unknown action.");
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $status = 'error';
        $msg    = $e->getMessage();
    }

    $_SESSION['user_flash'] = ['status' => $status, 'msg' => $msg];
    header("Location: userSetup");
    exit;
}

// ─── Flash ───────────────────────────────────────────────
$flash = $_SESSION['user_flash'] ?? null;
unset($_SESSION['user_flash']);

// ─── Admin roles (in user table) ─────────────────────────
$adminRoles = ['Admin', 'Company Admin', 'Staff', 'System_owner', 'Administrator'];
$adminRolePlaceholders = implode(',', array_fill(0, count($adminRoles), '?'));

// ─── Data ────────────────────────────────────────────────
$allUsers = $pdo->query(
    "SELECT userid, username, role, createdDate FROM user ORDER BY CAST(userid AS UNSIGNED) ASC"
)->fetchAll();

$adminUsers = $pdo->query(
    "SELECT userid, username, role, createdDate FROM user
     WHERE role IN ('Admin','Company Admin','Staff','System_owner','Administrator')
     ORDER BY role, username"
)->fetchAll();

$teacherUsers = $pdo->query(
    "SELECT t.id AS teacher_id, t.full_name, t.email, t.phone, t.active, t.created_at,
            u.userid, u.username
     FROM teachers t
     LEFT JOIN user u ON u.userid = t.user_id
     ORDER BY t.full_name"
)->fetchAll();

$parentUsers = $pdo->query(
    "SELECT id, full_name, email, phone, username, status, created_at FROM parents ORDER BY full_name"
)->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>User Management — BBCCC Admin</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand:#881b12; }
        .card { border:none !important; border-radius:14px !important; }
        .nav-tabs .nav-link.active { border-bottom:3px solid var(--brand); color:var(--brand); font-weight:600; }
        .nav-tabs .nav-link { color:#555; }
        .role-badge { border-radius:10px; padding:3px 10px; font-size:.76rem; font-weight:600; }
        .role-admin    { background:#fef3f2; color:var(--brand); border:1px solid #f7c6c3; }
        .role-teacher  { background:#e8f4fd; color:#1a6c9c; border:1px solid #b8dcf2; }
        .role-parent   { background:#eafaf1; color:#196f3d; border:1px solid #a9dfbf; }
        .role-staff    { background:#fdf2e9; color:#935116; border:1px solid #f0c07d; }
        .role-system   { background:#f4ecf7; color:#6c3483; border:1px solid #d2b4de; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>
            <div class="container-fluid">

                <!-- Heading -->
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">User Management</h1>
                        <p class="text-muted mb-0" style="font-size:.88rem;">Manage admin accounts, view teachers and parents.</p>
                    </div>
                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#createAdminModal" style="border-radius:10px;">
                        <i class="fas fa-user-plus mr-1"></i> Add Admin User
                    </button>
                </div>

                <!-- Flash -->
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

                <!-- Tabs -->
                <ul class="nav nav-tabs mb-4" id="userTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-toggle="tab" href="#pane-all" role="tab">
                            <i class="fas fa-users mr-1"></i> All Users
                            <span class="badge badge-secondary ml-1"><?= count($allUsers) ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#pane-admins" role="tab">
                            <i class="fas fa-user-shield mr-1"></i> Admins
                            <span class="badge badge-secondary ml-1"><?= count($adminUsers) ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#pane-teachers" role="tab">
                            <i class="fas fa-chalkboard-teacher mr-1"></i> Teachers
                            <span class="badge badge-secondary ml-1"><?= count($teacherUsers) ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#pane-parents" role="tab">
                            <i class="fas fa-user-friends mr-1"></i> Parents
                            <span class="badge badge-secondary ml-1"><?= count($parentUsers) ?></span>
                        </a>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- ═ All Users ═ -->
                    <div class="tab-pane fade show active" id="pane-all" role="tabpanel">
                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover dt-table" id="tblAll">
                                        <thead style="background:#f8f9fc;">
                                        <tr>
                                            <th>ID</th>
                                            <th>Username</th>
                                            <th>Role</th>
                                            <th>Created</th>
                                            <th style="width:90px;text-align:center;">Actions</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($allUsers as $u):
                                            $rc = match(true) {
                                                in_array($u['role'], ['Admin','Company Admin','Administrator','System_owner']) => 'role-admin',
                                                $u['role'] === 'teacher' => 'role-teacher',
                                                $u['role'] === 'parent'  => 'role-parent',
                                                $u['role'] === 'Staff'   => 'role-staff',
                                                default                  => 'role-system'
                                            };
                                        ?>
                                            <tr>
                                                <td><code><?= htmlspecialchars($u['userid']) ?></code></td>
                                                <td class="font-weight-bold"><?= htmlspecialchars($u['username']) ?></td>
                                                <td><span class="role-badge <?= $rc ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                                                <td style="font-size:.84rem;color:#888;"><?= $u['createdDate'] ? htmlspecialchars(date('d M Y', strtotime($u['createdDate']))) : '—' ?></td>
                                                <td class="text-center">
                                                    <?php if (in_array($u['role'], ['Admin','Company Admin','Staff','System_owner','Administrator'])): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-user"
                                                        data-userid="<?= htmlspecialchars($u['userid'], ENT_QUOTES) ?>"
                                                        data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
                                                        data-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>"
                                                        title="Edit Role"
                                                        style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;">
                                                        <i class="fas fa-pencil-alt" style="font-size:.75rem;"></i>
                                                    </button>
                                                    <?php if ($u['userid'] !== '1'): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-user"
                                                        data-userid="<?= htmlspecialchars($u['userid'], ENT_QUOTES) ?>"
                                                        data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
                                                        title="Delete"
                                                        style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;">
                                                        <i class="fas fa-trash-alt" style="font-size:.75rem;"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($allUsers)): ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-inbox mr-1"></i> No users found.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═ Admins ═ -->
                    <div class="tab-pane fade" id="pane-admins" role="tabpanel">
                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover dt-table" id="tblAdmins">
                                        <thead style="background:#f8f9fc;">
                                        <tr>
                                            <th>Username</th>
                                            <th>Role</th>
                                            <th>Created</th>
                                            <th style="width:90px;text-align:center;">Actions</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($adminUsers as $u): ?>
                                            <tr>
                                                <td class="font-weight-bold">
                                                    <i class="fas fa-user-shield mr-1" style="color:var(--brand);font-size:.8rem;"></i>
                                                    <?= htmlspecialchars($u['username']) ?>
                                                </td>
                                                <td><span class="role-badge role-admin"><?= htmlspecialchars($u['role']) ?></span></td>
                                                <td style="font-size:.84rem;color:#888;"><?= $u['createdDate'] ? htmlspecialchars(date('d M Y', strtotime($u['createdDate']))) : '—' ?></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-user"
                                                        data-userid="<?= htmlspecialchars($u['userid'], ENT_QUOTES) ?>"
                                                        data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
                                                        data-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>"
                                                        title="Edit"
                                                        style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;">
                                                        <i class="fas fa-pencil-alt" style="font-size:.75rem;"></i>
                                                    </button>
                                                    <?php if ($u['userid'] !== '1'): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-user"
                                                        data-userid="<?= htmlspecialchars($u['userid'], ENT_QUOTES) ?>"
                                                        data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
                                                        title="Delete"
                                                        style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;">
                                                        <i class="fas fa-trash-alt" style="font-size:.75rem;"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($adminUsers)): ?>
                                            <tr><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-inbox mr-1"></i> No admin users found.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═ Teachers ═ -->
                    <div class="tab-pane fade" id="pane-teachers" role="tabpanel">
                        <div class="d-flex justify-content-end mb-2">
                            <a href="admin-class-setup#pane-teachers" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                                <i class="fas fa-user-plus mr-1"></i> Add Teacher
                            </a>
                        </div>
                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover dt-table" id="tblTeachers">
                                        <thead style="background:#f8f9fc;">
                                        <tr>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Status</th>
                                            <th style="width:70px;text-align:center;">Toggle</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($teacherUsers as $t): ?>
                                            <tr>
                                                <td class="font-weight-bold">
                                                    <i class="fas fa-chalkboard-teacher mr-1" style="color:#1a6c9c;font-size:.8rem;"></i>
                                                    <?= htmlspecialchars($t['full_name']) ?>
                                                </td>
                                                <td><code><?= htmlspecialchars($t['username'] ?? '—') ?></code></td>
                                                <td><?= htmlspecialchars($t['email'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($t['phone'] ?? '—') ?></td>
                                                <td>
                                                    <?php if ($t['active']): ?>
                                                        <span class="badge badge-success" style="border-radius:10px;padding:4px 10px;">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary" style="border-radius:10px;padding:4px 10px;">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="toggle_teacher_active">
                                                        <input type="hidden" name="teacher_id" value="<?= (int)$t['teacher_id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary" style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;" title="Toggle Active">
                                                            <i class="fas fa-toggle-<?= $t['active'] ? 'on' : 'off' ?>" style="font-size:.85rem;color:<?= $t['active'] ? '#1cc88a' : '#adb5bd' ?>;"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($teacherUsers)): ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox mr-1"></i> No teachers found. <a href="admin-class-setup#pane-teachers">Add one →</a></td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═ Parents ═ -->
                    <div class="tab-pane fade" id="pane-parents" role="tabpanel">
                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover dt-table" id="tblParents">
                                        <thead style="background:#f8f9fc;">
                                        <tr>
                                            <th>Name</th>
                                            <th>Username / Email</th>
                                            <th>Phone</th>
                                            <th>Status</th>
                                            <th>Joined</th>
                                            <th style="width:70px;text-align:center;">Toggle</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($parentUsers as $p): ?>
                                            <tr>
                                                <td class="font-weight-bold">
                                                    <i class="fas fa-user-friends mr-1" style="color:#196f3d;font-size:.8rem;"></i>
                                                    <?= htmlspecialchars($p['full_name']) ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($p['username'] ?? '—') ?>
                                                    <?php if ($p['email']): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($p['email']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($p['phone'] ?? '—') ?></td>
                                                <td>
                                                    <?php if ($p['status'] === 'Active'): ?>
                                                        <span class="badge badge-success" style="border-radius:10px;padding:4px 10px;">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary" style="border-radius:10px;padding:4px 10px;">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size:.84rem;color:#888;"><?= htmlspecialchars(date('d M Y', strtotime($p['created_at']))) ?></td>
                                                <td class="text-center">
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="toggle_parent_status">
                                                        <input type="hidden" name="parent_id" value="<?= (int)$p['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary" style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;" title="Toggle Active">
                                                            <i class="fas fa-toggle-<?= $p['status'] === 'Active' ? 'on' : 'off' ?>" style="font-size:.85rem;color:<?= $p['status'] === 'Active' ? '#1cc88a' : '#adb5bd' ?>;"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($parentUsers)): ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox mr-1"></i> No parents registered yet.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /tab-content -->
            </div>
        </div>
        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<!-- ═ Create Admin Modal ═ -->
<div class="modal fade" id="createAdminModal" tabindex="-1" role="dialog" aria-labelledby="createAdminLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius:14px;border:none;">
            <form method="POST" id="createAdminForm">
                <input type="hidden" name="action" value="create_admin">
                <div class="modal-header" style="border-radius:14px 14px 0 0;background:#f8f9fc;">
                    <h5 class="modal-title font-weight-bold" id="createAdminLabel">
                        <i class="fas fa-user-plus mr-1 text-primary"></i> Add Admin User
                    </h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-at mr-1" style="color:var(--brand);font-size:.75rem;"></i> Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="username" required placeholder="e.g. admin@bbccc.com" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-key mr-1" style="color:var(--brand);font-size:.75rem;"></i> Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="password" id="newAdminPwd" required placeholder="Min 8 characters" autocomplete="new-password">
                            <div class="input-group-append">
                                <span class="input-group-text" style="cursor:pointer;" onclick="togglePwd('newAdminPwd',this)">
                                    <i class="fas fa-eye-slash"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tag mr-1" style="color:var(--brand);font-size:.75rem;"></i> Role <span class="text-danger">*</span></label>
                        <select class="form-control" name="role" id="createAdminRole" onchange="toggleFullNameField(this.value)">
                            <option value="Admin">Admin</option>
                            <option value="teacher">Teacher</option>
                        </select>
                    </div>
                    <div class="form-group" id="fullNameGroup" style="display:none;">
                        <label><i class="fas fa-user mr-1" style="color:var(--brand);font-size:.75rem;"></i> Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" id="createFullName" placeholder="e.g. Tenzin Dorji" autocomplete="off">
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid #eee;">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" style="border-radius:10px;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-plus mr-1"></i> Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═ Edit User Modal ═ -->
<div class="modal fade" id="editUserModal" tabindex="-1" role="dialog" aria-labelledby="editUserLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius:14px;border:none;">
            <form method="POST" id="editUserForm">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="userid" id="editUserId">
                <div class="modal-header" style="border-radius:14px 14px 0 0;background:#f8f9fc;">
                    <h5 class="modal-title font-weight-bold" id="editUserLabel">
                        <i class="fas fa-pencil-alt mr-1 text-primary"></i> Edit User
                    </h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="text-muted" style="font-size:.85rem;">Username</label>
                        <p class="font-weight-bold mb-2" id="editUserUsername"></p>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tag mr-1" style="color:var(--brand);font-size:.75rem;"></i> Role</label>
                        <select class="form-control" name="role" id="editUserRole">
                            <option value="Admin">Admin</option>
                            <option value="teacher">Teacher</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-key mr-1" style="color:var(--brand);font-size:.75rem;"></i> New Password <small class="text-muted">(leave blank to keep current)</small></label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="password" id="editUserPwd" placeholder="Leave blank to keep" autocomplete="new-password">
                            <div class="input-group-append">
                                <span class="input-group-text" style="cursor:pointer;" onclick="togglePwd('editUserPwd',this)">
                                    <i class="fas fa-eye-slash"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid #eee;">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" style="border-radius:10px;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save mr-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden delete form -->
<form id="deleteUserForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="userid" id="deleteUserId">
</form>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/datatables/jquery.dataTables.min.js"></script>
<script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
<script>
$(function () {
    // Restore tab from URL hash
    var hash = window.location.hash || '#pane-all';
    $('#userTabs a[href="' + hash + '"]').tab('show');
    $('#userTabs a').on('shown.bs.tab', function (e) { history.replaceState(null, null, e.target.hash); });

    // Init DataTables
    $('.dt-table').each(function () {
        if ($(this).find('tbody tr td').length > 1) {
            $(this).DataTable({ pageLength: 25, language: { searchPlaceholder: 'Search...' } });
        }
    });

    // Reset create modal when closed
    $('#createAdminModal').on('hidden.bs.modal', function () {
        document.getElementById('createAdminRole').value = 'Admin';
        toggleFullNameField('Admin');
    });

    // Open Edit modal
    $(document).on('click', '.btn-edit-user', function () {
        var btn = $(this);
        $('#editUserId').val(btn.data('userid'));
        $('#editUserUsername').text(btn.data('username'));
        $('#editUserRole').val(btn.data('role'));
        $('#editUserPwd').val('');
        $('#editUserModal').modal('show');
    });

    // Delete user with confirm
    $(document).on('click', '.btn-delete-user', function () {
        var uid  = $(this).data('userid');
        var uname = $('<span>').text($(this).data('username')).html();
        Swal.fire({
            title: 'Delete User?',
            html: 'Permanently delete <strong>' + uname + '</strong>?<br><small class="text-danger">This also removes any linked teacher account.</small>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#881b12',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, delete',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then(function (r) {
            if (r.isConfirmed) {
                $('#deleteUserId').val(uid);
                $('#deleteUserForm').submit();
            }
        });
    });
});

function toggleFullNameField(role) {
    var grp = document.getElementById('fullNameGroup');
    var inp = document.getElementById('createFullName');
    if (role === 'teacher') {
        grp.style.display = 'block';
        inp.required = true;
    } else {
        grp.style.display = 'none';
        inp.required = false;
        inp.value = '';
    }
}

function togglePwd(id, el) {
    var inp = document.getElementById(id);
    var ico = el.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text'; ico.classList.replace('fa-eye-slash', 'fa-eye');
    } else {
        inp.type = 'password'; ico.classList.replace('fa-eye', 'fa-eye-slash');
    }
}
</script>
</body>
</html>
