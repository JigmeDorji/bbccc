<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

function al_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$pdo = bbcc_audit_log_pdo();
if (!$pdo) {
    bbcc_fail_db();
}
bbcc_audit_ensure_table();

$username = trim((string)($_GET['username'] ?? ''));
$actionName = trim((string)($_GET['action_name'] ?? ''));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));

$where = [];
$params = [];

if ($username !== '') {
    $where[] = "LOWER(username) = LOWER(:username)";
    $params[':username'] = $username;
}
if ($actionName !== '') {
    $where[] = "action_name = :action_name";
    $params[':action_name'] = $actionName;
}
if ($fromDate !== '') {
    $where[] = "DATE(occurred_at) >= :from_date";
    $params[':from_date'] = $fromDate;
}
if ($toDate !== '') {
    $where[] = "DATE(occurred_at) <= :to_date";
    $params[':to_date'] = $toDate;
}

$sql = "
    SELECT id, occurred_at, user_id, username, role, ip_address, route, method, action_name, entity, status, details_json
    FROM audit_logs
";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY id DESC LIMIT 1000";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$actionOptions = $pdo->query("
    SELECT action_name, COUNT(*) AS c
    FROM audit_logs
    GROUP BY action_name
    ORDER BY c DESC, action_name ASC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Audit Logs</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
    <?php include 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>
            <div class="container-fluid py-3">
                <h1 class="h4 mb-3">Audit Logs</h1>

                <div class="card shadow mb-3">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Filters</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="form-row">
                            <div class="form-group col-md-3">
                                <label>Username</label>
                                <input type="text" name="username" class="form-control" value="<?= al_h($username) ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label>Action</label>
                                <select name="action_name" class="form-control">
                                    <option value="">All actions</option>
                                    <?php foreach ($actionOptions as $op): ?>
                                        <option value="<?= al_h((string)$op['action_name']) ?>" <?= $actionName === (string)$op['action_name'] ? 'selected' : '' ?>>
                                            <?= al_h((string)$op['action_name']) ?> (<?= (int)$op['c'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>From</label>
                                <input type="date" name="from_date" class="form-control" value="<?= al_h($fromDate) ?>">
                            </div>
                            <div class="form-group col-md-2">
                                <label>To</label>
                                <input type="date" name="to_date" class="form-control" value="<?= al_h($toDate) ?>">
                            </div>
                            <div class="form-group col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary btn-block">
                                    <i class="fas fa-filter mr-1"></i> Apply
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Events</h6>
                        <span class="badge badge-secondary"><?= count($rows) ?> row(s)</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sm" id="auditTable">
                                <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>When</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                    <th>Entity</th>
                                    <th>Method</th>
                                    <th>Route</th>
                                    <th>Status</th>
                                    <th>IP</th>
                                    <th>Details</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?= (int)$r['id'] ?></td>
                                        <td><?= al_h((string)$r['occurred_at']) ?></td>
                                        <td><?= al_h((string)($r['username'] ?: $r['user_id'])) ?></td>
                                        <td><?= al_h((string)$r['role']) ?></td>
                                        <td><code><?= al_h((string)$r['action_name']) ?></code></td>
                                        <td><?= al_h((string)$r['entity']) ?></td>
                                        <td><?= al_h((string)$r['method']) ?></td>
                                        <td><?= al_h((string)$r['route']) ?></td>
                                        <td><?= al_h((string)$r['status']) ?></td>
                                        <td><?= al_h((string)$r['ip_address']) ?></td>
                                        <td style="max-width:340px;white-space:pre-wrap;word-break:break-word;"><?= al_h((string)$r['details_json']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
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
<script src="vendor/datatables/jquery.dataTables.min.js"></script>
<script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
<script>
$(function () {
    $('#auditTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']]
    });
});
</script>
</body>
</html>

