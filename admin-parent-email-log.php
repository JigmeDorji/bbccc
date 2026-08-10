<?php
// admin-parent-email-log.php — Full history of parent emails sent by any
// admin/teacher via parent-email.php, not just the current user's own
// recent sends (parent-email.php's own panel is scoped to created_by).
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/pcm_helpers.php";
require_once "include/mail_queue.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

$pdo = pcm_pdo();
bbcc_mail_queue_ensure_table();

$rows = $pdo->query("
    SELECT mq.id, mq.to_email, mq.to_name, mq.subject, mq.status, mq.attempts, mq.max_attempts,
           mq.last_error, mq.created_at, mq.sent_at, mq.attachment_name, mq.created_by, mq.batch_id,
           COALESCE(NULLIF(ap.full_name, ''), NULLIF(t.full_name, ''), mq.created_by) AS sender_name
    FROM mail_queue mq
    LEFT JOIN admin_profiles ap ON ap.user_id = mq.created_by
    LEFT JOIN teachers t ON t.user_id COLLATE utf8mb4_general_ci = mq.created_by
    WHERE mq.source = 'parent-email'
    ORDER BY mq.id DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$counts = ['sent' => 0, 'queued' => 0, 'retry' => 0, 'failed' => 0];
$senders = [];
foreach ($rows as $r) {
    $status = strtolower((string)($r['status'] ?? 'queued'));
    if (isset($counts[$status])) $counts[$status]++;
    $createdBy = (string)($r['created_by'] ?? '');
    if ($createdBy !== '' && !isset($senders[$createdBy])) {
        $senders[$createdBy] = (string)($r['sender_name'] ?? $createdBy);
    }
}
asort($senders);

$pageScripts = [
    "https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js",
    "https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Parent Email History</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid py-3">

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0 text-gray-800">Parent Email History</h1>
    <a href="parent-email" class="btn btn-sm btn-outline-primary"><i class="fas fa-paper-plane mr-1"></i>Send Parent Email</a>
</div>

<!-- Summary -->
<div class="row mb-3">
    <div class="col-md-3 mb-3">
        <div class="card border-left-success shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-success text-uppercase mb-1">Sent</div><div class="h5 mb-0 font-weight-bold"><?= (int)$counts['sent'] ?></div></div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card border-left-warning shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Queued / Retrying</div><div class="h5 mb-0 font-weight-bold"><?= (int)($counts['queued'] + $counts['retry']) ?></div></div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card border-left-danger shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Failed</div><div class="h5 mb-0 font-weight-bold"><?= (int)$counts['failed'] ?></div></div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card border-left-primary shadow py-2"><div class="card-body"><div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total (last 500)</div><div class="h5 mb-0 font-weight-bold"><?= count($rows) ?></div></div></div>
    </div>
</div>

<!-- Filters -->
<div class="mb-3">
    <button class="btn btn-sm btn-primary filter-btn active" data-filter="all">All</button>
    <button class="btn btn-sm btn-outline-success filter-btn" data-filter="sent">Sent</button>
    <button class="btn btn-sm btn-outline-warning filter-btn" data-filter="queued">Queued</button>
    <button class="btn btn-sm btn-outline-warning filter-btn" data-filter="retry">Retrying</button>
    <button class="btn btn-sm btn-outline-danger filter-btn" data-filter="failed">Failed</button>
</div>
<div class="mb-3" style="max-width:280px;">
    <label class="small text-muted mb-1">Filter by Sender</label>
    <select id="senderFilter" class="form-control form-control-sm">
        <option value="">All Senders</option>
        <?php foreach ($senders as $userid => $name): ?>
            <option value="<?= h($name) ?>"><?= h($name) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="card shadow mb-4">
    <div class="card-body">
    <div class="table-responsive">
        <table id="emailLogTable" class="table table-bordered table-hover" style="width:100%">
            <thead class="thead-light">
                <tr><th>#</th><th>Sent By</th><th>Recipient</th><th>Subject</th><th>Attachment</th><th>Status</th><th>Attempts</th><th>Created</th><th>Sent At</th><th>Error</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <?php
                    $status = strtolower((string)($r['status'] ?? 'queued'));
                    $label = match ($status) {
                        'sent' => 'Sent',
                        'retry' => 'Retrying',
                        'failed' => 'Failed',
                        default => 'Queued',
                    };
                    $badge = match ($status) {
                        'sent' => 'success',
                        'retry' => 'warning',
                        'failed' => 'danger',
                        default => 'secondary',
                    };
                ?>
                <tr data-status="<?= h($status) ?>" data-sender="<?= h((string)$r['sender_name']) ?>">
                    <td><?= $i + 1 ?></td>
                    <td><?= h((string)$r['sender_name']) ?></td>
                    <td><?= h((string)($r['to_name'] ?: $r['to_email'])) ?><br><small class="text-muted"><?= h((string)$r['to_email']) ?></small></td>
                    <td><?= h((string)$r['subject']) ?></td>
                    <td><?= h((string)($r['attachment_name'] ?: '—')) ?></td>
                    <td><span class="badge badge-<?= $badge ?>"><?= $label ?></span></td>
                    <td><?= (int)$r['attempts'] ?>/<?= (int)$r['max_attempts'] ?></td>
                    <td class="nowrap"><?= $r['created_at'] ? date('d M Y, g:i A', strtotime($r['created_at'])) : '—' ?></td>
                    <td class="nowrap"><?= $r['sent_at'] ? date('d M Y, g:i A', strtotime($r['sent_at'])) : '—' ?></td>
                    <td class="text-danger small"><?= h((string)($r['last_error'] ?: '—')) ?></td>
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

<script>
$(function(){
    var dt = $('#emailLogTable').DataTable({pageLength:25, order:[[7,'desc']]});

    function applyFilters() {
        var status = $('.filter-btn.active').data('filter') || 'all';
        dt.column(5).search(status === 'all' ? '' : '^' + status + '\\b', true, false);
        var sender = $('#senderFilter').val() || '';
        dt.column(1).search(sender === '' ? '' : '^' + sender.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', true, false);
        dt.draw();
    }

    $('.filter-btn').on('click', function () {
        $('.filter-btn').removeClass('active'); $(this).addClass('active');
        applyFilters();
    });
    $('#senderFilter').on('change', applyFilters);
});
</script>
</body>
</html>
