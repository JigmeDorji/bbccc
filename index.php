<?php
require_once "include/config.php";
require_once "include/image_helpers.php";

$message = "";
$menus = [];
$banners = [];
$schoolContent = [];
$aboutContent = [];
$schoolStats = [
    'heading' => 'BLCS Snapshot - Term 1, 2026',
    'students' => '80+',
    'teachers' => '8',
    'campuses' => '2',
    'year_levels' => 'Age 6 years and above',
];
$sponsorIcons = [
    'icon_one' => 'fa-calendar-day',
    'icon_two' => 'fa-moon',
    'icon_three' => 'fa-spa',
];
$sponsorImages = [
    'image_one' => '',
    'image_two' => '',
    'image_three' => '',
];
$sponsorText = [
    'intro_text' => "We warmly welcome sponsorship from individuals, families, and groups to help sustain these monthly rituals at the Centre.\nThe following monthly rituals are available for sponsorship.\nFor sponsorship availability and further details, please contact Khenpo Sonam or Namgay (BBCC Program Coordinator) at 0434 522 720.",
    'title_one' => '10th Day of Bhutanese Month (Tshe Chutham)',
    'title_two' => '15th Day of Bhutanese Month (Tshe Chenga)',
    'title_three' => 'Monthly Tara and Menlha Dungdrup',
    'date_one' => '10th day of each Bhutanese month (Tshe Chutham).',
    'date_two' => '15th day of each Bhutanese month (Tshe Chenga).',
    'date_three' => 'Monthly (as scheduled by the Centre).',
];

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    // Fetch banner data
    $stmt = $pdo->prepare("SELECT * FROM banner ORDER BY COALESCE(sort_order, id) ASC, id ASC");
    $stmt->execute();
    $banners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM about ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $aboutContent = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sponsor_settings (
            id INT PRIMARY KEY,
            icon_one VARCHAR(60) NULL,
            icon_two VARCHAR(60) NULL,
            icon_three VARCHAR(60) NULL,
            image_one VARCHAR(255) NULL,
            image_two VARCHAR(255) NULL,
            image_three VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $extraCols = [
        'image_one' => "ALTER TABLE sponsor_settings ADD COLUMN image_one VARCHAR(255) NULL AFTER icon_three",
        'image_two' => "ALTER TABLE sponsor_settings ADD COLUMN image_two VARCHAR(255) NULL AFTER image_one",
        'image_three' => "ALTER TABLE sponsor_settings ADD COLUMN image_three VARCHAR(255) NULL AFTER image_two",
        'intro_text' => "ALTER TABLE sponsor_settings ADD COLUMN intro_text TEXT NULL AFTER image_three",
        'title_one' => "ALTER TABLE sponsor_settings ADD COLUMN title_one VARCHAR(255) NULL AFTER intro_text",
        'title_two' => "ALTER TABLE sponsor_settings ADD COLUMN title_two VARCHAR(255) NULL AFTER title_one",
        'title_three' => "ALTER TABLE sponsor_settings ADD COLUMN title_three VARCHAR(255) NULL AFTER title_two",
        'date_one' => "ALTER TABLE sponsor_settings ADD COLUMN date_one VARCHAR(255) NULL AFTER title_three",
        'date_two' => "ALTER TABLE sponsor_settings ADD COLUMN date_two VARCHAR(255) NULL AFTER date_one",
        'date_three' => "ALTER TABLE sponsor_settings ADD COLUMN date_three VARCHAR(255) NULL AFTER date_two",
    ];
    foreach ($extraCols as $col => $sql) {
        $chk = $pdo->query("SHOW COLUMNS FROM sponsor_settings LIKE " . $pdo->quote($col));
        if (!$chk || !$chk->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($sql);
        }
    }
    $stmt = $pdo->prepare("SELECT * FROM sponsor_settings WHERE id = 1 LIMIT 1");
    $stmt->execute();
    $iconRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['icon_one', 'icon_two', 'icon_three'] as $k) {
        $v = trim((string)($iconRow[$k] ?? ''));
        if ($v !== '' && preg_match('/^fa-[a-z0-9-]+$/', $v)) {
            $sponsorIcons[$k] = $v;
        }
    }
    foreach (['image_one', 'image_two', 'image_three'] as $k) {
        $sponsorImages[$k] = trim((string)($iconRow[$k] ?? ''));
    }
    foreach (array_keys($sponsorText) as $k) {
        $v = trim((string)($iconRow[$k] ?? ''));
        if ($v !== '') $sponsorText[$k] = $v;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS school_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            description TEXT NULL,
            imgUrl VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $stmt = $pdo->prepare("SELECT * FROM school_content ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $schoolContent = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($schoolContent)) {
        $schoolStats['heading'] = trim((string)($schoolContent['stats_heading'] ?? '')) !== '' ? (string)$schoolContent['stats_heading'] : $schoolStats['heading'];
        $schoolStats['students'] = trim((string)($schoolContent['students_count'] ?? '')) !== '' ? (string)$schoolContent['students_count'] : $schoolStats['students'];
        $schoolStats['teachers'] = trim((string)($schoolContent['teachers_count'] ?? '')) !== '' ? (string)$schoolContent['teachers_count'] : $schoolStats['teachers'];
        $schoolStats['campuses'] = trim((string)($schoolContent['campuses_count'] ?? '')) !== '' ? (string)$schoolContent['campuses_count'] : $schoolStats['campuses'];
        $schoolStats['year_levels'] = trim((string)($schoolContent['year_levels'] ?? '')) !== '' ? (string)$schoolContent['year_levels'] : $schoolStats['year_levels'];
    }

    // Fetch menu/event data for upcoming items only.
    // Items with no date are kept visible for backward compatibility.
    $stmt = $pdo->prepare("
        SELECT *
        FROM menu
        WHERE eventStartDateTime IS NULL
           OR eventStartDateTime = ''
           OR eventStartDateTime >= NOW()
        ORDER BY
            CASE WHEN eventStartDateTime IS NULL OR eventStartDateTime = '' THEN 1 ELSE 0 END,
            eventStartDateTime ASC,
            id DESC
    ");
    $stmt->execute();
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Buddhist Temple Canberra | Bhutanese Buddhist &amp; Cultural Centre</title>
    <meta name="description" content="Bhutanese Buddhist and Cultural Centre Canberra (BBCC), a Buddhist temple in Canberra offering spiritual services, Dzongkha classes, cultural programs and community support.">
    <meta name="keywords" content="Buddhist Temple Canberra, Buddhist Centre, Bhutanese Centre, Bhutanese Buddhist and Cultural Centre Canberra, Buddhist Canberra, Bhutanese in Canberra">
    <meta property="og:title" content="Buddhist Temple Canberra | Bhutanese Buddhist &amp; Cultural Centre">
    <meta property="og:description" content="Buddhist temple and Bhutanese Buddhist centre in Canberra offering spiritual guidance, cultural preservation and community services.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.bhutanesecentre.org/">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "BuddhistTemple",
      "name": "Bhutanese Buddhist and Cultural Centre Canberra",
      "alternateName": "BBCC",
      "url": "https://www.bhutanesecentre.org/",
      "description": "Buddhist temple in Canberra offering spiritual services, Dzongkha classes and Bhutanese cultural programs.",
      "areaServed": "Canberra, ACT"
    }
    </script>
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .blcs-metrics-panel {
            margin-top: 22px;
            padding: 18px;
            border-radius: 18px;
            background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 40%, #fef3c7 100%);
            border: 1px solid #fed7aa;
            box-shadow: 0 14px 30px rgba(180, 83, 9, 0.12);
        }
        .blcs-metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(120px, 1fr));
            gap: 12px;
        }
        .blcs-metrics-heading {
            margin: 0 0 12px;
            font-size: .94rem;
            font-weight: 700;
            letter-spacing: .2px;
            color: #7c2d12;
        }
        .blcs-metric-item {
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(251, 191, 36, 0.35);
            border-radius: 14px;
            padding: 12px 10px;
            text-align: center;
        }
        .blcs-metric-value {
            display: block;
            font-size: 1.35rem;
            font-weight: 800;
            color: #9a3412;
            line-height: 1.2;
            letter-spacing: 0.2px;
        }
        .blcs-metric-label {
            display: block;
            margin-top: 4px;
            font-size: .78rem;
            color: #7c2d12;
            text-transform: uppercase;
            letter-spacing: .8px;
            font-weight: 700;
        }
        .bbcc-service-card-ext__icon .bbcc-team-card__photo {
            width: 96px;
            height: 96px;
            margin: 0 auto;
            flex: 0 0 96px;
        }
        @media (max-width: 767.98px) {
            .blcs-metrics-grid {
                grid-template-columns: repeat(2, minmax(120px, 1fr));
            }
        }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<!-- ═══ HERO SLIDER ═══ -->
