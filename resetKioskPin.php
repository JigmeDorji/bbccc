<?php
require_once "include/config.php";
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

function has_parent_pin_hash(PDO $pdo): bool {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM parents LIKE 'pin_hash'");
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

ensure_kiosk_pin_resets_table($pdo);

$rawToken = trim($_GET['token'] ?? '');
if ($rawToken === '') {
    die("Invalid reset link.");
}
$tokenHash = hash('sha256', $rawToken);

$stmt = $pdo->prepare("
    SELECT id, parent_email, expires_at, used_at
    FROM kiosk_pin_resets
    WHERE token_hash = :h
    LIMIT 1
");
$stmt->execute([':h' => $tokenHash]);
$resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resetRow) {
    die("This kiosk PIN reset link is invalid or already used.");
}
if (!empty($resetRow['used_at'])) {
    die("This kiosk PIN reset link was already used. Please request a new one.");
}
if (strtotime((string)$resetRow['expires_at']) < time()) {
    die("This kiosk PIN reset link has expired. Please request a new one.");
}
if (!has_parent_pin_hash($pdo)) {
    die("Kiosk PIN feature is not enabled yet. Please contact admin.");
}

$email = strtolower(trim((string)($resetRow['parent_email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $pin = preg_replace('/\D+/', '', (string)($_POST['pin'] ?? ''));
    $confirm = preg_replace('/\D+/', '', (string)($_POST['confirm_pin'] ?? ''));

    if (!preg_match('/^\d{4,6}$/', $pin)) $errors[] = "PIN must be 4 to 6 digits.";
    if ($pin !== $confirm) $errors[] = "PIN and Confirm PIN do not match.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE parents SET pin_hash = :p WHERE LOWER(email) = LOWER(:e) LIMIT 1")
                ->execute([':p' => $pinHash, ':e' => $email]);

            $pdo->prepare("UPDATE kiosk_pin_resets SET used_at = :t WHERE id = :id")
                ->execute([':t' => date('Y-m-d H:i:s'), ':id' => (int)$resetRow['id']]);

            $pdo->commit();
            $message = "Kiosk PIN updated successfully. You can now use the kiosk.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = "Could not update kiosk PIN right now. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Kiosk PIN</title>
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
            <h4 class="mb-2"><i class="fas fa-lock mr-1"></i>Reset Kiosk PIN</h4>
            <p class="text-muted mb-3">Set a new 4 to 6 digit PIN for kiosk sign in/out.</p>

            <?php if (!empty($message)): ?>
                <script>
                document.addEventListener('DOMContentLoaded', () => {
                    Swal.fire({icon:'success', text:<?= json_encode($message) ?>, confirmButtonColor:'#881b12'})
                        .then(() => window.location = 'login');
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

            <?php if (empty($message)): ?>
            <form method="post" id="resetPinForm">
                <?= csrf_field() ?>
                <div class="form-group mb-3">
                    <label for="pin"><i class="fas fa-key mr-1"></i> New PIN</label>
                    <input type="password" class="form-control" id="pin" name="pin" pattern="\d{4,6}" maxlength="6" required inputmode="numeric" placeholder="4-6 digits">
                </div>
                <div class="form-group mb-3">
                    <label for="confirm_pin"><i class="fas fa-check mr-1"></i> Confirm PIN</label>
                    <input type="password" class="form-control" id="confirm_pin" name="confirm_pin" pattern="\d{4,6}" maxlength="6" required inputmode="numeric" placeholder="Re-enter PIN">
                </div>
                <button class="btn btn-primary btn-block" type="submit" id="resetBtn">
                    <i class="fas fa-save mr-1"></i> Update Kiosk PIN
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
document.getElementById('resetPinForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('resetBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Updating...';
});
</script>
</body>
</html>
