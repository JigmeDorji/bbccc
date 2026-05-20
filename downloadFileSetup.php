<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_login();
if (!is_admin_role() && !is_website_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$message = "";
$msgType = "success";

function bbcc_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $num = (float)$value;
    switch ($unit) {
        case 'g': return (int)($num * 1024 * 1024 * 1024);
        case 'm': return (int)($num * 1024 * 1024);
        case 'k': return (int)($num * 1024);
        default: return (int)$num;
    }
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS download_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            file_path VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (isset($_GET['delete'])) {
        $id = (int)$_GET['delete'];
        $stmt = $pdo->prepare("SELECT file_path FROM download_files WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $oldPath = (string)$stmt->fetchColumn();

        $del = $pdo->prepare("DELETE FROM download_files WHERE id = :id");
        $del->execute([':id' => $id]);

        if ($oldPath !== '' && str_starts_with($oldPath, 'uploads/downloads/')) {
            $abs = __DIR__ . '/' . $oldPath;
            if (is_file($abs)) @unlink($abs);
        }

        $message = "Download file deleted successfully.";
        $msgType = "success";
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $maxPost = bbcc_ini_bytes((string)ini_get('post_max_size'));
        $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLen > 0 && $maxPost > 0 && $contentLen > $maxPost && empty($_POST) && empty($_FILES)) {
            throw new Exception("Upload failed: request exceeds server limit (post_max_size).");
        }

        if (!isset($_FILES['download_file']) || !is_array($_FILES['download_file'])) {
            throw new Exception("Please choose a file.");
        }
        $err = (int)($_FILES['download_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception("Upload failed: file exceeds server/form upload size limit.");
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new Exception("Upload failed. Please select a file and try again.");
        }

        $title = trim((string)($_POST['title'] ?? $_POST['file_title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($title === '') throw new Exception("Title is required.");

        $fileName = (string)$_FILES['download_file']['name'];
        $tmp = (string)$_FILES['download_file']['tmp_name'];
        $size = (int)$_FILES['download_file']['size'];
        if ($size > 15728640) throw new Exception("File too large. Max 15MB.");

        $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $fileName);
        $dirAbs = __DIR__ . '/uploads/downloads';
        if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);
        if (!is_dir($dirAbs)) throw new Exception("Upload folder is not available.");

        $pathAbs = $dirAbs . '/' . $safeName;
        if (!move_uploaded_file($tmp, $pathAbs)) throw new Exception("Failed to upload file.");
        $rel = 'uploads/downloads/' . $safeName;

        $ins = $pdo->prepare("INSERT INTO download_files (title, description, file_path) VALUES (:title, :description, :file_path)");
        $ins->execute([
            ':title' => $title,
            ':description' => $description,
            ':file_path' => $rel,
        ]);

        $message = "Download file uploaded successfully.";
        $msgType = "success";
    }

    $files = $pdo->query("SELECT * FROM download_files ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = "error";
    $files = $files ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Download Files Setup</title>
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
        <h1 class="h3 mb-0 text-gray-800">Download Files Setup</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Upload New File</h6></div>
        <div class="card-body">
            <form method="POST" action="downloadFileSetup" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Title</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Description</label>
                        <input type="text" name="description" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>File</label>
                        <input type="file" name="download_file" class="form-control" required>
                        <small class="text-muted">Max 15MB.</small>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-upload mr-1"></i> Upload File</button>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Download Files</h6></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Title</th><th>Description</th><th>File</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($files as $i => $f): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars((string)$f['title']) ?></td>
                            <td><?= htmlspecialchars((string)$f['description']) ?></td>
                            <td><a href="<?= htmlspecialchars((string)$f['file_path']) ?>" target="_blank"><?= htmlspecialchars(basename((string)$f['file_path'])) ?></a></td>
                            <td><a href="downloadFileSetup?delete=<?= (int)$f['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this file?')"><i class="fas fa-trash"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$files): ?>
                        <tr><td colspan="5" class="text-center text-muted">No files uploaded yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</div>
<?php include_once 'include/admin-footer.php'; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
<?php if ($message): ?>
<script>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 });
</script>
<?php endif; ?>
</body>
</html>
