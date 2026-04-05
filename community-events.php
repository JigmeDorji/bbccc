<?php
require_once "include/config.php";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Community Events — BBCC</title>
    <meta name="description" content="Community events and observances organized by BBCC to strengthen unity and shared values.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-calendar-days"></i> Community Events</h1>
        <p class="bbcc-page-hero__subtitle">Religious and cultural gatherings that build unity</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li><li class="sep">/</li>
            <li><a href="services">Services</a></li><li class="sep">/</li>
            <li>Community Events</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container" style="max-width:980px;">
        <div class="section-header fade-up" style="text-align:left;max-width:none;">
            <span class="section-badge"><i class="fa-solid fa-bell"></i> Gatherings</span>
            <h2>Celebrating Together As A <span>Community</span></h2>
            <p>Our events create space for learning, worship, celebration and community connection across generations.</p>
        </div>

        <div class="bbcc-services-extended" style="grid-template-columns:repeat(2,minmax(0,1fr));">
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-moon"></i></div><h3>Religious Days</h3><p>Observance of important Buddhist calendar days and practices.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-people-roof"></i></div><h3>Community Gatherings</h3><p>Events that foster social connection, inclusion, and mutual support.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-campground"></i></div><h3>Cultural Celebrations</h3><p>Celebrations that highlight Bhutanese identity and heritage.</p></div>
            <div class="bbcc-service-card-ext fade-up"><div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-seedling"></i></div><h3>Volunteer Activities</h3><p>Community service and collaborative activities for shared wellbeing.</p></div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
