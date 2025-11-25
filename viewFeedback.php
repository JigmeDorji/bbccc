<?php
require_once "include/config.php";

$message = "";
$contacts = [];
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // DELETE FIRST (important)
    if (isset($_GET['delete'])) {
        $id = intval($_GET['delete']); // ensure it's an integer

        $stmt = $pdo->prepare("DELETE FROM contact WHERE id = :id LIMIT 1");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            $message = "Contact deleted successfully.";
            $reloadPage = true;
        } else {
            $message = "Failed to delete contact.";
        }
    }

    // Fetch updated contact list
    $stmt = $pdo->prepare("SELECT * FROM contact ORDER BY id DESC");
    $stmt->execute();
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>View Feedback</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
</head>

<body id="page-top">

<script>
document.addEventListener("DOMContentLoaded", function () {
    let msg = <?php echo json_encode($message); ?>;
    let reload = <?php echo $reloadPage ? 'true' : 'false'; ?>;

    if (msg !== "") {
        Swal.fire({
            icon: msg.includes("successfully") ? "success" : "error",
            title: msg,
            showConfirmButton: false,
            timer: 1500
        }).then(() => {
            if (reload) {
                window.location.href = "viewFeedback.php";
            }
        });
    }
});
</script>

<div id="wrapper">

    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">

            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

                <h1 class="h3 mb-2 text-gray-800">Contact View</h1>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Contact List</h6>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">

                            <table class="table table-bordered" width="100%" cellspacing="0">
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
                                        <td><?= $contact['id']; ?></td>
                                        <td><?= $contact['name']; ?></td>
                                        <td><?= $contact['email']; ?></td>
                                        <td><?= $contact['subject']; ?></td>
                                        <td><?= $contact['message']; ?></td>

                                        <td>
                                            <a href="#" 
                                                class="btn btn-danger btn-sm delete-feedback-btn" 
                                                data-id="<?= $contact['id']; ?>">
                                                    Delete
                                                </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

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
document.addEventListener("DOMContentLoaded", function () {
    const deleteButtons = document.querySelectorAll(".delete-feedback-btn");

    deleteButtons.forEach(btn => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();

            const feedbackId = this.getAttribute("data-id");

            Swal.fire({
                title: "Are you sure?",
                text: "This feedback entry will be permanently deleted.",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#d33",
                cancelButtonColor: "#3085d6",
                confirmButtonText: "Yes, delete it",
                cancelButtonText: "Cancel"
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = "viewFeedback.php?delete=" + feedbackId;
                }
            });
        });
    });
});
</script>

</body>
</html>
