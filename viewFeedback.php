<?php
require_once "include/config.php";

$message = "";
$msgType = "success";
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // DELETE
    if (isset($_GET['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM contact WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$_GET['delete']]);
        $message = "Message deleted successfully.";
        $reloadPage = true;
    }

    // Fetch contacts
    $contacts = $pdo->query("SELECT * FROM contact ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = "error";
    $contacts = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Contact Messages</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .setup-modal .modal-dialog { max-width: 580px; }
        .setup-modal .modal-content { border:none; border-radius:12px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.15); }
        .setup-modal .modal-header { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); color:#fff; border-bottom:none; padding:1.25rem 1.5rem; }
        .setup-modal .modal-header .modal-title { font-weight:700; font-size:1.1rem; display:flex; align-items:center; gap:8px; }
        .setup-modal .modal-header .btn-close-modal { color:#fff; opacity:.85; font-size:1.4rem; background:none; border:none; cursor:pointer; transition:opacity .2s; }
        .setup-modal .modal-header .btn-close-modal:hover { opacity:1; }
        .setup-modal .modal-body { padding:1.75rem 1.5rem 1rem; background:#f8f9fc; }
        .setup-modal .modal-footer { background:#fff; border-top:1px solid #e3e6f0; padding:1rem 1.5rem; }
        .setup-modal .modal-footer .btn { border-radius:8px; padding:.5rem 1.5rem; font-weight:600; }
        .setup-modal .btn-cancel-modal { background:#e3e6f0; color:#5a5c69; border:none; }
        .setup-modal .btn-cancel-modal:hover { background:#d1d3e2; }
        .msg-detail-label { font-size:.75rem; text-transform:uppercase; letter-spacing:.5px; font-weight:700; color:#b7b9cc; margin-bottom:.15rem; }
        .msg-detail-value { font-size:.95rem; color:#3a3b45; margin-bottom:1rem; padding:.6rem .85rem; background:#fff; border-radius:8px; border:1px solid #e3e6f0; word-wrap:break-word; }
        .msg-detail-value.msg-body { white-space:pre-wrap; min-height:80px; line-height:1.7; }
        .view-msg-btn { cursor:pointer; }
        .view-msg-btn:hover { color:#4e73df; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
<?php include_once 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include_once 'include/admin-header.php'; ?>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Contact Messages</h1>
        <span class="badge badge-primary p-2" style="font-size:.85rem;">
            <i class="fas fa-envelope mr-1"></i> <?= count($contacts) ?> message<?= count($contacts) !== 1 ? 's' : '' ?>
        </span>
    </div>

    <!-- CONTACTS TABLE -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-inbox mr-1"></i> Messages</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" width="100%">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Name</th><th>Email</th><th>Subject</th><th>Message</th><th style="min-width:120px">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contacts as $i => $c): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><a href="mailto:<?= htmlspecialchars($c['email']) ?>"><?= htmlspecialchars($c['email']) ?></a></td>
                            <td><?= htmlspecialchars($c['subject'] ?? '—') ?></td>
                            <td class="view-msg-btn"
                                data-name="<?= htmlspecialchars($c['name']) ?>"
                                data-email="<?= htmlspecialchars($c['email']) ?>"
                                data-subject="<?= htmlspecialchars($c['subject'] ?? '') ?>"
                                data-message="<?= htmlspecialchars($c['message'] ?? '') ?>"
                                title="Click to view full message">
                                <?= htmlspecialchars(mb_strimwidth($c['message'] ?? '', 0, 50, '...')) ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-primary btn-sm view-msg-detail"
                                    data-name="<?= htmlspecialchars($c['name']) ?>"
                                    data-email="<?= htmlspecialchars($c['email']) ?>"
                                    data-subject="<?= htmlspecialchars($c['subject'] ?? '') ?>"
                                    data-message="<?= htmlspecialchars($c['message'] ?? '') ?>"
                                    title="View message" aria-label="View message from <?= htmlspecialchars($c['name']) ?>"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                <a href="#" class="btn btn-danger btn-sm delete-msg-btn" data-id="<?= $c['id'] ?>" title="Delete message" aria-label="Delete message from <?= htmlspecialchars($c['name']) ?>"><i class="fas fa-trash" aria-hidden="true"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- VIEW MESSAGE MODAL -->
<div class="modal fade setup-modal" id="viewMsgModal" tabindex="-1" role="dialog" aria-labelledby="viewMsgModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewMsgModalLabel"><i class="fas fa-envelope-open-text" aria-hidden="true"></i> Message Details</h5>
                <button type="button" class="btn-close-modal" data-dismiss="modal" aria-label="Close dialog"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body">
                <div class="msg-detail-label"><i class="fas fa-user mr-1"></i> From</div>
                <div class="msg-detail-value" id="vm_name"></div>

                <div class="msg-detail-label"><i class="fas fa-envelope mr-1"></i> Email</div>
                <div class="msg-detail-value" id="vm_email"></div>

                <div class="msg-detail-label"><i class="fas fa-tag mr-1"></i> Subject</div>
                <div class="msg-detail-value" id="vm_subject"></div>

                <div class="msg-detail-label"><i class="fas fa-comment-alt mr-1"></i> Message</div>
                <div class="msg-detail-value msg-body" id="vm_message"></div>
            </div>
            <div class="modal-footer">
                <a href="#" class="btn btn-primary" id="vm_reply_btn" target="_blank"><i class="fas fa-reply mr-1"></i> Reply via Email</a>
                <button type="button" class="btn btn-cancel-modal" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Close</button>
            </div>
        </div>
    </div>
</div>

</div>
<?php include_once 'include/admin-footer.php'; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
<script>
$(document).ready(function(){
    // VIEW
    function openViewModal(name, email, subject, message) {
        $('#vm_name').text(name);
        $('#vm_email').text(email);
        $('#vm_subject').text(subject || '(No subject)');
        $('#vm_message').text(message || '(No message)');
        $('#vm_reply_btn').attr('href', 'mailto:' + encodeURIComponent(email) + '?subject=Re: ' + encodeURIComponent(subject));
        $('#viewMsgModal').modal('show');
    }

    $(document).on('click', '.view-msg-detail, .view-msg-btn', function(){
        var el = $(this);
        openViewModal(el.data('name'), el.data('email'), el.data('subject'), el.data('message'));
    });

    // DELETE
    $(document).on('click', '.delete-msg-btn', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        Swal.fire({ title:'Delete this message?', text:'This action cannot be undone.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, delete' })
        .then(r => { if(r.isConfirmed) window.location.href='viewFeedback.php?delete='+id; });
    });
});

<?php if ($message): ?>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 })
.then(() => { <?php if ($reloadPage): ?>window.location.href='viewFeedback.php';<?php endif; ?> });
<?php endif; ?>
</script>
</body>
</html>
