<?php
require_once "include/auth.php";
require_once "include/config.php";
require_once "include/utils.php";

$userName = get_or_default($_POST, 'userName', '');
$password = get_or_default($_POST, 'password', '');

if ($userName && $password) {
    $conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME);

    $query = "select u.userid, 
       u.username, 
       u.password, 
       u.companyID, 
       u.projectID, 
       c.companyName, 
       u.role,
       p.projectName
    FROM user u  
    inner join company c on u.companyID=c.companyID 
    inner join project p on p.projectID=u.projectID WHERE userName=?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $userName);

    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);

        if ($row) {
            $db_password = $row['password'];

            // For better security, consider using password_hash and password_verify here
            if ($row) {
                $db_password = $row['password'];

                // Check if stored password is hashed
                $is_hashed = preg_match('/^\$2[ayb]\$|\$argon2/', $db_password);

                // Verify password accordingly
                if (
                    ($is_hashed && password_verify($password, $db_password)) ||
                    (!$is_hashed && $password === $db_password)
                ) {
                    // Optional: Upgrade plain password to hashed version
                    if (!$is_hashed) {
                        $new_hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $update = mysqli_prepare($conn, "UPDATE user SET password=? WHERE userid=?");
                        mysqli_stmt_bind_param($update, "si", $new_hashed_password, $row['userid']);
                        mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                    }

                    login($row['userid'], $row['username'], $row['companyID'], $row['projectID'], $row['companyName'],$row['projectName'],$row['role']);

                    // Load all project IDs under this company
                    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $stmt = $pdo->prepare("SELECT projectID, projectName FROM project WHERE companyID = ?");
                    $stmt->execute([$row['companyID']]);
                    $_SESSION['projects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Default project (first one or user preference)
                    $_SESSION['projectID'] = $_SESSION['projects'][0]['projectID'];


                    header('Location: index-admin.php');
                    exit;
                } else {
                    $login_error = true;
                }
            }else {
                $login_error = true;
            }
        } else {
            $login_error = true;
        }
    }
    mysqli_stmt_close($stmt);
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }


        .login-container {
            display: flex;
            width: 100%;
            max-width: 100%;
            background-color: var(--card-background);
            border-radius: 12px;
            overflow: hidden;
        }

        .login-form-container {
            flex: 1;
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-content {
            margin-bottom: 2rem;
        }

        .login-form-container h2 {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .login-form-container p {
            color: var(--secondary-text-color);
            font-size: 1rem;
            margin-bottom: 2rem;
        }

        .input-group {
            margin-bottom: 1.5rem;
        }

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
        }

        .remember-me input {
            width: auto;
            margin-right: 0.5rem;
            accent-color: var(--primary-color);
        }

        .forgot-password {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
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
            transition: background-color 0.2s ease-in-out;
        }

        .login-button:hover {
            background-color: #2354cf;
        }

        .divider {
            text-align: center;
            margin: 2rem 0;
            font-size: 0.875rem;
            color: var(--secondary-text-color);
            position: relative;
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            width: 40%;
            height: 1px;
            background-color: var(--border-color);
        }

        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 0;
            width: 40%;
            height: 1px;
            background-color: var(--border-color);
        }

        .social-login {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        .social-button {
            flex: 1;
            padding: 0.75rem;
            background-color: transparent;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }

        .social-button:hover {
            background-color: var(--background-color);
            border-color: var(--primary-color);
        }

        .social-button i {
            font-size: 1.5rem;
        }

        .signup-link {
            text-align: center;
            font-size: 0.875rem;
            color: var(--secondary-text-color);
            margin-top: auto;
        }

        .signup-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }

        .login-image-container {
            flex: 1;
            background-image: url('bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png');
            background-size: cover;
            background-position: center;
            border-radius: 0 12px 12px 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: flex-start;
            padding: 2.5rem;
            position: relative;
            color: white;
        }

        .login-image-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.1));
            z-index: 1;
            border-radius: 0 12px 12px 0;
        }

        .overlay-text {
            position: relative;
            z-index: 2;
        }

        .overlay-text h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .overlay-text p {
            font-size: 1rem;
            font-weight: 400;
        }

        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                width: 100%;
                max-width: 450px;
                border-radius: 12px;
            }
            .login-form-container {
                padding: 2rem 1.5rem;
            }
            .login-image-container {
                display: none;
            }
        }
    </style>

    <?php
    include_once 'include/global_css.php'
    ?>


</head>
<body>

<?php
include_once 'include/nav.php'
?>
<div class="blog_area section_padding">
    <div class="container">
        <div class="login-container">
            <div class="login-form-container">
                <div class="form-content">
                    <h2>Welcome Back</h2>
                    <p>Please enter your details to sign in.</p>

                    <form action="login.php" method="post">
                        <div class="input-group">

                            <label for="email">User Name </label>
                            <input type="text" name="userName" id="userName" placeholder="username" required>
                        </div>
                        <div class="input-group">
                            <label for="password">Password</label>
                            <input type="password" name="password" id="password" placeholder="••••••••" required>
                        </div>
                        <div class="form-options">
                            <div class="remember-me">
                                <input type="checkbox" id="remember">
                                <label for="remember">Remember me</label>
                            </div>
                            <a href="#" class="forgot-password">Forgot Password?</a>
                        </div>
                        <button type="submit" class="login-button">Sign In</button>
                    </form>


                </div>

                <div class="signup-link">
                    Don't have an account? <a href="#">Sign Up</a>
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
    <br> <br> <br> <br> <br> <br> <br> <br> <br>

<?php
include_once 'include/footer.php';
include_once 'include/global_js.php';
?>
</body>
</html>
