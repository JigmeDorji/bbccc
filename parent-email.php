<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/class_teacher_helpers.php";
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

function pe_sanitize_email_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    // Legacy/plain-text submissions remain supported.
    if ($html === strip_tags($html)) {
        return nl2br(pe_h($html));
    }

    $allowedTags = [
        'p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'a', 'span',
    ];
    if (!class_exists('DOMDocument')) {
        // Preserve safety on minimal PHP installations even if formatting
        // must be reduced to plain text.
        return nl2br(pe_h(trim(strip_tags($html))));
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="pe-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->getElementById('pe-root');
    if (!$root) return '';

    $walk = function (DOMNode $node) use (&$walk, $allowedTags): void {
        for ($child = $node->firstChild; $child !== null;) {
            $next = $child->nextSibling;
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowedTags, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    $child = $next;
                    continue;
                }

                $allowedAttributes = match ($tag) {
                    'a' => ['href', 'target'],
                    'td', 'th' => ['colspan', 'rowspan'],
                    default => [],
                };
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    if (!in_array(strtolower($attribute->name), $allowedAttributes, true)) {
                        $child->removeAttribute($attribute->name);
                    }
                }
                if ($tag === 'a') {
                    $href = trim((string)$child->getAttribute('href'));
                    if (!preg_match('~^(https?://|mailto:)~i', $href)) {
                        $child->removeAttribute('href');
                    } else {
                        $child->setAttribute('target', '_blank');
                    }
                }
                $walk($child);
            }
            $child = $next;
        }
    };
    $walk($root);

    $safe = '';
    foreach ($root->childNodes as $child) {
        $safe .= $doc->saveHTML($child);
    }
    return $safe;
}

function pe_store_attachment(array $file): ?array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The attachment upload failed. Please try again.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Attachment must be smaller than 10 MB.');
    }

    $originalName = trim((string)($file['name'] ?? 'attachment'));
    $originalName = preg_replace('/[^A-Za-z0-9._()\- ]/', '_', basename($originalName)) ?: 'attachment';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'txt'];
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Unsupported attachment type. Use PDF, Office documents, JPG, PNG, or TXT.');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('The attachment upload could not be verified.');
    }

    $mime = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($tmpPath);
        if (is_string($detected) && $detected !== '') $mime = $detected;
    }

    $storageDir = __DIR__ . '/storage/mail-attachments';
    if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Unable to create attachment storage.');
    }
    $storedPath = $storageDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
    if (!move_uploaded_file($tmpPath, $storedPath)) {
        throw new RuntimeException('Unable to save the attachment.');
    }

    return ['path' => $storedPath, 'name' => $originalName, 'mime' => $mime];
}

