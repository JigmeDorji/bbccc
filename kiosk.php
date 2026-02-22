<?php
// kiosk.php — Standalone phone + PIN kiosk for parent sign-in/out
// No login session required. Fully self-contained.
require_once "include/config.php";
require_once "include/csrf.php";

$pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER, $DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$error = '';
$parentRow   = null;
$children    = [];
$signResult  = '';
$signOk      = false;

// Rate-limit: max 5 failed attempts per phone per 15 min
function kiosk_is_locked(PDO $pdo, string $phone, string $ip): bool {
    $cutoff = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM pcm_kiosk_failed
        WHERE (phone=:p OR ip_address=:ip) AND attempted_at >= :t
    ");
    $stmt->execute([':p'=>$phone, ':ip'=>$ip, ':t'=>$cutoff]);
    return $stmt->fetchColumn() >= 5;
}

function kiosk_record_fail(PDO $pdo, string $phone, string $ip): void {
    $pdo->prepare("INSERT INTO pcm_kiosk_failed (phone, ip_address) VALUES (:p,:ip)")
        ->execute([':p'=>$phone, ':ip'=>$ip]);
}

// ── POST: authenticate ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'login') {
    verify_csrf();
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
    $pin   = trim($_POST['pin'] ?? '');

    if (!$phone || !$pin) {
        $error = 'Phone and PIN are required.';
    } elseif (kiosk_is_locked($pdo, $phone, $ip)) {
        $error = 'Too many attempts. Please wait 15 minutes.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM parents WHERE REPLACE(REPLACE(phone,' ',''),'-','') = :p AND status='Active' LIMIT 1");
        $stmt->execute([':p'=>$phone]);
        $row = $stmt->fetch();

        if (!$row || empty($row['pin_hash']) || !password_verify($pin, $row['pin_hash'])) {
            kiosk_record_fail($pdo, $phone, $ip);
            $error = 'Invalid phone or PIN.';
        } else {
            $parentRow = $row;
            // Load approved children
            $kids = $pdo->prepare("
                SELECT s.id, s.student_id, s.student_name
                FROM students s
                JOIN pcm_enrolments e ON e.student_id = s.id AND e.status='Approved'
                WHERE s.parentId = :pid
                ORDER BY s.student_name
            ");
            $kids->execute([':pid'=>$parentRow['id']]);
            $children = $kids->fetchAll();
        }
    }
}

