<?php
// include/pcm_helpers.php — Shared helpers for Parent Class Management module
// All functions are pure — they receive $pdo where needed.

require_once __DIR__ . '/mailer.php';

function pcm_env(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function pcm_admin_notify_email(): string {
    $fallback = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : '';
    return pcm_env('ADMIN_NOTIFY_EMAIL', $fallback);
}

function pcm_ensure_fees_campus_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $stmt1 = $pdo->query("SHOW COLUMNS FROM fees_settings LIKE 'campus_one_name'");
    if (!$stmt1 || !$stmt1->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE fees_settings ADD COLUMN campus_one_name VARCHAR(150) NULL AFTER amount_yearly");
    }

    $stmt2 = $pdo->query("SHOW COLUMNS FROM fees_settings LIKE 'campus_two_name'");
    if (!$stmt2 || !$stmt2->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE fees_settings ADD COLUMN campus_two_name VARCHAR(150) NULL AFTER campus_one_name");
    }
    $done = true;
}

function pcm_campus_names(): array {
    $campus1 = '';
    $campus2 = '';
    try {
        $pdo = pcm_pdo();
        pcm_ensure_fees_campus_columns($pdo);
        $row = $pdo->query("SELECT campus_one_name, campus_two_name FROM fees_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $campus1 = trim((string)($row['campus_one_name'] ?? ''));
        $campus2 = trim((string)($row['campus_two_name'] ?? ''));
    } catch (Throwable $e) {
        // fallback to env defaults below
    }
    if ($campus1 === '') $campus1 = trim(pcm_env('CAMPUS_ONE_NAME', 'North Canberra Campus'));
    if ($campus2 === '') $campus2 = trim(pcm_env('CAMPUS_TWO_NAME', 'South Canberra Campus'));
    if ($campus1 === '') $campus1 = 'Campus 1';
    if ($campus2 === '') $campus2 = 'Campus 2';
    return [$campus1, $campus2];
}

function pcm_campus_choice_labels(): array {
    [$campus1, $campus2] = pcm_campus_names();
    return [
        'c1'   => $campus1,
        'c2'   => $campus2,
    ];
}

function pcm_normalize_campus_selection(string $stored): array {
    $stored = strtolower(trim($stored));
    if ($stored === '' || $stored === 'any') {
        return ['c1'];
    }
    if ($stored === 'both') {
        return ['c1', 'c2'];
    }
    $parts = array_filter(array_map('trim', explode(',', $stored)));
    $allowed = ['c1', 'c2'];
    $out = [];
    foreach ($parts as $p) {
        if (in_array($p, $allowed, true) && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    if (empty($out)) {
        $out = ['c1'];
    }
    return $out;
}

function pcm_campus_selection_label(string $stored): string {
    $labels = pcm_campus_choice_labels();
    $selected = pcm_normalize_campus_selection($stored);
    $mapped = [];
    foreach ($selected as $k) {
        if (isset($labels[$k])) $mapped[] = $labels[$k];
    }
    return implode(' + ', $mapped);
}

function pcm_ensure_enrolment_campus_preference(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $stmt = $pdo->query("SHOW COLUMNS FROM pcm_enrolments LIKE 'campus_preference'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE pcm_enrolments ADD COLUMN campus_preference VARCHAR(20) NOT NULL DEFAULT 'any' AFTER fee_plan");
    }
    $done = true;
}

function pcm_ensure_enrolment_audit_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pcm_enrolment_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            enrolment_id INT NULL,
            student_id INT NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            actor VARCHAR(150) NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_student (student_id),
            KEY idx_enrolment (enrolment_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function pcm_log_enrolment_event(PDO $pdo, int $studentId, ?int $enrolmentId, string $eventType, string $actor = '', string $details = ''): void {
    if ($studentId <= 0 || trim($eventType) === '') return;
    try {
        pcm_ensure_enrolment_audit_table($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO pcm_enrolment_audit (enrolment_id, student_id, event_type, actor, details)
            VALUES (:eid, :sid, :ev, :ac, :dt)
        ");
        $stmt->execute([
            ':eid' => $enrolmentId ?: null,
            ':sid' => $studentId,
            ':ev'  => trim($eventType),
            ':ac'  => trim($actor) !== '' ? trim($actor) : null,
            ':dt'  => trim($details) !== '' ? trim($details) : null,
        ]);
    } catch (Throwable $e) {
        // Non-blocking logging.
    }
}

function pcm_admin_portal_url(): string {
    $base = defined('BASE_URL') ? (string)BASE_URL : pcm_env('BASE_URL', '');
    if ($base === '') {
        return 'login';
    }
    return rtrim($base, '/') . '/admin-enrolments';
}

// ─── DB shortcut ──────────────────────────────────────────
function pcm_pdo(): PDO {
    global $DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
            $DB_USER, $DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

// ─── HTML escape shortcut ─────────────────────────────────
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// ─── Fee plan helpers ─────────────────────────────────────
function pcm_plan_instalments(string $plan): array {
    $p = strtolower(trim($plan));
    if ($p === 'term-wise')    return ['Term 1','Term 2','Term 3','Term 4'];
    if ($p === 'half-yearly')  return ['Half 1','Half 2'];
    if ($p === 'yearly')       return ['Yearly'];
    return [];
}

function pcm_plan_amount(string $plan): float {
    $p = strtolower(trim($plan));
    if ($p === 'term-wise')   return 65.00;
    if ($p === 'half-yearly') return 125.00;
    if ($p === 'yearly')      return 250.00;
    return 0.00;
}

function pcm_instalment_amount(string $plan, string $label): float {
    // Every instalment costs the same per plan
    return pcm_plan_amount($plan);
}

// ─── Create fee rows when enrolment is approved ───────────
function pcm_create_fee_rows(PDO $pdo, int $enrolmentId, int $studentId, int $parentId, string $plan, ?string $firstProof): void {
    $labels = pcm_plan_instalments($plan);
    foreach ($labels as $i => $label) {
        $due = pcm_instalment_amount($plan, $label);
        // First instalment uses enrolment proof
        $proof  = ($i === 0 && $firstProof) ? $firstProof : null;
        $status = ($i === 0 && $firstProof) ? 'Verified' : 'Unpaid';
        $paid   = ($status === 'Verified') ? $due : 0;

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO pcm_fee_payments
                (enrolment_id, student_id, parent_id, plan_type, instalment_label, due_amount, paid_amount, proof_path, status)
            VALUES (:eid, :sid, :pid, :plan, :label, :due, :paid, :proof, :st)
        ");
        $stmt->execute([
            ':eid'   => $enrolmentId,
            ':sid'   => $studentId,
            ':pid'   => $parentId,
            ':plan'  => $plan,
            ':label' => $label,
            ':due'   => $due,
            ':paid'  => $paid,
            ':proof' => $proof,
            ':st'    => $status,
        ]);
    }
}

// ─── Badge CSS class from status string ───────────────────
function pcm_badge(string $status): string {
    $s = strtolower(trim($status));
    if (in_array($s, ['approved','verified']))  return 'success';
    if (in_array($s, ['rejected']))             return 'danger';
    if (in_array($s, ['pending']))              return 'warning';
    return 'secondary';
}

function pcm_students_parent_column(PDO $pdo): string {
    static $col = null;
    if ($col !== null) {
        return $col;
    }

    $hasSnake = false;
    $stmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'parent_id'");
    if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hasSnake = true;
    }
    $col = $hasSnake ? 'parent_id' : 'parentId';
    return $col;
}

function pcm_fetch_student_review_context(PDO $pdo, int $studentId): ?array {
    $parentCol = pcm_students_parent_column($pdo);
    $sql = "
        SELECT s.*,
               p.full_name AS parent_name,
               p.email AS parent_email,
               e.id AS pcm_enrolment_id,
               e.fee_plan AS pcm_fee_plan,
               e.fee_amount AS pcm_fee_amount,
               e.payment_ref AS pcm_payment_ref,
               e.proof_path AS pcm_proof_path,
               e.status AS pcm_status
        FROM students s
        LEFT JOIN parents p ON p.id = s.`{$parentCol}`
        LEFT JOIN pcm_enrolments e ON e.student_id = s.id
        WHERE s.id = :id
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pcm_process_enrolment_decision(PDO $pdo, int $studentId, string $action, string $reviewer, string $note = ''): array {
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException("Invalid action.");
    }

    $ctx = pcm_fetch_student_review_context($pdo, $studentId);
    if (!$ctx) {
        throw new RuntimeException("Student not found.");
    }

    $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';
    $parentCol = pcm_students_parent_column($pdo);
    $parentId = (int)($ctx[$parentCol] ?? 0);

    $planType = trim((string)($ctx['pcm_fee_plan'] ?? ''));
    if ($planType === '') {
        $planType = trim((string)($ctx['payment_plan'] ?? ''));
    }
    if ($planType === '') {
        $planType = 'Term-wise';
    }

    $proofPath = $ctx['pcm_proof_path'] ?? null;
    if (!$proofPath) {
        $proofPath = $ctx['payment_proof'] ?? null;
    }

    $feeAmount = (float)($ctx['pcm_fee_amount'] ?? 0);
    if ($feeAmount <= 0) {
        $feeAmount = (float)($ctx['payment_amount'] ?? 0);
    }
    if ($feeAmount <= 0) {
        $feeAmount = pcm_plan_amount($planType);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE students SET approval_status = :st WHERE id = :id")
            ->execute([':st' => $newStatus, ':id' => $studentId]);

        $pcmEnrolmentId = (int)($ctx['pcm_enrolment_id'] ?? 0);
        if ($pcmEnrolmentId > 0) {
            $pdo->prepare("
                UPDATE pcm_enrolments
                SET status = :st, admin_note = :n, reviewed_by = :rb, reviewed_at = NOW()
                WHERE id = :id
            ")->execute([
                ':st' => $newStatus,
                ':n'  => ($note !== '' ? $note : null),
                ':rb' => $reviewer,
                ':id' => $pcmEnrolmentId
            ]);

            if ($newStatus === 'Approved') {
                pcm_create_fee_rows($pdo, $pcmEnrolmentId, $studentId, $parentId, $planType, $proofPath);
            }
        }

        $oldFees = $pdo->prepare("SELECT COUNT(*) FROM fees_payments WHERE student_id = :sid");
        $oldFees->execute([':sid' => (string)$studentId]);
        if ((int)$oldFees->fetchColumn() > 0) {
            if ($newStatus === 'Approved') {
                $firstCode = ($planType === 'Term-wise') ? 'TERM1' : (($planType === 'Half-yearly') ? 'HALF1' : 'YEARLY');
                $pdo->prepare("
                    UPDATE fees_payments
                    SET status = 'Approved', verified_by = :vb, verified_at = NOW()
                    WHERE student_id = :sid AND installment_code = :code
                ")->execute([':vb' => $reviewer, ':sid' => (string)$studentId, ':code' => $firstCode]);
            } else {
                $pdo->prepare("
                    UPDATE fees_payments
                    SET status = 'Rejected', verified_by = :vb, verified_at = NOW()
                    WHERE student_id = :sid
                ")->execute([':vb' => $reviewer, ':sid' => (string)$studentId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'enrolment_id' => $pcmEnrolmentId > 0 ? $pcmEnrolmentId : null,
        'student_id' => $studentId,
        'student_name' => (string)($ctx['student_name'] ?? 'Student'),
        'parent_name' => (string)($ctx['parent_name'] ?? 'Parent'),
        'parent_email' => (string)($ctx['parent_email'] ?? ''),
        'new_status' => $newStatus,
        'fee_plan' => $planType,
        'fee_amount' => $feeAmount,
    ];
}

// ─── Get the logged-in parent row ─────────────────────────
function pcm_current_parent(PDO $pdo): ?array {
    $email = strtolower(trim($_SESSION['username'] ?? ''));
    if ($email === '') return null;
    $stmt = $pdo->prepare("SELECT * FROM parents WHERE LOWER(email) = :e LIMIT 1");
    $stmt->execute([':e' => $email]);
    return $stmt->fetch() ?: null;
}

// ─── Generate next student ID ─────────────────────────────
function pcm_next_student_id(PDO $pdo): string {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(student_id,5) AS UNSIGNED)) AS mx FROM students WHERE student_id LIKE 'BLCS%'");
    $row = $stmt->fetch();
    $next = ((int)($row['mx'] ?? 0)) + 1;
    return 'BLCS' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

// ─── Safe upload directory creation ───────────────────────
function pcm_ensure_dir(string $path): void {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Cannot create directory: $path");
        }
    }
}

// ─── Email wrappers ───────────────────────────────────────
function pcm_email_wrap(string $title, string $body): string {
    return "
<!DOCTYPE html>
<html lang='en' xmlns='http://www.w3.org/1999/xhtml'>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <meta name='color-scheme' content='light'>
    <meta name='supported-color-schemes' content='light'>
    <title>{$title}</title>
</head>
<body style='margin:0;padding:0;background:#f4f4f4;-webkit-text-size-adjust:100%;'>
<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:24px 0;'>
<tr><td align='center'>
    <table role='presentation' width='600' cellpadding='0' cellspacing='0' style='max-width:600px;width:100%;font-family:Arial,Helvetica,sans-serif;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);'>
        <!-- Header -->
        <tr>
            <td style='background:#881b12;padding:20px 28px;'>
                <h1 style='margin:0;font-size:20px;font-weight:700;color:#ffffff;line-height:1.3;'>{$title}</h1>
            </td>
        </tr>
        <!-- Gold accent bar -->
        <tr>
            <td style='background:#c9a84c;height:4px;font-size:0;line-height:0;'>&nbsp;</td>
        </tr>
        <!-- Body -->
        <tr>
            <td style='background:#ffffff;padding:28px 28px 20px;color:#333333;font-size:15px;line-height:1.6;'>
                {$body}
            </td>
        </tr>
        <!-- Footer -->
        <tr>
            <td style='background:#ffffff;padding:0 28px 24px;'>
                <table role='presentation' width='100%' cellpadding='0' cellspacing='0'>
                    <tr><td style='border-top:1px solid #e0e0e0;padding-top:16px;'>
                        <p style='margin:0;font-size:13px;color:#666666;line-height:1.5;'>Bhutanese Buddhist &amp; Cultural Centre Canberra</p>
                    </td></tr>
                </table>
            </td>
        </tr>
    </table>
</td></tr>
</table>
</body>
</html>";
}

function pcm_notify_admin_enrolment(string $childName, string $parentName): void {
    $adminEmail = pcm_admin_notify_email();
    if ($adminEmail === '') {
        return;
    }
    $portalUrl = htmlspecialchars(pcm_admin_portal_url(), ENT_QUOTES, 'UTF-8');
    $html = pcm_email_wrap('Parent Finalized Enrollment', "
        <p style='margin:0 0 14px;'><strong>" . htmlspecialchars($parentName) . "</strong> has finalized enrollment for <strong>" . htmlspecialchars($childName) . "</strong>.</p>
        <p style='margin:0 0 14px;'>Please review this enrollment in the admin panel.</p>
        <p style='margin:16px 0 0;'>
            <a href='{$portalUrl}' style='background:#881b12;color:#ffffff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:14px;'>
                Open Admin Portal
            </a>
        </p>
    ");
    @send_mail($adminEmail, 'Admin', 'Parent Finalized Enrollment — ' . $childName, $html, 4);
}

function pcm_notify_admin_student_registration(string $childName, string $parentName, string $parentEmail, string $studentCode = ''): void {
    $adminEmail = pcm_admin_notify_email();
    if ($adminEmail === '') {
        return;
    }

    $portalUrl = htmlspecialchars(pcm_admin_portal_url(), ENT_QUOTES, 'UTF-8');
    $studentCodeHtml = $studentCode !== '' ? "<p style='margin:0 0 10px;'><strong>Student ID:</strong> " . htmlspecialchars($studentCode) . "</p>" : "";
    $html = pcm_email_wrap('New Student Registration', "
        <p style='margin:0 0 14px;'>A new child registration has been submitted.</p>
        <p style='margin:0 0 10px;'><strong>Child:</strong> " . htmlspecialchars($childName) . "</p>
        <p style='margin:0 0 10px;'><strong>Parent:</strong> " . htmlspecialchars($parentName) . "</p>
        <p style='margin:0 0 10px;'><strong>Parent Email:</strong> " . htmlspecialchars($parentEmail) . "</p>
        {$studentCodeHtml}
        <p style='margin:0;'>Please review in Admin portal.</p>
        <p style='margin:16px 0 0;'>
            <a href='{$portalUrl}' style='background:#881b12;color:#ffffff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:14px;'>
                Open Admin Portal
            </a>
        </p>
    ");
    @send_mail($adminEmail, 'Admin', 'New Student Registration — ' . $childName, $html, 4);
}

function pcm_notify_parent_enrolment(string $toEmail, string $parentName, string $childName, string $status, string $note): void {
    $colour = ($status === 'Approved') ? '#0d7a3e' : '#c0392b';
    $html = pcm_email_wrap('Enrolment ' . htmlspecialchars($status), "
        <p style='margin:0 0 14px;'>Hi " . htmlspecialchars($parentName) . ",</p>
        <p style='margin:0 0 14px;'>The enrolment for <strong>" . htmlspecialchars($childName) . "</strong> has been
           <span style='color:{$colour};font-weight:700;'>{$status}</span>.</p>
        " . ($note ? "<p style='margin:0 0 14px;background:#fef3f2;padding:12px 16px;border-radius:6px;border-left:4px solid #881b12;'><em>" . htmlspecialchars($note) . "</em></p>" : "") . "
    ");
    @send_mail($toEmail, $parentName, "Enrolment {$status} — {$childName}", $html);
}

function pcm_notify_parent_fee(string $toEmail, string $parentName, string $childName, string $label, string $status): void {
    $colour = ($status === 'Verified') ? '#0d7a3e' : '#c0392b';
    $html = pcm_email_wrap('Fee Payment ' . htmlspecialchars($status), "
        <p style='margin:0 0 14px;'>Hi " . htmlspecialchars($parentName) . ",</p>
        <p style='margin:0 0 14px;'>Payment for <strong>" . htmlspecialchars($childName) . "</strong> — " . htmlspecialchars($label) . " has been
           <span style='color:{$colour};font-weight:700;'>{$status}</span>.</p>
    ");
    @send_mail($toEmail, $parentName, "Fee {$status} — {$childName}", $html);
}

function pcm_notify_parent_enrolment_confirmed(string $toEmail, string $parentName, string $childName): void {
    if (trim($toEmail) === '') {
        return;
    }
    $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
    $loginUrl = $baseUrl !== '' ? $baseUrl . '/login' : 'login';
    $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $html = pcm_email_wrap('Enrollment Confirmed', "
        <p style='margin:0 0 14px;'>Hi " . htmlspecialchars($parentName) . ",</p>
        <p style='margin:0 0 10px;'>Congratulations. Your child's enrollment has been approved.</p>
        <p style='margin:0 0 10px;'>Thank you for paying the fees. <strong>" . htmlspecialchars($childName) . "</strong> is now enrolled in Dzongkha class.</p>
        <p style='margin:16px 0 0;'>
            <a href='{$safeLoginUrl}' style='background:#1f3b73;color:#ffffff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:14px;margin-right:8px;'>
                Login to Portal
            </a>
        </p>
        <p style='margin:10px 0 0;'>You can log in anytime to track attendance and updates.</p>
    ");

    @send_mail($toEmail, $parentName, "Enrollment Confirmed for {$childName}", $html);
}

function pcm_notify_parent_enrolment_changes_requested(string $toEmail, string $parentName, string $childName, string $note): void {
    if (trim($toEmail) === '') {
        return;
    }
    $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
    $loginUrl = $baseUrl !== '' ? $baseUrl . '/children-enrollment' : 'children-enrollment';
    $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    $safeNote = trim($note) !== '' ? htmlspecialchars($note) : 'Please review your submitted details and upload an updated proof if required.';

    $html = pcm_email_wrap('Enrollment Update Required', "
        <p style='margin:0 0 14px;'>Hi " . htmlspecialchars($parentName) . ",</p>
        <p style='margin:0 0 10px;'>Your enrollment submission for <strong>" . htmlspecialchars($childName) . "</strong> needs a small update before approval.</p>
        <div style='margin:10px 0 14px;background:#fef3f2;padding:12px 16px;border-radius:6px;border-left:4px solid #881b12;'>
            <strong>Admin note:</strong> {$safeNote}
        </div>
        <p style='margin:0 0 10px;'>Please log in, open Enrollment, update the details/proof, and resubmit.</p>
        <p style='margin:16px 0 0;'>
            <a href='{$safeLoginUrl}' style='background:#1f3b73;color:#ffffff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:14px;margin-right:8px;'>
                Update Enrollment
            </a>
        </p>
    ");

    @send_mail($toEmail, $parentName, "Enrollment Update Required for {$childName}", $html);
}

function pcm_notify_parent_payment_required(PDO $pdo, string $toEmail, string $parentName, string $childName, string $feePlan, float $feeAmount): void {
    if (trim($toEmail) === '') {
        return;
    }

    $bank = $pdo->query("SELECT bank_name, account_name, bsb, account_number, bank_notes FROM fees_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $bankBlock = '';
    if (!empty($bank)) {
        $bankBlock = "
            <div style='margin:14px 0;padding:12px 14px;background:#f8f9fc;border-radius:8px;border:1px solid #e3e6f0;'>
                <p style='margin:0 0 6px;'><strong>Bank:</strong> " . htmlspecialchars((string)($bank['bank_name'] ?? '')) . "</p>
                <p style='margin:0 0 6px;'><strong>Account Name:</strong> " . htmlspecialchars((string)($bank['account_name'] ?? '')) . "</p>
                <p style='margin:0 0 6px;'><strong>BSB:</strong> " . htmlspecialchars((string)($bank['bsb'] ?? '')) . "</p>
                <p style='margin:0 0 6px;'><strong>Account Number:</strong> " . htmlspecialchars((string)($bank['account_number'] ?? '')) . "</p>
                " . (!empty($bank['bank_notes']) ? "<p style='margin:8px 0 0;'><strong>Reference:</strong> " . htmlspecialchars((string)$bank['bank_notes']) . "</p>" : "") . "
            </div>
        ";
    }

    [$campus1, $campus2] = pcm_campus_names();
    $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
    $loginUrl = $baseUrl !== '' ? $baseUrl . '/login' : 'login';
    $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $html = pcm_email_wrap('Finalized Enrollment', "
        <p style='margin:0 0 14px;'>Hi " . htmlspecialchars($parentName) . ",</p>
        <p style='margin:0 0 10px;'>Congratulations. Your registration for child <strong>" . htmlspecialchars($childName) . "</strong> is successful.</p>
        <p style='margin:0 0 10px;'>To finalize enrolment, please log in to the parent portal, select your campus preference, and complete fee payment.</p>
        <p style='margin:0 0 10px;'><strong>Campus options:</strong> " . htmlspecialchars($campus1) . ", " . htmlspecialchars($campus2) . ", or both campuses.</p>
        {$bankBlock}
        <p style='margin:16px 0 0;'>
            <a href='{$safeLoginUrl}' style='background:#1f3b73;color:#ffffff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:14px;margin-right:8px;'>
                Login to Portal
            </a>
        </p>
        <p style='margin:10px 0 0;'>After payment, upload screenshot proof and confirm payment amount in the portal.</p>
    ");

    @send_mail($toEmail, $parentName, "Finalized Enrollment for {$childName}", $html);
}

function pcm_notify_admin_absence(string $childName, string $parentName, string $date): void {
    $adminEmail = pcm_admin_notify_email();
    if ($adminEmail === '') {
        return;
    }
    $html = pcm_email_wrap('Absence Request', "
        <p style='margin:0 0 14px;'><strong>" . htmlspecialchars($parentName) . "</strong> submitted an absence request for <strong>" . htmlspecialchars($childName) . "</strong> on <strong>" . htmlspecialchars($date) . "</strong>.</p>
    ");
    @send_mail($adminEmail, 'Admin', 'Absence Request — ' . $childName, $html, 4);
}