<section class="bbcc-hero" id="bbccHero">
    <?php if (!empty($banners)): ?>
    <?php foreach ($banners as $i => $banner): ?>
    <div class="bbcc-hero__slide <?= $i === 0 ? 'active' : '' ?>">
        <div class="bbcc-hero__container">
            <div class="bbcc-hero__content">
                <span class="hero-badge"><i class="fa-solid fa-dharma-wheel"></i> Welcome to BBCC</span>
                <h1><?= htmlspecialchars($banner['title']) ?></h1>
                <p><?= htmlspecialchars($banner['subtitle']) ?></p>
                <div class="bbcc-hero__actions">
                    <a href="about-us" class="bbcc-btn bbcc-btn--outline" aria-label="Learn about the Bhutanese Buddhist and Cultural Centre">
                        Learn About BBCC <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="bbcc-hero__image">
                <?= bbcc_render_responsive_picture(
                    (string)$banner['imgUrl'],
                    (string)$banner['title'],
                    [
                        'sizes' => '(max-width: 991px) 100vw, 45vw',
                        'loading' => 'eager',
                        'decoding' => 'async',
                        'fetchpriority' => ($i === 0 ? 'high' : 'auto'),
                        'widths' => [640, 960, 1280, 1600],
                    ]
                ) ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="bbcc-hero__dots">
        <?php foreach ($banners as $i => $banner): ?>
        <span class="dot <?= $i === 0 ? 'active' : '' ?>" data-slide="<?= $i ?>"></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<!-- ═══ FEATURES ═══ -->
