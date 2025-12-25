<?php
require_once "include/config.php";

$message = "";

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


    // Fetch existing menu data54rt6f
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
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Bhutanese Centre Canberra</title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    include_once 'include/global_css.php'
    ?>



</head>
<!-- =================Body Started==================== -->
<body>

<!--[if lt IE 8]>
<p class="browserupgrade">You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade
    your browser</a> to improve your experience.</p>
<![endif]-->

<?php
include_once 'include/nav.php'
?>




<!-- HERO SLIDER -->
<div class="hero-slider">

    <!-- Slide 1 -->
    <div class="hero-slide active">
        <div class="hero-container">
            <div class="hero-left">
                <h1><?php echo strtoupper($banners[0]['title']); ?></h1>
                <p><?php echo $banners[0]['subtitle']; ?></p>
 <div class="read_more_btn">
                    <a href="parentAccountSetup.php">Register for dzongkha class</a>
                </div>            </div>
            <div class="hero-right">
                <img src="<?php echo $banners[0]['imgUrl']; ?>" alt="">
            </div>
        </div>
    </div>

    <!-- Slide 2 -->
    <div class="hero-slide">
        <div class="hero-container">
            <div class="hero-left">
                <h1><?php echo strtoupper($banners[1]['title']); ?></h1>
                <p><?php echo $banners[1]['subtitle']; ?></p>
 <div class="read_more_btn">
                    <a href="parentAccountSetup.php">Register for dzongkha class</a>
                </div>              </div>
            <div class="hero-right">
                <img src="<?php echo $banners[1]['imgUrl']; ?>" alt="">
            </div>
        </div>
    </div>

    <!-- Slider Counter -->
<!-- Slider Counter (empty, will be populated by JS) -->
<div class="slider-counter"></div>


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


<!-- ABOUT AREA  -->
<div class="about_area" id="about">
    <div class="container">
        <div class="row">
            <!-- Left Side: Skills / Focus Areas -->
            <div class="col-md-6">
                <div class="about_single_image">
                    <img src="bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png" alt="Beautiful view of Bhutan" />
                </div>
            </div>
            <!-- Right Side: About BBCC -->
            <div class="col-md-6">
                <div class="about_history">
                    <h3>About <span>BBCC</span></h3>
                    <h4>Serving the Bhutanese Community in Canberra</h4>
                    <p>The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to providing spiritual guidance,
                        cultural preservation, and pastoral support to Bhutanese residents in Canberra and nearby NSW
                        towns. Our focus is on fostering unity, harmony, and the preservation of Bhutanese identity
                        within a diverse community.</p>
                    <p class="about_pra_2">BBCC offers weekly language and cultural classes for children, regular Dharma
                        teachings, meditation sessions, and special programs such as TARA practice and Doenchoe. These
                        services nurture spiritual growth, provide support during life’s challenges, and build a vibrant
                        Bhutanese community in the ACT.</p>
                </div>
                <div class="read_more_btn">
                    <a href="about-us.php">Read More</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- BLOG AREA -->
<div class="blog_area" id="blog">
    <div class="container">
        <div class="row">
            <!-- SECTION  TITLE  -->
            <div class="col-md-12">
                <div class="section_title">
                    <h2>Upcoming <span>Events</span></h2>
                    <img src="bbccassests/img/logo/line-1.png" alt=""/>
                    <p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut
                        labore et dolo magna</p>
                </div>
            </div>
        </div>
        <div class="row">

    <?php foreach ($menus as $menu): ?>
<?php 
    // Limit text to 120 characters
    $shortDetail = substr($menu['menuDetail'], 0, 120) . '...';

    // Format Event Date/Time
    $formattedDate = "No Date Set";
    if (!empty($menu['eventStartDateTime'])) {
        $formattedDate = date("d M Y – g:i A", strtotime($menu['eventStartDateTime']));
    }
?>
<div class="col-md-6">
    <div class="single_blog">
        <div class="blog_thumb">
            <a href="event_detail.php?id=<?php echo $menu['id']; ?>">
                <img src="<?php echo $menu['menuImgUrl']; ?>" alt=""/>
            </a>
        </div>

        <div class="blog_content">
            <div class="content_title">
                <h3>
                    <a href="event_detail.php?id=<?php echo $menu['id']; ?>">
                        <?php echo $menu['menuName']; ?>
                    </a>
                </h3>
            </div>

            <div class="blog_post_meta">
                <span class="meta_date">
                    <i class="fa fa-calendar-o"></i>
                    <?php echo $formattedDate; ?>
                </span>

            </div>

            <div class="blog_desc">
                <p><?php echo $shortDetail; ?></p>
            </div>

            <div class="blog_readmore">
                <a href="event_detail.php?id=<?php echo $menu['id']; ?>">Read More</a>
            </div>

        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
    </div>
</div>


<!-- FOOTER AREA  -->
<?php include_once 'include/footer.php'; ?>

<?php include_once 'include/global_js.php'; ?>

</body>
<!-- =================Body End==================== -->
<script>
let slides = document.querySelectorAll(".hero-slide");
let counter = document.querySelector(".slider-counter");
let index = 0;

// Dynamically create dots based on slides
slides.forEach((slide, i) => {
    let dot = document.createElement("span");
    dot.classList.add("dot");
    if(i === 0) dot.classList.add("active"); // first slide active
    dot.addEventListener("click", () => goToSlide(i)); // click to jump
    counter.appendChild(dot);
});

// Select all dots after creation
let dots = document.querySelectorAll(".slider-counter .dot");

// Function to go to a specific slide
function goToSlide(slideIndex) {
    // Remove active class from current slide and dot
    slides[index].classList.remove("active");
    dots[index].classList.remove("active");

    // Reset text animation
    let texts = slides[index].querySelectorAll(".hero-left h1, .hero-left p, .hero-left .hero-btn");
    texts.forEach(el => { el.style.animation = "none"; el.offsetHeight; el.style.animation = ""; });

    // Reset image animation
    let img = slides[index].querySelector(".hero-right img");
    img.style.animation = "none"; img.offsetHeight; img.style.animation = "";

    // Set new index
    index = slideIndex;

    // Add active class to new slide and dot
    slides[index].classList.add("active");
    dots[index].classList.add("active");
}

// Auto-slide function
setInterval(() => {
    goToSlide((index + 1) % slides.length);
}, 7000);

</script>




</body>
</html>














