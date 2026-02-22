<?php
// include/pcm_helpers.php — Shared helpers for Parent Class Management module
// All functions are pure — they receive $pdo where needed.

require_once __DIR__ . '/mailer.php';

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
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
        <div style='background:#4e73df;color:#fff;padding:18px 24px;border-radius:8px 8px 0 0;'>
            <h2 style='margin:0;font-size:18px;'>{$title}</h2>
        </div>
        <div style='padding:24px;background:#fff;border:1px solid #e3e6f0;border-top:none;border-radius:0 0 8px 8px;'>
            {$body}
            <hr style='margin:20px 0;border:none;border-top:1px solid #e3e6f0;'>
            <p style='font-size:12px;color:#888;'>Bhutanese Buddhist Centre Canberra</p>
        </div>
    </div>";
}

function pcm_notify_admin_enrolment(string $childName, string $parentName): void {
    $html = pcm_email_wrap('New Enrolment Request', "
        <p><strong>{$parentName}</strong> submitted an enrolment for <strong>{$childName}</strong>.</p>
        <p>Please review it in the admin panel.</p>
    ");
    @send_mail(MAIL_FROM_EMAIL, 'Admin', 'New Enrolment — ' . $childName, $html);
}

function pcm_notify_parent_enrolment(string $toEmail, string $parentName, string $childName, string $status, string $note): void {
    $colour = ($status === 'Approved') ? '#1cc88a' : '#e74a3b';
    $html = pcm_email_wrap('Enrolment ' . $status, "
        <p>Hi {$parentName},</p>
        <p>The enrolment for <strong>{$childName}</strong> has been
           <span style='color:{$colour};font-weight:700;'>{$status}</span>.</p>
        " . ($note ? "<p><em>Note: {$note}</em></p>" : "") . "
    ");
    @send_mail($toEmail, $parentName, "Enrolment {$status} — {$childName}", $html);
}

function pcm_notify_parent_fee(string $toEmail, string $parentName, string $childName, string $label, string $status): void {
    $colour = ($status === 'Verified') ? '#1cc88a' : '#e74a3b';
    $html = pcm_email_wrap('Fee Payment ' . $status, "
        <p>Hi {$parentName},</p>
        <p>Payment for <strong>{$childName}</strong> — {$label} has been
           <span style='color:{$colour};font-weight:700;'>{$status}</span>.</p>
    ");
    @send_mail($toEmail, $parentName, "Fee {$status} — {$childName}", $html);
}

function pcm_notify_admin_absence(string $childName, string $parentName, string $date): void {
    $html = pcm_email_wrap('Absence Request', "
        <p><strong>{$parentName}</strong> submitted an absence request for <strong>{$childName}</strong> on <strong>{$date}</strong>.</p>
    ");
    @send_mail(MAIL_FROM_EMAIL, 'Admin', 'Absence Request — ' . $childName, $html);
}
