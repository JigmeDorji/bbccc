<?php
require_once "include/config.php";
require_once "access_control.php";

$message = "";
$msgType = "success";
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // DELETE
    if (isset($_GET['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM banner WHERE id = :id");
        $stmt->execute([':id' => (int)$_GET['delete']]);
        $message = "Banner deleted successfully.";
        $reloadPage = true;
    }

    // INSERT / UPDATE
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $title    = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $edit_id  = $_POST['edit_id'] ?? '';

        // Existing image for edit
        $existing_imgUrl = '';
        if ($edit_id !== '') {
            $stmtOld = $pdo->prepare("SELECT imgUrl FROM banner WHERE id = :id");
            $stmtOld->execute([':id' => (int)$edit_id]);
            $existing_imgUrl = $stmtOld->fetchColumn() ?: '';
        }

        // Image handling
        if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] === 0) {
            $image_name = $_FILES['banner_image']['name'];
            $image_size = $_FILES['banner_image']['size'];
            $image_tmp  = $_FILES['banner_image']['tmp_name'];
            if ($image_size > 5242880) throw new Exception("File too large. Max 5MB.");
            $allowed = ['jpg','jpeg','png','gif'];
            $ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) throw new Exception("Only JPG, JPEG, PNG, GIF allowed.");
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $image_name);
            $upload_path = "uploads/banner/" . $safeName;
            if (!move_uploaded_file($image_tmp, $upload_path)) throw new Exception("Failed to upload image.");
            $imgUrl = $upload_path;
        } else {
            $imgUrl = $existing_imgUrl;
        }

        if ($edit_id !== '') {
            $stmt = $pdo->prepare("UPDATE banner SET title = :title, subtitle = :subtitle, imgUrl = :imgUrl WHERE id = :id");
            $stmt->execute([':title' => $title, ':subtitle' => $subtitle, ':imgUrl' => $imgUrl, ':id' => (int)$edit_id]);
            $message = "Banner updated successfully.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO banner (title, subtitle, imgUrl) VALUES (:title, :subtitle, :imgUrl)");
            $stmt->execute([':title' => $title, ':subtitle' => $subtitle, ':imgUrl' => $imgUrl]);
            $message = "Banner created successfully.";
        }
        $reloadPage = true;
    }

    // Fetch all banners
    $banners = $pdo->query("SELECT * FROM banner ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = "error";
    $banners = $banners ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Banner Setup</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        /* ── Professional Modal Styles ── */
        .setup-modal .modal-dialog { max-width: 620px; }
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
        .setup-modal .img-preview { max-width:180px; border-radius:8px; border:2px solid #e3e6f0; }
        .setup-modal .custom-file-label { border-radius:8px; }
        .btn-new-item { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; border-radius:8px; font-weight:600; padding:.45rem 1.1rem; transition:transform .15s,box-shadow .2s; }
        .btn-new-item:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(78,115,223,.35); color:#fff; }
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
        <h1 class="h3 mb-0 text-gray-800">Banner Setup</h1>
    </div>

    <!-- BANNER TABLE -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Banner List</h6>
            <button type="button" class="btn btn-sm btn-new-item" id="btnNewBanner" aria-label="Add a new banner">
                <i class="fas fa-plus-circle mr-1" aria-hidden="true"></i> New Banner
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" width="100%">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Title</th><th>Subtitle</th><th>Image</th><th style="min-width:120px">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($banners as $i => $b): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= htmlspecialchars($b['title']) ?></td>
                            <td><?= htmlspecialchars(mb_strimwidth($b['subtitle'], 0, 60, '...')) ?></td>
                            <td>
                                <?php if (!empty($b['imgUrl'])): ?>
                                    <img src="<?= htmlspecialchars($b['imgUrl']) ?>" class="img-fluid" style="max-width:80px;border-radius:6px;">
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-primary btn-sm edit-banner-btn"
                                    data-id="<?= $b['id'] ?>"
                                    data-title="<?= htmlspecialchars($b['title']) ?>"
                                    data-subtitle="<?= htmlspecialchars($b['subtitle']) ?>"
                                    data-img="<?= htmlspecialchars($b['imgUrl'] ?? '') ?>"
                                    title="Edit banner" aria-label="Edit banner <?= htmlspecialchars($b['title']) ?>"><i class="fas fa-edit" aria-hidden="true"></i></button>
                                <a href="#" class="btn btn-danger btn-sm delete-banner-btn" data-id="<?= $b['id'] ?>" title="Delete banner" aria-label="Delete banner <?= htmlspecialchars($b['title']) ?>"><i class="fas fa-trash" aria-hidden="true"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- BANNER MODAL -->
<div class="modal fade setup-modal" id="bannerModal" tabindex="-1" role="dialog" aria-labelledby="bModalTitleLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bModalTitleLabel"><i class="fas fa-image" id="bModalIcon" aria-hidden="true"></i> <span id="bModalTitle">Add New Banner</span></h5>
                <button type="button" class="btn-close-modal" data-dismiss="modal" aria-label="Close dialog"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <form method="POST" action="bannerSetup.php" enctype="multipart/form-data" id="bannerForm">
                <div class="modal-body">
                    <input type="hidden" name="edit_id" id="b_edit_id" value="">

                    <div class="section-divider"><i class="fas fa-heading mr-1"></i> Banner Content</div>
                    <div class="form-group">
                        <label>Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="b_title" class="form-control" required placeholder="Banner title">
                    </div>
                    <div class="form-group">
                        <label>Subtitle</label>
                        <textarea name="subtitle" id="b_subtitle" class="form-control" rows="3" placeholder="Banner subtitle text..."></textarea>
                    </div>

                    <div class="section-divider"><i class="fas fa-camera mr-1"></i> Banner Image</div>
                    <div class="form-group" id="b_img_preview_wrap" style="display:none;">
                        <label>Current Image</label><br>
                        <img src="" id="b_img_preview" class="img-preview mb-2">
                    </div>
                    <div class="form-group">
                        <label>Upload Image</label>
                        <input type="file" name="banner_image" class="form-control" accept="image/*">
                        <small class="text-muted">Max 5MB. JPG, PNG, GIF.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel-modal" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
                    <button type="submit" class="btn" id="bSubmitBtn"><i class="fas fa-save mr-1"></i> <span id="bSubmitText">Create Banner</span></button>
                </div>
            </form>
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
    // NEW
    $('#btnNewBanner').on('click', function(){
        $('#b_edit_id').val('');
        $('#b_title').val('');
        $('#b_subtitle').val('');
        $('#b_img_preview_wrap').hide();
        $('#bModalTitle').text('Add New Banner');
        $('#bModalIcon').attr('class','fas fa-image');
        $('#bSubmitBtn').removeClass('btn-save-update').addClass('btn-save');
        $('#bSubmitText').text('Create Banner');
        $('#bannerModal').modal('show');
    });

    // EDIT
    $(document).on('click', '.edit-banner-btn', function(){
        var btn = $(this);
        $('#b_edit_id').val(btn.data('id'));
        $('#b_title').val(btn.data('title'));
        $('#b_subtitle').val(btn.data('subtitle'));
        if (btn.data('img')) {
            $('#b_img_preview').attr('src', btn.data('img'));
            $('#b_img_preview_wrap').show();
        } else {
            $('#b_img_preview_wrap').hide();
        }
        $('#bModalTitle').text('Edit Banner #' + btn.data('id'));
        $('#bModalIcon').attr('class','fas fa-edit');
        $('#bSubmitBtn').removeClass('btn-save').addClass('btn-save-update');
        $('#bSubmitText').text('Update Banner');
        $('#bannerModal').modal('show');
    });

    // DELETE
    $(document).on('click', '.delete-banner-btn', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        Swal.fire({ title:'Delete this banner?', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, delete' })
        .then(r => { if(r.isConfirmed) window.location.href='bannerSetup.php?delete='+id; });
    });

    // Loading
    $('#bannerForm').on('submit', function(){
        $('#bSubmitBtn').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    });
});

<?php if ($message): ?>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 })
.then(() => { <?php if ($reloadPage): ?>window.location.href='bannerSetup.php';<?php endif; ?> });
<?php endif; ?>
</script>
</body>
</html>
