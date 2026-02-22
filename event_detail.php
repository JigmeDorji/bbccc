<?php
require_once "include/config.php";

$menu = null;
try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM menu WHERE id = :id LIMIT 1");
        $stmt->bindParam(":id", $_GET['id'], PDO::PARAM_INT);
        $stmt->execute();
        $menu = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // silent
}

if (!$menu) {
    header('Location: index.php');
    exit;
}

$eventDate = "Date Not Set";
if (!empty($menu['eventStartDateTime'])) {
    $eventDate = date("d M Y – g:i A", strtotime($menu['eventStartDateTime']));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title><?= htmlspecialchars($menu['menuName']) ?> — BBCC</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .ed-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 48px; align-items: flex-start; }
        .ed-img { width: 100%; height: 420px; object-fit: cover; border-radius: var(--radius-xl); box-shadow: var(--shadow-lg); }
        .ed-meta { display: flex; gap: 20px; font-size: .88rem; color: var(--gray-600); margin-bottom: 24px; flex-wrap: wrap; }
        .ed-meta i { color: var(--gold); margin-right: 6px; }
        .ed-body { font-size: 1.05rem; line-height: 1.9; color: var(--gray-700); }
        .ed-body p { margin-bottom: 16px; }
        @media (max-width: 991px) {
            .ed-grid { grid-template-columns: 1fr; gap: 32px; }
            .ed-img { height: 280px; }
        }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<!-- Page Hero -->
<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-calendar-check"></i> Event Detail</h1>
        <p class="bbcc-page-hero__subtitle">View event information and details</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index.php">Home</a></li>
            <li class="sep">/</li>
            <li><a href="events.php">Events</a></li>
            <li class="sep">/</li>
            <li>Detail</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="ed-grid">
            <div class="fade-up">
                <img src="<?= htmlspecialchars($menu['menuImgUrl']) ?>" alt="<?= htmlspecialchars($menu['menuName']) ?>" class="ed-img">
            </div>
            <div class="fade-up">
                <h2 style="font-size:2rem;font-weight:800;margin-bottom:12px;"><?= htmlspecialchars($menu['menuName']) ?></h2>
                <div class="ed-meta">
                    <span><i class="fa-regular fa-calendar"></i> <?= $eventDate ?></span>
                    <span><i class="fa-solid fa-user"></i> BBCC</span>
                </div>
                <div class="ed-body">
                    <p><?= nl2br(htmlspecialchars($menu['menuDetail'])) ?></p>
                </div>
                <a href="events.php" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm" style="margin-top:24px;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Events
                </a>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>






