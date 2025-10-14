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


?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Trading || Home 1</title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    include_once 'include/global_css.php'
    ?>
<style>
    /* Styling for the unique single image container */
    .about_single_image {
        position: relative;
        width: 90%; /* Adjust width as needed */
        height: 400px; /* Fixed height for consistent look */
        margin: 30px auto; /* Center the image and provide some vertical space */
        overflow: hidden;
        border-radius: 10px; /* Soften the corners */
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2); /* Stronger shadow for depth */
        transform: rotate(-5deg); /* Rotate the image container */
        transition: transform 0.5s ease-in-out;
    }

    .about_single_image:hover {
        transform: rotate(0deg) scale(1.02); /* Straighten and slightly enlarge on hover */
    }

    .about_single_image img {
        width: 100%;
        height: 100%;
        object-fit: cover; /* Ensure image covers the area */
        display: block;
        transform: rotate(5deg); /* Counter-rotate the image to appear straight inside the rotated container */
        transition: transform 0.5s ease-in-out;
    }

    .about_single_image:hover img {
        transform: rotate(0deg); /* Straighten image on container hover */
    }

    /* Responsive adjustments */
    @media screen and (max-width: 767px) {
        .about_single_image {
            width: 100%;
            height: 300px;
            margin: 20px 0;
            transform: rotate(0deg); /* Disable rotation on smaller screens for better fit */
        }
        .about_single_image:hover {
            transform: scale(1.02);
        }
        .about_single_image img {
            transform: rotate(0deg);
        }
    }
</style>
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


<!--Start nav  area -->
<div class="nav_areas hidden-lg hidden-md">
    <div class="mobile-menu">
        <div class="container">
            <div class="row">
                <!--nav area-->
                <div class="col-sm-12 col-xs-12">
                    <!--  nav menu-->
                    <nav class="menu">
                        <ul>
                            <li><a href="index.html">Home</a>
                                <ul>
                                    <li><a href="index-2.html">Home 2</a></li>
                                </ul>
                            </li>
                            <li><a href="about-us.html">About</a></li>
                            <li><a href="service.html">Service</a></li>
                            <li><a href="portfolio-grid.html">Project</a>
                                <ul>
                                    <li><a href="portfolio-grid.html">Portfolio Grid</a></li>
                                    <li><a href="portfolio-3column.html">Portfolio 3Column</a></li>
                                    <li><a href="single-portfolio.html">Single Portfolio</a></li>
                                </ul>
                            </li>
                            <li><a href="team-grid.html">Team</a>
                                <ul>
                                    <li><a href="team-grid.html">All Team Member</a></li>
                                    <li><a href="team-3column.html">Team 3 Column</a></li>
                                </ul>
                            </li>
                            <li><a href="blog.html">Blogs</a>
                                <ul>
                                    <li><a href="blog-left-sidebar.html">Blog Left Sidebar</a></li>
                                    <li><a href="blog-right-sidebar.html">Blog Right Sidebar</a></li>
                                    <li><a href="video-audio.html">Blog Video & Audio</a></li>
                                    <li><a href="single-blog.html">Single Blog</a></li>
                                </ul>

                            </li>

                            <li><a href="contact-us.html">Contact</a></li>
                        </ul>
                    </nav>
                    <!--end  nav menu-->
                </div>
            </div>
        </div>
    </div>

</div>
<!--end nav area-->

<!-- slider-area start -->
<section class="main-slider-area" id="home">
    <div class="container-fluid">
        <div class="row">
            <div class="slider">
                <div id="mainSlider" class="nivoSlider slider-image">
                    <img src="<?php echo $banners[0]['imgUrl']; ?>" alt="main slider" title="#htmlcaption1"/>
                    <img src="<?php echo $banners[1]['imgUrl']; ?>" alt="main slider"
                         title="#htmlcaption2"/>
                </div>

               

                <!-- Slide 1 -->
                <div id="htmlcaption1" class="nivo-html-caption slider-caption-1">
                    <div class="slide1-text">
                        <div class="middle-text margin_left">
                            <div class="cap-title ctitle1 wow slideInRight" data-wow-duration="2s" data-wow-delay="0s">
                                <h3><span class="no-p-laft"><?php echo $banners[0]['title']; ?></span></h3>
                            </div>
                            <div class="cap-dec wow slideInRight" data-wow-duration="3s" data-wow-delay="0s">
                                <p><?php echo $banners[0]['subtitle']; ?></p>
                            </div>
                            <div class="cap-readmore wow bounceInUp smore" data-wow-duration="3s" data-wow-delay="1s">
                                <a href="about-us.php" class="actice-button">Learn More</a> <a href="contact-us.php">Contact Us</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Slide 2 -->
                <div id="htmlcaption2" class="nivo-html-caption slider-caption-1">
                    <div class="slide1-text text-center">
                        <div class="middle-text">
                            <div class="cap-title wow zoomIn" data-wow-duration=".9s" data-wow-delay=".5s">
                                <h3><span>Preserving Bhutanese Identity & Culture</span></h3>
                            </div>
                            <div class="cap-dec wow zoomIn" data-wow-duration="1.1s" data-wow-delay=".5s">
                                <p>BBCC offers weekly Bhutanese language and cultural classes for children, regular
                                    Dharma teachings, TARA practice, and Doenchoe sessions to preserve our unique
                                    heritage within the ACT’s diverse community.</p>
                            </div>
                            <div class="cap-readmore wow zoomIn smore" data-wow-duration="1.5s" data-wow-delay=".5s">
                                <a href="#">Get Started</a>
                            </div>
                        </div>
                    </div>
                </div>

                

            </div>
        </div>
    </div>
