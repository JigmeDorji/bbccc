<?php
require_once "include/config.php";

$message = "";
$reloadPage = false;

$accountHeads = [];
$subAccounts = [];

$existing_id = "";
$existing_subAccountName = "";
$existing_accountHeadID = "";

$companyID = $_SESSION['companyID'] ?? null;
$projectID = $_SESSION['projectID'] ?? null;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Load Account Heads for dropdown
    $stmt = $pdo->prepare("SELECT id, accountHeadName FROM account_head WHERE companyID = :companyID AND projectID = :projectID");
    $stmt->execute([':companyID' => $companyID, ':projectID' => $projectID]);
    $accountHeads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load existing sub accounts
    $stmt = $pdo->prepare("
        SELECT s.*, a.accountHeadName 
        FROM account_head_sub s
        JOIN account_head a ON s.accountHeadID = a.id
        WHERE s.companyID = :companyID AND s.projectID = :projectID
        ORDER BY s.id
    ");
    $stmt->execute([':companyID' => $companyID, ':projectID' => $projectID]);
    $subAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Database Error: " . $e->getMessage();
}

// Edit
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM account_head_sub WHERE id = :id AND companyID = :companyID AND projectID = :projectID");
    $stmt->execute([':id' => $id, ':companyID' => $companyID, ':projectID' => $projectID]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($data) {
        $existing_id = $data['id'];
        $existing_subAccountName = $data['subAccountName'];
        $existing_accountHeadID = $data['accountHeadID'];
    } else {
        $message = "Sub Account not found.";
    }
}

// Save
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $id = $_POST['id'] ?? null;
        $subAccountName = trim($_POST['subAccountName']);
        $accountHeadID = $_POST['accountHeadID'];

        if (!$subAccountName || !$accountHeadID || !$companyID || !$projectID) {
            throw new Exception("All fields are required.");
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE account_head_sub SET subAccountName = :name, accountHeadID = :headID WHERE id = :id AND companyID = :companyID AND projectID = :projectID");
            $stmt->execute([
                ':name' => $subAccountName,
                ':headID' => $accountHeadID,
                ':id' => $id,
                ':companyID' => $companyID,
                ':projectID' => $projectID
            ]);
            $message = "Sub Account updated successfully.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO account_head_sub (subAccountName, accountHeadID, companyID, projectID) VALUES (:name, :headID, :companyID, :projectID)");
            $stmt->execute([
                ':name' => $subAccountName,
                ':headID' => $accountHeadID,
                ':companyID' => $companyID,
                ':projectID' => $projectID
            ]);
            $message = "Sub Account created successfully.";
        }

        $reloadPage = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM account_head_sub WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $message = "Sub Account deleted successfully.";
        $reloadPage = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// SweetAlert
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
                if ($reloadPage) {
                    window.location.href = 'createSubAccHead.php';
                }
            });
        }
    });
</script>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sub Account Setup</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>
            <div class="container-fluid">
                <h1 class="h3 mb-2 text-gray-800">Sub Account Setup</h1>
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <form action="createSubAccHead.php<?= isset($_GET['edit']) ? '?edit=' . urlencode($_GET['edit']) : '' ?>" method="POST">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($existing_id) ?>">

                            <div class="form-row row">
                                <div class="form-group col-md-6">
                                    <label>Sub Account Name</label>
                                    <input type="text" name="subAccountName" class="form-control" value="<?= htmlspecialchars($existing_subAccountName) ?>" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Select Account Head</label>
                                    <select name="accountHeadID" class="form-control" required>
                                        <option value="">Select Account Head</option>
                                        <?php foreach ($accountHeads as $head): ?>
                                            <option value="<?= $head['id'] ?>" <?= ($head['id'] == $existing_accountHeadID) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($head['accountHeadName']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row mt-3">
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-primary">Submit</button>
                                    <a href="createSubAccHead.php" class="btn btn-secondary">Reset</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Sub Account List -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">Sub Account List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Sub Account Name</th>
                                    <th>Account Head</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($subAccounts as $sub): ?>
                                    <tr>
                                        <td><?= $sub['id'] ?></td>
                                        <td><?= htmlspecialchars($sub['subAccountName']) ?></td>
                                        <td><?= htmlspecialchars($sub['accountHeadName']) ?></td>
                                        <td>
                                            <a href="createSubAccHead.php?edit=<?= $sub['id'] ?>" class="btn btn-info btn-sm">Edit</a>
                                            <a href="createSubAccHead.php?delete=<?= $sub['id'] ?>" onclick="return confirm('Delete this sub account?')" class="btn btn-danger btn-sm">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($subAccounts)): ?>
                                    <tr><td colspan="4" class="text-center">No sub accounts found.</td></tr>
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
