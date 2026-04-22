<?php
require_once "include/config.php";
require_once "include/mailer.php";
require_once "include/pcm_helpers.php";
require_once "include/csrf.php";

$message = "";
$errors = [];

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
} catch (Exception $e) {
    bbcc_fail_db($e);
}

function pin_client_ip(): ?string {
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function pin_user_agent(): ?string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

function pin_norm_phone(string $raw): string {
    return preg_replace('/\D+/', '', $raw);
}

function ensure_kiosk_pin_resets_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kiosk_pin_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_email VARCHAR(255) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            request_ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            UNIQUE KEY uq_kpr_token_hash (token_hash),
            KEY idx_kpr_email (parent_email),
            KEY idx_kpr_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    ensure_kiosk_pin_resets_table($pdo);

    $phoneRaw = trim($_POST['phone'] ?? '');
    $phone = pin_norm_phone($phoneRaw);

    if ($phone === '' || strlen($phone) < 8) {
        $errors[] = "Please enter a valid phone number.";
    }

    $message = "If your phone number is registered, a kiosk PIN reset link has been sent to your email.";

    if (empty($errors)) {
        $stmt = $pdo->query("SELECT id, full_name, email, phone FROM parents WHERE email IS NOT NULL AND email <> ''");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $parent = null;
        foreach ($rows as $row) {
            if (pin_norm_phone((string)($row['phone'] ?? '')) === $phone) {
                $parent = $row;
                break;
            }
        }

        if ($parent && !empty($parent['email'])) {
            $emailToReset = strtolower(trim((string)$parent['email']));
            $parentName = trim((string)($parent['full_name'] ?? 'Parent'));
            if ($parentName === '') $parentName = 'Parent';

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', time() + (60 * 30));

            $stmtIns = $pdo->prepare("
                INSERT INTO kiosk_pin_resets
                    (parent_email, token_hash, expires_at, used_at, created_at, request_ip, user_agent)
                VALUES
                    (:email, :hash, :exp, NULL, :crt, :ip, :ua)
            ");
            $stmtIns->execute([
                ':email' => $emailToReset,
                ':hash'  => $tokenHash,
                ':exp'   => $expiresAt,
                ':crt'   => date('Y-m-d H:i:s'),
                ':ip'    => pin_client_ip(),
                ':ua'    => pin_user_agent(),
            ]);

            $resetLink = rtrim(BASE_URL, '/') . "/resetKioskPin?token=" . urlencode($rawToken);
            $safeName = htmlspecialchars($parentName, ENT_QUOTES, 'UTF-8');
            $subject = "Reset your kiosk PIN - Bhutanese Language and Culture School";
            $body = pcm_email_wrap('Kiosk PIN Reset', "
                <p style='margin:0 0 14px;'>Hello {$safeName},</p>
                <p style='margin:0 0 14px;'>We received a request to reset your kiosk PIN. Click below to set a new PIN (minimum 4 digits). This link expires in <strong>30 minutes</strong>.</p>
                <p style='margin:20px 0;'>
                    <a href='{$resetLink}' style='background:#881b12;color:#ffffff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:15px;' target='_blank'>
                        Reset Kiosk PIN
                    </a>
                </p>
                <p style='margin:0;color:#666666;font-size:13px;'>If you did not request this, please ignore this email.</p>
            ");

            $sent = send_mail($emailToReset, $parentName, $subject, $body);
            if (!$sent) {
                file_put_contents(__DIR__ . "/mail_error.log", date('c') . " ForgotKioskPin send failed for {$emailToReset}\n", FILE_APPEND);
                $errors[] = "We could not send the reset email at this time. Please try again later.";
                $message = '';
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
    <title>Forgot Kiosk PIN</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php include_once 'include/global_css.php'; ?>
</head>
<body>
<?php include_once 'include/nav.php'; ?>
<div class="container py-5" style="max-width:560px;">
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <h4 class="mb-2"><i class="fas fa-key mr-1"></i>Forgot Kiosk PIN</h4>
            <p class="text-muted mb-3">Enter your registered phone number. We will email you a secure PIN reset link.</p>

            <?php if (!empty($message)): ?>
                <script>
                document.addEventListener('DOMContentLoaded', () => {
                    Swal.fire({icon:'success', html:<?= json_encode(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) ?>, confirmButtonColor:'#881b12'});
                });
                </script>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" id="kioskPinForgotForm">
                <?= csrf_field() ?>
                <div class="form-group mb-3">
                    <label for="phone"><i class="fas fa-mobile-alt mr-1"></i> Phone Number</label>
                    <input type="text" class="form-control" id="phone" name="phone" required inputmode="numeric"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="04xx xxx xxx">
                </div>
                <button class="btn btn-primary btn-block" type="submit" id="submitBtn">
                    <i class="fas fa-paper-plane mr-1"></i> Send PIN Reset Link
                </button>
                <div class="text-center mt-3">
                    <a href="login">Back to login</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('kioskPinForgotForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Sending...';
});
</script>
</body>
</html>