<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="bbcc-about" style="margin-bottom:54px;">
            <div class="bbcc-about__image fade-up">
                <?php if (!empty($schoolContent['imgUrl'])): ?>
                <?= bbcc_render_responsive_picture(
                    (string)$schoolContent['imgUrl'],
                    'Bhutanese Language and Culture School',
                    [
                        'sizes' => '(max-width: 991px) 100vw, 45vw',
                        'loading' => 'lazy',
                        'decoding' => 'async',
                        'widths' => [480, 768, 1200],
                    ]
                ) ?>
                <?php else: ?>
                <?= bbcc_render_responsive_picture(
                    'bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png',
                    'Bhutanese Language and Culture School',
                    [
                        'sizes' => '(max-width: 991px) 100vw, 45vw',
                        'loading' => 'lazy',
                        'decoding' => 'async',
                        'widths' => [480, 768, 1200],
                    ]
                ) ?>
                <?php endif; ?>
            </div>
            <div class="bbcc-about__content fade-up">
                <span class="section-badge"><i class="fa-solid fa-school"></i> Language Program</span>
                <h2>Bhutanese Language and <span>Culture School</span></h2>
                <?php if (!empty($schoolContent['description'])): ?>
                <p><?= nl2br(htmlspecialchars($schoolContent['description'])) ?></p>
                <?php else: ?>
                <p>Weekly structured classes in Dzongkha language, Bhutanese culture, and community values for children and adults.</p>
                <?php endif; ?>
                <div class="blcs-metrics-panel fade-up">
                    <p class="blcs-metrics-heading"><?= htmlspecialchars((string)$schoolStats['heading']) ?></p>
                    <div class="blcs-metrics-grid">
                        <div class="blcs-metric-item">
                            <span class="blcs-metric-value"><?= htmlspecialchars((string)$schoolStats['students']) ?></span>
                            <span class="blcs-metric-label">Students Enrolled</span>
                        </div>
                        <div class="blcs-metric-item">
                            <span class="blcs-metric-value"><?= htmlspecialchars((string)$schoolStats['teachers']) ?></span>
                            <span class="blcs-metric-label">Teachers</span>
                        </div>
                        <div class="blcs-metric-item">
                            <span class="blcs-metric-value"><?= htmlspecialchars((string)$schoolStats['campuses']) ?></span>
                            <span class="blcs-metric-label">Campuses</span>
                        </div>
                        <div class="blcs-metric-item">
                            <span class="blcs-metric-value"><?= htmlspecialchars((string)$schoolStats['year_levels']) ?></span>
                            <span class="blcs-metric-label">Age Group</span>
                        </div>
                    </div>
                </div>
                <a href="bhutanese-language-and-culture-school" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm" style="margin-top:16px;">
                    View School Details <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</section>

