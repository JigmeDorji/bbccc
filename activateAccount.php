<?php
require_once "include/config.php";
require_once "include/account_activation.php";

$result = ['ok' => false, 'message' => 'Invalid activation link.'];

try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $token = (string)($_GET['token'] ?? '');
    $result = bbcc_activate_account_with_token($pdo, $token);
} catch (Throwable $e) {
    error_log("Activation failed: " . $e->getMessage());
    $result = ['ok' => false, 'message' => 'Service temporarily unavailable. Please try again shortly.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Activation — Bhutanese Centre Canberra</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <?php include_once 'include/global_css.php'; ?>
    <style>
        :root { --brand:#881b12; --brand-dark:#6b140d; }
        .act-page { min-height:100vh; display:flex; flex-direction:column; }
        .act-hero {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color:#fff; padding:40px 0 60px; text-align:center; position:relative;
        }
        .act-hero::after {
            content:''; position:absolute; bottom:-30px; left:0; right:0; height:60px;
            background:#f5f7fa; border-radius:50% 50% 0 0/100% 100% 0 0;
        }
        .act-card {
            max-width:560px; margin:-20px auto 40px; position:relative; z-index:2;
            background:#fff; border-radius:16px; box-shadow:0 8px 32px rgba(0,0,0,0.1); padding:28px;
            text-align:center;
        }
        .btn-brand {
            background:var(--brand); color:#fff; border:none; border-radius:10px;
            font-weight:600; font-size:0.95rem; padding:12px 28px; text-decoration:none;
            display:inline-block;
        }
        .btn-brand:hover { background:var(--brand-dark); color:#fff; }
    </style>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>

<div class="act-page">
    <div class="act-hero">
        <div class="container">
            <h1><i class="fas fa-user-check me-2"></i>Account Activation</h1>
            <p>Verify your email to start using your account</p>
        </div>
    </div>

    <div class="act-card">
        <?php if (!empty($result['ok'])): ?>
            <div class="mb-3"><i class="fas fa-check-circle text-success" style="font-size:2.2rem;"></i></div>
            <h4 class="mb-2">Activation Successful</h4>
            <p class="text-muted mb-4"><?= htmlspecialchars((string)$result['message']) ?></p>
            <a class="btn-brand" href="login"><i class="fas fa-right-to-bracket me-2"></i>Go to Login</a>
        <?php else: ?>
            <div class="mb-3"><i class="fas fa-circle-exclamation text-danger" style="font-size:2.2rem;"></i></div>
            <h4 class="mb-2">Activation Failed</h4>
            <p class="text-muted mb-4"><?= htmlspecialchars((string)$result['message']) ?></p>
            <a class="btn-brand" href="login"><i class="fas fa-arrow-left me-2"></i>Back to Login</a>
        <?php endif; ?>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
