<?php
require_once "include/config.php";

$message = "";
$menus = [];
$banners = [];

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch banner data
    $stmt = $pdo->prepare("SELECT * FROM banner LIMIT 2");
    $stmt->execute();
    $banners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch menu/event data
    $stmt = $pdo->prepare("SELECT * FROM menu");
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
    <title>Bhutanese Buddhist &amp; Cultural Centre — Canberra</title>
    <meta name="description" content="BBCC provides spiritual services, cultural programs, and community engagement for Bhutanese residents in Canberra.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
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
                    <a href="parentAccountSetup" class="bbcc-btn bbcc-btn--primary">
                        <i class="fa-solid fa-user-plus"></i> Register Now
                    </a>
                    <a href="about-us" class="bbcc-btn bbcc-btn--outline">
                        Learn More <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="bbcc-hero__image">
                <img src="<?= htmlspecialchars($banner['imgUrl']) ?>" alt="<?= htmlspecialchars($banner['title']) ?>">
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

<!-- ═══ ABOUT ═══ -->
<section class="bbcc-section bbcc-section--gray" id="about">
    <div class="bbcc-container">
        <div class="bbcc-about">
            <div class="bbcc-about__image fade-up">
                <img src="bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png" alt="Beautiful view of Bhutan">
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
                <a href="about-us" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm" style="margin-top:16px;">
                    Read More <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ═══ EVENTS ═══ -->
<?php if (!empty($menus)): ?>
<section class="bbcc-section">
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
                    <img src="<?= htmlspecialchars($menu['menuImgUrl']) ?>" alt="<?= htmlspecialchars($menu['menuName']) ?>">
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
        <h2>Join Our Community</h2>
        <p>Register for Dzongkha classes, cultural programs, and spiritual services offered by BBCC in Canberra.</p>
        <div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;">
            <a href="parentAccountSetup" class="bbcc-btn bbcc-btn--white">
                <i class="fa-solid fa-user-plus"></i> Register Now
            </a>
            <a href="contact-us" class="bbcc-btn bbcc-btn--outline" style="border-color:rgba(255,255,255,.4);color:#fff;">
                Contact Us <i class="fa-solid fa-arrow-right"></i>
            </a>
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














