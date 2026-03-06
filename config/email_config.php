<?php
/**
 * config/email_config.php - Mailtrap Email Configuration with PHPMailer
 * Supports both verification codes and magic links
 */

// Load Composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Main email sending function using PHPMailer with Mailtrap
 */
function send_email($to, $name, $subject, $html_message) {
    // Log file for backup
    $log_file = __DIR__ . '/../logs/email.log';
    if (!file_exists(dirname($log_file))) {
        mkdir(dirname($log_file), 0777, true);
    }
    
    // Mailtrap credentials
    $smtp_host = 'sandbox.smtp.mailtrap.io';
    $smtp_port = 2525;
    $username = 'b028eb88d16123';
    $password = 'a806358af904e1';
    
    // Log the attempt
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Attempting to send email to $to via Mailtrap\n", FILE_APPEND);
    
    // Create a new PHPMailer instance
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $smtp_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->Port       = $smtp_port;
        
        // Disable SSL verification for development
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients
        $mail->setFrom('noreply@csms.com', 'CSMS System');
        $mail->addAddress($to, $name);
        $mail->addReplyTo('noreply@csms.com', 'CSMS Support');
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_message;
        $mail->AltBody = strip_tags($html_message);
        
        $mail->send();
        
        // Log success
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - ✅ Email sent successfully to $to via PHPMailer\n", FILE_APPEND);
        return true;
        
    } catch (Exception $e) {
        // Log error
        $error_msg = "❌ PHPMailer Error: " . $mail->ErrorInfo;
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - $error_msg\n", FILE_APPEND);
        
        // Fallback to logging the email
        return log_email_only($to, $name, $subject, $html_message, $log_file);
    }
}

/**
 * Fallback: Just log the email
 */
function log_email_only($to, $name, $subject, $html_message, $log_file) {
    $log_message = "\n" . str_repeat('=', 60) . "\n";
    $log_message .= date('Y-m-d H:i:s') . " - EMAIL (LOGGED ONLY - PHPMailer failed)\n";
    $log_message .= "To: $to\n";
    $log_message .= "Name: $name\n";
    $log_message .= "Subject: $subject\n";
    $log_message .= "Message: " . strip_tags($html_message) . "\n";
    
    // Extract any links
    if (preg_match_all('/href="([^"]+)"/', $html_message, $matches)) {
        $log_message .= "Links found:\n";
        foreach ($matches[1] as $link) {
            $log_message .= "  - $link\n";
        }
    }
    
    // Extract verification code
    if (preg_match('/<div class="verification-code">(\d{6})<\/div>/', $html_message, $matches)) {
        $log_message .= "Verification Code: " . $matches[1] . "\n";
    }
    
    $log_message .= str_repeat('=', 60) . "\n\n";
    
    file_put_contents($log_file, $log_message, FILE_APPEND);
    return true;
}

/**
 * Send password reset email with both verification code and link
 */
