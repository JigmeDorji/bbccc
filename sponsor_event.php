<?php
$programKey = isset($_GET['program']) ? (int)$_GET['program'] : 1;
if ($programKey === 2) {
    header('Location: program-tshe-chenga');
    exit;
}
if ($programKey === 3) {
    header('Location: program-tara-menlha');
    exit;
}
header('Location: program-tshe-chutham');
exit;
require_once "include/config.php";

$programKey = isset($_GET['program']) ? (int)$_GET['program'] : 1;
if ($programKey < 1 || $programKey > 3) {
    $programKey = 1;
}

$sponsorIcons = [
    'icon_one' => 'fa-calendar-day',
    'icon_two' => 'fa-moon',
    'icon_three' => 'fa-spa',
];
$sponsorImages = [
    'image_one' => '',
    'image_two' => '',
    'image_three' => '',
    'detail_image_one' => '',
    'detail_image_two' => '',
    'detail_image_three' => '',
];
$sponsorText = [
    'title_one' => '10th Day of Bhutanese Month (Tshe Chutham)',
    'title_two' => '15th Day of Bhutanese Month (Tshe Chenga)',
    'title_three' => 'Monthly Tara and Menlha Dungdrup',
    'date_one' => '10th day of each Bhutanese month (Tshe Chutham).',
    'date_two' => '15th day of each Bhutanese month (Tshe Chenga).',
    'date_three' => 'Monthly (as scheduled by the Centre).',
    'detail_one' => "On the 10th day of each Bhutanese month, the Centre observes Guru Rinpoche Day (Tshe Chutham) with prayers and community practice. Families, groups, and individuals are welcome to sponsor this monthly ritual and participate in preserving this sacred tradition.",
    'detail_two' => "On the 15th day of each Bhutanese month (Tshe Chenga), the Centre holds Yum Ekazati and Gyenyen Tshokhor practice. Community members are warmly invited to attend, receive blessings, and support this important monthly observance through sponsorship.",
    'detail_three' => "The Centre also conducts monthly Tara and Menlha Dungdrup prayers for wellbeing, healing, and merit. We warmly welcome sponsorship from individuals, families, and groups who wish to support these regular spiritual practices.",
];

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );

    $stmtSponsor = $pdo->prepare("SELECT * FROM sponsor_settings WHERE id = 1 LIMIT 1");
    $stmtSponsor->execute();
    $sRow = $stmtSponsor->fetch(PDO::FETCH_ASSOC) ?: [];

    if (!empty($sRow)) {
        foreach (['icon_one', 'icon_two', 'icon_three'] as $k) {
            $v = trim((string)($sRow[$k] ?? ''));
            if ($v !== '' && preg_match('/^fa-[a-z0-9-]+$/', $v)) {
                $sponsorIcons[$k] = $v;
            }
        }
        foreach (['image_one', 'image_two', 'image_three', 'detail_image_one', 'detail_image_two', 'detail_image_three'] as $k) {
            $sponsorImages[$k] = trim((string)($sRow[$k] ?? ''));
        }
        foreach (array_keys($sponsorText) as $k) {
            $v = trim((string)($sRow[$k] ?? ''));
            if ($v !== '') {
                $sponsorText[$k] = $v;
            }
        }
    }
} catch (Exception $e) {
    // Keep defaults when DB is unavailable.
}

$map = [
    1 => ['title' => 'title_one', 'date' => 'date_one', 'image' => 'image_one', 'detail_image' => 'detail_image_one', 'icon' => 'icon_one', 'detail' => 'detail_one'],
    2 => ['title' => 'title_two', 'date' => 'date_two', 'image' => 'image_two', 'detail_image' => 'detail_image_two', 'icon' => 'icon_two', 'detail' => 'detail_two'],
    3 => ['title' => 'title_three', 'date' => 'date_three', 'image' => 'image_three', 'detail_image' => 'detail_image_three', 'icon' => 'icon_three', 'detail' => 'detail_three'],
];

$sel = $map[$programKey];
$title = (string)$sponsorText[$sel['title']];
$dateText = (string)$sponsorText[$sel['date']];
$imagePath = (string)$sponsorImages[$sel['detail_image']];
if ($imagePath === '') {
    $imagePath = (string)$sponsorImages[$sel['image']];
}
$iconClass = (string)$sponsorIcons[$sel['icon']];
$detailText = (string)$sponsorText[$sel['detail']];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> — BBCC</title>
    <meta name="description" content="Program details for <?= htmlspecialchars($title) ?> at BBCC.">
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .sp-detail {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 32px;
            box-shadow: var(--shadow-md);
        }
        .sp-detail__media {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid rgba(136, 27, 18, .15);
            margin-bottom: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff8f4;
        }
        .sp-detail__media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .sp-detail__media i {
            font-size: 2rem;
            color: var(--brand);
        }
        .sp-detail__meta {
            margin: 14px 0 18px;
            color: var(--gray-700);
            font-weight: 600;
        }
        .sp-detail__body {
            color: var(--gray-700);
            line-height: 1.9;
            font-size: 1.03rem;
            margin-bottom: 26px;
        }
        .sp-detail__switch {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }
    </style>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-hand-holding-heart"></i> Sponsor Program Details</h1>
        <p class="bbcc-page-hero__subtitle">Learn more about each monthly ritual sponsorship program</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li><a href="events">Events</a></li>
            <li class="sep">/</li>
            <li>Sponsor Program</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="sp-detail fade-up">
            <div class="sp-detail__media">
                <?php if ($imagePath !== ''): ?>
                    <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars($title) ?>">
                <?php else: ?>
                    <i class="fa-solid <?= htmlspecialchars($iconClass) ?>"></i>
                <?php endif; ?>
            </div>
            <h2><?= htmlspecialchars($title) ?></h2>
            <p class="sp-detail__meta"><strong>Date:</strong> <?= htmlspecialchars($dateText) ?></p>
            <p class="sp-detail__body"><?= htmlspecialchars($detailText) ?></p>

            <div class="sp-detail__switch">
                <a href="sponsor_event.php?program=1" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm">Program 1</a>
                <a href="sponsor_event.php?program=2" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm">Program 2</a>
                <a href="sponsor_event.php?program=3" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm">Program 3</a>
                <a href="events#monthly-ritual-programs" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm">Back to Events</a>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
