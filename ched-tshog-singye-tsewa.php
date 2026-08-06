<?php
require_once "include/config.php";
require_once "include/image_helpers.php";

$ched = [
    'title' => 'Ched Tshog Singye Tsewa',
    'subtitle' => 'A community practice of offering and blessing',
    'intro_text' => 'The Bhutanese Centre offers Ched Tshog Singye Tsewa practice, and warmly welcomes members of the community to join.',
    'body_text' => '',
    'schedule_text' => 'Please contact the Centre for current practice dates and times.',
    'monthly_text' => 'Open to all practitioners, both new and experienced, from the wider community.',
    'contact_text' => 'Contact the Centre for more details about this practice.',
    'imgUrl' => '',
];

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
    $stmt = $pdo->prepare("SELECT * FROM ched_tshog_content ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($row)) {
        foreach ($ched as $k => $v) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
                $ched[$k] = (string)$row[$k];
            }
        }
    }
} catch (Throwable $e) {
    // keep defaults
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title><?= htmlspecialchars($ched['title']) ?> — BBCC</title>
    <meta name="description" content="Ched Tshog Singye Tsewa practice at the Bhutanese Buddhist and Cultural Centre Canberra.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .ched-highlight {
            margin-top: 16px;
            background: linear-gradient(135deg, #fdf2f8 0%, #fff7ed 100%);
            color: #831843;
            border: 1px solid #fbcfe8;
            border-radius: 16px;
            padding: 20px 22px;
            box-shadow: 0 10px 20px rgba(157, 23, 77, 0.08);
        }
        .ched-highlight p {
            margin: 0;
            font-size: 1.02rem;
            line-height: 1.75;
            font-weight: 600;
        }
        .ched-flow {
            margin-top: 20px;
            color: #4b5563;
            line-height: 1.9;
            font-size: 1rem;
            max-width: 860px;
        }
        .ched-info-grid {
            margin-top: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        .ched-info-card {
            border: 1px solid #fbcfe8;
            background: #ffffff;
            border-radius: 14px;
            padding: 14px 16px;
        }
        .ched-info-card h3 {
            margin: 0 0 8px;
            font-size: 1rem;
            color: #9d174d;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ched-info-card p {
            margin: 0;
            color: #374151;
            line-height: 1.6;
        }
        @media (max-width: 768px) {
            .ched-highlight { padding: 16px; }
            .ched-flow { font-size: .96rem; }
        }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-om"></i> <?= htmlspecialchars($ched['title']) ?></h1>
        <p class="bbcc-page-hero__subtitle"><?= htmlspecialchars($ched['subtitle']) ?></p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li><a href="services">Services</a></li>
            <li class="sep">/</li>
            <li>Ched Tshog Singye Tsewa</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container" style="max-width:980px;">
        <div class="section-header fade-up" style="text-align:left;max-width:none;margin-bottom:20px;">
            <span class="section-badge"><i class="fa-solid fa-hands-praying"></i> Spiritual Practice Program</span>
            <h2>About Our <span><?= htmlspecialchars($ched['title']) ?></span></h2>
        </div>

        <?php if (!empty($ched['imgUrl'])): ?>
        <div class="bbcc-about__image fade-up" style="max-width:680px;margin:0 0 20px;">
            <?= bbcc_render_responsive_picture(
                (string)$ched['imgUrl'],
                (string)$ched['title'],
                [
                    'sizes' => '(max-width: 991px) 100vw, 70vw',
                    'loading' => 'lazy',
                    'decoding' => 'async',
                    'widths' => [480, 768, 1200],
                ]
            ) ?>
        </div>
        <?php endif; ?>

        <div class="ched-highlight fade-up">
            <p>
                <?= htmlspecialchars($ched['intro_text']) ?>
            </p>
        </div>

        <?php if (trim((string)$ched['body_text']) !== ''): ?>
        <div class="ched-flow fade-up">
            <?php foreach (preg_split("/\r\n|\n|\r/", (string)$ched['body_text']) as $line): ?>
                <?php if (trim((string)$line) !== ''): ?>
                    <p><?= htmlspecialchars((string)$line) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="ched-info-grid fade-up">
            <div class="ched-info-card">
                <h3><i class="fa-regular fa-clock"></i> Schedule</h3>
                <p><?= htmlspecialchars($ched['schedule_text']) ?></p>
            </div>
            <div class="ched-info-card">
                <h3><i class="fa-solid fa-people-group"></i> Who Can Join</h3>
                <p><?= htmlspecialchars($ched['monthly_text']) ?></p>
            </div>
            <div class="ched-info-card">
                <h3><i class="fa-solid fa-phone"></i> Contact</h3>
                <p><?= htmlspecialchars($ched['contact_text']) ?></p>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>
