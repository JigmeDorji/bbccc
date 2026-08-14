<?php
// blogSetup.php — Admin CRUD for standalone Blog posts (separate from Events).
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/image_helpers.php";
require_once "include/csrf.php";
require_login();
if (!is_admin_role() && !is_website_admin_role()) {
    header("Location: unauthorized");
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = "";
$msgType = "success";
if (isset($_SESSION['blog_setup_flash']) && is_array($_SESSION['blog_setup_flash'])) {
    $message = (string)($_SESSION['blog_setup_flash']['message'] ?? '');
    $msgType = (string)($_SESSION['blog_setup_flash']['type'] ?? 'success');
    unset($_SESSION['blog_setup_flash']);
}

const BBCC_BLOG_IMAGE_WIDTHS = [360, 640, 960, 1280];

function blog_delete_image_and_variants(string $relPath): void {
    if ($relPath === '' || !str_starts_with($relPath, 'uploads/blog/')) return;
    $abs = __DIR__ . '/' . $relPath;
    $dir = dirname($abs);
    $base = pathinfo($abs, PATHINFO_FILENAME);
    if (is_file($abs)) @unlink($abs);
    foreach (glob($dir . '/' . $base . '-*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [] as $variant) {
        @unlink($variant);
    }
}

function blog_upload_image(array $file, string $oldPath = ''): string {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return $oldPath;
    if ($err !== UPLOAD_ERR_OK) throw new Exception("Image upload failed.");

    $size = (int)($file['size'] ?? 0);
    if ($size > 5242880) throw new Exception("Image too large. Max 5MB.");

    $name = (string)($file['name'] ?? '');
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        throw new Exception("Only JPG, PNG, GIF, or WEBP images are allowed.");
    }

    $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
    $dirAbs = __DIR__ . '/uploads/blog';
    if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);
    if (!is_dir($dirAbs)) throw new Exception("Upload folder is not available.");

    $destAbs = $dirAbs . '/' . $safeName;
    if (!move_uploaded_file((string)$file['tmp_name'], $destAbs)) throw new Exception("Failed to upload image.");
    bbcc_generate_responsive_variants($destAbs, BBCC_BLOG_IMAGE_WIDTHS, 82);

    blog_delete_image_and_variants($oldPath);

    return 'uploads/blog/' . $safeName;
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $hasTable = true;
    try {
        $pdo->query("SELECT id FROM blog_posts LIMIT 1");
    } catch (Throwable $e) {
        $hasTable = false;
    }

    if ($hasTable && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            $excerpt = trim((string)($_POST['excerpt'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));
            $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            if ($title === '') throw new Exception("Title is required.");

            $imagePath = '';
            if (isset($_FILES['image'])) {
                $imagePath = blog_upload_image($_FILES['image']);
            }

            $publishedAt = ($status === 'published') ? date('Y-m-d H:i:s') : null;

            $ins = $pdo->prepare("
                INSERT INTO blog_posts (title, excerpt, content, image_path, status, published_at, author_name, created_by)
                VALUES (:title, :excerpt, :content, :image_path, :status, :published_at, :author_name, :created_by)
            ");
            $ins->execute([
                ':title' => $title,
                ':excerpt' => $excerpt,
                ':content' => $content,
                ':image_path' => $imagePath,
                ':status' => $status,
                ':published_at' => $publishedAt,
                ':author_name' => trim((string)($_POST['author_name'] ?? '')),
                ':created_by' => (string)($_SESSION['username'] ?? $_SESSION['userid'] ?? ''),
            ]);

            $_SESSION['blog_setup_flash'] = ['type' => 'success', 'message' => 'Post created successfully.'];
            header('Location: blogSetup');
            exit;
        }

        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception("Invalid post.");

            $stmtOld = $pdo->prepare("SELECT status, published_at FROM blog_posts WHERE id = :id LIMIT 1");
            $stmtOld->execute([':id' => $id]);
            $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new Exception("Post not found.");

            $title = trim((string)($_POST['title'] ?? ''));
            $excerpt = trim((string)($_POST['excerpt'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));
            $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            if ($title === '') throw new Exception("Title is required.");

            $publishedAt = $old['published_at'];
            if ($status === 'published' && $publishedAt === null) {
                $publishedAt = date('Y-m-d H:i:s');
            }

            $upd = $pdo->prepare("
                UPDATE blog_posts
                SET title = :title, excerpt = :excerpt, content = :content,
                    status = :status, published_at = :published_at, author_name = :author_name
                WHERE id = :id
            ");
            $upd->execute([
                ':title' => $title,
                ':excerpt' => $excerpt,
                ':content' => $content,
                ':status' => $status,
                ':published_at' => $publishedAt,
                ':author_name' => trim((string)($_POST['author_name'] ?? '')),
                ':id' => $id,
            ]);

            $_SESSION['blog_setup_flash'] = ['type' => 'success', 'message' => 'Post updated successfully.'];
            header('Location: blogSetup');
            exit;
        }

        if ($action === 'set_image') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception("Invalid post.");

            $stmtOld = $pdo->prepare("SELECT image_path FROM blog_posts WHERE id = :id LIMIT 1");
            $stmtOld->execute([':id' => $id]);
            $oldImage = (string)($stmtOld->fetchColumn() ?: '');
            $imagePath = $oldImage;

            if (isset($_POST['remove_image'])) {
                $imagePath = '';
                blog_delete_image_and_variants($oldImage);
            } elseif (isset($_FILES['image'])) {
                $imagePath = blog_upload_image($_FILES['image'], $oldImage);
            }

            $upd = $pdo->prepare("UPDATE blog_posts SET image_path = :image_path WHERE id = :id");
            $upd->execute([':image_path' => $imagePath, ':id' => $id]);

            $message = "Image updated successfully.";
            $msgType = "success";
        }
    }

    if ($hasTable && isset($_GET['delete'])) {
        $id = (int)$_GET['delete'];
        $stmt = $pdo->prepare("SELECT image_path FROM blog_posts WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $oldPath = (string)$stmt->fetchColumn();

        $del = $pdo->prepare("DELETE FROM blog_posts WHERE id = :id");
        $del->execute([':id' => $id]);

        blog_delete_image_and_variants($oldPath);

        $_SESSION['blog_setup_flash'] = ['type' => 'success', 'message' => 'Post deleted successfully.'];
        header('Location: blogSetup');
        exit;
    }

    $posts = $hasTable
        ? $pdo->query("SELECT * FROM blog_posts ORDER BY COALESCE(published_at, created_at) DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC)
        : [];
} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = "error";
    $posts = $posts ?? [];
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Blog Setup</title>
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
        <h1 class="h3 mb-0 text-gray-800">Blog Setup</h1>
        <a href="blog" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt mr-1"></i> View Blog</a>
    </div>

    <?php if (!$hasTable): ?>
        <div class="alert alert-warning">
            The blog needs a database update. Go to <a href="run-migration">Run Migrations</a> and run pending migrations to enable it.
        </div>
    <?php else: ?>

    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Write New Post</h6></div>
        <div class="card-body">
            <form method="POST" action="blogSetup" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-row">
                    <div class="form-group col-md-8">
                        <label>Title</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Author</label>
                        <input type="text" name="author_name" class="form-control" placeholder="e.g. BBCC Team">
                    </div>
                </div>
                <div class="form-group">
                    <label>Excerpt <small class="text-muted">(short summary shown on the blog listing)</small></label>
                    <textarea name="excerpt" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Content</label>
                    <textarea name="content" class="form-control" rows="8"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Cover Image</label>
                        <input type="file" name="image" class="form-control-file" accept="image/*">
                        <small class="text-muted">Max 5MB.</small>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Create Post</button>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Posts</h6></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Image</th><th>Title</th><th>Status</th><th>Published</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($posts as $i => $p): ?>
                        <?php $imagePath = (string)($p['image_path'] ?? ''); ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <?php if ($imagePath !== ''): ?>
                                    <img src="<?= h($imagePath) ?>" alt="" style="width:60px;height:45px;border-radius:6px;object-fit:cover;">
                                <?php else: ?>
                                    <span class="text-muted small">No image</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h((string)$p['title']) ?></td>
                            <td>
                                <?php if ($p['status'] === 'published'): ?>
                                    <span class="badge badge-success">Published</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Draft</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?= $p['published_at'] ? date('d M Y, g:i A', strtotime((string)$p['published_at'])) : '—' ?></td>
                            <td class="text-nowrap">
                                <a href="blog-post?id=<?= (int)$p['id'] ?>" target="_blank" class="btn btn-outline-info btn-sm" title="View"><i class="fas fa-eye"></i></a>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#editModal<?= (int)$p['id'] ?>"><i class="fas fa-edit mr-1"></i>Edit</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle="modal" data-target="#imageModal<?= (int)$p['id'] ?>"><i class="fas fa-image mr-1"></i><?= $imagePath !== '' ? 'Change' : 'Add' ?> Image</button>
                                <a href="blogSetup?delete=<?= (int)$p['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this post?')"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>

                        <div class="modal fade" id="editModal<?= (int)$p['id'] ?>" tabindex="-1" role="dialog" aria-hidden="true">
                            <div class="modal-dialog modal-lg" role="document">
                                <div class="modal-content">
                                    <form method="POST" action="blogSetup">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Post</h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="form-row">
                                                <div class="form-group col-md-8">
                                                    <label>Title</label>
                                                    <input type="text" name="title" class="form-control" value="<?= h((string)$p['title']) ?>" required>
                                                </div>
                                                <div class="form-group col-md-4">
                                                    <label>Author</label>
                                                    <input type="text" name="author_name" class="form-control" value="<?= h((string)$p['author_name']) ?>">
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label>Excerpt</label>
                                                <textarea name="excerpt" class="form-control" rows="2"><?= h((string)$p['excerpt']) ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label>Content</label>
                                                <textarea name="content" class="form-control" rows="10"><?= h((string)$p['content']) ?></textarea>
                                            </div>
                                            <div class="form-group mb-0">
                                                <label>Status</label>
                                                <select name="status" class="form-control">
                                                    <option value="draft" <?= $p['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                                    <option value="published" <?= $p['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="imageModal<?= (int)$p['id'] ?>" tabindex="-1" role="dialog" aria-hidden="true">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <form method="POST" action="blogSetup" enctype="multipart/form-data">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_image">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Image for "<?= h((string)$p['title']) ?>"</h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                        </div>
                                        <div class="modal-body">
                                            <?php if ($imagePath !== ''): ?>
                                                <div class="mb-2">
                                                    <img src="<?= h($imagePath) ?>" alt="" style="width:160px;height:110px;border-radius:6px;object-fit:cover;">
                                                </div>
                                                <div class="form-check mb-3">
                                                    <input type="checkbox" class="form-check-input" id="removeImage<?= (int)$p['id'] ?>" name="remove_image" value="1">
                                                    <label class="form-check-label" for="removeImage<?= (int)$p['id'] ?>">Remove current image</label>
                                                </div>
                                            <?php endif; ?>
                                            <div class="form-group mb-0">
                                                <label>Upload <?= $imagePath !== '' ? 'replacement' : '' ?> image</label>
                                                <input type="file" name="image" class="form-control-file" accept="image/*">
                                                <small class="form-text text-muted">Max 5MB (JPG, PNG, GIF, WEBP).</small>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$posts): ?>
                        <tr><td colspan="6" class="text-center text-muted">No posts yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
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
