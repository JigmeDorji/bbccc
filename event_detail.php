<?php
require_once "include/config.php";

// Initialize
$message = "";
$menu = null;
$banners = [];

try {
    // Create PDO connection
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* ----------------------------
       1. Get menu details by ID
       ---------------------------- */
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $menuId = intval($_GET['id']);

        $stmt = $pdo->prepare("SELECT * FROM menu WHERE id = :id LIMIT 1");
        $stmt->bindParam(":id", $menuId, PDO::PARAM_INT);
        $stmt->execute();

        $menu = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$menu) {
            $message = "Menu not found.";
        }
    } else {
        $message = "Invalid menu ID.";
    }

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}
?>

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
.team_thumb img {
    width: 100%;
    height: 280px; /* adjust to your preferred height */
    object-fit: cover; 
    object-position: center;
    display: block;
    border-radius: 5px; /* optional */
}

.blog-detail-wrapper {
    padding: 60px 0;
    font-family: "Inter", sans-serif;
}

.blog-detail-container {
    max-width: 1100px;
    margin: auto;
}

.blog-row {
    display: flex;
    gap: 40px;
    align-items: flex-start;
}

.blog-left {
    flex: 1;
}

.blog-right {
    flex: 1.2;
}

.blog-featured-img {
    width: 100%;
    height: 420px;
    object-fit: cover;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
}

.blog-title {
    font-size: 32px;
    font-weight: 700;
    color: #222;
    margin-bottom: 15px;
    line-height: 1.3;
}

.blog-meta {
    display: flex;
    gap: 25px;
    font-size: 14px;
    color: #777;
    margin-bottom: 25px;
}

.blog-meta i {
    color: #c59146;
}

.blog-content p {
    font-size: 17px;
    line-height: 1.8;
    color: #444;
    margin-bottom: 20px;
}

@media (max-width: 767px) {
    .blog-row {
        flex-direction: column;
    }
    .blog-featured-img {
        height: 260px;
    }
    .blog-title {
        font-size: 26px;
    }
}



    </style>
</head>
<body>

<?php
include_once 'include/nav.php'
?>

<div class="hero_brd_area">
    <div class="container">
        <div class="hero_content">
            <h2 class="wow fadeInUp" data-wow-delay="0.3s">Event Detail</h2>
            <ul class="wow fadeInUp" data-wow-delay="0.5s">
                <li><a href="index.html">Home</a></li>
                <li>/</li>
                <li>Event Detail</li>
            </ul>
        </div>
    </div>
</div>


<div class="blog-detail-wrapper">
    <div class="blog-detail-container">

        <div class="blog-row">

            <!-- LEFT COLUMN (IMAGE) -->
            <div class="blog-left">
                <img src="<?php echo $menu['menuImgUrl']; ?>" 
                     alt="<?php echo $menu['menuName']; ?>" 
                     class="blog-featured-img">
            </div>

            <!-- RIGHT COLUMN (TEXT) -->
            <div class="blog-right">
                <h1 class="blog-title">
                    <?php echo $menu['menuName']; ?>
                </h1>

               <div class="blog-meta">

    <?php
        // Format event start date & time
        $eventDate = "Date Not Set";
        if (!empty($menu['eventStartDateTime'])) {
            $eventDate = date("d M Y – g:i A", strtotime($menu['eventStartDateTime']));
        }
    ?>

    <span><i class="fa fa-calendar"></i> <?php echo $eventDate; ?></span>
    <span><i class="fa fa-user"></i> Admin</span>

</div>


                <div class="blog-content">
                    <p><?php echo nl2br($menu['menuDetail']); ?></p>
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






