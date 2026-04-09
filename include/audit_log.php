<?php

function bbcc_audit_log_pdo(): ?PDO {
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
        error_log('[BBCC][AUDIT] DB connect error: ' . $e->getMessage());
        return null;
    }
}

function bbcc_audit_ensure_table(): bool {
    static $done = false;
    if ($done) return true;

    $pdo = bbcc_audit_log_pdo();
    if (!$pdo) return false;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_id VARCHAR(80) NULL,
                username VARCHAR(190) NULL,
                role VARCHAR(80) NULL,
                ip_address VARCHAR(64) NULL,
                route VARCHAR(190) NULL,
                method VARCHAR(12) NULL,
                action_name VARCHAR(120) NULL,
                entity VARCHAR(120) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'info',
                details_json MEDIUMTEXT NULL,
                KEY idx_occurred_at (occurred_at),
                KEY idx_user (username, occurred_at),
                KEY idx_action (action_name, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
        return true;
    } catch (Throwable $e) {
        error_log('[BBCC][AUDIT] Table ensure error: ' . $e->getMessage());
        return false;
    }
}

function bbcc_audit_log(string $actionName, string $entity = 'system', array $details = [], string $status = 'info'): bool {
    if (!bbcc_audit_ensure_table()) return false;
    $pdo = bbcc_audit_log_pdo();
    if (!$pdo) return false;

    try {
        $route = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs
                (user_id, username, role, ip_address, route, method, action_name, entity, status, details_json)
            VALUES
                (:user_id, :username, :role, :ip, :route, :method, :action_name, :entity, :status, :details_json)
        ");
        $stmt->execute([
            ':user_id' => (string)($_SESSION['userid'] ?? ''),
            ':username' => (string)($_SESSION['username'] ?? ''),
            ':role' => (string)($_SESSION['role'] ?? ''),
            ':ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ':route' => $route,
            ':method' => $method,
            ':action_name' => $actionName,
            ':entity' => $entity,
            ':status' => $status,
            ':details_json' => $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[BBCC][AUDIT] Insert error: ' . $e->getMessage());
        return false;
    }
}

function bbcc_audit_capture_request_once(): void {
    static $captured = false;
    if ($captured) return;
    $captured = true;

    if (!isset($_SESSION['userid'])) return;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = '';

    if ($method === 'POST') {
        $action = (string)($_POST['action'] ?? $_POST['email_action'] ?? $_POST['op'] ?? 'post_submit');
        $keys = array_keys($_POST);
        bbcc_audit_log($action !== '' ? $action : 'post_submit', 'request', [
            'post_keys' => array_slice($keys, 0, 30),
        ], 'info');
        return;
    }

    $watch = ['action', 'fee_action', 'op', 'do', 'approve', 'reject', 'toggle', 'delete'];
    foreach ($watch as $k) {
        if (isset($_GET[$k]) && (string)$_GET[$k] !== '') {
            $action = (string)$_GET[$k];
            break;
        }
    }
    if ($action !== '') {
        bbcc_audit_log($action, 'request', [
            'query' => array_intersect_key($_GET, array_flip($watch)),
        ], 'info');
    }
}

