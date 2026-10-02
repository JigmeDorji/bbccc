<?php
// process-mail-queue.php
// Usage:
//   php process-mail-queue.php
// Optional:
//   php process-mail-queue.php --limit=50

require_once __DIR__ . '/include/config.php';
require_once __DIR__ . '/include/mail_queue.php';

$isCommandLineRun = PHP_SAPI === 'cli'
    || (empty($_SERVER['REQUEST_METHOD']) && empty($_SERVER['HTTP_HOST']));

if (!$isCommandLineRun) {
    require_once __DIR__ . '/include/auth.php';
    require_once __DIR__ . '/include/role_helpers.php';
    require_login();
    if (!is_admin_role()) {
        header('Location: unauthorized');
        exit;
    }
}

$limit = (int)bbcc_env('MAIL_QUEUE_CRON_LIMIT', '50');
$limit = max(1, min(200, $limit));
if ($isCommandLineRun) {
    $workerArguments = $argv ?? ($_SERVER['argv'] ?? []);
    foreach ($workerArguments as $arg) {
        if (strpos($arg, '--limit=') === 0) {
            $limit = (int)substr($arg, 8);
        }
    }
}

$stats = bbcc_process_mail_queue($limit);

if ($isCommandLineRun) {
    echo "Mail Queue Processed\n";
    echo "Picked: {$stats['picked']}\n";
    echo "Sent: {$stats['sent']}\n";
    echo "Failed: {$stats['failed']}\n";
    exit(0);
}

header('Content-Type: application/json');
echo json_encode($stats);