function pe_build_email_html(string $recipientName, string $subject, string $body, string $senderName): string {
    $safeSubject = pe_h($subject);
    $safeBody = pe_sanitize_email_html($body);

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
              <div style="margin:0 0 14px 0;">' . $safeBody . '</div>
              <style>
                table{border-collapse:collapse;width:100%;margin:12px 0}
                th,td{border:1px solid #d1d5db;padding:8px;text-align:left;vertical-align:top}
                th{background:#f3f4f6;font-weight:bold}
                blockquote{border-left:4px solid #881b12;margin:12px 0;padding:8px 14px;color:#4b5563}
              </style>
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
bbcc_ensure_class_teacher_schema($pdo);

$sessionUsername = trim((string)($_SESSION['username'] ?? ''));
$senderDisplayName = pe_pretty_name_from_username($sessionUsername);
$sessionUserId = trim((string)($_SESSION['userid'] ?? ''));

$isAdmin = is_admin_role();
$teacherId = 0;
$teacherName = '';

if ($isAdmin && $sessionUserId !== '') {
    try {
        $stmtAdminName = $pdo->prepare("SELECT full_name FROM admin_profiles WHERE user_id = :uid LIMIT 1");
        $stmtAdminName->execute([':uid' => $sessionUserId]);
        $adminFullName = trim((string)$stmtAdminName->fetchColumn());
        if ($adminFullName !== '') {
            $senderDisplayName = $adminFullName;
        }
    } catch (Throwable $e) {
        // Keep fallback display name if admin_profiles table is not present.
    }
}

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
    $teacherClasses = bbcc_teacher_classes($pdo, $teacherId, false);

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
        INNER JOIN class_teacher_assignments cta ON cta.class_id = c.id
        INNER JOIN students s ON s.id = ca.student_id
        INNER JOIN parents p ON p.id = s.parentId
        WHERE cta.teacher_id = :tid
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
$deliveryReport = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['parent_email_flash'])) {
    $flash = (array)$_SESSION['parent_email_flash'];
    unset($_SESSION['parent_email_flash']);
    $result = isset($flash['result']) ? (string)$flash['result'] : null;
    $message = (string)($flash['message'] ?? '');
    $deliveryReport = isset($flash['delivery_report']) && is_array($flash['delivery_report'])
        ? $flash['delivery_report']
        : null;
}

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
            $attachment = null;
            try {
                $attachment = pe_store_attachment((array)($_FILES['attachment'] ?? []));
            } catch (Throwable $e) {
                $result = 'error';
                $message = $e->getMessage();
            }
        }

        if ($result !== 'error') {
            $queueEnabled = bbcc_mail_queue_is_truthy(bbcc_env('MAIL_QUEUE_ENABLED', '1'));
            $queued = 0;
            $sentDirect = 0;
            $failedDirect = 0;
            $skipped = 0;
            $batchId = 'pe_' . date('YmdHis') . '_' . bin2hex(random_bytes(8));
            $queueMetadata = [
                'source' => 'parent-email',
                'created_by' => $sessionUserId,
                'batch_id' => $batchId,
            ];

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

                if ($queueEnabled) {
                    if (bbcc_queue_mail($email, $name, $subjectFinal, $html, 5, $attachment, $queueMetadata)) {
                        $queued++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $attachments = $attachment ? [$attachment] : [];
                    if (send_mail($email, $name, $subjectFinal, $html, 10, $attachments)) {
                        $sentDirect++;
                    } else {
                        $failedDirect++;
                        bbcc_mail_log('PARENT EMAIL DIRECT SEND FAIL to ' . $email . ': ' . bbcc_last_mail_error());
                    }
                }
            }

            if ((!$queueEnabled || $queued === 0) && !empty($attachment['path'])) {
                @unlink((string)$attachment['path']);
            }

            if (!$queueEnabled) {
                $deliveryReport = ['sent' => $sentDirect, 'queued' => 0, 'retry' => 0, 'failed' => $failedDirect];
                if ($sentDirect > 0 && $failedDirect === 0) {
                    $result = 'success';
                    $message = "Confirmed sent directly to {$sentDirect} parent(s). Skipped {$skipped}.";
                } elseif ($sentDirect > 0) {
                    $result = 'warning';
                    $message = "Sent to {$sentDirect} parent(s), but {$failedDirect} failed. Skipped {$skipped}. Check mail_error.log.";
                } else {
                    $result = 'error';
                    $message = "No parent emails were sent. {$failedDirect} failed and {$skipped} skipped. Check mail_error.log.";
                }
            } else {
                $processed = bbcc_process_mail_queue(50);
                $batchStatus = $pdo->prepare("
                    SELECT status, COUNT(*) AS total
                    FROM mail_queue
                    WHERE batch_id = :batch_id
                    GROUP BY status
                ");
                $batchStatus->execute([':batch_id' => $batchId]);
                $deliveryReport = ['sent' => 0, 'queued' => 0, 'retry' => 0, 'failed' => 0];
                foreach ($batchStatus->fetchAll(PDO::FETCH_ASSOC) as $statusRow) {
                    $statusKey = strtolower((string)($statusRow['status'] ?? ''));
                    if (isset($deliveryReport[$statusKey])) {
                        $deliveryReport[$statusKey] = (int)$statusRow['total'];
                    }
                }
                $waiting = $deliveryReport['queued'] + $deliveryReport['retry'];
                if ($queued === 0) {
                    $result = 'error';
                    $message = "No emails were queued. Skipped {$skipped}.";
                } elseif ($deliveryReport['sent'] === $queued) {
                    $result = 'success';
                    $message = "Confirmed sent to {$deliveryReport['sent']} parent(s). Skipped {$skipped}.";
                } else {
                    $result = 'warning';
                    $message = "Delivery is not fully confirmed: {$deliveryReport['sent']} sent, {$waiting} waiting/retrying, {$deliveryReport['failed']} failed. Check Recent Delivery Status below.";
                }
            }
        }
    }

    // Sending is a state-changing action. Redirect after processing so a
    // browser refresh cannot submit the same email batch again.
    if ($emailAction !== 'preview') {
        $_SESSION['parent_email_flash'] = [
            'result' => $result,
            'message' => $message,
            'delivery_report' => $deliveryReport,
        ];
        $redirect = 'parent-email';
        if (!$isAdmin && $classId > 0) {
            $redirect .= '?class_id=' . $classId;
        }
        header('Location: ' . $redirect);
        exit;
    }
}

