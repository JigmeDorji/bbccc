<?php
require_once "include/config.php";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Cultural Preservation — BBCC</title>
    <meta name="description" content="Cultural preservation activities by BBCC including language, traditions and community heritage programs.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-language"></i> Cultural Preservation</h1>
        <p class="bbcc-page-hero__subtitle">Keeping Bhutanese language, traditions and identity alive</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li><li class="sep">/</li>
            <li><a href="services">Services</a></li><li class="sep">/</li>
            <li>Cultural Preservation</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container" style="max-width:980px;">
        <div class="section-header fade-up" style="text-align:left;max-width:none;">
            <span class="section-badge"><i class="fa-solid fa-flag"></i> Community Heritage</span>
            <h2>Preserving Bhutanese <span>Culture</span></h2>
            <p>We nurture the next generation through language, traditions, and community-centered cultural education.</p>
        </div>

        <div class="bbcc-services-extended" style="grid-template-columns:repeat(2,minmax(0,1fr));">
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-chalkboard-user"></i></div><h3>Language Learning</h3><p>Dzongkha literacy and conversation support for children and adults.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-people-group"></i></div><h3>Cultural Programs</h3><p>Community sessions focused on Bhutanese customs and social values.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-music"></i></div><h3>Traditional Arts</h3><p>Exposure to songs, recitations, and traditional forms of expression.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-child-reaching"></i></div><h3>Youth Engagement</h3><p>Activities that connect younger members with heritage and identity.</p></div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
