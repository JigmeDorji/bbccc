<?php
require_once "include/config.php";

require_once "access_control.php";

// Only for system owner
allowRoles(['System_owner']);



$message = "";
$reloadPage = false;

// Initialize variables
$users = [];
$companies = [];

$existing_userid = "";
$existing_username = "";
$existing_password = "";
$existing_companyID = "";
$existing_projectID = "";
$existing_role = "";
$existing_createdDate = "";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // AJAX handler to fetch projects for a company
    if (isset($_GET['getProjects']) && !empty($_GET['companyID'])) {
        $companyID = $_GET['companyID'];
        $stmt = $pdo->prepare("SELECT projectID, projectName FROM project WHERE companyID = :companyID ORDER BY projectName");
        $stmt->bindParam(':companyID', $companyID);
        $stmt->execute();
        $projectsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json');
        echo json_encode($projectsList);
        exit;
    }

    // Load companies for dropdown
    if ($_SESSION['role'] === 'System_owner') {
        // System owner can see all
        $stmt = $pdo->prepare("SELECT companyID, companyName FROM company ORDER BY companyName");
    } else {
        // Only their own company
        $stmt = $pdo->prepare("SELECT companyID, companyName FROM company WHERE companyID = :companyID");
        $stmt->bindParam(':companyID', $_SESSION['companyID']);

    }
    $stmt->execute();
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // Load users with company and project names
    $stmt = $pdo->prepare("
        SELECT u.*, c.companyName, p.projectName
        FROM user u
        INNER JOIN company c ON u.companyID COLLATE utf8mb4_unicode_ci = c.companyID COLLATE utf8mb4_unicode_ci
        LEFT JOIN project p ON u.projectID COLLATE utf8mb4_unicode_ci = p.projectID COLLATE utf8mb4_unicode_ci
        ORDER BY u.userid");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Database Error: " . $e->getMessage();
}

// Edit user
if (isset($_GET['edit'])) {
    try {
        $id = $_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM user WHERE userid = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $existing_userid = $user['userid'];
            $existing_username = $user['username'];
            $existing_password = "";
            $existing_companyID = $user['companyID'];
            $existing_projectID = $user['projectID'];
            $existing_role = $user['role'];
            $existing_createdDate = $user['createdDate'];
        } else {
            throw new Exception("User not found.");
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Save user (Insert or Update)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        if (isset($_GET['edit'])) {
            // For edit, keep posted userid
            $userid = trim($_POST['userid']);
        } else {
            // For new user, generate next userID by max+1
            $stmt = $pdo->query("SELECT MAX(CAST(userid AS UNSIGNED)) AS max_id FROM user");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $maxId = $row['max_id'] ?? 0;
            $userid = strval($maxId + 1);
        }
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $companyID = $_POST['companyID'];
        $projectID = trim($_POST['projectID']);
        $role = $_POST['role'];
        $createdDate = date("Y-m-d H:i:s");

        // Password hash
        if (!empty($password)) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        } elseif (isset($_GET['edit'])) {
            $stmt = $pdo->prepare("SELECT password FROM user WHERE userid = :userid");
            $stmt->bindParam(':userid', $userid);
            $stmt->execute();
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existingUser) {
                $passwordHash = $existingUser['password'];
            } else {
                throw new Exception("User not found for password update.");
            }
        } else {
            throw new Exception("Password is required for new user.");
        }

        if (isset($_GET['edit'])) {
            // Update user
            $stmt = $pdo->prepare("UPDATE user SET username = :username, password = :password, companyID = :companyID, projectID = :projectID, role = :role WHERE userid = :userid");
            $stmt->bindParam(':userid', $userid);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $passwordHash);
            $stmt->bindParam(':companyID', $companyID);
            $stmt->bindParam(':projectID', $projectID);
            $stmt->bindParam(':role', $role);
            $stmt->execute();
        } else {
            // Insert user
            $stmt = $pdo->prepare("INSERT INTO user (userid, username, password, companyID, projectID, role, createdDate) VALUES (:userid, :username, :password, :companyID, :projectID, :role, :createdDate)");
            $stmt->bindParam(':userid', $userid);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $passwordHash);
            $stmt->bindParam(':companyID', $companyID);
            $stmt->bindParam(':projectID', $projectID);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':createdDate', $createdDate);
            $stmt->execute();
        }

        $message = "User details " . (isset($_GET['edit']) ? "updated" : "submitted") . " successfully.";
        $reloadPage = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Delete user
if (isset($_GET['delete'])) {
    try {
        $id = $_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM user WHERE userid = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $message = "User deleted successfully.";
        $reloadPage = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// SweetAlert for messages
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@10'></script>";
echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        if ('$message' !== '') {
            Swal.fire({
                icon: '" . (str_contains($message, 'successfully') ? 'success' : 'error') . "',
                title: '$message',
                showConfirmButton: false,
                timer: 1500
            }).then(() => {
                if ('$reloadPage') {
                    window.location.href = 'userSetup.php';
                }
            });
        }
    });
