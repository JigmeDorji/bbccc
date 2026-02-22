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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password — Bhutanese Centre Canberra</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php include_once 'include/global_css.php'; ?>
    <style>
        :root { --brand:#881b12; --brand-dark:#6b140d; }

        .auth-page { min-height:100vh; display:flex; flex-direction:column; }

        .auth-hero {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color:#fff; padding:40px 0 60px; text-align:center; position:relative;
        }
        .auth-hero::after {
            content:''; position:absolute; bottom:-30px; left:0; right:0; height:60px;
            background:#f5f7fa; border-radius:50% 50% 0 0/100% 100% 0 0;
        }
        .auth-hero h1 { font-size:1.5rem; font-weight:700; }
        .auth-hero p { font-size:0.9rem; opacity:0.9; }

        .auth-card {
            max-width:480px; margin:-20px auto 40px; position:relative; z-index:2;
            background:#fff; border-radius:16px; box-shadow:0 8px 32px rgba(0,0,0,0.1); padding:32px;
        }

        .info-box { background:#fef3f2; border-left:4px solid var(--brand); border-radius:8px; padding:14px 18px; font-size:0.82rem; color:#555; margin-bottom:20px; }
        .info-box i { color:var(--brand); margin-right:6px; }

        .form-floating > .form-control { height:50px; border-radius:10px; border:1.5px solid #dee2e6; font-size:0.9rem; }
        .form-floating > .form-control:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(136,27,18,0.12); }
        .form-floating > label { font-size:0.85rem; color:#888; }

        .btn-brand { background:var(--brand); color:#fff; border:none; border-radius:10px; font-weight:600; font-size:0.95rem; padding:12px 28px; transition:all .2s; }
        .btn-brand:hover { background:var(--brand-dark); color:#fff; transform:translateY(-1px); box-shadow:0 4px 12px rgba(136,27,18,0.3); }

        .footer-links { text-align:center; margin-top:18px; font-size:0.88rem; }
        .footer-links a { color:var(--brand); font-weight:600; text-decoration:none; }
        .footer-links a:hover { text-decoration:underline; }
    </style>
</head>
<body>
<?php include_once 'include/nav.php'; ?>

<div class="auth-page">
    <div class="auth-hero">
        <div class="container">
            <h1><i class="fas fa-key me-2"></i>Forgot Password</h1>
            <p>We'll send you a reset link to get back in</p>
        </div>
    </div>

    <div class="auth-card">

        <?php if (!empty($message)): ?>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire({icon:'success', html:<?= json_encode(htmlspecialchars($message)) ?>, confirmButtonColor:'#881b12'});
        });
        </script>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="border-radius:10px; border:none; border-left:4px solid #dc3545;">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            Enter the <strong>email</strong> or <strong>phone number</strong> linked to your parent account.
        </div>

        <form method="post" id="forgotForm">
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="identifier" name="identifier"
                       placeholder="Email or Phone" required
                       value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" autocomplete="email">
                <label for="identifier"><i class="fas fa-envelope me-1"></i> Email or Phone Number</label>
            </div>

            <button type="submit" class="btn btn-brand w-100" id="sendBtn">
                <i class="fas fa-paper-plane me-2"></i>Send Reset Link
            </button>
        </form>

        <div class="footer-links">
            <a href="login.php"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
        </div>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
<script>
document.getElementById('forgotForm').addEventListener('submit', function() {
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
});
</script>
</body>
</html>
