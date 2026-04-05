<?php
require_once "include/config.php";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Pastoral Care — BBCC</title>
    <meta name="description" content="Pastoral care and compassionate support provided by BBCC during difficult life moments.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-hand-holding-heart"></i> Pastoral Care</h1>
        <p class="bbcc-page-hero__subtitle">Compassionate support through life's difficult moments</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li><li class="sep">/</li>
            <li><a href="services">Services</a></li><li class="sep">/</li>
            <li>Pastoral Care</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container" style="max-width:980px;">
        <div class="section-header fade-up" style="text-align:left;max-width:none;">
            <span class="section-badge"><i class="fa-solid fa-heart"></i> Care & Support</span>
            <h2>Walking With You In <span>Challenging Times</span></h2>
            <p>BBCC offers pastoral guidance and emotional-spiritual care for individuals and families in need.</p>
        </div>

        <div class="bbcc-services-extended" style="grid-template-columns:repeat(2,minmax(0,1fr));">
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-hospital"></i></div><h3>Illness Support</h3><p>Visits, prayers and spiritual encouragement for those facing health challenges.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-ribbon"></i></div><h3>Bereavement Care</h3><p>Compassionate guidance and rites for families experiencing loss.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-comments"></i></div><h3>Listening & Guidance</h3><p>Private conversations offering practical and spiritual reassurance.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-hands-holding-child"></i></div><h3>Family Support</h3><p>Support for families navigating difficult transitions and stress.</p></div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
