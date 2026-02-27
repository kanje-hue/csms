<?php
/**
 * config/debug.php
 * Debug logging helper
 */
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/../logs/debug.log';
    $dir = dirname($log_file);
    
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message";
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log_entry .= "\n" . print_r($data, true);
        } else {
            $log_entry .= "\n" . $data;
        }
    }
    
    $log_entry .= "\n" . str_repeat('-', 50) . "\n";
    
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}
?>