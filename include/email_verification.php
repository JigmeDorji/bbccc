<?php
/**
 * include/email_verification.php
 * ────────────────────────────────────────────────────────
 * OTP-based email verification helpers.
 *
 * Flow:
 *   1. Call bbcc_send_verification_code($email, 'signup')
 *      → generates a 6-digit code, stores SHA-256 hash, emails the code.
 *   2. Call bbcc_verify_email_code($email, $code, 'signup')
 *      → returns ['ok'=>true] on success, or ['ok'=>false,'message'=>'...'] on failure.
 *
 * Security:
 *   • Codes are hashed (SHA-256) — never stored in plain text.
 *   • Codes expire after 10 minutes.
 *   • Max 5 wrong attempts per code — after that it's burned.
 *   • Rate-limited: max 5 codes per email per hour.
 */

require_once __DIR__ . '/mailer.php';

/* ── Ensure table exists (safe to call repeatedly) ───────── */
function bbcc_evc_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `email_verification_codes` (
            `id`          INT NOT NULL AUTO_INCREMENT,
            `email`       VARCHAR(190) NOT NULL,
            `code_hash`   CHAR(64) NOT NULL,
            `purpose`     VARCHAR(50) NOT NULL DEFAULT 'signup',
            `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `verified_at` DATETIME DEFAULT NULL,
            `expires_at`  DATETIME NOT NULL,
            `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_evc_email_purpose` (`email`, `purpose`),
            KEY `idx_evc_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

/* ── Generate & send a 6-digit OTP ───────────────────────── */
function bbcc_send_verification_code(PDO $pdo, string $email, string $purpose = 'signup'): array {
    $email = strtolower(trim($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Invalid email address.'];
    }

    bbcc_evc_ensure_table($pdo);

    // ── Rate limit: max 5 codes per email per hour ─────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM email_verification_codes
        WHERE email = :email AND purpose = :purpose AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([':email' => $email, ':purpose' => $purpose]);
    if ((int)$stmt->fetchColumn() >= 5) {
        return ['ok' => false, 'message' => 'Too many verification attempts. Please wait a while before trying again.'];
    }

    // ── Invalidate any existing un-used codes for this email+purpose ──
    $pdo->prepare("
        DELETE FROM email_verification_codes
        WHERE email = :email AND purpose = :purpose AND verified_at IS NULL
    ")->execute([':email' => $email, ':purpose' => $purpose]);

    // ── Generate a 6-digit code ─────────────────────────────
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = hash('sha256', $code);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    $ins = $pdo->prepare("
        INSERT INTO email_verification_codes (email, code_hash, purpose, expires_at)
        VALUES (:email, :code_hash, :purpose, :expires_at)
    ");
    $ins->execute([
        ':email'     => $email,
        ':code_hash' => $codeHash,
        ':purpose'   => $purpose,
        ':expires_at' => $expiresAt,
    ]);

    // ── Send the email ──────────────────────────────────────
    $safeName = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $subject  = "Your Verification Code — Bhutanese Centre Canberra";

    if (function_exists('pcm_email_wrap')) {
        $body = pcm_email_wrap('Email Verification', "
            <p style='margin:0 0 14px;'>Hello,</p>
            <p style='margin:0 0 14px;'>Your email verification code is:</p>
            <div style='text-align:center;margin:24px 0;'>
                <span style='display:inline-block;background:#f5f7fa;border:2px solid #881b12;border-radius:12px;padding:16px 36px;font-size:32px;font-weight:700;letter-spacing:8px;color:#881b12;font-family:monospace;'>{$code}</span>
            </div>
            <p style='margin:0 0 8px;font-size:14px;color:#555;'>This code expires in <strong>10 minutes</strong>.</p>
            <p style='margin:0 0 8px;font-size:13px;color:#888;'>If you did not request this, please ignore this email.</p>
            <p style='margin:14px 0 0;font-size:13px;color:#666;'>If this email landed in Spam/Junk, please mark it as <em>Not Spam</em> so future emails arrive in your inbox.</p>
        ");
    } else {
        $body = "
            <p>Hello,</p>
            <p>Your email verification code is: <strong style='font-size:24px;letter-spacing:4px;'>{$code}</strong></p>
            <p>This code expires in 10 minutes.</p>
            <p>If you did not request this, please ignore this email.</p>
        ";
    }

    $sent = send_mail($email, '', $subject, $body);

    if (!$sent) {
        return ['ok' => false, 'message' => 'Failed to send verification email. Please try again.'];
    }

    return ['ok' => true, 'message' => 'Verification code sent to your email.'];
}

/* ── Verify a submitted OTP ──────────────────────────────── */
function bbcc_verify_email_code(PDO $pdo, string $email, string $code, string $purpose = 'signup'): array {
    $email = strtolower(trim($email));
    $code  = trim($code);

    if ($code === '' || strlen($code) !== 6 || !ctype_digit($code)) {
        return ['ok' => false, 'message' => 'Please enter a valid 6-digit code.'];
    }

    bbcc_evc_ensure_table($pdo);

    // ── Find the latest un-verified, un-expired code ────────
    $stmt = $pdo->prepare("
        SELECT id, code_hash, attempts, expires_at
        FROM email_verification_codes
        WHERE email = :email AND purpose = :purpose AND verified_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':email' => $email, ':purpose' => $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => 'No verification code found. Please request a new one.'];
    }

    // ── Check expiry ────────────────────────────────────────
    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare("DELETE FROM email_verification_codes WHERE id = :id")->execute([':id' => $row['id']]);
        return ['ok' => false, 'message' => 'This code has expired. Please request a new one.'];
    }

    // ── Check max attempts ──────────────────────────────────
    if ((int)$row['attempts'] >= 5) {
        $pdo->prepare("DELETE FROM email_verification_codes WHERE id = :id")->execute([':id' => $row['id']]);
        return ['ok' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
    }

    // ── Compare hash ────────────────────────────────────────
    $inputHash = hash('sha256', $code);
    if (!hash_equals($row['code_hash'], $inputHash)) {
        $pdo->prepare("UPDATE email_verification_codes SET attempts = attempts + 1 WHERE id = :id")
            ->execute([':id' => $row['id']]);
        $remaining = 4 - (int)$row['attempts']; // already incremented
        $msg = 'Incorrect code. ' . ($remaining > 0 ? "{$remaining} attempt(s) remaining." : 'Please request a new code.');
        return ['ok' => false, 'message' => $msg];
    }

    // ── Mark as verified ────────────────────────────────────
    $pdo->prepare("UPDATE email_verification_codes SET verified_at = NOW() WHERE id = :id")
        ->execute([':id' => $row['id']]);

    return ['ok' => true, 'message' => 'Email verified successfully.'];
}

/* ── Check if an email was recently verified (within N minutes) ── */
function bbcc_is_email_verified(PDO $pdo, string $email, string $purpose = 'signup', int $withinMinutes = 30): bool {
    $email = strtolower(trim($email));
    bbcc_evc_ensure_table($pdo);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM email_verification_codes
        WHERE email = :email
          AND purpose = :purpose
          AND verified_at IS NOT NULL
          AND verified_at > DATE_SUB(NOW(), INTERVAL :mins MINUTE)
    ");
    $stmt->execute([':email' => $email, ':purpose' => $purpose, ':mins' => $withinMinutes]);
    return (int)$stmt->fetchColumn() > 0;
}
