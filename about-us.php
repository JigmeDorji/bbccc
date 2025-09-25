<?php
require_once "include/config.php";

$message = "";
$aboutData = [];

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch the topmost record from the about table
    $stmt = $pdo->prepare("SELECT * FROM about order by id desc LIMIT 1");
    $stmt->execute();
    $aboutData = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch all team members from the ourteam table
    $stmt = $pdo->prepare("SELECT * FROM ourteam");
    $stmt->execute();
    $teamData = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>BCC || About Us</title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    include_once 'include/global_css.php'
    ?>
    <style>


    </style>
</head>
<body>

<?php
include_once 'include/nav.php'
?>

<div class="hero_brd_area">
    <div class="container">
        <div class="hero_content">
            <h2 class="wow fadeInUp" data-wow-delay="0.3s">About Us</h2>
            <ul class="wow fadeInUp" data-wow-delay="0.5s">
                <li><a href="index.html">Home</a></li>
                <li>/</li>
                <li>About Us</li>
            </ul>
        </div>
    </div>
</div>


<div class="blog_area section_padding">
    <div class="container">
        <div class="about_us_content wow fadeInUp" data-wow-delay="0.3s">
            <div class="img-col">
                <img src="bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png" alt="Bhutanese Buddhist and Cultural Centre">
            </div>
            <div class="text-col">
                <h3>A Glimpse into Our Journey</h3>
                <p>
                    The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to serving the Bhutanese community and all interested individuals in Canberra and surrounding areas. Our core objective is to offer spiritual services and pastoral care, ensuring the wellbeing of our community members. We strive to preserve and promote Bhutanese identity and culture for future generations.
                </p>
                <p>
                    Our services include regular spiritual rituals, group teachings, and engaging programs for children and elders. We hold weekly Bhutanese language and cultural classes for children and organize special practices like TARA and Doenchoe sessions to maintain our unique heritage within the culturally diverse community of the ACT. We also offer spiritual guidance, meditation sessions, and counseling with qualified Buddhist masters.
                </p>
            </div>

            <div class="row">
                <div class="col-md-6 col-sm-6 col-xs-12">
                    <div class="single_feature wow fadeInUp" data-wow-delay="0.3s">
                        <div class="feature_icon">
                            <i class="fa fa-truck"></i>
                        </div>
                        <div class="feature_content">
                            <h3>OUR VISION</h3>
                            <p>To provide spiritual and pastoral services to all Bhutanese and other interested devotees, while preserving and promoting Bhutanese identity and culture.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-sm-6 col-xs-12">
                    <div class="single_feature wow fadeInUp" data-wow-delay="0.5s">
                        <div class="feature_icon">
                            <i class="fa fa-heartbeat"></i>
                        </div>
                        <div class="feature_content">
                            <h3>OUR MISSION</h3>
                            <p>To build a vibrant community center and temple in Canberra, fostering unity, harmony, and providing a place of solace and guidance for all.</p>
                        </div>
                    </div>
                </div>
            </div>


        </div>
    </div>
</div>

<!-- Team Area -->
<div class="team_area" id="team">
    <div class="container">
        <div class="row">
            <!-- SECTION  TITLE  -->
            <div class="col-md-12">
                <div class="section_title">
                    <h2> Executive <span>Member</span></h2>
                    <img src="bbccassests/img/logo/line-1.png" alt=""/>
                    <p>The Bhutanese Buddhist and Cultural Centre (BBCC) is guided by dedicated executive members
                        committed to serving the spiritual, cultural, and community needs of Bhutanese residents in
                        Canberra and nearby regions.</p>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="team_wrap">
                <!-- single team -->
                <div class="col-lg-3 col-md-3 col-sm-6 col-xs-12">
                    <div class="single_team">
                        <div class="team_thumb">
                            <img src="bbccassests/img/team/1.jpg" alt=""/>
                            <div class="team_content">
                                <div class="team_content_hover">
                                    <div class="team_info">
                                        <h3>Choki Wangmo</h3>
                                        <span>Founder & Spiritual Head</span>
                                    </div>
                                    <div class="team_social">
                                        <div class="team_social_icon">
                                            <a href="#"><i class="fa fa-facebook"></i></a>
                                            <a href="#"><i class="fa fa-twitter"></i></a>
                                            <a href="#"><i class="fa fa-pinterest"></i></a>
                                            <a href="#"><i class="fa fa-linkedin"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- single team -->
                <div class="col-lg-3 col-md-3 col-sm-6 col-xs-12">
                    <div class="single_team">
                        <div class="team_thumb">
                            <img src="bbccassests/img/team/2.jpg" alt=""/>
                            <div class="team_content">
                                <div class="team_content_hover">
                                    <div class="team_info">
                                        <h3>Phurba Dorji</h3>
                                        <span>President</span>
                                    </div>
                                    <div class="team_social">
                                        <div class="team_social_icon">
                                            <a href="#"><i class="fa fa-facebook"></i></a>
                                            <a href="#"><i class="fa fa-twitter"></i></a>
                                            <a href="#"><i class="fa fa-pinterest"></i></a>
                                            <a href="#"><i class="fa fa-linkedin"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- single team -->
                <div class="col-lg-3 col-md-3 col-sm-6 col-xs-12">
                    <div class="single_team">
                        <div class="team_thumb">
                            <img src="bbccassests/img/team/3.jpg" alt=""/>
                            <div class="team_content">
                                <div class="team_content_hover">
                                    <div class="team_info">
                                        <h3>Tashi Lhamo</h3>
                                        <span>Vice President</span>
                                    </div>
                                    <div class="team_social">
                                        <div class="team_social_icon">
                                            <a href="#"><i class="fa fa-facebook"></i></a>
                                            <a href="#"><i class="fa fa-twitter"></i></a>
                                            <a href="#"><i class="fa fa-pinterest"></i></a>
                                            <a href="#"><i class="fa fa-linkedin"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- single team -->
                <div class="col-lg-3 col-md-3 col-sm-6 col-xs-12">
                    <div class="single_team">
                        <div class="team_thumb">
                            <img src="bbccassests/img/team/4.jpg" alt=""/>
                            <div class="team_content">
                                <div class="team_content_hover">
                                    <div class="team_info">
                                        <h3>Karma Wangchuk</h3>
                                        <span>Adviser</span>
                                    </div>
                                    <div class="team_social">
                                        <div class="team_social_icon">
                                            <a href="#"><i class="fa fa-facebook"></i></a>
                                            <a href="#"><i class="fa fa-twitter"></i></a>
                                            <a href="#"><i class="fa fa-pinterest"></i></a>
                                            <a href="#"><i class="fa fa-linkedin"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>




<?php
include_once 'include/footer.php';
include_once 'include/global_js.php';
?>
</body>
</html>