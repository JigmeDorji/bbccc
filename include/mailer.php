<?php
// include/mailer.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

// ====== CONFIG (update these) ======
const MAIL_HOST = 'smtp.gmail.com';
const MAIL_PORT = 587;
const MAIL_ENCRYPTION = 'tls';
const MAIL_USERNAME = 'tsheringkoney@gmail.com';
const MAIL_PASSWORD = 'ftom bvjd inpx ablw'; // <-- Gmail APP password
const MAIL_FROM_EMAIL = 'tsheringkoney@gmail.com';
const MAIL_FROM_NAME  = 'Bhutanese Centre Canberra';

// Debug + log
const MAIL_DEBUG = false; // set true to see SMTP debug output
const MAIL_LOG_FILE = __DIR__ . '/../mail_error.log';

function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();

        if (MAIL_DEBUG) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = 'html';
        }

        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        return $mail->send();

    } catch (Exception $e) {
        $msg = date('c') . " MAIL ERROR: " . $mail->ErrorInfo . " | EX: " . $e->getMessage() . PHP_EOL;
        file_put_contents(MAIL_LOG_FILE, $msg, FILE_APPEND);
        return false;
    }
}
