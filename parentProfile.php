<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'parent') {
    header("Location: index-admin");
    exit;
}

$message = "";
$errors = [];

// ---------------- DB CONNECTION ----------------
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    bbcc_fail_db($e);
}

// ✅ Email is username in your system
$loginEmail = trim($_SESSION['username'] ?? '');
if ($loginEmail === '') {
    die("Session username missing. Please log out and log in again.");
}

// View/Edit mode (edit only when ?edit=1)
$isEditMode = (isset($_GET['edit']) && $_GET['edit'] == '1');

// Fetch parent profile by email (case-insensitive)
$stmt = $pdo->prepare("SELECT * FROM parents WHERE LOWER(email) = LOWER(:e) LIMIT 1");
$stmt->execute([':e' => $loginEmail]);
$parent = $stmt->fetch();

if (!$parent) {
    die("No parent record found for your login email: " . htmlspecialchars($loginEmail) . ". Please contact admin.");
}

$parentId = (int)$parent['id'];

// Sticky values (default from DB)
$full_name = $parent['full_name'] ?? '';
$gender    = $parent['gender'] ?? '';
$email     = $parent['email'] ?? '';
$phone     = $parent['phone'] ?? '';
$address   = $parent['address'] ?? '';

/**
 * Get the parent's password hash from user table (email-as-username) if present.
 */
function get_user_hash(PDO $pdo, string $username): string {
    $stmtU = $pdo->prepare("SELECT password FROM user WHERE LOWER(username)=LOWER(:u) AND role='parent' LIMIT 1");
    $stmtU->execute([':u' => $username]);
    $row = $stmtU->fetch(PDO::FETCH_ASSOC);
    return $row['password'] ?? '';
}

// ---------------- Handle update (only in edit mode + POST) ----------------
if ($isEditMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $gender    = trim($_POST['gender'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');

    // Toggle
    $change_password = (($_POST['change_password'] ?? '') === '1');

    // Password fields
    $old_password = $_POST['old_password'] ?? '';
    $password     = $_POST['password'] ?? '';
    $confirm      = $_POST['confirm_password'] ?? '';

    // Validation
    if ($full_name === '') $errors[] = "Full name is required.";
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if ($phone === '') $errors[] = "Mobile number is required.";
    if ($address === '') $errors[] = "Address is required.";
    if ($gender === '') $errors[] = "Gender is required.";

    // ✅ Password rules (only if toggle ON)
    $updatePassword = false;
    if ($change_password) {
        if (trim($old_password) === '') $errors[] = "Old password is required.";
        if (trim($password) === '') $errors[] = "New password is required.";
        if (trim($confirm) === '') $errors[] = "Confirm new password is required.";

        if ($password !== '' && strlen($password) < 8) $errors[] = "New password must be at least 8 characters.";
        if ($password !== '' && $confirm !== '' && $password !== $confirm) $errors[] = "New password and Confirm Password do not match.";

        // Verify old password using parents table OR user table
        if (empty($errors)) {
            $parentsHash = $parent['password'] ?? '';
            $userHash = get_user_hash($pdo, $loginEmail);

            $okOld =
                ($parentsHash && password_verify($old_password, $parentsHash)) ||
                ($userHash && password_verify($old_password, $userHash));

            if (!$okOld) {
                $errors[] = "Old password is incorrect.";
            } else {
                $updatePassword = true; // ✅ only now allow update
            }
        }
    }

    // Check email duplication (if changing)
    if (empty($errors) && strcasecmp($email, ($parent['email'] ?? '')) !== 0) {
        $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM parents WHERE LOWER(email)=LOWER(:e) AND id<>:id");
        $stmtDup->execute([':e' => $email, ':id' => $parentId]);
        if ((int)$stmtDup->fetchColumn() > 0) {
            $errors[] = "This email is already registered. Please use a different email.";
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $oldData = [
                'full_name' => $parent['full_name'] ?? null,
                'gender'    => $parent['gender'] ?? null,
                'email'     => $parent['email'] ?? null,
                'phone'     => $parent['phone'] ?? null,
                'address'   => $parent['address'] ?? null,
            ];

            // Generate one hash for both tables
            $newHash = null;
            if ($updatePassword) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
            }

            // Update parents
            $sql = "UPDATE parents
                    SET full_name = :full_name,
                        gender = :gender,
                        email = :email,
                        phone = :phone,
                        address = :address";
            $params = [
                ':full_name' => $full_name,
                ':gender'    => $gender,
                ':email'     => $email,
                ':phone'     => $phone,
                ':address'   => $address,
                ':id'        => $parentId
            ];

            if ($updatePassword) {
                $sql .= ", password = :password";
                $params[':password'] = $newHash;
            }

            $sql .= " WHERE id = :id";
            $pdo->prepare($sql)->execute($params);

            // Sync `user` table: email = username (and password optionally)
            $sqlUser = "UPDATE user SET username = :u";
            $userParams = [
                ':u'   => $email,
                ':old' => $loginEmail,
            ];

            if ($updatePassword) {
                $sqlUser .= ", password = :p";
                $userParams[':p'] = $newHash;
            }

            $sqlUser .= " WHERE LOWER(username)=LOWER(:old) AND role='parent'";
            $pdo->prepare($sqlUser)->execute($userParams);

            $newData = [
                'full_name' => $full_name,
                'gender'    => $gender,
                'email'     => $email,
                'phone'     => $phone,
                'address'   => $address,
            ];

            // Log (ignore if table doesn't exist)
            try {
                $pdo->prepare("
                    INSERT INTO parent_profile_update_log (parent_id, updated_by_userid, updated_at, old_data, new_data)
                    VALUES (:pid, :uid, :at, :old, :new)
                ")->execute([
                    ':pid' => $parentId,
                    ':uid' => (string)($_SESSION['userid'] ?? ''),
                    ':at'  => date('Y-m-d H:i:s'),
                    ':old' => json_encode($oldData),
                    ':new' => json_encode($newData),
                ]);
            } catch (Exception $ignoreLog) {}

            $pdo->commit();

            // Update session username if email changed
            $_SESSION['username'] = $email;

            // Redirect to VIEW mode (prevents resubmit)
            header("Location: parentProfile?updated=1");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Update failed: " . $e->getMessage();
        }
    }
}

