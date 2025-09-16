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
    <title>Restaurant Login</title>
    <link rel="stylesheet" href="https://unpkg.com/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/bs-brain@2.0.3/components/logins/login-5/assets/css/login-5.css">
    <link rel="stylesheet" href="assets/font/flaticon.css">
    <link rel="stylesheet" href="assets/css/plugins/owl.carousel.min.css">
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
<?php include_once 'include/nav.php' ?>
<br><br>
<main>
    <section class="p-3 p-md-4 p-xl-5">
        <div class="container">
            <div class="card border-light-subtle shadow-sm">
                <div class="row g-0">
                    <div class="col-12 col-md-6 text-bg-primary">
                        <div class="d-flex align-items-center justify-content-center h-100">
                            <div class="col-10 col-xl-8 py-3">
                                <h3 class="fw-bold text-warning m-0" style="font-family: Tahoma, Geneva, Verdana, sans-serif;">
                                    Welcome <span class="text-light">Back</span></h3>
<!--                                <img src="assets/images/logo/banner1.png" alt="Logo">-->
                                <hr class="border-primary-subtle mb-4">
                                <p class="lead m-0">Your trusted partner in digital transformation. We engineer smart, scalable, and secure technology solutions to power your business forward.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="card-body p-3 p-md-4 p-xl-5">
                            <h3 class="mb-5">Log in</h3>
                            <form action="login.php" method="post">
                                <div class="row gy-3 gy-md-4 overflow-hidden">
                                    <div class="col-12">
                                        <label for="userName" class="form-label">Username<span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="userName" id="userName" required value="<?= htmlspecialchars($userName ?? '') ?>">
                                    </div>
                                    <div class="col-12">
                                        <label for="password" class="form-label">Password<span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" name="password" id="password" required>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="remember_me" id="remember_me" value="1">
                                            <label class="form-check-label text-secondary" for="remember_me">
                                                Keep me logged in
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-grid">
                                            <button class="btn bsb-btn-xl btn-primary" type="submit">Log in now</button>
                                        </div>
                                    </div>

                                    <?php if (!empty($login_error)) : ?>
                                        <div class="col-12">
                                            <div class="alert alert-danger" role="alert">
                                                Username or password is wrong, please try again!
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include_once 'include/footer.php' ?>
</body>
</html>
