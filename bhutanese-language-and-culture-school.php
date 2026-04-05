<?php
require_once "include/config.php";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Bhutanese Language and Culture School — BBCC</title>
    <meta name="description" content="Bhutanese Language and Culture School at BBCC teaching Dzongkha, Bhutanese culture and values.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-school"></i> Bhutanese Language and Culture School</h1>
        <p class="bbcc-page-hero__subtitle">Teaching Dzongkha, Bhutanese culture and values</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li><a href="services">Services</a></li>
            <li class="sep">/</li>
            <li>Bhutanese Language and Culture School</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container" style="max-width:980px;">
        <div class="section-header fade-up" style="text-align:left;max-width:none;">
            <span class="section-badge"><i class="fa-solid fa-book-open"></i> Program Overview</span>
            <h2>About Our <span>School Program</span></h2>
            <p>
                The Bhutanese Language and Cultural School provides structured weekly learning for children and adults,
                with a focus on Dzongkha language, Bhutanese culture, and community values.
            </p>
        </div>

        <div class="bbcc-services-extended" style="grid-template-columns:repeat(2,minmax(0,1fr));">
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-language"></i></div><h3>Dzongkha Language</h3><p>Reading, writing, speaking and pronunciation skills in progressive levels.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-landmark"></i></div><h3>Bhutanese Culture</h3><p>Traditions, stories, etiquette, and cultural practices taught in practical context.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-heart"></i></div><h3>Community Values</h3><p>Respect, compassion, discipline and shared responsibility within community life.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-users"></i></div><h3>Inclusive Learning</h3><p>Programs designed for different age groups and learning levels.</p></div>
        </div>

        <div style="margin-top:32px;padding:24px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;" class="fade-up">
            <h3 style="margin:0 0 12px;font-size:1.2rem;">Enrollment</h3>
            <p style="margin:0 0 14px;color:#4b5563;">
                Create a parent account to register your child for classes. Our team will provide level placement and class schedule details.
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <a href="parentAccountSetup" class="bbcc-btn bbcc-btn--primary">
                    <i class="fa-solid fa-user-plus"></i> Register Now
                </a>
                <a href="contact-us" class="bbcc-btn bbcc-btn--outline">
                    Contact Us <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>
