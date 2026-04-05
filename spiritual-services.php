<?php
require_once "include/config.php";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Spiritual Services — BBCC</title>
    <meta name="description" content="Spiritual services at BBCC Canberra including pujas, rituals, meditation and Dharma teachings.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-hands-praying"></i> Spiritual Services</h1>
        <p class="bbcc-page-hero__subtitle">Guidance, rituals and Dharma practice for spiritual wellbeing</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li><li class="sep">/</li>
            <li><a href="services">Services</a></li><li class="sep">/</li>
            <li>Spiritual Services</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container" style="max-width:980px;">
        <div class="section-header fade-up" style="text-align:left;max-width:none;">
            <span class="section-badge"><i class="fa-solid fa-dharmachakra"></i> What We Offer</span>
            <h2>Spiritual <span>Support</span></h2>
            <p>Our spiritual services help individuals and families remain connected to Buddhist teachings and daily practice.</p>
        </div>

        <div class="bbcc-services-extended" style="grid-template-columns:repeat(2,minmax(0,1fr));">
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-house"></i></div><h3>Household Rituals</h3><p>Pujas and blessings for homes, new beginnings, and important life milestones.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-person-praying"></i></div><h3>Prayer Services</h3><p>Prayer and spiritual ceremonies for wellbeing, healing, and protection.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-spa"></i></div><h3>Meditation Sessions</h3><p>Guided meditation to cultivate calmness, mindfulness, and inner clarity.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-book"></i></div><h3>Dharma Teachings</h3><p>Regular teachings and discussions to deepen understanding of Buddhist practice.</p></div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
