<?php
require_once "include/config.php";

$downloadItems = [];
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
            original_name VARCHAR(255) NULL,
            file_path VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $pdo->exec("ALTER TABLE download_files ADD COLUMN original_name VARCHAR(255) NULL AFTER description");
    } catch (Throwable $e) {
        // ignore if column exists
    }
    $downloadItems = $pdo->query("SELECT title, description, original_name, file_path FROM download_files ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $downloadItems = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Downloads | Bhutanese Buddhist &amp; Cultural Centre</title>
    <meta name="description" content="Download forms and templates from the Bhutanese Buddhist and Cultural Centre Canberra.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>

<section class="bbcc-section" style="padding-top:140px;">
    <div class="bbcc-container">
        <div class="section-header fade-up" style="margin-bottom:24px;">
            <span class="section-badge"><i class="fa-solid fa-download"></i> Resources</span>
            <h2>Download <span>Files</span></h2>
            <p>Access the latest templates and reference files.</p>
        </div>

        <div class="bbcc-features">
            <?php $renderedDownloads = 0; ?>
            <?php foreach ($downloadItems as $item): ?>
                <?php $filePath = __DIR__ . '/' . ($item['file_path'] ?? ''); ?>
                <?php if (!is_file($filePath)) continue; ?>
                <?php $renderedDownloads++; ?>
                <div class="bbcc-feature-card fade-up">
                    <div class="bbcc-feature-card__icon bbcc-feature-card__icon--info">
                        <i class="fa-solid fa-file-arrow-down"></i>
                    </div>
                    <h3><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?= htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                    <a class="bbcc-btn bbcc-btn--outline bbcc-btn--sm" href="<?= htmlspecialchars((string)$item['file_path'], ENT_QUOTES, 'UTF-8'); ?>" download="<?= htmlspecialchars((string)($item['original_name'] ?? basename((string)$item['file_path'])), ENT_QUOTES, 'UTF-8'); ?>">
                        Download <i class="fa-solid fa-arrow-down"></i>
                    </a>
                </div>
            <?php endforeach; ?>
            <?php if ($renderedDownloads === 0): ?>
                <div class="bbcc-feature-card fade-up" style="max-width:760px;margin:0 auto;text-align:center;">
                    <div class="bbcc-feature-card__icon bbcc-feature-card__icon--info">
                        <i class="fa-solid fa-circle-info"></i>
                    </div>
                    <h3>No Downloads Available Yet</h3>
                    <p>Files will appear here once uploaded by the administrator.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
