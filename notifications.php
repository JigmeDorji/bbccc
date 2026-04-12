<?php
require_once "include/config.php";
require_once "include/auth.php";
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
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

$username = (string)($_SESSION['username'] ?? '');
$role = (string)($_SESSION['role'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'mark_read') {
        $id = (int)($_POST['notification_id'] ?? 0);
        if ($id > 0) {
            bbcc_mark_notification_read($pdo, $id, $username, $role);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['notification_id'] ?? 0);
        if ($id > 0) {
            bbcc_delete_notification($pdo, $id, $username, $role);
        }
    } elseif ($action === 'mark_all') {
        bbcc_mark_all_notifications_read($pdo, $username, $role);
    }

    header('Location: notifications');
    exit;
}

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
$rows = bbcc_fetch_notifications_for_user($pdo, $username, $role, 100);
$unreadCount = 0;
foreach ($rows as $r) {
    if ((int)($r['is_read'] ?? 0) === 0) {
        $unreadCount++;
    }
}
if ($filter === 'unread') {
    $rows = array_values(array_filter($rows, static function (array $r): bool {
        return (int)($r['is_read'] ?? 0) === 0;
    }));
}

function n_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Notifications</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .notif-card { border-radius: 12px; border: 1px solid #e6e6e6; }
        .notif-item { border: 1px solid #ececec; border-radius: 10px; padding: 14px; margin-bottom: 10px; }
        .notif-item.unread { background: #f8fbff; border-color: #d6e8ff; }
        .notif-toolbar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .notif-toolbar form { margin: 0; }
        @media (max-width: 576px) {
            .notif-toolbar { width: 100%; }
            .notif-toolbar .btn,
            .notif-toolbar form {
                width: 100%;
            }
        }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>

            <div class="container-fluid py-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                    <h1 class="h4 mb-0">Notifications</h1>
                    <div class="notif-toolbar mt-2 mt-sm-0">
                        <a href="notifications?filter=all" class="btn btn-sm <?= $filter === 'unread' ? 'btn-outline-secondary' : 'btn-primary' ?>">All</a>
                        <a href="notifications?filter=unread" class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-outline-secondary' ?>">Unread (<?= (int)$unreadCount ?>)</a>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_all">
                            <button type="submit" class="btn btn-sm btn-outline-dark">Mark All Read</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow notif-card">
                    <div class="card-body">
                        <?php if (empty($rows)): ?>
                            <p class="text-muted mb-0">No notifications found.</p>
                        <?php else: ?>
                            <?php foreach ($rows as $n): ?>
                                <?php $isUnread = ((int)($n['is_read'] ?? 0) === 0); ?>
                                <div class="notif-item <?= $isUnread ? 'unread' : '' ?>">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <div class="font-weight-bold"><?= n_h((string)($n['title'] ?? 'Notification')) ?></div>
                                            <?php if (!empty($n['body'])): ?>
                                                <div class="text-muted small mt-1"><?= n_h((string)$n['body']) ?></div>
                                            <?php endif; ?>
                                            <div class="small text-muted mt-1"><?= n_h((string)($n['created_at'] ?? '')) ?></div>
                                        </div>
                                        <div class="text-right">
                                            <?php if (!empty($n['link_url'])): ?>
                                                <a href="<?= n_h((string)$n['link_url']) ?>" class="btn btn-sm btn-outline-primary mb-1">Open</a><br>
                                            <?php endif; ?>
                                            <?php if ($isUnread): ?>
                                                <form method="POST" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="notification_id" value="<?= (int)($n['id'] ?? 0) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Mark Read</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge badge-light">Read</span>
                                            <?php endif; ?>
                                            <form method="POST" class="d-inline ml-1 js-delete-notification-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="notification_id" value="<?= (int)($n['id'] ?? 0) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-delete-notification-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Delete notification?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#e74a3b',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
});
</script>
</body>
</html>
