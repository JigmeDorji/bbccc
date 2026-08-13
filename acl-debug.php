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

// ── Unmapped-page scan ──────────────────────────────────────────
// A route missing from bbcc_acl_page_rules() isn't blocked — it just
// falls back to "any logged-in user" (see bbcc_acl_enforce_current_page()).
// This scans every root-level .php file that calls require_login() and
// flags any that the centralized ACL doesn't know about, so a forgotten
// page doesn't silently sit open.
$moduleRules = bbcc_acl_route_module_rules();
$unmappedPages = [];
$mappedNoModule = [];
foreach (glob(__DIR__ . '/*.php') as $file) {
    $base = basename($file, '.php');
    $routeKey = strtolower($base);
    $source = (string)file_get_contents($file);
    if (strpos($source, 'require_login()') === false) {
        continue; // Public page, not gated by login at all.
    }
    if (!isset($rules[$routeKey])) {
        $hasManualCheck = (bool)preg_match('/is_admin_role\(\)|is_website_admin_role\(\)|is_teacher_role\(\)|is_parent_role\(\)/', $source);
        $unmappedPages[] = ['file' => $base . '.php', 'route' => $routeKey, 'manual_check' => $hasManualCheck];
    } elseif (!isset($moduleRules[$routeKey])) {
        $mappedNoModule[] = ['file' => $base . '.php', 'route' => $routeKey];
    }
}
usort($unmappedPages, fn($a, $b) => strcasecmp($a['file'], $b['file']));
usort($mappedNoModule, fn($a, $b) => strcasecmp($a['file'], $b['file']));
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

                <div class="card shadow mb-3">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Unmapped Pages</h6>
                        <span class="badge <?= $unmappedPages ? 'badge-danger' : 'badge-success' ?>"><?= (int)count($unmappedPages) ?> found</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Every page below calls <code>require_login()</code> but has no entry in
                            <code>bbcc_acl_page_rules()</code>. Unmapped routes aren't blocked by the
                            centralized ACL — they're reachable by <strong>any logged-in user</strong>
                            regardless of role, unless the page also has its own manual role check.
                            Add an entry to <code>include/acl.php</code> to close each one.
                        </p>
                        <?php if (empty($unmappedPages)): ?>
                            <div class="text-success"><i class="fas fa-check-circle mr-1"></i> None — every gated page is registered in the ACL.</div>
                        <?php else: ?>
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="thead-light"><tr><th>File</th><th>Route Key</th><th>Backstop</th></tr></thead>
                                <tbody>
                                <?php foreach ($unmappedPages as $p): ?>
                                    <tr>
                                        <td><code><?= h($p['file']) ?></code></td>
                                        <td><code><?= h($p['route']) ?></code></td>
                                        <td>
                                            <?php if ($p['manual_check']): ?>
                                                <span class="badge badge-warning">Has its own role check</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">None — open to any logged-in user</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($mappedNoModule)): ?>
                <div class="card shadow mb-3">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Pages Without a Module/Action Rule</h6>
                        <span class="badge badge-secondary"><?= (int)count($mappedNoModule) ?> found</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            These are registered in <code>bbcc_acl_page_rules()</code> (so role access is enforced),
                            but have no matching entry in <code>bbcc_acl_route_module_rules()</code> — they skip the
                            finer-grained module/action layer, so per-user grant/revoke overrides in Module Access
                            won't apply to them. Not necessarily a bug, but worth a deliberate decision.
                        </p>
                        <ul class="mb-0">
                            <?php foreach ($mappedNoModule as $p): ?>
                                <li><code><?= h($p['file']) ?></code> (route: <code><?= h($p['route']) ?></code>)</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

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

