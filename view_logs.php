<?php
/**
 * view_logs.php - Quick log viewer
 * Access via: http://your-site.com/view_logs.php
 */

// Security: Only allow local access or add password
$allowed_ips = ['127.0.0.1', '::1', 'localhost'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    die('Access denied');
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>Log Viewer</title>
    <style>
        body { font-family: monospace; background: #1e1e1e; color: #d4d4d4; padding: 20px; }
        .log-container { background: #252526; padding: 20px; border-radius: 5px; }
        .log-entry { border-bottom: 1px solid #3e3e42; padding: 10px 0; }
        .timestamp { color: #569cd6; }
        .success { color: #6a9955; }
        .error { color: #f48771; }
        .warning { color: #dcdcaa; }
        h2 { color: #4ec9b0; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #4ec9b0; margin-right: 15px; text-decoration: none; }
        .nav a:hover { text-decoration: underline; }
        pre { background: #1e1e1e; padding: 10px; overflow: auto; }
    </style>
</head>
<body>
    <div class='nav'>
        <a href='?file=debug'>Debug Log</a>
        <a href='?file=email'>Email Log</a>
        <a href='?file=php'>PHP Error Log</a>
        <a href='?clear=1'>Clear Current Log</a>
    </div>";

$file = $_GET['file'] ?? 'debug';
$log_dir = __DIR__ . '/logs/';

switch($file) {
    case 'email':
        $log_file = $log_dir . 'email.log';
        $title = 'Email Log';
        break;
    case 'php':
        $log_file = ini_get('error_log');
        $title = 'PHP Error Log';
        break;
    case 'debug':
    default:
        $log_file = $log_dir . 'debug.log';
        $title = 'Debug Log';
}

// Clear log if requested
if (isset($_GET['clear']) && file_exists($log_file)) {
    file_put_contents($log_file, '');
    echo "<div style='background: #2d2d30; padding: 10px; margin-bottom: 10px; color: #6a9955;'>✓ Log cleared</div>";
}

echo "<h2>$title - " . date('Y-m-d H:i:s') . "</h2>";
echo "<div class='log-container'>";

if (file_exists($log_file)) {
    $logs = file_get_contents($log_file);
    if (empty($logs)) {
        echo "<div class='log-entry'>Log file is empty</div>";
    } else {
        echo "<pre>$logs</pre>";
    }
    echo "<p><small>File: $log_file</small></p>";
    echo "<p><small>Size: " . filesize($log_file) . " bytes</small></p>";
} else {
    echo "<div class='log-entry error'>Log file not found: $log_file</div>";
    
    // Try to create the file
    if ($file !== 'php') {
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0777, true);
            echo "<div class='log-entry success'>✓ Created logs directory</div>";
        }
        $touch = touch($log_file);
        if ($touch) {
            echo "<div class='log-entry success'>✓ Created log file</div>";
        } else {
            echo "<div class='log-entry error'>✗ Could not create log file. Check permissions.</div>";
        }
    }
}

echo "</div></body></html>";
?>