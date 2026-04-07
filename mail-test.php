<?php
// mail-test.php — quick SMTP test utility (admin only)
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/csrf.php";
require_once "include/mailer.php";
require_login();

if (!is_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$toEmail = trim((string)($_POST['to_email'] ?? ($_SESSION['username'] ?? '')));
$result = null;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $result = 'error';
        $message = 'Please enter a valid recipient email.';
    } else {
        $subject = 'BBCC Mail Test (' . date('Y-m-d H:i:s') . ')';
        $html = "
            <p>Hello,</p>
            <p>This is a <strong>mail test</strong> from your local BBCC setup.</p>
            <p><strong>Time:</strong> " . htmlspecialchars(date('c')) . "</p>
            <p>If you received this, SMTP is working for this environment.</p>
        ";

        $ok = send_mail($toEmail, $toEmail, $subject, $html);
        if ($ok) {
            $result = 'success';
            $message = 'Mail sent successfully to ' . htmlspecialchars($toEmail) . '.';
        } else {
            $result = 'error';
            $message = 'Mail send failed. Check mail_error.log for details.';
        }
    }
}

$cfg = bbcc_mail_config();
function hmt(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function mask_secret(string $v): string {
    if ($v === '') return '(empty)';
    $len = strlen($v);
    if ($len <= 4) return str_repeat('*', $len);
    return substr($v, 0, 2) . str_repeat('*', max(0, $len - 4)) . substr($v, -2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Mail Test</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
<?php include 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column">
<div id="content">
<?php include 'include/admin-header.php'; ?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0 font-weight-bold text-gray-800">Mail Test</h1>
        <a href="index-admin" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Dashboard</a>
    </div>

    <?php if ($result === 'success'): ?>
        <div class="alert alert-success"><?= $message ?></div>
    <?php elseif ($result === 'error'): ?>
        <div class="alert alert-danger"><?= $message ?></div>
    <?php endif; ?>

    <div class="card shadow mb-4">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Send Test Email</h6></div>
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label>Recipient Email</label>
                    <input type="email" class="form-control" name="to_email" value="<?= hmt($toEmail) ?>" required>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i> Send Test</button>
            </form>
            <small class="text-muted d-block mt-2">If this fails, check <code>mail_error.log</code>.</small>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Current Mail Config (effective)</h6></div>
        <div class="card-body">
            <table class="table table-sm table-bordered mb-0">
                <tr><th style="width:200px;">Host</th><td><?= hmt((string)$cfg['host']) ?></td></tr>
                <tr><th>Port</th><td><?= hmt((string)$cfg['port']) ?></td></tr>
                <tr><th>Encryption</th><td><?= hmt((string)$cfg['encryption']) ?></td></tr>
                <tr><th>Username</th><td><?= hmt((string)$cfg['username']) ?></td></tr>
                <tr><th>Password</th><td><?= hmt(mask_secret((string)$cfg['password'])) ?></td></tr>
                <tr><th>From Email</th><td><?= hmt((string)$cfg['from_email']) ?></td></tr>
                <tr><th>From Name</th><td><?= hmt((string)$cfg['from_name']) ?></td></tr>
                <tr><th>Debug</th><td><?= !empty($cfg['debug']) ? 'on' : 'off' ?></td></tr>
                <tr><th>Log File</th><td><?= hmt((string)$cfg['log_file']) ?></td></tr>
            </table>
        </div>
    </div>
</div>

</div>
<?php include 'include/admin-footer.php'; ?>
</div>
</div>
</body>
</html>

