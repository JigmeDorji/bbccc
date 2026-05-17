<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/migrate.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$message = '';
$messageType = 'info';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'run_pending') {
        bbcc_run_migrations();
        $message = 'Pending migrations executed. Refresh status below.';
        $messageType = 'success';
    }
}

$migrationDir = __DIR__ . '/migrations';
$allFiles = [];
if (is_dir($migrationDir)) {
    $paths = glob($migrationDir . '/*.sql');
    if (is_array($paths)) {
        sort($paths);
        foreach ($paths as $p) {
            $allFiles[] = basename((string)$p);
        }
    }
}

$hasTracking = false;
$appliedMap = [];
$appliedRows = [];
try {
    $check = $pdo->query("SHOW TABLES LIKE 'db_migrations'");
    $hasTracking = (bool)$check->fetch(PDO::FETCH_NUM);
    if ($hasTracking) {
        $rows = $pdo->query("SELECT migration, applied_at FROM db_migrations ORDER BY applied_at DESC, id DESC")->fetchAll();
        foreach ($rows as $r) {
            $name = (string)($r['migration'] ?? '');
            if ($name !== '') {
                $appliedMap[$name] = (string)($r['applied_at'] ?? '');
                $appliedRows[] = [
                    'migration' => $name,
                    'applied_at' => (string)($r['applied_at'] ?? ''),
                ];
            }
        }
    }
} catch (Throwable $e) {
    $message = 'Could not read migration tracking table: ' . $e->getMessage();
    $messageType = 'danger';
}

$pendingFiles = [];
foreach ($allFiles as $f) {
    if (!isset($appliedMap[$f])) {
        $pendingFiles[] = $f;
    }
}

function m_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Run Migrations</title>
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
                    <h1 class="h4 mb-0">Run Migrations</h1>
                    <span class="badge badge-light"><?= count($pendingFiles) ?> pending</span>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-<?= m_h($messageType) ?>"><?= m_h($message) ?></div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Execute Pending SQL Migrations</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-3 text-muted">
                            This runs SQL files in <code>/migrations</code> that are not yet recorded in <code>db_migrations</code>.
                        </p>
                        <form method="POST" data-confirm="Run pending migrations now?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="run_pending">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-play mr-1"></i> Run Pending Migrations
                            </button>
                        </form>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Pending</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($pendingFiles)): ?>
                                    <div class="text-success"><i class="fas fa-check-circle mr-1"></i> No pending migrations.</div>
                                <?php else: ?>
                                    <ul class="mb-0">
                                        <?php foreach ($pendingFiles as $f): ?>
                                            <li><code><?= m_h($f) ?></code></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Applied</h6>
                            </div>
                            <div class="card-body">
                                <?php if (!$hasTracking): ?>
                                    <div class="text-muted">Tracking table not created yet. Run migrations once.</div>
                                <?php elseif (empty($appliedRows)): ?>
                                    <div class="text-muted">No migrations recorded yet.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0">
                                            <thead>
                                            <tr><th>Migration</th><th>Applied At</th></tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach (array_slice($appliedRows, 0, 25) as $r): ?>
                                                <tr>
                                                    <td><code><?= m_h((string)$r['migration']) ?></code></td>
                                                    <td><?= m_h((string)$r['applied_at']) ?></td>
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
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
