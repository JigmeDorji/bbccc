<?php
require_once "include/config.php";

$message = "";
$existing_menuName = "";
$existing_menuDetail = "";
$existing_menuImgUrl = "";
$existing_price = "";
$existing_eventStartDateTime = "";
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT * FROM menu");
    $stmt->execute();
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

// EDIT
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
            $existing_eventStartDateTime = $menu['eventStartDateTime'];
        }

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// INSERT / UPDATE
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {

        // INSERT OR UPDATE
        if (isset($_GET['edit'])) {
            $stmt = $pdo->prepare("UPDATE menu 
                SET menuName = :menuName, 
                    menuDetail = :menuDetail, 
                    menuImgUrl = :menuImgUrl, 
                    price = :price,
                    eventStartDateTime = :eventStartDateTime
                WHERE id = :id");
            $stmt->bindParam(":id", $_GET['edit']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO menu
                (menuName, menuDetail, menuImgUrl, price, eventStartDateTime)
                VALUES (:menuName, :menuDetail, :menuImgUrl, :price, :eventStartDateTime)");
        }

        $menuName = $_POST['menuName'];
        $menuDetail = $_POST['menuDetail'];
        $price = $_POST['price'];
        $eventStartDateTime = $_POST['eventStartDateTime'];

        // IMAGE HANDLING
        if (isset($_FILES['menu_image']) && $_FILES['menu_image']['error'] === 0) {
            $image_name = $_FILES['menu_image']['name'];
            $image_tmp = $_FILES['menu_image']['tmp_name'];
            $upload_path = "uploads/menu/" . $image_name;
            move_uploaded_file($image_tmp, $upload_path);
            $menuImgUrl = $upload_path;
        } else {
            $menuImgUrl = $existing_menuImgUrl;
        }

        $stmt->bindParam(":menuName", $menuName);
        $stmt->bindParam(":menuDetail", $menuDetail);
        $stmt->bindParam(":menuImgUrl", $menuImgUrl);
        $stmt->bindParam(":price", $price);
        $stmt->bindParam(":eventStartDateTime", $eventStartDateTime);

        $stmt->execute();

        $message = "Event saved successfully.";
        $reloadPage = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// DELETE
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM menu WHERE id = :id");
    $stmt->bindParam(":id", $_GET['delete']);
    $stmt->execute();
    $message = "Event deleted successfully.";
    $reloadPage = true;
}

// SweetAlert popup
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@10'></script>";
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    if ('$message' !== '') {
        Swal.fire({
            icon: 'success',
            title: '$message',
            showConfirmButton: false,
            timer: 1500
        }).then(() => {
            if ($reloadPage) window.location.href = 'serviceSetup.php';
        });
    }
});
</script>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<title>Event Setup</title>
<link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
<link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>

<body>
<div id="wrapper">

<?php include 'include/admin-nav.php'; ?>

<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid">

<h1 class="h3 mb-2 text-gray-800">Event Setup</h1>

<!-- TABLE -->
<div class="card shadow mb-4">
    <div class="card-header"><h6 class="m-0 font-weight-bold text-primary">Event List</h6></div>
    <div class="card-body">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Event Name</th>
                    <th>Detail</th>
                    <th>Image</th>
                    <th>Start Date & Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($menus as $menu): ?>
                <tr>
                    <td><?= $menu['id']; ?></td>
                    <td><?= $menu['menuName']; ?></td>
                    <td><?= $menu['menuDetail']; ?></td>
                    <td><img src="<?= $menu['menuImgUrl']; ?>" style="max-width:100px;"></td>
                    <td><?= $menu['eventStartDateTime']; ?></td>
                    <td>
                        <a href="serviceSetup.php?edit=<?= $menu['id']; ?>" class="btn btn-info btn-sm">Edit</a>
                        <a href="#" class="btn btn-danger btn-sm delete-btn" data-id="<?= $menu['id']; ?>">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- FORM -->
<div class="card shadow mb-4">
    <div class="card-header"><h6 class="m-0 font-weight-bold text-primary">Event Setup</h6></div>
    <div class="card-body">

<form method="POST" action="serviceSetup.php<?= isset($_GET['edit']) ? '?edit='.$_GET['edit'] : '' ?>" enctype="multipart/form-data">

    <div class="form-group">
        <label>Event Name</label>
        <input type="text" class="form-control" name="menuName" value="<?= $existing_menuName; ?>">
    </div>

    <div class="form-group">
        <label>Event Detail</label>
        <textarea class="form-control" name="menuDetail" rows="5"><?= $existing_menuDetail; ?></textarea>
    </div>

    <div class="form-group">
        <label>Current Image</label><br>
        <?php if ($existing_menuImgUrl): ?>
            <img src="<?= $existing_menuImgUrl; ?>" style="max-width:200px;">
        <?php else: ?>No image uploaded<?php endif; ?>
    </div>

    <div class="form-group">
        <label>Upload New Image</label>
        <input type="file" name="menu_image" class="form-control-file">
    </div>

    <div class="form-group">
        <label>Event Start Date & Time</label>
        <input type="datetime-local" class="form-control" name="eventStartDateTime"
               value="<?= $existing_eventStartDateTime; ?>">
    </div>

    <button type="submit" class="btn btn-primary">Submit Event</button>

</form>

    </div>
</div>

</div>
</div>

<?php include 'include/admin-footer.php'; ?>

</div>
</div>

<script>
document.querySelectorAll(".delete-btn").forEach(btn => {
    btn.addEventListener("click", function(e) {
        e.preventDefault();
        Swal.fire({
            title: "Delete this event?",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
        }).then(result => {
            if (result.isConfirmed) {
                window.location.href = "serviceSetup.php?delete=" + this.dataset.id;
            }
        });
    });
});
</script>

</body>
</html>
