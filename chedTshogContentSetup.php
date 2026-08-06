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
$ched = [
    'title' => 'Ched Tshog Singye Tsewa',
    'subtitle' => 'A community practice of offering and blessing',
    'intro_text' => 'The Bhutanese Centre offers Ched Tshog Singye Tsewa practice, and warmly welcomes members of the community to join.',
    'body_text' => '',
    'schedule_text' => 'Please contact the Centre for current practice dates and times.',
    'monthly_text' => 'Open to all practitioners, both new and experienced, from the wider community.',
    'contact_text' => 'Contact the Centre for more details about this practice.',
    'imgUrl' => '',
];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['ched_tshog_setup_flash']) && is_array($_SESSION['ched_tshog_setup_flash'])) {
    $message = (string)($_SESSION['ched_tshog_setup_flash']['message'] ?? '');
    $msgType = (string)($_SESSION['ched_tshog_setup_flash']['type'] ?? 'success');
    unset($_SESSION['ched_tshog_setup_flash']);
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $stmt = $pdo->prepare("SELECT * FROM ched_tshog_content ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($row)) {
        foreach ($ched as $k => $v) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
                $ched[$k] = (string)$row[$k];
            }
        }
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $title = trim((string)($_POST['ched_title'] ?? ''));
        $subtitle = trim((string)($_POST['ched_subtitle'] ?? ''));
        $introText = trim((string)($_POST['ched_intro_text'] ?? ''));
        $bodyText = trim((string)($_POST['ched_body_text'] ?? ''));
        $scheduleText = trim((string)($_POST['ched_schedule_text'] ?? ''));
        $monthlyText = trim((string)($_POST['ched_monthly_text'] ?? ''));
        $contactText = trim((string)($_POST['ched_contact_text'] ?? ''));
        $imgUrl = (string)$ched['imgUrl'];

        if ($title !== '') $ched['title'] = $title;
        if ($subtitle !== '') $ched['subtitle'] = $subtitle;
        if ($introText !== '') $ched['intro_text'] = $introText;
        if ($bodyText !== '') $ched['body_text'] = $bodyText;
        if ($scheduleText !== '') $ched['schedule_text'] = $scheduleText;
        if ($monthlyText !== '') $ched['monthly_text'] = $monthlyText;
        if ($contactText !== '') $ched['contact_text'] = $contactText;

        if (isset($_FILES['ched_image']) && (int)($_FILES['ched_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $imageName = (string)$_FILES['ched_image']['name'];
            $imageSize = (int)$_FILES['ched_image']['size'];
            $imageTmp = (string)$_FILES['ched_image']['tmp_name'];

            if ($imageSize > 5242880) throw new Exception("File too large. Max 5MB.");
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower((string)pathinfo($imageName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) throw new Exception("Only JPG, JPEG, PNG, GIF, WEBP allowed.");

            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $imageName);
            $uploadDir = __DIR__ . "/uploads/ched-tshog";
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }
            if (!is_dir($uploadDir)) throw new Exception("Upload folder is not available.");

            $uploadAbs = $uploadDir . "/" . $safeName;
            if (!move_uploaded_file($imageTmp, $uploadAbs)) throw new Exception("Failed to upload image.");
            bbcc_generate_responsive_variants($uploadAbs, [480, 768, 1200], 82);
            $imgUrl = "uploads/ched-tshog/" . $safeName;
        }

        $up = $pdo->prepare("
            INSERT INTO ched_tshog_content (title, subtitle, intro_text, body_text, schedule_text, monthly_text, contact_text, imgUrl)
            VALUES (:title, :subtitle, :intro_text, :body_text, :schedule_text, :monthly_text, :contact_text, :imgUrl)
        ");
        $up->execute([
            ':title' => (string)$ched['title'],
            ':subtitle' => (string)$ched['subtitle'],
            ':intro_text' => (string)$ched['intro_text'],
            ':body_text' => (string)$ched['body_text'],
            ':schedule_text' => (string)$ched['schedule_text'],
            ':monthly_text' => (string)$ched['monthly_text'],
            ':contact_text' => (string)$ched['contact_text'],
            ':imgUrl' => $imgUrl,
        ]);

        $_SESSION['ched_tshog_setup_flash'] = [
            'type' => 'success',
            'message' => 'Ched Tshog Singye Tsewa content updated successfully.',
        ];
        header("Location: chedTshogContentSetup");
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
    <title>Ched Tshog Singye Tsewa Content Setup</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .preview-card { background:#fff; border-radius:12px; padding:1.5rem; box-shadow:0 2px 12px rgba(0,0,0,.06); }
        .preview-card img { max-width:260px; border-radius:10px; border:2px solid #e3e6f0; margin-bottom:1rem; }
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
        <h1 class="h3 mb-0 text-gray-800">Ched Tshog Singye Tsewa Content Setup</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-eye mr-1"></i> Current Content</h6>
        </div>
        <div class="card-body">
            <div class="preview-card">
                <?php if (!empty($ched['imgUrl'])): ?>
                    <img src="<?= htmlspecialchars((string)$ched['imgUrl']) ?>" alt="Ched Tshog Singye Tsewa Image">
                <?php endif; ?>
                <p class="mb-1"><strong><?= htmlspecialchars((string)$ched['title']) ?></strong></p>
                <p class="text-muted mb-2"><?= htmlspecialchars((string)$ched['subtitle']) ?></p>
                <div class="text-muted" style="white-space:pre-wrap;line-height:1.7;"><?= htmlspecialchars(trim((string)$ched['intro_text'] . "\n\n" . (string)$ched['body_text'])) ?></div>
                <hr>
                <p class="mb-1"><strong>Schedule:</strong> <?= htmlspecialchars((string)$ched['schedule_text']) ?></p>
                <p class="mb-1"><strong>Who Can Join:</strong> <?= htmlspecialchars((string)$ched['monthly_text']) ?></p>
                <p class="mb-0"><strong>Contact:</strong> <?= htmlspecialchars((string)$ched['contact_text']) ?></p>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-om mr-1"></i> Edit Ched Tshog Singye Tsewa Content</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="chedTshogContentSetup" enctype="multipart/form-data" id="chedForm">
                <div class="form-group">
                    <label>Page Title</label>
                    <input type="text" name="ched_title" class="form-control" value="<?= htmlspecialchars((string)$ched['title']) ?>">
                </div>
                <div class="form-group">
                    <label>Page Subtitle</label>
                    <input type="text" name="ched_subtitle" class="form-control" value="<?= htmlspecialchars((string)$ched['subtitle']) ?>">
                </div>
                <div class="form-group">
                    <label>Intro Text</label>
                    <textarea name="ched_intro_text" class="form-control" rows="4"><?= htmlspecialchars((string)$ched['intro_text']) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Body Text</label>
                    <textarea name="ched_body_text" class="form-control" rows="5"><?= htmlspecialchars((string)$ched['body_text']) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Schedule Text</label>
                    <input type="text" name="ched_schedule_text" class="form-control" value="<?= htmlspecialchars((string)$ched['schedule_text']) ?>">
                </div>
                <div class="form-group">
                    <label>Who Can Join Text</label>
                    <input type="text" name="ched_monthly_text" class="form-control" value="<?= htmlspecialchars((string)$ched['monthly_text']) ?>">
                </div>
                <div class="form-group">
                    <label>Contact Text</label>
                    <input type="text" name="ched_contact_text" class="form-control" value="<?= htmlspecialchars((string)$ched['contact_text']) ?>">
                </div>
                <?php if (!empty($ched['imgUrl'])): ?>
                <div class="form-group">
                    <label>Current Image</label><br>
                    <img src="<?= htmlspecialchars((string)$ched['imgUrl']) ?>" style="max-width:200px;border-radius:8px;border:2px solid #e3e6f0;">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Upload New Image</label>
                    <input type="file" name="ched_image" class="form-control" accept="image/*">
                    <small class="text-muted">Max 5MB. JPG, PNG, GIF, WEBP.</small>
                </div>
                <button type="submit" class="btn btn-primary" id="chedSubmitBtn"><i class="fas fa-save mr-1"></i> Save Content</button>
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
    $('#chedForm').on('submit', function(){
        $('#chedSubmitBtn').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    });
});
<?php if ($message): ?>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 });
<?php endif; ?>
</script>
</body>
</html>