<section class="bbcc-section bbcc-section--gray">
    <div class="bbcc-container">
        <div class="section-header fade-up">
            <span class="section-badge"><i class="fa-solid fa-hands-praying"></i> What We Do</span>
            <h2>Our Core <span>Services</span></h2>
            <p>BBCC provides spiritual and pastoral services, rituals, and teachings to Bhutanese residents in Canberra.</p>
        </div>
        <div class="bbcc-features">
            <div class="bbcc-feature-card fade-up">
                <div class="bbcc-feature-card__icon bbcc-feature-card__icon--brand">
                    <i class="fa-solid fa-hands-praying"></i>
                </div>
                <h3>Spiritual Services</h3>
                <p>Offering private household rituals, group teachings, meditation sessions, and Dharma teachings to support the spiritual wellbeing of our community members.</p>
            </div>
            <div class="bbcc-feature-card fade-up">
                <div class="bbcc-feature-card__icon bbcc-feature-card__icon--gold">
                    <i class="fa-solid fa-language"></i>
                </div>
                <h3>Cultural Preservation</h3>
                <p>Weekly Bhutanese language and cultural classes, TARA practice, and Doenchoe sessions to preserve and promote Bhutanese identity within the ACT community.</p>
            </div>
            <div class="bbcc-feature-card fade-up">
                <div class="bbcc-feature-card__icon bbcc-feature-card__icon--info">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <h3>Community Events</h3>
                <p>Organizing ceremonies, rituals, and religious practices on important Buddhist days, fostering unity, harmony, and a supportive community environment.</p>
            </div>
        </div>
    </div>
</section>

<!-- ═══ SPONSORSHIP ═══ -->
<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="section-header fade-up" style="text-align:left;max-width:none;margin-bottom:22px;">
            <span class="section-badge"><i class="fa-solid fa-hand-holding-heart"></i> Opportunity to Sponsor</span>
            <h2>Support Monthly <span>Ritual Programs</span></h2>
            <?= nl2br(htmlspecialchars((string)$sponsorText['intro_text'])) ?>
        </div>

        <div class="bbcc-services-extended" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
            <div class="bbcc-service-card-ext fade-up" style="text-align:left;">
                <div class="bbcc-service-card-ext__icon">
                    <?php if ($sponsorImages['image_one'] !== ''): ?>
                        <div class="bbcc-team-card__photo">
                            <img src="<?= htmlspecialchars((string)$sponsorImages['image_one']) ?>" alt="Tshe Chutham sponsor">
                        </div>
                    <?php else: ?>
                        <i class="fa-solid <?= htmlspecialchars((string)$sponsorIcons['icon_one']) ?>"></i>
                    <?php endif; ?>
                </div>
                <h3><?= htmlspecialchars((string)$sponsorText['title_one']) ?></h3>
                <p><strong>Date:</strong> <?= htmlspecialchars((string)$sponsorText['date_one']) ?></p>
                <a href="program-tshe-chutham" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm" style="margin-top:12px;">
                    View More <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <div class="bbcc-service-card-ext fade-up" style="text-align:left;">
                <div class="bbcc-service-card-ext__icon">
                    <?php if ($sponsorImages['image_two'] !== ''): ?>
                        <div class="bbcc-team-card__photo">
                            <img src="<?= htmlspecialchars((string)$sponsorImages['image_two']) ?>" alt="Tshe Chenga sponsor">
                        </div>
                    <?php else: ?>
                        <i class="fa-solid <?= htmlspecialchars((string)$sponsorIcons['icon_two']) ?>"></i>
                    <?php endif; ?>
                </div>
                <h3><?= htmlspecialchars((string)$sponsorText['title_two']) ?></h3>
                <p><strong>Date:</strong> <?= htmlspecialchars((string)$sponsorText['date_two']) ?></p>
                <a href="program-tshe-chenga" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm" style="margin-top:12px;">
                    View More <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <div class="bbcc-service-card-ext fade-up" style="text-align:left;">
                <div class="bbcc-service-card-ext__icon">
                    <?php if ($sponsorImages['image_three'] !== ''): ?>
                        <div class="bbcc-team-card__photo">
                            <img src="<?= htmlspecialchars((string)$sponsorImages['image_three']) ?>" alt="Tara and Menlha sponsor">
                        </div>
                    <?php else: ?>
                        <i class="fa-solid <?= htmlspecialchars((string)$sponsorIcons['icon_three']) ?>"></i>
                    <?php endif; ?>
                </div>
                <h3><?= htmlspecialchars((string)$sponsorText['title_three']) ?></h3>
                <p><strong>Date:</strong> <?= htmlspecialchars((string)$sponsorText['date_three']) ?></p>
                <a href="program-tara-menlha" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm" style="margin-top:12px;">
                    View More <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ═══ ABOUT ═══ -->
