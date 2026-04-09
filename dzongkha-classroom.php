<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/notifications.php";
require_login();

function dc_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function dc_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS classroom_announcements (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n            title VARCHAR(200) NOT NULL,\n            message TEXT NOT NULL,\n            category VARCHAR(50) NOT NULL DEFAULT 'Announcement',\n            scope_type VARCHAR(30) NOT NULL DEFAULT 'selected_classes',\n            posted_by_user_id VARCHAR(80) NULL,\n            posted_by_username VARCHAR(190) NULL,\n            posted_by_name VARCHAR(190) NULL,\n            posted_by_role VARCHAR(40) NULL,\n            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            KEY idx_created_at (created_at),\n            KEY idx_scope_type (scope_type),\n            KEY idx_posted_by_user_id (posted_by_user_id)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS classroom_announcement_classes (\n            announcement_id BIGINT UNSIGNED NOT NULL,\n            class_id INT NOT NULL,\n            PRIMARY KEY (announcement_id, class_id),\n            KEY idx_class_id (class_id)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS classroom_reports (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n            class_id INT NOT NULL,\n            student_id INT NOT NULL,\n            teacher_id INT NULL,\n            report_title VARCHAR(200) NOT NULL,\n            report_type VARCHAR(50) NOT NULL DEFAULT 'Progress',\n            feedback_text TEXT NOT NULL,\n            created_by_user_id VARCHAR(80) NULL,\n            created_by_name VARCHAR(190) NULL,\n            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            KEY idx_student_id (student_id),\n            KEY idx_class_id (class_id),\n            KEY idx_created_at (created_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS classroom_report_comments (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n            report_id BIGINT UNSIGNED NOT NULL,\n            parent_id INT NOT NULL,\n            commenter_name VARCHAR(190) NULL,\n            comment_text TEXT NOT NULL,\n            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            KEY idx_report_id (report_id),\n            KEY idx_parent_id (parent_id),\n            KEY idx_created_at (created_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");

    $colTeacher = $pdo->query("SHOW COLUMNS FROM classroom_report_comments LIKE 'read_by_teacher_at'")->fetch(PDO::FETCH_ASSOC);
    if (!$colTeacher) {
        $pdo->exec("ALTER TABLE classroom_report_comments ADD COLUMN read_by_teacher_at DATETIME NULL AFTER created_at");
    }
    $colAdmin = $pdo->query("SHOW COLUMNS FROM classroom_report_comments LIKE 'read_by_admin_at'")->fetch(PDO::FETCH_ASSOC);
    if (!$colAdmin) {
        $pdo->exec("ALTER TABLE classroom_report_comments ADD COLUMN read_by_admin_at DATETIME NULL AFTER read_by_teacher_at");
    }

    $done = true;
}

function dc_detect_teacher(PDO $pdo): array {
    $uid = (string)($_SESSION['userid'] ?? '');
    $uname = (string)($_SESSION['username'] ?? '');
    $stmt = $pdo->prepare("\n        SELECT id, full_name\n        FROM teachers\n        WHERE (user_id = :uid AND :uid <> '')\n           OR LOWER(email) = LOWER(:em)\n        ORDER BY id ASC\n        LIMIT 1\n    ");
    $stmt->execute([':uid' => $uid, ':em' => $uname]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function dc_detect_parent(PDO $pdo): array {
    $uname = (string)($_SESSION['username'] ?? '');
    if ($uname === '') return [];
    $stmt = $pdo->prepare("SELECT id, full_name FROM parents WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $uname]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function dc_notify_usernames(PDO $pdo, array $usernames, string $title, string $body = '', string $linkUrl = ''): void {
    if (!function_exists('bbcc_notify_username')) {
        return;
    }
    $sent = [];
    foreach ($usernames as $u) {
        $uname = strtolower(trim((string)$u));
        if ($uname === '' || isset($sent[$uname])) {
            continue;
        }
        $sent[$uname] = true;
        bbcc_notify_username($pdo, $uname, $title, $body, $linkUrl);
    }
}

function dc_collect_parent_usernames_by_class_ids(PDO $pdo, array $classIds): array {
    $classIds = array_values(array_unique(array_map('intval', $classIds)));
    if (empty($classIds)) {
        return [];
    }
    $sql = "
        SELECT DISTINCT p.username
        FROM class_assignments ca
        INNER JOIN students s ON s.id = ca.student_id
        INNER JOIN parents p ON p.id = s.parentId
        WHERE ca.class_id IN (" . implode(',', $classIds) . ")
          AND p.username IS NOT NULL
          AND TRIM(p.username) <> ''
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function (array $r): string {
        return (string)($r['username'] ?? '');
    }, $rows ?: []);
}

function dc_collect_teacher_usernames_by_class_ids(PDO $pdo, array $classIds): array {
    $classIds = array_values(array_unique(array_map('intval', $classIds)));
    if (empty($classIds)) {
        return [];
    }
    $sql = "
        SELECT DISTINCT t.email
        FROM classes c
        INNER JOIN teachers t ON t.id = c.teacher_id
        WHERE c.id IN (" . implode(',', $classIds) . ")
          AND t.email IS NOT NULL
          AND TRIM(t.email) <> ''
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function (array $r): string {
        return (string)($r['email'] ?? '');
    }, $rows ?: []);
}

function dc_parent_username_for_student(PDO $pdo, int $studentId): string {
    if ($studentId <= 0) {
        return '';
    }
    $stmt = $pdo->prepare("
        SELECT p.username
        FROM students s
        INNER JOIN parents p ON p.id = s.parentId
        WHERE s.id = :sid
        LIMIT 1
    ");
    $stmt->execute([':sid' => $studentId]);
    return trim((string)$stmt->fetchColumn());
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    bbcc_fail_db($e);
}

dc_ensure_schema($pdo);

$isAdmin = is_admin_role();
$teacher = dc_detect_teacher($pdo);
$teacherId = (int)($teacher['id'] ?? 0);
$teacherName = trim((string)($teacher['full_name'] ?? ''));
$parent = dc_detect_parent($pdo);
$parentId = (int)($parent['id'] ?? 0);
$parentName = trim((string)($parent['full_name'] ?? ''));
$hasTeacherProfile = $teacherId > 0;
$hasParentProfile = is_parent_role() || $parentId > 0;
$requestedAs = strtolower(trim((string)($_GET['as'] ?? '')));
$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

$viewMode = 'none';
if ($isAdmin) {
    $viewMode = 'admin';
} elseif ($requestedAs === 'teacher' && $hasTeacherProfile) {
    $viewMode = 'teacher';
} elseif ($requestedAs === 'parent' && $hasParentProfile) {
    $viewMode = 'parent';
} elseif ($currentRole === 'parent' && $hasParentProfile) {
    $viewMode = 'parent';
} elseif ($currentRole === 'teacher' && $hasTeacherProfile) {
    $viewMode = 'teacher';
} elseif ($hasTeacherProfile) {
    $viewMode = 'teacher';
} elseif ($hasParentProfile) {
    $viewMode = 'parent';
}

if ($viewMode === 'none' || is_patron_role()) {
    header("Location: unauthorized");
    exit;
}

$tab = trim((string)($_GET['tab'] ?? 'announcements'));
if (!in_array($tab, ['announcements', 'reports'], true)) {
    $tab = 'announcements';
}

if (!$isAdmin && $hasTeacherProfile && $hasParentProfile && !in_array($requestedAs, ['teacher', 'parent'], true)) {
    header("Location: dzongkha-classroom?tab=" . urlencode($tab) . "&as=" . urlencode($viewMode));
    exit;
}

$modeQuery = ($viewMode === 'teacher' || $viewMode === 'parent') ? '&as=' . $viewMode : '';

$allClasses = $pdo->query("SELECT id, class_name FROM classes WHERE active = 1 ORDER BY class_name ASC")->fetchAll();

$teacherClasses = [];
if ($teacherId > 0) {
    $stmtTc = $pdo->prepare("SELECT id, class_name FROM classes WHERE active = 1 AND teacher_id = :tid ORDER BY class_name ASC");
    $stmtTc->execute([':tid' => $teacherId]);
    $teacherClasses = $stmtTc->fetchAll();
}
$teacherClassIds = array_map('intval', array_column($teacherClasses, 'id'));

$parentClassIds = [];
if ($parentId > 0) {
    $stmtPc = $pdo->prepare("\n        SELECT DISTINCT ca.class_id\n        FROM class_assignments ca\n        INNER JOIN students s ON s.id = ca.student_id\n        WHERE s.parentId = :pid\n    ");
    $stmtPc->execute([':pid' => $parentId]);
    $parentClassIds = array_map('intval', array_column($stmtPc->fetchAll(), 'class_id'));
}

$teacherStudents = [];
if ($teacherId > 0) {
    $stmtTs = $pdo->prepare("\n        SELECT DISTINCT\n            s.id AS student_id_pk,\n            s.student_id,\n            s.student_name,\n            c.id AS class_id,\n            c.class_name\n        FROM class_assignments ca\n        INNER JOIN classes c ON c.id = ca.class_id\n        INNER JOIN students s ON s.id = ca.student_id\n        WHERE c.teacher_id = :tid\n        ORDER BY c.class_name ASC, s.student_name ASC\n    ");
    $stmtTs->execute([':tid' => $teacherId]);
    $teacherStudents = $stmtTs->fetchAll();
}

$flashType = '';
$flashMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_announcement' && in_array($viewMode, ['admin', 'teacher'], true)) {
        $title = trim((string)($_POST['title'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'Announcement'));
        $scope = trim((string)($_POST['scope_type'] ?? 'selected_classes'));
        $selectedClassIds = array_map('intval', (array)($_POST['class_ids'] ?? []));

        try {
            if ($title === '') throw new Exception('Title is required.');
            if ($message === '') throw new Exception('Message is required.');

            if (!in_array($category, ['Announcement', 'Instruction', 'General'], true)) {
                $category = 'Announcement';
            }

            $targetClassIds = [];
            $scopeType = 'selected_classes';

            if ($viewMode === 'admin') {
                if ($scope === 'all_classes') {
                    $scopeType = 'all_classes';
                } else {
                    $allowed = array_map('intval', array_column($allClasses, 'id'));
                    foreach ($selectedClassIds as $cid) {
                        if (in_array($cid, $allowed, true)) $targetClassIds[] = $cid;
                    }
                    $targetClassIds = array_values(array_unique($targetClassIds));
                    if (!$targetClassIds) throw new Exception('Select at least one class or choose All Classes.');
                }
            } else {
                if (!$teacherClassIds) throw new Exception('No assigned classes found for your account.');

                if ($scope === 'all_my_classes') {
                    $targetClassIds = $teacherClassIds;
                } else {
                    foreach ($selectedClassIds as $cid) {
                        if (in_array($cid, $teacherClassIds, true)) $targetClassIds[] = $cid;
                    }
                    $targetClassIds = array_values(array_unique($targetClassIds));
                }

                if (!$targetClassIds) throw new Exception('Select at least one assigned class.');
                $scopeType = 'selected_classes';
            }

            $postedByName = (string)($_SESSION['username'] ?? 'Account');
            if ($viewMode === 'teacher' && $teacherName !== '') {
                $postedByName = $teacherName;
            }
            $postedByRole = $viewMode === 'teacher' ? 'teacher' : 'admin';

            $pdo->beginTransaction();
            $ins = $pdo->prepare("\n                INSERT INTO classroom_announcements\n                    (title, message, category, scope_type, posted_by_user_id, posted_by_username, posted_by_name, posted_by_role)\n                VALUES\n                    (:title, :message, :category, :scope_type, :uid, :uname, :pname, :prole)\n            ");
            $ins->execute([
                ':title' => $title,
                ':message' => $message,
                ':category' => $category,
                ':scope_type' => $scopeType,
                ':uid' => (string)($_SESSION['userid'] ?? ''),
                ':uname' => (string)($_SESSION['username'] ?? ''),
                ':pname' => $postedByName,
                ':prole' => $postedByRole,
            ]);
            $aid = (int)$pdo->lastInsertId();

            if ($scopeType === 'selected_classes') {
                $insMap = $pdo->prepare("INSERT INTO classroom_announcement_classes (announcement_id, class_id) VALUES (:aid, :cid)");
                foreach ($targetClassIds as $cid) {
                    $insMap->execute([':aid' => $aid, ':cid' => $cid]);
                }
            }

            $pdo->commit();

            $notifyClassIds = $targetClassIds;
            if ($viewMode === 'admin' && $scopeType === 'all_classes') {
                $notifyClassIds = array_map('intval', array_column($allClasses, 'id'));
            }

            if (function_exists('bbcc_audit_log')) {
                bbcc_audit_log('classroom_announcement_created', 'classroom_announcements', [
                    'announcement_id' => $aid,
                    'scope_type' => $scopeType,
                    'target_class_count' => count($targetClassIds),
                ], 'success');
            }

            $postedBy = $viewMode === 'teacher' ? ($teacherName !== '' ? $teacherName : 'Teacher') : 'Admin';
            $notifTitle = 'New Classroom ' . $category;
            $notifBody = $title . ' by ' . $postedBy;

            $parentUsers = dc_collect_parent_usernames_by_class_ids($pdo, $notifyClassIds);
            dc_notify_usernames($pdo, $parentUsers, $notifTitle, $notifBody, 'dzongkha-classroom?tab=announcements&as=parent');

            $teacherUsers = dc_collect_teacher_usernames_by_class_ids($pdo, $notifyClassIds);
            dc_notify_usernames($pdo, $teacherUsers, $notifTitle, $notifBody, 'dzongkha-classroom?tab=announcements&as=teacher');

            if (function_exists('bbcc_notify_admins')) {
                bbcc_notify_admins($pdo, $notifTitle, $notifBody, 'dzongkha-classroom?tab=announcements');
            }

            $flashType = 'success';
            $flashMsg = 'Announcement posted successfully.';
            $tab = 'announcements';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flashType = 'danger';
            $flashMsg = 'Error: ' . $e->getMessage();
            $tab = 'announcements';
        }
    }

    if ($action === 'create_report' && $viewMode === 'teacher') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        $reportTitle = trim((string)($_POST['report_title'] ?? ''));
        $reportType = trim((string)($_POST['report_type'] ?? 'Progress'));
        $feedbackText = trim((string)($_POST['feedback_text'] ?? ''));

        try {
            if ($classId <= 0) throw new Exception('Class is required.');
            if ($studentId <= 0) throw new Exception('Student is required.');
            if ($reportTitle === '') throw new Exception('Report title is required.');
            if ($feedbackText === '') throw new Exception('Feedback is required.');

            if (!in_array($classId, $teacherClassIds, true)) {
                throw new Exception('You can only create reports for your assigned classes.');
            }

            if (!in_array($reportType, ['Progress', 'Behavior', 'Homework', 'General'], true)) {
                $reportType = 'Progress';
            }

            $stmtChk = $pdo->prepare("\n                SELECT s.id\n                FROM class_assignments ca\n                INNER JOIN students s ON s.id = ca.student_id\n                INNER JOIN classes c ON c.id = ca.class_id\n                WHERE ca.class_id = :cid\n                  AND ca.student_id = :sid\n                  AND c.teacher_id = :tid\n                LIMIT 1\n            ");
            $stmtChk->execute([':cid' => $classId, ':sid' => $studentId, ':tid' => $teacherId]);
            if (!$stmtChk->fetch()) {
                throw new Exception('Selected student is not in your selected class.');
            }

            $insR = $pdo->prepare("\n                INSERT INTO classroom_reports\n                    (class_id, student_id, teacher_id, report_title, report_type, feedback_text, created_by_user_id, created_by_name)\n                VALUES\n                    (:class_id, :student_id, :teacher_id, :report_title, :report_type, :feedback_text, :uid, :uname)\n            ");
            $insR->execute([
                ':class_id' => $classId,
                ':student_id' => $studentId,
                ':teacher_id' => $teacherId,
                ':report_title' => $reportTitle,
                ':report_type' => $reportType,
                ':feedback_text' => $feedbackText,
                ':uid' => (string)($_SESSION['userid'] ?? ''),
                ':uname' => ($teacherName !== '' ? $teacherName : (string)($_SESSION['username'] ?? 'Teacher')),
            ]);
            $rid = (int)$pdo->lastInsertId();

            if (function_exists('bbcc_audit_log')) {
                bbcc_audit_log('classroom_report_created', 'classroom_reports', [
                    'report_id' => $rid,
                    'class_id' => $classId,
                    'student_id' => $studentId,
                ], 'success');
            }

            $parentUsername = dc_parent_username_for_student($pdo, $studentId);
            if ($parentUsername !== '' && function_exists('bbcc_notify_username')) {
                bbcc_notify_username(
                    $pdo,
                    $parentUsername,
                    'New Student Report Added',
                    'A new report has been posted for your child.',
                    'dzongkha-classroom?tab=reports&as=parent'
                );
            }

            $flashType = 'success';
            $flashMsg = 'Student report posted successfully.';
            $tab = 'reports';
        } catch (Throwable $e) {
            $flashType = 'danger';
            $flashMsg = 'Error: ' . $e->getMessage();
            $tab = 'reports';
        }
    }

    if ($action === 'parent_comment' && $viewMode === 'parent') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $commentText = trim((string)($_POST['comment_text'] ?? ''));

        try {
            if ($reportId <= 0) throw new Exception('Invalid report.');
            if ($commentText === '') throw new Exception('Comment is required.');

            $stmtOwn = $pdo->prepare("\n                SELECT r.id\n                FROM classroom_reports r\n                INNER JOIN students s ON s.id = r.student_id\n                WHERE r.id = :rid\n                  AND s.parentId = :pid\n                LIMIT 1\n            ");
            $stmtOwn->execute([':rid' => $reportId, ':pid' => $parentId]);
            if (!$stmtOwn->fetch()) {
                throw new Exception('You can only comment on reports for your child.');
            }

            $insC = $pdo->prepare("\n                INSERT INTO classroom_report_comments\n                    (report_id, parent_id, commenter_name, comment_text)\n                VALUES\n                    (:report_id, :parent_id, :commenter_name, :comment_text)\n            ");
            $insC->execute([
                ':report_id' => $reportId,
                ':parent_id' => $parentId,
                ':commenter_name' => ($parentName !== '' ? $parentName : (string)($_SESSION['username'] ?? 'Parent')),
                ':comment_text' => $commentText,
            ]);

            if (function_exists('bbcc_audit_log')) {
                bbcc_audit_log('classroom_report_parent_comment', 'classroom_report_comments', [
                    'report_id' => $reportId,
                    'parent_id' => $parentId,
                ], 'success');
            }

            $stmtNotify = $pdo->prepare("
                SELECT
                    r.student_id,
                    r.class_id,
                    t.email AS teacher_email
                FROM classroom_reports r
                LEFT JOIN classes c ON c.id = r.class_id
                LEFT JOIN teachers t ON t.id = c.teacher_id
                WHERE r.id = :rid
                LIMIT 1
            ");
            $stmtNotify->execute([':rid' => $reportId]);
            $notifyRow = $stmtNotify->fetch(PDO::FETCH_ASSOC) ?: [];
            $teacherEmail = trim((string)($notifyRow['teacher_email'] ?? ''));

            if ($teacherEmail !== '' && function_exists('bbcc_notify_username')) {
                bbcc_notify_username(
                    $pdo,
                    $teacherEmail,
                    'New Parent Comment on Student Report',
                    'A parent added a comment to a student report.',
                    'dzongkha-classroom?tab=reports&as=teacher'
                );
            }
            if (function_exists('bbcc_notify_admins')) {
                bbcc_notify_admins(
                    $pdo,
                    'New Parent Comment on Student Report',
                    'A parent added a comment to a classroom report.',
                    'dzongkha-classroom?tab=reports'
                );
            }

            $flashType = 'success';
            $flashMsg = 'Comment submitted successfully.';
            $tab = 'reports';
        } catch (Throwable $e) {
            $flashType = 'danger';
            $flashMsg = 'Error: ' . $e->getMessage();
            $tab = 'reports';
        }
    }

    if ($action === 'teacher_update_report' && $viewMode === 'teacher') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $reportTitle = trim((string)($_POST['report_title'] ?? ''));
        $reportType = trim((string)($_POST['report_type'] ?? 'Progress'));
        $feedbackText = trim((string)($_POST['feedback_text'] ?? ''));

        try {
            if ($reportId <= 0) throw new Exception('Invalid report.');
            if ($reportTitle === '') throw new Exception('Report title is required.');
            if ($feedbackText === '') throw new Exception('Feedback is required.');
            if (!in_array($reportType, ['Progress', 'Behavior', 'Homework', 'General'], true)) {
                $reportType = 'Progress';
            }

            $stmtOwn = $pdo->prepare("\n                SELECT id\n                FROM classroom_reports\n                WHERE id = :rid\n                  AND teacher_id = :tid\n                  AND class_id IN (" . ($teacherClassIds ? implode(',', $teacherClassIds) : '0') . ")\n                LIMIT 1\n            ");
            $stmtOwn->execute([':rid' => $reportId, ':tid' => $teacherId]);
            if (!$stmtOwn->fetch()) {
                throw new Exception('You can only edit reports created for your assigned classes.');
            }

            $upd = $pdo->prepare("\n                UPDATE classroom_reports\n                SET report_title = :title,\n                    report_type = :rtype,\n                    feedback_text = :feedback\n                WHERE id = :rid\n                LIMIT 1\n            ");
            $upd->execute([
                ':title' => $reportTitle,
                ':rtype' => $reportType,
                ':feedback' => $feedbackText,
                ':rid' => $reportId,
            ]);

            if (function_exists('bbcc_audit_log')) {
                bbcc_audit_log('classroom_report_updated', 'classroom_reports', [
                    'report_id' => $reportId,
                ], 'success');
            }

            $stmtStudent = $pdo->prepare("SELECT student_id FROM classroom_reports WHERE id = :rid LIMIT 1");
            $stmtStudent->execute([':rid' => $reportId]);
            $studentForNotify = (int)$stmtStudent->fetchColumn();
            $parentUsername = dc_parent_username_for_student($pdo, $studentForNotify);
            if ($parentUsername !== '' && function_exists('bbcc_notify_username')) {
                bbcc_notify_username(
                    $pdo,
                    $parentUsername,
                    'Student Report Updated',
                    'A teacher updated a report for your child.',
                    'dzongkha-classroom?tab=reports&as=parent'
                );
            }

            $flashType = 'success';
            $flashMsg = 'Report updated successfully.';
            $tab = 'reports';
        } catch (Throwable $e) {
            $flashType = 'danger';
            $flashMsg = 'Error: ' . $e->getMessage();
            $tab = 'reports';
        }
    }

    if ($action === 'teacher_delete_report' && $viewMode === 'teacher') {
        $reportId = (int)($_POST['report_id'] ?? 0);

        try {
            if ($reportId <= 0) throw new Exception('Invalid report.');

            $stmtOwn = $pdo->prepare("\n                SELECT id\n                FROM classroom_reports\n                WHERE id = :rid\n                  AND teacher_id = :tid\n                  AND class_id IN (" . ($teacherClassIds ? implode(',', $teacherClassIds) : '0') . ")\n                LIMIT 1\n            ");
            $stmtOwn->execute([':rid' => $reportId, ':tid' => $teacherId]);
            if (!$stmtOwn->fetch()) {
                throw new Exception('You can only delete reports created for your assigned classes.');
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM classroom_report_comments WHERE report_id = :rid")->execute([':rid' => $reportId]);
            $pdo->prepare("DELETE FROM classroom_reports WHERE id = :rid LIMIT 1")->execute([':rid' => $reportId]);
            $pdo->commit();

            if (function_exists('bbcc_audit_log')) {
                bbcc_audit_log('classroom_report_deleted', 'classroom_reports', [
                    'report_id' => $reportId,
                ], 'success');
            }

            $flashType = 'success';
            $flashMsg = 'Report deleted successfully.';
            $tab = 'reports';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flashType = 'danger';
            $flashMsg = 'Error: ' . $e->getMessage();
            $tab = 'reports';
        }
    }

    if ($action === 'mark_comments_read' && in_array($viewMode, ['admin', 'teacher'], true)) {
        $reportId = (int)($_POST['report_id'] ?? 0);

        try {
            if ($reportId <= 0) throw new Exception('Invalid report.');

            if ($viewMode === 'teacher') {
                $stmtOwn = $pdo->prepare("\n                    SELECT id\n                    FROM classroom_reports\n                    WHERE id = :rid\n                      AND class_id IN (" . ($teacherClassIds ? implode(',', $teacherClassIds) : '0') . ")\n                    LIMIT 1\n                ");
                $stmtOwn->execute([':rid' => $reportId]);
                if (!$stmtOwn->fetch()) {
                    throw new Exception('You can only update reports in your assigned classes.');
                }
                $pdo->prepare("\n                    UPDATE classroom_report_comments\n                    SET read_by_teacher_at = NOW()\n                    WHERE report_id = :rid\n                      AND read_by_teacher_at IS NULL\n                ")->execute([':rid' => $reportId]);
            } else {
                $stmtOwn = $pdo->prepare("SELECT id FROM classroom_reports WHERE id = :rid LIMIT 1");
                $stmtOwn->execute([':rid' => $reportId]);
                if (!$stmtOwn->fetch()) {
                    throw new Exception('Report not found.');
                }
                $pdo->prepare("\n                    UPDATE classroom_report_comments\n                    SET read_by_admin_at = NOW()\n                    WHERE report_id = :rid\n                      AND read_by_admin_at IS NULL\n                ")->execute([':rid' => $reportId]);
            }

            if (function_exists('bbcc_audit_log')) {
                bbcc_audit_log('classroom_comments_marked_read', 'classroom_report_comments', [
                    'report_id' => $reportId,
                    'role' => $viewMode,
                ], 'success');
            }

            $flashType = 'success';
            $flashMsg = 'Comments marked as read.';
            $tab = 'reports';
        } catch (Throwable $e) {
            $flashType = 'danger';
            $flashMsg = 'Error: ' . $e->getMessage();
            $tab = 'reports';
        }
    }
}

$visibleWhere = [];
if ($viewMode === 'admin') {
    $visibleWhere[] = '1=1';
} elseif ($viewMode === 'teacher') {
    $visibleWhere[] = "(a.scope_type = 'all_classes'";
    if ($teacherClassIds) {
        $inTeacher = implode(',', array_map('intval', $teacherClassIds));
        $visibleWhere[] = " OR EXISTS (SELECT 1 FROM classroom_announcement_classes ac2 WHERE ac2.announcement_id = a.id AND ac2.class_id IN ({$inTeacher}))";
    }
    $visibleWhere[] = ')';
} else {
    $visibleWhere[] = "(a.scope_type = 'all_classes'";
    if ($parentClassIds) {
        $inParent = implode(',', array_map('intval', $parentClassIds));
        $visibleWhere[] = " OR EXISTS (SELECT 1 FROM classroom_announcement_classes ac2 WHERE ac2.announcement_id = a.id AND ac2.class_id IN ({$inParent}))";
    }
    $visibleWhere[] = ')';
}

$sqlA = "\n    SELECT\n        a.id, a.title, a.message, a.category, a.scope_type, a.posted_by_name, a.posted_by_role, a.created_at,\n        CASE\n            WHEN a.scope_type = 'all_classes' THEN 'All Classes'\n            ELSE COALESCE(cls.class_list, 'Selected Classes')\n        END AS target_classes\n    FROM classroom_announcements a\n    LEFT JOIN (\n        SELECT ac.announcement_id, GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR ', ') AS class_list\n        FROM classroom_announcement_classes ac\n        INNER JOIN classes c ON c.id = ac.class_id\n        GROUP BY ac.announcement_id\n    ) cls ON cls.announcement_id = a.id\n    WHERE " . implode('', $visibleWhere) . "\n    ORDER BY a.created_at DESC, a.id DESC\n    LIMIT 300\n";
$stmtA = $pdo->prepare($sqlA);
$stmtA->execute();
$announcements = $stmtA->fetchAll();

$sqlR = "\n    SELECT\n        r.id, r.class_id, r.student_id, r.report_title, r.report_type, r.feedback_text, r.created_by_name, r.created_at,\n        s.student_name, s.student_id AS student_code, s.parentId,\n        c.class_name\n    FROM classroom_reports r\n    INNER JOIN students s ON s.id = r.student_id\n    LEFT JOIN classes c ON c.id = r.class_id\n";
$paramsR = [];
$whereR = [];

if ($viewMode === 'admin') {
    $whereR[] = '1=1';
} elseif ($viewMode === 'teacher') {
    $whereR[] = 'r.class_id IN (' . ($teacherClassIds ? implode(',', $teacherClassIds) : '0') . ')';
} else {
    $whereR[] = 's.parentId = :pid';
    $paramsR[':pid'] = $parentId;
}

$sqlR .= " WHERE " . implode(' AND ', $whereR) . " ORDER BY r.created_at DESC, r.id DESC LIMIT 400";
$stmtR = $pdo->prepare($sqlR);
$stmtR->execute($paramsR);
$reports = $stmtR->fetchAll();

$reportIds = array_map('intval', array_column($reports, 'id'));
$commentsByReport = [];
$unreadCountByReport = [];
if ($reportIds) {
    $sqlC = "\n        SELECT c.id, c.report_id, c.commenter_name, c.comment_text, c.created_at, c.read_by_teacher_at, c.read_by_admin_at\n        FROM classroom_report_comments c\n        WHERE c.report_id IN (" . implode(',', $reportIds) . ")\n        ORDER BY c.created_at ASC, c.id ASC\n    ";
    $rowsC = $pdo->query($sqlC)->fetchAll();
    foreach ($rowsC as $rowC) {
        $rid = (int)$rowC['report_id'];
        if (!isset($commentsByReport[$rid])) $commentsByReport[$rid] = [];
        $commentsByReport[$rid][] = $rowC;
        if (!isset($unreadCountByReport[$rid])) $unreadCountByReport[$rid] = 0;
        if ($viewMode === 'teacher' && empty($rowC['read_by_teacher_at'])) {
            $unreadCountByReport[$rid]++;
        }
        if ($viewMode === 'admin' && empty($rowC['read_by_admin_at'])) {
            $unreadCountByReport[$rid]++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Dzongkha Classroom</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
    <?php include 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'include/admin-header.php'; ?>
            <div class="container-fluid py-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h4 mb-0">Dzongkha Classroom</h1>
                    <span class="badge badge-light">
                        <?= $tab === 'announcements' ? count($announcements) . ' announcement(s)' : count($reports) . ' report(s)' ?>
                    </span>
                </div>

                <?php if ($flashMsg !== ''): ?>
                    <div class="alert alert-<?= dc_h($flashType !== '' ? $flashType : 'info') ?>"><?= dc_h($flashMsg) ?></div>
                <?php endif; ?>

                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item">
                        <a class="nav-link <?= $tab === 'announcements' ? 'active' : '' ?>" href="dzongkha-classroom?tab=announcements<?= dc_h($modeQuery) ?>">
                            <i class="fas fa-bullhorn mr-1"></i> Announcements
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $tab === 'reports' ? 'active' : '' ?>" href="dzongkha-classroom?tab=reports<?= dc_h($modeQuery) ?>">
                            <i class="fas fa-file-alt mr-1"></i> Student Reports
                        </a>
                    </li>
                </ul>
                <?php if ($hasTeacherProfile && $hasParentProfile && !$isAdmin): ?>
                    <div class="mb-3">
                        <a href="dzongkha-classroom?tab=<?= dc_h($tab) ?>&as=teacher"
                           class="btn btn-sm <?= $viewMode === 'teacher' ? 'btn-primary' : 'btn-outline-primary' ?>">
                            Teacher View
                        </a>
                        <a href="dzongkha-classroom?tab=<?= dc_h($tab) ?>&as=parent"
                           class="btn btn-sm <?= $viewMode === 'parent' ? 'btn-primary' : 'btn-outline-primary' ?>">
                            Parent View
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'announcements'): ?>
                    <?php if (in_array($viewMode, ['admin', 'teacher'], true)): ?>
                        <div class="card shadow mb-3">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Post Announcement / Instruction</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="create_announcement">

                                    <div class="form-row">
                                        <div class="form-group col-md-5">
                                            <label>Title</label>
                                            <input type="text" name="title" class="form-control" maxlength="200" required>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label>Category</label>
                                            <select name="category" class="form-control">
                                                <option value="Announcement">Announcement</option>
                                                <option value="Instruction">Instruction</option>
                                                <option value="General">General</option>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Target Scope</label>
                                            <select name="scope_type" id="scopeType" class="form-control">
                                                <?php if ($viewMode === 'admin'): ?>
                                                    <option value="all_classes">All Classes</option>
                                                    <option value="selected_classes">Selected Classes</option>
                                                <?php else: ?>
                                                    <option value="all_my_classes">All My Classes</option>
                                                    <option value="selected_classes">Selected My Classes</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group" id="classPickerWrap" style="display:none;">
                                        <label>Select Class<?= $viewMode === 'teacher' ? ' (Assigned only)' : '' ?></label>
                                        <div class="border rounded p-2" style="max-height:220px;overflow:auto;">
                                            <?php $classList = $viewMode === 'admin' ? $allClasses : $teacherClasses; ?>
                                            <?php if (empty($classList)): ?>
                                                <div class="text-muted">No classes available.</div>
                                            <?php else: ?>
                                                <?php foreach ($classList as $cl): ?>
                                                    <div class="custom-control custom-checkbox mb-1">
                                                        <input type="checkbox" class="custom-control-input" id="cl<?= (int)$cl['id'] ?>" name="class_ids[]" value="<?= (int)$cl['id'] ?>">
                                                        <label class="custom-control-label" for="cl<?= (int)$cl['id'] ?>"><?= dc_h((string)$cl['class_name']) ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Message</label>
                                        <textarea name="message" rows="5" class="form-control" required></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane mr-1"></i> Post
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Announcements</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($announcements)): ?>
                                <div class="text-muted">No announcements found for your class scope.</div>
                            <?php else: ?>
                                <?php foreach ($announcements as $a): ?>
                                    <div class="border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap">
                                            <div>
                                                <div class="font-weight-bold" style="font-size:1.02rem;"><?= dc_h((string)$a['title']) ?></div>
                                                <div class="mt-1">
                                                    <span class="badge badge-primary"><?= dc_h((string)$a['category']) ?></span>
                                                    <span class="badge badge-light ml-1"><?= dc_h((string)$a['target_classes']) ?></span>
                                                </div>
                                            </div>
                                            <div class="text-muted small mt-2 mt-sm-0 text-sm-right">
                                                <div><strong>Posted by:</strong> <?= dc_h((string)($a['posted_by_name'] ?: 'Account')) ?></div>
                                                <div><?= dc_h((string)$a['posted_by_role']) ?></div>
                                                <div><?= dc_h((string)$a['created_at']) ?></div>
                                            </div>
                                        </div>
                                        <div class="mt-3" style="white-space:pre-wrap;"><?= dc_h((string)$a['message']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if ($viewMode === 'teacher'): ?>
                        <div class="card shadow mb-3">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Create Student Report / Feedback</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="create_report">

                                    <div class="form-row">
                                        <div class="form-group col-md-4">
                                            <label>Class</label>
                                            <select name="class_id" id="reportClass" class="form-control" required>
                                                <option value="">-- Select Class --</option>
                                                <?php foreach ($teacherClasses as $cl): ?>
                                                    <option value="<?= (int)$cl['id'] ?>"><?= dc_h((string)$cl['class_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Student</label>
                                            <select name="student_id" id="reportStudent" class="form-control" required>
                                                <option value="">-- Select Student --</option>
                                                <?php foreach ($teacherStudents as $st): ?>
                                                    <option value="<?= (int)$st['student_id_pk'] ?>" data-class-id="<?= (int)$st['class_id'] ?>">
                                                        <?= dc_h((string)$st['student_name']) ?> (<?= dc_h((string)$st['student_id']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Report Type</label>
                                            <select name="report_type" class="form-control">
                                                <option value="Progress">Progress</option>
                                                <option value="Behavior">Behavior</option>
                                                <option value="Homework">Homework</option>
                                                <option value="General">General</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Report Title</label>
                                        <input type="text" name="report_title" class="form-control" maxlength="200" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Feedback / Comment</label>
                                        <textarea name="feedback_text" rows="5" class="form-control" required></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-1"></i> Save Report
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Student Reports</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($reports)): ?>
                                <div class="text-muted">No reports available in your scope.</div>
                            <?php else: ?>
                                <?php foreach ($reports as $r): ?>
                                    <div class="border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap">
                                            <div>
                                                <div class="font-weight-bold" style="font-size:1.02rem;"><?= dc_h((string)$r['report_title']) ?></div>
                                                <div class="mt-1">
                                                    <span class="badge badge-info"><?= dc_h((string)$r['report_type']) ?></span>
                                                    <span class="badge badge-light ml-1"><?= dc_h((string)($r['class_name'] ?? '-')) ?></span>
                                                </div>
                                                <div class="small text-muted mt-2">
                                                    <strong>Child:</strong> <?= dc_h((string)$r['student_name']) ?>
                                                    (<?= dc_h((string)$r['student_code']) ?>)
                                                </div>
                                            </div>
                                            <div class="text-muted small mt-2 mt-sm-0 text-sm-right">
                                                <div><strong>By:</strong> <?= dc_h((string)($r['created_by_name'] ?: 'Teacher')) ?></div>
                                                <div><?= dc_h((string)$r['created_at']) ?></div>
                                            </div>
                                        </div>

                                        <div class="mt-3" style="white-space:pre-wrap;"><?= dc_h((string)$r['feedback_text']) ?></div>

                                        <?php if ($viewMode === 'teacher' && (int)$r['class_id'] > 0 && in_array((int)$r['class_id'], $teacherClassIds, true)): ?>
                                            <div class="mt-3 d-flex flex-wrap" style="gap:.5rem;">
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary js-edit-report"
                                                        data-report-id="<?= (int)$r['id'] ?>"
                                                        data-report-type="<?= dc_h((string)$r['report_type']) ?>"
                                                        data-report-title="<?= dc_h((string)$r['report_title']) ?>"
                                                        data-feedback-text="<?= dc_h((string)$r['feedback_text']) ?>">
                                                    <i class="fas fa-edit mr-1"></i> Edit
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this report and all comments?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="teacher_delete_report">
                                                    <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash mr-1"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>

                                        <?php $rc = $commentsByReport[(int)$r['id']] ?? []; ?>
                                        <?php $unreadCount = (int)($unreadCountByReport[(int)$r['id']] ?? 0); ?>
                                        <div class="mt-3 p-2 rounded" style="background:#f8f9fc;">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                                                <div class="font-weight-bold" style="font-size:.9rem;">
                                                    Parent Comments
                                                    <?php if (in_array($viewMode, ['admin', 'teacher'], true) && $unreadCount > 0): ?>
                                                        <span class="badge badge-danger ml-1"><?= (int)$unreadCount ?> unread</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (in_array($viewMode, ['admin', 'teacher'], true) && $unreadCount > 0): ?>
                                                    <form method="POST" class="mt-2 mt-sm-0">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="mark_comments_read">
                                                        <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                            Mark Read
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (empty($rc)): ?>
                                                <div class="text-muted small">No comments yet.</div>
                                            <?php else: ?>
                                                <?php foreach ($rc as $c): ?>
                                                    <?php
                                                        $isUnreadForViewer = false;
                                                        if ($viewMode === 'teacher' && empty($c['read_by_teacher_at'])) $isUnreadForViewer = true;
                                                        if ($viewMode === 'admin' && empty($c['read_by_admin_at'])) $isUnreadForViewer = true;
                                                    ?>
                                                    <div class="border rounded p-2 mb-2" style="background:#fff;">
                                                        <div class="small text-muted">
                                                            <?= dc_h((string)($c['commenter_name'] ?: 'Parent')) ?> • <?= dc_h((string)$c['created_at']) ?>
                                                            <?php if (in_array($viewMode, ['admin', 'teacher'], true) && $isUnreadForViewer): ?>
                                                                <span class="badge badge-warning ml-1">New</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div style="white-space:pre-wrap;"><?= dc_h((string)$c['comment_text']) ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($viewMode === 'parent'): ?>
                                            <form method="POST" class="mt-3">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="parent_comment">
                                                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                                <div class="form-group mb-2">
                                                    <label class="small font-weight-bold mb-1">Add Your Comment</label>
                                                    <textarea name="comment_text" rows="2" class="form-control" required></textarea>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-comment mr-1"></i> Submit Comment
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>

<div class="modal fade" id="editReportModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="teacher_update_report">
            <input type="hidden" name="report_id" id="editReportId" value="">
            <div class="modal-header">
                <h5 class="modal-title">Edit Student Report</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group col-md-5">
                        <label>Report Type</label>
                        <select name="report_type" id="editReportType" class="form-control">
                            <option value="Progress">Progress</option>
                            <option value="Behavior">Behavior</option>
                            <option value="Homework">Homework</option>
                            <option value="General">General</option>
                        </select>
                    </div>
                    <div class="form-group col-md-7">
                        <label>Report Title</label>
                        <input type="text" name="report_title" id="editReportTitle" class="form-control" maxlength="200" required>
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label>Feedback / Comment</label>
                    <textarea name="feedback_text" id="editFeedbackText" rows="6" class="form-control" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Update Report
                </button>
            </div>
        </form>
    </div>
</div>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
$(function () {
    function refreshClassPicker() {
        var val = $('#scopeType').val();
        $('#classPickerWrap').toggle(val === 'selected_classes');
    }
    refreshClassPicker();
    $('#scopeType').on('change', refreshClassPicker);

    function filterStudentsByClass() {
        var classId = $('#reportClass').val();
        var $student = $('#reportStudent');
        $student.val('');
        $student.find('option').each(function () {
            var cls = $(this).data('class-id');
            if (!cls) return;
            if (!classId || String(cls) === String(classId)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }
    $('#reportClass').on('change', filterStudentsByClass);

    $('.js-edit-report').on('click', function () {
        var $btn = $(this);
        $('#editReportId').val($btn.data('report-id') || '');
        $('#editReportType').val($btn.data('report-type') || 'Progress');
        $('#editReportTitle').val($btn.data('report-title') || '');
        $('#editFeedbackText').val($btn.data('feedback-text') || '');
        $('#editReportModal').modal('show');
    });
});
</script>
</body>
</html>
