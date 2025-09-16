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
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>To</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/font/flaticon.css">
    <link rel="stylesheet" href="assets/css/plugins/owl.carousel.min.css">
    <link rel="stylesheet" href="assets/css/style.css"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">


    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .feature-card {
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            transition: 0.3s;
            height: 100%;
        }
        .feature-card:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }
        .feature-icon {
            font-size: 2rem;
            color: #6610f2;
            margin-bottom: 15px;
        }
        .feature-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        .feature-desc {
            color: #6c757d;
            font-size: 0.95rem;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            color: #1f1f1f;
        }
        .contact-wrapper {
            max-width: 1200px;
            margin: 60px auto;
            padding: 20px;
            display: flex;
            gap: 40px;
            flex-wrap: wrap;
        }
        .contact-left, .contact-right {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
            flex: 1 1 450px;
        }
        .contact-left h3,
        .contact-right h3 {
            margin-bottom: 24px;
            font-weight: 600;
            font-size: 24px;
        }
        .contact-info p {
            margin: 12px 0;
            line-height: 1.6;
        }
        .contact-info p span {
            display: block;
            font-weight: 500;
            margin-bottom: 4px;
        }
        .social-icons {
            margin-top: 20px;
        }
        .social-icons a {
            margin-right: 15px;
            text-decoration: none;
            font-size: 18px;
            color: #333;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background-color: #f9f9f9;
        }
        .form-group textarea {
            resize: none;
            min-height: 120px;
        }
        .btn-submit {
            padding: 12px 30px;
            background-color: #3661eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 16px;
            cursor: pointer;
            transition: 0.3s;
        }
        .btn-submit:hover {
            background-color: #264ec9;
        }
        .section-title {
            text-align: center;
            margin-top: 60px;
        }
        .section-title h2 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        .section-title p {
            font-size: 14px;
            color: #666;
        }
    </style>


