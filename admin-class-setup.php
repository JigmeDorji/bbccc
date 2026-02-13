<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/role_helpers.php";
require_login();
allowRoles(['Administrator', 'Admin', 'Company Admin', 'System_owner', 'Staff']);

$message = "";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_class') {
    try {
        $className = trim($_POST['class_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $capacity = (int)($_POST['capacity'] ?? 0);
        $scheduleText = trim($_POST['schedule_text'] ?? '');

        if ($className === '') {
            throw new Exception("Class name is required.");
        }

        $stmt = $pdo->prepare(
            "INSERT INTO classes (class_name, description, capacity, schedule_text)
             VALUES (:class_name, :description, :capacity, :schedule_text)"
        );
        $stmt->execute([
            ':class_name' => $className,
            ':description' => $description === '' ? null : $description,
            ':capacity' => $capacity,
            ':schedule_text' => $scheduleText === '' ? null : $scheduleText
        ]);

        $message = "Class created successfully.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$classes = $pdo->query(
    "SELECT c.*, t.full_name AS teacher_name
     FROM classes c
     LEFT JOIN teachers t ON t.id = c.teacher_id
     ORDER BY c.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$teachers = $pdo->query(
    "SELECT id, full_name FROM teachers WHERE active = 1 ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_teacher') {
    try {
        $classId = (int)($_POST['class_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);

        if ($classId === 0) {
            throw new Exception("Class selection is required.");
        }

        $stmt = $pdo->prepare("UPDATE classes SET teacher_id = :teacher_id WHERE id = :id");
        $stmt->execute([
            ':teacher_id' => $teacherId ?: null,
            ':id' => $classId
        ]);

        $message = "Teacher assignment updated.";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Class Setup</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">
                <h1 class="h3 mb-4 text-gray-800">Class Setup</h1>

                <?php if ($message): ?>
                    <div class="alert <?php echo (stripos($message, 'Error') === 0) ? 'alert-danger' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Create Class</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="create_class">
                            <div class="form-group">
                                <label>Class Name</label>
                                <input type="text" class="form-control" name="class_name" required>
                            </div>

                            <div class="form-group">
                                <label>Description</label>
                                <textarea class="form-control" name="description" rows="3"></textarea>
                            </div>

                            <div class="form-group">
                                <label>Capacity</label>
                                <input type="number" class="form-control" name="capacity" min="0">
                            </div>

                            <div class="form-group">
                                <label>Schedule Info</label>
                                <input type="text" class="form-control" name="schedule_text" placeholder="e.g. Sat 10:00-12:00">
                            </div>

                            <button type="submit" class="btn btn-primary">Create Class</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Assign Teacher</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="form-inline">
                            <input type="hidden" name="action" value="assign_teacher">
                            <div class="form-group mr-3">
                                <label class="mr-2">Class</label>
                                <select name="class_id" class="form-control" required>
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo (int)$class['id']; ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mr-3">
                                <label class="mr-2">Teacher</label>
                                <select name="teacher_id" class="form-control">
                                    <option value="">-- Unassigned --</option>
                                    <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?php echo (int)$teacher['id']; ?>">
                                            <?php echo htmlspecialchars($teacher['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Assign</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Existing Classes</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Class</th>
                                    <th>Teacher</th>
                                    <th>Capacity</th>
                                    <th>Schedule</th>
                                    <th>Active</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($class['teacher_name'] ?? 'Not Assigned'); ?></td>
                                        <td><?php echo htmlspecialchars($class['capacity']); ?></td>
                                        <td><?php echo htmlspecialchars($class['schedule_text'] ?? ''); ?></td>
                                        <td><?php echo $class['active'] ? 'Yes' : 'No'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($classes)): ?>
                                    <tr><td colspan="5" class="text-center">No classes found.</td></tr>
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
