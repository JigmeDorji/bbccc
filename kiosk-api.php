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

$action = $_POST['action'] ?? '';
$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!function_exists('bbcc_app_timezone')) {
    function bbcc_app_timezone(): DateTimeZone {
        static $tz = null;
        if ($tz === null) {
            $tz = new DateTimeZone('Australia/Sydney');
        }
        return $tz;
    }
}

if (!function_exists('bbcc_today_date')) {
    function bbcc_today_date(): string {
        return (new DateTimeImmutable('now', bbcc_app_timezone()))->format('Y-m-d');
    }
}

if (!function_exists('bbcc_now_time')) {
    function bbcc_now_time(): string {
        return (new DateTimeImmutable('now', bbcc_app_timezone()))->format('H:i:s');
    }
}

if (!function_exists('bbcc_format_au_time')) {
    function bbcc_format_au_time($value): string {
        $value = trim((string)$value);
        if ($value === '') return '';
        try {
            return (new DateTimeImmutable($value, bbcc_app_timezone()))->format('h:i A');
        } catch (Throwable $e) {
            return $value;
        }
    }
}

function bbcc_kiosk_b64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function bbcc_kiosk_b64url_decode(string $value): string {
    $pad = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

function bbcc_kiosk_token_secret(): string {
    global $DB_NAME, $DB_USER, $DB_PASSWORD;
    return hash('sha256', $DB_NAME . '|' . $DB_USER . '|' . $DB_PASSWORD . '|' . __DIR__);
}

function bbcc_kiosk_make_token(): array {
    $nowDt = new DateTimeImmutable('now', bbcc_app_timezone());
    $expiresAtDt = $nowDt->setTime(23, 59, 59);
    $payload = [
        'exp' => $expiresAtDt->getTimestamp(),
        'nonce' => bin2hex(random_bytes(16)),
    ];
    $payloadJson = json_encode($payload);
    if ($payloadJson === false) {
        throw new RuntimeException('Could not create QR token.');
    }
    $body = bbcc_kiosk_b64url_encode($payloadJson);
    $sig = hash_hmac('sha256', $body, bbcc_kiosk_token_secret());
    return [
        'token' => $body . '.' . $sig,
        'expires_in' => max(1, $expiresAtDt->getTimestamp() - $nowDt->getTimestamp()),
    ];
}

function bbcc_kiosk_token_is_valid(string $token): bool {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }
    list($body, $sig) = $parts;
    $expected = hash_hmac('sha256', $body, bbcc_kiosk_token_secret());
    if (!hash_equals($expected, $sig)) {
        return false;
    }
    $payload = json_decode(bbcc_kiosk_b64url_decode($body), true);
    if (!is_array($payload) || empty($payload['exp'])) {
        return false;
    }
    return (int)$payload['exp'] >= time();
}

// ─── CSRF token endpoint (no verification needed) ───
if ($action === 'csrf') {
    echo json_encode(['ok' => true, 'token' => csrf_token()]);
    exit;
}

// ─── Generate rotating QR token (called by QR display iPad) ───
if ($action === 'generate_token') {
    try {
        echo json_encode(array_merge(['ok' => true], bbcc_kiosk_make_token()));
    } catch (Throwable $e) {
        error_log('[BBCC] kiosk token generation failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Could not create QR code.']);
    }
    exit;
}

// ─── Validate a QR token (called by mobile page on load) ───
if ($action === 'validate_token') {
    $qrToken = trim($_POST['qr_token'] ?? '');

    if (!$qrToken) {
        echo json_encode(['ok' => false, 'message' => 'No token provided.']);
        exit;
    }

    if (!bbcc_kiosk_token_is_valid($qrToken)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid QR code. Please scan again at the door.']);
        exit;
    }

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

// PDO connection for parent authentication and sign-in/out actions.
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
    $today = bbcc_today_date();
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

    $today = bbcc_today_date();
    $now = bbcc_now_time();
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

    $today = bbcc_today_date();
    $now   = bbcc_now_time();
    $nowDisplay = bbcc_format_au_time($now);

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
                'raw_time'     => $now,
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
                'raw_time'     => $now,
                'time'         => $nowDisplay,
                'message'      => $child['student_name'] . ' signed out at ' . $nowDisplay,
            ],
        ]);
        exit;
    }
}

// ─── Unknown action ───
echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
