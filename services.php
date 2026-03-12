<?php
require_once "include/config.php";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Services — BBCC</title>
    <meta name="description" content="Spiritual services, cultural preservation, and community events offered by BBCC Canberra.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<!-- Page Hero -->
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

<!-- Main Services -->
<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="section-header fade-up">
            <span class="section-badge"><i class="fa-solid fa-hands-praying"></i> What We Do</span>
            <h2>Our Core <span>Services</span></h2>
            <p>BBCC provides spiritual and pastoral services, rituals, and teachings to Bhutanese residents in Canberra.</p>
        </div>
        <div class="bbcc-services-extended">
            <!-- Spiritual Services -->
            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon">
                    <i class="fa-solid fa-hands-praying"></i>
                </div>
                <h3>Spiritual Services</h3>
                <p>Offering private household rituals, group teachings, meditation sessions, and Dharma teachings to support the spiritual wellbeing of our community members.</p>
            </div>
            <!-- Cultural Preservation -->
            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon">
                    <i class="fa-solid fa-language"></i>
                </div>
                <h3>Cultural Preservation</h3>
                <p>Weekly Bhutanese language and cultural classes, TARA practice, and Doenchoe sessions to preserve and promote Bhutanese identity within the ACT community.</p>
            </div>
            <!-- Community Events -->
            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <h3>Community Events</h3>
                <p>Organizing ceremonies, rituals, and religious practices on important Buddhist days, fostering unity, harmony, and a supportive community environment.</p>
            </div>
            <!-- Dzongkha Classes -->
            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>
                <h3>Dzongkha Classes</h3>
                <p>Structured weekly Dzongkha language classes for children and adults, helping preserve our national language and cultural heritage in the Australian diaspora.</p>
            </div>
            <!-- Pastoral Care -->
            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon">
                    <i class="fa-solid fa-hand-holding-heart"></i>
                </div>
                <h3>Pastoral Care</h3>
                <p>Providing compassionate support and spiritual guidance during life's challenges, including illness, bereavement, and times of personal difficulty.</p>
            </div>
            <!-- Meditation -->
            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon">
                    <i class="fa-solid fa-spa"></i>
                </div>
                <h3>Meditation & Dharma</h3>
                <p>Regular meditation sessions and Dharma teachings aimed at cultivating inner peace, mindfulness, and a deeper understanding of Buddhist philosophy.</p>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="bbcc-cta">
    <div class="bbcc-container" style="position:relative;z-index:1;">
        <h2>Ready to Get Started?</h2>
        <p>Register your family for Dzongkha classes or join our community programs today.</p>
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

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>
