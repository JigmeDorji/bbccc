<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/csrf.php";
require_once "include/patron_schema.php";
require_once "access_control.php";
require_login();
allowRoles(['patron']);

$flash = '';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
    bbcc_ensure_patrons_table($pdo);
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

$sessionUserId = (string)($_SESSION['userid'] ?? '');
$sessionEmail = strtolower(trim((string)($_SESSION['username'] ?? '')));

if ($sessionUserId === '' || $sessionEmail === '') {
    bbcc_fail('Session expired. Please login again.');
}

$stmtPatron = $pdo->prepare("SELECT * FROM patrons WHERE LOWER(email) = LOWER(:email) LIMIT 1");
$stmtPatron->execute([':email' => $sessionEmail]);
$patron = $stmtPatron->fetch(PDO::FETCH_ASSOC);

if (!$patron) {
    bbcc_fail('Patron record not found. Please contact admin.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upgrade_parent') {
    try {
        verify_csrf();
        $pdo->beginTransaction();

        $stmtUser = $pdo->prepare("SELECT userid, username, password FROM `user` WHERE userid = :userid LIMIT 1");
        $stmtUser->execute([':userid' => $sessionUserId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new Exception('User account not found.');
        }

        $stmtExistingParent = $pdo->prepare("SELECT id FROM parents WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $stmtExistingParent->execute([':email' => $sessionEmail]);
        $existingParentId = (int)($stmtExistingParent->fetchColumn() ?: 0);

        if ($existingParentId > 0) {
            $parentId = $existingParentId;
        } else {
            $fullName = trim((string)($patron['full_name'] ?? ''));
            if ($fullName === '') {
                $fullName = 'Patron User';
            }
            $phone = trim((string)($patron['phone'] ?? ''));
            $address = trim((string)($patron['address'] ?? ''));

            $insertParent = $pdo->prepare("
                INSERT INTO parents (full_name, gender, email, phone, address, username, password, status)
                VALUES (:full_name, :gender, :email, :phone, :address, :username, :password, :status)
            ");
            $insertParent->execute([
                ':full_name' => $fullName,
                ':gender'    => 'Other',
                ':email'     => $sessionEmail,
                ':phone'     => $phone,
                ':address'   => $address,
                ':username'  => $sessionEmail,
                ':password'  => (string)$user['password'],
                ':status'    => 'Active'
            ]);
            $parentId = (int)$pdo->lastInsertId();
        }

        $updateUser = $pdo->prepare("UPDATE `user` SET role = 'parent' WHERE userid = :userid LIMIT 1");
        $updateUser->execute([':userid' => $sessionUserId]);

        $updatePatron = $pdo->prepare("
            UPDATE patrons
            SET parent_id = :parent_id, status = 'Active', updated_at = NOW()
            WHERE id = :patron_id
            LIMIT 1
        ");
        $updatePatron->execute([
            ':parent_id' => $parentId,
            ':patron_id' => (int)$patron['id']
        ]);

        $pdo->commit();

        $_SESSION['role'] = 'parent';
        header("Location: parent-dashboard");
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flash = "Upgrade failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Patron Dashboard</title>
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
                <h1 class="h3 mb-4 text-gray-800">Welcome, <?php echo htmlspecialchars($patron['full_name'] ?: 'Patron'); ?></h1>

                <?php if ($flash !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($flash); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-7 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Your Patron Account</h6>
                            </div>
                            <div class="card-body">
                                <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($patron['email'] ?? ''); ?></p>
                                <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($patron['phone'] ?? ''); ?></p>
                                <p class="mb-1"><strong>Address:</strong> <?php echo htmlspecialchars($patron['address'] ?? ''); ?></p>
                                <p class="mb-0"><strong>Status:</strong> <?php echo htmlspecialchars($patron['status'] ?? ''); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Upgrade to Parent Portal</h6>
                            </div>
                            <div class="card-body">
                                <p class="mb-3">If you want to enrol children for BLCS, upgrade this account to Parent Portal.</p>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="upgrade_parent">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-arrow-up mr-1"></i> Upgrade Now
                                    </button>
                                </form>
                            </div>
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
