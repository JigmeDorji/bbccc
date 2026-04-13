<?php
require_once "include/config.php";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Services — BBCC</title>
    <meta name="description" content="Spiritual services, cultural preservation, pastoral support and community events offered by BBCC Canberra.">
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

        <hr style="margin:42px 0;border:none;border-top:1px solid #e5e7eb;">

        <div class="section-header fade-up" style="margin-top:0;">
            <span class="section-badge"><i class="fa-solid fa-school"></i> Language Program</span>
            <h2>Bhutanese Language and <span>Culture School</span></h2>
            <p>
                Structured weekly classes that teach Dzongkha language, Bhutanese culture, and community values for children and adults.
            </p>
        </div>

        <div class="bbcc-services-extended" style="grid-template-columns:minmax(0,1fr);max-width:760px;margin:0 auto;">
            <div class="bbcc-service-card-ext fade-up" style="text-align:left;">
                <div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-chalkboard-user"></i></div>
                <h3><a href="bhutanese-language-and-culture-school" style="color:inherit;text-decoration:none;">Bhutanese Language and Culture School</a></h3>
                <p>Comprehensive language and culture learning program covering Dzongkha reading, writing, speaking, Bhutanese traditions, and values.</p>
                <a href="bhutanese-language-and-culture-school" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;color:#881b12;text-decoration:none;">View School Details <i class="fa-solid fa-arrow-right"></i></a>
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
