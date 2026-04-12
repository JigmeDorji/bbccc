<?php
require_once "include/auth.php";
require_once "include/config.php";
require_once "include/utils.php";
require_once "include/csrf.php";
require_once "include/account_activation.php";

$login_error = false;
$login_error_message = 'Invalid email or password. Please try again.';

// Load email from cookie if available (Remember me)
if (!empty($_COOKIE['remember_user'])) {
    $userName = $_COOKIE['remember_user'];
} else {
    $userName = get_or_default($_POST, 'userName', '');
}

$password = get_or_default($_POST, 'password', '');

if (!empty($userName) && !empty($password)) {
    verify_csrf();

    try {
        $pdoSchema = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);
        bbcc_activation_ensure_schema($pdoSchema);
    } catch (Throwable $e) {
        // If schema check fails, continue with existing login behavior.
    }

    $conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME);
    if (!$conn) {
        die("Database connection failed: " . mysqli_connect_error());
    }

    // Check whether activation column exists (for backward compatibility on hosting DBs)
    $hasIsActive = false;
    $colResult = @mysqli_query($conn, "SHOW COLUMNS FROM `user` LIKE 'is_active'");
    if ($colResult instanceof mysqli_result && mysqli_num_rows($colResult) > 0) {
        $hasIsActive = true;
    }

    // ✅ Email as Username (u.username stores email)
    // ✅ Fix: WHERE userName=?  -> WHERE u.username=?
    // ✅ Also fetch projectID/companyName/projectName to match login() function args
    $isActiveSelect = $hasIsActive ? "IFNULL(u.is_active, 1) AS is_active" : "1 AS is_active";
    $query = "
        SELECT
            u.userid,
            u.username,
            u.password,
            u.companyID,
            u.projectID,
            u.role,
            {$isActiveSelect},
            c.companyName,
            p.projectName
        FROM user u
        LEFT JOIN company c ON c.companyID = u.companyID
        LEFT JOIN project p ON p.projectID = u.projectID
        WHERE LOWER(u.username) = LOWER(?)
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        $login_error = true;
        $login_error_message = 'Unable to login right now. Please try again shortly.';
        mysqli_close($conn);
        goto login_render;
    }
    mysqli_stmt_bind_param($stmt, "s", $userName);

    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);

        if ($row) {
            $db_password = $row['password'] ?? '';

            // Detect hashed password
            $is_hashed = preg_match('/^\$2[ayb]\$|\$argon2/', $db_password);

            $password_ok = false;
            if ($is_hashed) {
                $password_ok = password_verify($password, $db_password);
            } else {
                $password_ok = ($password === $db_password);
            }

            if ($password_ok) {
                if ((int)($row['is_active'] ?? 1) !== 1) {
                    $login_error = true;
                    $login_error_message = 'Please activate your account from the email link before logging in. If you cannot find it, check Spam/Junk and mark it as Not Spam.';
                } else {
                    // Upgrade plain password to hashed
                    if (!$is_hashed) {
                        $new_hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $update = mysqli_prepare($conn, "UPDATE user SET password=? WHERE userid=?");
                        // ✅ userid is string -> use "ss"
                        mysqli_stmt_bind_param($update, "ss", $new_hashed_password, $row['userid']);
                        mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                    }

                    // ✅ Create session
                    login(
                        $row['userid'],
                        $row['username'],
                        $row['companyID'] ?? null,
                        $row['projectID'] ?? null,
                        $row['companyName'] ?? null,
                        $row['projectName'] ?? null,
                        $row['role'] ?? null
                    );

                    // ✅ IMPORTANT: store email in session for parent matching
                    $_SESSION['email'] = $row['username'];
                    unset($_SESSION['active_portal']);

                    // Mixed profile default portal: Teacher first
                    $hasParentProfile = false;
                    $hasTeacherProfile = false;

                    $stmtParentProfile = mysqli_prepare(
                        $conn,
                        "SELECT id FROM parents WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?) LIMIT 1"
                    );
                    if ($stmtParentProfile) {
                        mysqli_stmt_bind_param($stmtParentProfile, "ss", $row['username'], $row['username']);
                        if (mysqli_stmt_execute($stmtParentProfile)) {
                            $parentResult = mysqli_stmt_get_result($stmtParentProfile);
                            $hasParentProfile = (bool)mysqli_fetch_assoc($parentResult);
                        }
                        mysqli_stmt_close($stmtParentProfile);
                    }

                    $stmtTeacherProfile = mysqli_prepare(
                        $conn,
                        "SELECT id FROM teachers WHERE (user_id = ? AND ? <> '') OR LOWER(email) = LOWER(?) LIMIT 1"
                    );
                    if ($stmtTeacherProfile) {
                        mysqli_stmt_bind_param($stmtTeacherProfile, "sss", $row['userid'], $row['userid'], $row['username']);
                        if (mysqli_stmt_execute($stmtTeacherProfile)) {
                            $teacherResult = mysqli_stmt_get_result($stmtTeacherProfile);
                            $hasTeacherProfile = (bool)mysqli_fetch_assoc($teacherResult);
                        }
                        mysqli_stmt_close($stmtTeacherProfile);
                    }

                    if ($hasTeacherProfile) {
                        $_SESSION['active_portal'] = 'teacher';
                    } elseif ($hasParentProfile) {
                        $_SESSION['active_portal'] = 'parent';
                    }

                    // Optional: load projects for this company (if you use it)
                    try {
                        $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                        ]);

                        if (!empty($row['companyID'])) {
                            $stmtP = $pdo->prepare("SELECT projectID, projectName FROM project WHERE companyID = ?");
                            $stmtP->execute([$row['companyID']]);
                            $_SESSION['projects'] = $stmtP->fetchAll(PDO::FETCH_ASSOC);

                            // Default project if not set
                            if (!empty($_SESSION['projects'][0]['projectID'])) {
                                $_SESSION['projectID'] = $_SESSION['projects'][0]['projectID'];
                            }
                        }
                    } catch (Exception $e) {
                        // ignore if project table not used
                    }

                    // Remember me cookie
                    if (!empty($_POST['remember'])) {
                        setcookie("remember_user", $userName, time() + (86400 * 30), "/"); // 30 days
                    } else {
                        setcookie("remember_user", "", time() - 3600, "/");
                    }

                    $role = strtolower(trim((string)($row['role'] ?? '')));
                    $redirect = 'index-admin';
                    if ($role === 'patron') {
                        $redirect = 'patron-dashboard';
                    }

                    header('Location: ' . $redirect);
                    exit;
                }
            } else {
                $login_error = true;
            }

        } else {
            $login_error = true;
        }
    } else {
        $login_error = true;
    }

    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    if ($login_error) {
        bbcc_audit_log('login_failed', 'auth', [
            'username_input' => (string)$userName,
        ], 'warning');
    }
}
?>
<?php login_render: ?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Login</title>

    <?php include_once 'include/global_css.php'; ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        :root {
            --primary-color: #881b12;
            --primary-dark: #6b140d;
            --primary-light: #a82218;
            --text-color: #1a202c;
            --secondary-text-color: #718096;
            --border-color: #dee2e6;
            --background-color: #f5f7fa;
            --card-background: #ffffff;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body { background: var(--background-color); font-family: 'Inter', var(--font-body, sans-serif); }

        .login-container {
            display: flex;
            width: 100%;
            max-width: 1100px;
            margin: auto;
            background-color: var(--card-background);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }

        .login-form-container {
            flex: 1;
            padding: 3.5rem 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-content { margin-bottom: 1.5rem; }

        .login-form-container h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
            color: var(--text-color);
        }

        .login-form-container > .form-content > p {
            color: var(--secondary-text-color);
            margin-bottom: 1.8rem;
            font-size: 0.92rem;
        }

        .input-group {
            margin-bottom: 1.3rem;
            position: relative;
        }

        .input-group label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #5a5c69;
        }

        .input-group label i {
            color: var(--primary-color);
            margin-right: 4px;
            font-size: 0.75rem;
        }

        .input-group input {
            width: 100%;
            padding: 0.7rem 1rem;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-size: 0.92rem;
            transition: all 0.2s ease-in-out;
            font-family: 'Inter', sans-serif;
        }

        .input-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(136, 27, 18, 0.12);
        }

        .pw-wrapper { position: relative; }
        .pw-wrapper input { padding-right: 48px; }
        .pw-toggle-login {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #858796;
            cursor: pointer;
            padding: 4px 6px;
            font-size: 0.85rem;
        }
        .pw-toggle-login:hover { color: var(--primary-color); }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .remember-me input {
            margin: 0;
            transform: translateY(-1px);
            accent-color: var(--primary-color);
            width: auto;
        }

        .remember-me label {
            margin: 0;
            line-height: 1;
            cursor: pointer;
            font-size: 0.85rem;
            text-transform: none;
            letter-spacing: 0;
            color: #5a5c69;
        }

        .forgot-password {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .forgot-password:hover { text-decoration: underline; }

        .login-button {
            width: 100%;
            padding: 0.85rem;
            background: var(--primary-color);
            color: white;
            font-size: 0.95rem;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
            letter-spacing: 0.3px;
        }

        .login-button:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(136, 27, 18, 0.3);
        }

        .login-button:active { transform: translateY(0); }

        .login-image-container {
            flex: 1;
            background-image: url('bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png');
            background-size: cover;
            background-position: center;
            position: relative;
            padding: 3rem;
            color: white;
            display: flex;
            justify-content: flex-end;
            flex-direction: column;
        }

        .login-image-container::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.85), rgba(0,0,0,0.5));
        }

        .overlay-text { position: relative; z-index: 2; }
        .overlay-text h3 { font-size: 1.6rem; font-weight: 700; margin-bottom: 8px; color: #fff; }
        .overlay-text p { font-size: 0.88rem; opacity: 1; line-height: 1.5; color: rgba(255,255,255,.9); }

        .signup-link {
            text-align: center;
            font-size: 0.88rem;
            color: var(--secondary-text-color);
            margin-top: 1rem;
        }

        .signup-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }
        .signup-link a:hover { text-decoration: underline; }

        .error-box {
            background: #fef3f2;
            border-left: 4px solid var(--primary-color);
            color: #721c24;
            padding: 12px 16px;
            margin-bottom: 18px;
            border-radius: 8px;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .error-box i { color: var(--primary-color); font-size: 1rem; }

        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0;
            color: #ccc;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        .divider span { padding: 0 12px; }

        @media (max-width: 992px) {
            .login-container { max-width: 90%; }
            .login-form-container { padding: 2.5rem 2rem; }
        }

        @media (max-width: 768px) {
            .login-container { flex-direction: column; max-width: 450px; margin: auto; }
            .login-image-container { height: 200px; display: block; border-radius: 0; }
            .overlay-text h3 { font-size: 1.3rem; }
        }

        @media (max-width: 480px) {
            .login-form-container { padding: 1.5rem; }
            .login-form-container h2 { font-size: 1.5rem; }
        }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<section class="bbcc-section" style="display:flex;align-items:center;min-height:calc(100vh - 200px);">
    <div class="bbcc-container">
        <div class="login-container">
            <div class="login-form-container">
                <div class="form-content">
                    <h2>Welcome Back</h2>
                    <p>Please enter your details to sign in.</p>

                    <?php if (!empty($login_error)): ?>
                        <div class="error-box"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($login_error_message) ?></div>
                    <?php endif; ?>

                    <form action="login" method="post" id="loginForm">
                        <?= csrf_field() ?>
                        <div class="input-group">
                            <label for="userName"><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" name="userName" id="userName"
                                   placeholder="you@example.com"
                                   value="<?php echo htmlspecialchars($userName); ?>" required autocomplete="email">
                        </div>

                        <div class="input-group">
                            <label for="password"><i class="fas fa-lock"></i> Password</label>
                            <div class="pw-wrapper">
                                <input type="password" name="password" id="password" placeholder="••••••••" required autocomplete="current-password">
                                <button type="button" class="pw-toggle-login" onclick="togglePw()" tabindex="-1">
                                    <i class="fas fa-eye" id="pwIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-options">
                            <div class="remember-me">
                                <input type="checkbox" id="remember" name="remember" <?php if(!empty($_COOKIE['remember_user'])) echo "checked"; ?>>
                                <label for="remember">Remember me</label>
                            </div>
                            <a href="forgotPassword" class="forgot-password">Forgot Password?</a>
                        </div>

                        <button type="submit" class="login-button" id="loginBtn">
                            <i class="fas fa-sign-in-alt" style="margin-right:6px;"></i>Sign In
                        </button>
                    </form>

                    <script>
                    function togglePw() {
                        const f = document.getElementById('password');
                        const i = document.getElementById('pwIcon');
                        if (f.type === 'password') { f.type = 'text'; i.className = 'fas fa-eye-slash'; }
                        else { f.type = 'password'; i.className = 'fas fa-eye'; }
                    }
                    document.getElementById('loginForm').addEventListener('submit', function() {
                        const btn = document.getElementById('loginBtn');
                        btn.disabled = true;
                        btn.innerHTML = '<span style="display:inline-block;width:16px;height:16px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:8px;"></span>Signing in...';
                    });
                    </script>
                    <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
                </div>

                <div class="signup-link">
                    Don't have account? <a href="parentAccountSetup">Parent Sign Up</a> or <a href="patronRegistration">Patron Sign Up</a>
                </div>
            </div>

            <div class="login-image-container">
                <div class="overlay-text">
                    <h3>Your Place of Peace</h3>
                    <p>Find guidance and tranquility within a supportive community dedicated to spiritual growth, meditation, and overall wellbeing.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
include_once 'include/footer.php';
include_once 'include/global_js.php';
?>
</body>
</html>
