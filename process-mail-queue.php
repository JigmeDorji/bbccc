<?php
// process-mail-queue.php
// Usage:
//   php process-mail-queue.php
// Optional:
//   php process-mail-queue.php --limit=50

require_once __DIR__ . '/include/config.php';
require_once __DIR__ . '/include/mail_queue.php';

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/include/auth.php';
    require_once __DIR__ . '/include/role_helpers.php';
    require_login();
    if (!is_admin_role()) {
        header('Location: unauthorized');
        exit;
    }
}

$limit = 20;
if (PHP_SAPI === 'cli' && !empty($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--limit=') === 0) {
            $limit = (int)substr($arg, 8);
        }
    }
}

$stats = bbcc_process_mail_queue($limit);

if (PHP_SAPI === 'cli') {
    echo "Mail Queue Processed\n";
    echo "Picked: {$stats['picked']}\n";
    echo "Sent: {$stats['sent']}\n";
    echo "Failed: {$stats['failed']}\n";
    exit(0);
}

header('Content-Type: application/json');
echo json_encode($stats);
