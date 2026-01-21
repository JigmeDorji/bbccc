<?php
require_once "include/config.php";

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

$rawToken = trim($_GET['token'] ?? '');
if ($rawToken === '') {
    die("Invalid reset link.");
}

$tokenHash = hash('sha256', $rawToken);

// Find token row
$stmt = $pdo->prepare("
    SELECT id, user_email, expires_at, used_at
    FROM password_resets
    WHERE token_hash = :h
    LIMIT 1
");
$stmt->execute([':h' => $tokenHash]);
$resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resetRow) {
    die("This reset link is invalid or already used.");
}

if (!empty($resetRow['used_at'])) {
    die("This reset link was already used. Please request a new one.");
}

if (strtotime($resetRow['expires_at']) < time()) {
    die("This reset link has expired. Please request a new one.");
}

$email = strtolower(trim($resetRow['user_email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    if ($password !== $confirm) $errors[] = "Password and Confirm Password do not match.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $hashed = password_hash($password, PASSWORD_DEFAULT);

            // Update `user` table (email is username)
            $stmtU = $pdo->prepare("
                UPDATE user
                SET password = :p
                WHERE LOWER(username) = LOWER(:e)
                LIMIT 1
            ");
            $stmtU->execute([':p' => $hashed, ':e' => $email]);

            // Update `parents` table too (if you store password there)
            $stmtP = $pdo->prepare("
                UPDATE parents
                SET password = :p
                WHERE LOWER(email) = LOWER(:e)
                LIMIT 1
            ");
            $stmtP->execute([':p' => $hashed, ':e' => $email]);

            // Mark token used
            $stmtUsed = $pdo->prepare("UPDATE password_resets SET used_at = :t WHERE id = :id");
            $stmtUsed->execute([':t' => date('Y-m-d H:i:s'), ':id' => (int)$resetRow['id']]);

            $pdo->commit();

            $message = "Password updated successfully. You can now log in.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Update failed: " . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reset Password</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <style>
        body{background:#f5f7fa;}
        .box{max-width:460px;margin:70px auto;background:#fff;padding:28px;border-radius:10px;box-shadow:0 4px 18px rgba(0,0,0,0.08);}
    </style>
</head>
<body>
<div class="box">
    <h3>Reset Password</h3>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <a class="btn btn-primary" href="login.php">Go to Login</a>
    <?php else: ?>

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
                <label>New Password</label>
                <input class="form-control" type="password" name="password" required placeholder="Min 8 characters">
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input class="form-control" type="password" name="confirm_password" required placeholder="Re-enter password">
            </div>
            <button class="btn btn-primary">Update Password</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
