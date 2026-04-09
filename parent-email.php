<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/mail_queue.php";
require_login();

function pe_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function pe_pretty_name_from_username(string $username): string {
    $base = trim($username);
    if ($base === '') return 'Account User';
    if (strpos($base, '@') !== false) {
        $base = strstr($base, '@', true) ?: $base;
    }
    $base = str_replace(['.', '_', '-'], ' ', $base);
    $base = preg_replace('/\s+/', ' ', $base) ?? $base;
    $base = trim($base);
    if ($base === '') return 'Account User';
    return ucwords(strtolower($base));
}

function pe_presets(): array {
    return [
        'class_update' => [
            'label' => 'Class Update',
            'subject' => 'Class Update - Bhutanese Language and Culture School',
            'body' => "Dear {PARENT_NAME},\n\nThis is a class update from Bhutanese Language and Culture School.\nPlease check your child portal for recent announcements, schedule updates, and upcoming learning activities.\n\nThank you for your continued support.",
        ],
        'fee_reminder' => [
            'label' => 'Fee Reminder',
            'subject' => 'Fee Reminder - Bhutanese Language and Culture School',
            'body' => "Dear {PARENT_NAME},\n\nThis is a gentle reminder regarding your child enrollment fee.\nPlease complete the payment in the parent portal and upload proof of payment to finalize the process.\n\nIf payment is already completed, please ignore this message.",
        ],
        'holiday_notice' => [
            'label' => 'Holiday Notice',
            'subject' => 'Holiday Notice - Bhutanese Language and Culture School',
            'body' => "Dear {PARENT_NAME},\n\nPlease note that classes will be closed for the upcoming holiday period.\nClasses will resume as per the school calendar published in the portal.\n\nWe wish your family a safe and peaceful holiday.",
        ],
    ];
}

function pe_apply_tokens(string $text, string $recipientName): string {
    $name = trim($recipientName) !== '' ? $recipientName : 'Parent';
    return strtr($text, [
        '{PARENT_NAME}' => $name,
        '{SCHOOL_NAME}' => 'Bhutanese Language and Culture School',
    ]);
}

