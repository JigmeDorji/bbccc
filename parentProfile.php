<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'parent') {
    header("Location: index-admin.php");
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
    die("DB connection failed: " . $e->getMessage());
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
            header("Location: parentProfile.php?updated=1");
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

    <style>
        .profile-label { color:#6c757d; font-size:12px; text-transform: uppercase; letter-spacing:.02em; }
        .profile-value { font-size:15px; font-weight:600; color:#343a40; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">

    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">

            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h1 class="h3 text-gray-800 mb-0">My Profile</h1>

                    <?php if (!$isEditMode): ?>
                        <a href="parentProfile.php?edit=1" class="btn btn-primary">
                            <i class="fas fa-user-edit mr-1"></i> Edit Profile
                        </a>
                    <?php else: ?>
                        <a href="parentProfile.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-1"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $er): ?>
                                <li><?php echo htmlspecialchars($er); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!$isEditMode): ?>
                    <!-- ✅ VIEW MODE -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Your Details</h6>
                            <span class="badge badge-info">View Mode</span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="profile-label">Full Name</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($full_name); ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="profile-label">Gender</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($gender ?: '-'); ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="profile-label">Email (Username)</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($email); ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="profile-label">Mobile Number</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($phone); ?></div>
                                </div>

                                <div class="col-md-12 mb-1">
                                    <div class="profile-label">Address</div>
                                    <div class="profile-value"><?php echo htmlspecialchars($address); ?></div>
                                </div>
                            </div>

                            <hr>
                            <div class="small text-muted">
                                To update your details, click <strong>Edit Profile</strong>.
                            </div>
                        </div>
                    </div>

                    <a class="btn btn-secondary" href="index-admin.php">Back to Dashboard</a>

                <?php else: ?>
                    <!-- ✅ EDIT MODE -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Edit Your Details</h6>
                            <span class="badge badge-warning">Edit Mode</span>
                        </div>

                        <div class="card-body">
                            <form method="POST" action="parentProfile.php?edit=1" id="profileForm">

                                <div class="form-group">
                                    <label>Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="full_name" required
                                           value="<?php echo htmlspecialchars($full_name); ?>">
                                </div>

                                <div class="form-group">
                                    <label>Gender <span class="text-danger">*</span></label>
                                    <select class="form-control" name="gender" required>
                                        <option value="">-- Select --</option>
                                        <?php
                                        $opts = ['Male','Female','Other'];
                                        foreach ($opts as $o) {
                                            $sel = (strcasecmp($gender, $o) === 0) ? 'selected' : '';
                                            echo "<option value=\"".htmlspecialchars($o)."\" $sel>".htmlspecialchars($o)."</option>";
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Email (This is your username) <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" name="email" required
                                           value="<?php echo htmlspecialchars($email); ?>">
                                </div>

                                <div class="form-group">
                                    <label>Mobile Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="phone" required
                                           value="<?php echo htmlspecialchars($phone); ?>">
                                </div>

                                <div class="form-group">
                                    <label>Address <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="address" required
                                           value="<?php echo htmlspecialchars($address); ?>">
                                </div>

                                <hr>

                                <!-- ✅ Toggle Change Password -->
                                <div class="custom-control custom-switch mb-3">
                                    <input type="checkbox" class="custom-control-input" id="toggleChangePw">
                                    <label class="custom-control-label" for="toggleChangePw">Change Password</label>
                                </div>

                                <!-- Hidden field sent to server -->
                                <input type="hidden" name="change_password" id="change_password" value="0">

                                <div id="passwordBox" style="display:none;">
                                    <div class="alert alert-light">
                                        For security, please enter your <strong>old password</strong> first.
                                    </div>

                                    <div class="form-group">
                                        <label>Old Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" name="old_password" id="old_password" autocomplete="current-password">
                                    </div>

                                    <div class="form-group">
                                        <label>New Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" name="password" id="password" autocomplete="new-password" placeholder="Min 8 characters">
                                    </div>

                                    <div class="form-group">
                                        <label>Confirm New Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" name="confirm_password" id="confirm_password" autocomplete="new-password" placeholder="Re-enter new password">
                                    </div>
                                </div>

                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-save mr-1"></i> Save Changes
                                </button>

                                <a class="btn btn-secondary ml-2" href="parentProfile.php">Cancel</a>

                            </form>
                        </div>
                    </div>

                    <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const toggle = document.getElementById('toggleChangePw');
                        const box = document.getElementById('passwordBox');
                        const flag = document.getElementById('change_password');

                        // Keep toggle ON after a failed submit if user selected it
                        const wasOn = <?php echo (isset($_POST['change_password']) && $_POST['change_password'] === '1') ? 'true' : 'false'; ?>;
                        if (toggle) toggle.checked = wasOn;

                        function applyToggle() {
                            const on = toggle && toggle.checked;
                            if (flag) flag.value = on ? '1' : '0';
                            if (box) box.style.display = on ? 'block' : 'none';

                            // Optional: clear fields when OFF
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
