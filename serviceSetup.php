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
        $stmt = $pdo->prepare("DELETE FROM menu WHERE id = :id");
        $stmt->execute([':id' => (int)$_GET['delete']]);
        $message = "Event deleted successfully.";
        $reloadPage = true;
    }

    // INSERT / UPDATE
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $menuName  = trim($_POST['menuName'] ?? '');
        $menuDetail = trim($_POST['menuDetail'] ?? '');
        $price     = trim($_POST['price'] ?? '');
        $eventStartDateTime = $_POST['eventStartDateTime'] ?? '';
        $edit_id   = $_POST['edit_id'] ?? '';

        // Get existing image for edit
        $existing_img = '';
        if ($edit_id !== '') {
            $stmtOld = $pdo->prepare("SELECT menuImgUrl FROM menu WHERE id = :id");
            $stmtOld->execute([':id' => (int)$edit_id]);
            $existing_img = $stmtOld->fetchColumn() ?: '';
        }

        // Image handling
        if (isset($_FILES['menu_image']) && $_FILES['menu_image']['error'] === 0) {
            $image_name = $_FILES['menu_image']['name'];
            $image_tmp  = $_FILES['menu_image']['tmp_name'];
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $image_name);
            $upload_path = "uploads/menu/" . $safeName;
            move_uploaded_file($image_tmp, $upload_path);
            $menuImgUrl = $upload_path;
        } else {
            $menuImgUrl = $existing_img;
        }

        if ($edit_id !== '') {
            $stmt = $pdo->prepare("UPDATE menu SET menuName=:n, menuDetail=:d, menuImgUrl=:i, price=:p, eventStartDateTime=:e WHERE id=:id");
            $stmt->execute([':n'=>$menuName, ':d'=>$menuDetail, ':i'=>$menuImgUrl, ':p'=>$price, ':e'=>$eventStartDateTime, ':id'=>(int)$edit_id]);
            $message = "Event updated successfully.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO menu (menuName, menuDetail, menuImgUrl, price, eventStartDateTime) VALUES (:n,:d,:i,:p,:e)");
            $stmt->execute([':n'=>$menuName, ':d'=>$menuDetail, ':i'=>$menuImgUrl, ':p'=>$price, ':e'=>$eventStartDateTime]);
            $message = "Event created successfully.";
        }
        $reloadPage = true;
    }

    // Fetch all
    $menus = $pdo->query("SELECT * FROM menu ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = "error";
    $menus = $menus ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Event Setup</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .setup-modal .modal-dialog { max-width: 660px; }
        .setup-modal .modal-content { border:none; border-radius:12px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.15); }
        .setup-modal .modal-header { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); color:#fff; border-bottom:none; padding:1.25rem 1.5rem; }
        .setup-modal .modal-header .modal-title { font-weight:700; font-size:1.1rem; display:flex; align-items:center; gap:8px; }
        .setup-modal .modal-header .btn-close-modal { color:#fff; opacity:.85; font-size:1.4rem; background:none; border:none; cursor:pointer; transition:opacity .2s; }
        .setup-modal .modal-header .btn-close-modal:hover { opacity:1; }
        .setup-modal .modal-body { padding:1.75rem 1.5rem 1rem; background:#f8f9fc; }
        .setup-modal .modal-body .form-group { margin-bottom:1rem; }
        .setup-modal .modal-body label { font-weight:600; font-size:.82rem; text-transform:uppercase; letter-spacing:.4px; color:#5a5c69; margin-bottom:.3rem; }
        .setup-modal .modal-body .form-control { border-radius:8px; border:1px solid #d1d3e2; padding:.55rem .85rem; font-size:.9rem; transition:border-color .2s,box-shadow .2s; }
        .setup-modal .modal-body .form-control:focus { border-color:#4e73df; box-shadow:0 0 0 3px rgba(78,115,223,.15); }
        .setup-modal .modal-body textarea.form-control { resize:vertical; min-height:80px; }
        .setup-modal .modal-body .section-divider { font-size:.75rem; text-transform:uppercase; letter-spacing:1px; font-weight:700; color:#b7b9cc; margin:.75rem 0 .5rem; padding-bottom:.35rem; border-bottom:1px solid #e3e6f0; }
        .setup-modal .modal-footer { background:#fff; border-top:1px solid #e3e6f0; padding:1rem 1.5rem; }
        .setup-modal .modal-footer .btn { border-radius:8px; padding:.5rem 1.5rem; font-weight:600; }
        .setup-modal .btn-save { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; }
        .setup-modal .btn-save:hover { background:linear-gradient(135deg,#224abe 0%,#1a339a 100%); }
        .setup-modal .btn-save-update { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; }
        .setup-modal .btn-save-update:hover { background:linear-gradient(135deg,#224abe 0%,#1a339a 100%); }
        .setup-modal .btn-cancel-modal { background:#e3e6f0; color:#5a5c69; border:none; }
        .setup-modal .btn-cancel-modal:hover { background:#d1d3e2; }
        .setup-modal .img-preview { max-width:160px; border-radius:8px; border:2px solid #e3e6f0; }
        .btn-new-item { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; border-radius:8px; font-weight:600; padding:.45rem 1.1rem; transition:transform .15s,box-shadow .2s; }
        .btn-new-item:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(78,115,223,.35); color:#fff; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Event Setup</h1>
    </div>

    <!-- TABLE -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Event List</h6>
            <button type="button" class="btn btn-sm btn-new-item" id="btnNewEvent" aria-label="Create a new event">
                <i class="fas fa-plus-circle mr-1" aria-hidden="true"></i> New Event
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" width="100%">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Event Name</th><th>Detail</th><th>Image</th><th>Start Date & Time</th><th style="min-width:120px">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($menus as $i => $m): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= htmlspecialchars($m['menuName']) ?></td>
                            <td><?= htmlspecialchars(mb_strimwidth($m['menuDetail'] ?? '', 0, 60, '...')) ?></td>
                            <td>
                                <?php if (!empty($m['menuImgUrl'])): ?>
                                    <img src="<?= htmlspecialchars($m['menuImgUrl']) ?>" style="max-width:80px;border-radius:6px;">
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($m['eventStartDateTime'] ?? '—') ?></td>
                            <td>
                                <button type="button" class="btn btn-primary btn-sm edit-event-btn"
                                    data-id="<?= $m['id'] ?>"
                                    data-name="<?= htmlspecialchars($m['menuName']) ?>"
                                    data-detail="<?= htmlspecialchars($m['menuDetail'] ?? '') ?>"
                                    data-img="<?= htmlspecialchars($m['menuImgUrl'] ?? '') ?>"
                                    data-price="<?= htmlspecialchars($m['price'] ?? '') ?>"
                                    data-datetime="<?= htmlspecialchars($m['eventStartDateTime'] ?? '') ?>"
                                    title="Edit event" aria-label="Edit event <?= htmlspecialchars($m['menuName']) ?>"><i class="fas fa-edit" aria-hidden="true"></i></button>
                                <a href="#" class="btn btn-danger btn-sm delete-event-btn" data-id="<?= $m['id'] ?>" title="Delete event" aria-label="Delete event <?= htmlspecialchars($m['menuName']) ?>"><i class="fas fa-trash" aria-hidden="true"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- EVENT MODAL -->
<div class="modal fade setup-modal" id="eventModal" tabindex="-1" role="dialog" aria-labelledby="eModalTitleLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eModalTitleLabel"><i class="fas fa-calendar-plus" id="eModalIcon" aria-hidden="true"></i> <span id="eModalTitle">Add New Event</span></h5>
                <button type="button" class="btn-close-modal" data-dismiss="modal" aria-label="Close dialog"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <form method="POST" action="serviceSetup.php" enctype="multipart/form-data" id="eventForm">
                <div class="modal-body">
                    <input type="hidden" name="edit_id" id="e_edit_id" value="">

                    <div class="section-divider"><i class="fas fa-info-circle mr-1"></i> Event Details</div>
                    <div class="form-group">
                        <label>Event Name <span class="text-danger">*</span></label>
                        <input type="text" name="menuName" id="e_name" class="form-control" required placeholder="Enter event name">
                    </div>
                    <div class="form-group">
                        <label>Event Detail</label>
                        <textarea name="menuDetail" id="e_detail" class="form-control" rows="4" placeholder="Describe the event..."></textarea>
                    </div>

                    <div class="section-divider"><i class="fas fa-clock mr-1"></i> Schedule</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Start Date & Time</label>
                                <input type="datetime-local" name="eventStartDateTime" id="e_datetime" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Price</label>
                                <input type="text" name="price" id="e_price" class="form-control" placeholder="e.g. 25.00">
                            </div>
                        </div>
                    </div>

                    <div class="section-divider"><i class="fas fa-camera mr-1"></i> Event Image</div>
                    <div class="form-group" id="e_img_preview_wrap" style="display:none;">
                        <label>Current Image</label><br>
                        <img src="" id="e_img_preview" class="img-preview mb-2">
                    </div>
                    <div class="form-group">
                        <label>Upload Image</label>
                        <input type="file" name="menu_image" class="form-control" accept="image/*">
                        <small class="text-muted">Optional event image.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel-modal" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
                    <button type="submit" class="btn" id="eSubmitBtn"><i class="fas fa-save mr-1"></i> <span id="eSubmitText">Create Event</span></button>
                </div>
            </form>
        </div>
    </div>
</div>

</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
<script>
$(document).ready(function(){
    // NEW
    $('#btnNewEvent').on('click', function(){
        $('#e_edit_id').val('');
        $('#e_name').val('');
        $('#e_detail').val('');
        $('#e_datetime').val('');
        $('#e_price').val('');
        $('#e_img_preview_wrap').hide();
        $('#eModalTitle').text('Add New Event');
        $('#eModalIcon').attr('class','fas fa-calendar-plus');
        $('#eSubmitBtn').removeClass('btn-save-update').addClass('btn-save');
        $('#eSubmitText').text('Create Event');
        $('#eventModal').modal('show');
    });

    // EDIT
    $(document).on('click', '.edit-event-btn', function(){
        var btn = $(this);
        $('#e_edit_id').val(btn.data('id'));
        $('#e_name').val(btn.data('name'));
        $('#e_detail').val(btn.data('detail'));
        $('#e_datetime').val(btn.data('datetime'));
        $('#e_price').val(btn.data('price'));
        if (btn.data('img')) {
            $('#e_img_preview').attr('src', btn.data('img'));
            $('#e_img_preview_wrap').show();
        } else {
            $('#e_img_preview_wrap').hide();
        }
        $('#eModalTitle').text('Edit Event #' + btn.data('id'));
        $('#eModalIcon').attr('class','fas fa-edit');
        $('#eSubmitBtn').removeClass('btn-save').addClass('btn-save-update');
        $('#eSubmitText').text('Update Event');
        $('#eventModal').modal('show');
    });

    // DELETE
    $(document).on('click', '.delete-event-btn', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        Swal.fire({ title:'Delete this event?', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, delete' })
        .then(r => { if(r.isConfirmed) window.location.href='serviceSetup.php?delete='+id; });
    });

    // Loading
    $('#eventForm').on('submit', function(){
        $('#eSubmitBtn').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    });
});

<?php if ($message): ?>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 })
.then(() => { <?php if ($reloadPage): ?>window.location.href='serviceSetup.php';<?php endif; ?> });
<?php endif; ?>
</script>
</body>
</html>