</script>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>User Setup</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" />
    <link href="css/sb-admin-2.min.css" rel="stylesheet" />
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>
            <div class="container-fluid">
                <h1 class="h3 mb-2 text-gray-800">User Setup</h1>
                <!-- User Form -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Create or Update user detail</h6>
                    </div>
                    <div class="card-body">
                        <form action="userSetup.php<?= isset($_GET['edit']) ? '?edit=' . urlencode($_GET['edit']) : '' ?>" method="POST" onsubmit="return validatePasswords();">
                            <div class="form-row row">
                                <input type="hidden" name="userid" class="form-control" value="<?= htmlspecialchars($existing_userid) ?>" <?= isset($_GET['edit']) ? 'readonly' : '' ?> required />

                                <div class="form-group col-md-4">
                                    <label>Username:</label>
                                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($existing_username) ?>" required />
                                </div>

                                <div class="form-group col-md-4">
                                    <label>Password: <?= isset($_GET['edit']) ? '(Leave blank to keep current password)' : '' ?></label>
                                    <div class="input-group">
                                        <input type="password" name="password" id="password" class="form-control" <?= isset($_GET['edit']) ? '' : 'required' ?>>
                                        <div class="input-group-append">
                                            <span class="input-group-text" onclick="togglePassword('password', this)">
                                                <i class="fa fa-eye-slash" aria-hidden="true"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group col-md-4">
                                    <label>Confirm Password:</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" <?= isset($_GET['edit']) ? '' : 'required' ?>>
                                        <div class="input-group-append">
                                            <span class="input-group-text" onclick="togglePassword('confirm_password', this)">
                                                <i class="fa fa-eye-slash" aria-hidden="true"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row row">
                                <div class="form-group col-md-4">
                                    <label>Company:</label>
                                    <select name="companyID" id="companySelect" class="form-control" required>
                                        <option value="">Select Company</option>
                                        <?php foreach ($companies as $company): ?>
                                            <option value="<?= htmlspecialchars($company['companyID']) ?>" <?= ($company['companyID'] == $existing_companyID) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($company['companyName']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group col-md-4">
                                    <label>Project:</label>
                                    <select name="projectID" id="projectSelect" class="form-control" required>
                                        <option value="">Select Project</option>
                                        <!-- Loaded dynamically -->
                                    </select>
                                </div>

                                <div class="form-group col-md-4">
                                    <label>Role:</label>
                                    <select name="role" class="form-control" required>
                                        <option value="">Select Role</option>
                                        <?php
                                        $roles = ['Company Admin', 'Manager', 'Staff'];
                                        foreach ($roles as $roleOption): ?>
                                            <option value="<?= htmlspecialchars($roleOption) ?>" <?= ($roleOption == $existing_role) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($roleOption) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row row mt-3">
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-primary">Submit</button>
                                    <a href="userSetup.php" class="btn btn-secondary">Reset</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- User List -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">User List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>User ID</th>
                                    <th>Username</th>
                                    <th>Company</th>
                                    <th>Project</th>
                                    <th>Role</th>
                                    <th>Created Date</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($users)): ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($user['userid']) ?></td>
                                            <td><?= htmlspecialchars($user['username']) ?></td>
                                            <td><?= htmlspecialchars($user['companyName']) ?></td>
                                            <td><?= htmlspecialchars($user['projectName'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($user['role']) ?></td>
                                            <td><?= htmlspecialchars($user['createdDate']) ?></td>
                                            <td>
                                                <a href="userSetup.php?edit=<?= urlencode($user['userid']) ?>" class="btn btn-info btn-sm">Edit</a>
                                                <a href="userSetup.php?delete=<?= urlencode($user['userid']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this user?')">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center">No users found.</td></tr>
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

<script>

    function togglePassword(fieldId, iconSpan) {
     const input = document.getElementById(fieldId);
     const icon = iconSpan.querySelector('i');
     if (input.type === "password") {
         input.type = "text";
         icon.classList.remove("fa-eye-slash");
         icon.classList.add("fa-eye");
     } else {
         input.type = "password";
         icon.classList.remove("fa-eye");
         icon.classList.add("fa-eye-slash");
     }
 }

     function validatePasswords() {
         const pwd = document.getElementById("password").value;
         const confirmPwd = document.getElementById("confirm_password").value;

         // Skip check if in edit mode and password is blank
         const isEdit = window.location.search.includes("edit");
         if (isEdit && pwd === "" && confirmPwd === "") return true;

         if (pwd !== confirmPwd) {
             alert("Password and Confirm Password do not match.");
             return false;
         }
         return true;
     }
     document.addEventListener('DOMContentLoaded', function() {
         const companySelect = document.getElementById('companySelect');
         const projectSelect = document.getElementById('projectSelect');

         function loadProjects(companyID, selectedProjectID = '') {
             projectSelect.innerHTML = '<option value="">Loading...</option>';
             if (!companyID) {
                 projectSelect.innerHTML = '<option value="">Select Project</option>';
                 return;
             }

             fetch(`userSetup.php?getProjects=1&companyID=${encodeURIComponent(companyID)}`)
                 .then(response => response.json())
                 .then(data => {
                     let options = '<option value="">Select Project</option>';
                     data.forEach(project => {
                         const selected = project.projectID === selectedProjectID ? 'selected' : '';
                         options += `<option value="${project.projectID}" ${selected}>${project.projectName}</option>`;
                     });
                     projectSelect.innerHTML = options;
                 })
                 .catch(() => {
                     projectSelect.innerHTML = '<option value="">Error loading projects</option>';
                 });
         }

         // On page load, if company is selected (edit mode), load projects
         const initialCompanyID = companySelect.value;
         const initialProjectID = "<?= addslashes($existing_projectID) ?>";
    if (initialCompanyID) {
        loadProjects(initialCompanyID, initialProjectID);
    }

    // Load projects when company changes
    companySelect.addEventListener('change', function() {
        loadProjects(this.value);
    });
});
</script>

</body>
</html>
