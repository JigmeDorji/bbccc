<?php
require_once "include/config.php";
require_once "include/auth.php";
require_login();

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'parent') {
    // If you want admins to also use this page, remove this block.
    header("Location: index-admin.php");
    exit;
}

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

// ✅ Email is username in your system
$loginEmail = trim($_SESSION['username'] ?? '');
if ($loginEmail === '') {
    die("Session username missing. Please log out and log in again.");
}

// Fetch parent profile by email (case-insensitive)
$stmt = $pdo->prepare("SELECT * FROM parents WHERE LOWER(email) = LOWER(:e) LIMIT 1");
$stmt->execute([':e' => $loginEmail]);
$parent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parent) {
    die("No parent record found for your login email: " . htmlspecialchars($loginEmail) . ". Please contact admin.");
}

$parentId = (int)$parent['id'];

// Sticky values (default from DB)
$full_name = $parent['full_name'] ?? '';
$gender    = $parent['gender'] ?? '';        // if you added gender column
$email     = $parent['email'] ?? '';
$phone     = $parent['phone'] ?? '';
$address   = $parent['address'] ?? '';

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $gender    = trim($_POST['gender'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Validation
    if ($full_name === '') $errors[] = "Full name is required.";
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if ($phone === '') $errors[] = "Mobile number is required.";
    if ($address === '') $errors[] = "Address is required.";
    if ($gender === '') $errors[] = "Gender is required.";

    // If password fields are filled, validate
    $updatePassword = false;
    if ($password !== '' || $confirm !== '') {
        if ($password !== $confirm) $errors[] = "Password and Confirm Password do not match.";
        if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
        $updatePassword = empty($errors);
    }

    // Check email duplication (if changing)
    if (empty($errors) && strcasecmp($email, $parent['email']) !== 0) {
        $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM parents WHERE LOWER(email)=LOWER(:e) AND id<>:id");
        $stmtDup->execute([':e' => $email, ':id' => $parentId]);
        if ((int)$stmtDup->fetchColumn() > 0) {
            $errors[] = "This email is already registered. Please use a different email.";
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // OLD data for log
            $oldData = [
                'full_name' => $parent['full_name'] ?? null,
                'gender'    => $parent['gender'] ?? null,
                'email'     => $parent['email'] ?? null,
                'phone'     => $parent['phone'] ?? null,
                'address'   => $parent['address'] ?? null,
            ];

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
                $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = :id";

            $stmtUp = $pdo->prepare($sql);
            $stmtUp->execute($params);

            // Keep `user.username` in sync with email (since email = username)
            $stmtUser = $pdo->prepare("UPDATE user SET username = :u" . ($updatePassword ? ", password = :p" : "") . " WHERE LOWER(username)=LOWER(:old) AND role='parent'");
            $userParams = [
                ':u'   => $email,
                ':old' => $loginEmail
            ];
            if ($updatePassword) {
                $userParams[':p'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $stmtUser->execute($userParams);

            // NEW data for log
            $newData = [
                'full_name' => $full_name,
                'gender'    => $gender,
                'email'     => $email,
                'phone'     => $phone,
                'address'   => $address,
            ];

            // Log row
            $stmtLog = $pdo->prepare("
                INSERT INTO parent_profile_update_log (parent_id, updated_by_userid, updated_at, old_data, new_data)
                VALUES (:pid, :uid, :at, :old, :new)
            ");
            $stmtLog->execute([
                ':pid' => $parentId,
                ':uid' => (string)($_SESSION['userid'] ?? ''),
                ':at'  => date('Y-m-d H:i:s'),
                ':old' => json_encode($oldData),
                ':new' => json_encode($newData),
            ]);

            $pdo->commit();

            // Update session username if email changed
            $_SESSION['username'] = $email;

            $message = "Profile updated successfully.";

            // Reload parent data
            $stmt = $pdo->prepare("SELECT * FROM parents WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $parentId]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);

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
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>My Profile</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>

<body id="page-top">
<div id="wrapper">

    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">

            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

                <h1 class="h3 mb-3 text-gray-800">My Profile</h1>

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

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Update Your Details</h6>
                    </div>

                    <div class="card-body">
                        <form method="POST" action="parentProfile.php">

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

                            <div class="alert alert-light">
                                If you don’t want to change your password, leave it blank.
                            </div>

                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" class="form-control" name="password" placeholder="Min 8 characters">
                            </div>

                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" class="form-control" name="confirm_password" placeholder="Re-enter password">
                            </div>

                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-save mr-1"></i> Update Profile
                            </button>

                            <a class="btn btn-secondary ml-2" href="index-admin.php">Back to Dashboard</a>
                        </form>
                    </div>
                </div>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>