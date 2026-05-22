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
$default_tara_img = "bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png";
$tara = [
    'title' => 'Droenchoe (Tara) Practice',
    'subtitle' => 'A deeply spiritual journey for all practitioners',
    'intro_text' => 'Under the blessing and guidance of His Eminence Leytshog Lopen Rinpoche, the Bhutanese Centre offers Droenchoe (Tara) Practice classes for all practitioners.',
    'body_text' => "If you are interested and wanting to take your first step, we warmly welcome you to join this deeply spiritual journey.\n\nWe warmly welcome anyone wishing to learn and deepen their practice.",
    'schedule_text' => 'Classes are held every Saturday from 5:00 PM to 8:00 PM.',
    'monthly_text' => 'We also conduct monthly Droenchoe practice sessions.',
    'contact_text' => 'Contact Khenpo Sonam at 0434 522 720 or visit the Bhutanese Centre.',
    'imgUrl' => $default_tara_img,
];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['tara_setup_flash']) && is_array($_SESSION['tara_setup_flash'])) {
    $message = (string)($_SESSION['tara_setup_flash']['message'] ?? '');
    $msgType = (string)($_SESSION['tara_setup_flash']['type'] ?? 'success');
    unset($_SESSION['tara_setup_flash']);
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS tara_content (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            title VARCHAR(180) NULL,\n            subtitle VARCHAR(255) NULL,\n            intro_text TEXT NULL,\n            body_text TEXT NULL,\n            schedule_text VARCHAR(255) NULL,\n            monthly_text VARCHAR(255) NULL,\n            contact_text VARCHAR(255) NULL,\n            imgUrl VARCHAR(255) DEFAULT NULL,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");

    $stmt = $pdo->prepare("SELECT * FROM tara_content ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($row)) {
        foreach ($tara as $k => $v) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
                $tara[$k] = (string)$row[$k];
            }
        }
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $title = trim((string)($_POST['tara_title'] ?? ''));
        $subtitle = trim((string)($_POST['tara_subtitle'] ?? ''));
        $introText = trim((string)($_POST['tara_intro_text'] ?? ''));
        $bodyText = trim((string)($_POST['tara_body_text'] ?? ''));
        $scheduleText = trim((string)($_POST['tara_schedule_text'] ?? ''));
        $monthlyText = trim((string)($_POST['tara_monthly_text'] ?? ''));
        $contactText = trim((string)($_POST['tara_contact_text'] ?? ''));
        $imgUrl = (string)$tara['imgUrl'];

        if ($title !== '') $tara['title'] = $title;
        if ($subtitle !== '') $tara['subtitle'] = $subtitle;
        if ($introText !== '') $tara['intro_text'] = $introText;
        if ($bodyText !== '') $tara['body_text'] = $bodyText;
        if ($scheduleText !== '') $tara['schedule_text'] = $scheduleText;
        if ($monthlyText !== '') $tara['monthly_text'] = $monthlyText;
        if ($contactText !== '') $tara['contact_text'] = $contactText;

        if (isset($_FILES['tara_image']) && (int)($_FILES['tara_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $imageName = (string)$_FILES['tara_image']['name'];
            $imageSize = (int)$_FILES['tara_image']['size'];
            $imageTmp = (string)$_FILES['tara_image']['tmp_name'];

            if ($imageSize > 5242880) throw new Exception("File too large. Max 5MB.");
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower((string)pathinfo($imageName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) throw new Exception("Only JPG, JPEG, PNG, GIF, WEBP allowed.");

            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $imageName);
            $uploadDir = __DIR__ . "/uploads/tara";
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }
            if (!is_dir($uploadDir)) throw new Exception("Upload folder is not available.");

            $uploadAbs = $uploadDir . "/" . $safeName;
            if (!move_uploaded_file($imageTmp, $uploadAbs)) throw new Exception("Failed to upload image.");
            bbcc_generate_responsive_variants($uploadAbs, [480, 768, 1200], 82);
            $imgUrl = "uploads/tara/" . $safeName;
        }

        $up = $pdo->prepare("\n            INSERT INTO tara_content (title, subtitle, intro_text, body_text, schedule_text, monthly_text, contact_text, imgUrl)\n            VALUES (:title, :subtitle, :intro_text, :body_text, :schedule_text, :monthly_text, :contact_text, :imgUrl)\n        ");
        $up->execute([
            ':title' => (string)$tara['title'],
            ':subtitle' => (string)$tara['subtitle'],
            ':intro_text' => (string)$tara['intro_text'],
            ':body_text' => (string)$tara['body_text'],
            ':schedule_text' => (string)$tara['schedule_text'],
            ':monthly_text' => (string)$tara['monthly_text'],
            ':contact_text' => (string)$tara['contact_text'],
            ':imgUrl' => $imgUrl,
        ]);

        $_SESSION['tara_setup_flash'] = [
            'type' => 'success',
            'message' => 'Droenchoe (Tara) content updated successfully.',
        ];
        header("Location: taraContentSetup");
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
    <title>Tara Content Setup</title>
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
        <h1 class="h3 mb-0 text-gray-800">Tara Content Setup</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-eye mr-1"></i> Current Droenchoe (Tara) Content</h6>
        </div>
        <div class="card-body">
            <div class="preview-card">
                <?php if (!empty($tara['imgUrl'])): ?>
                    <img src="<?= htmlspecialchars((string)$tara['imgUrl']) ?>" alt="Tara Practice Image">
                <?php endif; ?>
                <p class="mb-1"><strong><?= htmlspecialchars((string)$tara['title']) ?></strong></p>
                <p class="text-muted mb-2"><?= htmlspecialchars((string)$tara['subtitle']) ?></p>
                <div class="text-muted" style="white-space:pre-wrap;line-height:1.7;"><?= htmlspecialchars((string)$tara['intro_text'] . "\n\n" . (string)$tara['body_text']) ?></div>
                <hr>
                <p class="mb-1"><strong>Schedule:</strong> <?= htmlspecialchars((string)$tara['schedule_text']) ?></p>
                <p class="mb-1"><strong>Monthly:</strong> <?= htmlspecialchars((string)$tara['monthly_text']) ?></p>
                <p class="mb-0"><strong>Contact:</strong> <?= htmlspecialchars((string)$tara['contact_text']) ?></p>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-om mr-1"></i> Edit Droenchoe (Tara) Content</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="taraContentSetup" enctype="multipart/form-data" id="taraForm">
                <div class="form-group">
                    <label>Page Title</label>
                    <input type="text" name="tara_title" class="form-control" value="<?= htmlspecialchars((string)$tara['title']) ?>">
                </div>
                <div class="form-group">
                    <label>Page Subtitle</label>
                    <input type="text" name="tara_subtitle" class="form-control" value="<?= htmlspecialchars((string)$tara['subtitle']) ?>">
                </div>
                <div class="form-group">
                    <label>Intro Text</label>
                    <textarea name="tara_intro_text" class="form-control" rows="4"><?= htmlspecialchars((string)$tara['intro_text']) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Body Text</label>
                    <textarea name="tara_body_text" class="form-control" rows="5"><?= htmlspecialchars((string)$tara['body_text']) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Schedule Text</label>
                    <input type="text" name="tara_schedule_text" class="form-control" value="<?= htmlspecialchars((string)$tara['schedule_text']) ?>">
                </div>
                <div class="form-group">
                    <label>Monthly Text</label>
                    <input type="text" name="tara_monthly_text" class="form-control" value="<?= htmlspecialchars((string)$tara['monthly_text']) ?>">
                </div>
                <div class="form-group">
                    <label>Contact Text</label>
                    <input type="text" name="tara_contact_text" class="form-control" value="<?= htmlspecialchars((string)$tara['contact_text']) ?>">
                </div>
                <?php if (!empty($tara['imgUrl'])): ?>
                <div class="form-group">
                    <label>Current Image</label><br>
                    <img src="<?= htmlspecialchars((string)$tara['imgUrl']) ?>" style="max-width:200px;border-radius:8px;border:2px solid #e3e6f0;">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Upload New Image</label>
                    <input type="file" name="tara_image" class="form-control" accept="image/*">
                    <small class="text-muted">Max 5MB. JPG, PNG, GIF, WEBP.</small>
                </div>
                <button type="submit" class="btn btn-primary" id="taraSubmitBtn"><i class="fas fa-save mr-1"></i> Save Tara Content</button>
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
    $('#taraForm').on('submit', function(){
        $('#taraSubmitBtn').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    });
});
<?php if ($message): ?>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 });
<?php endif; ?>
</script>
</body>
</html>
