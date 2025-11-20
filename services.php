<?php
require_once "include/config.php";

$message = "";

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch existing menu data
    $stmt = $pdo->prepare("SELECT * FROM menu");
    $stmt->execute();
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

?>

<?php
require_once "include/config.php";

$message = "";
$banners = [];

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch existing banner data
    $stmt = $pdo->prepare("SELECT * FROM banner LIMIT 2");
    $stmt->execute();
    $banners = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>services</title>
    <?php
    include_once 'include/global_css.php'
    ?>
</head>
<!-- =================Body Started==================== -->
<body>
<!-- *********************************************************** -->
<!-- //////////////////////header-started/////////////////////// -->
<?php
include_once 'include/nav.php'
?>

<div class="hero_brd_area">
    <div class="container">
        <div class="hero_content">
            <h2 class="wow fadeInUp" data-wow-delay="0.3s">Services</h2>
            <ul class="wow fadeInUp" data-wow-delay="0.5s">
                <li><a href="index.html">Home</a></li>
                <li>/</li>
                <li>Services</li>
            </ul>
        </div>
    </div>
</div>

<!-- FEATURE AREA  -->
<div class="feature_area home_2">
    <div class="container">
        <div class="row">
            <!-- SECTION  TITLE  -->
            <div class="col-md-12">
                <div class="section_title">
                    <h2>What We <span>Do ?</span></h2>
                    <img src="bbccassests/img/logo/line-2.png" alt="" />
                    <p>BBCC provides spiritual and pastoral services, rituals, and teachings to Bhutanese residents in Canberra.</p>
                </div>
            </div>
        </div>
        <div class="row">
            <!-- SINGLE FEATURE ITEM  -->
            <div class="col-md-4 col-sm-6 col-xs-12">
                <div class="single_feature">
                    <div class="feature_icon">
                        <i class="fa fa-truck "></i>
                    </div>
                    <div class="feature_content">
                        <h3>Spiritual Services</h3>
                        <p>Offering private household rituals, group teachings, meditation sessions, and Dharma
                            teachings to support the spiritual wellbeing of our community members.</p>
                    </div>
                </div>
            </div>
            <!-- SINGLE FEATURE ITEM  -->
            <div class="col-md-4 col-sm-6 col-xs-12">
                <div class="single_feature">
                    <div class="feature_icon">
                        <i class="fa fa-heartbeat "></i>
                    </div>
                    <div class="feature_content">
                        <h3>Cultural Preservation</h3>
                        <p>Weekly Bhutanese language and cultural classes, TARA practice, and Doenchoe sessions to preserve and promote Bhutanese identity within the ACT community.</p>
                    </div>
                </div>
            </div>
            <!-- SINGLE FEATURE ITEM  -->
            <div class="col-md-4 col-sm-6 col-xs-12">
                <div class="single_feature">
                    <div class="feature_icon">
                        <i class="fa fa-code "></i>
                    </div>
                    <div class="feature_content">
                        <h3>Community Events</h3>
                        <p>Organizing ceremonies, rituals, and religious practices on important Buddhist days, fostering unity, harmony, and a supportive community environment.</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>



<!-- //////////////////////Main-End/////////////////////// -->
<!-- ====================================================== -->
<!-- //////////////////////Footer-Started/////////////////////// -->
<?php
include_once 'include/footer.php'
?>
<!-- *********************************************************** -->
</body>
<!-- =================Body End==================== -->
</html>