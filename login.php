<?php
require_once "include/auth.php";
require_once "include/config.php";
require_once "include/utils.php";

$login_error = false;

// Load email from cookie if available (Remember me)
if (!empty($_COOKIE['remember_user'])) {
    $userName = $_COOKIE['remember_user'];
} else {
    $userName = get_or_default($_POST, 'userName', '');
}

$password = get_or_default($_POST, 'password', '');

if (!empty($userName) && !empty($password)) {

    $conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME);
    if (!$conn) {
        die("Database connection failed: " . mysqli_connect_error());
    }

    // ✅ Email as Username (u.username stores email)
    // ✅ Fix: WHERE userName=?  -> WHERE u.username=?
    // ✅ Also fetch projectID/companyName/projectName to match login() function args
    $query = "
        SELECT
            u.userid,
            u.username,
            u.password,
            u.companyID,
            u.projectID,
            u.role,
            c.companyName,
            p.projectName
        FROM user u
        LEFT JOIN company c ON c.companyID = u.companyID
        LEFT JOIN project p ON p.projectID = u.projectID
        WHERE LOWER(u.username) = LOWER(?)
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $query);
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

                // Optional: load projects for this company (if you use it)
                try {
                    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

                header('Location: index-admin.php');
                exit;

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
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Login</title>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        :root {
            --primary-color: #2D67F2;
            --text-color: #1a202c;
            --secondary-text-color: #718096;
            --border-color: #e2e8f0;
            --background-color: #f7fafc;
            --card-background: #ffffff;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body { background: var(--background-color); }

        .login-container {
            display: flex;
            width: 100%;
            max-width: 1100px;
            margin: auto;
            background-color: var(--card-background);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .login-form-container {
            flex: 1;
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-content { margin-bottom: 2rem; }

        .login-form-container h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .login-form-container p {
            color: var(--secondary-text-color);
            margin-bottom: 2rem;
        }

        .input-group { margin-bottom: 1.5rem; }

        .input-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .input-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s ease-in-out;
        }

        .input-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 103, 242, 0.2);
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
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
        }

        .remember-me label {
            margin: 0;
            line-height: 1;
            cursor: pointer;
        }

        .login-button {
            width: 100%;
            padding: 1rem;
            background-color: var(--primary-color);
            color: white;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        .login-button:hover { background-color: #2354cf; }

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
            background: linear-gradient(to top, rgba(0,0,0,0.7), rgba(0,0,0,0.1));
        }

        .overlay-text { position: relative; z-index: 2; }

        .signup-link {
            text-align: center;
            font-size: 0.875rem;
            color: var(--secondary-text-color);
            margin-top: 1rem;
        }

        .signup-link a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .error-box {
            background: #ffdddd;
            border: 1px solid #ff5c5c;
            color: #b30000;
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        @media (max-width: 992px) {
            .login-container { max-width: 90%; }
            .login-form-container { padding: 3rem 2rem; }
        }

        @media (max-width: 768px) {
            .login-container { flex-direction: column; max-width: 450px; margin: auto; }
            .login-image-container { height: 250px; display: block; border-radius: 0; }
            .overlay-text h3 { font-size: 1.5rem; }
        }

        @media (max-width: 480px) {
            .login-form-container { padding: 1.5rem; }
            .login-form-container h2 { font-size: 1.6rem; }
            .overlay-text h3 { font-size: 1.3rem; }
        }
    </style>

    <?php include_once 'include/global_css.php'; ?>
</head>
<body>

<?php include_once 'include/nav.php'; ?>

<div class="blog_area section_padding">
    <div class="container">
        <div class="login-container">
            <div class="login-form-container">
                <div class="form-content">
                    <h2>Welcome Back</h2>
                    <p>Please enter your details to sign in.</p>

                    <?php if (!empty($login_error)): ?>
                        <div class="error-box">Invalid email or password.</div>
                    <?php endif; ?>

                    <form action="login.php" method="post">
                        <div class="input-group">
                            <label for="userName">Email Address</label>
                            <input type="email" name="userName" id="userName"
                                   placeholder="you@example.com"
                                   value="<?php echo htmlspecialchars($userName); ?>" required>
                        </div>

                        <div class="input-group">
                            <label for="password">Password</label>
                            <input type="password" name="password" id="password" placeholder="••••••••" required>
                        </div>

                        <div class="form-options">
                            <div class="remember-me">
                                <input type="checkbox" id="remember" name="remember" <?php if(!empty($_COOKIE['remember_user'])) echo "checked"; ?>>
                                <label for="remember">Remember me</label>
                            </div>
                            <a href="#" class="forgot-password">Forgot Password?</a>
                        </div>

                        <button type="submit" class="login-button">Sign In</button>
                    </form>
                </div>

                <div class="signup-link">
                    Don't have account? <a href="parentAccountSetup.php">Sign Up</a>
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

    <br><br><br><br><br><br><br><br><br>

<?php
include_once 'include/footer.php';
include_once 'include/global_js.php';
?>
</body>
</html>
