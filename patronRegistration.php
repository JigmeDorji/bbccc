<?php
require_once "include/config.php";
require_once "include/csrf.php";
require_once "include/mailer.php";
require_once "include/pcm_helpers.php";
require_once "include/account_activation.php";
require_once "include/patron_schema.php";
require_once "include/user_id_helper.php";
require_once "include/email_verification.php";

$message = "";
$isSuccess = false;

$old = [
    'full_name' => '',
    'email'     => '',
    'phone'     => '',
    'address'   => ''
];

function clean_text($value) {
    return trim((string)$value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        if (!bbcc_verify_form_nonce_once('patron_signup_submit')) {
            throw new Exception("Duplicate submission detected. Please submit once and wait.");
        }

        $fullName = clean_text($_POST['full_name'] ?? '');
        $email = strtolower(clean_text($_POST['email'] ?? ''));
        $phone = clean_text($_POST['phone'] ?? '');
        $address = clean_text($_POST['address'] ?? '');
        $passwordPlain = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        $old = [
            'full_name' => $fullName,
            'email'     => $email,
            'phone'     => $phone,
            'address'   => $address
        ];

        if ($fullName === '') {
            throw new Exception("Full Name is required.");
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid Email Address.");
        }
        if ($phone === '' || !preg_match('/^[0-9 +()-]{8,20}$/', $phone)) {
            throw new Exception("Please enter a valid Mobile Number.");
        }
        if ($address === '') {
            throw new Exception("Address is required.");
        }
        $verifiedEmail = strtolower((string)($_SESSION['verified_email'] ?? ''));
        $verifiedAt = (int)($_SESSION['verified_email_at'] ?? 0);
        $verifiedPurpose = strtolower((string)($_SESSION['verified_email_purpose'] ?? ''));
        if ($verifiedEmail !== strtolower($email) || (time() - $verifiedAt) > 1800 || $verifiedPurpose !== 'patron_signup') {
            throw new Exception("Please verify your email address first.");
        }
        if (strlen($passwordPlain) < 8) {
            throw new Exception("Password must be at least 8 characters long.");
        }
        if (!preg_match('/[A-Za-z]/', $passwordPlain) || !preg_match('/[0-9]/', $passwordPlain)) {
            throw new Exception("Password must include at least 1 letter and 1 number.");
        }
        if ($passwordPlain !== $confirmPassword) {
            throw new Exception("Password and Confirm Password do not match.");
        }

        $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);
        bbcc_activation_ensure_schema($pdo);
        bbcc_ensure_patrons_table($pdo);

        $existsUser = $pdo->prepare("SELECT userid FROM `user` WHERE LOWER(username) = LOWER(:email) LIMIT 1");
        $existsUser->execute([':email' => $email]);
        if ($existsUser->fetchColumn()) {
            throw new Exception("Email is already registered. Please login or use forgot password.");
        }

        $existsPatron = $pdo->prepare("SELECT id FROM patrons WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $existsPatron->execute([':email' => $email]);
        if ($existsPatron->fetchColumn()) {
            throw new Exception("Patron email already exists. Please login or use forgot password.");
        }

        $passwordHash = password_hash($passwordPlain, PASSWORD_DEFAULT);
        $userId = bbcc_generate_userid($pdo, 'PT');

        $pdo->beginTransaction();

        $insertUser = $pdo->prepare("
            INSERT INTO `user` (userid, username, password, role, is_active, createdDate)
            VALUES (:userid, :username, :password, :role, :is_active, :createdDate)
        ");
        $insertUser->execute([
            ':userid'     => $userId,
            ':username'   => $email,
            ':password'   => $passwordHash,
            ':role'       => 'patron',
            ':is_active'  => 0,
            ':createdDate'=> date('Y-m-d H:i:s')
        ]);

        $activationToken = bbcc_issue_activation_token($pdo, $userId, $email, 48);

        $insertPatron = $pdo->prepare("
            INSERT INTO patrons (parent_id, full_name, email, phone, address, patron_type, status, created_at)
            VALUES (NULL, :full_name, :email, :phone, :address, :patron_type, :status, NOW())
        ");
        $insertPatron->execute([
            ':full_name'   => $fullName,
            ':email'       => $email,
            ':phone'       => $phone,
            ':address'     => $address,
            ':patron_type' => 'Regular',
            ':status'      => 'Active'
        ]);

        $pdo->commit();

        $activationLink = bbcc_activation_link($activationToken);
        $sent = bbcc_send_patron_activation_email($email, $fullName, $activationLink);
        if (!$sent) {
            error_log("Patron signup activation email failed for {$email}");
            $message = "Patron account created, but activation email could not be sent. Please contact admin.";
        } else {
            $message = "Patron account created successfully. Please check your inbox for the activation email. If you do not see it, check Spam/Junk and mark it as Not Spam, then open the activation link.";
        }
        unset($_SESSION['verified_email'], $_SESSION['verified_email_at'], $_SESSION['verified_email_purpose']);
        $isSuccess = true;
        $old = ['full_name' => '', 'email' => '', 'phone' => '', 'address' => ''];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Error: " . $e->getMessage();
        $isSuccess = false;
    }
}

$sessionVerifiedEmail = strtolower((string)($_SESSION['verified_email'] ?? ''));
$sessionVerifiedAt = (int)($_SESSION['verified_email_at'] ?? 0);
$sessionVerifiedPurpose = strtolower((string)($_SESSION['verified_email_purpose'] ?? ''));
$isSessionOtpValid = ($sessionVerifiedEmail !== '' && (time() - $sessionVerifiedAt) <= 1800 && $sessionVerifiedPurpose === 'patron_signup');
$isPreverifiedEmail = $isSessionOtpValid && !empty($old['email']) && strtolower((string)$old['email']) === $sessionVerifiedEmail;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patron Registration — Bhutanese Centre Canberra</title>
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
        .auth-card {
            max-width:640px; margin:-20px auto 40px; position:relative; z-index:2;
            background:#fff; border-radius:16px; box-shadow:0 8px 32px rgba(0,0,0,0.1); padding:28px;
        }
        .form-floating > .form-control {
            height:50px; border-radius:10px; border:1.5px solid #dee2e6; font-size:0.9rem;
        }
        .form-floating > .form-control:focus {
            border-color:var(--brand); box-shadow:0 0 0 3px rgba(136,27,18,0.12);
        }
        .btn-brand {
            background:var(--brand); color:#fff; border:none; border-radius:10px;
            font-weight:600; font-size:0.95rem; padding:12px 28px;
        }
        .btn-brand:hover { background:var(--brand-dark); color:#fff; }
        .verify-box {
            background:#fef3f2;
            border:1px solid #f1d2cf;
            border-left:4px solid var(--brand);
            border-radius:12px;
            padding:14px;
            margin-bottom:16px;
        }
        .verify-badge {
            display:none;
            margin-top:10px;
            padding:8px 12px;
            border-radius:10px;
            background:#d4edda;
            border:1px solid #c3e6cb;
            color:#155724;
            font-weight:600;
            font-size:.86rem;
        }
    </style>
</head>
<body>
<?php include_once 'include/nav.php'; ?>

<div class="auth-page">
    <div class="auth-hero">
        <div class="container">
            <h1><i class="fas fa-hands-helping me-2"></i>Patron Registration</h1>
            <p>Create a patron account now. You can upgrade to Parent Portal after login.</p>
        </div>
    </div>

    <div class="auth-card">
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <?= bbcc_form_nonce_field('patron_signup_submit') ?>
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Full Name" required value="<?= htmlspecialchars($old['full_name']) ?>">
                <label for="full_name"><i class="fas fa-user me-1"></i> Full Name *</label>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="email" name="email" placeholder="Email" required value="<?= htmlspecialchars($old['email']) ?>">
                        <label for="email"><i class="fas fa-envelope me-1"></i> Email *</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="phone" name="phone" placeholder="Phone" required value="<?= htmlspecialchars($old['phone']) ?>">
                        <label for="phone"><i class="fas fa-phone me-1"></i> Mobile *</label>
                    </div>
                </div>
            </div>

            <div class="verify-box">
                <div style="font-size:.86rem;color:#6b140d;margin-bottom:8px;">
                    <i class="fas fa-envelope-circle-check me-1"></i> Verify your email before creating patron account.
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <button type="button" class="btn btn-brand btn-sm" id="patronSendCodeBtn">
                        <i class="fas fa-paper-plane me-1"></i> Send Code
                    </button>
                    <div id="patronResendTimer" style="font-size:.8rem;color:#777;display:none;">
                        Resend in <strong id="patronCountdown">60</strong>s
                    </div>
                    <button type="button" class="btn btn-link btn-sm p-0" id="patronResendBtn" style="display:none;color:var(--brand);">
                        <i class="fas fa-redo me-1"></i> Resend
                    </button>
                </div>
                <div class="row g-2 mt-2" id="patronOtpSection" style="display:none;">
                    <div class="col-8 col-md-6">
                        <input type="text" class="form-control text-center fw-bold" id="patronOtpCode" maxlength="6" placeholder="Enter 6-digit code" autocomplete="off">
                    </div>
                    <div class="col-4 col-md-3">
                        <button type="button" class="btn btn-outline-secondary w-100" id="patronVerifyCodeBtn">
                            Verify
                        </button>
                    </div>
                </div>
                <div class="verify-badge" id="patronVerifiedBadge">
                    <i class="fas fa-check-circle me-1"></i> Email verified successfully
                </div>
            </div>

            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="address" name="address" placeholder="Address" required value="<?= htmlspecialchars($old['address']) ?>">
                <label for="address"><i class="fas fa-location-dot me-1"></i> Address *</label>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required autocomplete="new-password">
                        <label for="password"><i class="fas fa-lock me-1"></i> Password *</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required autocomplete="new-password">
                        <label for="confirm_password"><i class="fas fa-lock me-1"></i> Confirm Password *</label>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-brand w-100" data-loading-text="<span class='spinner-border spinner-border-sm me-2'></span>Creating..."><i class="fas fa-user-check me-2"></i>Create Patron Account</button>

            <div class="text-center mt-3">
                Already have an account? <a href="login">Login</a>
            </div>
        </form>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const message = <?= json_encode($message) ?>;
    const isSuccess = <?= $isSuccess ? 'true' : 'false' ?>;
    const csrfToken = document.querySelector('input[name="_csrf"]').value;
    const emailInput = document.getElementById('email');
    const sendBtn = document.getElementById('patronSendCodeBtn');
    const resendBtn = document.getElementById('patronResendBtn');
    const resendTimer = document.getElementById('patronResendTimer');
    const countdownEl = document.getElementById('patronCountdown');
    const otpSection = document.getElementById('patronOtpSection');
    const otpInput = document.getElementById('patronOtpCode');
    const verifyBtn = document.getElementById('patronVerifyCodeBtn');
    const verifiedBadge = document.getElementById('patronVerifiedBadge');
    const form = document.querySelector('form[method="post"]');

    let emailIsVerified = <?= $isPreverifiedEmail ? 'true' : 'false' ?>;
    let verifiedEmail = <?= json_encode($sessionVerifiedEmail) ?>;
    let countdownInterval = null;

    function setVerifyUI() {
        if (emailIsVerified && verifiedEmail === (emailInput.value || '').trim().toLowerCase()) {
            verifiedBadge.style.display = 'block';
            sendBtn.style.display = 'none';
            otpSection.style.display = 'none';
            resendBtn.style.display = 'none';
            resendTimer.style.display = 'none';
            return;
        }
        verifiedBadge.style.display = 'none';
        sendBtn.style.display = '';
    }

    function resetVerificationIfEmailChanged() {
        const current = (emailInput.value || '').trim().toLowerCase();
        if (current !== verifiedEmail) {
            emailIsVerified = false;
            verifiedEmail = '';
            otpInput.value = '';
            otpSection.style.display = 'none';
            resendBtn.style.display = 'none';
            resendTimer.style.display = 'none';
            if (countdownInterval) {
                clearInterval(countdownInterval);
                countdownInterval = null;
            }
            setVerifyUI();
        }
    }

    function startResendCountdown(seconds) {
        let remaining = seconds;
        resendTimer.style.display = '';
        resendBtn.style.display = 'none';
        countdownEl.textContent = String(remaining);
        if (countdownInterval) clearInterval(countdownInterval);
        countdownInterval = setInterval(() => {
            remaining -= 1;
            countdownEl.textContent = String(remaining);
            if (remaining <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                resendTimer.style.display = 'none';
                resendBtn.style.display = '';
            }
        }, 1000);
    }

    function requestCode() {
        const email = (emailInput.value || '').trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            Swal.fire({icon:'warning',title:'Invalid Email',text:'Please enter a valid email first.',confirmButtonColor:'#881b12'});
            emailInput.focus();
            return;
        }

        sendBtn.disabled = true;
        sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';

        fetch('verify-email', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfToken) + '&action=send&purpose=patron_signup&email=' + encodeURIComponent(email)
        }).then(r => r.json()).then(data => {
            if (data.ok) {
                otpSection.style.display = '';
                otpInput.focus();
                startResendCountdown(60);
                Swal.fire({icon:'success',title:'Code Sent',text:data.message,confirmButtonColor:'#881b12'});
            } else {
                Swal.fire({icon:'error',title:'Error',text:data.message || 'Unable to send code.',confirmButtonColor:'#881b12'});
            }
        }).catch(() => {
            Swal.fire({icon:'error',title:'Error',text:'Network error. Please try again.',confirmButtonColor:'#881b12'});
        }).finally(() => {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send Code';
        });
    }

    function verifyCode() {
        const email = (emailInput.value || '').trim();
        const code = (otpInput.value || '').trim();
        if (!/^\d{6}$/.test(code)) {
            Swal.fire({icon:'warning',title:'Invalid Code',text:'Enter the 6-digit code.',confirmButtonColor:'#881b12'});
            return;
        }
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('verify-email', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfToken) + '&action=verify&purpose=patron_signup&email=' + encodeURIComponent(email) + '&code=' + encodeURIComponent(code)
        }).then(r => r.json()).then(data => {
            if (data.ok) {
                emailIsVerified = true;
                verifiedEmail = email.toLowerCase();
                setVerifyUI();
                Swal.fire({icon:'success',title:'Email Verified',text:'You can now submit the registration.',confirmButtonColor:'#881b12'});
            } else {
                Swal.fire({icon:'error',title:'Verification Failed',text:data.message || 'Incorrect code.',confirmButtonColor:'#881b12'});
            }
        }).catch(() => {
            Swal.fire({icon:'error',title:'Error',text:'Network error. Please try again.',confirmButtonColor:'#881b12'});
        }).finally(() => {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = 'Verify';
        });
    }

    sendBtn.addEventListener('click', requestCode);
    resendBtn.addEventListener('click', requestCode);
    verifyBtn.addEventListener('click', verifyCode);
    otpInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    });
    otpInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            verifyCode();
        }
    });
    emailInput.addEventListener('input', resetVerificationIfEmailChanged);

    form.addEventListener('submit', function(e) {
        const email = (emailInput.value || '').trim().toLowerCase();
        if (!emailIsVerified || email !== verifiedEmail) {
            e.preventDefault();
            Swal.fire({
                icon:'warning',
                title:'Email Not Verified',
                text:'Please verify your email address before creating patron account.',
                confirmButtonColor:'#881b12'
            });
            return;
        }
    });

    setVerifyUI();

    if (message) {
        Swal.fire({
            icon: isSuccess ? 'success' : 'error',
            title: isSuccess ? 'Success' : 'Error',
            text: message,
            confirmButtonColor: '#881b12'
        }).then(() => {
            if (isSuccess) window.location.href = 'login';
        });
    }
});
</script>
</body>
</html>
