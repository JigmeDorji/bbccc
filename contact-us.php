<?php
require_once "include/config.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $name = $_POST['name'];
        $email = $_POST['email'];
        $subject = $_POST['subject'];
        $messageContent = $_POST['message'];

        $stmt = $pdo->prepare("INSERT INTO contact (name, email, subject, message) VALUES (:name, :email, :subject, :message)");

        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':subject', $subject, PDO::PARAM_STR);
        $stmt->bindParam(':message', $messageContent, PDO::PARAM_STR);

        $stmt->execute();

        $message = "Form data saved successfully!";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        if ('$message' !== '') {
            Swal.fire({
                icon: '" . ($message == 'Form data saved successfully!' ? 'success' : 'error') . "',
                title: '$message',
                showConfirmButton: false,
                timer: 1500
            });
        }
    });
</script>";
?>





<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Contact Us</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

</head>
<body>



</body>
</html>




<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Contact Us</title>

    <?php include_once 'include/global_css.php'; ?>

</head>
<body>

<?php include_once 'include/nav.php'; ?>

<main>
    <div class="hero_brd_area">
        <div class="container">
            <div class="hero_content">
                <h2 class="wow fadeInUp" data-wow-delay="0.3s">Contact Us</h2>
                <ul class="wow fadeInUp" data-wow-delay="0.5s">
                    <li><a href="index.html">Home</a></li>
                    <li>/</li>
                    <li>Contact Us</li>
                </ul>
            </div>
        </div>
    </div>


    <div class="contact_area contact_us">
        <div class="container">
            <div class="row">
                <div class="col-md-12">
                    <div class="section_title">
                        <h2>Our <span>Contact</span></h2>
                        <img src="img/logo/line-1.png" alt="" />
                        <p>It is a long-established fact that meaningful connections drive innovation. Reach out to explore how we can craft strategic tech solutions tailored to your business.</p>
                    </div>
                </div>
            </div>
            <div class="row">
                <form id="contact-form" action="contact-us.php" method="POST">
                    <div class="col-md-5">
                        <div class="contact_form">
                            <div class="input_boxes">
                                <input type="text" name="name" placeholder="Full name" required />
                            </div>
                            <div class="input_boxes">
                                <input type="email" name="email" placeholder="Email address" required />
                            </div>
                            <div class="input_boxes">
                                <input type="text" name="phone" placeholder="Phone number" />
                            </div>
                            <div class="input_boxes">
                                <input type="text" name="subject" placeholder="Subject" />
                            </div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="message_form">
                            <div class="input_boxes textarea">
                                <textarea class="message_box" name="message" placeholder="Message"></textarea>
                            </div>
                            <button type="submit" class="sbuton">Send Message</button>
                        </div>
                    </div>
                </form>
                <div class="col-md-12">
                    <p class="form-messege"></p>
                </div>
            </div>
            <div class="row">
                <div class="company_information">
                    <div class="col-md-4">
                        <div class="single_contact">
                            <div class="address">
                                <div class="contact_icon">
                                    <i class="fa fa-map-marker"></i>
                                </div>
                                <div class="address_content">
                                    <h3>Office Location</h3>
                                    <p>76/A, Babesa, Thimphu, Bhutan</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="single_contact">
                            <div class="address">
                                <div class="contact_icon">
                                    <i class="fa fa-phone"></i>
                                </div>
                                <div class="address_content">
                                    <h3>Phone Number:</h3>
                                    <p>+975 8754 3433 223</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="single_contact">
                            <div class="address">
                                <div class="contact_icon">
                                    <i class="fa fa-envelope-o"></i>
                                </div>
                                <div class="address_content">
                                    <h3>Email Address</h3>
                                    <p>tobgaytechstrat@gmail.com</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include_once 'include/footer.php'; ?>

</body>
</html>
