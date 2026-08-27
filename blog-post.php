<?php
require_once "include/config.php";
require_once "include/image_helpers.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = strtolower((string)($_SESSION['role'] ?? ''));
$canEditPost = in_array($role, ['admin', 'website admin', 'website_admin'], true);

$post = null;
try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id = :id LIMIT 1");
        $stmt->bindParam(":id", $_GET['id'], PDO::PARAM_INT);
        $stmt->execute();
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // silent
}

// Visitors only see published posts; admins/website admins may preview drafts.
if (!$post || ($post['status'] !== 'published' && !$canEditPost)) {
    header('Location: blog');
    exit;
}

$postDate = !empty($post['published_at']) ? date("d M Y", strtotime((string)$post['published_at'])) : 'Not yet published';

// Recent posts for the sidebar, excluding the current one.
$otherPosts = [];
try {
    $stmtOther = $pdo->prepare("
        SELECT id, title, published_at
        FROM blog_posts
        WHERE status = 'published' AND published_at <= NOW() AND id <> :id
        ORDER BY published_at DESC
        LIMIT 5
    ");
    $stmtOther->execute([':id' => (int)$post['id']]);
    $otherPosts = $stmtOther->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $otherPosts = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title><?= htmlspecialchars((string)$post['title']) ?> — BBCC Blog</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .bp-grid { display: grid; grid-template-columns: 2.2fr 1fr; gap: 34px; align-items: start; }
        .bp-panel {
            background: var(--white); border: 1px solid var(--gray-200); border-radius: var(--radius-xl);
            box-shadow: var(--shadow-md); overflow: hidden;
        }
        .bp-media { width: 100%; aspect-ratio: 16 / 9; overflow: hidden; background: #fff8f4; }
        .bp-media picture, .bp-media img { width: 100%; height: 100%; display: block; object-fit: cover; }
        .bp-body { padding: 30px 34px 34px; }
        .bp-meta {
            display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 16px;
            font-size: .85rem; color: var(--gray-600);
        }
        .bp-meta span { display: inline-flex; align-items: center; gap: 6px; }
        .bp-meta i { color: var(--brand); }
        .bp-content { color: var(--gray-700); line-height: 1.9; font-size: 1.03rem; }
        .bp-draft-flag {
            display: inline-block; margin-bottom: 14px; padding: 5px 12px; border-radius: 999px;
            background: #fef3c7; color: #92400e; font-size: .78rem; font-weight: 700;
        }
        .bp-side-card {
            background: var(--white); border: 1px solid var(--gray-200); border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm); padding: 22px;
        }
        .bp-side-card h4 { font-size: 1rem; font-weight: 700; margin-bottom: 14px; }
        .bp-side-list { list-style: none; margin: 0; padding: 0; }
        .bp-side-list li { margin-bottom: 12px; }
        .bp-side-list a { color: var(--gray-800); font-weight: 600; font-size: .92rem; text-decoration: none; }
        .bp-side-list a:hover { color: var(--brand); }
        .bp-side-list small { display: block; color: var(--gray-500); font-weight: 500; margin-top: 2px; }
        @media (max-width: 991px) { .bp-grid { grid-template-columns: 1fr; } }
        @media (max-width: 576px) { .bp-body { padding: 22px; } }
    </style>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-newspaper"></i> Blog</h1>
        <p class="bbcc-page-hero__subtitle">News, updates, and stories from BBCC</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li><a href="blog">Blog</a></li>
            <li class="sep">/</li>
            <li>Post</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="bp-grid fade-up">
            <div class="bp-panel">
                <?php if (!empty($post['image_path'])): ?>
                <div class="bp-media">
                    <?= bbcc_render_responsive_picture(
                        (string)$post['image_path'],
                        (string)$post['title'],
                        [
                            'sizes' => '(max-width: 991px) 100vw, 65vw',
                            'loading' => 'eager',
                            'decoding' => 'async',
                            'fetchpriority' => 'high',
                            'widths' => [360, 640, 960, 1280],
                        ]
                    ) ?>
                </div>
                <?php endif; ?>
                <div class="bp-body">
                    <?php if ($post['status'] !== 'published'): ?>
                        <span class="bp-draft-flag"><i class="fa-solid fa-eye-slash mr-1"></i>Draft — only visible to admins</span>
                    <?php endif; ?>
                    <h2 style="font-size:2rem;font-weight:800;margin-bottom:14px;"><?= htmlspecialchars((string)$post['title']) ?></h2>
                    <div class="bp-meta">
                        <span><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars($postDate) ?></span>
                        <?php if (!empty($post['author_name'])): ?>
                            <span><i class="fa-regular fa-user"></i> <?= htmlspecialchars((string)$post['author_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="bp-content">
                        <?= nl2br(htmlspecialchars((string)$post['content'])) ?>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:26px;">
                        <a href="blog" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm">
                            <i class="fa-solid fa-arrow-left"></i> Back to Blog
                        </a>
                        <?php if ($canEditPost): ?>
                        <a href="blogSetup" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm">
                            <i class="fa-solid fa-pen-to-square"></i> Manage Posts
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($otherPosts)): ?>
            <div class="bp-side-card">
                <h4><i class="fa-solid fa-clock-rotate-left mr-1"></i> Recent Posts</h4>
                <ul class="bp-side-list">
                    <?php foreach ($otherPosts as $op): ?>
                        <li>
                            <a href="blog-post?id=<?= (int)$op['id'] ?>"><?= htmlspecialchars((string)$op['title']) ?></a>
                            <small><?= htmlspecialchars(date('d M Y', strtotime((string)$op['published_at']))) ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
