<?php
require_once "include/config.php";
require_once "access_control.php";

$message = "";
$msgType = "success";
$existing_description = "";
$existing_imgUrl = "";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Fetch existing about data
    $stmt = $pdo->prepare("SELECT * FROM about ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $existing_description = $row['description'];
        $existing_imgUrl      = $row['imgUrl'];
    }

    // Form submission
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $description = $_POST['description'] ?? '';

        // Image handling
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $image_name = $_FILES['image']['name'];
            $image_size = $_FILES['image']['size'];
            $image_tmp  = $_FILES['image']['tmp_name'];
            if ($image_size > 5242880) throw new Exception("File too large. Max 5MB.");
            $allowed = ['jpg','jpeg','png','gif'];
            $ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) throw new Exception("Only JPG, JPEG, PNG, GIF allowed.");
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $image_name);
            $upload_path = "uploads/" . $safeName;
            if (!move_uploaded_file($image_tmp, $upload_path)) throw new Exception("Failed to upload image.");
            $imgUrl = $upload_path;
        } else {
            $imgUrl = $existing_imgUrl;
        }

        $stmt = $pdo->prepare("INSERT INTO about (description, imgUrl) VALUES (:description, :imgUrl)");
        $stmt->execute([':description' => $description, ':imgUrl' => $imgUrl]);

        $message = "About page updated successfully.";
        $existing_description = $description;
        $existing_imgUrl = $imgUrl;
    }

} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = "error";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>About Us Setup</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .setup-modal .modal-dialog { max-width: 680px; }
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
        .setup-modal .modal-body textarea.form-control { resize:vertical; min-height:120px; }
        .setup-modal .modal-body .section-divider { font-size:.75rem; text-transform:uppercase; letter-spacing:1px; font-weight:700; color:#b7b9cc; margin:.75rem 0 .5rem; padding-bottom:.35rem; border-bottom:1px solid #e3e6f0; }
        .setup-modal .modal-footer { background:#fff; border-top:1px solid #e3e6f0; padding:1rem 1.5rem; }
        .setup-modal .modal-footer .btn { border-radius:8px; padding:.5rem 1.5rem; font-weight:600; }
        .setup-modal .btn-save-update { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; }
        .setup-modal .btn-save-update:hover { background:linear-gradient(135deg,#224abe 0%,#1a339a 100%); }
        .setup-modal .btn-cancel-modal { background:#e3e6f0; color:#5a5c69; border:none; }
        .setup-modal .btn-cancel-modal:hover { background:#d1d3e2; }
        .setup-modal .img-preview { max-width:200px; border-radius:8px; border:2px solid #e3e6f0; }
        .about-preview-card { background:#fff; border-radius:12px; padding:2rem; box-shadow:0 2px 12px rgba(0,0,0,.06); }
        .about-preview-card img { max-width:100%; border-radius:10px; margin-bottom:1rem; }
        .about-preview-card .about-text { color:#5a5c69; line-height:1.8; white-space:pre-wrap; }
        .btn-edit-about { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; border-radius:8px; font-weight:600; padding:.5rem 1.3rem; transition:transform .15s,box-shadow .2s; }
        .btn-edit-about:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(78,115,223,.35); color:#fff; }
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
        <h1 class="h3 mb-0 text-gray-800">About Page Setup</h1>
        <button type="button" class="btn btn-edit-about" id="btnEditAbout" aria-label="Edit the about page content">
            <i class="fas fa-edit mr-1" aria-hidden="true"></i> Edit About Page
        </button>
    </div>

    <!-- Current About Preview -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-eye mr-1"></i> Current About Page Content</h6>
        </div>
        <div class="card-body">
            <div class="about-preview-card">
                <?php if ($existing_imgUrl): ?>
                    <img src="<?= htmlspecialchars($existing_imgUrl) ?>" alt="About Image">
                <?php endif; ?>
                <?php if ($existing_description): ?>
                    <div class="about-text"><?= htmlspecialchars($existing_description) ?></div>
                <?php else: ?>
                    <p class="text-muted text-center py-4"><i class="fas fa-info-circle mr-1"></i> No about content set yet. Click "Edit About Page" to add content.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ABOUT MODAL -->
<div class="modal fade setup-modal" id="aboutModal" tabindex="-1" role="dialog" aria-labelledby="aboutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="aboutModalLabel"><i class="fas fa-file-alt" aria-hidden="true"></i> Edit About Page</h5>
                <button type="button" class="btn-close-modal" data-dismiss="modal" aria-label="Close dialog"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <form method="POST" action="aboutPageSetup.php" enctype="multipart/form-data" id="aboutForm">
                <div class="modal-body">
                    <div class="section-divider"><i class="fas fa-align-left mr-1"></i> Description</div>
                    <div class="form-group">
                        <label>About Description</label>
                        <textarea name="description" id="a_desc" class="form-control" rows="8" placeholder="Write about your organization..."><?= htmlspecialchars($existing_description) ?></textarea>
                    </div>

                    <div class="section-divider"><i class="fas fa-camera mr-1"></i> About Image</div>
                    <?php if ($existing_imgUrl): ?>
                    <div class="form-group">
                        <label>Current Image</label><br>
                        <img src="<?= htmlspecialchars($existing_imgUrl) ?>" class="img-preview mb-2">
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Upload New Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <small class="text-muted">Max 5MB. JPG, PNG, GIF.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel-modal" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
                    <button type="submit" class="btn btn-save-update" id="aSubmitBtn"><i class="fas fa-save mr-1"></i> Save Changes</button>
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
    $('#btnEditAbout').on('click', function(){ $('#aboutModal').modal('show'); });
    $('#aboutForm').on('submit', function(){
        $('#aSubmitBtn').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    });
});

<?php if ($message): ?>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 });
<?php endif; ?>
</script>
</body>
</html>
