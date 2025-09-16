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
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>About Us</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/font/flaticon.css">
    <link rel="stylesheet" href="assets/css/plugins/owl.carousel.min.css">
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .team-main-box {
            text-align: center;
            margin-bottom: 20px;
        }
        .team-main-box img {
            width: 100%;
            max-width: 300px; /* Max width for large thumbnails */
            height: 300px; /* Height for large thumbnails */
            object-fit: cover;
            display: block;
            margin: 0 auto; /* Center the image */
            border-radius: 8px; /* Optional: Add rounded corners */
        }
        .team-content-box {
            text-align: center;
            padding: 10px;
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
                        <h3>About us</h3>
                        <ol>
                            <li>Home <i class="flaticon-double-right-arrow"></i></li>
                            <li>About Us</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- ====================================================== -->
    <section class="about-se">
        <div class="container">
            <div class="row">
                <div class="col-md-6 col-12 float-se">
                    <div class="wrapper">
                        <div class="image" id="image">
                            <img src="<?php echo $aboutData['imgUrl']; ?>" alt="About Image"> <!-- populate from database using imgUrl -->
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="wrapper-content">
                        <div class="content">
                            <center>
                                <h2>About Us</h2> <br>
                                <p id="description"><?php echo $aboutData['description']; ?></p> <!-- to be pulled from description column -->
                            </center>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- ====================================================== -->

    <section class="team">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="heading">
                        <h2>Our Team</h2>
                    </div>
                </div>
                <?php foreach ($teamData as $teamMember): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 col-6">
                        <div class="team-main-box">
                            <img src="<?php echo $teamMember['imgUrl']; ?>" alt="<?php echo $teamMember['Name']; ?>">
                            <div class="team-content-box">
                                <h3><?php echo $teamMember['Name']; ?></h3>
                                <b><?php echo $teamMember['designation']; ?></b>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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