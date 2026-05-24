<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/sponsor_program_data.php";
require_login();
if (!is_admin_role() && !is_website_admin_role()) {
    header("Location: unauthorized");
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$program = isset($_GET['program']) ? (int)$_GET['program'] : 1;
if (!in_array($program, [1, 2, 3], true)) {
    $program = 1;
}

$map = [
    1 => ['title' => 'title_one', 'date' => 'date_one', 'detail' => 'detail_one', 'detail_image' => 'detail_image_one', 'style' => 'style_one', 'label' => 'Program 1'],
    2 => ['title' => 'title_two', 'date' => 'date_two', 'detail' => 'detail_two', 'detail_image' => 'detail_image_two', 'style' => 'style_two', 'label' => 'Program 2'],
    3 => ['title' => 'title_three', 'date' => 'date_three', 'detail' => 'detail_three', 'detail_image' => 'detail_image_three', 'style' => 'style_three', 'label' => 'Program 3'],
];
$sel = $map[$program];

$flashMessage = '';
$flashType = 'success';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $settings = bbcc_load_sponsor_program_settings($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);

    $title = (string)$settings['texts'][$sel['title']];
    $date = (string)$settings['texts'][$sel['date']];
    $detail = (string)$settings['texts'][$sel['detail']];
    $style = (string)$settings['styles'][$sel['style']];
    $detailImage = (string)$settings['images'][$sel['detail_image']];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $titlePosted = trim((string)($_POST['title'] ?? ''));
        $datePosted = trim((string)($_POST['date'] ?? ''));
        $detailPosted = trim((string)($_POST['detail'] ?? ''));
        $stylePosted = trim((string)($_POST['style'] ?? ''));

        if ($titlePosted !== '') $title = $titlePosted;
        if ($datePosted !== '') $date = $datePosted;
        if ($detailPosted !== '') $detail = $detailPosted;
        if (in_array($stylePosted, ['classic', 'split', 'highlight'], true)) {
            $style = $stylePosted;
        }

        if (isset($_FILES['detail_image']) && (int)($_FILES['detail_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $name = (string)$_FILES['detail_image']['name'];
            $size = (int)$_FILES['detail_image']['size'];
            $tmp = (string)$_FILES['detail_image']['tmp_name'];
            if ($size > 5242880) throw new Exception("File too large. Max 5MB.");
            $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                throw new Exception("Only JPG, JPEG, PNG, GIF, WEBP allowed.");
            }
            $safe = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
            $dir = __DIR__ . "/uploads/sponsor";
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            if (!move_uploaded_file($tmp, $dir . "/" . $safe)) {
                throw new Exception("Failed to upload image.");
            }
            $detailImage = "uploads/sponsor/" . $safe;
        }

        $sql = "\n            INSERT INTO sponsor_settings (id, {$sel['title']}, {$sel['date']}, {$sel['detail']}, {$sel['detail_image']}, {$sel['style']})\n            VALUES (1, :title, :date_txt, :detail_txt, :detail_image, :style_txt)\n            ON DUPLICATE KEY UPDATE\n                {$sel['title']} = VALUES({$sel['title']}),\n                {$sel['date']} = VALUES({$sel['date']}),\n                {$sel['detail']} = VALUES({$sel['detail']}),\n                {$sel['detail_image']} = VALUES({$sel['detail_image']}),\n                {$sel['style']} = VALUES({$sel['style']})\n        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':title' => $title,
            ':date_txt' => $date,
            ':detail_txt' => $detail,
            ':detail_image' => $detailImage,
            ':style_txt' => $style,
        ]);

        $_SESSION['sponsor_program_detail_flash'] = ['type' => 'success', 'message' => $sel['label'] . ' updated successfully.'];
        header('Location: ProgramDetailsSetup');
        exit;
    }
} catch (Exception $e) {
    $flashMessage = $e->getMessage();
    $flashType = 'error';
    if (!isset($title)) $title = '';
    if (!isset($date)) $date = '';
    if (!isset($detail)) $detail = '';
    if (!isset($style)) $style = 'classic';
    if (!isset($detailImage)) $detailImage = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Edit Program Detail</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
<?php include_once 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column"><div id="content">
<?php include_once 'include/admin-header.php'; ?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Edit <?= htmlspecialchars($sel['label']) ?> Details</h1>
        <a href="ProgramDetailsSetup" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i> Back to List</a>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Update Content and Image</h6></div>
        <div class="card-body">
            <form method="POST" action="sponsorProgramDetailEdit?program=<?= (int)$program ?>" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group col-md-8">
                        <label>Program Title</label>
                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($title) ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Date Text</label>
                        <input type="text" name="date" class="form-control" value="<?= htmlspecialchars($date) ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Design Style</label>
                        <select name="style" class="form-control">
                            <option value="classic" <?= $style === 'classic' ? 'selected' : '' ?>>Classic</option>
                            <option value="split" <?= $style === 'split' ? 'selected' : '' ?>>Split</option>
                            <option value="highlight" <?= $style === 'highlight' ? 'selected' : '' ?>>Highlight</option>
                        </select>
                    </div>
                    <div class="form-group col-md-8">
                        <label>Detail Image</label>
                        <?php if ($detailImage !== ''): ?>
                            <div class="mb-2"><img src="<?= htmlspecialchars($detailImage) ?>" alt="detail image" style="width:120px;height:120px;border-radius:10px;object-fit:cover;"></div>
                        <?php endif; ?>
                        <input type="file" name="detail_image" class="form-control" accept="image/*">
                    </div>
                </div>
                <div class="form-group">
                    <label>Detail Content</label>
                    <textarea name="detail" class="form-control" rows="10"><?= htmlspecialchars($detail) ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save Changes</button>
            </form>
        </div>
    </div>
</div>
</div><?php include_once 'include/admin-footer.php'; ?></div></div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
<script><?php if ($flashMessage): ?>Swal.fire({ icon:'<?= $flashType ?>', title:'<?= addslashes($flashMessage) ?>', showConfirmButton:false, timer:1800 });<?php endif; ?></script>
</body>
</html>
