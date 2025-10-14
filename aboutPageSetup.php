<?php
require_once "include/config.php";
require_once "access_control.php";

// Only for system owner
// allowRoles(['System_owner']);



$message = "";
$existing_description = "";
$existing_imgUrl = "";

try {
    // Create a new PDO instance
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);

    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch existing data
    $stmt = $pdo->prepare("SELECT * FROM about ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $existing_description = $row['description'];
        $existing_imgUrl = $row['imgUrl'];
    }

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Create a new PDO instance
        $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);

        // Set the PDO error mode to exception
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Prepare an INSERT statement
        $stmt = $pdo->prepare("INSERT INTO about (description, imgUrl) VALUES (:description, :imgUrl)");

        // Bind parameters
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':imgUrl', $imgUrl);

        // Get form data
        $description = $_POST['description'];

        // Check if an image is uploaded
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $image_name = $_FILES['image']['name'];
            $image_size = $_FILES['image']['size'];
            $image_tmp = $_FILES['image']['tmp_name'];
            $image_type = $_FILES['image']['type'];

            // Check file size (5MB max)
            if ($image_size > 5242880) {
                throw new Exception("File is too large. Max file size is 5MB.");
            }

            // Allow certain file formats
            $allowed_types = array('jpg', 'jpeg', 'png', 'gif');
            $file_extension = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));

            if (!in_array($file_extension, $allowed_types)) {
                throw new Exception("Invalid file format. Only JPG, JPEG, PNG, and GIF files are allowed.");
            }

            // Upload file to server
            $upload_path = "uploads/" . $image_name;
            $imgUrl = $upload_path;

            if (!move_uploaded_file($image_tmp, $upload_path)) {
                throw new Exception("Failed to upload image.");
            }
        } else {
            // Use the existing image if no new image is selected
            $imgUrl = $existing_imgUrl;
        }

        // Execute the INSERT statement
        $stmt->execute();

        // Fetch the updated data
        $stmt = $pdo->prepare("SELECT * FROM about ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $existing_description = $row['description'];
            $existing_imgUrl = $row['imgUrl'];
        }

        $message = "Form submitted successfully.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@10'></script>";
echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        if ('$message' !== '') {
            Swal.fire({
                icon: '" . ($message == 'Form submitted successfully.' ? 'success' : 'error') . "',
                title: '$message',
                showConfirmButton: false,
                timer: 1500
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

    <title>Admin Rest</title>

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
                <h1 class="h3 mb-2 text-gray-800">Setup About Page</h1>
                <!-- DataTales Example -->

                <div class="card shadow mb-4">
                    <div class="card-body">
                        <form action="aboutPageSetup.php" method="POST" enctype="multipart/form-data">
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Description :</label>
                                </div>
                                <div class="col-md-9">
                                    <textarea class="form-control" name="description"
                                              rows="15"><?php echo $existing_description; ?></textarea>
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Current Image :</label>
                                </div>
                                <div class="col-md-9">
                                    <?php if ($existing_imgUrl): ?>
                                        <img src="<?php echo $existing_imgUrl; ?>" alt="Current Image" class="img-fluid"
                                             style="max-width: 200px;">
                                    <?php else: ?>
                                        <p>No image uploaded</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Upload New Image :</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="file" class="form-control-file" name="image">
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-md-3">
                                </div>
                                <div class="col-md-9">
                                    <button type="submit" class="btn btn-primary">Submit</button>
                                </div>
                            </div>
                        </form>
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
