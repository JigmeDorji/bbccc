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
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/font/flaticon.css">
    <link rel="stylesheet" href="assets/css/plugins/owl.carousel.min.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
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
<body>

<?php include_once 'include/nav.php'; ?>

<main>
    <section class="abt-01">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="seting">
                        <h3>Contact Us</h3>
                        <ol>
                            <li>Home <i class="flaticon-double-right-arrow"></i></li>
                            <li>Contact Us</li>
                        </ol>
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

<?php include_once 'include/footer.php'; ?>

</body>
</html>