// Show success after redirect
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $message = "Profile updated successfully.";
}

// Reload parent data
$stmt = $pdo->prepare("SELECT * FROM parents WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $parentId]);
$parent = $stmt->fetch();

$full_name = $parent['full_name'] ?? '';
$gender    = $parent['gender'] ?? '';
$email     = $parent['email'] ?? '';
$phone     = $parent['phone'] ?? '';
$address   = $parent['address'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>My Profile</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body id="page-top">
<div id="wrapper">

    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">

            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h1 class="h3 text-gray-800 mb-0"><i class="fas fa-user-circle mr-2" style="color:var(--brand);"></i>My Profile</h1>

                    <?php if (!$isEditMode): ?>
                        <a href="parentProfile?edit=1" class="btn btn-primary">
                            <i class="fas fa-user-edit mr-1"></i> Edit Profile
                        </a>
                    <?php else: ?>
                        <a href="parentProfile" class="btn btn-secondary">
                            <i class="fas fa-times mr-1"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($message)): ?>
                    <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        Swal.fire({icon:'success', title:'Profile Updated!', text:'Your changes have been saved.', timer:2000, showConfirmButton:false, confirmButtonColor:'#881b12'});
                    });
                    </script>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <ul class="mb-0 d-inline">
                            <?php foreach ($errors as $er): ?>
                                <li><?= htmlspecialchars($er) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!$isEditMode): ?>
                    <!-- VIEW MODE -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-id-card mr-1"></i> Your Details</h6>
                            <span class="badge badge-info">View Mode</span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="profile-label"><i class="fas fa-user mr-1" style="color:var(--brand);font-size:0.6rem;"></i> Full Name</div>
                                    <div class="profile-value"><?= htmlspecialchars($full_name) ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="profile-label"><i class="fas fa-venus-mars mr-1" style="color:var(--brand);font-size:0.6rem;"></i> Gender</div>
                                    <div class="profile-value"><?= htmlspecialchars($gender ?: '—') ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="profile-label"><i class="fas fa-envelope mr-1" style="color:var(--brand);font-size:0.6rem;"></i> Email (Username)</div>
                                    <div class="profile-value"><?= htmlspecialchars($email) ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="profile-label"><i class="fas fa-phone mr-1" style="color:var(--brand);font-size:0.6rem;"></i> Mobile Number</div>
                                    <div class="profile-value"><?= htmlspecialchars($phone) ?></div>
                                </div>

                                <div class="col-md-12 mb-1">
                                    <div class="profile-label"><i class="fas fa-map-marker-alt mr-1" style="color:var(--brand);font-size:0.6rem;"></i> Address</div>
                                    <div class="profile-value"><?= htmlspecialchars($address) ?></div>
                                </div>
                            </div>

                            <hr>
                            <div class="info-box mt-3">
                                <i class="fas fa-info-circle"></i>
                                To update your details or change your password, click <strong>Edit Profile</strong>.
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- EDIT MODE -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-edit mr-1"></i> Edit Your Details</h6>
                            <span class="badge badge-warning">Edit Mode</span>
                        </div>

                        <div class="card-body">
                            <form method="POST" action="parentProfile?edit=1" id="profileForm">

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><i class="fas fa-user mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Full Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="full_name" required
                                                   value="<?= htmlspecialchars($full_name) ?>" placeholder="Enter full name">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><i class="fas fa-venus-mars mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Gender <span class="text-danger">*</span></label>
                                            <select class="form-control" name="gender" required>
                                                <option value="">— Select —</option>
                                                <?php
                                                $opts = ['Male','Female','Other'];
                                                foreach ($opts as $o) {
                                                    $sel = (strcasecmp($gender, $o) === 0) ? 'selected' : '';
                                                    echo "<option value=\"".htmlspecialchars($o)."\" $sel>".htmlspecialchars($o)."</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><i class="fas fa-envelope mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Email (Username) <span class="text-danger">*</span></label>
                                            <input type="email" class="form-control" name="email" required
                                                   value="<?= htmlspecialchars($email) ?>" placeholder="you@example.com">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><i class="fas fa-phone mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Mobile Number <span class="text-danger">*</span></label>
                                            <input type="tel" class="form-control" name="phone" required
                                                   value="<?= htmlspecialchars($phone) ?>" placeholder="0412 345 678">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label><i class="fas fa-map-marker-alt mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Address <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="address" required
                                           value="<?= htmlspecialchars($address) ?>" placeholder="Street, Suburb, State, Postcode">
                                </div>

                                <hr>

                                <!-- Toggle Change Password -->
                                <div class="custom-control custom-switch mb-3">
                                    <input type="checkbox" class="custom-control-input" id="toggleChangePw">
                                    <label class="custom-control-label" for="toggleChangePw" style="font-size:0.88rem; font-weight:600;">
                                        <i class="fas fa-key mr-1" style="color:var(--brand);"></i> Change Password
                                    </label>
                                </div>

                                <input type="hidden" name="change_password" id="change_password" value="0">

                                <div id="passwordBox" style="display:none;">
                                    <div class="info-box">
                                        <i class="fas fa-shield-alt"></i>
                                        For security, enter your <strong>current password</strong> before setting a new one.
                                    </div>

                                    <div class="form-group">
                                        <label><i class="fas fa-lock mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Current Password <span class="text-danger">*</span></label>
                                        <div class="pw-wrapper">
                                            <input type="password" class="form-control" name="old_password" id="old_password" autocomplete="current-password" placeholder="Enter current password">
                                            <button type="button" class="pw-toggle" onclick="togglePw('old_password', this)"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><i class="fas fa-lock-open mr-1" style="color:var(--brand);font-size:0.7rem;"></i> New Password <span class="text-danger">*</span></label>
                                                <div class="pw-wrapper">
                                                    <input type="password" class="form-control" name="password" id="password" autocomplete="new-password" placeholder="Min 8 characters">
                                                    <button type="button" class="pw-toggle" onclick="togglePw('password', this)"><i class="fas fa-eye"></i></button>
                                                </div>
                                                <div class="pw-strength"><div class="pw-strength-bar" id="strengthBar"></div></div>
                                                <div class="pw-hint" id="pwHints">
                                                    <span class="unmet" data-check="len">8+ chars</span> &middot;
                                                    <span class="unmet" data-check="letter">1 letter</span> &middot;
                                                    <span class="unmet" data-check="num">1 number</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><i class="fas fa-check-double mr-1" style="color:var(--brand);font-size:0.7rem;"></i> Confirm Password <span class="text-danger">*</span></label>
                                                <div class="pw-wrapper">
                                                    <input type="password" class="form-control" name="confirm_password" id="confirm_password" autocomplete="new-password" placeholder="Re-enter password">
                                                    <button type="button" class="pw-toggle" onclick="togglePw('confirm_password', this)"><i class="fas fa-eye"></i></button>
                                                </div>
                                                <div id="matchFeedback" style="font-size:0.72rem; margin-top:4px;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <button class="btn btn-primary" type="submit" id="saveBtn">
                                        <i class="fas fa-save mr-1"></i> Save Changes
                                    </button>
                                    <a class="btn btn-secondary ml-2" href="parentProfile">Cancel</a>
                                </div>

                            </form>
                        </div>
                    </div>

                    <script>
                    function togglePw(id, btn) {
                        const f = document.getElementById(id);
                        const icon = btn.querySelector('i');
                        if (f.type === 'password') { f.type = 'text'; icon.className = 'fas fa-eye-slash'; }
                        else { f.type = 'password'; icon.className = 'fas fa-eye'; }
                    }

                    document.addEventListener('DOMContentLoaded', function () {
                        const toggle = document.getElementById('toggleChangePw');
                        const box = document.getElementById('passwordBox');
                        const flag = document.getElementById('change_password');

                        const wasOn = <?= (isset($_POST['change_password']) && $_POST['change_password'] === '1') ? 'true' : 'false'; ?>;
                        if (toggle) toggle.checked = wasOn;

                        function applyToggle() {
                            const on = toggle && toggle.checked;
                            if (flag) flag.value = on ? '1' : '0';
                            if (box) box.style.display = on ? 'block' : 'none';
                            if (!on) {
                                ['old_password','password','confirm_password'].forEach(id => {
                                    const el = document.getElementById(id);
                                    if (el) el.value = '';
                                });
                            }
                        }

                        if (toggle) {
                            toggle.addEventListener('change', applyToggle);
                            applyToggle();
                        }

                        // Password strength
                        const pwField = document.getElementById('password');
                        const confirmField = document.getElementById('confirm_password');
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

                                const hints = document.getElementById('pwHints');
                                hints.querySelector('[data-check=len]').className = v.length >= 8 ? 'met' : 'unmet';
                                hints.querySelector('[data-check=letter]').className = /[A-Za-z]/.test(v) ? 'met' : 'unmet';
                                hints.querySelector('[data-check=num]').className = /[0-9]/.test(v) ? 'met' : 'unmet';

                                checkMatch();
                            });
                        }
                        if (confirmField) confirmField.addEventListener('input', checkMatch);

                        function checkMatch() {
                            const fb = document.getElementById('matchFeedback');
                            if (!confirmField || !confirmField.value) { fb.innerHTML = ''; return; }
                            if (pwField.value === confirmField.value) {
                                fb.innerHTML = '<span style="color:#28a745;"><i class="fas fa-check-circle"></i> Passwords match</span>';
                            } else {
                                fb.innerHTML = '<span style="color:#dc3545;"><i class="fas fa-times-circle"></i> Do not match</span>';
                            }
                        }

                        // Submit loading
                        document.getElementById('profileForm').addEventListener('submit', function() {
                            const btn = document.getElementById('saveBtn');
                            btn.disabled = true;
                            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Saving...';
                        });
                    });
                    </script>

                <?php endif; ?>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