function send_password_reset_email($to, $name, $magic_link, $verification_code) {
    $subject = "Reset Your Password - CSMS";
    $html_message = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px; }
            .header { background: linear-gradient(135deg, #2dd4bf, #14b8a6); color: white; padding: 25px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
            .code-box { 
                background: #f0f9ff; 
                border: 2px dashed #2dd4bf; 
                padding: 20px; 
                text-align: center; 
                margin: 25px 0; 
                border-radius: 10px;
            }
            .verification-code { 
                font-size: 42px; 
                font-weight: bold; 
                letter-spacing: 10px; 
                color: #0f172a; 
                font-family: monospace;
                background: white;
                padding: 15px;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
            }
            .button { 
                display: inline-block; 
                padding: 14px 28px; 
                background: linear-gradient(135deg, #2dd4bf, #14b8a6); 
                color: white; 
                text-decoration: none; 
                border-radius: 8px; 
                margin: 15px 0; 
                font-weight: bold;
                font-size: 16px;
            }
            .divider { 
                text-align: center; 
                margin: 25px 0; 
                position: relative;
            }
            .divider:before,
            .divider:after {
                content: '';
                position: absolute;
                top: 50%;
                width: 45%;
                height: 1px;
                background: #e2e8f0;
            }
            .divider:before { left: 0; }
            .divider:after { right: 0; }
            .divider span { 
                background: white; 
                padding: 0 15px; 
                color: #64748b; 
                font-weight: 600;
            }
            .footer { text-align: center; margin-top: 30px; color: #64748b; font-size: 12px; }
            .note { background: #fff3cd; border-left: 4px solid #f59e0b; padding: 12px; margin: 20px 0; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🔐 Password Reset Request</h1>
            </div>
            <div class='content'>
                <h2>Hello $name,</h2>
                <p>We received a request to reset your password. You can use either of the following methods:</p>
                
                <h3 style='color: #0f172a;'>📱 Method 1: Use Verification Code</h3>
                <div class='code-box'>
                    <p style='margin-bottom: 10px; color: #475569;'>Enter this 6-digit code on the website:</p>
                    <div class='verification-code'>$verification_code</div>
                    <p style='margin-top: 10px; color: #64748b;'>This code expires in 1 hour</p>
                </div>
                
                <div class='divider'>
                    <span>OR</span>
                </div>
                
                <h3 style='color: #0f172a;'>🔗 Method 2: Click the Magic Link</h3>
                <div style='text-align: center; margin: 20px 0;'>
                    <a href='$magic_link' class='button'>🔑 Click Here to Reset Password</a>
                </div>
                <p style='color: #64748b; font-size: 14px;'>Or copy this link to your browser:</p>
                <p style='background: #f1f5f9; padding: 12px; word-break: break-all; border-radius: 5px; font-size: 13px;'>$magic_link</p>
                
                <div class='note'>
                    <strong>⏰ Note:</strong> This link and code will expire in 1 hour. If you didn't request this, please ignore this email.
                </div>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " CSMS - College Student Management System</p>
                <p style='margin-top: 5px;'>This is an automated message, please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return send_email($to, $name, $subject, $html_message);
}

/**
 * Send teacher welcome email
 */
function send_teacher_welcome_email($to, $name, $temp_password, $login_link) {
    $subject = "Welcome to CSMS - Your Teacher Account";
    $html_message = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px; }
            .header { background: linear-gradient(135deg, #2dd4bf, #14b8a6); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
            .credentials { background: #f0f0f0; padding: 15px; border-left: 4px solid #2dd4bf; margin: 20px 0; font-family: monospace; }
            .warning { color: #e74c3c; font-weight: bold; }
            .button { display: inline-block; padding: 10px 20px; background: #2dd4bf; color: white; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to CSMS!</h1>
            </div>
            <div class='content'>
                <h2>Hello $name,</h2>
                <p>Your teacher account has been created. Use the credentials below to login:</p>
                
                <div class='credentials'>
                    <p><strong>Email:</strong> $to</p>
                    <p><strong>Temporary Password:</strong> <span style='font-size: 16px;'>$temp_password</span></p>
                </div>
                
                <p class='warning'>⚠️ You will be required to change your password on first login.</p>
                
                <p style='text-align: center;'>
                    <a href='$login_link' class='button'>Login to CSMS</a>
                </p>
                
                <p>Or copy this link: $login_link</p>
                
                <hr>
                <p style='color: #888; font-size: 12px;'>This is an automated message. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return send_email($to, $name, $subject, $html_message);
}

/**
 * Send student notification
 */
function send_student_notification($to, $name, $subject, $message) {
    $html_message = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px; }
            .header { background: #2dd4bf; color: white; padding: 15px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>CSMS Notification</h2>
            </div>
            <div class='content'>
                <p>Hello $name,</p>
                <p>$message</p>
                <hr>
                <p style='color: #888;'>CSMS - College Student Management System</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return send_email($to, $name, $subject, $html_message);
}
?>