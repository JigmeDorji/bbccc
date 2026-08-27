<?php
require_once "include/config.php";
require_once "include/image_helpers.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$banner = null;
try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM banner WHERE id = :id LIMIT 1");
        $stmt->bindParam(":id", $_GET['id'], PDO::PARAM_INT);
        $stmt->execute();
        $banner = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // silent
}

if (!$banner) {
    header('Location: index');
    exit;
}

$canEditBanner = in_array(strtolower((string)($_SESSION['role'] ?? '')), ['administrator', 'website admin', 'website_admin', 'company_admin'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title><?= htmlspecialchars((string)$banner['title']) ?> — BBCC</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .bd-panel {
            background: linear-gradient(145deg, #ffffff, #fff7f2);
            border: 1px solid #f3e0d5;
            border-radius: var(--radius-xl);
            padding: 34px;
            box-shadow: var(--shadow-md);
            max-width: 1120px;
            margin: 0 auto;
        }
        .bd-media {
            border-radius: 18px;
            overflow: hidden;
            border: 3px solid rgba(136, 27, 18, .14);
            background: #fff8f4;
            max-width: 720px;
            margin: 0 auto 28px;
        }
        .bd-media picture,
        .bd-media img { width: 100%; height: auto; display: block; }
        .bd-title { font-size: 2rem; font-weight: 800; margin-bottom: 16px; color: var(--gray-900); text-align: center; }
        .bd-body { color: var(--gray-700); line-height: 1.9; font-size: 1.08rem; text-align: center; max-width: 760px; margin: 0 auto 24px; }
        .bd-actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
        @media (max-width: 767px) {
            .bd-panel { padding: 22px; }
            .bd-title { font-size: 1.5rem; }
        }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<!-- Page Hero -->
<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-image"></i> <?= htmlspecialchars((string)$banner['title']) ?></h1>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li><?= htmlspecialchars((string)$banner['title']) ?></li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="bd-panel fade-up">
            <div class="bd-media">
                <?php if (!empty($banner['imgUrl'])): ?>
                    <?= bbcc_render_responsive_picture(
                        (string)$banner['imgUrl'],
                        (string)$banner['title'],
                        [
                            'sizes' => '(max-width: 767px) 100vw, 720px',
                            'loading' => 'eager',
                            'decoding' => 'async',
                            'widths' => [640, 960, 1280, 1600],
                        ]
                    ) ?>
                <?php endif; ?>
            </div>
            <h2 class="bd-title"><?= htmlspecialchars((string)$banner['title']) ?></h2>
            <?php if (!empty($banner['subtitle'])): ?>
            <div class="bd-body">
                <p><?= nl2br(htmlspecialchars((string)$banner['subtitle'])) ?></p>
            </div>
            <?php endif; ?>
            <div class="bd-actions">
                <a href="index" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm">
                    <i class="fa-solid fa-arrow-left"></i> Back to Home
                </a>
                <a href="about-us" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm">
                    Learn About BBCC <i class="fa-solid fa-arrow-right"></i>
                </a>
                <?php if ($canEditBanner): ?>
                <a href="bannerSetup" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm">
                    <i class="fa-solid fa-pen-to-square"></i> Update Banner
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>
