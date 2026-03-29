<?php
require_once "include/config.php";

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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password — Bhutanese Centre Canberra</title>
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

        .form-floating > .form-control { height:50px; border-radius:10px; border:1.5px solid #dee2e6; font-size:0.9rem; }
        .form-floating > .form-control:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(136,27,18,0.12); }
        .form-floating > label { font-size:0.85rem; color:#888; }

        .btn-brand { background:var(--brand); color:#fff; border:none; border-radius:10px; font-weight:600; font-size:0.95rem; padding:12px 28px; transition:all .2s; }
        .btn-brand:hover { background:var(--brand-dark); color:#fff; transform:translateY(-1px); box-shadow:0 4px 12px rgba(136,27,18,0.3); }

        .pw-strength { height:4px; border-radius:2px; background:#e9ecef; margin-top:6px; overflow:hidden; }
        .pw-strength-bar { height:100%; width:0; border-radius:2px; transition:width .3s, background .3s; }
        .pw-hint { font-size:0.72rem; color:#999; margin-top:4px; }
        .pw-hint .met { color:#28a745; }
        .pw-hint .unmet { color:#ccc; }

        .pw-wrapper { position:relative; }
        .pw-wrapper .form-control { padding-right:48px; }
        .pw-toggle { position:absolute; right:10px; top:14px; background:none; border:none; color:#858796; cursor:pointer; padding:4px 8px; font-size:0.85rem; }
        .pw-toggle:hover { color:var(--brand); }

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
            <h1><i class="fas fa-lock-open me-2"></i>Reset Password</h1>
            <p>Create a new secure password for your account</p>
        </div>
    </div>

    <div class="auth-card">

    <?php if (!empty($message)): ?>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire({icon:'success', title:'Password Updated!', text:'You can now login with your new password.', confirmButtonColor:'#881b12'}).then(()=>window.location='login.php');
        });
        </script>
        <div class="text-center py-3">
            <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
            <h5 class="fw-bold">Password Updated Successfully</h5>
            <p class="text-muted">Redirecting to login...</p>
            <a class="btn btn-brand" href="login"><i class="fas fa-sign-in-alt me-2"></i>Go to Login</a>
        </div>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="border-radius:10px; border:none; border-left:4px solid #dc3545;">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="mb-3 text-center">
            <small class="text-muted">Resetting password for: <strong><?= htmlspecialchars($email) ?></strong></small>
        </div>

        <form method="post" id="resetForm">
            <div class="form-floating mb-2">
                <div class="pw-wrapper">
                    <input type="password" class="form-control" id="password" name="password" placeholder="New Password" required minlength="8" autocomplete="new-password" style="height:50px; border-radius:10px; border:1.5px solid #dee2e6; padding-right:48px;">
                    <button type="button" class="pw-toggle" onclick="togglePw('password', this)"><i class="fas fa-eye"></i></button>
                </div>
                <div class="pw-strength"><div class="pw-strength-bar" id="strengthBar"></div></div>
                <div class="pw-hint" id="pwHints">
                    <span class="unmet" data-check="len"><i class="fas fa-circle" style="font-size:0.5rem;"></i> 8+ chars</span> &nbsp;
                    <span class="unmet" data-check="letter"><i class="fas fa-circle" style="font-size:0.5rem;"></i> 1 letter</span> &nbsp;
                    <span class="unmet" data-check="num"><i class="fas fa-circle" style="font-size:0.5rem;"></i> 1 number</span>
                </div>
            </div>

            <div class="form-floating mb-3" style="margin-top:18px;">
                <div class="pw-wrapper">
                    <input type="password" class="form-control" id="confirm" name="confirm_password" placeholder="Confirm Password" required autocomplete="new-password" style="height:50px; border-radius:10px; border:1.5px solid #dee2e6; padding-right:48px;">
                    <button type="button" class="pw-toggle" onclick="togglePw('confirm', this)"><i class="fas fa-eye"></i></button>
                </div>
                <div id="matchFeedback" style="font-size:0.72rem; margin-top:4px;"></div>
            </div>

            <button type="submit" class="btn btn-brand w-100" id="resetBtn">
                <i class="fas fa-shield-alt me-2"></i>Update Password
            </button>
        </form>

        <div class="footer-links">
            <a href="login"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
        </div>

    <?php endif; ?>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
<script>
function togglePw(id, btn) {
    const f = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (f.type === 'password') { f.type = 'text'; icon.className = 'fas fa-eye-slash'; }
    else { f.type = 'password'; icon.className = 'fas fa-eye'; }
}

const pwField = document.getElementById('password');
const confirmField = document.getElementById('confirm');
const bar = document.getElementById('strengthBar');

if (pwField) {
    pwField.addEventListener('input', function() {
        const v = this.value;
        let s = 0;
        if (v.length >= 8) s++;
        if (/[A-Za-z]/.test(v)) s++;
        if (/[0-9]/.test(v)) s++;
        if (/[^A-Za-z0-9]/.test(v)) s++;

        const w = [0,25,50,75,100][s];
        const c = ['#dc3545','#dc3545','#ffc107','#28a745','#28a745'][s];
        bar.style.width = w + '%';
        bar.style.background = c;

        // Hints
        const hints = document.getElementById('pwHints');
        hints.querySelector('[data-check=len]').className = v.length >= 8 ? 'met' : 'unmet';
        hints.querySelector('[data-check=letter]').className = /[A-Za-z]/.test(v) ? 'met' : 'unmet';
        hints.querySelector('[data-check=num]').className = /[0-9]/.test(v) ? 'met' : 'unmet';

        checkMatch();
    });
}

if (confirmField) {
    confirmField.addEventListener('input', checkMatch);
}

function checkMatch() {
    const fb = document.getElementById('matchFeedback');
    if (!confirmField.value) { fb.innerHTML = ''; return; }
    if (pwField.value === confirmField.value) {
        fb.innerHTML = '<span style="color:#28a745;"><i class="fas fa-check-circle"></i> Passwords match</span>';
        confirmField.style.borderColor = '#28a745';
    } else {
        fb.innerHTML = '<span style="color:#dc3545;"><i class="fas fa-times-circle"></i> Passwords do not match</span>';
        confirmField.style.borderColor = '#dc3545';
    }
}

document.getElementById('resetForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('resetBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
});
</script>
</body>
</html>
