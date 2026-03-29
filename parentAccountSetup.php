<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
require_once "include/config.php";

$message = "";
$signupSuccess = false;

$old = [
    'full_name' => '',
    'gender'    => '',
    'email'     => '',
    'phone'     => '',
    'address'   => ''
];

function clean($v) { return trim((string)$v); }

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);

        $full_name = clean($_POST['full_name'] ?? '');
        $gender    = clean($_POST['gender'] ?? '');
        $email     = strtolower(clean($_POST['email'] ?? ''));
        $phone     = clean($_POST['phone'] ?? '');
        $address   = clean($_POST['address'] ?? '');
        $password_plain   = (string)($_POST['password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        $old = compact('full_name','gender','email','phone','address');

        if ($full_name === '') throw new Exception("Full Name is required.");
        if (!in_array($gender, ['Male','Female','Other'], true)) throw new Exception("Please select a valid Gender.");
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Please enter a valid Email Address.");
        if ($phone === '') throw new Exception("Mobile Number is required.");
        if (!preg_match('/^[0-9 +()-]{8,20}$/', $phone)) throw new Exception("Please enter a valid Mobile Number.");
        if ($address === '') throw new Exception("Address is required.");
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
        $userid = 'P' . substr(uniqid(), -9);

        $pdo->prepare("INSERT INTO parents (full_name, gender, email, phone, address, username, password) VALUES (:full_name, :gender, :email, :phone, :address, :username, :password)")
            ->execute([':full_name'=>$full_name, ':gender'=>$gender, ':email'=>$email, ':phone'=>$phone, ':address'=>$address, ':username'=>$username, ':password'=>$password]);

        $pdo->prepare("INSERT INTO `user` (userid, username, password, role, createdDate) VALUES (:userid, :username, :password, :role, :createdDate)")
            ->execute([':userid'=>$userid, ':username'=>$username, ':password'=>$password, ':role'=>'parent', ':createdDate'=>date('Y-m-d H:i:s')]);

        $pdo->commit();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'dorjijigme32@gmail.com';
            $mail->Password   = 'qssf jqwo nptu lbfb';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;
            $mail->setFrom('dorjijigme32@gmail.com', 'Bhutanese Centre Canberra');
            $mail->addAddress($email, $full_name);
            $mail->isHTML(true);
            $mail->Subject = 'Welcome to Bhutanese Centre Canberra';
            $mail->Body = "<html><body style='font-family:Arial,sans-serif;background:#f5f7fa;padding:20px;'>
                <div style='max-width:600px;margin:auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 4px 18px rgba(0,0,0,0.1);'>
                    <h2 style='color:#881b12;'>Welcome, ".htmlspecialchars($full_name)."!</h2>
                    <p>Thank you for creating a parent account at <strong style='color:#881b12;'>Bhutanese Centre Canberra</strong>.</p>
                    <p><strong>Login email:</strong> ".htmlspecialchars($email)."</p>
                    <a href='login' style='display:inline-block;padding:10px 20px;background-color:#881b12;color:#fff;text-decoration:none;border-radius:5px;margin-top:10px;'>Login Now</a>
                    <p style='margin-top:20px;'>Thank you,<br><strong style='color:#881b12;'>Bhutanese Centre Canberra</strong></p>
                </div></body></html>";
            $mail->send();
            $message = "Account created successfully! Check your email and login.";
            $signupSuccess = true;
        } catch (Exception $e) {
            $message = "Account created successfully! Please login.";
            $signupSuccess = true;
        }
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
                <div class="step-item" data-step="2"><div class="step-dot">2</div><span>Security</span></div>
                <div class="step-line"></div>
                <div class="step-item" data-step="3"><div class="step-dot">3</div><span>Review</span></div>
            </div>

            <form method="POST" action="" id="signupForm" novalidate>

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

                <!-- STEP 2: Security -->
                <div class="step-panel" id="step2">
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
                        <button type="button" class="btn btn-outline-brand" id="backToStep1"><i class="fas fa-arrow-left me-2"></i> Back</button>
                        <button type="button" class="btn btn-brand" id="toStep3">Review <i class="fas fa-arrow-right ms-2"></i></button>
                    </div>
                </div>

                <!-- STEP 3: Review & Submit -->
                <div class="step-panel" id="step3">
                    <div class="info-box">
                        <i class="fas fa-clipboard-check"></i>
                        Please review your details before submitting.
                    </div>

                    <table class="review-table" id="reviewTable">
                        <tr><td>Full Name</td><td id="revName">—</td></tr>
                        <tr><td>Gender</td><td id="revGender">—</td></tr>
                        <tr><td>Email</td><td id="revEmail">—</td></tr>
                        <tr><td>Mobile</td><td id="revPhone">—</td></tr>
                        <tr><td>Address</td><td id="revAddress">—</td></tr>
                        <tr><td>Password</td><td><span style="color:#28a745"><i class="fas fa-check-circle me-1"></i>Set</span></td></tr>
                    </table>

                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-brand" id="backToStep2"><i class="fas fa-arrow-left me-2"></i> Back</button>
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

    if (msg) {
        Swal.fire({
            icon: isSuccess ? 'success' : 'error',
            title: isSuccess ? 'Success!' : 'Oops!',
            text: msg,
            timer: isSuccess ? 3000 : 5000,
            showConfirmButton: true,
            confirmButtonColor: '#881b12'
        }).then(() => {
            if (isSuccess) { window.location.href = "login.php"; return; }
            const lower = (msg || "").toLowerCase();
            if (lower.includes("email is already registered")) { goToStep(1); document.getElementById('email')?.focus(); }
            if (lower.includes("password")) { goToStep(2); document.getElementById('password').value=''; document.getElementById('confirmPassword').value=''; document.getElementById('password')?.focus(); }
        });
    }

    const steps = [document.getElementById('step1'), document.getElementById('step2'), document.getElementById('step3')];
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

    function validateStep2() {
        const pw = document.getElementById('password').value;
        const cf = document.getElementById('confirmPassword').value;
        const errors = [];
        if (pw.length < 8) errors.push('Password must be at least 8 characters');
        if (!/[A-Za-z]/.test(pw)) errors.push('Password needs at least 1 letter');
        if (!/[0-9]/.test(pw)) errors.push('Password needs at least 1 number');
        if (pw !== cf) errors.push('Passwords do not match');
        return errors;
    }

    document.getElementById('toStep2').addEventListener('click', () => {
        const errors = validateStep1();
        if (errors.length) { Swal.fire({icon:'warning',title:'Please fix:',html:errors.map(e=>'<div>'+e+'</div>').join(''),confirmButtonColor:'#881b12'}); return; }
        goToStep(2);
    });
    document.getElementById('backToStep1').addEventListener('click', () => goToStep(1));
    document.getElementById('toStep3').addEventListener('click', () => {
        const errors = validateStep2();
        if (errors.length) { Swal.fire({icon:'warning',title:'Please fix:',html:errors.map(e=>'<div>'+e+'</div>').join(''),confirmButtonColor:'#881b12'}); return; }
        document.getElementById('revName').textContent = document.getElementById('fullName').value.trim();
        document.getElementById('revGender').textContent = document.getElementById('gender').value;
        document.getElementById('revEmail').textContent = document.getElementById('email').value.trim();
        document.getElementById('revPhone').textContent = document.getElementById('phone').value.trim();
        document.getElementById('revAddress').textContent = document.getElementById('address').value.trim();
        goToStep(3);
    });
    document.getElementById('backToStep2').addEventListener('click', () => goToStep(2));

    // Password strength
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

    document.getElementById('signupForm').addEventListener('submit', function() {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating Account...';
    });
});
</script>
</body>
</html>