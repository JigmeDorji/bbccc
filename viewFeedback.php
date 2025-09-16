<?php
require_once "include/config.php";
require_once "access_control.php";

// Only for system owner
allowRoles(['System_owner']);




$message = "";
$contacts = [];
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch existing data from the contact table
    $stmt = $pdo->prepare("SELECT * FROM contact");
    $stmt->execute();
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

// Delete Contact
if (isset($_GET['delete'])) {
    try {
        $id = $_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM contact WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $message = "Contact deleted successfully.";
        $reloadPage = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@10'></script>";
echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        if ('$message' !== '') {
            Swal.fire({
                icon: '" . ($message == 'Contact deleted successfully.' ? 'success' : 'error') . "',
                title: '$message',
                showConfirmButton: false,
                timer: 1500
            }).then((result) => {
                if ('$reloadPage') {
                    window.location.href = 'contactView.php';
                }
            });
        }
    });
</script>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Admin Rest - Contact View</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

</head>
<body id="page-top">
<!-- Page Wrapper -->
<div id="wrapper">
    <?php
    include_once 'include/admin-nav.php'
    ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php
            include_once 'include/admin-header.php'
            ?>

            <!-- Begin Page Content -->
            <div class="container-fluid">

                <!-- Page Heading -->
                <h1 class="h3 mb-2 text-gray-800">Contact View</h1>

                <!-- DataTales Example -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Contact List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Subject</th>
                                    <th>Message</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($contacts as $contact): ?>
                                    <tr>
                                        <td><?php echo $contact['id']; ?></td>
                                        <td><?php echo $contact['name']; ?></td>
                                        <td><?php echo $contact['email']; ?></td>
                                        <td><?php echo $contact['subject']; ?></td>
                                        <td><?php echo $contact['message']; ?></td>
                                        <td>
                                            <a href="contactView.php?delete=<?php echo $contact['id']; ?>"
                                               class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this contact?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
            <!-- /.container-fluid -->

        </div>
        <?php
        include_once 'include/admin-footer.php'
        ?>
    </div>
</div>
</body>
</html>
