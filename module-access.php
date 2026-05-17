<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/module_access.php";
require_once "include/csrf.php";
require_login();

if (!bbcc_is_superadmin_role()) {
    header("Location: unauthorized");
    exit;
}

function ma_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function ma_profile_from_role(string $role): string {
    $r = bbcc_normalize_role_key($role);
    if (in_array($r, ['website admin', 'website_admin'], true)) return 'website_admin';
    if (in_array($r, ['parent', 'teacher', 'patron'], true)) return $r;
    if (bbcc_is_superadmin_role($r)) return 'superadmin';
    if (in_array($r, ['administrator', 'admin', 'company admin', 'company_admin', 'system owner', 'system_owner', 'staff'], true)) {
        return 'admin';
    }
    return 'guest';
}

function ma_ensure_override_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_module_access_overrides (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id VARCHAR(64) NOT NULL DEFAULT '',
          username VARCHAR(190) NOT NULL DEFAULT '',
          module_key VARCHAR(64) NOT NULL,
          action_key VARCHAR(64) NOT NULL,
          effect ENUM('grant','revoke') NOT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_by VARCHAR(190) NOT NULL DEFAULT '',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_user_module_action (user_id, username, module_key, action_key),
          KEY idx_override_user_id (user_id),
          KEY idx_override_username (username),
          KEY idx_override_module (module_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    ma_ensure_override_table($pdo);
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

$catalog = bbcc_module_catalog();
$defaults = bbcc_role_default_module_access();
$flash = $_SESSION['module_access_flash'] ?? null;
unset($_SESSION['module_access_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    $targetUserId = trim((string)($_POST['target_user_id'] ?? ''));
    $targetUsername = trim((string)($_POST['target_username'] ?? ''));

    try {
        if ($targetUserId === '' || $targetUsername === '') {
            throw new Exception('Please choose a valid user.');
        }

        $chk = $pdo->prepare("SELECT userid, username FROM user WHERE userid = :uid AND LOWER(username)=LOWER(:uname) LIMIT 1");
        $chk->execute([':uid' => $targetUserId, ':uname' => $targetUsername]);
        $row = $chk->fetch();
        if (!$row) {
            throw new Exception('Target user not found.');
        }

        if ($action === 'save_overrides') {
            $states = $_POST['state'] ?? [];

            $pdo->beginTransaction();
            $del = $pdo->prepare("DELETE FROM user_module_access_overrides WHERE user_id = :uid AND username = :uname");
            $del->execute([':uid' => $targetUserId, ':uname' => $targetUsername]);

            $ins = $pdo->prepare("
                INSERT INTO user_module_access_overrides
                (user_id, username, module_key, action_key, effect, is_active, created_by)
                VALUES (:uid, :uname, :module_key, :action_key, :effect, 1, :created_by)
            ");
            $createdBy = (string)($_SESSION['username'] ?? 'superadmin');

            foreach ($catalog as $moduleKey => $moduleMeta) {
                $actions = (array)($moduleMeta['actions'] ?? []);
                foreach ($actions as $actionKey) {
                    $state = strtolower(trim((string)($states[$moduleKey][$actionKey] ?? 'default')));
                    if (!in_array($state, ['default', 'grant', 'revoke'], true)) {
                        $state = 'default';
                    }
                    if ($state === 'default') {
                        continue;
                    }
                    $ins->execute([
                        ':uid' => $targetUserId,
                        ':uname' => $targetUsername,
                        ':module_key' => (string)$moduleKey,
                        ':action_key' => (string)$actionKey,
                        ':effect' => $state,
                        ':created_by' => $createdBy,
                    ]);
                }
            }

            $pdo->commit();
            $_SESSION['module_access_flash'] = ['type' => 'success', 'msg' => 'Module access overrides saved.'];
        } elseif ($action === 'reset_overrides') {
            $del = $pdo->prepare("DELETE FROM user_module_access_overrides WHERE user_id = :uid AND username = :uname");
            $del->execute([':uid' => $targetUserId, ':uname' => $targetUsername]);
            $_SESSION['module_access_flash'] = ['type' => 'success', 'msg' => 'Overrides reset. Role defaults now apply.'];
        } else {
            throw new Exception('Invalid action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['module_access_flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
    }

    header("Location: module-access?uid=" . urlencode($targetUserId));
    exit;
}

$users = $pdo->query("SELECT userid, username, role FROM user ORDER BY role ASC, username ASC")->fetchAll();
$selectedUserId = trim((string)($_GET['uid'] ?? ''));
if ($selectedUserId === '' && !empty($users)) {
    $selectedUserId = (string)$users[0]['userid'];
}

$selectedUser = null;
foreach ($users as $u) {
    if ((string)$u['userid'] === $selectedUserId) {
        $selectedUser = $u;
        break;
    }
}

$selectedOverrides = [];
$selectedProfile = 'guest';
if ($selectedUser) {
    $selectedProfile = ma_profile_from_role((string)$selectedUser['role']);
    $stmtOv = $pdo->prepare("
        SELECT module_key, action_key, effect
        FROM user_module_access_overrides
        WHERE user_id = :uid AND username = :uname AND is_active = 1
    ");
    $stmtOv->execute([
        ':uid' => (string)$selectedUser['userid'],
        ':uname' => (string)$selectedUser['username'],
    ]);
    foreach ($stmtOv->fetchAll() as $ov) {
        $mk = (string)($ov['module_key'] ?? '');
        $ak = (string)($ov['action_key'] ?? '');
        $ef = strtolower((string)($ov['effect'] ?? ''));
        if ($mk !== '' && $ak !== '' && in_array($ef, ['grant', 'revoke'], true)) {
            $selectedOverrides[$mk][$ak] = $ef;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Module Access</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
    <?php include 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>
            <div class="container-fluid py-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h4 mb-0">Module Access Control</h1>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= ma_h((string)($flash['type'] ?? 'info')) ?>">
                        <?= ma_h((string)($flash['msg'] ?? '')) ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-body">
                        <form method="GET" class="form-inline">
                            <label for="uid" class="mr-2 font-weight-bold">Select User:</label>
                            <select id="uid" name="uid" class="form-control mr-2" style="min-width:320px;">
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= ma_h((string)$u['userid']) ?>" <?= ((string)$u['userid'] === $selectedUserId ? 'selected' : '') ?>>
                                        <?= ma_h((string)$u['username']) ?> (<?= ma_h((string)$u['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary btn-sm" type="submit">Load</button>
                        </form>
                    </div>
                </div>

                <?php if ($selectedUser): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <?= ma_h((string)$selectedUser['username']) ?> — Effective Profile: <?= ma_h($selectedProfile) ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_overrides">
                                <input type="hidden" name="target_user_id" value="<?= ma_h((string)$selectedUser['userid']) ?>">
                                <input type="hidden" name="target_username" value="<?= ma_h((string)$selectedUser['username']) ?>">

                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm">
                                        <thead class="thead-light">
                                        <tr>
                                            <th style="width:240px;">Module</th>
                                            <th>Action</th>
                                            <th style="width:300px;">Access State</th>
                                            <th style="width:220px;">Default</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($catalog as $moduleKey => $moduleMeta): ?>
                                            <?php $actions = (array)($moduleMeta['actions'] ?? []); ?>
                                            <?php foreach ($actions as $idx => $actionKey): ?>
                                                <?php
                                                    $defaultAllowed = false;
                                                    $profileDefaults = $defaults[$selectedProfile][$moduleKey] ?? [];
                                                    $profileDefaults = array_map('strtolower', (array)$profileDefaults);
                                                    if (in_array('*', $profileDefaults, true) || in_array(strtolower((string)$actionKey), $profileDefaults, true)) {
                                                        $defaultAllowed = true;
                                                    }
                                                    $state = $selectedOverrides[$moduleKey][$actionKey] ?? 'default';
                                                ?>
                                                <tr>
                                                    <?php if ($idx === 0): ?>
                                                        <td rowspan="<?= count($actions) ?>" class="align-middle font-weight-bold">
                                                            <?= ma_h((string)($moduleMeta['label'] ?? $moduleKey)) ?>
                                                        </td>
                                                    <?php endif; ?>
                                                    <td><code><?= ma_h((string)$actionKey) ?></code></td>
                                                    <td>
                                                        <select class="form-control form-control-sm" name="state[<?= ma_h($moduleKey) ?>][<?= ma_h((string)$actionKey) ?>]">
                                                            <option value="default" <?= $state === 'default' ? 'selected' : '' ?>>Default</option>
                                                            <option value="grant" <?= $state === 'grant' ? 'selected' : '' ?>>Grant</option>
                                                            <option value="revoke" <?= $state === 'revoke' ? 'selected' : '' ?>>Revoke</option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <?= $defaultAllowed ? '<span class="badge badge-success">Allowed</span>' : '<span class="badge badge-secondary">Not allowed</span>' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-3 d-flex" style="gap:8px;">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fas fa-save mr-1"></i> Save Overrides
                                    </button>
                                </div>
                            </form>

                            <form method="POST" class="mt-2" data-confirm="Reset all overrides for this user?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_overrides">
                                <input type="hidden" name="target_user_id" value="<?= ma_h((string)$selectedUser['userid']) ?>">
                                <input type="hidden" name="target_username" value="<?= ma_h((string)$selectedUser['username']) ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="fas fa-undo mr-1"></i> Reset To Defaults
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
