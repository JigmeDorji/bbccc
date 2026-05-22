<?php
require_once "include/config.php";
require_once "include/image_helpers.php";
require_once "include/blcs_schedule.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_login();
if (!is_admin_role() && !is_website_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$message = "";
$msgType = "success";
$existing_description = "";
$default_school_img = "bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png";
$existing_imgUrl = $default_school_img;
$existing_students_count = "80+";
$existing_teachers_count = "8";
$existing_campuses_count = "2";
$existing_year_levels = "Age 6 years and above";
$existing_stats_heading = "BLCS Snapshot - Term 1, 2026";
$default_tara_img = "bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png";
$existing_tara_title = "Droenchoe (Tara) Practice";
$existing_tara_subtitle = "A deeply spiritual journey for all practitioners";
$existing_tara_intro = "Under the blessing and guidance of His Eminence Leytshog Lopen Rinpoche, the Bhutanese Centre offers Droenchoe (Tara) Practice classes for all practitioners.";
$existing_tara_body = "If you are interested and wanting to take your first step, we warmly welcome you to join this deeply spiritual journey.\n\nWe warmly welcome anyone wishing to learn and deepen their practice.";
$existing_tara_schedule = "Classes are held every Saturday from 5:00 PM to 8:00 PM.";
$existing_tara_monthly = "We also conduct monthly Droenchoe practice sessions.";
$existing_tara_contact = "Contact Khenpo Sonam at 0434 522 720 or visit the Bhutanese Centre.";
$existing_tara_imgUrl = $default_tara_img;
$blcsSchedule = [
    'intro_text' => bbcc_blcs_default_intro_text(),
    'terms_text' => bbcc_blcs_default_terms_text(),
    'sunday_dates_text' => bbcc_blcs_default_sunday_dates_text(),
    'page_text' => bbcc_blcs_default_page_text(),
    'highlight_text' => bbcc_blcs_default_highlight_text(),
];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['school_setup_flash']) && is_array($_SESSION['school_setup_flash'])) {
    $message = (string)($_SESSION['school_setup_flash']['message'] ?? '');
    $msgType = (string)($_SESSION['school_setup_flash']['type'] ?? 'success');
    unset($_SESSION['school_setup_flash']);
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $stmt = $pdo->prepare("SELECT * FROM school_content ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $existing_description = (string)($row['description'] ?? '');
        $existing_imgUrl = (string)($row['imgUrl'] ?? '');
        $existing_students_count = trim((string)($row['students_count'] ?? '')) !== '' ? (string)$row['students_count'] : $existing_students_count;
        $existing_teachers_count = trim((string)($row['teachers_count'] ?? '')) !== '' ? (string)$row['teachers_count'] : $existing_teachers_count;
        $existing_campuses_count = trim((string)($row['campuses_count'] ?? '')) !== '' ? (string)$row['campuses_count'] : $existing_campuses_count;
        $existing_year_levels = trim((string)($row['year_levels'] ?? '')) !== '' ? (string)$row['year_levels'] : $existing_year_levels;
        $existing_stats_heading = trim((string)($row['stats_heading'] ?? '')) !== '' ? (string)$row['stats_heading'] : $existing_stats_heading;
        if ($existing_imgUrl === '') {
            $existing_imgUrl = $default_school_img;
        }
    }
    $taraStmt = $pdo->prepare("SELECT * FROM tara_content ORDER BY id DESC LIMIT 1");
    $taraStmt->execute();
    $taraRow = $taraStmt->fetch(PDO::FETCH_ASSOC);
    if ($taraRow) {
        $existing_tara_title = trim((string)($taraRow['title'] ?? '')) !== '' ? (string)$taraRow['title'] : $existing_tara_title;
        $existing_tara_subtitle = trim((string)($taraRow['subtitle'] ?? '')) !== '' ? (string)$taraRow['subtitle'] : $existing_tara_subtitle;
        $existing_tara_intro = trim((string)($taraRow['intro_text'] ?? '')) !== '' ? (string)$taraRow['intro_text'] : $existing_tara_intro;
        $existing_tara_body = trim((string)($taraRow['body_text'] ?? '')) !== '' ? (string)$taraRow['body_text'] : $existing_tara_body;
        $existing_tara_schedule = trim((string)($taraRow['schedule_text'] ?? '')) !== '' ? (string)$taraRow['schedule_text'] : $existing_tara_schedule;
        $existing_tara_monthly = trim((string)($taraRow['monthly_text'] ?? '')) !== '' ? (string)$taraRow['monthly_text'] : $existing_tara_monthly;
        $existing_tara_contact = trim((string)($taraRow['contact_text'] ?? '')) !== '' ? (string)$taraRow['contact_text'] : $existing_tara_contact;
        $existing_tara_imgUrl = trim((string)($taraRow['imgUrl'] ?? '')) !== '' ? (string)$taraRow['imgUrl'] : $existing_tara_imgUrl;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $action = trim((string)($_POST['action'] ?? 'update_school_content'));
        if ($action === 'update_blcs_schedule') {
            $introText = trim((string)($_POST['blcs_intro_text'] ?? ''));
            $termsText = trim((string)($_POST['blcs_terms_text'] ?? ''));
            $datesText = trim((string)($_POST['blcs_dates_text'] ?? ''));
            $pageText = trim((string)($_POST['blcs_page_text'] ?? ''));
            $highlightText = trim((string)($_POST['blcs_highlight_text'] ?? ''));

            if ($introText === '') $introText = bbcc_blcs_default_intro_text();
            if ($termsText === '') $termsText = bbcc_blcs_default_terms_text();
            if ($datesText === '') $datesText = bbcc_blcs_default_sunday_dates_text();
            if ($pageText === '') $pageText = bbcc_blcs_default_page_text();
            if ($highlightText === '') $highlightText = bbcc_blcs_default_highlight_text();

            bbcc_blcs_ensure_schedule_table($pdo);
            $stmtUp = $pdo->prepare("
                INSERT INTO blcs_schedule_settings (id, intro_text, terms_text, sunday_dates_text, page_text, highlight_text, updated_by)
                VALUES (1, :intro, :terms, :dates, :page_text, :highlight_text, :updated_by)
                ON DUPLICATE KEY UPDATE
                    intro_text = VALUES(intro_text),
                    terms_text = VALUES(terms_text),
                    sunday_dates_text = VALUES(sunday_dates_text),
                    page_text = VALUES(page_text),
                    highlight_text = VALUES(highlight_text),
                    updated_by = VALUES(updated_by)
            ");
            $stmtUp->execute([
                ':intro' => $introText,
                ':terms' => $termsText,
                ':dates' => $datesText,
                ':page_text' => $pageText,
                ':highlight_text' => $highlightText,
                ':updated_by' => (string)($_SESSION['username'] ?? 'admin'),
            ]);
            $_SESSION['school_setup_flash'] = [
                'type' => 'success',
                'message' => 'BLCS schedule updated successfully.',
            ];
            header("Location: schoolContentSetup");
            exit;
        }
        if ($action === 'update_tara_content') {
            $taraTitle = trim((string)($_POST['tara_title'] ?? ''));
            $taraSubtitle = trim((string)($_POST['tara_subtitle'] ?? ''));
            $taraIntro = trim((string)($_POST['tara_intro_text'] ?? ''));
            $taraBody = trim((string)($_POST['tara_body_text'] ?? ''));
            $taraSchedule = trim((string)($_POST['tara_schedule_text'] ?? ''));
            $taraMonthly = trim((string)($_POST['tara_monthly_text'] ?? ''));
            $taraContact = trim((string)($_POST['tara_contact_text'] ?? ''));
            $taraImgUrl = $existing_tara_imgUrl;

            if ($taraTitle === '') $taraTitle = $existing_tara_title;
            if ($taraSubtitle === '') $taraSubtitle = $existing_tara_subtitle;
            if ($taraIntro === '') $taraIntro = $existing_tara_intro;
            if ($taraBody === '') $taraBody = $existing_tara_body;
            if ($taraSchedule === '') $taraSchedule = $existing_tara_schedule;
            if ($taraMonthly === '') $taraMonthly = $existing_tara_monthly;
            if ($taraContact === '') $taraContact = $existing_tara_contact;

            if (isset($_FILES['tara_image']) && (int)($_FILES['tara_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $image_name = (string)$_FILES['tara_image']['name'];
                $image_size = (int)$_FILES['tara_image']['size'];
                $image_tmp = (string)$_FILES['tara_image']['tmp_name'];

                if ($image_size > 5242880) throw new Exception("File too large. Max 5MB.");
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $ext = strtolower((string)pathinfo($image_name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) throw new Exception("Only JPG, JPEG, PNG, GIF, WEBP allowed.");

                $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $image_name);
                $uploadDir = __DIR__ . "/uploads/tara";
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0775, true);
                }
                if (!is_dir($uploadDir)) throw new Exception("Upload folder is not available.");

                $uploadAbs = $uploadDir . "/" . $safeName;
                if (!move_uploaded_file($image_tmp, $uploadAbs)) throw new Exception("Failed to upload image.");
                bbcc_generate_responsive_variants($uploadAbs, [480, 768, 1200], 82);
                $taraImgUrl = "uploads/tara/" . $safeName;
            }

            $taraUp = $pdo->prepare("
                INSERT INTO tara_content (title, subtitle, intro_text, body_text, schedule_text, monthly_text, contact_text, imgUrl)
                VALUES (:title, :subtitle, :intro_text, :body_text, :schedule_text, :monthly_text, :contact_text, :imgUrl)
            ");
            $taraUp->execute([
                ':title' => $taraTitle,
                ':subtitle' => $taraSubtitle,
                ':intro_text' => $taraIntro,
                ':body_text' => $taraBody,
                ':schedule_text' => $taraSchedule,
                ':monthly_text' => $taraMonthly,
                ':contact_text' => $taraContact,
                ':imgUrl' => $taraImgUrl,
            ]);

            $_SESSION['school_setup_flash'] = [
                'type' => 'success',
                'message' => 'Droenchoe (Tara) content updated successfully.',
            ];
            header("Location: schoolContentSetup");
            exit;
        }

        $description = trim((string)($_POST['description'] ?? ''));
        $studentsCount = trim((string)($_POST['students_count'] ?? ''));
        $teachersCount = trim((string)($_POST['teachers_count'] ?? ''));
        $campusesCount = trim((string)($_POST['campuses_count'] ?? ''));
        $yearLevels = trim((string)($_POST['year_levels'] ?? ''));
        $statsHeading = trim((string)($_POST['stats_heading'] ?? ''));
        $imgUrl = $existing_imgUrl;
        if ($studentsCount === '') $studentsCount = $existing_students_count;
        if ($teachersCount === '') $teachersCount = $existing_teachers_count;
        if ($campusesCount === '') $campusesCount = $existing_campuses_count;
        if ($yearLevels === '') $yearLevels = $existing_year_levels;
        if ($statsHeading === '') $statsHeading = $existing_stats_heading;

        if (isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $image_name = (string)$_FILES['image']['name'];
            $image_size = (int)$_FILES['image']['size'];
            $image_tmp = (string)$_FILES['image']['tmp_name'];

            if ($image_size > 5242880) throw new Exception("File too large. Max 5MB.");
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower((string)pathinfo($image_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) throw new Exception("Only JPG, JPEG, PNG, GIF, WEBP allowed.");

            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $image_name);
            $uploadDir = __DIR__ . "/uploads/school";
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }
            if (!is_dir($uploadDir)) throw new Exception("Upload folder is not available.");

            $uploadAbs = $uploadDir . "/" . $safeName;
            if (!move_uploaded_file($image_tmp, $uploadAbs)) throw new Exception("Failed to upload image.");
            bbcc_generate_responsive_variants($uploadAbs, [480, 768, 1200], 82);
            $imgUrl = "uploads/school/" . $safeName;
        }

        $stmt = $pdo->prepare("INSERT INTO school_content (description, imgUrl, students_count, teachers_count, campuses_count, year_levels, stats_heading) VALUES (:description, :imgUrl, :students_count, :teachers_count, :campuses_count, :year_levels, :stats_heading)");
        $stmt->execute([
            ':description' => $description,
            ':imgUrl' => $imgUrl,
            ':students_count' => $studentsCount,
            ':teachers_count' => $teachersCount,
            ':campuses_count' => $campusesCount,
            ':year_levels' => $yearLevels,
            ':stats_heading' => $statsHeading,
        ]);

        $_SESSION['school_setup_flash'] = [
            'type' => 'success',
            'message' => 'School content updated successfully.',
        ];
        header("Location: schoolContentSetup");
        exit;
    }

    $blcsSchedule = bbcc_blcs_load_schedule($pdo);
} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = "error";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>School Content Setup</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .setup-modal .modal-dialog { max-width: 680px; }
        .setup-modal .modal-content { border:none; border-radius:12px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.15); }
        .setup-modal .modal-header { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); color:#fff; border-bottom:none; padding:1.25rem 1.5rem; }
        .setup-modal .modal-header .modal-title { font-weight:700; font-size:1.1rem; display:flex; align-items:center; gap:8px; }
        .setup-modal .modal-header .btn-close-modal { color:#fff; opacity:.85; font-size:1.4rem; background:none; border:none; cursor:pointer; transition:opacity .2s; }
        .setup-modal .modal-header .btn-close-modal:hover { opacity:1; }
        .setup-modal .modal-body { padding:1.75rem 1.5rem 1rem; background:#f8f9fc; }
        .setup-modal .modal-body .form-group { margin-bottom:1rem; }
        .setup-modal .modal-body label { font-weight:600; font-size:.82rem; text-transform:uppercase; letter-spacing:.4px; color:#5a5c69; margin-bottom:.3rem; }
        .setup-modal .modal-body .form-control { border-radius:8px; border:1px solid #d1d3e2; padding:.55rem .85rem; font-size:.9rem; }
        .setup-modal .modal-body textarea.form-control { resize:vertical; min-height:120px; }
        .setup-modal .modal-body .section-divider { font-size:.75rem; text-transform:uppercase; letter-spacing:1px; font-weight:700; color:#b7b9cc; margin:.75rem 0 .5rem; padding-bottom:.35rem; border-bottom:1px solid #e3e6f0; }
        .setup-modal .modal-footer { background:#fff; border-top:1px solid #e3e6f0; padding:1rem 1.5rem; }
        .setup-modal .btn-save-update { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; border-radius:8px; font-weight:600; }
        .setup-modal .btn-cancel-modal { background:#e3e6f0; color:#5a5c69; border:none; border-radius:8px; font-weight:600; }
        .preview-card { background:#fff; border-radius:12px; padding:1.5rem; box-shadow:0 2px 12px rgba(0,0,0,.06); }
        .preview-card img { max-width:260px; border-radius:10px; border:2px solid #e3e6f0; margin-bottom:1rem; }
        .btn-edit { background:linear-gradient(135deg,#4e73df 0%,#224abe 100%); border:none; color:#fff; border-radius:8px; font-weight:600; padding:.5rem 1.3rem; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
<?php include_once 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include_once 'include/admin-header.php'; ?>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">School Content Setup</h1>
        <button type="button" class="btn btn-edit" id="btnEditSchool">
            <i class="fas fa-edit mr-1"></i> Edit School Content
        </button>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-eye mr-1"></i> Current School Content</h6>
        </div>
        <div class="card-body">
            <div class="preview-card">
                <?php if ($existing_imgUrl): ?>
                    <img src="<?= htmlspecialchars($existing_imgUrl) ?>" alt="School Image">
                <?php endif; ?>
                <?php if ($existing_description): ?>
                    <div class="text-muted" style="white-space:pre-wrap;line-height:1.8;"><?= htmlspecialchars($existing_description) ?></div>
                <?php else: ?>
                    <p class="text-muted mb-0">No school content set yet.</p>
                <?php endif; ?>
                <hr>
                <p class="text-muted mb-2"><strong><?= htmlspecialchars($existing_stats_heading) ?></strong></p>
                <div class="row text-center">
                    <div class="col-md-3 col-6 mb-2"><strong><?= htmlspecialchars($existing_students_count) ?></strong><div class="small text-muted">Students</div></div>
                    <div class="col-md-3 col-6 mb-2"><strong><?= htmlspecialchars($existing_teachers_count) ?></strong><div class="small text-muted">Teachers</div></div>
                    <div class="col-md-3 col-6 mb-2"><strong><?= htmlspecialchars($existing_campuses_count) ?></strong><div class="small text-muted">Campuses</div></div>
                    <div class="col-md-3 col-12 mb-2"><strong><?= htmlspecialchars($existing_year_levels) ?></strong><div class="small text-muted">Age Group</div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-calendar-alt mr-1"></i> BLCS Schedule Settings</h6>
            <small class="text-muted">Shown on Bhutanese Language and Culture School page</small>
        </div>
        <div class="card-body">
            <form method="POST" action="schoolContentSetup">
                <input type="hidden" name="action" value="update_blcs_schedule">
                <div class="form-group">
                    <label>BLCS Highlight Text</label>
                    <textarea name="blcs_highlight_text" rows="3" class="form-control"><?= htmlspecialchars((string)$blcsSchedule['highlight_text']) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Intro Line</label>
                    <input type="text" name="blcs_intro_text" class="form-control" value="<?= htmlspecialchars((string)$blcsSchedule['intro_text']) ?>">
                </div>
                <div class="form-row">
                    <div class="form-group col-12">
                        <label>BLCS Page Main Text</label>
                        <textarea name="blcs_page_text" rows="14" class="form-control"><?= htmlspecialchars((string)$blcsSchedule['page_text']) ?></textarea>
                        <small class="text-muted d-block">Formatting options:</small>
                        <small class="text-muted d-block">`## Heading` for headings, `- item` or `• item` for bullet lists, `1. item` for numbered lists.</small>
                        <small class="text-muted">Blank lines create paragraph breaks.</small>
                    </div>
                    <div class="form-group col-lg-6">
                        <label>School Terms (one line each)</label>
                        <textarea name="blcs_terms_text" rows="8" class="form-control"><?= htmlspecialchars((string)$blcsSchedule['terms_text']) ?></textarea>
                    </div>
                    <div class="form-group col-lg-6">
                        <label>Sunday Dates (one line each)</label>
                        <textarea name="blcs_dates_text" rows="8" class="form-control"><?= htmlspecialchars((string)$blcsSchedule['sunday_dates_text']) ?></textarea>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save BLCS Schedule</button>
            </form>
        </div>
    </div>

</div>

<div class="modal fade setup-modal" id="schoolModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-school"></i> Edit School Content</h5>
                <button type="button" class="btn-close-modal" data-dismiss="modal" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" action="schoolContentSetup" enctype="multipart/form-data" id="schoolForm">
                <input type="hidden" name="action" value="update_school_content">
                <div class="modal-body">
                    <div class="section-divider">Description</div>
                    <div class="form-group">
                        <label>School Description</label>
                        <textarea name="description" class="form-control" rows="8" placeholder="Write school section content..."><?= htmlspecialchars($existing_description) ?></textarea>
                    </div>
                    <div class="section-divider">School Panel Statistics</div>
                    <div class="form-group">
                        <label>Stats Heading (Term/Year)</label>
                        <input type="text" name="stats_heading" class="form-control" value="<?= htmlspecialchars($existing_stats_heading) ?>" placeholder="e.g. BLCS Snapshot - Term 2, 2026">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-3 col-6">
                            <label>Students</label>
                            <input type="text" name="students_count" class="form-control" value="<?= htmlspecialchars($existing_students_count) ?>" placeholder="e.g. 80+">
                        </div>
                        <div class="form-group col-md-3 col-6">
                            <label>Teachers</label>
                            <input type="text" name="teachers_count" class="form-control" value="<?= htmlspecialchars($existing_teachers_count) ?>" placeholder="e.g. 8">
                        </div>
                        <div class="form-group col-md-3 col-6">
                            <label>Campuses</label>
                            <input type="text" name="campuses_count" class="form-control" value="<?= htmlspecialchars($existing_campuses_count) ?>" placeholder="e.g. 2">
                        </div>
                        <div class="form-group col-md-3 col-6">
                            <label>Age Group</label>
                            <input type="text" name="year_levels" class="form-control" value="<?= htmlspecialchars($existing_year_levels) ?>" placeholder="e.g. Age 6 years and above">
                        </div>
                    </div>
                    <div class="section-divider">School Image</div>
                    <?php if ($existing_imgUrl): ?>
                    <div class="form-group">
                        <label>Current Image</label><br>
                        <img src="<?= htmlspecialchars($existing_imgUrl) ?>" style="max-width:200px;border-radius:8px;border:2px solid #e3e6f0;">
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Upload New Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <small class="text-muted">Max 5MB. JPG, PNG, GIF, WEBP.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel-modal" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
                    <button type="submit" class="btn btn-save-update" id="schoolSubmitBtn"><i class="fas fa-save mr-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

</div>
<?php include_once 'include/admin-footer.php'; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
<script>
$(document).ready(function(){
    $('#btnEditSchool').on('click', function(){ $('#schoolModal').modal('show'); });
    $('#schoolForm').on('submit', function(){
        $('#schoolSubmitBtn').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
    });
});
<?php if ($message): ?>
Swal.fire({ icon:'<?= $msgType ?>', title:'<?= addslashes($message) ?>', showConfirmButton:false, timer:1800 });
<?php endif; ?>
</script>
</body>
</html>
