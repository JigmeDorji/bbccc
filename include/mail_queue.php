<?php
// include/mail_queue.php — lightweight DB-backed outgoing mail queue

require_once __DIR__ . '/mailer.php';

function bbcc_mail_queue_is_truthy(string $value): bool {
    return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
}

function bbcc_mail_queue_pdo(): ?PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME;
    if (empty($DB_HOST) || empty($DB_USER) || empty($DB_NAME)) {
        return null;
    }
    try {
        $pdo = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
            $DB_USER,
            $DB_PASSWORD ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        return $pdo;
    } catch (Throwable $e) {
        bbcc_mail_log('MAIL QUEUE DB ERROR: ' . $e->getMessage());
        return null;
    }
}

function bbcc_mail_queue_ensure_table(): bool {
    static $done = false;
    if ($done) return true;
    $pdo = bbcc_mail_queue_pdo();
    if (!$pdo) return false;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mail_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                to_email VARCHAR(255) NOT NULL,
                to_name VARCHAR(255) NULL,
                subject VARCHAR(255) NOT NULL,
                html_body MEDIUMTEXT NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 5,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                last_error TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                KEY idx_status_available (status, available_at),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
        return true;
    } catch (Throwable $e) {
        bbcc_mail_log('MAIL QUEUE TABLE ERROR: ' . $e->getMessage());
        return false;
    }
}

function bbcc_queue_mail(string $toEmail, string $toName, string $subject, string $htmlBody, int $maxAttempts = 5): bool {
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        bbcc_mail_log('MAIL QUEUE ERROR: invalid recipient "' . $toEmail . '"');
        return false;
    }
    if (!bbcc_mail_queue_ensure_table()) {
        return false;
    }
    $pdo = bbcc_mail_queue_pdo();
    if (!$pdo) return false;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO mail_queue (to_email, to_name, subject, html_body, max_attempts, status)
            VALUES (:to_email, :to_name, :subject, :html_body, :max_attempts, 'queued')
        ");
        $stmt->execute([
            ':to_email' => $toEmail,
            ':to_name' => trim($toName) !== '' ? $toName : null,
            ':subject' => $subject,
            ':html_body' => $htmlBody,
            ':max_attempts' => max(1, $maxAttempts),
        ]);

        // Improve perceived speed: drain a small batch after response is sent.
        if (bbcc_mail_queue_is_truthy(bbcc_env('MAIL_QUEUE_DRAIN_ON_SHUTDOWN', '1'))) {
            $drainLimit = (int)bbcc_env('MAIL_QUEUE_DRAIN_LIMIT', '3');
            bbcc_schedule_mail_queue_drain(max(1, min(10, $drainLimit)));
        }
        return true;
    } catch (Throwable $e) {
        bbcc_mail_log('MAIL QUEUE INSERT ERROR: ' . $e->getMessage());
        return false;
    }
}

function bbcc_schedule_mail_queue_drain(int $limit = 3): void {
    static $scheduled = false;
    if ($scheduled) {
        return;
    }
    $scheduled = true;
    $limit = max(1, min(10, $limit));

    register_shutdown_function(function () use ($limit) {
        // If available, flush HTTP response first so user does not wait on SMTP.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            bbcc_process_mail_queue($limit);
        } catch (Throwable $e) {
            bbcc_mail_log('MAIL QUEUE SHUTDOWN DRAIN ERROR: ' . $e->getMessage());
        }
    });
}

function bbcc_process_mail_queue(int $limit = 20): array {
    $limit = max(1, min(200, $limit));
    $stats = ['picked' => 0, 'sent' => 0, 'failed' => 0];

    if (!bbcc_mail_queue_ensure_table()) {
        return $stats;
    }
    $pdo = bbcc_mail_queue_pdo();
    if (!$pdo) return $stats;

    $jobs = $pdo->prepare("
        SELECT *
        FROM mail_queue
        WHERE status IN ('queued','retry')
          AND available_at <= NOW()
        ORDER BY id ASC
        LIMIT {$limit}
    ");
    $jobs->execute();
    $rows = $jobs->fetchAll();
    $stats['picked'] = count($rows);

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $attempts = (int)$row['attempts'];
        $maxAttempts = (int)$row['max_attempts'];
        try {
            $queueTimeout = (int)bbcc_env('MAIL_QUEUE_SEND_TIMEOUT', '8');
            $ok = send_mail(
                (string)$row['to_email'],
                (string)($row['to_name'] ?? ''),
                (string)$row['subject'],
                (string)$row['html_body'],
                $queueTimeout > 0 ? $queueTimeout : null
            );
            if ($ok) {
                $upd = $pdo->prepare("UPDATE mail_queue SET status='sent', sent_at=NOW(), attempts=:a, last_error=NULL WHERE id=:id");
                $upd->execute([':a' => $attempts + 1, ':id' => $id]);
                $stats['sent']++;
            } else {
                $nextAttempts = $attempts + 1;
                $isDead = $nextAttempts >= $maxAttempts;
                $status = $isDead ? 'failed' : 'retry';
                $delayMinutes = min(60, max(1, $nextAttempts * 2));
                $upd = $pdo->prepare("
                    UPDATE mail_queue
                    SET status=:st,
                        attempts=:a,
                        last_error=:err,
                        available_at=DATE_ADD(NOW(), INTERVAL :mins MINUTE)
                    WHERE id=:id
                ");
                $upd->execute([
                    ':st' => $status,
                    ':a' => $nextAttempts,
                    ':err' => 'send_mail returned false',
                    ':mins' => $delayMinutes,
                    ':id' => $id
                ]);
                $stats['failed']++;
            }
        } catch (Throwable $e) {
            $nextAttempts = $attempts + 1;
            $isDead = $nextAttempts >= $maxAttempts;
            $status = $isDead ? 'failed' : 'retry';
            $delayMinutes = min(60, max(1, $nextAttempts * 2));
            $upd = $pdo->prepare("
                UPDATE mail_queue
                SET status=:st,
                    attempts=:a,
                    last_error=:err,
                    available_at=DATE_ADD(NOW(), INTERVAL :mins MINUTE)
                WHERE id=:id
            ");
            $upd->execute([
                ':st' => $status,
                ':a' => $nextAttempts,
                ':err' => $e->getMessage(),
                ':mins' => $delayMinutes,
                ':id' => $id
            ]);
            $stats['failed']++;
        }
    }

    return $stats;
}
