<?php
require_once "include/config.php";
require_once "include/image_helpers.php";

$tara = [
    'title' => 'Droenchoe (Tara) Practice',
    'subtitle' => 'A deeply spiritual journey for all practitioners',
    'intro_text' => 'Under the blessing and guidance of His Eminence Leytshog Lopen Rinpoche, the Bhutanese Centre offers Droenchoe (Tara) Practice classes for all practitioners.',
    'body_text' => "If you are interested and wanting to take your first step, we warmly welcome you to join this deeply spiritual journey.\n\nWe warmly welcome anyone wishing to learn and deepen their practice.",
    'schedule_text' => 'Classes are held every Saturday from 5:00 PM to 8:00 PM.',
    'monthly_text' => 'We also conduct monthly Droenchoe practice sessions.',
    'contact_text' => 'Contact Khenpo Sonam at 0434 522 720 or visit the Bhutanese Centre.',
    'imgUrl' => 'bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png',
];

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
    $stmt = $pdo->prepare("SELECT * FROM tara_content ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($row)) {
        foreach ($tara as $k => $v) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
                $tara[$k] = (string)$row[$k];
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
    <title>Droenchoe (Tara) Practice — BBCC</title>
    <meta name="description" content="Droenchoe (Tara) Practice classes at BBCC under the blessing and guidance of His Eminence Leytshog Lopen Rinpoche.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .tara-highlight {
            margin-top: 16px;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfeff 100%);
            color: #14532d;
            border: 1px solid #bbf7d0;
            border-radius: 16px;
            padding: 20px 22px;
            box-shadow: 0 10px 20px rgba(22, 101, 52, 0.08);
        }
        .tara-highlight p {
            margin: 0;
            font-size: 1.02rem;
            line-height: 1.75;
            font-weight: 600;
        }
        .tara-flow {
            margin-top: 20px;
            color: #4b5563;
            line-height: 1.9;
            font-size: 1rem;
            max-width: 860px;
        }
        .tara-info-grid {
            margin-top: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        .tara-info-card {
            border: 1px solid #d1fae5;
            background: #ffffff;
            border-radius: 14px;
            padding: 14px 16px;
        }
        .tara-info-card h3 {
            margin: 0 0 8px;
            font-size: 1rem;
            color: #065f46;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tara-info-card p {
            margin: 0;
            color: #374151;
            line-height: 1.6;
        }
        @media (max-width: 768px) {
            .tara-highlight { padding: 16px; }
            .tara-flow { font-size: .96rem; }
        }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-om"></i> <?= htmlspecialchars($tara['title']) ?></h1>
        <p class="bbcc-page-hero__subtitle"><?= htmlspecialchars($tara['subtitle']) ?></p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li><a href="services">Services</a></li>
            <li class="sep">/</li>
            <li>Droenchoe (Tara) Practice</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container" style="max-width:980px;">
        <div class="section-header fade-up" style="text-align:left;max-width:none;margin-bottom:20px;">
            <span class="section-badge"><i class="fa-solid fa-hands-praying"></i> Spiritual Practice Program</span>
            <h2>About Our <span><?= htmlspecialchars($tara['title']) ?></span></h2>
        </div>

        <div class="bbcc-about__image fade-up" style="max-width:680px;margin:0 0 20px;">
            <?= bbcc_render_responsive_picture(
                (string)$tara['imgUrl'],
                (string)$tara['title'],
                [
                    'sizes' => '(max-width: 991px) 100vw, 70vw',
                    'loading' => 'lazy',
                    'decoding' => 'async',
                    'widths' => [480, 768, 1200],
                ]
            ) ?>
        </div>

        <div class="tara-highlight fade-up">
            <p>
                <?= htmlspecialchars($tara['intro_text']) ?>
            </p>
        </div>

        <div class="tara-flow fade-up">
            <?php foreach (preg_split("/\r\n|\n|\r/", (string)$tara['body_text']) as $line): ?>
                <?php if (trim((string)$line) !== ''): ?>
                    <p><?= htmlspecialchars((string)$line) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="tara-info-grid fade-up">
            <div class="tara-info-card">
                <h3><i class="fa-regular fa-clock"></i> Class Time</h3>
                <p><?= htmlspecialchars($tara['schedule_text']) ?></p>
            </div>
            <div class="tara-info-card">
                <h3><i class="fa-solid fa-calendar-day"></i> Monthly Practice</h3>
                <p><?= htmlspecialchars($tara['monthly_text']) ?></p>
            </div>
            <div class="tara-info-card">
                <h3><i class="fa-solid fa-phone"></i> Contact</h3>
                <p><?= htmlspecialchars($tara['contact_text']) ?></p>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>
