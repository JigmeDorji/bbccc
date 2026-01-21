<?php
require_once "include/config.php";
require_once "include/mailer.php";

$message = "";
$errors = [];

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME}",
        $DB_USER,
        $DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

function is_email(string $v): bool {
    return filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
}

function client_ip(): ?string {
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function user_agent(): ?string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier === '') {
        $errors[] = "Please enter your email address or phone number.";
    }

    if (empty($errors)) {

        // default generic message
        $message = "If an account exists, a password reset link has been sent.";

        $emailToReset = null;
        $parentName = "Parent";

        if (is_email($identifier)) {
            $emailToReset = strtolower($identifier);

            // Optional: fetch name for nicer email
            $stmt = $pdo->prepare("SELECT full_name FROM parents WHERE LOWER(email)=LOWER(:e) LIMIT 1");
            $stmt->execute([':e' => $emailToReset]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['full_name'])) $parentName = $row['full_name'];

        } else {
            // treat as phone, find parent email
            $stmt = $pdo->prepare("SELECT email, full_name FROM parents WHERE phone = :p LIMIT 1");
            $stmt->execute([':p' => $identifier]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && !empty($row['email'])) {
                $emailToReset = strtolower(trim($row['email']));
                if (!empty($row['full_name'])) $parentName = $row['full_name'];
            }
        }

        if ($emailToReset) {
            // create secure token
            $rawToken = bin2hex(random_bytes(32)); // sent in link
            $tokenHash = hash('sha256', $rawToken); // stored in DB

            $expiresAt = date('Y-m-d H:i:s', time() + (60 * 30)); // 30 mins

            // insert into YOUR table schema
            $stmtIns = $pdo->prepare("
                INSERT INTO password_resets
                    (user_email, token_hash, expires_at, used_at, created_at, request_ip, user_agent)
                VALUES
                    (:email, :hash, :exp, NULL, :crt, :ip, :ua)
            ");
            $stmtIns->execute([
                ':email' => $emailToReset,
                ':hash'  => $tokenHash,
                ':exp'   => $expiresAt,
                ':crt'   => date('Y-m-d H:i:s'),
                ':ip'    => client_ip(),
                ':ua'    => user_agent(),
            ]);

            // reset link (CHANGE to your real domain/path)
            $resetLink = "http://localhost/bbccc/resetPassword.php?token=" . urlencode($rawToken);

            $subject = "Reset your password - Bhutanese Centre Canberra";
            $body = "
                <div style='font-family: Arial, sans-serif;'>
                    <h3>Password Reset</h3>
                    <p>Hello " . htmlspecialchars($parentName) . ",</p>
                    <p>Click below to reset your password. This link expires in <strong>30 minutes</strong>.</p>
                    <p style='margin:18px 0;'>
                        <a href='{$resetLink}' style='background:#881b12;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;'>
                            Reset Password
                        </a>
                    </p>
                    <p>If you did not request this, ignore this email.</p>
                </div>
            ";

            $sent = send_mail($emailToReset, $parentName, $subject, $body);

            if (!$sent) {
                // keep UI generic, but log for you
                file_put_contents(__DIR__ . "/mail_error.log", date('c')." ForgotPassword send failed for {$emailToReset}\n", FILE_APPEND);
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <style>
        body{background:#f5f7fa;}
        .box{max-width:460px;margin:70px auto;background:#fff;padding:28px;border-radius:10px;box-shadow:0 4px 18px rgba(0,0,0,0.08);}
    </style>
</head>
<body>
<div class="box">
    <h3>Forgot Password</h3>
    <p>Enter your email address or phone number to receive a reset link.</p>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label>Email or Phone</label>
            <input type="text" name="identifier" class="form-control"
                   placeholder="example@gmail.com or 04xxxxxxxx"
                   value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>" required>
        </div>
        <button class="btn btn-primary">Send Reset Link</button>
        <a href="login.php" class="btn btn-default">Back to login</a>
    </form>
</div>
</body>
</html>
