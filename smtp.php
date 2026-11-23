<?php
// Prevent direct browser access to SMTP credentials
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Direct access denied.');
}

// Load PHPMailer from /dusknet/PHPMailer/
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =========================================================================
// DuskNet SMTP Configuration (Gmail / Outlook)
// =========================================================================
const SMTP_HOST       = 'smtp.gmail.com';        // Outlook: smtp-mail.outlook.com
const SMTP_PORT       = 587;
const SMTP_USER       = 'enverblade@gmail.com';    // Sender email address
const SMTP_PASS       = 'ljkg ytfv hmmo ertz';     // 16-char Gmail App Password
const SMTP_FROM_EMAIL = 'dusknetsupport@gmail.com';
const SMTP_FROM_NAME  = 'DuskNet';

/**
 * Sends an email via PHPMailer using the SMTP settings above.
 * Returns ['ok' => bool, 'error' => string]
 */
function sendDuskNetMail(
    string $toEmail,
    string $toName,
    string $subject,
    string $body,
    bool $isHtml = false
): array {
    if (SMTP_USER === 'yourmail@gmail.com' || SMTP_PASS === 'your-app-password') {
        return [
            'ok'    => false,
            'error' => 'SMTP settings are not configured yet. Please update smtp.php.'
        ];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return ['ok' => true, 'error' => ''];
    } catch (Exception $e) {
        return [
            'ok'    => false,
            'error' => 'Mail could not be sent: ' . $mail->ErrorInfo
        ];
    }
}