<section class="bbcc-section bbcc-section--gray" id="about">
    <div class="bbcc-container">
        <div class="bbcc-about">
            <div class="bbcc-about__image fade-up">
                <?php if (!empty($aboutContent['imgUrl'])): ?>
                <?= bbcc_render_responsive_picture(
                    (string)$aboutContent['imgUrl'],
                    'Beautiful view of Bhutan',
                    [
                        'sizes' => '(max-width: 991px) 100vw, 45vw',
                        'loading' => 'lazy',
                        'decoding' => 'async',
                        'widths' => [480, 768, 1200],
                    ]
                ) ?>
                <?php else: ?>
                <?= bbcc_render_responsive_picture(
                    'bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png',
                    'Beautiful view of Bhutan',
                    [
                        'sizes' => '(max-width: 991px) 100vw, 45vw',
                        'loading' => 'lazy',
                        'decoding' => 'async',
                        'widths' => [480, 768, 1200],
                    ]
                ) ?>
                <?php endif; ?>
                <div class="experience-badge">
                    <span class="num">BBCC</span>
                    <span class="label">Canberra, ACT</span>
                </div>
            </div>
            <div class="bbcc-about__content fade-up">
                <span class="section-badge"><i class="fa-solid fa-circle-info"></i> About Us</span>
                <h2>About <span>BBCC</span></h2>
                <p class="subtitle">Serving the Bhutanese Community in Canberra</p>
                <p>The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to providing spiritual guidance, cultural preservation, and pastoral support to Bhutanese residents in Canberra and nearby NSW towns.</p>
                <p>Our focus is on fostering unity, harmony, and the preservation of Bhutanese identity within a diverse community.</p>
                <a href="about-us" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm" style="margin-top:16px;" aria-label="Read about the mission and story of BBCC">
                    Read About Our Mission <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ═══ EVENTS ═══ -->
<?php if (!empty($menus)): ?>
<section class="bbcc-section bbcc-section--gray">
    <div class="bbcc-container">
        <div class="section-header fade-up">
            <span class="section-badge"><i class="fa-solid fa-calendar-check"></i> Events</span>
            <h2>Upcoming <span>Events</span></h2>
            <p>Stay connected with our community through ceremonies, teachings, and cultural celebrations.</p>
        </div>
        <div class="bbcc-events-grid">
            <?php foreach ($menus as $menu): ?>
            <?php
                $shortDetail = mb_strimwidth(strip_tags($menu['menuDetail']), 0, 140, '...');
                $formattedDate = "No Date Set";
                if (!empty($menu['eventStartDateTime'])) {
                    $formattedDate = date("d M Y – g:i A", strtotime($menu['eventStartDateTime']));
                }
            ?>
            <a href="event_detail?id=<?= $menu['id'] ?>" class="bbcc-event-card fade-up">
                <div class="bbcc-event-card__image">
                    <?= bbcc_render_responsive_picture(
                        (string)$menu['menuImgUrl'],
                        (string)$menu['menuName'],
                        [
                            'sizes' => '(max-width: 576px) 100vw, (max-width: 991px) 50vw, 33vw',
                            'loading' => 'lazy',
                            'decoding' => 'async',
                            'widths' => [360, 640, 960],
                        ]
                    ) ?>
                </div>
                <div class="bbcc-event-card__body">
                    <span class="bbcc-event-card__date">
                        <i class="fa-regular fa-calendar"></i> <?= $formattedDate ?>
                    </span>
                    <h3><?= htmlspecialchars($menu['menuName']) ?></h3>
                    <p><?= htmlspecialchars($shortDetail) ?></p>
                    <span class="bbcc-event-card__link">
                        Read More <i class="fa-solid fa-arrow-right"></i>
                    </span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <div style="text-align:center;margin-top:48px;">
            <a href="events" class="bbcc-btn bbcc-btn--outline">
                View All Events <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ CTA ═══ -->
<section class="bbcc-cta">
    <div class="bbcc-container" style="position:relative;z-index:1;">
        <div class="bbcc-cta-grid">
            <div class="bbcc-cta-col">
                <h2>Register for Dzongkha class</h2>
                <p>Register for Dzongkha classes, cultural programs, and spiritual services offered by BBCC in Canberra.</p>
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

<!-- Footer -->
<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

<!-- Hero Slider JS -->
<script>
(function() {
    var slides = document.querySelectorAll(".bbcc-hero__slide");
    var dots = document.querySelectorAll(".bbcc-hero__dots .dot");
    if (!slides.length) return;
    var idx = 0;

    function goTo(n) {
        slides[idx].classList.remove("active");
        if (dots[idx]) dots[idx].classList.remove("active");
        idx = n % slides.length;
        slides[idx].classList.add("active");
        if (dots[idx]) dots[idx].classList.add("active");
    }

    dots.forEach(function(d, i) {
        d.addEventListener("click", function() { goTo(i); });
    });

    setInterval(function() { goTo(idx + 1); }, 7000);
})();
</script>

</body>
</html>
