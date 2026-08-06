<?php
require_once "include/config.php";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Ched Tshog Singye Tsewa — BBCC</title>
    <meta name="description" content="Ched Tshog Singye Tsewa practice at the Bhutanese Buddhist and Cultural Centre Canberra.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>

<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-om"></i> Ched Tshog Singye Tsewa</h1>
        <p class="bbcc-page-hero__subtitle">A community practice of offering and blessing</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li><li class="sep">/</li>
            <li><a href="services">Services</a></li><li class="sep">/</li>
            <li>Ched Tshog Singye Tsewa</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container" style="max-width:980px;">
        <div class="section-header fade-up" style="text-align:left;max-width:none;">
            <span class="section-badge"><i class="fa-solid fa-hands-praying"></i> Spiritual Practice</span>
            <h2>About This <span>Practice</span></h2>
            <p>The Bhutanese Centre offers Ched Tshog Singye Tsewa practice, and warmly welcomes members of the community to join.</p>
        </div>

        <div class="bbcc-services-extended" style="grid-template-columns:repeat(2,minmax(0,1fr));">
            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon"><i class="fa-regular fa-clock"></i></div>
                <h3>Schedule</h3>
                <p>Please contact the Centre for current practice dates and times.</p>
            </div>
            <div class="bbcc-service-card-ext fade-up">
                <div class="bbcc-service-card-ext__icon"><i class="fa-solid fa-people-group"></i></div>
                <h3>Who Can Join</h3>
                <p>Open to all practitioners, both new and experienced, from the wider community.</p>
            </div>
        </div>

        <p class="fade-up" style="margin-top:28px;color:var(--gray-700);line-height:1.8;">
            For more details about this practice, please <a href="contact-us" style="color:#881b12;font-weight:600;">contact the Centre</a>.
        </p>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
