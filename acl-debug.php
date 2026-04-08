<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$capabilities = ['authenticated', 'admin', 'teacher', 'parent', 'patron'];
$rules = bbcc_acl_page_rules();
ksort($rules, SORT_NATURAL | SORT_FLAG_CASE);

$capState = [];
foreach ($capabilities as $cap) {
    $capState[$cap] = bbcc_acl_has_capability($cap);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>ACL Debug</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .acl-chip {
            display: inline-block;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: .75rem;
            font-weight: 600;
            margin-right: 6px;
            margin-bottom: 6px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #374151;
        }
        .acl-chip.ok {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #166534;
        }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include "include/admin-nav.php"; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include "include/admin-header.php"; ?>
            <div class="container-fluid py-3">
                <h1 class="h3 mb-3 text-gray-800">ACL Debug</h1>

                <div class="card shadow mb-3">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Current Session</h6>
                    </div>
                    <div class="card-body">
                        <div><strong>User:</strong> <?= h((string)($_SESSION['username'] ?? '')) ?></div>
                        <div><strong>Role:</strong> <?= h((string)($_SESSION['role'] ?? '')) ?></div>
                        <div class="mt-2">
                            <?php foreach ($capabilities as $cap): ?>
                                <span class="acl-chip <?= $capState[$cap] ? 'ok' : '' ?>">
                                    <?= h($cap) ?>: <?= $capState[$cap] ? 'YES' : 'NO' ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Route Permission Matrix</h6>
                        <span class="badge badge-secondary"><?= (int)count($rules) ?> route(s)</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sm">
                                <thead class="thead-light">
                                <tr>
                                    <th style="width:60px;">#</th>
                                    <th>Route Key</th>
                                    <th>Allowed Capabilities</th>
                                    <th style="width:120px;">Current Access</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $i = 1; foreach ($rules as $route => $allowed): ?>
                                    <?php
                                        $canAccess = false;
                                        foreach ((array)$allowed as $cap) {
                                            if (bbcc_acl_has_capability((string)$cap)) {
                                                $canAccess = true;
                                                break;
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><?= $i++ ?></td>
                                        <td><code><?= h((string)$route) ?></code></td>
                                        <td>
                                            <?php foreach ((array)$allowed as $cap): ?>
                                                <span class="acl-chip"><?= h((string)$cap) ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <?php if ($canAccess): ?>
                                                <span class="badge badge-success">Allowed</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Denied</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include "include/admin-footer.php"; ?>
    </div>
</div>
</body>
</html>

