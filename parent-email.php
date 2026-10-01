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
        'semester_update' => [
            'label' => 'Semester Update',
            'subject' => 'Semester Update - Bhutanese Language and Culture School',
            'body' => '<p>Dear {PARENT_NAME},</p><p>Here are a few highlights from the {SEMESTER_NAME} of {SCHOOL_YEAR}, celebrating learning, culture and community at our school.</p><h2>Classroom Highlights</h2><p>Share classroom learning, student progress and achievements here.</p><h2>Culture and Community</h2><p>Add stories and photographs from cultural activities, celebrations and performances.</p><h2>Dates for Your Diary</h2><p>List upcoming events, term dates and important reminders.</p><p>Warm regards,<br>{PRINCIPAL_NAME}<br>{SCHOOL_NAME}</p>',
            'body_is_html' => true,
        ],
        'general_info_note' => [
            'label' => 'Info Note / General Update',
            'subject' => 'School Update - Information Note',
            'body' => "Dear {PARENT_NAME},\n\nThis is an important information note from {SCHOOL_NAME}. We would like to share the latest school updates for the semester, including announcements, new staff introductions, important reminders, and upcoming activities.\n\nPlease take note of the following:\n- school updates and announcements\n- welcome to new teachers and staff\n- principal and leadership updates\n- upcoming semester dates and key reminders\n\nWe thank you for your continued support and partnership in helping our children thrive.\n\nKind regards,\n{PRINCIPAL_NAME}",
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

function pe_masthead_defaults(string $style): array {
    $currentMonth = (new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney')))->format('F Y');
    return [
        'name' => 'Bhutanese Language and Culture School',
        'title' => $style === 'newspaper' ? 'The Semester Review' : ($style === 'newsletter' ? 'Information Note' : 'Parent Communication'),
        'subtitle' => $style === 'newspaper'
            ? 'SEMESTER EDITION  |  ' . $currentMonth
            : ($style === 'newsletter' ? 'School Newsletter  |  ' . $currentMonth : 'Official Communication'),
        'headline_label' => $style === 'newspaper' ? 'LEAD STORY' : 'Subject',
        'footer' => 'This is an official communication from Bhutanese Language and Culture School. Please do not reply to this email if sent from a no-reply address.',
    ];
}

function pe_ensure_info_note_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS parent_email_templates (
        template_key VARCHAR(80) NOT NULL PRIMARY KEY,
        subject VARCHAR(200) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        updated_by VARCHAR(50) DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function pe_load_info_note(PDO $pdo, array $presets): array {
    try {
        pe_ensure_info_note_table($pdo);
        $stmt = $pdo->prepare("SELECT subject, body FROM parent_email_templates WHERE template_key = 'general_info_note' LIMIT 1");
        $stmt->execute();
        $saved = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($saved) {
            $presets['general_info_note']['subject'] = (string)$saved['subject'];
            $presets['general_info_note']['body'] = (string)$saved['body'];
            $presets['general_info_note']['body_is_html'] = true;
        }
    } catch (Throwable $e) {
        error_log('[Parent Email] Could not load saved Info Note: ' . $e->getMessage());
    }
    return $presets;
}

function pe_apply_tokens(string $text, string $recipientName, string $senderName = ''): string {
    $nameParts = preg_split('/\s+/', trim($recipientName), 2);
    $name = !empty($nameParts[0]) ? $nameParts[0] : 'Parent';
    $schoolName = 'Bhutanese Language and Culture School';
    $principalName = trim($senderName) ?: (trim((string)($_SESSION['principal_name'] ?? 'Principal')) ?: 'Principal');
    $semesterName = trim((string)($_SESSION['semester_name'] ?? 'Semester')) ?: 'Semester';
    $schoolYear = trim((string)($_SESSION['school_year'] ?? date('Y')));

    $replacements = [
        '{PARENT_NAME}' => $name,
        '{parent_name}' => $name,
        '{SCHOOL_NAME}' => $schoolName,
        '{school_name}' => $schoolName,
        '{PRINCIPAL_NAME}' => $principalName,
        '{principal_name}' => $principalName,
        '{SEMESTER_NAME}' => $semesterName,
        '{semester_name}' => $semesterName,
        '{SCHOOL_YEAR}' => $schoolYear,
        '{school_year}' => $schoolYear,
    ];

    return strtr($text, $replacements);
}

function pe_sanitize_email_html(string $html, string $style = 'standard'): string {
    $html = trim($html);
    if ($html === '') return '';

    // Legacy/plain-text submissions remain supported.
    if ($html === strip_tags($html)) {
        return nl2br(pe_h($html));
    }

    $allowedTags = [
        'p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'a', 'span', 'img',
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

    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $style): void {
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
                    'img' => ['src', 'alt', 'title', 'width'],
                    default => [],
                };
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    if (!in_array(strtolower($attribute->name), $allowedAttributes, true)) {
                        $child->removeAttribute($attribute->name);
                    }
                }
                if ($tag === 'img') {
                    $src = trim((string)$child->getAttribute('src'));
                    if (!preg_match('~^https?://~i', $src)) {
                        $node->removeChild($child);
                        $child = $next;
                        continue;
                    }
                    $imageWidth = (int)$child->getAttribute('width');
                    $imageWidth = $imageWidth > 0 ? max(240, min(560, $imageWidth)) : 560;
                    $child->setAttribute('width', (string)$imageWidth);
                    $child->setAttribute('style', 'display:block;width:100%;max-width:' . $imageWidth . 'px;max-height:420px;height:auto;object-fit:cover;border:1px solid #d3cec3;margin:14px auto 6px;');
                }
                if ($style === 'newspaper' && $tag === 'h2') {
                    $child->setAttribute('style', 'font-family:Georgia,serif;font-size:22px;line-height:1.3;color:#203b37;border-bottom:1px solid #d3e0dd;padding-bottom:6px;margin:24px 0 10px;');
                }
                if ($style === 'newspaper' && $tag === 'h3') {
                    $child->setAttribute('style', 'font-family:Arial,sans-serif;font-size:13px;color:#80652f;text-transform:uppercase;letter-spacing:.08em;margin:20px 0 8px;');
                }
                if ($tag === 'p') {
                    $previous = $child->previousSibling;
                    while ($previous !== null && !$previous instanceof DOMElement) {
                        $previous = $previous->previousSibling;
                    }
                    if ($previous instanceof DOMElement && strtolower($previous->tagName) === 'p' && $previous->getElementsByTagName('img')->length > 0) {
                        $child->setAttribute('style', 'margin:0 0 18px;text-align:center;color:#6b6861;font:italic 12px Arial,sans-serif;');
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

function pe_store_inline_image(array $file): array {
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $uploadMessages = [
            UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
            UPLOAD_ERR_INI_SIZE => 'Image exceeds the server upload_max_filesize limit. Raise it to at least 5 MB in cPanel.',
            UPLOAD_ERR_FORM_SIZE => 'Image exceeds the upload form size limit.',
            UPLOAD_ERR_PARTIAL => 'Image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload folder configured.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image. Check the uploads folder permissions.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the image upload.',
        ];
        throw new RuntimeException($uploadMessages[$uploadError] ?? 'Image upload failed. Please try again.');
    }
    $tmpPath = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmpPath === '' || !is_uploaded_file($tmpPath) || $size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be smaller than 5 MB.');
    }

    $imageInfo = @getimagesize($tmpPath);
    $mime = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Use a JPG, PNG, GIF, or WebP image.');
    }

    $directory = __DIR__ . '/uploads/email-newsletter';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create image storage.');
    }
    @chmod($directory, 0755);
    clearstatcache(true, $directory);
    $directoryPermissions = @fileperms($directory);
    if ($directoryPermissions === false || ($directoryPermissions & 0005) !== 0005) {
        throw new RuntimeException('The image folder must be publicly readable. Set uploads/email-newsletter to permission 755 in cPanel.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('The newsletter image folder is not writable by PHP. Check its cPanel folder ownership and permissions.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $directory . '/' . $filename;
    if (!move_uploaded_file($tmpPath, $destination)) {
        throw new RuntimeException('Unable to save the image.');
    }
    @chmod($destination, 0644);
    clearstatcache(true, $destination);
    $filePermissions = @fileperms($destination);
    if ($filePermissions === false || ($filePermissions & 0004) !== 0004) {
        @unlink($destination);
        throw new RuntimeException('The uploaded image is not publicly readable. Check cPanel file permissions.');
    }

    $relativePath = 'uploads/email-newsletter/' . $filename;
    $baseUrl = rtrim((string)BASE_URL, '/');
    $configuredHost = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
    $requestHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($requestHost !== '' && in_array($configuredHost, ['localhost', '127.0.0.1'], true)) {
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $appPath = trim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/.');
        $baseUrl = $scheme . '://' . $requestHost . ($appPath !== '' ? '/' . $appPath : '');
    }
    return [
        'url' => $baseUrl . '/' . $relativePath,
        'name' => basename((string)($file['name'] ?? 'newsletter-image')),
    ];
}

function pe_safe_newsletter_asset_url(string $url): string {
    $url = trim($url);
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return '';
    }
    $path = (string)($parts['path'] ?? '');
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !preg_match('~(?:^|/)uploads/email-newsletter/[a-f0-9]{32}\.(jpg|png|gif|webp)$~i', $path)) {
        return '';
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $allowedHosts = [
        strtolower((string)parse_url((string)BASE_URL, PHP_URL_HOST)),
        strtolower((string)explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]),
    ];
    return in_array($host, array_filter($allowedHosts), true) ? $url : '';
}

function pe_embed_pdf_upload_images(string $html): string {
    if (!class_exists('DOMDocument')) {
        throw new RuntimeException('PDF export requires the PHP DOM extension.');
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="pdf-upload-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->getElementById('pdf-upload-root');
    if (!$root) return '';

    $uploadRoot = realpath(__DIR__ . '/uploads/email-newsletter');
    foreach (iterator_to_array($root->getElementsByTagName('img')) as $image) {
        if (!$image instanceof DOMElement) continue;

        $assetUrl = pe_safe_newsletter_asset_url((string)$image->getAttribute('src'));
        $filename = basename((string)parse_url($assetUrl, PHP_URL_PATH));
        $assetPath = $uploadRoot !== false && preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/i', $filename)
            ? realpath($uploadRoot . DIRECTORY_SEPARATOR . $filename)
            : false;
        if ($assetPath === false || !str_starts_with($assetPath, $uploadRoot . DIRECTORY_SEPARATOR)) {
            $image->parentNode?->removeChild($image);
            continue;
        }

        $imageInfo = @getimagesize($assetPath);
        $imageContents = @file_get_contents($assetPath);
        $mime = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
        if ($imageContents === false || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new RuntimeException('An uploaded image could not be included in the PDF.');
        }
        $image->setAttribute('src', 'data:' . $mime . ';base64,' . base64_encode($imageContents));
    }

    $fragment = '';
    foreach ($root->childNodes as $child) {
        $fragment .= $doc->saveHTML($child);
    }
    return $fragment;
}

function pe_build_email_html(string $recipientName, string $subject, string $body, string $senderName, string $style = 'standard', array $masthead = []): string {
    $safeSubject = pe_h($subject);
    $safeBody = pe_sanitize_email_html($body, $style);
    $schoolName = 'Bhutanese Language and Culture School';
    $isNewsletter = $style === 'newsletter';
    $isNewspaper = $style === 'newspaper';
    $isSchoolLetter = $style === 'school_letter';
    $defaults = pe_masthead_defaults($style);
    $brandName = trim((string)($masthead['name'] ?? $defaults['name']));
    $headingText = trim((string)($masthead['title'] ?? $defaults['title']));
    $subtitleText = trim((string)($masthead['subtitle'] ?? $defaults['subtitle']));
    $logoUrl = $isNewspaper ? pe_safe_newsletter_asset_url((string)($masthead['logo'] ?? '')) : '';
    $headerImageUrl = $isNewspaper ? pe_safe_newsletter_asset_url((string)($masthead['header_image'] ?? '')) : '';
    $logoMarkup = $logoUrl !== ''
        ? '<div style="margin-bottom:12px;"><img src="' . pe_h($logoUrl) . '" width="140" alt="School logo" style="display:block;width:auto;max-width:140px;max-height:72px;height:auto;border:0;margin:0;"></div>'
        : '';
    $hasFullHeaderImage = $headerImageUrl !== '';
    $headerImageMarkup = $headerImageUrl !== ''
        ? '<img src="' . pe_h($headerImageUrl) . '" width="720" alt="School newsletter header" style="display:block;width:100%;max-width:720px;height:auto;border:0;margin:0;">'
        : '';
    $mastheadImageMarkup = $headerImageMarkup !== '' ? $headerImageMarkup : $logoMarkup;
        $pageBackground = $isNewspaper ? '#eaf1ef' : '#eef3f2';
    $paperStyle = $isNewspaper
                ? 'max-width:720px;background:#ffffff;border:1px solid #cddbd8;border-radius:10px;overflow:hidden;box-shadow:0 12px 36px rgba(31,55,52,.09);'
        : 'max-width:700px;background:#ffffff;border:1px solid #dce5e3;border-radius:16px;overflow:hidden;box-shadow:0 12px 36px rgba(31,55,52,.09);';
    $mastheadStyle = $hasFullHeaderImage
        ? 'background:#174b46;padding:0;'
        : ($isNewspaper
                ? 'background:#174b46;padding:28px 32px 22px;border-bottom:4px solid #c8a85b;'
        : ($isNewsletter ? 'background:#741f1b;padding:24px 30px;border-bottom:4px solid #d5a84b;' : 'background:#f7faf9;padding:22px 28px;border-bottom:1px solid #e1e9e7;'));
    $mastheadNameStyle = $isNewspaper
                ? 'font-family:Arial,sans-serif;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#e7c987;font-weight:bold;'
        : ($isNewsletter ? 'font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:#f4dfac;font-weight:bold;' : 'font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:#6f2521;font-weight:bold;');
    $mastheadHeadingStyle = $isNewspaper
                ? 'font-family:Georgia,serif;font-size:36px;font-weight:bold;color:#ffffff;line-height:1.1;margin-top:9px;'
        : ($isNewsletter ? 'font-size:30px;font-weight:bold;color:#fff;line-height:1.2;margin-top:10px;' : 'font-size:30px;font-weight:bold;color:#1f2937;line-height:1.2;margin-top:8px;');
    $subtitleColor = $isNewspaper ? '#4c4942' : ($isNewsletter ? '#f5e6c5' : '#6b7280');
    $subjectLabel = trim((string)($masthead['headline_label'] ?? $defaults['headline_label']));
    $footerText = trim((string)($masthead['footer'] ?? $defaults['footer']));
    $footerBackground = $isNewspaper ? '#174b46' : ($isNewsletter ? '#741f1b' : '#f7faf9');
    $footerAccent = $isNewspaper ? '#c8a85b' : ($isNewsletter ? '#d5a84b' : '#e1e9e7');
    $footerBrandColor = $isNewspaper ? '#e7c987' : ($isNewsletter ? '#f4dfac' : '#6f2521');
    $footerTextColor = $isNewspaper ? '#d7e7e3' : ($isNewsletter ? '#f5e6c5' : '#6b7280');
    $subjectStyle = $isNewspaper
                ? 'font-family:Georgia,serif;font-size:28px;font-weight:bold;color:#203b37;line-height:1.2;border-bottom:1px solid #d3e0dd;padding-bottom:14px;'
        : 'font-size:20px;font-weight:bold;color:#111827;line-height:1.3;';
    $contentStyle = $isNewspaper
                ? 'padding:8px 32px 10px;font-family:Georgia,serif;font-size:16px;line-height:1.75;color:#293b38;'
        : 'padding:8px 28px 10px;font-size:15px;line-height:1.7;color:#1f2937;';
    $signature = ($isSchoolLetter || $isNewsletter || $isNewspaper)
        ? '<div style="margin-top:22px;padding-top:16px;border-top:1px solid #e5e7eb;">'
            . '<div style="font-size:12px;color:#6b7280;letter-spacing:.08em;text-transform:uppercase;">Warm regards</div>'
            . '<div style="font-size:18px;font-weight:bold;color:#111827;">' . pe_h($senderName ?: 'School Administration') . '</div>'
            . '<div style="font-size:13px;color:#6b7280;">' . pe_h($brandName !== '' ? $brandName : $schoolName) . '</div>'
            . '</div>'
        : '';

    return '
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:' . $pageBackground . ';font-family:Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:' . $pageBackground . ';padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="' . $paperStyle . '">
          <tr>
                        <td style="' . $mastheadStyle . '">
                            ' . $mastheadImageMarkup . '
                            ' . ($hasFullHeaderImage ? '<div style="background:#174b46;padding:18px 28px 22px;border-top:4px solid #c8a85b;">' . ($brandName !== '' ? '<div style="' . $mastheadNameStyle . '">' . pe_h($brandName) . '</div>' : '') . ($headingText !== '' ? '<div style="' . $mastheadHeadingStyle . '">' . pe_h($headingText) . '</div>' : '') . ($subtitleText !== '' ? '<div style="font-family:Arial,sans-serif;font-size:12px;letter-spacing:.08em;color:#d7e7e3;margin-top:8px;">' . pe_h($subtitleText) . '</div>' : '') . '</div>' : '') . '
                            ' . (!$hasFullHeaderImage && $brandName !== '' ? '<div style="' . $mastheadNameStyle . '">' . pe_h($brandName) . '</div>' : '') . '
                            ' . (!$hasFullHeaderImage && $headingText !== '' ? '<div style="' . $mastheadHeadingStyle . '">' . pe_h($headingText) . '</div>' : '') . '
                            ' . (!$hasFullHeaderImage && $subtitleText !== '' ? '<div style="font-family:Arial,sans-serif;font-size:12px;letter-spacing:.08em;color:' . $subtitleColor . ';margin-top:8px;">' . pe_h($subtitleText) . '</div>' : '') . '
            </td>
          </tr>
          <tr>
                        <td style="padding:' . ($isNewspaper ? '22px 32px 14px' : '24px 28px 10px') . ';">
                            <div style="font-family:Arial,sans-serif;font-size:11px;color:' . ($isNewspaper ? '#80652f' : '#7b1f1a') . ';margin-bottom:8px;text-transform:uppercase;letter-spacing:.14em;font-weight:bold;">' . pe_h($subjectLabel) . '</div>
                            <div style="' . $subjectStyle . '">' . $safeSubject . '</div>
            </td>
          </tr>
          <tr>
            <td style="' . $contentStyle . '">
              <div style="margin:0 0 14px 0;">' . $safeBody . '</div>
              ' . $signature . '
            </td>
          </tr>
          <tr>
                        <td style="padding:0;background:' . $footerBackground . ';border-top:4px solid ' . $footerAccent . ';">
                            <div style="padding:16px 28px 20px;">
                                <div style="font-size:11px;color:' . $footerBrandColor . ';font-weight:bold;text-transform:uppercase;">' . pe_h($brandName !== '' ? $brandName : $schoolName) . '</div>
                                <div style="font-size:12px;color:' . $footerTextColor . ';line-height:1.6;margin-top:6px;">' . pe_h($footerText) . '</div>
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

$emailAction = (string)($_POST['email_action'] ?? '');
$isNewsletterAssetUpload = $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($emailAction, ['upload_inline_image', 'upload_newsletter_logo', 'upload_newsletter_header'], true);
if ($isNewsletterAssetUpload) {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $fileKey = match ($emailAction) {
            'upload_newsletter_logo' => 'newsletter_logo',
            'upload_newsletter_header' => 'newsletter_header',
            default => 'newsletter_image',
        };
        $image = pe_store_inline_image((array)($_FILES[$fileKey] ?? []));
        echo json_encode(['ok' => true, 'url' => $image['url'], 'name' => $image['name']]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
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
        INNER JOIN parents p ON p.id = s.parent_id
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
$presets = pe_load_info_note($pdo, pe_presets());
$presetId = trim((string)($_POST['preset_id'] ?? ''));
$templateStyle = match ($presetId) {
    'semester_update' => 'newspaper',
    'general_info_note' => 'newsletter',
    default => 'standard',
};
$mastheadDefaults = pe_masthead_defaults($templateStyle);
$masthead = [
    'name' => array_key_exists('masthead_name', $_POST) ? trim((string)$_POST['masthead_name']) : $mastheadDefaults['name'],
    'title' => array_key_exists('masthead_title', $_POST) ? trim((string)$_POST['masthead_title']) : $mastheadDefaults['title'],
    'subtitle' => array_key_exists('masthead_subtitle', $_POST) ? trim((string)$_POST['masthead_subtitle']) : $mastheadDefaults['subtitle'],
    'headline_label' => array_key_exists('masthead_headline_label', $_POST) ? trim((string)$_POST['masthead_headline_label']) : $mastheadDefaults['headline_label'],
    'footer' => array_key_exists('masthead_footer', $_POST) ? trim((string)$_POST['masthead_footer']) : $mastheadDefaults['footer'],
    'logo' => pe_safe_newsletter_asset_url((string)($_POST['masthead_logo_url'] ?? '')),
    'header_image' => pe_safe_newsletter_asset_url((string)($_POST['masthead_header_image_url'] ?? '')),
];
$mode = (string)($_POST['mode'] ?? 'all');
$subject = trim((string)($_POST['subject'] ?? ''));
$body = trim((string)($_POST['body'] ?? ''));
$senderNameInput = array_key_exists('sender_name', $_POST) ? trim(strip_tags((string)$_POST['sender_name'])) : $senderDisplayName;
$senderNameInput = $senderNameInput !== '' ? $senderNameInput : $senderDisplayName;
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
    $emailAction = (string)($_POST['email_action'] ?? '');
    if ($presetId !== '' && isset($presets[$presetId])) {
        if ($subject === '') {
            $subject = (string)$presets[$presetId]['subject'];
        }
        if ($body === '') {
            $body = (string)$presets[$presetId]['body'];
        }
    }

    if ($emailAction === 'save_info_note') {
        $result = 'success';
        if (!$isAdmin) {
            $result = 'error';
            $message = 'Only administrators can save the shared Info Note template.';
        } elseif ($subject === '' || $body === '') {
            $result = 'error';
            $message = 'Add a subject and message before saving the Info Note.';
        } else {
            try {
                $safeBody = pe_sanitize_email_html($body);
                $saveTemplate = $pdo->prepare("INSERT INTO parent_email_templates (template_key, subject, body, updated_by)
                    VALUES ('general_info_note', :subject, :body, :updated_by)
                    ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), updated_by = VALUES(updated_by)");
                $saveTemplate->execute([
                    ':subject' => $subject,
                    ':body' => $safeBody,
                    ':updated_by' => $sessionUserId,
                ]);
                $message = 'Info Note template saved. Select it from Quick Preset to use it again.';
            } catch (Throwable $e) {
                error_log('[Parent Email] Could not save Info Note: ' . $e->getMessage());
                $result = 'error';
                $message = 'The Info Note could not be saved. Please try again.';
            }
        }
        $_SESSION['parent_email_flash'] = ['result' => $result, 'message' => $message];
        header('Location: parent-email');
        exit;
    }

    if (in_array($emailAction, ['download_pdf', 'download_semester_pdf'], true)) {
        if ($subject === '' || $body === '') {
            http_response_code(400);
            exit('Add a subject and message before downloading the PDF.');
        }
        try {
            $senderName = $senderNameInput;
            $pdfSubject = pe_apply_tokens($subject, 'Parent', $senderName);
            $pdfBody = pe_sanitize_email_html(pe_apply_tokens($body, 'Parent', $senderName), $templateStyle);
            $pdfBrand = trim((string)$masthead['name']);
            $pdfTitle = trim((string)$masthead['title']);
            $pdfSubtitle = trim((string)$masthead['subtitle']);
            $pdfHeadlineLabel = trim((string)$masthead['headline_label']);
            $pdfFooter = trim((string)$masthead['footer']);
            $pdfHeaderImage = pe_safe_newsletter_asset_url((string)$masthead['header_image']);
            $pdfLogo = pe_safe_newsletter_asset_url((string)$masthead['logo']);
            $mastheadImage = $pdfHeaderImage !== ''
                ? '<img class="header-image" src="' . pe_h($pdfHeaderImage) . '" alt="School newsletter header">'
                : ($pdfLogo !== '' ? '<img class="school-logo" src="' . pe_h($pdfLogo) . '" alt="School logo">' : '');
            $pdfMarkup = $mastheadImage
                . '<header class="masthead">'
                . ($pdfBrand !== '' ? '<div class="school-name">' . pe_h($pdfBrand) . '</div>' : '')
                . ($pdfTitle !== '' ? '<h1>' . pe_h($pdfTitle) . '</h1>' : '')
                . ($pdfSubtitle !== '' ? '<div class="edition">' . pe_h($pdfSubtitle) . '</div>' : '')
                . '</header>'
                . '<main><div class="subject-label">' . pe_h($pdfHeadlineLabel) . '</div><h2 class="subject">' . pe_h($pdfSubject) . '</h2>'
                . '<section class="message">' . $pdfBody . '</section>'
                . '<div class="signature"><div class="regards">Warm regards</div><strong>' . pe_h($senderName) . '</strong><div>' . pe_h($pdfBrand !== '' ? $pdfBrand : 'Bhutanese Language and Culture School') . '</div></div></main>'
                . '<footer><strong>' . pe_h($pdfBrand !== '' ? $pdfBrand : 'Bhutanese Language and Culture School') . '</strong>'
                . ($pdfFooter !== '' ? '<div>' . pe_h($pdfFooter) . '</div>' : '') . '</footer>';
            $pdfMarkup = pe_embed_pdf_upload_images($pdfMarkup);
            $pdfHtml = '<!doctype html><html><head><meta charset="utf-8"><style>
                @page { margin: 32px 38px; }
                body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #293b38; font-size: 11pt; line-height: 1.6; }
                .header-image { display: block; width: 100%; height: auto; margin: 0 0 0; }
                .school-logo { display: block; max-width: 140px; max-height: 72px; margin: 0 0 14px; }
                .masthead { background: #174b46; color: #fff; padding: 18px 24px 20px; border-top: 4px solid #c8a85b; margin-bottom: 24px; }
                .school-name { color: #e7c987; font-size: 9pt; font-weight: bold; text-transform: uppercase; }
                h1 { color: #fff; font: bold 26pt Georgia, serif; line-height: 1.2; margin: 8px 0 0; }
                .edition { color: #d7e7e3; font-size: 9pt; margin-top: 8px; }
                .subject-label { color: #80652f; font-size: 8pt; font-weight: bold; text-transform: uppercase; }
                .subject { color: #203b37; font: bold 19pt Georgia, serif; border-bottom: 1px solid #d3e0dd; padding-bottom: 10px; margin: 6px 0 18px; }
                h2, h3, h4 { page-break-after: avoid; }
                p, li, blockquote { orphans: 3; widows: 3; }
                ul, ol, blockquote, table { page-break-inside: auto; }
                tr, img { page-break-inside: avoid; }
                img { max-width: 100%; height: auto; }
                .message h2 { color: #203b37; font: bold 16pt Georgia, serif; border-bottom: 1px solid #d3e0dd; padding-bottom: 5px; margin: 20px 0 8px; }
                .message h3 { color: #80652f; font-size: 10pt; text-transform: uppercase; }
                .signature { border-top: 1px solid #d3e0dd; margin-top: 24px; padding-top: 14px; }
                .regards { color: #6b7280; font-size: 9pt; text-transform: uppercase; }
                .signature strong { display: block; font-size: 14pt; margin-top: 4px; }
                footer { background: #174b46; border-top: 4px solid #c8a85b; color: #d7e7e3; font-size: 8pt; margin-top: 24px; padding: 14px 18px 16px; }
                footer strong { color: #e7c987; display: block; font-size: 8pt; text-transform: uppercase; }
                footer div { margin-top: 5px; }
                a { color: #174b46; text-decoration: underline; }
            </style></head><body>' . $pdfMarkup . '</body></html>';
            $pdf = new Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $pdf->setPaper('A4', 'portrait');
            $pdf->loadHtml($pdfHtml, 'UTF-8');
            $pdf->render();
            $filename = 'parent-email-' . date('Y-m-d') . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: private, no-store, max-age=0');
            echo $pdf->output();
            exit;
        } catch (Throwable $e) {
            error_log('[Parent Email] PDF export failed: ' . $e->getMessage());
            http_response_code(500);
            exit('The PDF could not be generated. Please check the server error log.');
        }
    }

    if (!in_array($emailAction, ['preview', 'send'], true)) {
        http_response_code(400);
        exit('Choose Preview Email, Download PDF, or Send Email.');
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

    $senderName = $senderNameInput;

    if ($emailAction === 'preview') {
        $sampleRecipient = trim((string)(($recipients[0]['full_name'] ?? 'Parent')));
        $previewSubjectRaw = $subject !== '' ? $subject : 'Sample Subject';
        $previewBodyRaw = $body !== '' ? $body : "This is a sample message preview.\nPlease update message before sending.";
        $previewSubject = pe_apply_tokens($previewSubjectRaw, $sampleRecipient, $senderName);
        $previewBody = pe_apply_tokens($previewBodyRaw, $sampleRecipient, $senderName);
        $previewHtml = pe_build_email_html($sampleRecipient, $previewSubject, $previewBody, $senderName, in_array($templateStyle, ['school_letter', 'newsletter', 'newspaper'], true) ? $templateStyle : 'standard', $masthead);
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

                $subjectFinal = pe_apply_tokens($subject, $name, $senderName);
                $bodyFinal = pe_apply_tokens($body, $name, $senderName);
                $html = pe_build_email_html($name, $subjectFinal, $bodyFinal, $senderName, in_array($templateStyle, ['school_letter', 'newsletter', 'newspaper'], true) ? $templateStyle : 'standard', $masthead);

                if ($queueEnabled) {
                    if (bbcc_queue_mail($email, $name, $subjectFinal, $html, 5, $attachment, $queueMetadata)) {
                        $queued++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $historyId = bbcc_record_direct_mail($email, $name, $subjectFinal, $html, $attachment, $queueMetadata);
                    if ($historyId === null) {
                        $failedDirect++;
                        bbcc_mail_log('PARENT EMAIL DIRECT SEND SKIPPED: unable to create history record for ' . $email);
                        continue;
                    }

                    $attachments = $attachment ? [$attachment] : [];
                    if (send_mail($email, $name, $subjectFinal, $html, 10, $attachments)) {
                        $sentDirect++;
                        if (!bbcc_finish_direct_mail_record($historyId, true)) {
                            bbcc_mail_log('PARENT EMAIL DIRECT SEND HISTORY UPDATE FAIL for row ' . $historyId);
                        }
                    } else {
                        $failedDirect++;
                        $sendError = bbcc_last_mail_error();
                        bbcc_finish_direct_mail_record($historyId, false, $sendError);
                        bbcc_mail_log('PARENT EMAIL DIRECT SEND FAIL to ' . $email . ': ' . $sendError);
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
                    $result = 'success';
                    $message = "Queued {$queued} email(s) for background delivery. {$deliveryReport['sent']} already sent, {$waiting} waiting/retrying, {$deliveryReport['failed']} failed. Skipped {$skipped}.";
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
if (!$isAdmin) {
    // Admins have the full cross-sender history page instead (see the
    // "View Full History" link below) -- no need to also show their own
    // scoped panel here. Teachers don't have access to that page, so they
    // keep this personal recent-sends view.
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
        .email-rich-editor img { display:block;width:100%;max-width:560px;max-height:420px;height:auto;object-fit:cover;margin:14px auto 6px;border:1px solid #d3cec3; }
        .email-rich-editor img.image-size-selected { outline:2px solid #881b12;outline-offset:2px; }
        .email-rich-editor > p:has(img) + p { color:#6b6861;font-size:12px;text-align:center;font-style:italic;margin:0 0 18px; }
        .email-rich-editor.newspaper-editor { font-family:Georgia,serif;background:#fff; }
        .email-rich-editor.newspaper-editor h2 { font-family:Georgia,serif;font-size:22px;color:#203b37;border-bottom:1px solid #d3e0dd;padding-bottom:6px;margin:24px 0 10px; }
        .email-rich-editor.newspaper-editor h3 { font-family:Arial,sans-serif;font-size:13px;color:#80652f;text-transform:uppercase;letter-spacing:.08em;margin:20px 0 8px; }
        .email-rich-editor.newspaper-editor p { margin:0 0 14px; }
        #previewEmailContent table { width:100%;border-collapse:collapse;margin:12px 0; }
        #previewEmailContent th, #previewEmailContent td { border:1px solid #d1d5db;padding:8px;text-align:left;vertical-align:top; }
        #previewEmailContent th { background:#f3f4f6;font-weight:bold; }
        #previewEmailContent blockquote { border-left:4px solid #881b12;margin:12px 0;padding:8px 14px;color:#4b5563; }
        #previewEmailContent img { display:block;width:100%;max-width:560px;max-height:420px;height:auto;object-fit:cover;margin:14px auto 6px;border:1px solid #d3cec3; }
        #previewEmailContent h2 { font-family:Georgia,serif;font-size:22px;color:#203b37;border-bottom:1px solid #d3e0dd;padding-bottom:6px;margin:24px 0 10px; }
        #previewEmailContent p:has(img) + p { color:#6b6861;font-size:12px;text-align:center;font-style:italic;margin:0 0 18px; }
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
                                <div class="form-group col-md-8">
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

                            <div class="d-flex justify-content-end mb-3">
                                <button type="submit" name="email_action" value="download_pdf" class="btn btn-success download-pdf-button">
                                    <i class="fas fa-file-pdf mr-1"></i> Download PDF
                                </button>
                            </div>

                            <div id="mastheadControls" class="border rounded p-3 mb-3" style="<?= $templateStyle === 'newspaper' ? '' : 'display:none;' ?>background:#f1f6f4;border-color:#d3e0dd !important;">
                                <h6 class="font-weight-bold mb-3">Newspaper Masthead</h6>
                                <div class="form-row">
                                    <div class="form-group col-md-4">
                                        <label for="mastheadName">School name</label>
                                        <input type="text" id="mastheadName" name="masthead_name" class="form-control" maxlength="150" value="<?= pe_h($masthead['name']) ?>">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="mastheadTitle">Masthead title</label>
                                        <input type="text" id="mastheadTitle" name="masthead_title" class="form-control" maxlength="120" value="<?= pe_h($masthead['title']) ?>">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="mastheadSubtitle">Edition line</label>
                                        <input type="text" id="mastheadSubtitle" name="masthead_subtitle" class="form-control" maxlength="180" value="<?= pe_h($masthead['subtitle']) ?>">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-md-4">
                                        <label for="mastheadHeadlineLabel">Headline label</label>
                                        <input type="text" id="mastheadHeadlineLabel" name="masthead_headline_label" class="form-control" maxlength="60" value="<?= pe_h($masthead['headline_label']) ?>">
                                    </div>
                                    <div class="form-group col-md-8">
                                        <label for="mastheadFooter">Footer note</label>
                                        <input type="text" id="mastheadFooter" name="masthead_footer" class="form-control" maxlength="300" value="<?= pe_h($masthead['footer']) ?>">
                                    </div>
                                </div>
                                <div class="form-group mb-0">
                                    <label>Header logo</label>
                                    <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
                                        <button type="button" id="headerLogoButton" class="btn btn-sm btn-outline-secondary"><i class="fas fa-image mr-1"></i> Add logo</button>
                                        <button type="button" id="removeHeaderLogoButton" class="btn btn-sm btn-outline-danger" <?= $masthead['logo'] === '' ? 'hidden' : '' ?>><i class="fas fa-times mr-1"></i> Remove</button>
                                        <input type="file" id="headerLogoInput" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none">
                                        <input type="hidden" name="masthead_logo_url" id="mastheadLogoUrl" value="<?= pe_h($masthead['logo']) ?>">
                                        <div id="headerLogoPreview" style="<?= $masthead['logo'] === '' ? 'display:none;' : '' ?>padding:8px 12px;border:1px solid #d8d3c8;background:#fff;">
                                            <img src="<?= pe_h($masthead['logo']) ?>" alt="Header logo preview" style="display:block;max-width:140px;max-height:72px;width:auto;height:auto;">
                                        </div>
                                        <small id="headerLogoStatus" class="text-muted">JPG, PNG, GIF or WebP, up to 5 MB.</small>
                                    </div>
                                </div>
                                <div class="form-group mt-3 mb-0">
                                    <label>Header image <span class="text-muted">(full-width; masthead wording stays below)</span></label>
                                    <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
                                        <button type="button" id="headerImageButton" class="btn btn-sm btn-outline-secondary"><i class="fas fa-panorama mr-1"></i> Add header image</button>
                                        <button type="button" id="removeHeaderImageButton" class="btn btn-sm btn-outline-danger" <?= $masthead['header_image'] === '' ? 'hidden' : '' ?>><i class="fas fa-times mr-1"></i> Remove</button>
                                        <input type="file" id="headerImageInput" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none">
                                        <input type="hidden" name="masthead_header_image_url" id="mastheadHeaderImageUrl" value="<?= pe_h($masthead['header_image']) ?>">
                                        <small id="headerImageStatus" class="text-muted">Editable school name, title and edition line appear in a green band below the image. JPG, PNG, GIF or WebP, up to 5 MB.</small>
                                    </div>
                                    <div id="headerImagePreview" class="mt-2" style="<?= $masthead['header_image'] === '' ? 'display:none;' : '' ?>max-width:656px;padding:8px;border:1px solid #d8d3c8;background:#fff;">
                                        <img src="<?= pe_h($masthead['header_image']) ?>" alt="Header image preview" style="display:block;width:100%;max-width:640px;height:auto;">
                                    </div>
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
                                <label for="senderNameInput">Sender name</label>
                                <input type="text" name="sender_name" id="senderNameInput" class="form-control" maxlength="120" value="<?= pe_h($senderNameInput) ?>">
                                <small class="form-text text-muted">Shown beneath “Warm regards” in newsletter and semester templates.</small>
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
                                    <button type="button" class="btn btn-sm btn-light" id="editorStoryButton" title="Add a story section"><i class="fas fa-newspaper"></i> Add Story</button>
                                    <button type="button" class="btn btn-sm btn-light" id="insertNewsletterImageButton" title="Insert image"><i class="fas fa-image"></i></button>
                                    <select id="imageSizeSelect" class="form-control form-control-sm" style="width:auto;" title="Choose the selected photo size" disabled>
                                        <option value="">Photo size</option>
                                        <option value="240">Small</option>
                                        <option value="400">Medium</option>
                                        <option value="560">Large</option>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-light" id="editorTableButton" title="Insert table"><i class="fas fa-table"></i></button>
                                    <button type="button" class="btn btn-sm btn-light editor-command" data-command="removeFormat" title="Clear formatting"><i class="fas fa-eraser"></i></button>
                                </div>
                                <div id="bodyEditor" class="email-rich-editor <?= $templateStyle === 'newspaper' ? 'newspaper-editor' : '' ?>" contenteditable="true" role="textbox" aria-multiline="true"><?= pe_sanitize_email_html($body) ?></div>
                                <textarea name="body" id="bodyInput" class="d-none" aria-hidden="true"><?= pe_h($body) ?></textarea>
                                <input type="file" id="newsletterImageInput" accept="image/jpeg,image/png,image/gif" class="d-none">
                                <small class="text-muted" id="newsletterImageStatus">Click a photo, then choose Small, Medium or Large. New photos start at Medium. JPG, PNG, GIF or WebP up to 5 MB.</small>
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
                            <button type="submit" id="downloadPdfButton" name="email_action" value="download_pdf" class="btn btn-outline-success download-pdf-button">
                                <i class="fas fa-file-pdf mr-1"></i> Download PDF
                            </button>
                            <?php if ($isAdmin): ?>
                                <button type="submit" name="email_action" value="save_info_note" class="btn btn-outline-secondary">
                                    <i class="fas fa-save mr-1"></i> Save Info Note
                                </button>
                            <?php endif; ?>
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

                <?php if ($isAdmin): ?>
                <div class="card shadow mt-3">
                    <div class="card-body d-flex align-items-center justify-content-between flex-wrap" style="gap:12px;">
                        <div>
                            <h6 class="m-0 font-weight-bold text-primary">Delivery Status</h6>
                            <small class="text-muted">See every parent email sent by any admin or teacher, with filters by status and sender.</small>
                        </div>
                        <a href="admin-parent-email-log" class="btn btn-outline-primary">
                            <i class="fas fa-history mr-1"></i> View Full History
                        </a>
                    </div>
                </div>
                <?php else: ?>
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
                                            'sending' => 'Sending',
                                            'retry' => 'Retrying',
                                            'failed' => 'Failed',
                                            default => 'Queued',
                                        };
                                        $deliveryBadge = match ($deliveryStatus) {
                                            'sent' => 'success',
                                            'sending' => 'info',
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
                <?php endif; ?>
            </div>
        </div>
        <?php include 'include/admin-footer.php'; ?>
    </div>
</div>

<script src="vendor/jquery/jquery.min.js"></script>
<script>
$(function () {
    var presets = <?= json_encode($presets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var mastheadPresetDefaults = <?= json_encode([
        'standard' => pe_masthead_defaults('standard'),
        'newsletter' => pe_masthead_defaults('newsletter'),
        'newspaper' => pe_masthead_defaults('newspaper'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var allParents = <?= json_encode(array_map(static function (array $parent): array {
        return [
            'id' => (int)($parent['id'] ?? 0),
            'name' => (string)($parent['full_name'] ?? 'Parent'),
        ];
    }, $parents), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function parseImageUploadResponse(response) {
        return response.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (error) {
                if (response.status === 413 || text === '') {
                    throw new Error('The image upload exceeded the server request limit. Raise cPanel post_max_size to at least 6 MB.');
                }
                throw new Error('The upload handler returned a page instead of an image result. Check that parent-email.php is deployed and review the cPanel PHP error log.');
            }
        });
    }

    function applyPreviewTokens(value, parentName, senderName) {
        var firstName = String(parentName || '').trim().split(/\s+/)[0] || 'Parent';
        var schoolName = 'Bhutanese Language and Culture School';
        var principalName = String(senderName || '').trim() || 'Principal';
        var semesterName = 'Semester';
        var schoolYear = new Date().getFullYear();

        return String(value || '')
            .split('{PARENT_NAME}').join(firstName)
            .split('{parent_name}').join(firstName)
            .split('{SCHOOL_NAME}').join(schoolName)
            .split('{school_name}').join(schoolName)
            .split('{PRINCIPAL_NAME}').join(principalName)
            .split('{principal_name}').join(principalName)
            .split('{SEMESTER_NAME}').join(semesterName)
            .split('{semester_name}').join(semesterName)
            .split('{SCHOOL_YEAR}').join(String(schoolYear))
            .split('{school_year}').join(String(schoolYear));
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
        var allowed = ['P','DIV','BR','STRONG','B','EM','I','U','S','H1','H2','H3','H4','UL','OL','LI','BLOCKQUOTE','TABLE','THEAD','TBODY','TFOOT','TR','TH','TD','A','SPAN','IMG'];
        Array.from(template.content.querySelectorAll('*')).forEach(function (node) {
            if (allowed.indexOf(node.tagName) === -1) {
                node.replaceWith.apply(node, Array.from(node.childNodes));
                return;
            }
            Array.from(node.attributes).forEach(function (attribute) {
                var permitted = (node.tagName === 'A' && ['href','target'].indexOf(attribute.name.toLowerCase()) !== -1) ||
                    (node.tagName === 'IMG' && ['src','alt','title','width'].indexOf(attribute.name.toLowerCase()) !== -1) ||
                    (['TD','TH'].indexOf(node.tagName) !== -1 && ['colspan','rowspan'].indexOf(attribute.name.toLowerCase()) !== -1);
                if (!permitted) node.removeAttribute(attribute.name);
            });
            if (node.tagName === 'A') {
                var href = node.getAttribute('href') || '';
                if (!/^(https?:\/\/|mailto:)/i.test(href)) node.removeAttribute('href');
                else node.setAttribute('target', '_blank');
            }
            if (node.tagName === 'IMG') {
                if (!/^https?:\/\//i.test(node.getAttribute('src') || '')) {
                    node.remove();
                } else {
                    var imageWidth = parseInt(node.getAttribute('width') || '560', 10);
                    imageWidth = Math.max(240, Math.min(560, imageWidth || 560));
                    node.setAttribute('width', String(imageWidth));
                    node.style.width = '100%';
                    node.style.maxWidth = imageWidth + 'px';
                    node.style.maxHeight = '420px';
                    node.style.height = 'auto';
                    node.style.display = 'block';
                    node.style.margin = '14px auto 6px';
                }
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

    function buildPreviewHtml(subject, bodyHtml, style, masthead, senderName) {
        var safeSubject = escapeHtml(subject);
        var safeBody = cleanPreviewHtml(bodyHtml);
        masthead = masthead || {};
        var defaults = mastheadPresetDefaults[style] || mastheadPresetDefaults.standard;
        var brandName = masthead.name == null ? defaults.name : String(masthead.name);
        var heading = masthead.title == null ? defaults.title : String(masthead.title);
        var subtitle = masthead.subtitle == null ? defaults.subtitle : String(masthead.subtitle);
        var newsletter = style === 'newsletter';
        var newspaper = style === 'newspaper';
        var pageBackground = newspaper ? '#eaf1ef' : '#eef3f2';
        var mastheadBackground = newspaper ? '#174b46' : (newsletter ? '#741f1b' : '#f7faf9');
        var mastheadColor = newspaper ? '#fff' : (newsletter ? '#fff' : '#1f3734');
        var mastheadRule = newspaper ? 'border-bottom:4px solid #c8a85b;' : (newsletter ? 'border-bottom:4px solid #d5a84b;' : 'border-bottom:1px solid #e1e9e7;');
        var mastheadFont = newspaper ? 'font-family:Georgia,serif;' : '';
        var headlineLabel = masthead.headline_label == null ? defaults.headline_label : String(masthead.headline_label);
        var footerText = masthead.footer == null ? defaults.footer : String(masthead.footer);
        var footerBackground = newspaper ? '#174b46' : (newsletter ? '#741f1b' : '#f7faf9');
        var footerAccent = newspaper ? '#c8a85b' : (newsletter ? '#d5a84b' : '#e1e9e7');
        var footerBrandColor = newspaper ? '#e7c987' : (newsletter ? '#f4dfac' : '#6f2521');
        var footerTextColor = newspaper ? '#d7e7e3' : (newsletter ? '#f5e6c5' : '#6b7280');
        var headlineStyle = newspaper
            ? 'font-family:Georgia,serif;font-size:28px;font-weight:bold;color:#203b37;line-height:1.2;border-bottom:1px solid #d3e0dd;padding-bottom:14px;'
            : 'font-size:20px;font-weight:bold;color:#111827;line-height:1.3;';
        var contentStyle = newspaper
            ? 'padding:8px 32px 10px;font-family:Georgia,serif;font-size:16px;line-height:1.75;color:#293b38;'
            : 'padding:8px 24px 10px;font-size:15px;line-height:1.7;color:#1f2937;';
        var signature = (newsletter || newspaper)
            ? '<div style="margin-top:22px;padding-top:16px;border-top:1px solid #e5e7eb;"><div style="font-size:12px;color:#6b7280;text-transform:uppercase;">Warm regards</div><div style="font-size:18px;font-weight:bold;color:#111827;">' + escapeHtml(senderName || 'School Administration') + '</div><div style="font-size:13px;color:#6b7280;">' + escapeHtml(brandName || 'Bhutanese Language and Culture School') + '</div></div>'
            : '';
        var logoMarkup = newspaper && masthead.logo
            ? '<div style="margin-bottom:12px;"><img src="' + escapeHtml(masthead.logo) + '" width="140" alt="School logo" style="display:block;width:auto;max-width:140px;max-height:72px;height:auto;border:0;margin:0;"></div>'
            : '';
        var headerImageMarkup = newspaper && masthead.header_image
            ? '<img src="' + escapeHtml(masthead.header_image) + '" width="720" alt="School newsletter header" style="display:block;width:100%;max-width:720px;height:auto;border:0;margin:0;">'
            : '';
        var mastheadImageMarkup = headerImageMarkup || logoMarkup;
        var fullHeaderImage = headerImageMarkup !== '';
        return '<div style="margin:0;padding:24px 0;background:' + pageBackground + ';font-family:Arial,sans-serif;color:#1f2937;">' +
            '<div style="max-width:' + (newspaper ? '720px' : '700px') + ';margin:0 auto;background:#fff;border:1px solid ' + (newspaper ? '#cddbd8' : '#dce5e3') + ';border-radius:10px;box-shadow:0 12px 36px rgba(31,55,52,.09);overflow:hidden;">' +
                '<div style="background:' + mastheadBackground + ';' + (fullHeaderImage ? 'padding:0;' : 'padding:22px 28px;') + 'color:' + mastheadColor + ';' + (fullHeaderImage ? '' : mastheadRule) + '">' +
                    mastheadImageMarkup +
                    (fullHeaderImage ? '<div style="background:#174b46;padding:18px 28px 22px;border-top:4px solid #c8a85b;">' + (brandName !== '' ? '<div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#e7c987;font-weight:bold;">' + escapeHtml(brandName) + '</div>' : '') + (heading !== '' ? '<div style="font-family:Georgia,serif;font-size:34px;font-weight:bold;color:#fff;line-height:1.2;margin-top:8px;">' + escapeHtml(heading) + '</div>' : '') + (subtitle !== '' ? '<div style="font-size:12px;letter-spacing:.08em;color:#d7e7e3;margin-top:8px;">' + escapeHtml(subtitle) + '</div>' : '') + '</div>' : '') +
                    (!fullHeaderImage && brandName !== '' ? '<div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:' + (newspaper ? '#e7c987' : (newsletter ? '#f4dfac' : '#6f2521')) + ';font-weight:bold;">' + escapeHtml(brandName) + '</div>' : '') +
                    (!fullHeaderImage && heading !== '' ? '<div style="' + mastheadFont + 'font-size:' + (newspaper ? '34px' : '22px') + ';font-weight:bold;line-height:1.2;margin-top:8px;">' + escapeHtml(heading) + '</div>' : '') +
                    (!fullHeaderImage && subtitle !== '' ? '<div style="font-size:12px;letter-spacing:.08em;color:' + (newspaper ? '#d7e7e3' : (newsletter ? '#f5e6c5' : '#6b7280')) + ';margin-top:8px;">' + escapeHtml(subtitle) + '</div>' : '') +
                '</div>' +
                '<div style="padding:22px 24px 10px;">' +
                    '<div style="font-size:11px;color:' + (newspaper ? '#80652f' : '#7b1f1a') + ';margin-bottom:8px;text-transform:uppercase;letter-spacing:.14em;font-weight:bold;">' + headlineLabel + '</div>' +
                    '<div style="' + headlineStyle + '">' + safeSubject + '</div>' +
                '</div>' +
                '<div style="' + contentStyle + '"><div style="margin:0 0 14px;">' + safeBody + '</div>' + signature + '</div>' +
                '<div style="background:' + footerBackground + ';border-top:4px solid ' + footerAccent + ';padding:14px 24px 18px;">' +
                    '<div style="font-size:11px;color:' + footerBrandColor + ';font-weight:bold;text-transform:uppercase;">' + escapeHtml(brandName || 'Bhutanese Language and Culture School') + '</div>' +
                    '<div style="font-size:12px;color:' + footerTextColor + ';line-height:1.6;margin-top:6px;">' + escapeHtml(footerText) + '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    $('#modeSelect').on('change', function () {
        $('#parentPickerWrap').toggle($(this).val() === 'selected');
    });

    $('#presetSelect').on('change', function () {
        var key = $(this).val();
        var mastheadStyle = key === 'semester_update'
            ? 'newspaper'
            : (key === 'general_info_note' ? 'newsletter' : 'standard');
        var mastheadDefaults = mastheadPresetDefaults[mastheadStyle];
        $('#mastheadControls').toggle(mastheadStyle === 'newspaper');
        $('#mastheadName').val(mastheadDefaults.name);
        $('#mastheadTitle').val(mastheadDefaults.title);
        $('#mastheadSubtitle').val(mastheadDefaults.subtitle);
        $('#mastheadHeadlineLabel').val(mastheadDefaults.headline_label);
        $('#mastheadFooter').val(mastheadDefaults.footer);
        $('#bodyEditor').toggleClass('newspaper-editor', key === 'semester_update');
        if (!key || !presets[key]) {
            $('#previewEmailCard').hide();
            return;
        }
        $('#subjectInput').val(presets[key].subject || '');
        var presetBody = presets[key].body || '';
        $('#bodyEditor').html(presets[key].body_is_html ? presetBody : plainTextToEditorHtml(presetBody));
        syncEditorBody();
        $('#previewEmailCard').hide();
    });

    $('#headerLogoButton').on('click', function () {
        $('#headerLogoInput').trigger('click');
    });

    $('#headerLogoInput').on('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;
        var formData = new FormData();
        formData.append('email_action', 'upload_newsletter_logo');
        formData.append('_csrf', $('input[name="_csrf"]').val() || '');
        formData.append('newsletter_logo', file);
        $('#headerLogoStatus').text('Uploading logo...');
        fetch(window.location.href, {method:'POST', body:formData, credentials:'same-origin'})
            .then(parseImageUploadResponse)
            .then(function (result) {
                if (!result.ok) throw new Error(result.error || 'Logo upload failed.');
                $('#mastheadLogoUrl').val(result.url);
                var image = document.createElement('img');
                image.src = result.url;
                image.alt = 'Header logo preview';
                image.style.display = 'block';
                image.style.maxWidth = '140px';
                image.style.maxHeight = '72px';
                image.style.width = 'auto';
                image.style.height = 'auto';
                $('#headerLogoPreview').empty().append(image).show();
                $('#removeHeaderLogoButton').prop('hidden', false);
                $('#headerLogoStatus').text('Logo added to the newspaper header.');
            })
            .catch(function (error) {
                $('#headerLogoStatus').text(error.message || 'Logo upload failed.');
            })
            .finally(function () {
                $('#headerLogoInput').val('');
            });
    });

    $('#removeHeaderLogoButton').on('click', function () {
        $('#mastheadLogoUrl').val('');
        $('#headerLogoPreview').empty().hide();
        $(this).prop('hidden', true);
        $('#headerLogoStatus').text('Logo removed from this message.');
    });

    $('#headerImageButton').on('click', function () {
        $('#headerImageInput').trigger('click');
    });

    $('#headerImageInput').on('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;
        var formData = new FormData();
        formData.append('email_action', 'upload_newsletter_header');
        formData.append('_csrf', $('input[name="_csrf"]').val() || '');
        formData.append('newsletter_header', file);
        $('#headerImageStatus').text('Uploading header image...');
        fetch(window.location.href, {method:'POST', body:formData, credentials:'same-origin'})
            .then(parseImageUploadResponse)
            .then(function (result) {
                if (!result.ok) throw new Error(result.error || 'Header image upload failed.');
                $('#mastheadHeaderImageUrl').val(result.url);
                var image = document.createElement('img');
                image.src = result.url;
                image.alt = 'Header image preview';
                image.style.display = 'block';
                image.style.width = '100%';
                image.style.maxWidth = '640px';
                image.style.height = 'auto';
                $('#headerImagePreview').empty().append(image).show();
                $('#removeHeaderImageButton').prop('hidden', false);
                $('#headerImageStatus').text('Full-width header added. Editable masthead wording will appear in a green band below.');
            })
            .catch(function (error) {
                $('#headerImageStatus').text(error.message || 'Header image upload failed.');
            })
            .finally(function () {
                $('#headerImageInput').val('');
            });
    });

    $('#removeHeaderImageButton').on('click', function () {
        $('#mastheadHeaderImageUrl').val('');
        $('#headerImagePreview').empty().hide();
        $(this).prop('hidden', true);
        $('#headerImageStatus').text('Header image removed. The logo will be used if one is set.');
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

    $('#editorStoryButton').on('click', function () {
        var editor = document.getElementById('bodyEditor');
        editor.focus();
        document.execCommand('insertHTML', false, '<h2>Story headline</h2><p>Add your story text here.</p><p><br></p>');
        syncEditorBody();
    });

    var selectedNewsletterImage = null;
    function selectNewsletterImage(image) {
        if (selectedNewsletterImage) {
            selectedNewsletterImage.classList.remove('image-size-selected');
        }
        selectedNewsletterImage = image || null;
        var sizeSelect = $('#imageSizeSelect');
        if (!selectedNewsletterImage) {
            sizeSelect.val('').prop('disabled', true);
            return;
        }

        selectedNewsletterImage.classList.add('image-size-selected');
        var currentWidth = parseInt(selectedNewsletterImage.getAttribute('width') || '400', 10);
        var sizes = [240, 400, 560];
        var nearestSize = sizes.reduce(function (nearest, size) {
            return Math.abs(size - currentWidth) < Math.abs(nearest - currentWidth) ? size : nearest;
        }, sizes[0]);
        sizeSelect.val(String(nearestSize)).prop('disabled', false);
    }

    $('#bodyEditor').on('click', function (event) {
        selectNewsletterImage(event.target.closest('img'));
    });

    $('#imageSizeSelect').on('change', function () {
        if (!selectedNewsletterImage || !this.value) return;
        var imageWidth = Math.max(240, Math.min(560, parseInt(this.value, 10) || 400));
        selectedNewsletterImage.setAttribute('width', String(imageWidth));
        selectedNewsletterImage.style.width = '100%';
        selectedNewsletterImage.style.maxWidth = imageWidth + 'px';
        selectedNewsletterImage.style.maxHeight = '420px';
        selectedNewsletterImage.style.height = 'auto';
        syncEditorBody();
    });

    var savedEditorRange = null;
    $('#insertNewsletterImageButton').on('click', function () {
        var editor = document.getElementById('bodyEditor');
        var selection = window.getSelection();
        if (selection && selection.rangeCount && editor.contains(selection.anchorNode)) {
            savedEditorRange = selection.getRangeAt(0).cloneRange();
        }
        $('#newsletterImageInput').trigger('click');
    });

    $('#newsletterImageInput').on('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;
        var formData = new FormData();
        formData.append('email_action', 'upload_inline_image');
        formData.append('_csrf', $('input[name="_csrf"]').val() || '');
        formData.append('newsletter_image', file);
        $('#newsletterImageStatus').text('Uploading image...');
        fetch(window.location.href, {method:'POST', body:formData, credentials:'same-origin'})
            .then(parseImageUploadResponse)
            .then(function (result) {
                if (!result.ok) throw new Error(result.error || 'Image upload failed.');
                var editor = document.getElementById('bodyEditor');
                editor.focus();
                var selection = window.getSelection();
                if (selection && savedEditorRange) {
                    selection.removeAllRanges();
                    selection.addRange(savedEditorRange);
                }
                document.execCommand('insertHTML', false, '<p><img src="' + escapeHtml(result.url) + '" alt="' + escapeHtml(file.name) + '" width="400" style="width:100%;max-width:400px;height:auto;"></p><p><em>Photo caption</em></p>');
                var images = editor.querySelectorAll('img');
                selectNewsletterImage(images.length ? images[images.length - 1] : null);
                syncEditorBody();
                $('#newsletterImageStatus').text('Image added to the message.');
            })
            .catch(function (error) {
                $('#newsletterImageStatus').text(error.message || 'Image upload failed.');
            })
            .finally(function () {
                $('#newsletterImageInput').val('');
                savedEditorRange = null;
            });
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

    $('.download-pdf-button').on('click', function (event) {
        event.preventDefault();
        var form = this.form;
        syncEditorBody();
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'email_action';
        actionInput.value = 'download_pdf';
        form.appendChild(actionInput);
        HTMLFormElement.prototype.submit.call(form);
    });

    $('#previewEmailButton').on('click', function () {
        var recipients = previewRecipients();
        if (!recipients.length) {
            window.alert('Please select at least one parent.');
            return;
        }

        var sampleName = recipients[0].name || 'Parent';
        var senderName = $('#senderNameInput').val();
        var subject = applyPreviewTokens($('#subjectInput').val() || 'Sample Subject', sampleName, senderName);
        syncEditorBody();
        var bodyHtml = applyPreviewTokens(
            $('#bodyInput').val() || plainTextToEditorHtml('This is a sample message preview.\nPlease update the message before sending.'),
            sampleName,
            senderName
        );

        var presetKey = $('#presetSelect').val();
        var templateStyle = presetKey === 'semester_update'
            ? 'newspaper'
            : (presetKey === 'general_info_note' ? 'newsletter' : 'standard');
        var previewMasthead = {
            name: $('#mastheadName').val(),
            title: $('#mastheadTitle').val(),
            subtitle: $('#mastheadSubtitle').val(),
            headline_label: $('#mastheadHeadlineLabel').val(),
            footer: $('#mastheadFooter').val(),
            logo: $('#mastheadLogoUrl').val(),
            header_image: $('#mastheadHeaderImageUrl').val()
        };
        $('#previewSubjectText').text(subject);
        $('#previewRecipientCount').text('Recipients in scope: ' + recipients.length);
        $('#previewEmailContent').html(buildPreviewHtml(subject, bodyHtml, templateStyle, previewMasthead, $('#senderNameInput').val()));
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
