<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "access_control.php";
require_once "include/role_helpers.php";
require_login();
allowRoles(['Administrator', 'Admin', 'Company Admin', 'System_owner', 'Staff']);

$message = "";
$messageTab = "";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
} catch (Exception $e) {
    bbcc_fail_db($e);
}

// ─── Handle all POST actions before SELECT ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create Class
    if ($action === 'create_class') {
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

    // Edit Class
    if ($action === 'edit_class') {
        try {
            $editId = (int)($_POST['edit_id'] ?? 0);
            $className = trim($_POST['class_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $capacity = (int)($_POST['capacity'] ?? 0);
            $scheduleText = trim($_POST['schedule_text'] ?? '');
            $active = isset($_POST['active']) ? 1 : 0;

            if ($editId === 0 || $className === '') {
                throw new Exception("Class ID and name are required.");
            }

            $teacherId = (int)($_POST['teacher_id'] ?? 0) ?: null;

            $stmt = $pdo->prepare(
                "UPDATE classes SET class_name = :class_name, description = :description,
                 capacity = :capacity, schedule_text = :schedule_text, active = :active,
                 teacher_id = :teacher_id WHERE id = :id"
            );
            $stmt->execute([
                ':class_name'   => $className,
                ':description'  => $description === '' ? null : $description,
                ':capacity'     => $capacity,
                ':schedule_text'=> $scheduleText === '' ? null : $scheduleText,
                ':active'       => $active,
                ':teacher_id'   => $teacherId,
                ':id'           => $editId
            ]);

            $message = "Class updated successfully.";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }

    // Delete Class
    if ($action === 'delete_class') {
        try {
            $deleteId = (int)($_POST['delete_id'] ?? 0);
            if ($deleteId === 0) {
                throw new Exception("Invalid class ID.");
            }

            // Check for students assigned to this class
            $check = $pdo->prepare("SELECT COUNT(*) FROM class_assignments WHERE class_id = :id");
            $check->execute([':id' => $deleteId]);
            $assignedCount = $check->fetchColumn();

            if ($assignedCount > 0) {
                throw new Exception("Cannot delete class — $assignedCount student(s) are still assigned. Remove assignments first.");
            }

            $stmt = $pdo->prepare("DELETE FROM classes WHERE id = :id");
            $stmt->execute([':id' => $deleteId]);
            $message = "Class deleted successfully.";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }

    // Edit Teacher
    if ($action === 'edit_teacher') {
        try {
            $editId = (int)($_POST['edit_teacher_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $active = isset($_POST['teacher_active']) ? 1 : 0;

            if ($editId === 0 || $fullName === '') {
                throw new Exception("Teacher ID and name are required.");
            }

            $stmt = $pdo->prepare(
                "UPDATE teachers SET full_name = :full_name, email = :email, phone = :phone, active = :active WHERE id = :id"
            );
            $stmt->execute([
                ':full_name' => $fullName,
                ':email' => $email === '' ? null : $email,
                ':phone' => $phone === '' ? null : $phone,
                ':active' => $active,
                ':id' => $editId
            ]);

            $message = "Teacher updated successfully.";
            $messageTab = 'teachers';
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }

    // Delete Teacher
    if ($action === 'delete_teacher') {
        try {
            $deleteId = (int)($_POST['delete_teacher_id'] ?? 0);
            if ($deleteId === 0) {
                throw new Exception("Invalid teacher ID.");
            }

            // Check if teacher is assigned to any class
            $check = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE teacher_id = (SELECT id FROM teachers WHERE id = :id)");
            $check->execute([':id' => $deleteId]);
            $assignedCount = $check->fetchColumn();

            if ($assignedCount > 0) {
                throw new Exception("Cannot delete teacher — assigned to $assignedCount class(es). Unassign first.");
            }

            // Delete teacher and associated user account
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT user_id FROM teachers WHERE id = :id");
            $stmt->execute([':id' => $deleteId]);
            $userId = $stmt->fetchColumn();

            $pdo->prepare("DELETE FROM teachers WHERE id = :id")->execute([':id' => $deleteId]);
            if ($userId) {
                $pdo->prepare("DELETE FROM user WHERE userid = :uid")->execute([':uid' => $userId]);
            }
            $pdo->commit();

            $message = "Teacher deleted successfully.";
            $messageTab = 'teachers';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
        }
    }

    // Assign Teacher
    if ($action === 'assign_teacher') {
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

    // Create Teacher
    if ($action === 'create_teacher') {
        try {
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $phone    = trim($_POST['phone'] ?? '');
            $username = $email; // email is the login username
            $password = $_POST['password'] ?? '';

            if ($fullName === '' || $email === '' || $password === '') {
                throw new Exception("Full name, email and password are required.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Please enter a valid email address.");
            }

            $chk = $pdo->prepare("SELECT userid FROM user WHERE username = :username");
            $chk->execute([':username' => $username]);
            if ($chk->fetch()) {
                throw new Exception("Username already exists.");
            }

            $row    = $pdo->query("SELECT MAX(CAST(userid AS UNSIGNED)) AS max_id FROM user")->fetch(PDO::FETCH_ASSOC);
            $userId = (string)((int)($row['max_id'] ?? 0) + 1);

            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO user (userid, username, password, role, createdDate)
                 VALUES (:userid, :username, :password, 'teacher', :createdDate)"
            )->execute([
                ':userid'      => $userId,
                ':username'    => $username,
                ':password'    => password_hash($password, PASSWORD_DEFAULT),
                ':createdDate' => date('Y-m-d H:i:s'),
            ]);

            $pdo->prepare(
                "INSERT INTO teachers (user_id, full_name, email, phone)
                 VALUES (:user_id, :full_name, :email, :phone)"
            )->execute([
                ':user_id'   => $userId,
                ':full_name' => $fullName,
                ':email'     => $email === '' ? null : $email,
                ':phone'     => $phone === '' ? null : $phone,
            ]);

            $pdo->commit();

            $message    = "Teacher created successfully.";
            $messageTab = 'teachers';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message    = "Error: " . $e->getMessage();
            $messageTab = 'teachers';
        }
    }
}

// ─── Fetch data after all mutations ───
$classes = $pdo->query(
    "SELECT c.*, t.full_name AS teacher_name
     FROM classes c
     LEFT JOIN teachers t ON t.id = c.teacher_id
     ORDER BY c.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$teachers = $pdo->query(
    "SELECT id, full_name FROM teachers WHERE active = 1 ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Classes & Teachers</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

                <?php if ($message): ?>
                <script>
                document.addEventListener('DOMContentLoaded', () => {
                    Swal.fire({
                        icon: <?= json_encode(stripos($message, 'Error') === 0 ? 'error' : 'success') ?>,
                        title: <?= json_encode($message) ?>,
                        timer: 2000,
                        showConfirmButton: false
                    });
                });
                </script>
                <?php endif; ?>

                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h1 class="h3 mb-0 text-gray-800 font-weight-bold">Classes & Teachers</h1>
                        <p class="text-muted mb-0" style="font-size:.88rem;">Manage classes, assign teachers, and configure schedules.</p>
                    </div>
                </div>

                <!-- ─── Tabs ─── -->
                <ul class="nav nav-tabs mb-4" id="setupTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="tab-classes" data-toggle="tab" href="#pane-classes" role="tab">
                            <i class="fas fa-chalkboard mr-1"></i> Classes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-teachers" data-toggle="tab" href="#pane-teachers" role="tab">
                            <i class="fas fa-chalkboard-teacher mr-1"></i> Teachers
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- ═══ Classes Tab ═══ -->
                    <div class="tab-pane fade show active" id="pane-classes" role="tabpanel">

                        <!-- Create Class Form -->
                        <div class="card shadow mb-4" style="border-radius:14px;border:none;">
                            <div class="card-header py-3" style="border-radius:14px 14px 0 0;">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-plus-circle mr-1"></i> Create New Class</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="create_class">
                                    <div class="form-row">
                                        <div class="form-group col-md-5">
                                            <label><i class="fas fa-tag mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Class Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="class_name" required placeholder="e.g. Beginner Dzongkha">
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label><i class="fas fa-users mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Capacity</label>
                                            <input type="number" class="form-control" name="capacity" min="0" placeholder="e.g. 25">
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label><i class="fas fa-clock mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Schedule</label>
                                            <input type="text" class="form-control" name="schedule_text" placeholder="e.g. Sat 10:00-12:00">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-align-left mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Description</label>
                                        <textarea class="form-control" name="description" rows="2" placeholder="Optional class description"></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-plus mr-1"></i> Create Class</button>
                                </form>
                            </div>
                        </div>

                        <!-- Assign Teacher -->
                        <div class="card shadow mb-4" style="border-radius:14px;border:none;">
                            <div class="card-header py-3" style="border-radius:14px 14px 0 0;">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-link mr-1"></i> Assign Teacher to Class</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="assign_teacher">
                                    <div class="form-row align-items-end">
                                        <div class="form-group col-md-5">
                                            <label><i class="fas fa-chalkboard mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Class</label>
                                            <select name="class_id" class="form-control" required>
                                                <option value="">— Select class —</option>
                                                <?php foreach ($classes as $class): ?>
                                                    <option value="<?= (int)$class['id'] ?>"><?= htmlspecialchars($class['class_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-5">
                                            <label><i class="fas fa-chalkboard-teacher mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Teacher</label>
                                            <select name="teacher_id" class="form-control">
                                                <option value="">— Unassigned —</option>
                                                <?php foreach ($teachers as $teacher): ?>
                                                    <option value="<?= (int)$teacher['id'] ?>"><?= htmlspecialchars($teacher['full_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-2">
                                            <button type="submit" class="btn btn-primary btn-block" style="border-radius:10px;"><i class="fas fa-link mr-1"></i> Assign</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Classes Table -->
                        <div class="card shadow mb-4" style="border-radius:14px;border:none;">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list mr-1"></i> All Classes</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" width="100%">
                                        <thead style="background:#f8f9fc;">
                                        <tr>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Class</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Teacher</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Capacity</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Schedule</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Active</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;width:90px;text-align:center;">Actions</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($classes as $class): ?>
                                            <tr>
                                                <td class="font-weight-bold"><?= htmlspecialchars($class['class_name']) ?></td>
                                                <td><?= htmlspecialchars($class['teacher_name'] ?? 'Not Assigned') ?></td>
                                                <td><?= htmlspecialchars($class['capacity']) ?></td>
                                                <td><?= htmlspecialchars($class['schedule_text'] ?? '') ?></td>
                                                <td>
                                                    <?php if ($class['active']): ?>
                                                        <span class="badge badge-success" style="border-radius:12px;padding:5px 12px;">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary" style="border-radius:12px;padding:5px 12px;">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center" style="white-space:nowrap;">
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-class"
                                                        title="Edit"
                                                        data-id="<?= (int)$class['id'] ?>"
                                                        data-name="<?= htmlspecialchars($class['class_name'], ENT_QUOTES) ?>"
                                                        data-description="<?= htmlspecialchars($class['description'] ?? '', ENT_QUOTES) ?>"
                                                        data-capacity="<?= (int)$class['capacity'] ?>"
                                                        data-schedule="<?= htmlspecialchars($class['schedule_text'] ?? '', ENT_QUOTES) ?>"
                                                        data-active="<?= (int)$class['active'] ?>"
                                                        style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;">
                                                        <i class="fas fa-pencil-alt" style="font-size:.75rem;"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-class"
                                                        title="Delete"
                                                        data-id="<?= (int)$class['id'] ?>"
                                                        data-name="<?= htmlspecialchars($class['class_name'], ENT_QUOTES) ?>"
                                                        style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;">
                                                        <i class="fas fa-trash-alt" style="font-size:.75rem;"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($classes)): ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox mr-1"></i> No classes found.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Teachers Tab ═══ -->
                    <div class="tab-pane fade" id="pane-teachers" role="tabpanel">

                        <!-- Create Teacher Form -->
                        <div class="card shadow mb-4" style="border-radius:14px;border:none;">
                            <div class="card-header py-3" style="border-radius:14px 14px 0 0;">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-plus mr-1"></i> Add New Teacher</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="admin-class-setup">
                                    <input type="hidden" name="action" value="create_teacher">
                                    <div class="form-row">
                                        <div class="form-group col-md-4">
                                            <label><i class="fas fa-user mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Full Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="full_name" required placeholder="e.g. Karma Tshering">
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label><i class="fas fa-envelope mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Email (Login Username) <span class="text-danger">*</span></label>
                                            <input type="email" class="form-control" name="email" placeholder="teacher@example.com" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label><i class="fas fa-phone mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Phone</label>
                                            <input type="text" class="form-control" name="phone" placeholder="04xx xxx xxx">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-12">
                                            <label><i class="fas fa-key mr-1" style="color:var(--brand,#881b12);font-size:.7rem;"></i> Temp Password <span class="text-danger">*</span></label>
                                            <input type="password" class="form-control" name="password" required placeholder="Initial password">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-user-plus mr-1"></i> Create Teacher</button>
                                </form>
                            </div>
                        </div>

                        <!-- Teachers Table -->
                        <div class="card shadow mb-4" style="border-radius:14px;border:none;">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list mr-1"></i> All Teachers</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" width="100%">
                                        <thead style="background:#f8f9fc;">
                                        <tr>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Name</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Username</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Email</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Phone</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">Active</th>
                                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;width:90px;text-align:center;">Actions</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $allTeachers = $pdo->query(
                                            "SELECT t.*, u.username FROM teachers t LEFT JOIN user u ON u.userid = t.user_id ORDER BY t.created_at DESC"
                                        )->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($allTeachers as $teacher): ?>
                                            <tr>
                                                <td class="font-weight-bold"><?= htmlspecialchars($teacher['full_name']) ?></td>
                                                <td><code><?= htmlspecialchars($teacher['username'] ?? '') ?></code></td>
                                                <td><?= htmlspecialchars($teacher['email'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($teacher['phone'] ?? '—') ?></td>
                                                <td>
                                                    <?php if ($teacher['active']): ?>
                                                        <span class="badge badge-success" style="border-radius:12px;padding:5px 12px;">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary" style="border-radius:12px;padding:5px 12px;">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center" style="white-space:nowrap;">
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-teacher"
                                                        title="Edit"
                                                        data-id="<?= (int)$teacher['id'] ?>"
                                                        data-name="<?= htmlspecialchars($teacher['full_name'], ENT_QUOTES) ?>"
                                                        data-email="<?= htmlspecialchars($teacher['email'] ?? '', ENT_QUOTES) ?>"
                                                        data-phone="<?= htmlspecialchars($teacher['phone'] ?? '', ENT_QUOTES) ?>"
                                                        data-active="<?= (int)$teacher['active'] ?>"
                                                        style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;">
                                                        <i class="fas fa-pencil-alt" style="font-size:.75rem;"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-teacher"
                                                        title="Delete"
                                                        data-id="<?= (int)$teacher['id'] ?>"
                                                        data-name="<?= htmlspecialchars($teacher['full_name'], ENT_QUOTES) ?>"
                                                        style="width:32px;height:32px;padding:0;border-radius:8px;line-height:32px;">
                                                        <i class="fas fa-trash-alt" style="font-size:.75rem;"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($allTeachers)): ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox mr-1"></i> No teachers found.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- tab-content -->

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<!-- ─── Edit Class Modal ─── -->
<div class="modal fade" id="editClassModal" tabindex="-1" role="dialog" aria-labelledby="editClassLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius:14px;border:none;">
            <form method="POST" id="editClassForm">
                <input type="hidden" name="action" value="edit_class">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="modal-header" style="border-radius:14px 14px 0 0;background:#f8f9fc;">
                    <h5 class="modal-title font-weight-bold" id="editClassLabel">
                        <i class="fas fa-pencil-alt mr-1 text-primary"></i> Edit Class
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-tag mr-1" style="color:#881b12;font-size:.7rem;"></i> Class Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="class_name" id="edit_class_name" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label><i class="fas fa-users mr-1" style="color:#881b12;font-size:.7rem;"></i> Capacity</label>
                            <input type="number" class="form-control" name="capacity" id="edit_capacity" min="0">
                        </div>
                        <div class="form-group col-md-6">
                            <label><i class="fas fa-clock mr-1" style="color:#881b12;font-size:.7rem;"></i> Schedule</label>
                            <input type="text" class="form-control" name="schedule_text" id="edit_schedule">
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-align-left mr-1" style="color:#881b12;font-size:.7rem;"></i> Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
                    </div>
                    <div class="form-group mt-2">
                        <label><i class="fas fa-chalkboard-teacher mr-1" style="color:#881b12;font-size:.7rem;"></i> Assign Teacher</label>
                        <select class="form-control" name="teacher_id" id="edit_class_teacher">
                            <option value="">— Unassigned —</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="custom-control custom-switch mt-2">
                        <input type="checkbox" class="custom-control-input" id="edit_active" name="active" value="1">
                        <label class="custom-control-label" for="edit_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid #eee;">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" style="border-radius:10px;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save mr-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden delete form (class) -->
<form id="deleteClassForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete_class">
    <input type="hidden" name="delete_id" id="delete_class_id">
</form>

<!-- ─── Edit Teacher Modal ─── -->
<div class="modal fade" id="editTeacherModal" tabindex="-1" role="dialog" aria-labelledby="editTeacherLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius:14px;border:none;">
            <form method="POST" id="editTeacherForm">
                <input type="hidden" name="action" value="edit_teacher">
                <input type="hidden" name="edit_teacher_id" id="edit_teacher_id">
                <div class="modal-header" style="border-radius:14px 14px 0 0;background:#f8f9fc;">
                    <h5 class="modal-title font-weight-bold" id="editTeacherLabel">
                        <i class="fas fa-pencil-alt mr-1 text-primary"></i> Edit Teacher
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-user mr-1" style="color:#881b12;font-size:.7rem;"></i> Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" id="edit_teacher_name" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label><i class="fas fa-envelope mr-1" style="color:#881b12;font-size:.7rem;"></i> Email</label>
                            <input type="email" class="form-control" name="email" id="edit_teacher_email">
                        </div>
                        <div class="form-group col-md-6">
                            <label><i class="fas fa-phone mr-1" style="color:#881b12;font-size:.7rem;"></i> Phone</label>
                            <input type="text" class="form-control" name="phone" id="edit_teacher_phone">
                        </div>
                    </div>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="edit_teacher_active" name="teacher_active" value="1">
                        <label class="custom-control-label" for="edit_teacher_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid #eee;">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" style="border-radius:10px;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save mr-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden delete form (teacher) -->
<form id="deleteTeacherForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete_teacher">
    <input type="hidden" name="delete_teacher_id" id="delete_teacher_id">
</form>

<script>
// Preserve active tab via URL hash (or forced tab after POST)
$(function(){
    var forcedTab = <?= json_encode($messageTab) ?>;
    if (forcedTab === 'teachers') {
        $('#setupTabs a[href="#pane-teachers"]').tab('show');
    } else {
        var hash = window.location.hash;
        if (hash) {
            $('#setupTabs a[href="'+hash+'"]').tab('show');
        }
    }
    $('#setupTabs a').on('shown.bs.tab', function(e){
        history.replaceState(null, null, e.target.hash);
    });
});

// ─── Edit Class ───
$(document).on('click', '.btn-edit-class', function(){
    var btn = $(this);
    $('#edit_id').val(btn.data('id'));
    $('#edit_class_name').val(btn.data('name'));
    $('#edit_description').val(btn.data('description'));
    $('#edit_capacity').val(btn.data('capacity'));
    $('#edit_schedule').val(btn.data('schedule'));
    $('#edit_active').prop('checked', btn.data('active') == 1);
    var tid = btn.data('teacher-id');
    $('#edit_class_teacher').val(tid ? String(tid) : '');
    $('#editClassModal').modal('show');
});

// ─── Delete Class (SweetAlert) ───
$(document).on('click', '.btn-delete-class', function(){
    var id = $(this).data('id');
    var name = $(this).data('name');
    Swal.fire({
        title: 'Delete Class?',
        html: 'Are you sure you want to delete <strong>' + name + '</strong>?<br><small class="text-muted">This cannot be undone.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#881b12',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, delete',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then(function(result){
        if (result.isConfirmed) {
            $('#delete_class_id').val(id);
            $('#deleteClassForm').submit();
        }
    });
});

// ─── Edit Teacher ───
$(document).on('click', '.btn-edit-teacher', function(){
    var btn = $(this);
    $('#edit_teacher_id').val(btn.data('id'));
    $('#edit_teacher_name').val(btn.data('name'));
    $('#edit_teacher_email').val(btn.data('email'));
    $('#edit_teacher_phone').val(btn.data('phone'));
    $('#edit_teacher_active').prop('checked', btn.data('active') == 1);
    $('#editTeacherModal').modal('show');
});

// ─── Delete Teacher (SweetAlert) ───
$(document).on('click', '.btn-delete-teacher', function(){
    var id = $(this).data('id');
    var name = $(this).data('name');
    Swal.fire({
        title: 'Delete Teacher?',
        html: 'Are you sure you want to delete <strong>' + name + '</strong>?<br><small class="text-muted">This will also remove their login account.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#881b12',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, delete',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then(function(result){
        if (result.isConfirmed) {
            $('#delete_teacher_id').val(id);
            $('#deleteTeacherForm').submit();
        }
    });
});
</script>

</body>
</html>