</head>
<!-- =================Body Started==================== -->
<body>
<!-- *********************************************************** -->
<!-- //////////////////////header-started/////////////////////// -->
<?php
include_once 'include/nav.php'
?>
<!-- //////////////////////header-End/////////////////////// -->
<!-- ====================================================== -->
<!-- //////////////////////Main-Started/////////////////////// -->
<main>

    <section class="banner">
        <div class="shap"></div>
        <div class="shap-01"></div>
        <div class="shap-02"></div>
        <div class="shap-03"></div>
        <div id="carouselExampleCaptions" class="carousel slide" data-ride="carousel">
            <ol class="carousel-indicators">
                <li data-target="#carouselExampleCaptions" data-slide-to="0" class="active"></li>
                <li data-target="#carouselExampleCaptions" data-slide-to="1"></li>
            </ol>
            <div class="carousel-inner">
                <!-- First Banner -->
                <div class="carousel-item active">
                    <div class="container">
                        <div class="row">
                            <div class="col-md-6 col-12">
                                <div class="wrapper">
                                    <div class="content">
                                        <h1><?php echo $banners[0]['title']; ?></h1>
                                        <p><?php echo $banners[0]['subtitle']; ?></p>
                                        <a href="contact-us.php">Get Started</a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-12">
                                <div class="wrapper">
                                    <div class="image">
                                        <img src="<?php echo $banners[0]['imgUrl']; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Second Banner -->
                <div class="carousel-item">
                    <div class="container">
                        <div class="row">
                            <div class="col-md-6 col-12">
                                <div class="wrapper">
                                    <div class="content">
                                        <h1><?php echo $banners[1]['title']; ?> </h1>
                                        <p><?php echo $banners[1]['subtitle']; ?></p>
                                        <a href="order.php">Order now</a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-12">
                                <div class="wrapper">
                                    <div class="image">
                                        <img src="<?php echo $banners[1]['imgUrl']; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <a class="carousel-control-prev" href="#carouselExampleCaptions" role="button" data-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="sr-only">Previous</span>
            </a>
            <a class="carousel-control-next" href="#carouselExampleCaptions" role="button" data-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="sr-only">Next</span>
            </a>
        </div>
    </section>
    <!-- ====================================================== -->
    <!-- ====================================================== -->

    <section class="main-menu py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold">Our Services</h2>
                <p class="text-muted">Explore our high-quality, customizable, and regularly updated offerings.</p>
            </div>

            <div class="container py-5">
                <div class="row g-4">

                    <div class="col-md-4 mb-3">
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-bar-chart"></i></div>
                            <div class="feature-title">Company & Project Registration</div>
                            <div class="feature-desc">Secure onboarding for your business and its projects.
                                Design: Icon on the left, heading and short description on the right, in a card layout.
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-layers"></i></div>
                            <div class="feature-title">Expense Management</div>
                            <div class="feature-desc">Record, categorize, and analyze expenses by project.
                                Design: Use an icon and a small chart/graph placeholder next to the text.
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-layout-text-sidebar"></i></div>
                            <div class="feature-title">Financial Reporting</div>
                            <div class="feature-desc">Real-time financial dashboards and exportable reports.
                                Design: Showcase a card with a mock dashboard or graph preview.</div>
                        </div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-lightning-charge"></i></div>
                            <div class="feature-title">User Roles & Access Control</div>
                            <div class="feature-desc">Define roles (Admin, Manager, Staff) with custom access.
                                Design: Use a roles icon and a UI preview of permission toggles.
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-sliders"></i></div>
                            <div class="feature-title">Multi-Project Switching</div>
                            <div class="feature-desc">Seamlessly switch between projects with role-based access.
                                Design: Add a dropdown-style mock UI showing project switching.
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-arrow-clockwise"></i></div>
                            <div class="feature-title">Timeline View</div>
                            <div class="feature-desc">View project spending across a timeline or phase-wise.
                                Design: Add a horizontal timeline UI or Gantt-style preview.</div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </section>


    <section class="bg-001">
        <div class="container">
            <div class="section-title">
                <h2>Let’s Stay Connected</h2>
                <p>It is a long-established fact that meaningful connections drive innovation. Reach out to explore how we can craft strategic tech solutions tailored to your business.</p>
            </div>

            <div class="contact-wrapper">

                <!-- Contact Info Left -->
                <div class="contact-left">
                    <div class="contact-info">
                        <p><span>Email Address</span>tobgaytechstrat@gmail.com</p>
                        <p><span>Office Location</span>76/A, Babesa, Thimphu, Bhutan</p>
                        <p><span>Phone Number</span>+975 8754 3433 223</p>
                        <p><span>Skype Email</span>example@yourmail.com</p>
                        <p><span>Social Media</span></p>
                        <div class="social-icons">
                            <a href="#"><strong>f</strong></a>
                            <a href="#"><strong>t</strong></a>
                            <a href="#"><strong>in</strong></a>
                            <a href="#"><strong>Behance</strong></a>
                        </div>
                    </div>
                </div>

                <!-- Contact Form Right -->
                <div class="contact-right">
                    <form action="contact-us.php" method="POST">
                        <div class="form-row">
                            <div class="form-group" style="flex: 1;">
                                <input type="text" name="name" placeholder="Full name" required>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <input type="email" name="email" placeholder="Email address" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="flex: 1;">
                                <input type="text" name="phone" placeholder="Phone number">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <input type="text" name="subject" placeholder="Subject">
                            </div>
                        </div>
                        <div class="form-group">
                            <textarea name="message" placeholder="Message"></textarea>
                        </div>
                        <button type="submit" class="btn-submit">Send Message</button>
                    </form>
                </div>
            </div>
        </div>
    </section>



</main>
<!-- //////////////////////Main-End/////////////////////// -->
<!-- ====================================================== -->
<!-- //////////////////////Footer-Started/////////////////////// -->
<?php
include_once 'include/footer.php'
?>
<!-- *********************************************************** -->
</body>
<!-- =================Body End==================== -->
