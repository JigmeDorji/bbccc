<?php
require_once "include/config.php";
require_once "include/image_helpers.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_login();
if (!is_admin_role() && !is_website_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$message = "";
$msgType = "success";
$existing_description = "";
$default_school_img = "bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png";
$existing_imgUrl = $default_school_img;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['school_setup_flash']) && is_array($_SESSION['school_setup_flash'])) {
    $message = (string)($_SESSION['school_setup_flash']['message'] ?? '');
    $msgType = (string)($_SESSION['school_setup_flash']['type'] ?? 'success');
    unset($_SESSION['school_setup_flash']);
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS school_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            description TEXT NULL,
            imgUrl VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->prepare("SELECT * FROM school_content ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $existing_description = (string)($row['description'] ?? '');
        $existing_imgUrl = (string)($row['imgUrl'] ?? '');
        if ($existing_imgUrl === '') {
            $existing_imgUrl = $default_school_img;
        }
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $description = trim((string)($_POST['description'] ?? ''));
        $imgUrl = $existing_imgUrl;

        if (isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $image_name = (string)$_FILES['image']['name'];
            $image_size = (int)$_FILES['image']['size'];
            $image_tmp = (string)$_FILES['image']['tmp_name'];

            if ($image_size > 5242880) throw new Exception("File too large. Max 5MB.");
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower((string)pathinfo($image_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) throw new Exception("Only JPG, JPEG, PNG, GIF, WEBP allowed.");

            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $image_name);
            $uploadDir = __DIR__ . "/uploads/school";
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }
            if (!is_dir($uploadDir)) throw new Exception("Upload folder is not available.");

            $uploadAbs = $uploadDir . "/" . $safeName;
            if (!move_uploaded_file($image_tmp, $uploadAbs)) throw new Exception("Failed to upload image.");
            bbcc_generate_responsive_variants($uploadAbs, [480, 768, 1200], 82);
            $imgUrl = "uploads/school/" . $safeName;
        }

        $stmt = $pdo->prepare("INSERT INTO school_content (description, imgUrl) VALUES (:description, :imgUrl)");
        $stmt->execute([
            ':description' => $description,
            ':imgUrl' => $imgUrl,
        ]);

        $_SESSION['school_setup_flash'] = [
            'type' => 'success',
            'message' => 'School content updated successfully.',
        ];
        header("Location: schoolContentSetup");
        exit;
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
    <title>School Content Setup</title>
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
        .setup-modal .modal-body .form-control { border-radius:8px; border:1px solid #d1d3e2; padding:.55rem .85rem; font-size:.9rem; }
        .setup-modal .modal-body textarea.form-control { resize:vertical; min-height:120px; }
        .setup-modal .modal-body .section-divider { font-size:.75rem; text-transform:uppercase; letter-spacing:1px; font-weight:700; color:#b7b9cc; margin:.75rem 0 .5rem; padding-bottom:.35rem; border-bottom:1px solid #e3e6f0; }
        .setup-modal .modal-footer { background:#fff; border-top:1px solid #e3e6f0; padding:1rem 1.5rem; }
        .setup-modal .btn-save-update { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; border-radius:8px; font-weight:600; }
        .setup-modal .btn-cancel-modal { background:#e3e6f0; color:#5a5c69; border:none; border-radius:8px; font-weight:600; }
        .preview-card { background:#fff; border-radius:12px; padding:1.5rem; box-shadow:0 2px 12px rgba(0,0,0,.06); }
        .preview-card img { max-width:260px; border-radius:10px; border:2px solid #e3e6f0; margin-bottom:1rem; }
        .btn-edit { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; border-radius:8px; font-weight:600; padding:.5rem 1.3rem; }
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
        <h1 class="h3 mb-0 text-gray-800">School Content Setup</h1>
        <button type="button" class="btn btn-edit" id="btnEditSchool">
            <i class="fas fa-edit mr-1"></i> Edit School Content
        </button>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-eye mr-1"></i> Current School Content</h6>
        </div>
        <div class="card-body">
            <div class="preview-card">
                <?php if ($existing_imgUrl): ?>
                    <img src="<?= htmlspecialchars($existing_imgUrl) ?>" alt="School Image">
                <?php endif; ?>
                <?php if ($existing_description): ?>
                    <div class="text-muted" style="white-space:pre-wrap;line-height:1.8;"><?= htmlspecialchars($existing_description) ?></div>
                <?php else: ?>
                    <p class="text-muted mb-0">No school content set yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade setup-modal" id="schoolModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-school"></i> Edit School Content</h5>
                <button type="button" class="btn-close-modal" data-dismiss="modal" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" action="schoolContentSetup" enctype="multipart/form-data" id="schoolForm">
                <div class="modal-body">
                    <div class="section-divider">Description</div>
                    <div class="form-group">
                        <label>School Description</label>
                        <textarea name="description" class="form-control" rows="8" placeholder="Write school section content..."><?= htmlspecialchars($existing_description) ?></textarea>
                    </div>
                    <div class="section-divider">School Image</div>
                    <?php if ($existing_imgUrl): ?>
                    <div class="form-group">
                        <label>Current Image</label><br>
                        <img src="<?= htmlspecialchars($existing_imgUrl) ?>" style="max-width:200px;border-radius:8px;border:2px solid #e3e6f0;">
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Upload New Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <small class="text-muted">Max 5MB. JPG, PNG, GIF, WEBP.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel-modal" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
                    <button type="submit" class="btn btn-save-update" id="schoolSubmitBtn"><i class="fas fa-save mr-1"></i> Save Changes</button>
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
    $('#btnEditSchool').on('click', function(){ $('#schoolModal').modal('show'); });
    $('#schoolForm').on('submit', function(){
        $('#schoolSubmitBtn').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    });
});
<?php if ($message): ?>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 });
<?php endif; ?>
</script>
</body>
</html>
