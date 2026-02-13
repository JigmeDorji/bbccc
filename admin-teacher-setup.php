<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/role_helpers.php";
require_login();
allowRoles(['Administrator', 'Admin', 'Company Admin', 'System_owner', 'Staff']);

$message = "";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullName === '' || $username === '' || $password === '') {
            throw new Exception("Full name, username and password are required.");
        }

        $stmt = $pdo->prepare("SELECT userid FROM user WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            throw new Exception("Username already exists.");
        }

        $stmt = $pdo->query("SELECT MAX(CAST(userid AS UNSIGNED)) AS max_id FROM user");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextId = (int)($row['max_id'] ?? 0) + 1;
        $userId = (string)$nextId;

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO user (userid, username, password, role, createdDate)
             VALUES (:userid, :username, :password, :role, :createdDate)"
        );
        $stmt->execute([
            ':userid' => $userId,
            ':username' => $username,
            ':password' => $passwordHash,
            ':role' => 'teacher',
            ':createdDate' => date('Y-m-d H:i:s')
        ]);

        $stmt = $pdo->prepare(
            "INSERT INTO teachers (user_id, full_name, email, phone)
             VALUES (:user_id, :full_name, :email, :phone)"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':full_name' => $fullName,
            ':email' => $email === '' ? null : $email,
            ':phone' => $phone === '' ? null : $phone
        ]);

        $pdo->commit();

        $message = "Teacher created successfully.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Error: " . $e->getMessage();
    }
}

$teachers = $pdo->query(
    "SELECT t.*, u.username
     FROM teachers t
     LEFT JOIN user u ON u.userid COLLATE utf8mb4_0900_ai_ci = t.user_id COLLATE utf8mb4_0900_ai_ci
     ORDER BY t.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Teacher Setup</title>
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
                <h1 class="h3 mb-4 text-gray-800">Teacher Setup</h1>

                <?php if ($message): ?>
                    <div class="alert <?php echo (stripos($message, 'Error') === 0) ? 'alert-danger' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Create Teacher</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" class="form-control" name="full_name" required>
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>

                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" class="form-control" name="phone">
                            </div>

                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" class="form-control" name="username" required>
                            </div>

                            <div class="form-group">
                                <label>Temp Password</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>

                            <button type="submit" class="btn btn-primary">Create Teacher</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Teachers</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Active</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($teachers as $teacher): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($teacher['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($teacher['username'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($teacher['email'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($teacher['phone'] ?? ''); ?></td>
                                        <td><?php echo $teacher['active'] ? 'Yes' : 'No'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($teachers)): ?>
                                    <tr><td colspan="5" class="text-center">No teachers found.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
