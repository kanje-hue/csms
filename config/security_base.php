<?php
/**
 * config/security_base.php - Shared Security Functions
 * Include this in all admin files for consistent security
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if admin is logged in with session timeout
 */
function checkAdminSession() {
    if (!isset($_SESSION['admin_logged_in'])) {
        header("Location: login.php");
        exit();
    }
    
    // Session timeout (1 hour)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
        session_destroy();
        header("Location: login.php?timeout=1");
        exit();
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Validate CSRF token
 */
function validateCSRF($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        die("Security token verification failed");
    }
    return true;
}

/**
 * Generate CSRF token
 */
function generateCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Sanitize input to prevent XSS
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate ID parameter
 */
function validateID($id) {
    return filter_var($id, FILTER_VALIDATE_INT) && $id > 0;
}

/**
 * Log admin action for audit trail
 */
function logAdminAction($conn, $admin_id, $action, $description) {
    $stmt = $conn->prepare("
        INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt->bind_param("isss", $admin_id, $action, $description, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * Check rate limit for IP
 */
function checkRateLimit($conn, $ip, $action, $limit = 5, $minutes = 15) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as attempts FROM rate_limits 
        WHERE ip_address = ? AND action = ? 
        AND first_attempt > DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->bind_param("ssi", $ip, $action, $minutes);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result['attempts'] < $limit;
}

/**
 * Record rate limit attempt
 */
function recordRateLimit($conn, $ip, $action) {
    // Check if record exists
    $stmt = $conn->prepare("
        SELECT id, attempts FROM rate_limits 
        WHERE ip_address = ? AND action = ? 
        AND first_attempt > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->bind_param("ss", $ip, $action);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $update = $conn->prepare("UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?");
        $update->bind_param("i", $row['id']);
        $update->execute();
        $update->close();
    } else {
        $insert = $conn->prepare("INSERT INTO rate_limits (ip_address, action) VALUES (?, ?)");
        $insert->bind_param("ss", $ip, $action);
        $insert->execute();
        $insert->close();
    }
    $stmt->close();
}
?>