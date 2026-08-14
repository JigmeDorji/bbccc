<?php
require_once "include/config.php";
require_once "include/image_helpers.php";

$posts = [];
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
    $posts = $pdo->query("
        SELECT id, title, excerpt, content, image_path, published_at, author_name
        FROM blog_posts
        WHERE status = 'published' AND published_at <= NOW()
        ORDER BY published_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $posts = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Blog | Bhutanese Buddhist &amp; Cultural Centre</title>
    <meta name="description" content="News, updates, and stories from the Bhutanese Buddhist and Cultural Centre Canberra.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
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
            <li>Blog</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <?php if (empty($posts)): ?>
        <div class="bbcc-empty-state fade-up" style="text-align:center;padding:34px 20px;border:1px dashed #d1d5db;border-radius:14px;background:#fff;">
            <p style="margin:0;color:#4b5563;font-weight:600;">No posts published yet. Check back soon.</p>
        </div>
        <?php else: ?>
        <div class="bbcc-events-grid">
            <?php foreach ($posts as $post): ?>
            <a href="blog-post?id=<?= (int)$post['id'] ?>" class="bbcc-event-card fade-up">
                <div class="bbcc-event-card__image">
                    <?php if (!empty($post['image_path'])): ?>
                        <?= bbcc_render_responsive_picture(
                            (string)$post['image_path'],
                            (string)$post['title'],
                            [
                                'sizes' => '(max-width: 576px) 100vw, (max-width: 991px) 50vw, 33vw',
                                'loading' => 'lazy',
                                'decoding' => 'async',
                                'widths' => [360, 640, 960],
                            ]
                        ) ?>
                    <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#fff8f4;">
                            <i class="fa-solid fa-newspaper" style="font-size:2rem;color:var(--brand);opacity:.5;"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="bbcc-event-card__body">
                    <span class="bbcc-event-card__date">
                        <i class="fa-regular fa-calendar"></i> <?= htmlspecialchars(date('d M Y', strtotime((string)$post['published_at']))) ?>
                        <?php if (!empty($post['author_name'])): ?> &middot; <?= htmlspecialchars((string)$post['author_name']) ?><?php endif; ?>
                    </span>
                    <h3><?= htmlspecialchars((string)$post['title']) ?></h3>
                    <?php $teaser = trim((string)$post['excerpt']) !== '' ? (string)$post['excerpt'] : (string)$post['content']; ?>
                    <p><?= htmlspecialchars(mb_strimwidth(trim($teaser), 0, 140, '...')) ?></p>
                    <span class="bbcc-event-card__link">Read More <i class="fa-solid fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
