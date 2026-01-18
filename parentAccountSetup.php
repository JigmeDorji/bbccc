<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
require_once "include/config.php";

$message = "";
$signupSuccess = false;

// ✅ Keep old values so form doesn't clear on errors
$old = [
    'full_name' => '',
    'gender'    => '',
    'email'     => '',
    'phone'     => '',
    'address'   => ''
];

// ✅ Helper: basic sanitize
function clean($v) {
    return trim((string)$v);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // DB Connection
        $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // ---------------- FORM VALUES ----------------
        $full_name = clean($_POST['full_name'] ?? '');
        $gender    = clean($_POST['gender'] ?? '');
        $email     = strtolower(clean($_POST['email'] ?? ''));
        $phone     = clean($_POST['phone'] ?? '');
        $address   = clean($_POST['address'] ?? '');

        $password_plain  = (string)($_POST['password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        // Store old values (for sticky form)
        $old['full_name'] = $full_name;
        $old['gender']    = $gender;
        $old['email']     = $email;
        $old['phone']     = $phone;
        $old['address']   = $address;

        // ---------------- VALIDATION ----------------
        if ($full_name === '') {
            throw new Exception("Full Name is required.");
        }

        if (!in_array($gender, ['Male','Female','Other'], true)) {
            throw new Exception("Please select a valid Gender.");
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid Email Address.");
        }

        // Basic AU mobile-ish check (not strict)
        if ($phone === '') {
            throw new Exception("Mobile Number is required.");
        }
        if (!preg_match('/^[0-9 +()-]{8,20}$/', $phone)) {
            throw new Exception("Please enter a valid Mobile Number.");
        }

        if ($address === '') {
            throw new Exception("Address is required.");
        }

        // Password rules (normal requirements)
        // - at least 8 chars
        // - at least 1 letter
        // - at least 1 number
        if (strlen($password_plain) < 8) {
            throw new Exception("Password must be at least 8 characters long.");
        }
        if (!preg_match('/[A-Za-z]/', $password_plain) || !preg_match('/[0-9]/', $password_plain)) {
            throw new Exception("Password must include at least 1 letter and 1 number.");
        }
        if ($password_plain !== $confirm_password) {
            throw new Exception("Password and Confirm Password do not match.");
        }

        // ✅ Email as username only
        $username = $email;

        // ---------------- DUPLICATE CHECKS ----------------
        // Check parents email already exists
        $stmtCheck = $pdo->prepare("SELECT id FROM parents WHERE LOWER(email) = LOWER(:e) LIMIT 1");
        $stmtCheck->execute([':e' => $email]);
        if ($stmtCheck->fetchColumn()) {
            throw new Exception("Email is already registered. Please use a different email address.");
        }

        // Check user table username already exists
        $stmtCheckUser = $pdo->prepare("SELECT userid FROM `user` WHERE LOWER(username) = LOWER(:u) LIMIT 1");
        $stmtCheckUser->execute([':u' => $username]);
        if ($stmtCheckUser->fetchColumn()) {
            throw new Exception("Email is already registered. Please use a different email address.");
        }

        // Hash password (store hash only)
        $password = password_hash($password_plain, PASSWORD_DEFAULT);

        // ---------------- TRANSACTION ----------------
        $pdo->beginTransaction();

        // Generate user ID (example: P + 9 chars)
        $userid = 'P' . substr(uniqid(), -9);

        // Insert into parents table
        // NOTE: This assumes parents table has columns: full_name, gender, email, phone, address, username, password
        $stmtParents = $pdo->prepare("
            INSERT INTO parents (full_name, gender, email, phone, address, username, password)
            VALUES (:full_name, :gender, :email, :phone, :address, :username, :password)
        ");

        $stmtParents->execute([
            ':full_name' => $full_name,
            ':gender'    => $gender,
            ':email'     => $email,
            ':phone'     => $phone,
            ':address'   => $address,
            ':username'  => $username,
            ':password'  => $password
        ]);

        // Insert into user table
        $stmtUser = $pdo->prepare("
            INSERT INTO `user` (userid, username, password, role, createdDate)
            VALUES (:userid, :username, :password, :role, :createdDate)
        ");

        $stmtUser->execute([
            ':userid'      => $userid,
            ':username'    => $username,
            ':password'    => $password,
            ':role'        => 'parent',
            ':createdDate' => date('Y-m-d H:i:s')
        ]);

        $pdo->commit();

        // ---------------- EMAIL ----------------
        // ✅ Do NOT email passwords. Just confirm account creation.
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'dorjijigme32@gmail.com';
            $mail->Password   = 'qssf jqwo nptu lbfb';   // ⚠️ Ideally move to env/config
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
                        <h2 style='color: #881b12;'>Welcome, " . htmlspecialchars($full_name) . "!</h2>
                        <p>Thank you for creating a parent account at <strong style='color: #881b12;'>Bhutanese Centre Canberra</strong>.</p>
                        <p><strong>Login email:</strong> " . htmlspecialchars($email) . "</p>

                        <a href='login.php' style='display: inline-block; padding: 10px 20px; background-color: #881b12; color: #fff; text-decoration: none; border-radius: 5px; margin-top: 10px;'>
                            Login Now
                        </a>

                        <p style='margin-top: 20px;'>Thank you,<br><strong style='color: #881b12;'>Bhutanese Centre Canberra</strong></p>
                    </div>
                </body>
                </html>
            ";

            $mail->send();
            $message = "Parent account created successfully! Please login.";
            $signupSuccess = true;

        } catch (Exception $e) {
            // Account is created, email failed - still redirect to login.
            $message = "Account created successfully, but email could not be sent.";
            $signupSuccess = true;
        }

    } catch (Exception $e) {
        // If a transaction started and failed, rollback
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Error: " . $e->getMessage();
        $signupSuccess = false;
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
    .signup-wrapper { max-width: 520px; margin: 60px auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.1); }
    .signup-wrapper h3 { text-align: center; margin-bottom: 30px; color: #881b12; }
    .form-control { height: 42px; border-radius: 6px; }
    .btn-primary { width: 100%; height: 45px; font-size: 16px; border-radius: 6px; background-color: #881b12; border: none; }
    .footer-text { text-align: center; margin-top: 15px; }
    .help-text { color:#6c757d; font-size: 12px; margin-top: 5px; }
</style>
<?php include_once 'include/global_css.php'; ?>
</head>

<body>
<?php include_once 'include/nav.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const msg = <?php echo json_encode($message); ?>;
    const isSuccess = <?php echo $signupSuccess ? 'true' : 'false'; ?>;

    if (msg) {
        Swal.fire({
            icon: isSuccess ? 'success' : 'error',
            title: msg,
            timer: isSuccess ? 2000 : 4000,
            showConfirmButton: true
        }).then(() => {
            if (isSuccess) {
                window.location.href = "login.php";
                return;
            }

            const lower = (msg || "").toLowerCase();

            // duplicate email → focus email
            if (lower.includes("email is already registered")) {
                const email = document.querySelector('input[name="email"]');
                if (email) { email.focus(); email.select(); }
            }

            // password issues → clear only password fields
            if (lower.includes("password")) {
                const p1 = document.querySelector('input[name="password"]');
                const p2 = document.querySelector('input[name="confirm_password"]');
                if (p1) p1.value = "";
                if (p2) p2.value = "";
                if (p1) p1.focus();
            }
        });
    }
});
</script>

<div class="signup-wrapper">
    <h3>Parent Sign Up</h3>

    <div class="alert alert-info">
        <strong>Login:</strong> Your <strong>Email Address</strong> will be your username.
        <div class="help-text">Password must be at least 8 characters and include at least 1 letter and 1 number.</div>
    </div>

    <form method="POST" action="">
        <div class="form-group">
            <label>Full Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="full_name" placeholder="Enter full name" required
                   value="<?php echo htmlspecialchars($old['full_name']); ?>">
        </div>

        <div class="form-group">
            <label>Gender <span class="text-danger">*</span></label>
            <select class="form-control" name="gender" required>
                <option value="">-- Select --</option>
                <option value="Male"   <?php echo ($old['gender']==='Male')?'selected':''; ?>>Male</option>
                <option value="Female" <?php echo ($old['gender']==='Female')?'selected':''; ?>>Female</option>
                <option value="Other"  <?php echo ($old['gender']==='Other')?'selected':''; ?>>Other</option>
            </select>
        </div>

        <div class="form-group">
            <label>Email Address <span class="text-danger">*</span></label>
            <input type="email" class="form-control" name="email" placeholder="Enter email" required
                   value="<?php echo htmlspecialchars($old['email']); ?>">
            <div class="help-text">This email will be used to log in.</div>
        </div>

        <div class="form-group">
            <label>Mobile Number <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="phone" placeholder="Enter mobile number" required
                   value="<?php echo htmlspecialchars($old['phone']); ?>">
        </div>

        <div class="form-group">
            <label>Address <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="address" placeholder="Enter address" required
                   value="<?php echo htmlspecialchars($old['address']); ?>">
        </div>

        <div class="form-group">
            <label>Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" name="password" placeholder="Create password" required>
            <div class="help-text">At least 8 chars, include letter + number.</div>
        </div>

        <div class="form-group">
            <label>Confirm Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" name="confirm_password" placeholder="Re-enter password" required>
        </div>

        <button type="submit" class="btn btn-primary">Create Account</button>
        <p class="footer-text">Already registered? <a href="login.php">Login here</a></p>
    </form>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