function pe_build_email_html(string $recipientName, string $subject, string $body, string $senderName): string {
    $safeRecipient = pe_h($recipientName !== '' ? $recipientName : 'Parent');
    $safeSubject = pe_h($subject);
    $safeBody = nl2br(pe_h($body));
    $safeSender = pe_h($senderName);

    return '
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
          <tr>
            <td style="background:#881b12;padding:18px 24px;color:#ffffff;">
              <div style="font-size:13px;opacity:.9;letter-spacing:.04em;text-transform:uppercase;">Bhutanese Language and Culture School</div>
              <div style="font-size:22px;font-weight:bold;line-height:1.2;margin-top:6px;">Parent Communication</div>
            </td>
          </tr>
          <tr>
            <td style="padding:22px 24px 10px 24px;">
              <div style="font-size:13px;color:#6b7280;margin-bottom:8px;">Subject</div>
              <div style="font-size:20px;font-weight:bold;color:#111827;line-height:1.3;">' . $safeSubject . '</div>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 24px 10px 24px;font-size:15px;line-height:1.7;color:#1f2937;">
              <p style="margin:0 0 14px 0;">Dear ' . $safeRecipient . ',</p>
              <p style="margin:0 0 14px 0;">' . $safeBody . '</p>
              <p style="margin:0;">Regards,<br>' . $safeSender . '<br>Bhutanese Language and Culture School</p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 24px 22px 24px;">
              <div style="height:1px;background:#e5e7eb;margin:0 0 10px 0;"></div>
              <div style="font-size:12px;color:#6b7280;line-height:1.5;">
                This is an official communication from Bhutanese Language and Culture School.
                Please do not reply to this email if sent from a no-reply address.
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
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

$sessionUsername = trim((string)($_SESSION['username'] ?? ''));
$senderDisplayName = pe_pretty_name_from_username($sessionUsername);

$isAdmin = is_admin_role();
$teacherId = 0;
$teacherName = '';

if (!$isAdmin) {
    $sessionUserId = (string)($_SESSION['userid'] ?? '');
    $sessionUsername = (string)($_SESSION['username'] ?? '');
    $stmtTeacher = $pdo->prepare("
        SELECT id, full_name
        FROM teachers
        WHERE (user_id = :uid AND :uid <> '')
           OR LOWER(email) = LOWER(:em)
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmtTeacher->execute([':uid' => $sessionUserId, ':em' => $sessionUsername]);
    $teacherRow = $stmtTeacher->fetch(PDO::FETCH_ASSOC) ?: null;
    $teacherId = (int)($teacherRow['id'] ?? 0);
    $teacherName = trim((string)($teacherRow['full_name'] ?? ''));
    if ($teacherName !== '') {
        $senderDisplayName = $teacherName;
    }

    if ($teacherId <= 0) {
        header("Location: unauthorized");
        exit;
    }
}

$teacherClasses = [];
$classId = 0;
$parents = [];

if (!$isAdmin) {
    $stmtClasses = $pdo->prepare("
        SELECT id, class_name
        FROM classes
        WHERE teacher_id = :tid
        ORDER BY class_name ASC
    ");
    $stmtClasses->execute([':tid' => $teacherId]);
    $teacherClasses = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);

    $classId = (int)($_GET['class_id'] ?? ($_POST['class_id'] ?? 0));
    if ($classId > 0) {
        $allowed = array_map('intval', array_column($teacherClasses, 'id'));
        if (!in_array($classId, $allowed, true)) {
            $classId = 0;
        }
    }
}

if ($isAdmin) {
    $parents = $pdo->query("
        SELECT id, full_name, email, '' AS classes_csv
        FROM parents
        WHERE email IS NOT NULL AND email <> ''
        ORDER BY full_name ASC
    ")->fetchAll();
} else {
    $sql = "
        SELECT
            p.id,
            p.full_name,
            p.email,
            GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR ', ') AS classes_csv
        FROM class_assignments ca
        INNER JOIN classes c ON c.id = ca.class_id
        INNER JOIN students s ON s.id = ca.student_id
        INNER JOIN parents p ON p.id = s.parentId
        WHERE c.teacher_id = :tid
          AND p.email IS NOT NULL
          AND p.email <> ''
    ";
    $params = [':tid' => $teacherId];
    if ($classId > 0) {
        $sql .= " AND c.id = :cid";
        $params[':cid'] = $classId;
    }
    $sql .= " GROUP BY p.id, p.full_name, p.email ORDER BY p.full_name ASC";

    $stmtParents = $pdo->prepare($sql);
    $stmtParents->execute($params);
    $parents = $stmtParents->fetchAll(PDO::FETCH_ASSOC);
}

$result = null;
$message = '';
$presets = pe_presets();
$presetId = trim((string)($_POST['preset_id'] ?? ''));
$mode = (string)($_POST['mode'] ?? 'all');
$subject = trim((string)($_POST['subject'] ?? ''));
$body = trim((string)($_POST['body'] ?? ''));
$selectedIds = array_map('intval', (array)($_POST['parent_ids'] ?? []));
$previewHtml = '';
$previewSubject = '';
$previewCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $emailAction = (string)($_POST['email_action'] ?? 'send');
    if ($presetId !== '' && isset($presets[$presetId])) {
        if ($subject === '') {
            $subject = (string)$presets[$presetId]['subject'];
        }
        if ($body === '') {
            $body = (string)$presets[$presetId]['body'];
        }
    }

    $recipients = [];
    if ($mode === 'selected') {
        $selectedMap = array_fill_keys($selectedIds, true);
        foreach ($parents as $p) {
            $pid = (int)($p['id'] ?? 0);
            if ($pid > 0 && isset($selectedMap[$pid])) {
                $recipients[] = $p;
            }
        }
        if (!$recipients) {
            $result = 'error';
            $message = 'Please select at least one parent.';
        }
    } else {
        $recipients = $parents;
    }

    $senderName = $isAdmin
        ? $senderDisplayName
        : ($teacherName !== '' ? $teacherName : $senderDisplayName);

    if ($emailAction === 'preview') {
        $sampleRecipient = trim((string)(($recipients[0]['full_name'] ?? 'Parent')));
        $previewSubjectRaw = $subject !== '' ? $subject : 'Sample Subject';
        $previewBodyRaw = $body !== '' ? $body : "This is a sample message preview.\nPlease update message before sending.";
        $previewSubject = pe_apply_tokens($previewSubjectRaw, $sampleRecipient);
        $previewBody = pe_apply_tokens($previewBodyRaw, $sampleRecipient);
        $previewHtml = pe_build_email_html($sampleRecipient, $previewSubject, $previewBody, $senderName);
        $previewCount = count($recipients);
        if ($result !== 'error') {
            $result = 'success';
            $message = 'Preview generated. Review below before sending.';
        }
    } else {
        if ($subject === '') {
            $result = 'error';
            $message = 'Subject is required.';
        } elseif ($body === '') {
            $result = 'error';
            $message = 'Message is required.';
        }

        if ($result !== 'error') {
            $queued = 0;
            $skipped = 0;

            foreach ($recipients as $p) {
                $email = trim((string)($p['email'] ?? ''));
                $name = trim((string)($p['full_name'] ?? 'Parent'));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    continue;
                }

                $subjectFinal = pe_apply_tokens($subject, $name);
                $bodyFinal = pe_apply_tokens($body, $name);
                $html = pe_build_email_html($name, $subjectFinal, $bodyFinal, $senderName);

                if (bbcc_queue_mail($email, $name, $subjectFinal, $html)) {
                    $queued++;
                } else {
                    $skipped++;
                }
            }

            $processed = bbcc_process_mail_queue(50);
            $result = 'success';
            $message = "Email queued for {$queued} parent(s). Skipped {$skipped}. Immediate send: {$processed['sent']} sent, {$processed['failed']} failed.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Parent Email</title>
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
                    <h1 class="h4 mb-0">Send Parent Email</h1>
                    <span class="badge badge-light"><?= count($parents) ?> recipient(s)</span>
                </div>

                <?php if ($result === 'success'): ?>
                    <div class="alert alert-success"><?= pe_h($message) ?></div>
                <?php elseif ($result === 'error'): ?>
                    <div class="alert alert-danger"><?= pe_h($message) ?></div>
                <?php endif; ?>

                <?php if (!$isAdmin): ?>
                    <div class="card shadow mb-3">
                        <div class="card-body">
                            <form method="GET" class="form-row align-items-end mb-0">
                                <div class="form-group col-md-6">
                                    <label>Class Scope</label>
                                    <select name="class_id" class="form-control">
                                        <option value="0">All My Classes</option>
                                        <?php foreach ($teacherClasses as $cl): ?>
                                            <option value="<?= (int)$cl['id'] ?>" <?= $classId === (int)$cl['id'] ? 'selected' : '' ?>>
                                                <?= pe_h((string)$cl['class_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-2">
                                    <button type="submit" class="btn btn-outline-primary btn-block">
                                        <i class="fas fa-filter mr-1"></i> Filter
                                    </button>
                                </div>
                            </form>
                            <small class="text-muted">Teacher can email only parents of children in assigned classes.</small>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-3">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Compose Message</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <?php if (!$isAdmin): ?>
                                <input type="hidden" name="class_id" value="<?= (int)$classId ?>">
                            <?php endif; ?>

                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Recipients</label>
                                    <select name="mode" id="modeSelect" class="form-control">
                                        <option value="all" <?= $mode === 'all' ? 'selected' : '' ?>>
                                            <?= $isAdmin ? 'All parents' : 'All parents in class scope' ?>
                                        </option>
                                        <option value="selected" <?= $mode === 'selected' ? 'selected' : '' ?>>
                                            <?= $isAdmin ? 'Selected parents' : 'Selected parents in class scope' ?>
                                        </option>
                                    </select>
                                </div>
                                <div class="form-group col-md-5">
                                    <label>Quick Preset</label>
                                    <select name="preset_id" id="presetSelect" class="form-control">
                                        <option value="">-- Select preset (optional) --</option>
                                        <?php foreach ($presets as $id => $p): ?>
                                            <option value="<?= pe_h($id) ?>" <?= $presetId === $id ? 'selected' : '' ?>>
                                                <?= pe_h((string)$p['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div id="parentPickerWrap" class="form-group" style="<?= $mode === 'selected' ? '' : 'display:none;' ?>">
                                <label>Select parents</label>
                                <div class="border rounded p-2" style="max-height:300px;overflow:auto;">
                                    <?php if (empty($parents)): ?>
                                        <div class="text-muted">No parent recipients found for current scope.</div>
                                    <?php else: ?>
                                        <?php foreach ($parents as $p): ?>
                                            <?php $pid = (int)($p['id'] ?? 0); ?>
                                            <div class="custom-control custom-checkbox mb-1">
                                                <input
                                                    type="checkbox"
                                                    class="custom-control-input"
                                                    id="p<?= $pid ?>"
                                                    name="parent_ids[]"
                                                    value="<?= $pid ?>"
                                                    <?= in_array($pid, $selectedIds, true) ? 'checked' : '' ?>
                                                >
                                                <label class="custom-control-label" for="p<?= $pid ?>">
                                                    <?= pe_h((string)($p['full_name'] ?? 'Parent')) ?>
                                                    (<?= pe_h((string)($p['email'] ?? '')) ?>)
                                                    <?php if (!$isAdmin && !empty($p['classes_csv'])): ?>
                                                        <span class="text-muted">- <?= pe_h((string)$p['classes_csv']) ?></span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Subject</label>
                                <input type="text" name="subject" id="subjectInput" class="form-control" maxlength="200" value="<?= pe_h($subject) ?>">
                            </div>
                            <div class="form-group">
                                <label>Message</label>
                                <textarea name="body" id="bodyInput" rows="8" class="form-control"><?= pe_h($body) ?></textarea>
                                <small class="text-muted">Use Preview to review the exact email design before sending.</small>
                            </div>

                            <button type="submit" name="email_action" value="preview" class="btn btn-outline-primary" <?= empty($parents) ? 'disabled' : '' ?>>
                                <i class="fas fa-eye mr-1"></i> Preview Email
                            </button>
                            <button type="submit" name="email_action" value="send" class="btn btn-primary" <?= empty($parents) ? 'disabled' : '' ?>>
                                <i class="fas fa-paper-plane mr-1"></i> Send Email
                            </button>
                        </form>
                    </div>
                </div>

                <?php if ($previewHtml !== ''): ?>
                    <div class="card shadow">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Email Preview</h6>
                            <span class="badge badge-info">Recipients in scope: <?= (int)$previewCount ?></span>
                        </div>
                        <div class="card-body">
                            <div class="mb-2"><strong>Subject:</strong> <?= pe_h($previewSubject) ?></div>
                            <div class="border rounded" style="background:#fff;overflow:hidden;">
                                <?= $previewHtml ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>

<script src="vendor/jquery/jquery.min.js"></script>
<script>
$(function () {
    var presets = <?= json_encode($presets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    $('#modeSelect').on('change', function () {
        $('#parentPickerWrap').toggle($(this).val() === 'selected');
    });

    $('#presetSelect').on('change', function () {
        var key = $(this).val();
        if (!key || !presets[key]) return;
        $('#subjectInput').val(presets[key].subject || '');
        $('#bodyInput').val(presets[key].body || '');
    });
});
</script>
</body>
</html>
