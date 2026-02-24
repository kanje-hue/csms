<?php
// PHPMailer Configuration for Mailtrap

// Load PHPMailer classes
require __DIR__ . '/../vendor/phpmailer/Exception.php';
require __DIR__ . '/../vendor/phpmailer/SMTP.php';
require __DIR__ . '/../vendor/phpmailer/PHPMailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Mailtrap SMTP Configuration
define('MAIL_HOST', 'sandbox.smtp.mailtrap.io');
define('MAIL_PORT', 2525);
define('MAIL_USERNAME', 'b028eb88d16123');
define('MAIL_PASSWORD', 'a806358af904e1');
define('MAIL_FROM', 'noreply@csms.com');
define('MAIL_FROM_NAME', 'CSMS System');

/**
 * Send email using PHPMailer with Mailtrap
 */
function send_email($to_email, $to_name, $subject, $html_body) {
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->Port = MAIL_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        
        // Sender
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        
        // Recipient
        $mail->addAddress($to_email, $to_name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html_body;
        $mail->AltBody = strip_tags($html_body);
        
        // Send
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Email Error: " . $e->getMessage());
        return false;
    }
}
?>