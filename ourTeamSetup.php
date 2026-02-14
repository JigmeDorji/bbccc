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
        $stmt = $pdo->prepare("DELETE FROM ourteam WHERE id = :id");
        $stmt->execute([':id' => (int)$_GET['delete']]);
        $message = "Team member deleted successfully.";
        $reloadPage = true;
    }

    // INSERT / UPDATE
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $name        = trim($_POST['Name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $edit_id     = $_POST['edit_id'] ?? '';

        // Existing image for edit
        $existing_img = '';
        if ($edit_id !== '') {
            $stmtOld = $pdo->prepare("SELECT imgUrl FROM ourteam WHERE id = :id");
            $stmtOld->execute([':id' => (int)$edit_id]);
            $existing_img = $stmtOld->fetchColumn() ?: '';
        }

        // Image handling
        if (isset($_FILES['team_image']) && $_FILES['team_image']['error'] === 0) {
            $image_name = $_FILES['team_image']['name'];
            $image_size = $_FILES['team_image']['size'];
            $image_tmp  = $_FILES['team_image']['tmp_name'];
            if ($image_size > 5242880) throw new Exception("File too large. Max 5MB.");
            $allowed = ['jpg','jpeg','png','gif'];
            $ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) throw new Exception("Only JPG, JPEG, PNG, GIF allowed.");
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $image_name);
            $upload_path = "uploads/ourteam/" . $safeName;
            if (!move_uploaded_file($image_tmp, $upload_path)) throw new Exception("Failed to upload image.");
            $imgUrl = $upload_path;
        } else {
            $imgUrl = $existing_img;
        }

        if ($edit_id !== '') {
            $stmt = $pdo->prepare("UPDATE ourteam SET Name=:n, designation=:d, imgUrl=:i WHERE id=:id");
            $stmt->execute([':n'=>$name, ':d'=>$designation, ':i'=>$imgUrl, ':id'=>(int)$edit_id]);
            $message = "Team member updated successfully.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO ourteam (Name, designation, imgUrl) VALUES (:n,:d,:i)");
            $stmt->execute([':n'=>$name, ':d'=>$designation, ':i'=>$imgUrl]);
            $message = "Team member added successfully.";
        }
        $reloadPage = true;
    }

    // Fetch all
    $teams = $pdo->query("SELECT * FROM ourteam ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = "error";
    $teams = $teams ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Team Setup</title>
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
        .setup-modal .modal-body .form-group { margin-bottom:1rem; }
        .setup-modal .modal-body label { font-weight:600; font-size:.82rem; text-transform:uppercase; letter-spacing:.4px; color:#5a5c69; margin-bottom:.3rem; }
        .setup-modal .modal-body .form-control { border-radius:8px; border:1px solid #d1d3e2; padding:.55rem .85rem; font-size:.9rem; transition:border-color .2s,box-shadow .2s; }
        .setup-modal .modal-body .form-control:focus { border-color:#4e73df; box-shadow:0 0 0 3px rgba(78,115,223,.15); }
        .setup-modal .modal-body textarea.form-control { resize:vertical; min-height:60px; }
        .setup-modal .modal-body .section-divider { font-size:.75rem; text-transform:uppercase; letter-spacing:1px; font-weight:700; color:#b7b9cc; margin:.75rem 0 .5rem; padding-bottom:.35rem; border-bottom:1px solid #e3e6f0; }
        .setup-modal .modal-footer { background:#fff; border-top:1px solid #e3e6f0; padding:1rem 1.5rem; }
        .setup-modal .modal-footer .btn { border-radius:8px; padding:.5rem 1.5rem; font-weight:600; }
        .setup-modal .btn-save { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; }
        .setup-modal .btn-save:hover { background:linear-gradient(135deg,#224abe 0%,#1a339a 100%); }
        .setup-modal .btn-save-update { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; }
        .setup-modal .btn-save-update:hover { background:linear-gradient(135deg,#224abe 0%,#1a339a 100%); }
        .setup-modal .btn-cancel-modal { background:#e3e6f0; color:#5a5c69; border:none; }
        .setup-modal .btn-cancel-modal:hover { background:#d1d3e2; }
        .setup-modal .img-preview { max-width:120px; border-radius:50%; border:3px solid #e3e6f0; }
        .btn-new-item { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; border-radius:8px; font-weight:600; padding:.45rem 1.1rem; transition:transform .15s,box-shadow .2s; }
        .btn-new-item:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(78,115,223,.35); color:#fff; }
        .team-avatar { width:50px; height:50px; border-radius:50%; object-fit:cover; border:2px solid #e3e6f0; }
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
        <h1 class="h3 mb-0 text-gray-800">Team Setup</h1>
    </div>

    <!-- TEAM TABLE -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Team Members</h6>
            <button type="button" class="btn btn-sm btn-new-item" id="btnNewTeam" aria-label="Add a new team member">
                <i class="fas fa-plus-circle mr-1" aria-hidden="true"></i> Add Member
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" width="100%">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Photo</th><th>Name</th><th>Designation</th><th style="min-width:120px">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teams as $i => $t): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td>
                                <?php if (!empty($t['imgUrl'])): ?>
                                    <img src="<?= htmlspecialchars($t['imgUrl']) ?>" class="team-avatar">
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($t['Name']) ?></td>
                            <td><?= htmlspecialchars($t['designation']) ?></td>
                            <td>
                                <button type="button" class="btn btn-primary btn-sm edit-team-btn"
                                    data-id="<?= $t['id'] ?>"
                                    data-name="<?= htmlspecialchars($t['Name']) ?>"
                                    data-designation="<?= htmlspecialchars($t['designation']) ?>"
                                    data-img="<?= htmlspecialchars($t['imgUrl'] ?? '') ?>"
                                    title="Edit member" aria-label="Edit team member <?= htmlspecialchars($t['Name']) ?>"><i class="fas fa-edit" aria-hidden="true"></i></button>
                                <a href="#" class="btn btn-danger btn-sm delete-team-btn" data-id="<?= $t['id'] ?>" title="Delete member" aria-label="Delete team member <?= htmlspecialchars($t['Name']) ?>"><i class="fas fa-trash" aria-hidden="true"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- TEAM MODAL -->
<div class="modal fade setup-modal" id="teamModal" tabindex="-1" role="dialog" aria-labelledby="tModalTitleLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tModalTitleLabel"><i class="fas fa-user-plus" id="tModalIcon" aria-hidden="true"></i> <span id="tModalTitle">Add Team Member</span></h5>
                <button type="button" class="btn-close-modal" data-dismiss="modal" aria-label="Close dialog"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <form method="POST" action="ourTeamSetup.php" enctype="multipart/form-data" id="teamForm">
                <div class="modal-body">
                    <input type="hidden" name="edit_id" id="t_edit_id" value="">

                    <div class="section-divider"><i class="fas fa-id-card mr-1"></i> Member Info</div>
                    <div class="form-group">
                        <label>Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="Name" id="t_name" class="form-control" required placeholder="Enter full name">
                    </div>
                    <div class="form-group">
                        <label>Designation / Role</label>
                        <textarea name="designation" id="t_designation" class="form-control" rows="2" placeholder="e.g. President, Committee Member"></textarea>
                    </div>

                    <div class="section-divider"><i class="fas fa-camera mr-1"></i> Profile Photo</div>
                    <div class="form-group" id="t_img_preview_wrap" style="display:none;">
                        <label>Current Photo</label><br>
                        <img src="" id="t_img_preview" class="img-preview mb-2">
                    </div>
                    <div class="form-group">
                        <label>Upload Photo</label>
                        <input type="file" name="team_image" class="form-control" accept="image/*">
                        <small class="text-muted">Max 5MB. JPG, PNG, GIF.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel-modal" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
                    <button type="submit" class="btn" id="tSubmitBtn"><i class="fas fa-save mr-1"></i> <span id="tSubmitText">Add Member</span></button>
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
    $('#btnNewTeam').on('click', function(){
        $('#t_edit_id').val('');
        $('#t_name').val('');
        $('#t_designation').val('');
        $('#t_img_preview_wrap').hide();
        $('#tModalTitle').text('Add Team Member');
        $('#tModalIcon').attr('class','fas fa-user-plus');
        $('#tSubmitBtn').removeClass('btn-save-update').addClass('btn-save');
        $('#tSubmitText').text('Add Member');
        $('#teamModal').modal('show');
    });

    // EDIT
    $(document).on('click', '.edit-team-btn', function(){
        var btn = $(this);
        $('#t_edit_id').val(btn.data('id'));
        $('#t_name').val(btn.data('name'));
        $('#t_designation').val(btn.data('designation'));
        if (btn.data('img')) {
            $('#t_img_preview').attr('src', btn.data('img'));
            $('#t_img_preview_wrap').show();
        } else {
            $('#t_img_preview_wrap').hide();
        }
        $('#tModalTitle').text('Edit Member #' + btn.data('id'));
        $('#tModalIcon').attr('class','fas fa-user-edit');
        $('#tSubmitBtn').removeClass('btn-save').addClass('btn-save-update');
        $('#tSubmitText').text('Update Member');
        $('#teamModal').modal('show');
    });

    // DELETE
    $(document).on('click', '.delete-team-btn', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        Swal.fire({ title:'Delete this member?', text:'This action cannot be undone.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, delete' })
        .then(r => { if(r.isConfirmed) window.location.href='ourTeamSetup.php?delete='+id; });
    });

    // Loading
    $('#teamForm').on('submit', function(){
        $('#tSubmitBtn').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    });
});

<?php if ($message): ?>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 })
.then(() => { <?php if ($reloadPage): ?>window.location.href='ourTeamSetup.php';<?php endif; ?> });
<?php endif; ?>
</script>
</body>
</html>
