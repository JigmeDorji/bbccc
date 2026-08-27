<?php
require_once "include/config.php";
require_once "include/image_helpers.php";

$servicePrograms = [
    ['icon' => 'fa-chalkboard-user', 'image_url' => '', 'title' => 'Bhutanese Language and Culture School', 'description' => 'Comprehensive language and culture learning program covering Dzongkha reading, writing, speaking, Bhutanese traditions, and values.', 'link_url' => 'bhutanese-language-and-culture-school'],
    ['icon' => 'fa-om', 'image_url' => '', 'title' => 'Ched Tshog Singye Tsewa', 'description' => 'A community practice of offering and blessing, open to new and experienced practitioners.', 'link_url' => 'ched-tshog-singye-tsewa'],
    ['icon' => 'fa-om', 'image_url' => '', 'title' => 'Droenchoe (Tara) Practice', 'description' => 'Weekly Saturday practice under guidance, welcoming new and experienced practitioners on a spiritual learning journey.', 'link_url' => 'droenchoe-tara-practice'],
];
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
    $rows = $pdo->query("SELECT icon, image_url, title, description, link_url FROM service_programs ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $servicePrograms = $rows;
    }
} catch (Throwable $e) {
    // keep defaults above
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Spiritual &amp; Cultural Services Canberra | BBCC</title>
    <meta name="description" content="Explore Buddhist prayers, meditation, spiritual services, pastoral care, Dzongkha classes and cultural programs at the Bhutanese Buddhist and Cultural Centre Canberra.">
    <link rel="canonical" href="https://www.bhutanesecentre.org/services">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-hands-praying"></i> Our Services</h1>
        <p class="bbcc-page-hero__subtitle">Spiritual guidance, cultural preservation and pastoral support</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li>Services</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="section-header fade-up">
            <span class="section-badge"><i class="fa-solid fa-hands-praying"></i> Weekly Programs</span>
            <h2>Classes and <span>Practices</span></h2>
            <p>
                Weekly classes and spiritual practices for children, families and the wider community.
            </p>
        </div>

        <div class="bbcc-services-extended">
            <?php foreach ($servicePrograms as $sp): ?>
                <div class="bbcc-service-card-ext fade-up" style="text-align:left;">
                    <div class="bbcc-service-card-ext__icon">
                        <?php if (!empty($sp['image_url'])): ?>
                            <?= bbcc_render_responsive_picture((string)$sp['image_url'], (string)$sp['title'], ['sizes' => '80px', 'loading' => 'lazy', 'widths' => [80, 160]]) ?>
                        <?php else: ?>
                            <i class="fa-solid <?= htmlspecialchars((string)$sp['icon']) ?>"></i>
                        <?php endif; ?>
                    </div>
                    <h3><a href="<?= htmlspecialchars((string)$sp['link_url']) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars((string)$sp['title']) ?></a></h3>
                    <p><?= htmlspecialchars((string)$sp['description']) ?></p>
                    <a href="<?= htmlspecialchars((string)$sp['link_url']) ?>" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;color:#881b12;text-decoration:none;">View Details <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            <?php endforeach; ?>
        </div>

        <hr style="margin:42px 0;border:none;border-top:1px solid #e5e7eb;">

        <div class="section-header fade-up" style="margin-top:0;">
            <span class="section-badge"><i class="fa-solid fa-hands-praying"></i> Core Services</span>
            <h2>Our Four <span>Core Services</span></h2>
            <p>BBCC provides key spiritual and community services for Bhutanese families and the wider Canberra community.</p>
        </div>

        <div class="bbcc-services-extended">
            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-hands-praying"></i></div>
                <h3><a href="spiritual-services" style="color:inherit;text-decoration:none;">Spiritual Services</a></h3>
                <p>Household rituals, pujas, group teachings, meditation sessions, and Dharma guidance.</p>
                <a href="spiritual-services" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;color:#881b12;text-decoration:none;">View Details <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-language"></i></div>
                <h3><a href="cultural-preservation" style="color:inherit;text-decoration:none;">Cultural Preservation</a></h3>
                <p>Programs and activities that preserve Bhutanese identity, language, customs and traditions.</p>
                <a href="cultural-preservation" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;color:#881b12;text-decoration:none;">View Details <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-hand-holding-heart"></i></div>
                <h3><a href="pastoral-care" style="color:inherit;text-decoration:none;">Pastoral Care</a></h3>
                <p>Compassionate support for illness, bereavement, family hardship and personal challenges.</p>
                <a href="pastoral-care" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;color:#881b12;text-decoration:none;">View Details <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-calendar-days"></i></div>
                <h3><a href="community-events" style="color:inherit;text-decoration:none;">Community Events</a></h3>
                <p>Religious observances and cultural events that foster unity, harmony and community connection.</p>
                <a href="community-events" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;color:#881b12;text-decoration:none;">View Details <i class="fa-solid fa-arrow-right"></i></a>
            </div>
        </div>

    </div>
</section>

<section class="bbcc-cta">
    <div class="bbcc-container" style="position:relative;z-index:1;">
        <div class="bbcc-cta-grid">
            <div class="bbcc-cta-col">
                <h2>Register for Dzongkha class</h2>
                <p>Register your children for Dzongkha classes and join services that support culture, language, and community wellbeing.</p>
                <div class="bbcc-cta-actions">
                    <a href="parentAccountSetup" class="bbcc-btn bbcc-btn--white">
                        <i class="fa-solid fa-user-plus"></i> Register Now
                    </a>
                    <a href="contact-us" class="bbcc-btn bbcc-btn--outline" style="border-color:rgba(255,255,255,.4);color:#fff;">
                        Contact Us <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="bbcc-cta-col bbcc-cta-col--patron">
                <h3><i class="fa-solid fa-hands-holding-circle"></i> Become a Patron</h3>
                <p>Support the Bhutanese Buddhist and Cultural Centre Canberra as a patron and help sustain spiritual and cultural activities for our community.</p>
                <a href="patronRegistration" class="bbcc-btn bbcc-btn--white">
                    <i class="fa-solid fa-heart"></i> Join as Patron
                </a>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>
