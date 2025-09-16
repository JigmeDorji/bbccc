<?php
require_once "include/config.php";

$message = "";
$reloadPage = false;

$accountHeads = [];
$companies = [];
$types = [];

$existing_id = "";
$existing_accountHeadName = "";
$existing_accountHeadTypeID = "";
$existing_companyID = "";
$existing_projectID = "";
// Fetch current companyID and projectID from session
// Fetch current companyID and projectID from session
$companyID = $_SESSION['companyID'] ?? null;
$projectID = $_SESSION['projectID'] ?? null;



try {
    // PDO connection assumed in config.php as $pdo
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);




    // Load companies for dropdown
    $stmt = $pdo->prepare("SELECT companyID, companyName FROM company ORDER BY companyName");
    $stmt->execute();
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load account head types for dropdown
    $stmt = $pdo->prepare("SELECT id, typeName FROM account_head_type ORDER BY typeName");
    $stmt->execute();
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load account heads for listing with joins to company and type names
    $stmt = $stmt = $pdo->prepare("
    SELECT ah.*, c.companyName, t.typeName 
    FROM account_head ah
    INNER JOIN company c ON ah.companyID = c.companyID
    INNER JOIN account_head_type t ON ah.accountHeadTypeID = t.id
    WHERE ah.companyID = :companyID AND ah.projectID = :projectID
    ORDER BY ah.id
");
    $stmt->execute([
        ':companyID' => $companyID,
        ':projectID' => $projectID
    ]);

    $accountHeads = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Database Error: " . $e->getMessage();
}

// Edit: Load existing account head data into form
if (isset($_GET['edit'])) {
    try {
        $id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM account_head WHERE id = :id AND companyID = :companyID AND projectID = :projectID");
        $stmt->bindParam(':companyID', $companyID);
        $stmt->bindParam(':projectID', $projectID);

        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data) {
            $existing_id = $data['id'];
            $existing_accountHeadName = $data['accountHeadName'];
            $existing_accountHeadTypeID = $data['accountHeadTypeID'];
            $existing_companyID = $data['companyID'];
            $existing_projectID = $data['projectID'];
        } else {
            throw new Exception("Account Head not found.");
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Save (Insert or Update)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $id = $_POST['id'] ?? null;
        $name = trim($_POST['accountHeadName']);
        $typeID = $_POST['accountHeadTypeID'];
        $companyID = $companyID;
        $projectID = $projectID;

        if (empty($name) || empty($typeID) || empty($companyID) || empty($projectID)) {
            throw new Exception("Please fill in all required fields.");
        }

        if ($id) {
            // Update existing account head
            $stmt = $pdo->prepare("UPDATE account_head SET accountHeadName = :name, accountHeadTypeID = :typeID, companyID = :companyID, projectID = :projectID WHERE id = :id");
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':typeID', $typeID, PDO::PARAM_INT);
            $stmt->bindParam(':companyID', $companyID);
            $stmt->bindParam(':projectID', $projectID);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $message = "Account Head updated successfully.";
        } else {
            // Insert new account head
            $stmt = $pdo->prepare("INSERT INTO account_head (accountHeadName, accountHeadTypeID, companyID, projectID) VALUES (:name, :typeID, :companyID, :projectID)");
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':typeID', $typeID, PDO::PARAM_INT);
            $stmt->bindParam(':companyID', $companyID);
            $stmt->bindParam(':projectID', $projectID);
            $stmt->execute();
            $message = "Account Head created successfully.";
        }

        $reloadPage = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Delete
if (isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM account_head WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $message = "Account Head deleted successfully.";
        $reloadPage = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// SweetAlert message
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
                    window.location.href = 'createAccHead.php';
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
    <title>Account Head Setup</title>
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
                <h1 class="h3 mb-2 text-gray-800">Account Head Setup</h1>
                <!-- Account Head Form -->
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <form action="createAccHead.php<?= isset($_GET['edit']) ? '?edit=' . urlencode($_GET['edit']) : '' ?>" method="POST">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($existing_id) ?>" />

                            <div class="form-row row">
                                <div class="form-group col-md-6">
                                    <label>Account Head Name</label>
                                    <input type="text" name="accountHeadName" class="form-control" value="<?= htmlspecialchars($existing_accountHeadName) ?>" required />
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Account Head Type</label>
                                    <select name="accountHeadTypeID" class="form-control" required>
                                        <option value="">Select Type</option>
                                        <?php foreach ($types as $type): ?>
                                            <option value="<?= htmlspecialchars($type['id']) ?>" <?= ($type['id'] == $existing_accountHeadTypeID) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($type['typeName']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>



                            <div class="form-row mt-3">
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-primary">Submit</button>
                                    <a href="createAccHead.php" class="btn btn-secondary">Reset</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Account Head List -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Account Head List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Type</th>
<!--                                    <th>Company</th>-->
<!--                                    <th>Project ID</th>-->
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($accountHeads)): ?>
                                    <?php foreach ($accountHeads as $head): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($head['id']) ?></td>
                                            <td><?= htmlspecialchars($head['accountHeadName']) ?></td>
                                            <td><?= htmlspecialchars($head['typeName']) ?></td>
<!--                                            <td>--><?php //= htmlspecialchars($head['companyName']) ?><!--</td>-->
<!--                                            <td>--><?php //= htmlspecialchars($head['projectID']) ?><!--</td>-->
                                            <td>
                                                <a href="createAccHead.php?edit=<?= urlencode($head['id']) ?>" class="btn btn-info btn-sm">Edit</a>
                                                <a href="createAccHead.php?delete=<?= urlencode($head['id']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this account head?')">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center">No account heads found.</td></tr>
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
