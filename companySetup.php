<?php
require_once "include/config.php";

require_once "access_control.php";

// Only for system owner
allowRoles(['System_owner']);



$message = "";
$reloadPage = false;

$existing_companyID = "";
$existing_companyName = "";
$existing_address = "";
$existing_contactEmail = "";
$existing_contactPhone = "";
$existing_contactPerson = "";
$existing_gst = "";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT * FROM company");
    $stmt->execute();
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

// Edit
if (isset($_GET['edit'])) {
    try {
        $id = $_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM company WHERE companyID = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($company) {
            $existing_companyID = $company['companyID'];
            $existing_companyName = $company['companyName'];
            $existing_address = $company['address'];
            $existing_contactEmail = $company['contactEmail'];
            $existing_contactPhone = $company['contactPhone'];
            $existing_contactPerson = $company['contact_person'];
            $existing_gst = $company['gst'];
        } else {
            throw new Exception("Company not found.");
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Save
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $isEdit = isset($_GET['edit']);

        if ($isEdit) {
            $companyID = $_GET['edit']; // Use existing company ID
        } else {
            // Generate new company ID
            $stmt = $pdo->query("SELECT MAX(CAST(companyID AS UNSIGNED)) AS max_id FROM company");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $maxId = $row['max_id'] ?? 0;
            $companyID = strval($maxId + 1);
        }

        $companyName = $_POST['companyName'];
        $address = $_POST['address'];
        $contactEmail = $_POST['contactEmail'];
        $contactPhone = $_POST['contactPhone'];
        $contact_person = $_POST['contact_person'];
        $gst = $_POST['gst'];

        if ($isEdit) {
            $stmt = $pdo->prepare("UPDATE company SET companyName = :companyName, address = :address, contactEmail = :contactEmail, contactPhone = :contactPhone, contact_person = :contact_person, gst = :gst WHERE companyID = :companyID");
        } else {
            $stmt = $pdo->prepare("INSERT INTO company (companyID, companyName, address, contactEmail, contactPhone, contact_person, gst) VALUES (:companyID, :companyName, :address, :contactEmail, :contactPhone, :contact_person, :gst)");
        }

        $stmt->bindParam(':companyID', $companyID);
        $stmt->bindParam(':companyName', $companyName);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':contactEmail', $contactEmail);
        $stmt->bindParam(':contactPhone', $contactPhone);
        $stmt->bindParam(':contact_person', $contact_person);
        $stmt->bindParam(':gst', $gst);
        $stmt->execute();

        $message = "Company details " . ($isEdit ? "updated" : "submitted") . " successfully.";
        $reloadPage = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Delete
if (isset($_GET['delete'])) {
    try {
        $id = $_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM company WHERE companyID = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $message = "Company deleted successfully.";
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
                if ('$reloadPage') {
                    window.location.href = 'companySetup.php';
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
    <title>Company Setup</title>
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
                <h1 class="h3 mb-2 text-gray-800">Company Setup</h1>

                <!-- List -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Company List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Address</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Contact Person</th>
                                    <th>GST</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($companies as $row): ?>
                                    <tr>
                                        <td><?= $row['companyID'] ?></td>
                                        <td><?= $row['companyName'] ?></td>
                                        <td><?= $row['address'] ?></td>
                                        <td><?= $row['contactEmail'] ?></td>
                                        <td><?= $row['contactPhone'] ?></td>
                                        <td><?= $row['contact_person'] ?></td>
                                        <td><?= $row['gst'] ?></td>
                                        <td>
                                            <a href="companySetup.php?edit=<?= $row['companyID'] ?>" class="btn btn-info btn-sm">Edit</a>
                                            <a href="companySetup.php?delete=<?= $row['companyID'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this company?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <form action="companySetup.php<?= isset($_GET['edit']) ? '?edit=' . $_GET['edit'] : '' ?>" method="POST">
                            <div class="form-row row">
                                <input type="hidden" name="companyID" class="form-control" value="<?= $existing_companyID ?>" <?= isset($_GET['edit']) ? 'readonly' : '' ?> required>

                                <div class="form-group col-md-4">
                                    <label>Company Name:</label>
                                    <input type="text" name="companyName" class="form-control" value="<?= $existing_companyName ?>" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Address:</label>
                                    <input type="text" name="address" class="form-control" value="<?= $existing_address ?>" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Email:</label>
                                    <input type="email" name="contactEmail" class="form-control" value="<?= $existing_contactEmail ?>" required>
                                </div>
                            </div>

                            <div class="form-row row">

                                <div class="form-group col-md-4">
                                    <label>Contact No.:</label>
                                    <input type="text" name="contactPhone" class="form-control" value="<?= $existing_contactPhone ?>" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Contact Person:</label>
                                    <input type="text" name="contact_person" class="form-control" value="<?= $existing_contactPerson ?>" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>GST:</label>
                                    <input type="text" name="gst" class="form-control" value="<?= $existing_gst ?>" required>
                                </div>
                            </div>



                            <div class="form-row row mt-3">
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-primary">Submit</button>
                                    <a href="companySetup.php" class="btn btn-secondary">Reset</a>
                                </div>
                            </div>
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
