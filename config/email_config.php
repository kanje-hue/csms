<?php
/**
 * config/email_config.php
 * Simple email configuration with logging
 */
function send_email($to, $name, $subject, $html_message) {
    // Log file path
    $log_file = __DIR__ . '/../logs/email.log';
    
    // Create logs directory if it doesn't exist
    if (!file_exists(dirname($log_file))) {
        mkdir(dirname($log_file), 0777, true);
    }
    
    // Extract the verification code from the message
    preg_match('/<div[^>]*style="[^"]*font-size: 32px[^"]*">(\d+)<\/div>/', $html_message, $matches);
    $code = $matches[1] ?? 'unknown';
    
    // Log the email for development
    $log_message = date('Y-m-d H:i:s') . " - PASSWORD RESET\n";
    $log_message .= "To: $to\n";
    $log_message .= "Subject: $subject\n";
    $log_message .= "Verification Code: $code\n";
    $log_message .= "Full message: " . strip_tags($html_message) . "\n";
    $log_message .= str_repeat('=', 50) . "\n\n";
    
    file_put_contents($log_file, $log_message, FILE_APPEND);
    
    // FOR PRODUCTION: Uncomment and configure this section when you have email working
    /*
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: CSMS Portal <noreply@yourdomain.com>\r\n";
    
    if (mail($to, $subject, $html_message, $headers)) {
        return true;
    }
    return false;
    */
    
    // For development, always return true
    return true;
}
?>