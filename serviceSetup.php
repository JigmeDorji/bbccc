<?php
require_once "include/config.php";

//require_once "access_control.php";

// Only for system owner
//allowRoles(['Administrator']);



$message = "";
$existing_menuName = "";
$existing_menuDetail = "";
$existing_menuImgUrl = "";
$existing_price = "";
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch existing menu data
    $stmt = $pdo->prepare("SELECT * FROM menu");
    $stmt->execute();
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

// Edit Menu
if (isset($_GET['edit'])) {
    try {
        $id = $_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM menu WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $menu = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($menu) {
            $existing_menuName = $menu['menuName'];
            $existing_menuDetail = $menu['menuDetail'];
            $existing_menuImgUrl = $menu['menuImgUrl'];
            $existing_price = $menu['price'];
        } else {
            throw new Exception("Menu item not found.");
        }

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if editing an existing menu item
        if (isset($_GET['edit'])) {
            $stmt = $pdo->prepare("UPDATE menu SET menuName = :menuName, menuDetail = :menuDetail, menuImgUrl = :menuImgUrl, price = :price WHERE id = :id");
            $stmt->bindParam(':id', $_GET['edit']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO menu (menuName, menuDetail, menuImgUrl, price) VALUES (:menuName, :menuDetail, :menuImgUrl, :price)");
        }

        $menuName = $_POST['menuName'];
        $menuDetail = $_POST['menuDetail'];
        $price = $_POST['price'];

        // Check if a new image is uploaded
        if (isset($_FILES['menu_image']) && $_FILES['menu_image']['error'] === 0) {
            $image_name = $_FILES['menu_image']['name'];
            $image_size = $_FILES['menu_image']['size'];
            $image_tmp = $_FILES['menu_image']['tmp_name'];
            $image_type = $_FILES['menu_image']['type'];

            if ($image_size > 5242880) {
                throw new Exception("File is too large. Max file size is 5MB.");
            }

            $allowed_types = array('jpg', 'jpeg', 'png', 'gif');
            $file_extension = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));

            if (!in_array($file_extension, $allowed_types)) {
                throw new Exception("Invalid file format. Only JPG, JPEG, PNG, and GIF files are allowed.");
            }

            $upload_path = "uploads/menu/" . $image_name;
            $menuImgUrl = $upload_path;

            if (!move_uploaded_file($image_tmp, $upload_path)) {
                throw new Exception("Failed to upload image.");
            }
        } else {
            // If no new image is uploaded during editing, retain the previous image
            $menuImgUrl = $existing_menuImgUrl;
        }

        $stmt->bindParam(':menuName', $menuName);
        $stmt->bindParam(':menuDetail', $menuDetail);
        $stmt->bindParam(':menuImgUrl', $menuImgUrl);
        $stmt->bindParam(':price', $price);

        $stmt->execute();

        $message = "Menu item " . (isset($_GET['edit']) ? "updated" : "submitted") . " successfully.";
        $reloadPage = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Delete Menu Item
if (isset($_GET['delete'])) {
    try {
        $id = $_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM menu WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $message = "Menu item deleted successfully.";
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
                icon: '" . ($message == 'Menu item submitted successfully.' || $message == 'Menu item updated successfully.' || $message == 'Menu item deleted successfully.' ? 'success' : 'error') . "',
                title: '$message',
                showConfirmButton: false,
                timer: 1500
            }).then((result) => {
                if ('$reloadPage') {
                    window.location.href = 'menuSetup.php';
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

    <title>Our Services</title>

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
                <h1 class="h3 mb-2 text-gray-800">Event Setup</h1>

                <!-- DataTales Example -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Event List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Event Name</th>
                                    <th>Event Detail</th>
                                    <th>Image</th>
                                    <th>Price</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($menus as $menu): ?>
                                    <tr>
                                        <td><?php echo $menu['id']; ?></td>
                                        <td><?php echo $menu['menuName']; ?></td>
                                        <td><?php echo $menu['menuDetail']; ?></td>
                                        <td>
                                            <?php if ($menu['menuImgUrl']): ?>
                                                <img src="<?php echo $menu['menuImgUrl']; ?>" alt="Menu Image"
                                                     class="img-fluid" style="max-width: 100px;">
                                            <?php else: ?>
                                                <p>No image uploaded</p>
                                            <?php endif; ?>
                                        </td>
                                        <td>$<?php echo $menu['price']; ?></td>
                                        <td>
                                            <a href="menuSetup.php?edit=<?php echo $menu['id']; ?>"
                                               class="btn btn-info btn-sm">Edit</a>
                                            <a href="menuSetup.php?delete=<?php echo $menu['id']; ?>"
                                               class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this menu item?')">Delete</a>
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
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Event Setup</h6>
                    </div>
                    <div class="card-body">
                        <form action="menuSetup.php<?php echo isset($_GET['edit']) ? '?edit='.$_GET['edit'] : ''; ?>" method="POST" enctype="multipart/form-data">
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Event Name :</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="text" class="form-control" name="menuName"
                                           value="<?php echo $existing_menuName; ?>">
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Event Detail :</label>
                                </div>
                                <div class="col-md-9">
                                    <textarea class="form-control" name="menuDetail"
                                              rows="5"><?php echo $existing_menuDetail; ?></textarea>
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Image :</label>
                                </div>
                                <div class="col-md-9">
                                    <?php if ($existing_menuImgUrl): ?>
                                        <img src="<?php echo $existing_menuImgUrl; ?>" alt="Current Menu Image"
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
                                    <input type="file" class="form-control-file" name="menu_image">
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-md-3">
                                    <label class="col-form-label">Price :</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="text" class="form-control" name="price"
                                           value="<?php echo $existing_price; ?>">
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-md-3">
                                </div>
                                <div class="col-md-9">
                                    <button type="submit" class="btn btn-primary">Submit Event</button>
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
