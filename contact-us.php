<?php
require_once "include/config.php";

$message = "";
$msgType = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $messageContent = trim($_POST['message'] ?? '');

        $stmt = $pdo->prepare("INSERT INTO contact (name, email, subject, message) VALUES (:name, :email, :subject, :message)");
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':subject', $subject, PDO::PARAM_STR);
        $stmt->bindParam(':message', $messageContent, PDO::PARAM_STR);
        $stmt->execute();

        $message = "Thank you for contacting us! We'll be in touch soon.";
        $msgType = "success";
    } catch (Exception $e) {
        $message = "Something went wrong. Please try again.";
        $msgType = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact Us — Buddhist Temple Canberra | BBCC</title>
    <meta name="description" content="Contact BBCC, a Bhutanese Buddhist temple in Canberra, for spiritual services, Dzongkha classes and community support.">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<!-- Page Hero -->
<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-envelope"></i> Contact Us</h1>
        <p class="bbcc-page-hero__subtitle">Get in touch with the Bhutanese Buddhist &amp; Cultural Center</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li>Contact Us</li>
        </ul>
    </div>
</div>

<!-- Contact Section -->
<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="section-header fade-up">
            <span class="section-badge"><i class="fa-solid fa-envelope"></i> Get In Touch</span>
            <h2>We'd Love to <span>Hear From You</span></h2>
            <p>Reach out to explore how we can serve your spiritual and cultural needs. We're here to help.</p>
        </div>

        <div class="bbcc-contact-grid">
            <!-- Contact Form -->
            <div class="bbcc-contact-form fade-up">
                <form action="contact-us" method="POST">
                    <div class="form-group">
                        <label><i class="fa-solid fa-user"></i> Full Name</label>
                        <input type="text" name="name" placeholder="Enter your full name" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fa-solid fa-envelope"></i> Email Address</label>
                        <input type="email" name="email" placeholder="you@example.com" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fa-solid fa-tag"></i> Subject</label>
                        <input type="text" name="subject" placeholder="What is this about?">
                    </div>
                    <div class="form-group">
                        <label><i class="fa-solid fa-pen"></i> Message</label>
                        <textarea name="message" placeholder="How can we help you?" required></textarea>
                    </div>
                    <button type="submit" class="bbcc-btn bbcc-btn--primary" style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-paper-plane"></i> Send Message
                    </button>
                </form>
            </div>

            <!-- Contact Info -->
            <div class="bbcc-contact-info fade-up">
                <div class="bbcc-contact-info__card">
                    <div class="bbcc-contact-info__icon">
                        <i class="fa-solid fa-location-dot"></i>
                    </div>
                    <div class="bbcc-contact-info__text">
                        <h4>Our Location</h4>
                        <p>Canberra, ACT, Australia</p>
                    </div>
                </div>
                <div class="bbcc-contact-info__card">
                    <div class="bbcc-contact-info__icon">
                        <i class="fa-solid fa-phone"></i>
                    </div>
                    <div class="bbcc-contact-info__text">
                        <h4>Contact Persons</h4>                    
                        <p>
                            <strong>Khenchen Kinzang  Thinley</strong><br>
                            Resident Lam<br>
                            📞 0411 786 688
                        </p>

                        <p style="margin-top:10px;">
                            <strong>Khenpo Sonam Gyeltshen</strong><br>
                            Khenpo<br>
                            📞 0434 522 720
                        </p>

                        <p style="margin-top:10px;">
                            <strong>Chencho Tshering</strong><br>
                            President<br>
                            📞 0450 727 541
                        </p>
                        </div>
                </div>
                <div class="bbcc-contact-info__card">
                    <div class="bbcc-contact-info__icon">
                        <i class="fa-solid fa-envelope"></i>
                    </div>
                    <div class="bbcc-contact-info__text">
                        <h4>Email Address</h4>
                        <p>bhutanesecentrecanberra@gmail.com</p>
                    </div>
                </div>
                <div class="bbcc-contact-info__card">
                    <div class="bbcc-contact-info__icon">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="bbcc-contact-info__text">
                        <h4>Operating Hours</h4>
                        <p>Everyday : 6 AM – 10 PM</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

<?php if (!empty($message)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        icon: '<?= $msgType ?>',
        title: '<?= addslashes($message) ?>',
        showConfirmButton: false,
        timer: 2500,
        toast: false
    });
});
</script>
<?php endif; ?>

</body>
</html>
