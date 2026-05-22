<?php
require_once "include/config.php";
require_once "include/image_helpers.php";

$menu = null;
try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

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
    header('Location: index');
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
        .ed-panel {
            background: linear-gradient(145deg, #ffffff, #fff7f2);
            border: 1px solid #f3e0d5;
            border-radius: var(--radius-xl);
            padding: 34px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            max-width: 1120px;
            margin: 0 auto;
        }
        .ed-panel::after {
            content: "";
            position: absolute;
            top: -70px;
            right: -50px;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(136, 27, 18, .07);
            pointer-events: none;
        }
        .ed-grid {
            position: relative;
            z-index: 1;
            display: block;
        }
        .ed-media {
            border-radius: 18px;
            overflow: hidden;
            border: 3px solid rgba(136, 27, 18, .14);
            background: #fff8f4;
            height: 320px;
            max-width: 620px;
            margin: 0 auto 24px;
        }
        .ed-media picture,
        .ed-media img {
            width: 100%;
            height: 100%;
            display: block;
        }
        .ed-media img {
            object-fit: contain;
            background: #fff;
            padding: 8px;
        }
        .ed-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 12px 0 16px;
        }
        .ed-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid #f1d7c8;
            color: #7c2d12;
            font-size: .82rem;
            font-weight: 700;
        }
        .ed-chip i { color: #9a3412; }
        .ed-body {
            color: var(--gray-700);
            line-height: 1.9;
            font-size: 1.03rem;
            margin-bottom: 16px;
        }
        .ed-empty {
            display: inline-flex;
            width: 100%;
            min-height: 420px;
            align-items: center;
            justify-content: center;
            background: #fff8f4;
            color: #9a3412;
            font-weight: 700;
            letter-spacing: .3px;
        }
        @media (max-width: 991px) {
            .ed-media,
            .ed-media img,
            .ed-empty { height: 240px; }
        }
        @media (max-width: 767px) {
            .ed-panel { padding: 22px; }
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
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li><a href="events">Events</a></li>
            <li class="sep">/</li>
            <li>Detail</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="ed-panel fade-up">
            <div class="ed-grid">
                <div class="ed-media">
                    <?php if (!empty($menu['menuImgUrl'])): ?>
                        <?= bbcc_render_responsive_picture(
                            (string)$menu['menuImgUrl'],
                            (string)$menu['menuName'],
                            [
                                'sizes' => '(max-width: 991px) 100vw, 58vw',
                                'loading' => 'lazy',
                                'decoding' => 'async',
                                'widths' => [640, 960, 1280, 1600],
                            ]
                        ) ?>
                    <?php else: ?>
                        <div class="ed-empty"><i class="fa-solid fa-calendar-days" style="margin-right:8px;"></i> Event Image</div>
                    <?php endif; ?>
                </div>
                <div>
                <h2 style="font-size:2rem;font-weight:800;margin-bottom:10px;"><?= htmlspecialchars($menu['menuName']) ?></h2>
                <div class="ed-meta">
                    <span class="ed-chip"><i class="fa-regular fa-calendar"></i> <?= $eventDate ?></span>
                    <span class="ed-chip"><i class="fa-solid fa-user"></i> BBCC Community Event</span>
                </div>
                <div class="ed-body">
                    <p><?= nl2br(htmlspecialchars((string)$menu['menuDetail'])) ?></p>
                </div>
                <a href="events" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm" style="margin-top:8px;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Events
                </a>
            </div>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>
