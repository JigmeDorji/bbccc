<?php
// regenerate-image-variants.php — One-time/maintenance tool to backfill
// resized WebP/fallback variants for images that were uploaded before
// (or otherwise missed) responsive-image generation, so
// bbcc_render_responsive_picture() can serve small files instead of
// falling back to the original multi-MB upload. Mirrors run-migration.php.
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/image_helpers.php";
require_login();
if (!is_admin_role() && !is_website_admin_role()) {
    header("Location: unauthorized");
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Directory => widths, matching how each is rendered via
// bbcc_render_responsive_picture() elsewhere in the codebase.
$targets = [
    'uploads/banner' => [640, 960, 1280, 1600],
    'uploads/about'  => [480, 768, 1200],
    'uploads/school' => [480, 768, 1200],
    'uploads/tara'   => [480, 768, 1200],
    'uploads/menu'   => [360, 640, 960, 1280, 1600],
    'uploads/chedtshog' => [480, 768, 1200],
    'uploads/ourteam' => [96, 192],
];

$message = '';
$messageType = 'info';
if (isset($_SESSION['regen_images_flash']) && is_array($_SESSION['regen_images_flash'])) {
    $message = (string)($_SESSION['regen_images_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['regen_images_flash']['type'] ?? 'info');
    unset($_SESSION['regen_images_flash']);
}

function riv_has_variant(string $absPath, array $widths): bool {
    $dir = dirname($absPath);
    $base = pathinfo($absPath, PATHINFO_FILENAME);
    foreach ($widths as $w) {
        if (is_file($dir . '/' . $base . '-' . (int)$w . '.webp')) {
            return true;
        }
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $generated = 0;
    $skipped = 0;
    $scanned = 0;

    foreach ($targets as $relDir => $widths) {
        $absDir = __DIR__ . '/' . $relDir;
        if (!is_dir($absDir)) continue;

        $files = glob($absDir . '/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE);
        if (!$files) continue;

        foreach ($files as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME);
            // Skip files that are themselves already-generated variants
            // (name ends in -<width>), so we don't recurse on our own output.
            if (preg_match('/-\d+$/', $base)) continue;

            $scanned++;
            if (riv_has_variant($file, $widths)) {
                $skipped++;
                continue;
            }
            bbcc_generate_responsive_variants($file, $widths, 82);
            $generated++;
        }
    }

    $message = "Scanned {$scanned} image(s): generated variants for {$generated}, {$skipped} already had them.";
    $messageType = 'success';
    $_SESSION['regen_images_flash'] = ['message' => $message, 'type' => $messageType];
    header('Location: regenerate-image-variants');
    exit;
}

// Status: which originals are still missing variants right now.
$pending = [];
foreach ($targets as $relDir => $widths) {
    $absDir = __DIR__ . '/' . $relDir;
    if (!is_dir($absDir)) continue;
    $files = glob($absDir . '/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE);
    if (!$files) continue;
    foreach ($files as $file) {
        $base = pathinfo($file, PATHINFO_FILENAME);
        if (preg_match('/-\d+$/', $base)) continue;
        if (!riv_has_variant($file, $widths)) {
            $pending[] = [
                'path' => $relDir . '/' . basename($file),
                'size' => filesize($file),
            ];
        }
    }
}

function riv_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function riv_kb(int $bytes): string { return number_format($bytes / 1024, 0) . ' KB'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Regenerate Image Variants</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
    <?php include 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>
            <div class="container-fluid py-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h4 mb-0">Regenerate Image Variants</h1>
                    <span class="badge badge-light"><?= count($pending) ?> pending</span>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-<?= riv_h($messageType === 'success' ? 'success' : $messageType) ?>"><?= riv_h($message) ?></div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Create Resized WebP/Fallback Copies</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-3 text-muted">
                            Site pages request smaller resized versions of banner, about, school, and event photos.
                            Photos uploaded before that feature existed (or via a page that doesn't generate them yet)
                            are still served at full original size, which is a common cause of slow page loads.
                            This scans <code>uploads/banner</code>, <code>uploads/about</code>, <code>uploads/school</code>,
                            <code>uploads/tara</code>, <code>uploads/menu</code>, and <code>uploads/ourteam</code> and creates the missing resized copies.
                            Safe to run any time — it skips images that already have them.
                        </p>
                        <form method="POST" data-confirm="Regenerate missing image variants now?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-images mr-1"></i> Regenerate Missing Variants
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Still Missing Variants</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending)): ?>
                            <div class="text-success"><i class="fas fa-check-circle mr-1"></i> Every scanned image already has resized variants.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>File</th><th>Original Size</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($pending as $p): ?>
                                        <tr>
                                            <td><code><?= riv_h($p['path']) ?></code></td>
                                            <td><?= riv_kb((int)$p['size']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