// ── POST: sign in / sign out ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'sign') {
    verify_csrf();
    $pid     = (int)($_POST['parent_id'] ?? 0);
    $childId = (int)($_POST['child_id'] ?? 0);
    $signAct = $_POST['sign_action'] ?? '';

    // Re-load parent to be safe
    $stmt = $pdo->prepare("SELECT * FROM parents WHERE id=:id LIMIT 1");
    $stmt->execute([':id'=>$pid]);
    $parentRow = $stmt->fetch();

    if ($parentRow) {
        $kids = $pdo->prepare("
            SELECT s.id, s.student_id, s.student_name
            FROM students s
            JOIN pcm_enrolments e ON e.student_id = s.id AND e.status='Approved'
            WHERE s.parentId = :pid ORDER BY s.student_name
        ");
        $kids->execute([':pid'=>$pid]);
        $children = $kids->fetchAll();
    }

    $today = date('Y-m-d');
    $now   = date('H:i:s');

    if ($signAct === 'in') {
        $ins = $pdo->prepare("
            INSERT INTO pcm_kiosk_log (child_id, parent_id, log_date, time_in, method)
            VALUES (:cid, :pid, :d, :t, 'KIOSK')
            ON DUPLICATE KEY UPDATE time_in = CASE WHEN time_in IS NULL THEN VALUES(time_in) ELSE time_in END
        ");
        $ins->execute([':cid'=>$childId, ':pid'=>$pid, ':d'=>$today, ':t'=>$now]);
        $signResult = 'Signed in at ' . date('h:i A');
        $signOk = true;
    } elseif ($signAct === 'out') {
        $upd = $pdo->prepare("
            UPDATE pcm_kiosk_log SET time_out = :t
            WHERE child_id = :cid AND log_date = :d AND time_out IS NULL
        ");
        $upd->execute([':t'=>$now, ':cid'=>$childId, ':d'=>$today]);
        if ($upd->rowCount()) {
            $signResult = 'Signed out at ' . date('h:i A');
            $signOk = true;
        } else {
            $signResult = 'No open sign-in found for today. Sign in first.';
        }
    }
}

// Build per-child status for today
$childStatus = [];
if ($parentRow && $children) {
    foreach ($children as $c) {
        $chk = $pdo->prepare("SELECT time_in, time_out FROM pcm_kiosk_log WHERE child_id=:cid AND log_date=:d LIMIT 1");
        $chk->execute([':cid'=>$c['id'], ':d'=>date('Y-m-d')]);
        $childStatus[$c['id']] = $chk->fetch() ?: null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <title>Kiosk — Sign In / Out</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); min-height: 100vh; display:flex; align-items:center; justify-content:center; }
        .kiosk-card { max-width:520px; width:100%; margin:20px; }
        .kiosk-header { background:#fff; text-align:center; padding:24px; border-radius:16px 16px 0 0; border-bottom:3px solid #4e73df; }
        .kiosk-header img { height:60px; margin-bottom:8px; }
        .kiosk-header h4 { margin:0; font-weight:700; color:#1a1a2e; }
        .kiosk-body { background:#fff; padding:28px; border-radius:0 0 16px 16px; }
        .child-row { border:1px solid #e3e6f0; border-radius:10px; padding:14px 18px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; }
        .child-row .name { font-weight:600; font-size:1rem; }
        .child-row .code { font-size:0.78rem; color:#888; }
        .child-row .actions form { display:inline-block; margin-left:6px; }
        .status-tag { font-size:0.72rem; background:#f0f2f5; padding:3px 8px; border-radius:6px; }
        .status-tag.in  { background:#d4edda; color:#155724; }
        .status-tag.out { background:#f8d7da; color:#721c24; }
        #countdown { font-size:0.85rem; color:#888; margin-top:12px; text-align:center; }
    </style>
</head>
<body>

<div class="kiosk-card">
    <div class="kiosk-header">
        <img src="bbccassests/img/logo/logo5.jpg" alt="Logo" onerror="this.style.display='none'">
        <h4>Kiosk Sign In / Out</h4>
        <p class="mb-0 text-muted" style="font-size:0.85rem"><?= date('l, d F Y — h:i A') ?></p>
    </div>
    <div class="kiosk-body">

    <?php if ($signResult): ?>
        <script>
        document.addEventListener('DOMContentLoaded',()=>{
            Swal.fire({icon:'<?= $signOk?"success":"warning" ?>',title:<?= json_encode($signResult) ?>,timer:2000,showConfirmButton:false});
        });
        </script>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-1"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$parentRow): ?>
    <!-- ─── Login Form ─── -->
    <form method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="login">

        <div class="form-group">
            <label class="font-weight-bold"><i class="fas fa-phone mr-1"></i>Phone Number</label>
            <input type="tel" name="phone" class="form-control form-control-lg" placeholder="04xx xxx xxx" required autofocus
                   inputmode="numeric" pattern="[0-9]*" maxlength="15">
        </div>
        <div class="form-group">
            <label class="font-weight-bold"><i class="fas fa-lock mr-1"></i>PIN</label>
            <input type="password" name="pin" class="form-control form-control-lg" placeholder="Enter your PIN" required
                   inputmode="numeric" pattern="[0-9]*" maxlength="6">
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="fas fa-sign-in-alt mr-2"></i>Continue</button>
    </form>

    <?php else: ?>
    <!-- ─── Children List ─── -->
    <p class="mb-3">Welcome, <strong><?= htmlspecialchars($parentRow['full_name']) ?></strong></p>

    <?php if (empty($children)): ?>
        <p class="text-muted">No approved children found. Please complete enrolment first.</p>
    <?php else: ?>
        <?php foreach ($children as $c):
            $st = $childStatus[$c['id']] ?? null;
            $signedIn  = $st && $st['time_in'] && !$st['time_out'];
            $signedOut = $st && $st['time_out'];
        ?>
        <div class="child-row">
            <div>
                <div class="name"><?= htmlspecialchars($c['student_name']) ?></div>
                <div class="code"><?= htmlspecialchars($c['student_id']) ?></div>
                <?php if ($signedIn): ?>
                    <span class="status-tag in">Signed in at <?= date('h:i A', strtotime($st['time_in'])) ?></span>
                <?php elseif ($signedOut): ?>
                    <span class="status-tag out">Done for today</span>
                <?php endif; ?>
            </div>
            <div class="actions">
                <?php if (!$signedIn && !$signedOut): ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="step" value="sign">
                    <input type="hidden" name="parent_id" value="<?= $parentRow['id'] ?>">
                    <input type="hidden" name="child_id" value="<?= $c['id'] ?>">
                    <input type="hidden" name="sign_action" value="in">
                    <button class="btn btn-success btn-sm"><i class="fas fa-sign-in-alt mr-1"></i>Sign In</button>
                </form>
                <?php elseif ($signedIn): ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="step" value="sign">
                    <input type="hidden" name="parent_id" value="<?= $parentRow['id'] ?>">
                    <input type="hidden" name="child_id" value="<?= $c['id'] ?>">
                    <input type="hidden" name="sign_action" value="out">
                    <button class="btn btn-danger btn-sm"><i class="fas fa-sign-out-alt mr-1"></i>Sign Out</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div id="countdown">Session will reset in <span id="timer">60</span>s</div>
    <a href="kiosk.php" class="btn btn-outline-secondary btn-block mt-2"><i class="fas fa-redo mr-1"></i>Done / Switch Parent</a>

    <script>
    (function(){
        var sec = 60;
        var el = document.getElementById('timer');
        var iv = setInterval(function(){
            sec--;
            if(el) el.textContent = sec;
            if(sec <= 0){ clearInterval(iv); window.location.href = 'kiosk.php'; }
        }, 1000);
    })();
    </script>
    <?php endif; ?>

    </div>
</div>

</body>
</html>
