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

$flashMessage = "";
$flashType = "success";
$icons = [
    'icon_one' => 'fa-calendar-day',
    'icon_two' => 'fa-moon',
    'icon_three' => 'fa-spa',
];
$images = [
    'image_one' => '',
    'image_two' => '',
    'image_three' => '',
];
$texts = [
    'intro_text' => "We warmly welcome sponsorship from individuals, families, and groups to help sustain these monthly rituals at the Centre.\nThe following monthly rituals are available for sponsorship.\nFor sponsorship availability and further details, please contact Khenpo Sonam or Namgay (BBCC Program Coordinator) at 0434 522 720.",
    'title_one' => '10th Day of Bhutanese Month (Tshe Chutham)',
    'title_two' => '15th Day of Bhutanese Month (Tshe Chenga)',
    'title_three' => 'Monthly Tara and Menlha Dungdrup',
    'date_one' => '10th day of each Bhutanese month (Tshe Chutham).',
    'date_two' => '15th day of each Bhutanese month (Tshe Chenga).',
    'date_three' => 'Monthly (as scheduled by the Centre).',
];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['sponsor_setup_flash']) && is_array($_SESSION['sponsor_setup_flash'])) {
    $flashMessage = (string)($_SESSION['sponsor_setup_flash']['message'] ?? '');
    $flashType = (string)($_SESSION['sponsor_setup_flash']['type'] ?? 'success');
    unset($_SESSION['sponsor_setup_flash']);
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

        $load = $pdo->prepare("SELECT * FROM sponsor_settings WHERE id = 1 LIMIT 1");
    $load->execute();
    $row = $load->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['icon_one','icon_two','icon_three'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v !== '' && preg_match('/^fa-[a-z0-9-]+$/', $v)) {
            $icons[$k] = $v;
        }
    }
    foreach (['image_one','image_two','image_three'] as $k) {
        $images[$k] = trim((string)($row[$k] ?? ''));
    }
    foreach (array_keys($texts) as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v !== '') $texts[$k] = $v;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $iconOne = trim((string)($_POST['icon_one'] ?? ''));
        $iconTwo = trim((string)($_POST['icon_two'] ?? ''));
        $iconThree = trim((string)($_POST['icon_three'] ?? ''));
        $introPosted = trim((string)($_POST['intro_text'] ?? ''));
        if ($introPosted !== '') $texts['intro_text'] = $introPosted;
        foreach (['title_one','title_two','title_three','date_one','date_two','date_three'] as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v !== '') $texts[$k] = $v;
        }

        $validate = static function (string $v): bool {
            return (bool)preg_match('/^fa-[a-z0-9-]+$/', $v);
        };

        if (!$validate($iconOne) || !$validate($iconTwo) || !$validate($iconThree)) {
            throw new Exception("Use valid Font Awesome icon names like fa-calendar-day, fa-moon, fa-spa.");
        }

        $uploadFields = [
            'image_one' => 'sponsor_image_one',
            'image_two' => 'sponsor_image_two',
            'image_three' => 'sponsor_image_three',
        ];
        foreach ($uploadFields as $col => $fileKey) {
            if (isset($_FILES[$fileKey]) && (int)($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $imageName = (string)$_FILES[$fileKey]['name'];
                $imageSize = (int)$_FILES[$fileKey]['size'];
                $imageTmp = (string)$_FILES[$fileKey]['tmp_name'];
                if ($imageSize > 5242880) throw new Exception("File too large. Max 5MB.");
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $ext = strtolower((string)pathinfo($imageName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) throw new Exception("Only JPG, JPEG, PNG, GIF, WEBP allowed.");

                $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $imageName);
                $uploadDir = __DIR__ . "/uploads/sponsor";
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
                if (!is_dir($uploadDir)) throw new Exception("Upload folder is not available.");

                $uploadAbs = $uploadDir . "/" . $safeName;
                if (!move_uploaded_file($imageTmp, $uploadAbs)) throw new Exception("Failed to upload image.");
                $images[$col] = "uploads/sponsor/" . $safeName;
            }
        }

        $save = $pdo->prepare("\n            INSERT INTO sponsor_settings (id, icon_one, icon_two, icon_three, image_one, image_two, image_three, intro_text, title_one, title_two, title_three, date_one, date_two, date_three)\n            VALUES (1, :icon_one, :icon_two, :icon_three, :image_one, :image_two, :image_three, :intro_text, :title_one, :title_two, :title_three, :date_one, :date_two, :date_three)\n            ON DUPLICATE KEY UPDATE\n                icon_one = VALUES(icon_one),\n                icon_two = VALUES(icon_two),\n                icon_three = VALUES(icon_three),\n                image_one = VALUES(image_one),\n                image_two = VALUES(image_two),\n                image_three = VALUES(image_three),\n                intro_text = VALUES(intro_text),\n                title_one = VALUES(title_one),\n                title_two = VALUES(title_two),\n                title_three = VALUES(title_three),\n                date_one = VALUES(date_one),\n                date_two = VALUES(date_two),\n                date_three = VALUES(date_three)\n        ");
        $save->execute([
            ':icon_one' => $iconOne,
            ':icon_two' => $iconTwo,
            ':icon_three' => $iconThree,
            ':image_one' => (string)$images['image_one'],
            ':image_two' => (string)$images['image_two'],
            ':image_three' => (string)$images['image_three'],
            ':intro_text' => (string)$texts['intro_text'],
            ':title_one' => (string)$texts['title_one'],
            ':title_two' => (string)$texts['title_two'],
            ':title_three' => (string)$texts['title_three'],
            ':date_one' => (string)$texts['date_one'],
            ':date_two' => (string)$texts['date_two'],
            ':date_three' => (string)$texts['date_three'],
        ]);

        $_SESSION['sponsor_setup_flash'] = [
            'type' => 'success',
            'message' => 'Sponsor icons and images updated successfully.',
        ];
        header('Location: sponsorSetup');
        exit;
    }

} catch (Exception $e) {
    $flashMessage = $e->getMessage();
    $flashType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Setup Montly Events</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
<?php include_once 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include_once 'include/admin-header.php'; ?>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Setup Montly Events</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-icons mr-1"></i> Support Monthly Ritual Programs Icons &amp; Images</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="sponsorSetup" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Icon 1 (Tshe Chutham)</label>
                        <input type="text" name="icon_one" class="form-control" value="<?= htmlspecialchars((string)$icons['icon_one']) ?>" required>
                        <?php if (!empty($images['image_one'])): ?><div class="mt-2"><img src="<?= htmlspecialchars((string)$images['image_one']) ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #e5e7eb;"></div><?php endif; ?>
                        <input type="file" name="sponsor_image_one" class="form-control mt-2" accept="image/*">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Icon 2 (Tshe Chenga)</label>
                        <input type="text" name="icon_two" class="form-control" value="<?= htmlspecialchars((string)$icons['icon_two']) ?>" required>
                        <?php if (!empty($images['image_two'])): ?><div class="mt-2"><img src="<?= htmlspecialchars((string)$images['image_two']) ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #e5e7eb;"></div><?php endif; ?>
                        <input type="file" name="sponsor_image_two" class="form-control mt-2" accept="image/*">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Icon 3 (Tara &amp; Menlha)</label>
                        <input type="text" name="icon_three" class="form-control" value="<?= htmlspecialchars((string)$icons['icon_three']) ?>" required>
                        <?php if (!empty($images['image_three'])): ?><div class="mt-2"><img src="<?= htmlspecialchars((string)$images['image_three']) ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #e5e7eb;"></div><?php endif; ?>
                        <input type="file" name="sponsor_image_three" class="form-control mt-2" accept="image/*">
                    </div>
                </div>
                <small class="text-muted d-block mb-3">Enter Font Awesome icon names only, e.g. <code>fa-calendar-day</code>, <code>fa-moon</code>, <code>fa-spa</code>.</small>

                <hr>
                <div class="form-group">
                    <label>Intro Text</label>
                    <textarea name="intro_text" class="form-control" rows="4"><?= htmlspecialchars((string)$texts['intro_text']) ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Program Title 1</label>
                        <input type="text" name="title_one" class="form-control mb-2" value="<?= htmlspecialchars((string)$texts['title_one']) ?>">
                        <label>Date Text 1</label>
                        <input type="text" name="date_one" class="form-control" value="<?= htmlspecialchars((string)$texts['date_one']) ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Program Title 2</label>
                        <input type="text" name="title_two" class="form-control mb-2" value="<?= htmlspecialchars((string)$texts['title_two']) ?>">
                        <label>Date Text 2</label>
                        <input type="text" name="date_two" class="form-control" value="<?= htmlspecialchars((string)$texts['date_two']) ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Program Title 3</label>
                        <input type="text" name="title_three" class="form-control mb-2" value="<?= htmlspecialchars((string)$texts['title_three']) ?>">
                        <label>Date Text 3</label>
                        <input type="text" name="date_three" class="form-control" value="<?= htmlspecialchars((string)$texts['date_three']) ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save Settings</button>
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
<?php if ($flashMessage): ?>
Swal.fire({ icon:'<?= $flashType ?>', title:'<?= addslashes($flashMessage) ?>', showConfirmButton:false, timer:1800 });
<?php endif; ?>
</script>
</body>
</html>
