<?php
// include/mailer.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('bbcc_env')) {
    function bbcc_env(string $key, string $default = ''): string {
        $value = getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}

if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', bbcc_env('MAIL_FROM_EMAIL', bbcc_env('MAIL_USERNAME', 'no-reply@localhost')));
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', bbcc_env('MAIL_FROM_NAME', 'Bhutanese Centre Canberra'));
}

function bbcc_mail_config(): array {
    return [
        'host'       => bbcc_env('MAIL_HOST', 'smtp.gmail.com'),
        'port'       => (int)bbcc_env('MAIL_PORT', '587'),
        'encryption' => bbcc_env('MAIL_ENCRYPTION', 'tls'),
        'username'   => bbcc_env('MAIL_USERNAME', ''),
        'password'   => bbcc_env('MAIL_PASSWORD', ''),
        'from_email' => MAIL_FROM_EMAIL,
        'from_name'  => MAIL_FROM_NAME,
        'debug'      => in_array(strtolower(bbcc_env('MAIL_DEBUG', '0')), ['1', 'true', 'on', 'yes'], true),
        'log_file'   => bbcc_env('MAIL_LOG_FILE', __DIR__ . '/../mail_error.log'),
    ];
}

function bbcc_mail_log(string $message): void {
    $cfg = bbcc_mail_config();
    @file_put_contents($cfg['log_file'], date('c') . ' ' . $message . PHP_EOL, FILE_APPEND);
}

function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $cfg = bbcc_mail_config();

    if ($cfg['username'] === '' || $cfg['password'] === '') {
        bbcc_mail_log('MAIL CONFIG ERROR: MAIL_USERNAME or MAIL_PASSWORD is not set.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();

        if ($cfg['debug']) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = 'html';
        }

        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = $cfg['encryption'];
        $mail->Port       = $cfg['port'];

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        return $mail->send();

    } catch (Exception $e) {
        bbcc_mail_log('MAIL ERROR: ' . $mail->ErrorInfo . ' | EX: ' . $e->getMessage());
        return false;
    }
}
