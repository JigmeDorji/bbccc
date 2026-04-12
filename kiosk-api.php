<?php
/**
 * kiosk-api.php — JSON API backend for iPad kiosk sign-in/out
 *
 * Endpoints (POST):
 *   action=csrf            → returns fresh CSRF token
 *   action=generate_token  → creates a rotating QR token (called by QR display iPad)
 *   action=validate_token  → checks if a QR token is valid (called by mobile page)
 *   action=auth            → phone + PIN authentication
 *   action=sign            → sign-in or sign-out a child
 *
 * All responses: { ok: bool, message: string, data?: {} }
 */

require_once "include/config.php";
require_once "include/csrf.php";

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

// PDO connection
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER, $DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Service unavailable.']);
    exit;
}

$action = $_POST['action'] ?? '';
$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ─── CSRF token endpoint (no verification needed) ───
if ($action === 'csrf') {
    echo json_encode(['ok' => true, 'token' => csrf_token()]);
    exit;
}

// ─── Generate rotating QR token (called by QR display iPad) ───
if ($action === 'generate_token') {
    // Auto-create table if missing (first run)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pcm_kiosk_tokens` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `token` VARCHAR(64) NOT NULL UNIQUE,
            `expires_at` DATETIME NOT NULL,
            `used` TINYINT(1) NOT NULL DEFAULT 0,
            `used_by_ip` VARCHAR(45) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (`token`),
            INDEX idx_expires (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Clean up expired tokens (older than 10 min)
    $pdo->exec("DELETE FROM pcm_kiosk_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

    // Generate a cryptographically secure token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+3 minutes')); // valid for 3 minutes

    $stmt = $pdo->prepare("INSERT INTO pcm_kiosk_tokens (token, expires_at) VALUES (:t, :e)");
    $stmt->execute([':t' => $token, ':e' => $expiresAt]);

    echo json_encode([
        'ok'    => true,
        'token' => $token,
        'expires_in' => 180, // seconds
    ]);
    exit;
}

// ─── Validate a QR token (called by mobile page on load) ───
if ($action === 'validate_token') {
    $qrToken = trim($_POST['qr_token'] ?? '');

    if (!$qrToken) {
        echo json_encode(['ok' => false, 'message' => 'No token provided.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, token, expires_at, used FROM pcm_kiosk_tokens
        WHERE token = :t LIMIT 1
    ");
    $stmt->execute([':t' => $qrToken]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['ok' => false, 'message' => 'Invalid QR code. Please scan again at the door.']);
        exit;
    }

    if (strtotime($row['expires_at']) < time()) {
        echo json_encode(['ok' => false, 'message' => 'QR code has expired. Please scan the new code at the door.']);
        exit;
    }

    if ($row['used']) {
        echo json_encode(['ok' => false, 'message' => 'This QR code has already been used. Please scan the new code at the door.']);
        exit;
    }

    // Mark token as used
    $upd = $pdo->prepare("UPDATE pcm_kiosk_tokens SET used = 1, used_by_ip = :ip WHERE id = :id");
    $upd->execute([':ip' => $ip, ':id' => $row['id']]);

    // Return a session key so subsequent calls (auth, sign) are allowed
    $sessionKey = bin2hex(random_bytes(16));
    $_SESSION['kiosk_mobile_key'] = $sessionKey;
    $_SESSION['kiosk_mobile_expires'] = time() + 600; // 10 min session

    echo json_encode([
        'ok'          => true,
        'session_key' => $sessionKey,
        'message'     => 'Token valid. You may proceed.',
    ]);
    exit;
}

// ─── All other actions require CSRF ───
$submitted_csrf = $_POST['_csrf'] ?? '';
if (!hash_equals(csrf_token(), $submitted_csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please start again.', 'csrf_error' => true]);
    exit;
}

function bbcc_verify_kiosk_mobile_session(): bool {
    $qrSession = trim((string)($_POST['qr_session'] ?? ''));
    if ($qrSession === '') {
        // Non-QR/iPad kiosk flow does not send qr_session.
        return true;
    }
    if (!isset($_SESSION['kiosk_mobile_key'], $_SESSION['kiosk_mobile_expires'])) {
        return false;
    }
    if (!hash_equals((string)$_SESSION['kiosk_mobile_key'], $qrSession)) {
        return false;
    }
    if ((int)$_SESSION['kiosk_mobile_expires'] <= time()) {
        return false;
    }
    return true;
}

// ═══════════════════════════════════════════════════════════
// ACTION: Authenticate parent by phone + PIN
// ═══════════════════════════════════════════════════════════
if ($action === 'auth') {
    if (!bbcc_verify_kiosk_mobile_session()) {
        echo json_encode(['ok' => false, 'message' => 'Session expired. Please scan the QR code again at the door.', 'token_expired' => true]);
        exit;
    }
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
    $pin   = trim($_POST['pin'] ?? '');

    if (!$phone || !$pin) {
        echo json_encode(['ok' => false, 'message' => 'Phone and PIN are required.']);
        exit;
    }

    // Rate-limit check: 5 failures per phone OR IP in 15 minutes
    $cutoff = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM pcm_kiosk_failed
        WHERE (phone = :p OR ip_address = :ip) AND attempted_at >= :t
    ");
    $stmt->execute([':p' => $phone, ':ip' => $ip, ':t' => $cutoff]);

    if ($stmt->fetchColumn() >= 5) {
        echo json_encode(['ok' => false, 'message' => 'Too many attempts. Please wait 15 minutes or contact staff.']);
        exit;
    }

    // Look up parent
    $stmt = $pdo->prepare("
        SELECT * FROM parents
        WHERE REPLACE(REPLACE(phone, ' ', ''), '-', '') = :p AND status = 'Active'
        LIMIT 1
    ");
    $stmt->execute([':p' => $phone]);
    $parent = $stmt->fetch();

    if (!$parent || empty($parent['pin_hash']) || !password_verify($pin, $parent['pin_hash'])) {
        // Record failure
        $pdo->prepare("INSERT INTO pcm_kiosk_failed (phone, ip_address) VALUES (:p, :ip)")
            ->execute([':p' => $phone, ':ip' => $ip]);
        echo json_encode(['ok' => false, 'message' => 'Invalid phone number or PIN.']);
        exit;
    }

    // Load children with approved enrolments
    $kids = $pdo->prepare("
        SELECT s.id, s.student_id, s.student_name
        FROM students s
        JOIN pcm_enrolments e ON e.student_id = s.id AND e.status = 'Approved'
        WHERE s.parentId = :pid
        ORDER BY s.student_name
    ");
    $kids->execute([':pid' => $parent['id']]);
    $children = $kids->fetchAll();

    // Get today's sign status for each child
    $today = date('Y-m-d');
    $childData = [];
    foreach ($children as $c) {
        $chk = $pdo->prepare("SELECT time_in, time_out FROM pcm_kiosk_log WHERE child_id = :cid AND log_date = :d LIMIT 1");
        $chk->execute([':cid' => $c['id'], ':d' => $today]);
        $log = $chk->fetch();

        $status = 'none'; // not signed in yet
        if ($log && $log['time_in'] && !$log['time_out']) {
            $status = 'signed_in';
        } elseif ($log && $log['time_out']) {
            $status = 'done';
        }

        $childData[] = [
            'id'           => (int)$c['id'],
            'student_id'   => $c['student_id'],
            'student_name' => $c['student_name'],
            'status'       => $status,
            'time_in'      => $log['time_in'] ?? null,
            'time_out'     => $log['time_out'] ?? null,
        ];
    }

    echo json_encode([
        'ok'   => true,
        'data' => [
            'parent_id'   => (int)$parent['id'],
            'parent_name' => $parent['full_name'],
            'children'    => $childData,
        ],
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// ACTION: Batch sign-in/out children (submit on Done)
// ═══════════════════════════════════════════════════════════
if ($action === 'sign_batch') {
    if (!bbcc_verify_kiosk_mobile_session()) {
        echo json_encode(['ok' => false, 'message' => 'Session expired. Please scan the QR code again at the door.', 'token_expired' => true]);
        exit;
    }

    $parentId = (int)($_POST['parent_id'] ?? 0);
    $rawActions = (string)($_POST['actions'] ?? '');
    $actions = json_decode($rawActions, true);

    if ($parentId <= 0 || !is_array($actions) || empty($actions)) {
        echo json_encode(['ok' => false, 'message' => 'No attendance actions to submit.']);
        exit;
    }

    $today = date('Y-m-d');
    $now = date('H:i:s');
    $results = [];
    $successCount = 0;
    $failedCount = 0;

    $verify = $pdo->prepare("
        SELECT s.id, s.student_name
        FROM students s
        JOIN pcm_enrolments e ON e.student_id = s.id AND e.status = 'Approved'
        WHERE s.id = :cid AND s.parentId = :pid
        LIMIT 1
    ");
    $selectLog = $pdo->prepare("SELECT id, time_in, time_out FROM pcm_kiosk_log WHERE child_id = :cid AND log_date = :d LIMIT 1");
    $insertIn = $pdo->prepare("
        INSERT INTO pcm_kiosk_log (child_id, parent_id, log_date, time_in, method)
        VALUES (:cid, :pid, :d, :t, 'KIOSK')
    ");
    $updateOut = $pdo->prepare("
        UPDATE pcm_kiosk_log SET time_out = :t
        WHERE child_id = :cid AND log_date = :d AND time_out IS NULL
    ");

    foreach ($actions as $row) {
        $childId = (int)($row['child_id'] ?? 0);
        $mode = (string)($row['mode'] ?? '');

        if ($childId <= 0 || !in_array($mode, ['in', 'out'], true)) {
            $failedCount++;
            $results[] = ['child_id' => $childId, 'mode' => $mode, 'ok' => false, 'message' => 'Invalid child action.'];
            continue;
        }

        $verify->execute([':cid' => $childId, ':pid' => $parentId]);
        $child = $verify->fetch();
        if (!$child) {
            $failedCount++;
            $results[] = ['child_id' => $childId, 'mode' => $mode, 'ok' => false, 'message' => 'Child not found or not enrolled.'];
            continue;
        }

        if ($mode === 'in') {
            $selectLog->execute([':cid' => $childId, ':d' => $today]);
            $existing = $selectLog->fetch();
            if ($existing && $existing['time_in'] && !$existing['time_out']) {
                $failedCount++;
                $results[] = ['child_id' => $childId, 'child_name' => $child['student_name'], 'mode' => 'in', 'ok' => false, 'message' => $child['student_name'] . ' is already signed in.'];
                continue;
            }
            if ($existing && $existing['time_out']) {
                $failedCount++;
                $results[] = ['child_id' => $childId, 'child_name' => $child['student_name'], 'mode' => 'in', 'ok' => false, 'message' => $child['student_name'] . ' is already signed out today.'];
                continue;
            }

            $insertIn->execute([':cid' => $childId, ':pid' => $parentId, ':d' => $today, ':t' => $now]);
            $successCount++;
            $results[] = ['child_id' => $childId, 'child_name' => $child['student_name'], 'mode' => 'in', 'ok' => true, 'message' => $child['student_name'] . ' signed in.'];
            continue;
        }

        $updateOut->execute([':t' => $now, ':cid' => $childId, ':d' => $today]);
        if ($updateOut->rowCount() === 0) {
            $failedCount++;
            $results[] = ['child_id' => $childId, 'child_name' => $child['student_name'], 'mode' => 'out', 'ok' => false, 'message' => 'No active sign-in found for ' . $child['student_name'] . '.'];
            continue;
        }
        $successCount++;
        $results[] = ['child_id' => $childId, 'child_name' => $child['student_name'], 'mode' => 'out', 'ok' => true, 'message' => $child['student_name'] . ' signed out.'];
    }

    if ($successCount === 0) {
        $msg = !empty($results[0]['message']) ? (string)$results[0]['message'] : 'No actions were completed.';
        echo json_encode(['ok' => false, 'message' => $msg, 'data' => ['results' => $results, 'success_count' => 0, 'failed_count' => $failedCount]]);
        exit;
    }

    $message = ($failedCount > 0)
        ? ("Submitted " . $successCount . " action(s). " . $failedCount . " could not be processed.")
        : ("Submitted " . $successCount . " action(s) successfully.");

    echo json_encode([
        'ok' => true,
        'data' => [
            'message' => $message,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ],
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// ACTION: Sign in or sign out a child
// ═══════════════════════════════════════════════════════════
if ($action === 'sign') {
    if (!bbcc_verify_kiosk_mobile_session()) {
        echo json_encode(['ok' => false, 'message' => 'Session expired. Please scan the QR code again at the door.', 'token_expired' => true]);
        exit;
    }

    $parentId  = (int)($_POST['parent_id'] ?? 0);
    $childId   = (int)($_POST['child_id'] ?? 0);
    $signMode  = $_POST['mode'] ?? ''; // 'in' or 'out'

    if (!$parentId || !$childId || !in_array($signMode, ['in', 'out'], true)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
        exit;
    }

    // Verify the child belongs to this parent and has an approved enrolment
    $verify = $pdo->prepare("
        SELECT s.id, s.student_name
        FROM students s
        JOIN pcm_enrolments e ON e.student_id = s.id AND e.status = 'Approved'
        WHERE s.id = :cid AND s.parentId = :pid
        LIMIT 1
    ");
    $verify->execute([':cid' => $childId, ':pid' => $parentId]);
    $child = $verify->fetch();

    if (!$child) {
        echo json_encode(['ok' => false, 'message' => 'Child not found or not enrolled.']);
        exit;
    }

    $today = date('Y-m-d');
    $now   = date('H:i:s');
    $nowDisplay = date('h:i A');

    if ($signMode === 'in') {
        // Sign in: insert new row or skip if already signed in
        $chk = $pdo->prepare("SELECT id, time_in, time_out FROM pcm_kiosk_log WHERE child_id = :cid AND log_date = :d LIMIT 1");
        $chk->execute([':cid' => $childId, ':d' => $today]);
        $existing = $chk->fetch();

        if ($existing && $existing['time_in'] && !$existing['time_out']) {
            echo json_encode(['ok' => false, 'message' => $child['student_name'] . ' is already signed in.']);
            exit;
        }

        if ($existing && $existing['time_out']) {
            echo json_encode(['ok' => false, 'message' => $child['student_name'] . ' has already been signed in and out today.']);
            exit;
        }

        $ins = $pdo->prepare("
            INSERT INTO pcm_kiosk_log (child_id, parent_id, log_date, time_in, method)
            VALUES (:cid, :pid, :d, :t, 'KIOSK')
        ");
        $ins->execute([':cid' => $childId, ':pid' => $parentId, ':d' => $today, ':t' => $now]);

        echo json_encode([
            'ok'   => true,
            'data' => [
                'action'       => 'in',
                'child_name'   => $child['student_name'],
                'time'         => $nowDisplay,
                'message'      => $child['student_name'] . ' signed in at ' . $nowDisplay,
            ],
        ]);
        exit;
    }

    if ($signMode === 'out') {
        // Sign out: update existing row
        $upd = $pdo->prepare("
            UPDATE pcm_kiosk_log SET time_out = :t
            WHERE child_id = :cid AND log_date = :d AND time_out IS NULL
        ");
        $upd->execute([':t' => $now, ':cid' => $childId, ':d' => $today]);

        if ($upd->rowCount() === 0) {
            echo json_encode(['ok' => false, 'message' => 'No active sign-in found for ' . $child['student_name'] . '. Please sign in first.']);
            exit;
        }

        echo json_encode([
            'ok'   => true,
            'data' => [
                'action'       => 'out',
                'child_name'   => $child['student_name'],
                'time'         => $nowDisplay,
                'message'      => $child['student_name'] . ' signed out at ' . $nowDisplay,
            ],
        ]);
        exit;
    }
}

// ─── Unknown action ───
echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
