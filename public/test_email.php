<?php
/**
 * public/test_email.php
 * Test file to verify email configuration
 */

require_once '../config/email_config.php';

echo "<h1>Email Configuration Test</h1>";

// Test code
$test_code = sprintf("%06d", rand(0, 999999));

// Send test email
$result = send_email(
    'test@example.com',
    'Test User',
    'Test Email - CSMS',
    "<div style='font-family: Arial, sans-serif;'>
        <h2>Test Email</h2>
        <p>Your test verification code is:</p>
        <div style='font-size: 32px; font-weight: bold; color: #16a085;'>
            {$test_code}
        </div>
    </div>"
);

echo "<h2>Result:</h2>";
if ($result) {
    echo "<p style='color: green;'>✓ Email function executed successfully</p>";
    echo "<p>Test code generated: <strong>$test_code</strong></p>";
} else {
    echo "<p style='color: red;'>✗ Email function failed</p>";
}

// Show log file contents
$log_file = __DIR__ . '/../logs/email.log';
echo "<h2>Log File Contents:</h2>";
if (file_exists($log_file)) {
    echo "<pre style='background: #f4f4f4; padding: 10px; overflow: auto; max-height: 300px;'>";
    echo file_get_contents($log_file);
    echo "</pre>";
} else {
    echo "<p>Log file not found yet. Path: $log_file</p>";
}

// Check if logs directory is writable
$logs_dir = __DIR__ . '/../logs';
echo "<h2>Directory Permissions:</h2>";
if (is_writable($logs_dir)) {
    echo "<p style='color: green;'>✓ Logs directory is writable</p>";
} else {
    echo "<p style='color: red;'>✗ Logs directory is NOT writable. Path: $logs_dir</p>";
    echo "<p>Run: chmod 777 $logs_dir (on Linux/Mac)</p>";
}
?>