<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; 
require_once "include/config.php"; 

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // DB Connection
        $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Form values
        $full_name  = $_POST['full_name'];
        $email      = $_POST['email'];
        $phone      = $_POST['phone'];
        $address    = $_POST['address'];
        $occupation = $_POST['occupation'];
        $username   = $_POST['username'];
        $password_plain = $_POST['password']; // store plain password for email
        $password   = password_hash($password_plain, PASSWORD_DEFAULT);

        // Insert Query
       // Start transaction
        $pdo->beginTransaction();

        // Generate user ID (example: P000123456)
        $userid = 'P' . substr(uniqid(), -9);

        // Insert into parents table
        $stmtParents = $pdo->prepare("
            INSERT INTO parents (full_name, email, phone, address, occupation, username, password)
            VALUES (:full_name, :email, :phone, :address, :occupation, :username, :password)
        ");

        $stmtParents->execute([
            ':full_name'  => $full_name,
            ':email'      => $email,
            ':phone'      => $phone,
            ':address'    => $address,
            ':occupation' => $occupation,
            ':username'   => $username,
            ':password'   => $password
        ]);

        // Insert into user table
        $stmtUser = $pdo->prepare("
            INSERT INTO user (userid, username, password, role, createdDate)
            VALUES (:userid, :username, :password, :role, :createdDate)
        ");

        $stmtUser->execute([
            ':userid'      => $userid,
            ':username'    => $username,
            ':password'    => $password,
            ':role'        => 'parent',
            ':createdDate' => date('Y-m-d H:i:s')
        ]);

        // Commit transaction
        $pdo->commit();

        // Send Welcome Email using PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'dorjijigme32@gmail.com'; 
            $mail->Password   = 'qssf jqwo nptu lbfb';   
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;

            $mail->setFrom('dorjijigme32@gmail.com', 'Bhutanese Centre Canberra');
            $mail->addAddress($email, $full_name);

            $mail->isHTML(true);
            $mail->Subject = 'Welcome to Bhutanese Centre Canberra';
            $mail->Body = "
            <html>
            <body style='font-family: Arial, sans-serif; background-color: #f5f7fa; padding: 20px;'>
                <div style='max-width: 600px; margin: auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.1);'>
                    <h2 style='color: #881b12;'>Welcome, $full_name!</h2>
                    <p>Thank you for creating a parent account at <strong style='color: #881b12;'>Bhutanese Centre Canberra</strong>. We are thrilled to have you with us.</p>
                    
                    <table style='width: 100%; margin-top: 20px; border-collapse: collapse;'>
                        <tr>
                            <td style='padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;'><strong>Username:</strong></td>
                            <td style='padding: 10px; border: 1px solid #ddd;'>$username</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;'><strong>Password:</strong></td>
                            <td style='padding: 10px; border: 1px solid #ddd;'>$password_plain</td>
                        </tr>
                    </table>

                    <p style='margin-top: 20px;'>Please keep your credentials safe. You can now log in and access all parent resources.</p>

                    <a href='https://your-school-website.com/login' style='display: inline-block; padding: 10px 20px; background-color: #881b12; color: #fff; text-decoration: none; border-radius: 5px; margin-top: 10px;'>Login Now</a>

                    <p style='margin-top: 20px;'>Thank you,<br><strong style='color: #881b12;'>Bhutanese Centre Canberra</strong></p>
                </div>
            </body>
            </html>
            ";

            $mail->send();
            $message = "Parent account created successfully! A welcome email has been sent.";
        } catch (Exception $e) {
            $message = "Parent account created, but email could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Parent Sign Up</title>

<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    body { background: #f5f7fa; font-family: "Helvetica Neue", Arial, sans-serif; }
    .signup-wrapper { max-width: 500px; margin: 60px auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.1); }
    .signup-wrapper h3 { text-align: center; margin-bottom: 30px; color: #881b12; }
    .form-control { height: 42px; border-radius: 6px; }
    .btn-primary { width: 100%; height: 45px; font-size: 16px; border-radius: 6px; background-color: #881b12; border: none; }
    .footer-text { text-align: center; margin-top: 15px; }
</style>
<?php
    include_once 'include/global_css.php'
    ?>
</head>
<body>

<?php include_once 'include/nav.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let msg = "<?php echo $message; ?>";
    if (msg !== "") {
        Swal.fire({
            icon: msg.includes("successfully") ? 'success' : 'error',
            title: msg,
            timer: 2500,
            showConfirmButton: false
        });
    }
});
</script>

<div class="signup-wrapper">
    <h3>Parent Sign Up</h3>
    <form method="POST" action="">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" class="form-control" name="full_name" placeholder="Enter full name" required>
        </div>
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" class="form-control" name="email" placeholder="Enter email" required>
        </div>
        <div class="form-group">
            <label>Phone Number</label>
            <input type="text" class="form-control" name="phone" placeholder="Enter phone number">
        </div>
        <div class="form-group">
            <label>Address</label>
            <input type="text" class="form-control" name="address" placeholder="Enter address">
        </div>
        <div class="form-group">
            <label>Occupation</label>
            <input type="text" class="form-control" name="occupation" placeholder="Enter occupation">
        </div>
        <div class="form-group">
            <label>Username</label>
            <input type="text" class="form-control" name="username" placeholder="Create username" required>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" class="form-control" name="password" placeholder="Create password" required>
        </div>
        <button type="submit" class="btn btn-primary">Create Account</button>
        <p class="footer-text">Already registered? <a href="login.php">Login here</a></p>
    </form>
</div>

<?php include_once 'include/footer.php'; ?>
















</body>
</html>
