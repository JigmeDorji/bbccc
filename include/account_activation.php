<?php
require_once __DIR__ . '/mailer.php';

function bbcc_activation_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM `user` LIKE 'is_active'");
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE `user` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM `user` LIKE 'activated_at'");
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE `user` ADD COLUMN `activated_at` DATETIME NULL AFTER `is_active`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `account_activation_tokens` (
            `id`         INT NOT NULL AUTO_INCREMENT,
            `user_id`    VARCHAR(50)  NOT NULL,
            `user_email` VARCHAR(150) NOT NULL,
            `token_hash` CHAR(64)     NOT NULL,
            `expires_at` DATETIME     NOT NULL,
            `used_at`    DATETIME     DEFAULT NULL,
            `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_activation_token_hash` (`token_hash`),
            KEY `idx_activation_user_id` (`user_id`),
            KEY `idx_activation_user_email` (`user_email`),
            KEY `idx_activation_expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}

function bbcc_issue_activation_token(PDO $pdo, string $userId, string $email, int $ttlHours = 48): string {
    bbcc_activation_ensure_schema($pdo);

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));

    $del = $pdo->prepare("
        DELETE FROM account_activation_tokens
        WHERE user_id = :uid OR LOWER(user_email) = LOWER(:email)
    ");
    $del->execute([':uid' => $userId, ':email' => $email]);

    $ins = $pdo->prepare("
        INSERT INTO account_activation_tokens (user_id, user_email, token_hash, expires_at)
        VALUES (:uid, :email, :token_hash, :expires_at)
    ");
    $ins->execute([
        ':uid' => $userId,
        ':email' => strtolower(trim($email)),
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt
    ]);

    return $rawToken;
}

function bbcc_activation_link(string $rawToken): string {
    return rtrim(BASE_URL, '/') . '/activateAccount?token=' . urlencode($rawToken);
}

function bbcc_send_activation_email(string $toEmail, string $toName, string $activationLink): bool {
    $safeName = htmlspecialchars($toName ?: $toEmail, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($activationLink, ENT_QUOTES, 'UTF-8');

    if (function_exists('pcm_email_wrap')) {
        $body = pcm_email_wrap('Activate Your Account', "
            <p style='margin:0 0 14px;'>Hello <strong>{$safeName}</strong>,</p>
            <p style='margin:0 0 14px;'>Please activate your account to continue.</p>
            <p style='margin:20px 0;'>
                <a href='{$safeLink}' style='background:#881b12;color:#ffffff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:15px;'>
                    Activate Account
                </a>
            </p>
            <p style='margin:0 0 8px;'>If the button does not work, copy this URL into your browser:</p>
            <p style='margin:0;word-break:break-all;'><a href='{$safeLink}'>{$safeLink}</a></p>
            <p style='margin:14px 0 0;font-size:13px;color:#666;'>This link expires in 48 hours.</p>
        ");
    } else {
        $body = "
            <p>Hello <strong>{$safeName}</strong>,</p>
            <p>Please activate your account using this link:</p>
            <p><a href='{$safeLink}'>{$safeLink}</a></p>
            <p>This link expires in 48 hours.</p>
        ";
    }

    return send_mail($toEmail, $toName, 'Activate your account — Bhutanese Centre Canberra', $body);
}

function bbcc_activate_account_with_token(PDO $pdo, string $rawToken): array {
    bbcc_activation_ensure_schema($pdo);

    $rawToken = trim($rawToken);
    if ($rawToken === '') {
        return ['ok' => false, 'message' => 'Invalid activation link.'];
    }

    $tokenHash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare("
        SELECT id, user_id, user_email, expires_at, used_at
        FROM account_activation_tokens
        WHERE token_hash = :h
        LIMIT 1
    ");
    $stmt->execute([':h' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => 'This activation link is invalid.'];
    }
    if (!empty($row['used_at'])) {
        return ['ok' => false, 'message' => 'This activation link was already used.'];
    }
    if (strtotime((string)$row['expires_at']) < time()) {
        return ['ok' => false, 'message' => 'This activation link has expired.'];
    }

    $pdo->beginTransaction();
    try {
        $updUser = $pdo->prepare("
            UPDATE `user`
            SET is_active = 1, activated_at = NOW()
            WHERE userid = :uid
            LIMIT 1
        ");
        $updUser->execute([':uid' => $row['user_id']]);

        $updTok = $pdo->prepare("UPDATE account_activation_tokens SET used_at = NOW() WHERE id = :id LIMIT 1");
        $updTok->execute([':id' => (int)$row['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Activation failed. Please try again later.'];
    }

    return [
        'ok' => true,
        'message' => 'Your account has been activated. You can now log in.',
        'email' => $row['user_email']
    ];
}
