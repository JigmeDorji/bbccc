<?php
require_once "include/config.php";
require_once "access_control.php";

// Only for system owner
allowRoles(['System_owner']);



$message = "";
$existing_title = "";
$existing_subtitle = "";
$existing_imgUrl = "";
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch existing data
    $stmt = $pdo->prepare("SELECT * FROM banner");
    $stmt->execute();
    $banners = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

// Edit Banner
if (isset($_GET['edit'])) {
    try {
        $id = $_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM banner WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $banner = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($banner) {
            $existing_title = $banner['title'];
            $existing_subtitle = $banner['subtitle'];
            $existing_imgUrl = $banner['imgUrl'];
        } else {
            throw new Exception("Banner not found.");
        }

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if editing an existing banner
        if (isset($_GET['edit'])) {
            $stmt = $pdo->prepare("UPDATE banner SET title = :title, subtitle = :subtitle, imgUrl = :imgUrl WHERE id = :id");
            $stmt->bindParam(':id', $_GET['edit']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO banner (title, subtitle, imgUrl) VALUES (:title, :subtitle, :imgUrl)");
        }

        $title = $_POST['title'];
        $subtitle = $_POST['subtitle'];

        // Check if a new image is uploaded
        if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] === 0) {
            $image_name = $_FILES['banner_image']['name'];
            $image_size = $_FILES['banner_image']['size'];
            $image_tmp = $_FILES['banner_image']['tmp_name'];
            $image_type = $_FILES['banner_image']['type'];

            if ($image_size > 5242880) {
                throw new Exception("File is too large. Max file size is 5MB.");
            }

            $allowed_types = array('jpg', 'jpeg', 'png', 'gif');
            $file_extension = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));

            if (!in_array($file_extension, $allowed_types)) {
                throw new Exception("Invalid file format. Only JPG, JPEG, PNG, and GIF files are allowed.");
            }

            $upload_path = "uploads/banner/" . $image_name;
            $imgUrl = $upload_path;

            if (!move_uploaded_file($image_tmp, $upload_path)) {
                throw new Exception("Failed to upload image.");
            }
        } else {
            // If no new image is uploaded during editing, retain the previous image
            $imgUrl = $existing_imgUrl;
        }

        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':subtitle', $subtitle);
        $stmt->bindParam(':imgUrl', $imgUrl);

        $stmt->execute();

        $message = "Banner details " . (isset($_GET['edit']) ? "updated" : "submitted") . " successfully.";
        $reloadPage = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Delete Banner
if (isset($_GET['delete'])) {
    try {
        $id = $_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM banner WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $message = "Banner deleted successfully.";
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
                icon: '" . ($message == 'Banner details submitted successfully.' || $message == 'Banner details updated successfully.' || $message == 'Banner deleted successfully.' ? 'success' : 'error') . "',
                title: '$message',
                showConfirmButton: false,
                timer: 1500
            }).then((result) => {
                if ('$reloadPage') {
                    window.location.href = 'bannerSetup.php';
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

    <title>Admin Rest - Banner Setup</title>

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
                <h1 class="h3 mb-2 text-gray-800">Banner Setup</h1>

                <!-- DataTales Example -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Banner List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Subtitle</th>
                                    <th>Image</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($banners as $banner): ?>
                                    <tr>
                                        <td><?php echo $banner['id']; ?></td>
                                        <td><?php echo $banner['title']; ?></td>
                                        <td><?php echo $banner['subtitle']; ?></td>
                                        <td>
                                            <?php if ($banner['imgUrl']): ?>
                                                <img src="<?php echo $banner['imgUrl']; ?>" alt="Banner Image"
                                                     class="img-fluid" style="max-width: 100px;">
                                            <?php else: ?>
                                                <p>No image uploaded</p>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="bannerSetup.php?edit=<?php echo $banner['id']; ?>"
                                               class="btn btn-info btn-sm">Edit</a>
                                            <a href="bannerSetup.php?delete=<?php echo $banner['id']; ?>"
                                               class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this banner?')">Delete</a>
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
                        <form action="bannerSetup.php<?php echo isset($_GET['edit']) ? '?edit='.$_GET['edit'] : ''; ?>" method="POST" enctype="multipart/form-data">
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Title :</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="text" class="form-control" name="title"
                                           value="<?php echo $existing_title; ?>">
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Subtitle :</label>
                                </div>
                                <div class="col-md-9">
                                    <textarea class="form-control" name="subtitle"
                                              rows="5"><?php echo $existing_subtitle; ?></textarea>
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Current Image :</label>
                                </div>
                                <div class="col-md-9">
                                    <?php if ($existing_imgUrl): ?>
                                        <img src="<?php echo $existing_imgUrl; ?>" alt="Current Banner Image"
                                             class="img-fluid" style="max-width: 200px;">
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
                                    <input type="file" class="form-control-file" name="banner_image">
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
