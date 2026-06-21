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
                attachment_path VARCHAR(1000) NULL,
                attachment_name VARCHAR(255) NULL,
                attachment_mime VARCHAR(150) NULL,
                source VARCHAR(50) NULL,
                created_by VARCHAR(100) NULL,
                batch_id VARCHAR(64) NULL,
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
        $attachmentColumns = [
            'attachment_path' => "ALTER TABLE mail_queue ADD COLUMN attachment_path VARCHAR(1000) NULL AFTER html_body",
            'attachment_name' => "ALTER TABLE mail_queue ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path",
            'attachment_mime' => "ALTER TABLE mail_queue ADD COLUMN attachment_mime VARCHAR(150) NULL AFTER attachment_name",
            'source' => "ALTER TABLE mail_queue ADD COLUMN source VARCHAR(50) NULL AFTER attachment_mime",
            'created_by' => "ALTER TABLE mail_queue ADD COLUMN created_by VARCHAR(100) NULL AFTER source",
            'batch_id' => "ALTER TABLE mail_queue ADD COLUMN batch_id VARCHAR(64) NULL AFTER created_by",
        ];
        foreach ($attachmentColumns as $column => $alterSql) {
            $check = $pdo->query("SHOW COLUMNS FROM mail_queue LIKE " . $pdo->quote($column));
            if (!$check || !$check->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($alterSql);
            }
        }
        $done = true;
        return true;
    } catch (Throwable $e) {
        bbcc_mail_log('MAIL QUEUE TABLE ERROR: ' . $e->getMessage());
        return false;
    }
}

function bbcc_queue_mail(string $toEmail, string $toName, string $subject, string $htmlBody, int $maxAttempts = 5, ?array $attachment = null, array $metadata = []): bool {
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
            INSERT INTO mail_queue
                (to_email, to_name, subject, html_body, attachment_path, attachment_name, attachment_mime, source, created_by, batch_id, max_attempts, status)
            VALUES
                (:to_email, :to_name, :subject, :html_body, :attachment_path, :attachment_name, :attachment_mime, :source, :created_by, :batch_id, :max_attempts, 'queued')
        ");
        $stmt->execute([
            ':to_email' => $toEmail,
            ':to_name' => trim($toName) !== '' ? $toName : null,
            ':subject' => $subject,
            ':html_body' => $htmlBody,
            ':attachment_path' => !empty($attachment['path']) ? (string)$attachment['path'] : null,
            ':attachment_name' => !empty($attachment['name']) ? (string)$attachment['name'] : null,
            ':attachment_mime' => !empty($attachment['mime']) ? (string)$attachment['mime'] : null,
            ':source' => !empty($metadata['source']) ? substr((string)$metadata['source'], 0, 50) : null,
            ':created_by' => !empty($metadata['created_by']) ? substr((string)$metadata['created_by'], 0, 100) : null,
            ':batch_id' => !empty($metadata['batch_id']) ? substr((string)$metadata['batch_id'], 0, 64) : null,
            ':max_attempts' => max(1, $maxAttempts),
        ]);

        // Improve perceived speed: drain only when response can be flushed first.
        // On many cPanel/shared Apache setups, fastcgi_finish_request() is unavailable,
        // so draining on shutdown can block the user-facing request.
        $drainDefault = function_exists('fastcgi_finish_request') ? '1' : '0';
        if (bbcc_mail_queue_is_truthy(bbcc_env('MAIL_QUEUE_DRAIN_ON_SHUTDOWN', $drainDefault))) {
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
            $attachments = [];
            if (!empty($row['attachment_path'])) {
                $attachments[] = [
                    'path' => (string)$row['attachment_path'],
                    'name' => (string)($row['attachment_name'] ?? ''),
                    'mime' => (string)($row['attachment_mime'] ?? ''),
                ];
            }
            $ok = send_mail(
                (string)$row['to_email'],
                (string)($row['to_name'] ?? ''),
                (string)$row['subject'],
                (string)$row['html_body'],
                $queueTimeout > 0 ? $queueTimeout : null,
                $attachments
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
                    ':err' => bbcc_last_mail_error() !== '' ? bbcc_last_mail_error() : 'Mail server rejected the send request.',
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

        $attachmentPath = trim((string)($row['attachment_path'] ?? ''));
        if ($attachmentPath !== '') {
            $active = $pdo->prepare("
                SELECT COUNT(*)
                FROM mail_queue
                WHERE attachment_path = :path
                  AND status IN ('queued', 'retry')
            ");
            $active->execute([':path' => $attachmentPath]);
            if ((int)$active->fetchColumn() === 0) {
                @unlink($attachmentPath);
            }
        }
    }

    return $stats;
}
