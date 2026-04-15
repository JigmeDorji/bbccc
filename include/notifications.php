<?php
// include/notifications.php — simple role/user notification helpers
require_once __DIR__ . '/mailer.php';

function bbcc_notification_role_key(string $role): string {
    $r = strtolower(trim($role));
    if (in_array($r, ['administrator', 'admin', 'company admin', 'staff', 'system_owner', 'system owner'], true)) {
        return 'admin';
    }
    if ($r === 'parent') return 'parent';
    if ($r === 'teacher') return 'teacher';
    if ($r === 'patron') return 'patron';
    return 'user';
}

function bbcc_ensure_notifications_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            target_username VARCHAR(255) NULL,
            target_role VARCHAR(40) NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            link_url VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            KEY idx_target_user (target_username),
            KEY idx_target_role (target_role),
            KEY idx_read_created (is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function bbcc_create_notification(PDO $pdo, array $data): bool {
    bbcc_ensure_notifications_table($pdo);
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') return false;
    $stmt = $pdo->prepare("
        INSERT INTO app_notifications
            (target_username, target_role, title, body, level, link_url, is_read)
        VALUES
            (:u, :r, :t, :b, :l, :lnk, 0)
    ");
    return $stmt->execute([
        ':u'   => !empty($data['target_username']) ? strtolower(trim((string)$data['target_username'])) : null,
        ':r'   => !empty($data['target_role']) ? strtolower(trim((string)$data['target_role'])) : null,
        ':t'   => $title,
        ':b'   => (string)($data['body'] ?? ''),
        ':l'   => (string)($data['level'] ?? 'info'),
        ':lnk' => !empty($data['link_url']) ? (string)$data['link_url'] : null,
    ]);
}

function bbcc_notify_admins(PDO $pdo, string $title, string $body = '', string $linkUrl = ''): bool {
    $ok = bbcc_create_notification($pdo, [
        'target_role' => 'admin',
        'title' => $title,
        'body' => $body,
        'level' => 'info',
        'link_url' => $linkUrl,
    ]);

    // Also send an admin email notification.
    try {
        if (!function_exists('pcm_class_notify_email') && file_exists(__DIR__ . '/pcm_helpers.php')) {
            require_once __DIR__ . '/pcm_helpers.php';
        }
        $fallback = function_exists('bbcc_env')
            ? bbcc_env('ADMIN_NOTIFY_EMAIL', defined('MAIL_FROM_EMAIL') ? (string)MAIL_FROM_EMAIL : '')
            : (defined('MAIL_FROM_EMAIL') ? (string)MAIL_FROM_EMAIL : '');
        $to = trim((string)(function_exists('pcm_class_notify_email')
            ? pcm_class_notify_email()
            : (function_exists('bbcc_env') ? bbcc_env('CLASS_NOTIFY_EMAIL', $fallback) : $fallback)));

        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
            $safeLink = trim($linkUrl) !== '' ? htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8') : '';
            $html = "
                <h3 style='margin:0 0 12px;'>Admin Notification</h3>
                <p style='margin:0 0 10px;'><strong>{$safeTitle}</strong></p>
                " . ($safeBody !== '' ? "<p style='margin:0 0 10px;'>{$safeBody}</p>" : "") . "
                " . ($safeLink !== '' ? "<p style='margin:0;'><strong>Link:</strong> {$safeLink}</p>" : "") . "
            ";
            @send_mail($to, 'Admin', $title, $html, 4);
        }
    } catch (Throwable $e) {
        // Non-blocking: keep in-app notification working even if mail fails.
        if (function_exists('bbcc_mail_log')) {
            bbcc_mail_log('NOTIFY ADMIN MAIL ERROR: ' . $e->getMessage());
        }
    }

    return $ok;
}

function bbcc_notify_username(PDO $pdo, string $username, string $title, string $body = '', string $linkUrl = ''): bool {
    return bbcc_create_notification($pdo, [
        'target_username' => $username,
        'title' => $title,
        'body' => $body,
        'level' => 'info',
        'link_url' => $linkUrl,
    ]);
}

function bbcc_fetch_notifications_for_user(PDO $pdo, string $username, string $role, int $limit = 20): array {
    bbcc_ensure_notifications_table($pdo);
    $limit = max(1, min(100, $limit));
    $roleKey = bbcc_notification_role_key($role);
    $stmt = $pdo->prepare("
        SELECT *
        FROM app_notifications
        WHERE (target_username IS NOT NULL AND LOWER(target_username) = LOWER(:u))
           OR (target_role IS NOT NULL AND target_role = :r)
           OR (target_role = 'all')
        ORDER BY is_read ASC, created_at DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':u' => strtolower(trim($username)), ':r' => $roleKey]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bbcc_unread_notifications_count(PDO $pdo, string $username, string $role): int {
    bbcc_ensure_notifications_table($pdo);
    $roleKey = bbcc_notification_role_key($role);
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM app_notifications
        WHERE is_read = 0
          AND (
              (target_username IS NOT NULL AND LOWER(target_username) = LOWER(:u))
              OR (target_role IS NOT NULL AND target_role = :r)
              OR (target_role = 'all')
          )
    ");
    $stmt->execute([':u' => strtolower(trim($username)), ':r' => $roleKey]);
    return (int)$stmt->fetchColumn();
}

function bbcc_mark_notification_read(PDO $pdo, int $id, string $username, string $role): void {
    bbcc_ensure_notifications_table($pdo);
    $roleKey = bbcc_notification_role_key($role);
    $stmt = $pdo->prepare("
        UPDATE app_notifications
        SET is_read = 1, read_at = NOW()
        WHERE id = :id
          AND (
              (target_username IS NOT NULL AND LOWER(target_username) = LOWER(:u))
              OR (target_role IS NOT NULL AND target_role = :r)
              OR (target_role = 'all')
          )
    ");
    $stmt->execute([':id' => $id, ':u' => strtolower(trim($username)), ':r' => $roleKey]);
}

function bbcc_mark_all_notifications_read(PDO $pdo, string $username, string $role): void {
    bbcc_ensure_notifications_table($pdo);
    $roleKey = bbcc_notification_role_key($role);
    $stmt = $pdo->prepare("
        UPDATE app_notifications
        SET is_read = 1, read_at = NOW()
        WHERE is_read = 0
          AND (
              (target_username IS NOT NULL AND LOWER(target_username) = LOWER(:u))
              OR (target_role IS NOT NULL AND target_role = :r)
              OR (target_role = 'all')
          )
    ");
    $stmt->execute([':u' => strtolower(trim($username)), ':r' => $roleKey]);
}

function bbcc_delete_notification(PDO $pdo, int $id, string $username, string $role): void {
    bbcc_ensure_notifications_table($pdo);
    $roleKey = bbcc_notification_role_key($role);
    $stmt = $pdo->prepare("
        DELETE FROM app_notifications
        WHERE id = :id
          AND (
              (target_username IS NOT NULL AND LOWER(target_username) = LOWER(:u))
              OR (target_role IS NOT NULL AND target_role = :r)
              OR (target_role = 'all')
          )
    ");
    $stmt->execute([':id' => $id, ':u' => strtolower(trim($username)), ':r' => $roleKey]);
}
