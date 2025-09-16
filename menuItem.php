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
    <title>Menus</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/font/flaticon.css">
    <link rel="stylesheet" href="assets/css/plugins/owl.carousel.min.css">
    <link rel="stylesheet" href="assets/css/style.css" />

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
    <!-- ====================================================== -->
    <section class="abt-01">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="seting">
                        <h3>Our Services</h3>
                        <ol>
                            <li>Home <i class="flaticon-double-right-arrow"></i></li>
                            <li>Our Services</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- ====================================================== -->
    <!-- ====================================================== -->

    <section class="main-menu py-5">
        <div class="container">
            <div class="text-center mb-5">
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
</html>