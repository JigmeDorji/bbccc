<?php
require_once "include/config.php";
require_once "access_control.php";

// Only for system owner
allowRoles(['System_owner']);


$message = "";
$reloadPage = false;

$projects = [];
$companies = [];
$existing_projectID = "";
$existing_projectName = "";
$existing_projectAddress = "";
$existing_deadline = "";
$existing_remarks = "";
$existing_companyID = "";

// Connect
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Load companies
    $stmt = $pdo->query("SELECT companyID, companyName FROM company ORDER BY companyName");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $companies[$c['companyID']] = $c['companyName'];
    }

    // Handle delete
    if (isset($_GET['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM project WHERE projectID = ?");
        $stmt->execute([$_GET['delete']]);
        $message = "Project deleted successfully.";
        $reloadPage = true;
    }

    // Edit mode
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare("SELECT * FROM project WHERE projectID = ?");
        $stmt->execute([$_GET['edit']]);
        if ($proj = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing_projectID      = $proj['projectID'];
            $existing_projectName    = $proj['projectName'];
            $existing_projectAddress = $proj['projectAddress'];
            $existing_deadline       = $proj['deadline'];
            $existing_remarks        = $proj['remarks'];
            $existing_companyID      = $proj['companyID'];
        }
    }

    // Save (add/update)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $isEdit = !empty($_POST['projectID']);
        if ($isEdit) {
            $projectID = $_POST['projectID'];
        } else {
            $row = $pdo->query("SELECT MAX(CAST(projectID AS UNSIGNED)) AS max_id FROM project")->fetch(PDO::FETCH_ASSOC);
            $projectID = strval(($row['max_id'] ?? 0) + 1);
        }

        $stmt = $pdo->prepare(
            $isEdit
                ? "UPDATE project SET companyID=:companyID, projectName=:projectName, projectAddress=:projectAddress, deadline=:deadline, remarks=:remarks WHERE projectID=:projectID"
                : "INSERT INTO project (projectID, companyID, projectName, projectAddress, deadline, remarks) VALUES (:projectID, :companyID, :projectName, :projectAddress, :deadline, :remarks)"
        );

        $stmt->execute([
            ':projectID'      => $projectID,
            ':companyID'      => $_POST['companyID'],
            ':projectName'    => $_POST['projectName'],
            ':projectAddress' => $_POST['projectAddress'],
            ':deadline'       => $_POST['deadline'],
            ':remarks'        => $_POST['remarks']
        ]);

        $message = "Project " . ($isEdit ? "updated" : "created") . " successfully.";
        $reloadPage = true;
    }

    // Fetch all projects
    $projects = $pdo->query("SELECT * FROM project ORDER BY projectID DESC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

//if ($reloadPage) {
//    header("Location: projectSetup.php");
//    exit;
//}

// Output SweetAlert script
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@10'></script>";
echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        const msg = " . json_encode($message) . ";
        const reload = " . ($reloadPage ? 'true' : 'false') . ";
        if(msg) {
            Swal.fire({
                icon: msg.includes('successfully') ? 'success' : 'error',
                title: msg,
                showConfirmButton: false,
                timer: 1500
            }).then(() => {
                if (reload) {
                    window.location.href = 'projectSetup.php';
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
    <title>Project Setup</title>
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
                <h1 class="h3 mb-3 text-gray-800">Project Setup</h1>

                <!-- Success/Error popup -->
                <?php if ($message): ?>
                    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
                    <script>
                        Swal.fire({
                            icon: '<?= strpos($message, 'successfully') !== false ? 'success' : 'error'; ?>',
                            title: '<?= addslashes($message); ?>',
                            showConfirmButton: false,
                            timer: 1500
                        });
                    </script>
                <?php endif; ?>

                <!-- Project List -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Projects</h6></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Company</th>
                                    <th>Project Name</th>
                                    <th>Address</th>
                                    <th>Deadline</th>
                                    <th>Remarks</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($projects as $p): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($p['projectID']); ?></td>
                                        <td><?= htmlspecialchars($companies[$p['companyID']] ?? 'Unknown'); ?></td>
                                        <td><?= htmlspecialchars($p['projectName']); ?></td>
                                        <td><?= htmlspecialchars($p['projectAddress']); ?></td>
                                        <td><?= htmlspecialchars($p['deadline']); ?></td>
                                        <td><?= htmlspecialchars($p['remarks']); ?></td>
                                        <td>
                                            <a href="?edit=<?= urlencode($p['projectID']); ?>" class="btn btn-sm btn-info">Edit</a>
                                            <a href="?delete=<?= urlencode($p['projectID']); ?>" class="btn btn-sm btn-danger"
                                               onclick="return confirm('Delete this project?');">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Project Form -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><?= isset($_GET['edit']) ? 'Edit Project' : 'New Project'; ?></h6></div>
                    <div class="card-body">
                        <form action="projectSetup.php<?= isset($_GET['edit']) ? '?edit=' . urlencode($_GET['edit']) : ''; ?>" method="POST">
                            <input type="hidden" name="projectID" value="<?= htmlspecialchars($existing_projectID); ?>">

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Company</label>
                                    <select name="companyID" class="form-control" required>
                                        <option value="">-- Select Company --</option>
                                        <?php foreach ($companies as $cid => $cname): ?>
                                            <option value="<?= htmlspecialchars($cid); ?>" <?= ($cid == $existing_companyID) ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($cname); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Project Name</label>
                                    <input type="text" name="projectName" class="form-control"
                                           value="<?= htmlspecialchars($existing_projectName); ?>" required>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Deadline</label>
                                    <input type="date" name="deadline" class="form-control"
                                           value="<?= htmlspecialchars($existing_deadline); ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Project Address</label>
                                    <input type="text" name="projectAddress" class="form-control"
                                           value="<?= htmlspecialchars($existing_projectAddress); ?>" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Remarks</label>
                                    <textarea name="remarks" class="form-control"><?= htmlspecialchars($existing_remarks); ?></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary"><?= isset($_GET['edit']) ? 'Update' : 'Create'; ?></button>
                            <a href="projectSetup.php" class="btn btn-secondary ml-2">Reset</a>
                        </form>
                    </div>
                </div>
            </div>
            <?php include_once 'include/admin-footer.php'; ?>
        </div>
    </div>
</div>
</body>
</html>
