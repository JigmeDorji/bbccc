<?php
require_once "include/config.php";
require_once "include/mailer.php";
require_once "include/csrf.php";
require_once "include/pcm_helpers.php";
require_once "include/account_activation.php";
require_once "include/patron_schema.php";
require_once "include/user_id_helper.php";
require_once "include/email_verification.php";

$message = "";
$signupSuccess = false;

$old = [
    'full_name' => '',
    'gender'    => '',
    'email'     => '',
    'phone'     => '',
    'address'   => '',
    'register_as_patron' => ''
];

function clean($v) { return trim((string)$v); }

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        verify_csrf();

        $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);
        bbcc_activation_ensure_schema($pdo);
        bbcc_ensure_patrons_table($pdo);

        $full_name = clean($_POST['full_name'] ?? '');
        $gender    = clean($_POST['gender'] ?? '');
        $email     = strtolower(clean($_POST['email'] ?? ''));
        $phone     = clean($_POST['phone'] ?? '');
        $address   = clean($_POST['address'] ?? '');
        $registerAsPatron = isset($_POST['register_as_patron']) ? 1 : 0;
        $password_plain   = (string)($_POST['password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        $old = compact('full_name','gender','email','phone','address');
        $old['register_as_patron'] = $registerAsPatron ? '1' : '';

        if ($full_name === '') throw new Exception("Full Name is required.");
        if (!in_array($gender, ['Male','Female','Other'], true)) throw new Exception("Please select a valid Gender.");
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Please enter a valid Email Address.");
        if ($phone === '') throw new Exception("Mobile Number is required.");
        if (!preg_match('/^[0-9 +()-]{8,20}$/', $phone)) throw new Exception("Please enter a valid Mobile Number.");
        if ($address === '') throw new Exception("Address is required.");

        // ── Verify that the email was OTP-verified ──
        $verifiedEmail = $_SESSION['verified_email'] ?? '';
        $verifiedAt    = $_SESSION['verified_email_at'] ?? 0;
        $verifiedPurpose = strtolower((string)($_SESSION['verified_email_purpose'] ?? 'signup'));
        if (strtolower($verifiedEmail) !== strtolower($email) || (time() - $verifiedAt) > 1800 || $verifiedPurpose !== 'signup') {
            throw new Exception("Please verify your email address first. Go back to the Email Verification step.");
        }

        if (strlen($password_plain) < 8) throw new Exception("Password must be at least 8 characters long.");
        if (!preg_match('/[A-Za-z]/', $password_plain) || !preg_match('/[0-9]/', $password_plain)) throw new Exception("Password must include at least 1 letter and 1 number.");
        if ($password_plain !== $confirm_password) throw new Exception("Password and Confirm Password do not match.");

        $username = $email;

        $stmtCheck = $pdo->prepare("SELECT id FROM parents WHERE LOWER(email) = LOWER(:e) LIMIT 1");
        $stmtCheck->execute([':e' => $email]);
        if ($stmtCheck->fetchColumn()) throw new Exception("Email is already registered. Please use a different email address.");

        $stmtCheckUser = $pdo->prepare("SELECT userid FROM `user` WHERE LOWER(username) = LOWER(:u) LIMIT 1");
        $stmtCheckUser->execute([':u' => $username]);
        if ($stmtCheckUser->fetchColumn()) throw new Exception("Email is already registered. Please use a different email address.");

        $password = password_hash($password_plain, PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        $userid = bbcc_generate_userid($pdo, 'P');

        $pdo->prepare("INSERT INTO parents (full_name, gender, email, phone, address, username, password) VALUES (:full_name, :gender, :email, :phone, :address, :username, :password)")
            ->execute([':full_name'=>$full_name, ':gender'=>$gender, ':email'=>$email, ':phone'=>$phone, ':address'=>$address, ':username'=>$username, ':password'=>$password]);
        $parentId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO `user` (userid, username, password, role, is_active, createdDate) VALUES (:userid, :username, :password, :role, :is_active, :createdDate)")
            ->execute([':userid'=>$userid, ':username'=>$username, ':password'=>$password, ':role'=>'parent', ':is_active'=>1, ':createdDate'=>date('Y-m-d H:i:s')]);

        if ($registerAsPatron) {
            $pdo->prepare("
                INSERT INTO patrons (parent_id, full_name, email, phone, address, patron_type, status, created_at)
                VALUES (:parent_id, :full_name, :email, :phone, :address, :patron_type, :status, NOW())
                ON DUPLICATE KEY UPDATE
                    parent_id = VALUES(parent_id),
                    full_name = VALUES(full_name),
                    phone = VALUES(phone),
                    address = VALUES(address),
                    status = VALUES(status)
            ")->execute([
                ':parent_id'   => $parentId,
                ':full_name'   => $full_name,
                ':email'       => $email,
                ':phone'       => $phone,
                ':address'     => $address,
                ':patron_type' => 'Regular',
                ':status'      => 'Active'
            ]);
        }

        $pdo->commit();

        // Send welcome greeting email (no activation link needed — email already verified via OTP)
        $safeName = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
        $welcomeSubject = 'Welcome to Parent Portal';
        if (function_exists('pcm_email_wrap')) {
            $welcomeBody = pcm_email_wrap('Bhutanese Language and Culture School, Canberra', "
                <p style='margin:0 0 14px;'>Hello <strong>{$safeName}</strong>,</p>
                <p style='margin:0 0 14px;'>Welcome to the <strong>Bhutanese Language and Culture School</strong> community! Your account has been created successfully.</p>
                <p style='margin:0 0 14px;'>You can now log in using your email and password to access all parent portal features including class enrolments, attendance tracking, and more.</p>
                <p style='margin:20px 0;'>
                    <a href='" . rtrim(BASE_URL, '/') . "/login' style='background:#881b12;color:#ffffff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:15px;'>
                        Login to Your Account
                    </a>
                </p>
                <p style='margin:14px 0 0;font-size:13px;color:#666;'>Thank you for joining us. We look forward to serving you and your family.</p>
            ");
        } else {
            $welcomeBody = "
                <p>Hello <strong>{$safeName}</strong>,</p>
                <p>Welcome to the Bhutanese Language and Culture School! Your account has been created successfully.</p>
                <p>You can now log in using your email and password.</p>
                <p>Thank you for joining us.</p>
            ";
        }
        $sent = send_mail($email, $full_name, $welcomeSubject, $welcomeBody);
        if (!$sent) {
            error_log("Parent signup welcome email failed for {$email}");
        }
        unset($_SESSION['verified_email'], $_SESSION['verified_email_at'], $_SESSION['verified_email_purpose']);
        $message = "Account created successfully! You can now log in with your email and password.";
        $signupSuccess = true;
    } catch (Exception $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
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
<title>Parent Sign Up — Bhutanese Centre Canberra</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php include_once 'include/global_css.php'; ?>
<style>
:root { --brand:#881b12; --brand-light:#a82218; --brand-dark:#6b140d; }

.signup-page { min-height:100vh; display:flex; flex-direction:column; }

.signup-hero {
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    color:#fff; padding:40px 0 60px; text-align:center; position:relative;
}
.signup-hero::after {
    content:''; position:absolute; bottom:-30px; left:0; right:0; height:60px;
    background:#f5f7fa; border-radius:50% 50% 0 0/100% 100% 0 0;
}
.signup-hero h1 { font-size:1.7rem; font-weight:700; }
.signup-hero p { font-size:0.92rem; opacity:0.9; }

.signup-card {
    max-width:620px; margin:-20px auto 40px; position:relative; z-index:2;
    background:#fff; border-radius:16px; box-shadow:0 8px 32px rgba(0,0,0,0.1); overflow:hidden;
}
.signup-card .card-body-inner { padding:28px 32px 32px; }

/* Steps */
.steps-indicator { display:flex; justify-content:center; gap:0; margin-bottom:28px; }
.step-item { display:flex; align-items:center; gap:8px; font-size:0.78rem; font-weight:600; color:#bbb; text-transform:uppercase; letter-spacing:.5px; }
.step-item.active { color:var(--brand); }
.step-item.completed { color:#28a745; }
.step-dot { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.75rem; background:#e9ecef; color:#aaa; transition:all .3s; }
.step-item.active .step-dot { background:var(--brand); color:#fff; }
.step-item.completed .step-dot { background:#28a745; color:#fff; }
.step-line { width:40px; height:2px; background:#e9ecef; margin:0 8px; align-self:center; transition:background .3s; }
.step-line.done { background:#28a745; }

/* Form */
.form-floating > .form-control, .form-floating > .form-select { height:50px; border-radius:10px; border:1.5px solid #dee2e6; font-size:0.9rem; }
.form-floating > .form-control:focus, .form-floating > .form-select:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(136,27,18,0.12); }
.form-floating > label { font-size:0.85rem; color:#888; }

.step-panel { display:none; }
.step-panel.active { display:block; animation:fadeIn .3s ease-in-out; }
@keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }

/* Password */
.pw-strength { height:4px; border-radius:2px; background:#e9ecef; margin-top:6px; overflow:hidden; }
.pw-strength-bar { height:100%; width:0; border-radius:2px; transition:width .3s, background .3s; }
.pw-hint { font-size:0.72rem; color:#999; margin-top:4px; }
.pw-hint .met { color:#28a745; }
.pw-hint .unmet { color:#ccc; }

.form-control.is-valid, .form-select.is-valid { border-color:#28a745 !important; }
.form-control.is-invalid, .form-select.is-invalid { border-color:#dc3545 !important; }
.validation-icon { position:absolute; right:14px; top:50%; transform:translateY(-50%); font-size:0.9rem; pointer-events:none; }

.btn-brand { background:var(--brand); color:#fff; border:none; border-radius:10px; font-weight:600; font-size:0.95rem; padding:12px 28px; transition:all .2s; }
.btn-brand:hover { background:var(--brand-dark); color:#fff; transform:translateY(-1px); box-shadow:0 4px 12px rgba(136,27,18,0.3); }
.btn-outline-brand { border:2px solid var(--brand); color:var(--brand); border-radius:10px; font-weight:600; font-size:0.95rem; padding:10px 28px; background:transparent; }
.btn-outline-brand:hover { background:var(--brand); color:#fff; }

.info-box { background:#fef3f2; border-left:4px solid var(--brand); border-radius:8px; padding:14px 18px; font-size:0.82rem; color:#555; margin-bottom:20px; }
.info-box i { color:var(--brand); margin-right:6px; }

.footer-links { text-align:center; padding:12px 0 8px; font-size:0.85rem; }
.footer-links a { color:var(--brand); font-weight:600; text-decoration:none; }
.footer-links a:hover { text-decoration:underline; }

.review-table { width:100%; }
.review-table td { padding:8px 0; font-size:0.88rem; border-bottom:1px solid #f0f0f0; }
.review-table td:first-child { color:#888; font-weight:500; width:130px; }
.review-table td:last-child { font-weight:600; color:#333; }

@media (max-width:576px) {
    .signup-card .card-body-inner { padding:20px 18px 24px; }
    .step-item span { display:none; }
    .step-line { width:24px; }
}
</style>
</head>
<body>
<?php include_once 'include/nav.php'; ?>

<div class="signup-page">
    <div class="signup-hero">
        <div class="container">
            <h1><i class="fas fa-user-plus me-2"></i>Create Parent Account</h1>
            <p>Join the Bhutanese Centre Canberra community</p>
        </div>
    </div>

    <div class="signup-card">
        <div class="card-body-inner">

            <div class="steps-indicator" id="stepsIndicator">
                <div class="step-item active" data-step="1"><div class="step-dot">1</div><span>Personal Info</span></div>
                <div class="step-line"></div>
                <div class="step-item" data-step="2"><div class="step-dot">2</div><span>Verify Email</span></div>
                <div class="step-line"></div>
                <div class="step-item" data-step="3"><div class="step-dot">3</div><span>Security</span></div>
                <div class="step-line"></div>
                <div class="step-item" data-step="4"><div class="step-dot">4</div><span>Review</span></div>
            </div>

            <form method="POST" action="" id="signupForm" novalidate>
                <?= csrf_field() ?>

                <!-- STEP 1: Personal Info -->
                <div class="step-panel active" id="step1">
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        Your <strong>email address</strong> will be your login username.
                    </div>

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="fullName" name="full_name" placeholder="Full Name" required value="<?= htmlspecialchars($old['full_name']) ?>" autocomplete="name">
                        <label for="fullName"><i class="fas fa-user me-1"></i> Full Name *</label>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <div class="form-floating">
                                <select class="form-select" id="gender" name="gender" required>
                                    <option value="">Select gender</option>
                                    <option value="Male" <?= $old['gender']==='Male'?'selected':'' ?>>Male</option>
                                    <option value="Female" <?= $old['gender']==='Female'?'selected':'' ?>>Female</option>
                                    <option value="Other" <?= $old['gender']==='Other'?'selected':'' ?>>Other</option>
                                </select>
                                <label for="gender"><i class="fas fa-venus-mars me-1"></i> Gender *</label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-floating">
                                <input type="tel" class="form-control" id="phone" name="phone" placeholder="Mobile" required value="<?= htmlspecialchars($old['phone']) ?>" autocomplete="tel">
                                <label for="phone"><i class="fas fa-phone me-1"></i> Mobile *</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="email" name="email" placeholder="Email" required value="<?= htmlspecialchars($old['email']) ?>" autocomplete="email">
                        <label for="email"><i class="fas fa-envelope me-1"></i> Email Address *</label>
                        <div class="form-text" style="font-size:0.72rem; margin-top:4px; color:#999;">
                            <i class="fas fa-key" style="font-size:0.65rem;"></i> This will be your login username
                        </div>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="address" name="address" placeholder="Address" required value="<?= htmlspecialchars($old['address']) ?>" autocomplete="street-address">
                        <label for="address"><i class="fas fa-map-marker-alt me-1"></i> Address *</label>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="button" class="btn btn-brand" id="toStep2">Continue <i class="fas fa-arrow-right ms-2"></i></button>
                    </div>
                </div>

                <!-- STEP 2: Verify Email -->
                <div class="step-panel" id="step2">
                    <div class="info-box">
                        <i class="fas fa-envelope-circle-check"></i>
                        We need to verify your email address. A <strong>6-digit code</strong> will be sent to your email.
                    </div>

                    <div class="text-center mb-3">
                        <p class="mb-1" style="font-size:0.9rem;color:#555;">Sending verification code to:</p>
                        <p class="fw-bold" style="font-size:1.05rem;color:var(--brand);" id="verifyEmailDisplay">—</p>
                    </div>

                    <div class="text-center mb-3" id="sendCodeSection">
                        <button type="button" class="btn btn-brand" id="sendCodeBtn">
                            <i class="fas fa-paper-plane me-2"></i>Send Verification Code
                        </button>
                    </div>

                    <div id="otpSection" style="display:none;">
                        <p class="text-center mb-2" style="font-size:0.85rem;color:#28a745;">
                            <i class="fas fa-check-circle me-1"></i>Code sent! Check your inbox (and Spam/Junk folder).
                        </p>
                        <div class="d-flex justify-content-center gap-2 mb-2" id="otpInputGroup">
                            <input type="text" class="form-control text-center fw-bold" id="otp1" maxlength="1" style="width:48px;height:56px;font-size:1.4rem;border-radius:10px;border:2px solid #dee2e6;" autocomplete="off">
                            <input type="text" class="form-control text-center fw-bold" id="otp2" maxlength="1" style="width:48px;height:56px;font-size:1.4rem;border-radius:10px;border:2px solid #dee2e6;" autocomplete="off">
                            <input type="text" class="form-control text-center fw-bold" id="otp3" maxlength="1" style="width:48px;height:56px;font-size:1.4rem;border-radius:10px;border:2px solid #dee2e6;" autocomplete="off">
                            <span class="align-self-center" style="font-size:1.2rem;color:#ccc;">—</span>
                            <input type="text" class="form-control text-center fw-bold" id="otp4" maxlength="1" style="width:48px;height:56px;font-size:1.4rem;border-radius:10px;border:2px solid #dee2e6;" autocomplete="off">
                            <input type="text" class="form-control text-center fw-bold" id="otp5" maxlength="1" style="width:48px;height:56px;font-size:1.4rem;border-radius:10px;border:2px solid #dee2e6;" autocomplete="off">
                            <input type="text" class="form-control text-center fw-bold" id="otp6" maxlength="1" style="width:48px;height:56px;font-size:1.4rem;border-radius:10px;border:2px solid #dee2e6;" autocomplete="off">
                        </div>

                        <div class="text-center mb-3">
                            <button type="button" class="btn btn-brand" id="verifyCodeBtn">
                                <i class="fas fa-shield-halved me-2"></i>Verify Code
                            </button>
                        </div>

                        <div class="text-center" style="font-size:0.82rem;color:#888;">
                            <span id="resendTimer">Resend available in <strong id="countdown">60</strong>s</span>
                            <button type="button" class="btn btn-link btn-sm p-0" id="resendBtn" style="display:none;font-size:0.82rem;color:var(--brand);">
                                <i class="fas fa-redo me-1"></i>Resend Code
                            </button>
                        </div>

                        <div class="text-center mt-2" style="font-size:0.75rem;color:#999;">
                            <i class="fas fa-clock me-1"></i>Code expires in 10 minutes &nbsp;|&nbsp; <i class="fas fa-shield me-1"></i>Max 5 attempts
                        </div>
                    </div>

                    <div class="text-center mt-3" id="emailVerifiedBadge" style="display:none;">
                        <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:12px;padding:16px 24px;display:inline-block;">
                            <i class="fas fa-check-circle text-success" style="font-size:1.6rem;"></i>
                            <p class="mb-0 mt-1 fw-bold text-success">Email Verified Successfully!</p>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-brand" id="backToStep1FromVerify"><i class="fas fa-arrow-left me-2"></i> Back</button>
                        <button type="button" class="btn btn-brand" id="toStep3" disabled>
                            Continue <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 3: Security -->
                <div class="step-panel" id="step3">
                    <div class="info-box">
                        <i class="fas fa-shield-halved"></i>
                        Create a strong password — at least 8 characters with both letters and numbers.
                    </div>

                    <div class="form-floating mb-2 position-relative">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required autocomplete="new-password">
                        <label for="password"><i class="fas fa-lock me-1"></i> Password *</label>
                        <button type="button" class="btn btn-sm position-absolute" id="togglePw" style="right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#888;z-index:5;">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>

                    <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
                    <div class="pw-hint" id="pwHints">
                        <span class="unmet" id="hintLen"><i class="fas fa-circle" style="font-size:5px;vertical-align:middle;"></i> 8+ characters</span> &nbsp;
                        <span class="unmet" id="hintLetter"><i class="fas fa-circle" style="font-size:5px;vertical-align:middle;"></i> Letter</span> &nbsp;
                        <span class="unmet" id="hintNum"><i class="fas fa-circle" style="font-size:5px;vertical-align:middle;"></i> Number</span>
                    </div>

                    <div class="form-floating mb-3 mt-3 position-relative">
                        <input type="password" class="form-control" id="confirmPassword" name="confirm_password" placeholder="Confirm Password" required autocomplete="new-password">
                        <label for="confirmPassword"><i class="fas fa-lock me-1"></i> Confirm Password *</label>
                        <span class="validation-icon" id="matchIcon"></span>
                    </div>

                    <div class="d-flex justify-content-between mt-3">
                        <button type="button" class="btn btn-outline-brand" id="backToStep2"><i class="fas fa-arrow-left me-2"></i> Back</button>
                        <button type="button" class="btn btn-brand" id="toStep4">Review <i class="fas fa-arrow-right ms-2"></i></button>
                    </div>
                </div>

                <!-- STEP 4: Review & Submit -->
                <div class="step-panel" id="step4">
                    <div class="info-box">
                        <i class="fas fa-clipboard-check"></i>
                        Please review your details before submitting.
                    </div>

                    <table class="review-table" id="reviewTable">
                        <tr><td>Full Name</td><td id="revName">—</td></tr>
                        <tr><td>Gender</td><td id="revGender">—</td></tr>
                        <tr><td>Email</td><td id="revEmail">— <span style="color:#28a745;font-size:0.8rem;"><i class="fas fa-check-circle"></i> Verified</span></td></tr>
                        <tr><td>Mobile</td><td id="revPhone">—</td></tr>
                        <tr><td>Address</td><td id="revAddress">—</td></tr>
                        <tr>
                            <td>Patron Registration</td>
                            <td>
                                <div style="display:flex;align-items:flex-start;gap:8px;">
                                    <input type="checkbox" id="registerAsPatron" name="register_as_patron" value="1" <?= !empty($old['register_as_patron']) ? 'checked' : '' ?> style="margin-top:4px;">
                                    <label for="registerAsPatron" style="margin:0;font-weight:500;color:#333;">
                                        I want to register as a patron for Bhutanese Buddhist and culture centre
                                    </label>
                                </div>
                            </td>
                        </tr>
                        <tr><td>Password</td><td><span style="color:#28a745"><i class="fas fa-check-circle me-1"></i>Set</span></td></tr>
                    </table>

                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-brand" id="backToStep3"><i class="fas fa-arrow-left me-2"></i> Back</button>
                        <button type="submit" class="btn btn-brand" id="submitBtn"><i class="fas fa-user-check me-2"></i> Create Account</button>
                    </div>
                </div>

            </form>

            <div class="footer-links">Already have an account? <a href="login">Login here</a></div>
        </div>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const msg = <?= json_encode($message) ?>;
    const isSuccess = <?= $signupSuccess ? 'true' : 'false' ?>;
    const csrfToken = document.querySelector('input[name="_csrf"]').value;

    if (msg) {
        Swal.fire({
            icon: isSuccess ? 'success' : 'error',
            title: isSuccess ? 'Success!' : 'Oops!',
            text: msg,
            timer: isSuccess ? 3000 : 5000,
            showConfirmButton: true,
            confirmButtonColor: '#881b12'
        }).then(() => {
            if (isSuccess) { window.location.href = "login"; return; }
            const lower = (msg || "").toLowerCase();
            if (lower.includes("email is already registered")) { goToStep(1); document.getElementById('email')?.focus(); }
            if (lower.includes("verify your email")) { goToStep(2); }
            if (lower.includes("password")) { goToStep(3); document.getElementById('password').value=''; document.getElementById('confirmPassword').value=''; document.getElementById('password')?.focus(); }
        });
    }

    /* ── Step navigation ─────────────────────────────────── */
    const steps = [document.getElementById('step1'), document.getElementById('step2'), document.getElementById('step3'), document.getElementById('step4')];
    const indicators = document.querySelectorAll('.step-item');
    const lines = document.querySelectorAll('.step-line');

    function goToStep(n) {
        steps.forEach((s,i) => s.classList.toggle('active', i === n-1));
        indicators.forEach((ind,i) => {
            const sn = i+1;
            ind.classList.remove('active','completed');
            if (sn === n) ind.classList.add('active');
            else if (sn < n) ind.classList.add('completed');
        });
        lines.forEach((line,i) => line.classList.toggle('done', i < n-1));
        window.scrollTo({top:0,behavior:'smooth'});
    }

    /* ── Step 1 validation ───────────────────────────────── */
    function validateStep1() {
        const name = document.getElementById('fullName').value.trim();
        const gender = document.getElementById('gender').value;
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const address = document.getElementById('address').value.trim();
        const errors = [];
        if (!name) errors.push('Full Name is required');
        if (!gender) errors.push('Please select a Gender');
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.push('Valid Email is required');
        if (!phone || !/^[0-9 +()-]{8,20}$/.test(phone)) errors.push('Valid Mobile Number is required');
        if (!address) errors.push('Address is required');
        ['fullName','gender','email','phone','address'].forEach(id => {
            const el = document.getElementById(id);
            const val = el.value.trim();
            const ok = id === 'email' ? (val && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) : !!val;
            el.classList.toggle('is-valid', ok);
            el.classList.toggle('is-invalid', !ok);
        });
        return errors;
    }

    /* ── Step 3 (password) validation ────────────────────── */
    function validateStep3() {
        const pw = document.getElementById('password').value;
        const cf = document.getElementById('confirmPassword').value;
        const errors = [];
        if (pw.length < 8) errors.push('Password must be at least 8 characters');
        if (!/[A-Za-z]/.test(pw)) errors.push('Password needs at least 1 letter');
        if (!/[0-9]/.test(pw)) errors.push('Password needs at least 1 number');
        if (pw !== cf) errors.push('Passwords do not match');
        return errors;
    }

    /* ── Navigation buttons ──────────────────────────────── */
    // Step 1 → Step 2
    document.getElementById('toStep2').addEventListener('click', () => {
        const errors = validateStep1();
        if (errors.length) { Swal.fire({icon:'warning',title:'Please fix:',html:errors.map(e=>'<div>'+e+'</div>').join(''),confirmButtonColor:'#881b12'}); return; }
        // Show the email on step 2
        document.getElementById('verifyEmailDisplay').textContent = document.getElementById('email').value.trim();
        // Reset verification UI if email changed
        if (verifiedEmail !== document.getElementById('email').value.trim().toLowerCase()) {
            resetOtpUI();
        }
        goToStep(2);
    });

    // Step 2 → Step 1
    document.getElementById('backToStep1FromVerify').addEventListener('click', () => goToStep(1));

    // Step 2 → Step 3 (only when email is verified)
    document.getElementById('toStep3').addEventListener('click', () => {
        if (!emailIsVerified) {
            Swal.fire({icon:'warning',title:'Email not verified',text:'Please verify your email before continuing.',confirmButtonColor:'#881b12'});
            return;
        }
        goToStep(3);
    });

    // Step 3 → Step 2
    document.getElementById('backToStep2').addEventListener('click', () => goToStep(2));

    // Step 3 → Step 4
    document.getElementById('toStep4').addEventListener('click', () => {
        const errors = validateStep3();
        if (errors.length) { Swal.fire({icon:'warning',title:'Please fix:',html:errors.map(e=>'<div>'+e+'</div>').join(''),confirmButtonColor:'#881b12'}); return; }
        document.getElementById('revName').textContent = document.getElementById('fullName').value.trim();
        document.getElementById('revGender').textContent = document.getElementById('gender').value;
        document.getElementById('revEmail').textContent = document.getElementById('email').value.trim();
        document.getElementById('revPhone').textContent = document.getElementById('phone').value.trim();
        document.getElementById('revAddress').textContent = document.getElementById('address').value.trim();
        goToStep(4);
    });

    // Step 4 → Step 3
    document.getElementById('backToStep3').addEventListener('click', () => goToStep(3));

    /* ══════════════════════════════════════════════════════
       EMAIL VERIFICATION (OTP) — Step 2
       ══════════════════════════════════════════════════════ */
    let emailIsVerified = false;
    let verifiedEmail = '';
    let countdownInterval = null;

    function resetOtpUI() {
        emailIsVerified = false;
        verifiedEmail = '';
        document.getElementById('sendCodeSection').style.display = '';
        document.getElementById('otpSection').style.display = 'none';
        document.getElementById('emailVerifiedBadge').style.display = 'none';
        document.getElementById('toStep3').disabled = true;
        document.getElementById('sendCodeBtn').disabled = false;
        document.getElementById('sendCodeBtn').innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Verification Code';
        clearOtpInputs();
        if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
    }

    function clearOtpInputs() {
        for (let i = 1; i <= 6; i++) {
            const el = document.getElementById('otp'+i);
            el.value = '';
            el.style.borderColor = '#dee2e6';
        }
    }

    function getOtpValue() {
        let code = '';
        for (let i = 1; i <= 6; i++) code += document.getElementById('otp'+i).value;
        return code;
    }

    function startResendCountdown(seconds) {
        const timerSpan = document.getElementById('resendTimer');
        const countdownEl = document.getElementById('countdown');
        const resendBtn = document.getElementById('resendBtn');
        timerSpan.style.display = '';
        resendBtn.style.display = 'none';
        let remaining = seconds;
        countdownEl.textContent = remaining;

        if (countdownInterval) clearInterval(countdownInterval);
        countdownInterval = setInterval(() => {
            remaining--;
            countdownEl.textContent = remaining;
            if (remaining <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                timerSpan.style.display = 'none';
                resendBtn.style.display = '';
            }
        }, 1000);
    }

    // ── Send Code ───────────────────────────────────────────
    function sendCode() {
        const email = document.getElementById('email').value.trim();
        const btn = document.getElementById('sendCodeBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';

        fetch('verify-email', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfToken) + '&action=send&purpose=signup&email=' + encodeURIComponent(email)
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('sendCodeSection').style.display = 'none';
                document.getElementById('otpSection').style.display = '';
                document.getElementById('otp1').focus();
                startResendCountdown(60);
            } else {
                Swal.fire({icon:'error',title:'Error',text:data.message,confirmButtonColor:'#881b12'});
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Verification Code';
            }
        })
        .catch(() => {
            Swal.fire({icon:'error',title:'Error',text:'Network error. Please try again.',confirmButtonColor:'#881b12'});
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Verification Code';
        });
    }

    document.getElementById('sendCodeBtn').addEventListener('click', sendCode);
    document.getElementById('resendBtn').addEventListener('click', () => {
        clearOtpInputs();
        document.getElementById('verifyCodeBtn').disabled = false;
        document.getElementById('verifyCodeBtn').innerHTML = '<i class="fas fa-shield-halved me-2"></i>Verify Code';
        sendCode();
        document.getElementById('sendCodeSection').style.display = 'none';
        document.getElementById('otpSection').style.display = '';
    });

    // ── Verify Code ─────────────────────────────────────────
    document.getElementById('verifyCodeBtn').addEventListener('click', function() {
        const code = getOtpValue();
        if (code.length !== 6 || !/^\d{6}$/.test(code)) {
            Swal.fire({icon:'warning',title:'Invalid Code',text:'Please enter the complete 6-digit code.',confirmButtonColor:'#881b12'});
            return;
        }

        const email = document.getElementById('email').value.trim();
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';

        fetch('verify-email', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfToken) + '&action=verify&purpose=signup&email=' + encodeURIComponent(email) + '&code=' + encodeURIComponent(code)
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                emailIsVerified = true;
                verifiedEmail = email.toLowerCase();
                // Show success state
                document.getElementById('otpSection').style.display = 'none';
                document.getElementById('emailVerifiedBadge').style.display = '';
                document.getElementById('toStep3').disabled = false;
                // Highlight OTP inputs green
                for (let i = 1; i <= 6; i++) document.getElementById('otp'+i).style.borderColor = '#28a745';
                if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
                Swal.fire({
                    icon:'success',
                    title:'Email Verified!',
                    text:'Your email has been verified. You can now continue.',
                    timer:2000,
                    showConfirmButton:false
                });
            } else {
                Swal.fire({icon:'error',title:'Verification Failed',text:data.message,confirmButtonColor:'#881b12'});
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-shield-halved me-2"></i>Verify Code';
                // Shake animation on OTP inputs
                for (let i = 1; i <= 6; i++) {
                    const el = document.getElementById('otp'+i);
                    el.style.borderColor = '#dc3545';
                    el.style.animation = 'shake 0.4s';
                    setTimeout(() => { el.style.animation = ''; el.style.borderColor = '#dee2e6'; }, 500);
                }
            }
        })
        .catch(() => {
            Swal.fire({icon:'error',title:'Error',text:'Network error. Please try again.',confirmButtonColor:'#881b12'});
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-shield-halved me-2"></i>Verify Code';
        });
    });

    // ── OTP input auto-advance & paste support ──────────────
    const otpInputs = document.querySelectorAll('#otpInputGroup input');
    otpInputs.forEach((input, idx) => {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value && idx < otpInputs.length - 1) {
                otpInputs[idx+1].focus();
            }
        });
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && idx > 0) {
                otpInputs[idx-1].focus();
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('verifyCodeBtn').click();
            }
        });
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
            for (let j = 0; j < paste.length && j < otpInputs.length; j++) {
                otpInputs[j].value = paste[j];
            }
            if (paste.length >= 6) otpInputs[5].focus();
            else if (paste.length > 0) otpInputs[paste.length].focus();
        });
        input.addEventListener('focus', function() {
            this.select();
        });
    });

    /* ── Password strength ───────────────────────────────── */
    const pwField = document.getElementById('password');
    const pwBar = document.getElementById('pwBar');
    pwField.addEventListener('input', function() {
        const v = this.value;
        let score = 0;
        const hasLen = v.length >= 8, hasLetter = /[A-Za-z]/.test(v), hasNum = /[0-9]/.test(v);
        if (hasLen) score++; if (hasLetter) score++; if (hasNum) score++;
        if (v.length >= 12) score++; if (/[^A-Za-z0-9]/.test(v)) score++;
        pwBar.style.width = Math.min(100, (score/5)*100)+'%';
        pwBar.style.background = ['#dc3545','#dc3545','#ffc107','#28a745','#28a745','#20c997'][score] || '#e9ecef';
        document.getElementById('hintLen').className = hasLen ? 'met' : 'unmet';
        document.getElementById('hintLetter').className = hasLetter ? 'met' : 'unmet';
        document.getElementById('hintNum').className = hasNum ? 'met' : 'unmet';
        updateMatchIcon();
    });

    const confirmField = document.getElementById('confirmPassword');
    const matchIcon = document.getElementById('matchIcon');
    function updateMatchIcon() {
        const pw = pwField.value, cf = confirmField.value;
        if (!cf) { matchIcon.innerHTML=''; confirmField.classList.remove('is-valid','is-invalid'); return; }
        if (pw === cf) { matchIcon.innerHTML='<i class="fas fa-check-circle text-success"></i>'; confirmField.classList.add('is-valid'); confirmField.classList.remove('is-invalid'); }
        else { matchIcon.innerHTML='<i class="fas fa-times-circle text-danger"></i>'; confirmField.classList.add('is-invalid'); confirmField.classList.remove('is-valid'); }
    }
    confirmField.addEventListener('input', updateMatchIcon);

    document.getElementById('togglePw').addEventListener('click', function() {
        const t = pwField.type === 'password' ? 'text' : 'password';
        pwField.type = t; confirmField.type = t;
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
    });

    /* ── Form submit ─────────────────────────────────────── */
    document.getElementById('signupForm').addEventListener('submit', function() {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating Account...';
    });
});
</script>

<style>
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-6px); }
    50% { transform: translateX(6px); }
    75% { transform: translateX(-4px); }
}
</style>
</body>
</html>