</section>
<!-- slider-area end -->


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
            <!-- SINGLE BLOG ITEM  -->
            <div class="col-md-6">
                <div class="single_blog">
                    <div class="blog_thumb">
                        <a href="single-blog.html">
                            <img src="bbccassests/img/blog/3.jpg" alt=""/>
                        </a>
                    </div>
                    <!-- BLOG CONTENT -->
                    <div class="blog_content">
                        <div class="content_title">
                            <h3><a href="single-blog.html">Learnig and installation</a></h3>
                        </div>
                        <div class="blog_post_meta">
                            <span class="meta_date"><i class="fa fa-calendar-o "></i>05 Jun 2017</span>
                            <span class="meta_like"><i class="fa fa-comment"></i>240</span>
                            <span class="meta_comments"><i class="fa fa-tag"></i>400</span>
                        </div>
                        <div class="blog_desc">
                            <p>Lorem ipsum dolor sit amet, consectetur adip elit, sed do eiusmod tempor incididunt loren
                                labore et dolore magna aliqua. Ut enim asan minim veniam, quis nostrud </p>
                        </div>
                        <div class="blog_readmore">
                            <a href="single-blog.html">Read More</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- SINGLE BLOG ITEM  -->
            <div class="col-md-6">
                <div class="single_blog">
                    <div class="blog_thumb">
                        <a href="single-blog.html">
                            <img src="bbccassests/img/blog/4.jpg" alt=""/>
                        </a>
                    </div>
                    <!-- BLOG CONTENT -->
                    <div class="blog_content">
                        <div class="content_title">
                            <h3><a href="single-blog.html">Wiring and installation</a></h3>
                        </div>
                        <div class="blog_post_meta">
                            <span class="meta_date"><i class="fa fa-calendar-o "></i>05 Jun 2017</span>
                            <span class="meta_like"><i class="fa fa-comment"></i>240</span>
                            <span class="meta_comments"><i class="fa fa-tag"></i>400</span>
                        </div>
                        <div class="blog_desc">
                            <p>Lorem ipsum dolor sit amet, consectetur adip elit, sed do eiusmod tempor incididunt loren
                                labore et dolore magna aliqua. Ut enim asan minim veniam, quis nostrud </p>
                        </div>
                        <div class="blog_readmore">
                            <a href="single-blog.html">Read More</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- SINGLE BLOG ITEM  -->
            <div class="col-md-6">
                <div class="single_blog">
                    <div class="blog_thumb">
                        <a href="single-blog.html">
                            <img src="bbccassests/img/blog/2.jpg" alt=""/>
                        </a>
                    </div>
                    <!-- BLOG CONTENT -->
                    <div class="blog_content">
                        <div class="content_title">
                            <h3><a href="single-blog.html">Reading and Declaration</a></h3>
                        </div>
                        <div class="blog_post_meta">
                            <span class="meta_date"><i class="fa fa-calendar-o "></i>05 Jun 2017</span>
                            <span class="meta_like"><i class="fa fa-comment"></i>240</span>
                            <span class="meta_comments"><i class="fa fa-tag"></i>400</span>
                        </div>
                        <div class="blog_desc">
                            <p>Lorem ipsum dolor sit amet, consectetur adip elit, sed do eiusmod tempor incididunt loren
                                labore et dolore magna aliqua. Ut enim asan minim veniam, quis nostrud </p>
                        </div>
                        <div class="blog_readmore">
                            <a href="single-blog.html">Read More</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- SINGLE BLOG ITEM  -->
            <div class="col-md-6">
                <div class="single_blog">
                    <div class="blog_thumb">
                        <a href="single-blog.html">
                        <img src="bbccassests/img/blog/2.jpg" alt=""/>
                        </a>
                    </div>
                    <!-- BLOG CONTENT -->
                    <div class="blog_content">
                        <div class="content_title">
                            <h3><a href="single-blog.html">Loren and installation</a></h3>
                        </div>
                        <div class="blog_post_meta">
                            <span class="meta_date"><i class="fa fa-calendar-o "></i>05 Jun 2017</span>
                            <span class="meta_like"><i class="fa fa-comment"></i>240</span>
                            <span class="meta_comments"><i class="fa fa-tag"></i>400</span>
                        </div>
                        <div class="blog_desc">
                            <p>Lorem ipsum dolor sit amet, consectetur adip elit, sed do eiusmod tempor incididunt loren
                                labore et dolore magna aliqua. Ut enim asan minim veniam, quis nostrud </p>
                        </div>
                        <div class="blog_readmore">
                            <a href="single-blog.html">Read More</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- FOOTER AREA  -->
<?php include_once 'include/footer.php'; ?>

<?php include_once 'include/global_js.php'; ?>

</body>
<!-- =================Body End==================== -->