$recentDeliveries = [];
try {
    bbcc_mail_queue_ensure_table();
    $recentStmt = $pdo->prepare("
        SELECT to_email, to_name, subject, status, attempts, max_attempts, last_error, created_at, sent_at, attachment_name
        FROM mail_queue
        WHERE source = 'parent-email'
          AND created_by = :created_by
        ORDER BY id DESC
        LIMIT 100
    ");
    $recentStmt->execute([':created_by' => $sessionUserId]);
    $recentDeliveries = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentDeliveries = [];
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
    <style>
        .email-editor-toolbar {
            display:flex;
            flex-wrap:wrap;
            gap:5px;
            padding:8px;
            border:1px solid #d1d3e2;
            border-bottom:0;
            border-radius:.35rem .35rem 0 0;
            background:#f8f9fc;
        }
        .email-editor-toolbar .btn { min-width:36px; }
        .email-rich-editor {
            min-height:220px;
            max-height:520px;
            overflow:auto;
            padding:12px;
            border:1px solid #d1d3e2;
            border-radius:0 0 .35rem .35rem;
            background:#fff;
            color:#333;
            line-height:1.55;
        }
        .email-rich-editor:focus {
            border-color:#bac8f3;
            outline:0;
            box-shadow:0 0 0 .2rem rgba(78,115,223,.25);
        }
        .email-rich-editor table {
            width:100%;
            border-collapse:collapse;
            margin:10px 0;
        }
        .email-rich-editor th, .email-rich-editor td {
            border:1px solid #b7bcc5;
            padding:7px;
            min-width:60px;
        }
        .email-rich-editor th { background:#f1f3f5; }
        #previewEmailContent table { width:100%;border-collapse:collapse;margin:12px 0; }
        #previewEmailContent th, #previewEmailContent td { border:1px solid #d1d5db;padding:8px;text-align:left;vertical-align:top; }
        #previewEmailContent th { background:#f3f4f6;font-weight:bold; }
        #previewEmailContent blockquote { border-left:4px solid #881b12;margin:12px 0;padding:8px 14px;color:#4b5563; }
    </style>
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
                <?php elseif ($result === 'warning'): ?>
                    <div class="alert alert-warning"><?= pe_h($message) ?></div>
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
                        <form method="POST" enctype="multipart/form-data">
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
                                                    data-parent-name="<?= pe_h((string)($p['full_name'] ?? 'Parent')) ?>"
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
                                <div class="email-editor-toolbar" role="toolbar" aria-label="Email formatting">
                                    <button type="button" class="btn btn-sm btn-light editor-command" data-command="bold" title="Bold"><i class="fas fa-bold"></i></button>
                                    <button type="button" class="btn btn-sm btn-light editor-command" data-command="italic" title="Italic"><i class="fas fa-italic"></i></button>
                                    <button type="button" class="btn btn-sm btn-light editor-command" data-command="underline" title="Underline"><i class="fas fa-underline"></i></button>
                                    <button type="button" class="btn btn-sm btn-light editor-command" data-command="insertUnorderedList" title="Bulleted list"><i class="fas fa-list-ul"></i></button>
                                    <button type="button" class="btn btn-sm btn-light editor-command" data-command="insertOrderedList" title="Numbered list"><i class="fas fa-list-ol"></i></button>
                                    <select id="editorFormatBlock" class="form-control form-control-sm" style="width:auto;" title="Text style">
                                        <option value="p">Paragraph</option>
                                        <option value="h2">Heading 1</option>
                                        <option value="h3">Heading 2</option>
                                        <option value="blockquote">Quote</option>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-light" id="editorLinkButton" title="Insert link"><i class="fas fa-link"></i></button>
                                    <button type="button" class="btn btn-sm btn-light" id="editorTableButton" title="Insert table"><i class="fas fa-table"></i></button>
                                    <button type="button" class="btn btn-sm btn-light editor-command" data-command="removeFormat" title="Clear formatting"><i class="fas fa-eraser"></i></button>
                                </div>
                                <div id="bodyEditor" class="email-rich-editor" contenteditable="true" role="textbox" aria-multiline="true"><?= pe_sanitize_email_html($body) ?></div>
                                <textarea name="body" id="bodyInput" class="d-none" aria-hidden="true"><?= pe_h($body) ?></textarea>
                                <small class="text-muted">Formatting is preserved in Preview and in the delivered email.</small>
                            </div>
                            <div class="form-group">
                                <label for="attachmentInput">Attachment <span class="text-muted">(optional)</span></label>
                                <div class="custom-file">
                                    <input type="file" name="attachment" id="attachmentInput" class="custom-file-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.txt">
                                    <label class="custom-file-label" for="attachmentInput">Choose file</label>
                                </div>
                                <small class="text-muted">Maximum 10 MB. PDF, Office documents, JPG, PNG, or TXT. Preview does not upload or clear the selected file.</small>
                            </div>

                            <button type="button" id="previewEmailButton" class="btn btn-outline-primary" <?= empty($parents) ? 'disabled' : '' ?>>
                                <i class="fas fa-eye mr-1"></i> Preview Email
                            </button>
                            <button type="submit" name="email_action" value="send" class="btn btn-primary" <?= empty($parents) ? 'disabled' : '' ?>>
                                <i class="fas fa-paper-plane mr-1"></i> Send Email
                            </button>
                        </form>
                    </div>
                </div>

                    <div class="card shadow" id="emailPreviewCard" style="<?= $previewHtml === '' ? 'display:none;' : '' ?>">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Email Preview</h6>
                            <span class="badge badge-info" id="previewRecipientCount">Recipients in scope: <?= (int)$previewCount ?></span>
                        </div>
                        <div class="card-body">
                            <div class="mb-2"><strong>Subject:</strong> <span id="previewSubjectText"><?= pe_h($previewSubject) ?></span></div>
                            <div class="border rounded" id="previewEmailContent" style="background:#fff;overflow:hidden;">
                                <?= $previewHtml ?>
                            </div>
                        </div>
                    </div>

                <div class="card shadow mt-3">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Recent Delivery Status</h6>
                        <small class="text-muted">Sent means accepted by the configured mail server</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered table-hover mb-0">
                                <thead class="thead-light">
                                    <tr><th>Recipient</th><th>Subject</th><th>Attachment</th><th>Status</th><th>Attempts</th><th>Sent At</th><th>Error</th></tr>
                                </thead>
                                <tbody>
                                <?php if (empty($recentDeliveries)): ?>
                                    <tr><td colspan="7" class="text-center text-muted">No tracked parent emails yet.</td></tr>
                                <?php else: foreach ($recentDeliveries as $delivery): ?>
                                    <?php
                                        $deliveryStatus = strtolower((string)($delivery['status'] ?? 'queued'));
                                        $deliveryLabel = match ($deliveryStatus) {
                                            'sent' => 'Sent',
                                            'retry' => 'Retrying',
                                            'failed' => 'Failed',
                                            default => 'Queued',
                                        };
                                        $deliveryBadge = match ($deliveryStatus) {
                                            'sent' => 'success',
                                            'retry' => 'warning',
                                            'failed' => 'danger',
                                            default => 'secondary',
                                        };
                                    ?>
                                    <tr>
                                        <td><?= pe_h((string)($delivery['to_name'] ?: $delivery['to_email'])) ?><br><small><?= pe_h((string)$delivery['to_email']) ?></small></td>
                                        <td><?= pe_h((string)$delivery['subject']) ?></td>
                                        <td><?= pe_h((string)($delivery['attachment_name'] ?: '—')) ?></td>
                                        <td><span class="badge badge-<?= $deliveryBadge ?>"><?= $deliveryLabel ?></span></td>
                                        <td><?= (int)$delivery['attempts'] ?>/<?= (int)$delivery['max_attempts'] ?></td>
                                        <td class="nowrap"><?= pe_h((string)($delivery['sent_at'] ?: '—')) ?></td>
                                        <td class="text-danger small"><?= pe_h((string)($delivery['last_error'] ?: '—')) ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>

<script src="vendor/jquery/jquery.min.js"></script>
<script>
$(function () {
    var presets = <?= json_encode($presets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var allParents = <?= json_encode(array_map(static function (array $parent): array {
        return [
            'id' => (int)($parent['id'] ?? 0),
            'name' => (string)($parent['full_name'] ?? 'Parent'),
        ];
    }, $parents), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function applyPreviewTokens(value, parentName) {
        return String(value || '')
            .split('{PARENT_NAME}').join(parentName || 'Parent')
            .split('{SCHOOL_NAME}').join('Bhutanese Language and Culture School');
    }

    function syncEditorBody() {
        var editor = document.getElementById('bodyEditor');
        var text = (editor.innerText || '').replace(/\u00a0/g, ' ').trim();
        $('#bodyInput').val(text === '' ? '' : editor.innerHTML);
    }

    function plainTextToEditorHtml(value) {
        return escapeHtml(value || '').replace(/\r?\n/g, '<br>');
    }

    function cleanPreviewHtml(html) {
        var template = document.createElement('template');
        template.innerHTML = String(html || '');
        var allowed = ['P','DIV','BR','STRONG','B','EM','I','U','S','H1','H2','H3','H4','UL','OL','LI','BLOCKQUOTE','TABLE','THEAD','TBODY','TFOOT','TR','TH','TD','A','SPAN'];
        Array.from(template.content.querySelectorAll('*')).forEach(function (node) {
            if (allowed.indexOf(node.tagName) === -1) {
                node.replaceWith.apply(node, Array.from(node.childNodes));
                return;
            }
            Array.from(node.attributes).forEach(function (attribute) {
                var permitted = (node.tagName === 'A' && ['href','target'].indexOf(attribute.name.toLowerCase()) !== -1) ||
                    (['TD','TH'].indexOf(node.tagName) !== -1 && ['colspan','rowspan'].indexOf(attribute.name.toLowerCase()) !== -1);
                if (!permitted) node.removeAttribute(attribute.name);
            });
            if (node.tagName === 'A') {
                var href = node.getAttribute('href') || '';
                if (!/^(https?:\/\/|mailto:)/i.test(href)) node.removeAttribute('href');
                else node.setAttribute('target', '_blank');
            }
        });
        return template.innerHTML;
    }

    function previewRecipients() {
        if ($('#modeSelect').val() !== 'selected') {
            return allParents;
        }
        var selected = [];
        $('input[name="parent_ids[]"]:checked').each(function () {
            selected.push({
                id: Number(this.value || 0),
                name: $(this).data('parent-name') || 'Parent'
            });
        });
        return selected;
    }

    function buildPreviewHtml(subject, bodyHtml) {
        var safeSubject = escapeHtml(subject);
        var safeBody = cleanPreviewHtml(bodyHtml);
        return '<div style="margin:0;padding:24px 0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937;">' +
            '<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">' +
                '<div style="background:#881b12;padding:18px 24px;color:#fff;">' +
                    '<div style="font-size:13px;opacity:.9;letter-spacing:.04em;text-transform:uppercase;">Bhutanese Language and Culture School</div>' +
                    '<div style="font-size:22px;font-weight:bold;line-height:1.2;margin-top:6px;">Parent Communication</div>' +
                '</div>' +
                '<div style="padding:22px 24px 10px;">' +
                    '<div style="font-size:13px;color:#6b7280;margin-bottom:8px;">Subject</div>' +
                    '<div style="font-size:20px;font-weight:bold;color:#111827;line-height:1.3;">' + safeSubject + '</div>' +
                '</div>' +
                '<div style="padding:8px 24px 10px;font-size:15px;line-height:1.7;color:#1f2937;">' + safeBody + '</div>' +
                '<div style="padding:16px 24px 22px;">' +
                    '<div style="height:1px;background:#e5e7eb;margin-bottom:10px;"></div>' +
                    '<div style="font-size:12px;color:#6b7280;line-height:1.5;">This is an official communication from Bhutanese Language and Culture School. Please do not reply to this email if sent from a no-reply address.</div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    $('#modeSelect').on('change', function () {
        $('#parentPickerWrap').toggle($(this).val() === 'selected');
    });

    $('#presetSelect').on('change', function () {
        var key = $(this).val();
        if (!key || !presets[key]) return;
        $('#subjectInput').val(presets[key].subject || '');
        $('#bodyEditor').html(plainTextToEditorHtml(presets[key].body || ''));
        syncEditorBody();
    });

    $('#attachmentInput').on('change', function () {
        var name = this.files && this.files.length ? this.files[0].name : 'Choose file';
        $(this).next('.custom-file-label').text(name);
    });

    $('.editor-command').on('click', function () {
        document.getElementById('bodyEditor').focus();
        document.execCommand($(this).data('command'), false, null);
        syncEditorBody();
    });

    $('#editorFormatBlock').on('change', function () {
        document.getElementById('bodyEditor').focus();
        document.execCommand('formatBlock', false, '<' + this.value + '>');
        syncEditorBody();
    });

    $('#editorLinkButton').on('click', function () {
        var url = window.prompt('Enter a web address (https://...) or email link (mailto:...)');
        if (!url) return;
        url = url.trim();
        if (!/^(https?:\/\/|mailto:)/i.test(url)) {
            url = 'https://' + url;
        }
        document.getElementById('bodyEditor').focus();
        document.execCommand('createLink', false, url);
        syncEditorBody();
    });

    $('#editorTableButton').on('click', function () {
        var rowsInput = window.prompt('Number of rows', '3');
        if (rowsInput === null) return;
        var rows = Math.max(1, Math.min(20, parseInt(rowsInput, 10) || 1));
        var columnsInput = window.prompt('Number of columns', '3');
        if (columnsInput === null) return;
        var columns = Math.max(1, Math.min(10, parseInt(columnsInput, 10) || 1));

        var table = '<table><tbody>';
        for (var row = 0; row < rows; row++) {
            table += '<tr>';
            for (var column = 0; column < columns; column++) {
                var tag = row === 0 ? 'th' : 'td';
                table += '<' + tag + '>' + (row === 0 ? 'Heading' : 'Text') + '</' + tag + '>';
            }
            table += '</tr>';
        }
        table += '</tbody></table><p><br></p>';
        document.getElementById('bodyEditor').focus();
        document.execCommand('insertHTML', false, table);
        syncEditorBody();
    });

    $('#bodyEditor').on('input blur', syncEditorBody);
    $('form[method="POST"]').on('submit', function () {
        if ($(this).find('#bodyEditor').length) syncEditorBody();
    });

    $('#previewEmailButton').on('click', function () {
        var recipients = previewRecipients();
        if (!recipients.length) {
            window.alert('Please select at least one parent.');
            return;
        }

        var sampleName = recipients[0].name || 'Parent';
        var subject = applyPreviewTokens($('#subjectInput').val() || 'Sample Subject', sampleName);
        syncEditorBody();
        var bodyHtml = applyPreviewTokens(
            $('#bodyInput').val() || plainTextToEditorHtml('This is a sample message preview.\nPlease update the message before sending.'),
            sampleName
        );

        $('#previewSubjectText').text(subject);
        $('#previewRecipientCount').text('Recipients in scope: ' + recipients.length);
        $('#previewEmailContent').html(buildPreviewHtml(subject, bodyHtml));
        $('#emailPreviewCard').show();

        var previewTop = $('#emailPreviewCard').offset();
        if (previewTop) {
            $('html, body').animate({scrollTop: Math.max(0, previewTop.top - 20)}, 200);
        }
    });
});
</script>
</body>
</html>
