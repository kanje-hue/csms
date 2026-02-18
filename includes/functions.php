<?php
/**
 * ====================================================
 * CSMS Helper Functions File
 * ====================================================
 * This file contains reusable functions used across
 * the entire application for security, validation,
 * and common operations.
 */

/**
 * 1. INPUT VALIDATION & SANITIZATION
 * ====================================================
 */

/**
 * Validate and sanitize text input
 * Removes whitespace, prevents XSS attacks
 * 
 * @param string $data - The input data to sanitize
 * @return string - Cleaned data
 * 
 * Usage: $username = validate_input($_POST['username']);
 */
function validate_input($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate integer values
 * Makes sure the value is a valid integer
 * 
 * @param mixed $data - The value to validate
 * @return int|false - Integer or false if invalid
 * 
 * Usage: $user_id = validate_integer($_GET['user_id']);
 */
function validate_integer($data) {
    return filter_var($data, FILTER_VALIDATE_INT);
}

/**
 * Validate email format
 * 
 * @param string $email - Email to validate
 * @return string|false - Email or false if invalid
 * 
 * Usage: $email = validate_email($_POST['email']);
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate password strength
 * Requirements:
 * - Minimum 8 characters
 * - At least 1 uppercase letter
 * - At least 1 number
 * 
 * @param string $password - Password to check
 * @return array - ['valid' => bool, 'message' => string]
 * 
 * Usage: $result = validate_password($_POST['password']);
 *        if ($result['valid']) { ... }
 */
function validate_password($password) {
    if (strlen($password) < 8) {
        return [
            'valid' => false,
            'message' => 'Password must be at least 8 characters'
        ];
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        return [
            'valid' => false,
            'message' => 'Password must contain at least 1 uppercase letter'
        ];
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        return [
            'valid' => false,
            'message' => 'Password must contain at least 1 number'
        ];
    }
    
    return [
        'valid' => true,
        'message' => 'Password is strong'
    ];
}

/**
 * 2. CSRF PROTECTION
 * ====================================================
 */

/**
 * Generate CSRF token for forms
 * Must be called once per page that has forms
 * 
 * @return string - CSRF token
 * 
 * Usage: $token = generate_csrf_token();
 *        <input type="hidden" name="csrf_token" value="<?= $token ?>">
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from form submission
 * Always call this before processing form data
 * 
 * @param string $token - Token from $_POST or $_REQUEST
 * @return bool - true if valid, false if invalid
 * 
 * Usage: if (!verify_csrf_token($_POST['csrf_token'])) {
 *            die('CSRF token verification failed');
 *        }
 */
function verify_csrf_token($token) {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * 3. AUTHENTICATION CHECKS
 * ====================================================
 */

/**
 * Check if current user is admin
 * 
 * @return bool - true if admin, false otherwise
 * 
 * Usage: if (is_admin()) { ... }
 */
function is_admin() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Check if current user is teacher
 * 
 * @return bool - true if teacher, false otherwise
 * 
 * Usage: if (is_teacher()) { ... }
 */
function is_teacher() {
    return isset($_SESSION['teacher_logged_in']) && $_SESSION['teacher_logged_in'] === true;
}

/**
 * Check if current user is student
 * 
 * @return bool - true if student, false otherwise
 * 
 * Usage: if (is_student()) { ... }
 */
function is_student() {
    return isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'] === true;
}

/**
 * Check if user is logged in
 * 
 * @return bool - true if any user is logged in
 * 
 * Usage: if (is_logged_in()) { ... }
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * 4. AUTHORIZATION CHECKS
 * ====================================================
 */

/**
 * Require user to be logged in, redirect if not
 * Use this at the top of pages that need authentication
 * 
 * @param string|array $required_role - Role(s) required ('admin', 'teacher', 'student')
 * @return void - Redirects if not authenticated
 * 
 * Usage: require_login('admin');
 *        require_login(['admin', 'teacher']);
 */
function require_login($required_role = null) {
    if (!is_logged_in()) {
        header("Location: ../login.php");
        exit();
    }
    
    if ($required_role) {
        $roles = is_array($required_role) ? $required_role : [$required_role];
        $has_role = false;
        
        foreach ($roles as $role) {
            if (call_user_func("is_$role")) {
                $has_role = true;
                break;
            }
        }
        
        if (!$has_role) {
            die("Access denied. You don't have permission to access this page.");
        }
    }
}

/**
 * 5. LOGGING & AUDIT
 * ====================================================
 */

/**
 * Log user activity to database
 * Tracks what actions users perform
 * 
 * @param object $conn - Database connection
 * @param int $user_id - User performing the action
 * @param string $action - Action performed (e.g., 'create_course')
 * @param string $details - Additional details
 * @return void
 * 
 * Usage: log_activity($conn, 1, 'create_course', 'Created CS101 course');
 */
function log_activity($conn, $user_id, $action, $details = '') {
    // Make sure activity_logs table exists first!
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (user_id, action, details, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    
    if ($stmt) {
        $stmt->bind_param("iss", $user_id, $action, $details);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * 6. ERROR MESSAGES
 * ====================================================
 */

/**
 * Get user-friendly error messages
 * 
 * @param string $error_code - Error code identifier
 * @return string - User-friendly error message
 * 
 * Usage: $message = get_error_message('invalid_email');
 */
function get_error_message($error_code) {
    $errors = [
        'invalid_email' => 'Please enter a valid email address',
        'weak_password' => 'Password must be at least 8 characters with uppercase and numbers',
        'email_exists' => 'This email is already registered',
        'invalid_credentials' => 'Invalid email or password',
        'access_denied' => 'You do not have permission to access this resource',
        'not_found' => 'The requested resource was not found',
        'server_error' => 'An error occurred. Please try again later',
    ];
    
    return $errors[$error_code] ?? 'An error occurred';
}

/**
 * 7. PASSWORD HASHING
 * ====================================================
 */

/**
 * Hash a password securely
 * Use this when storing passwords in database
 * 
 * @param string $password - Plain text password
 * @return string - Hashed password
 * 
 * Usage: $hashed = hash_password('mypassword123');
 *        // Store $hashed in database
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify a password against its hash
 * Use this when checking login credentials
 * 
 * @param string $password - Plain text password to verify
 * @param string $hash - The hash from database
 * @return bool - true if password matches
 * 
 * Usage: if (verify_password($submitted_password, $db_hash)) {
 *            // Password is correct
 *        }
 */
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * 8. UTILITY FUNCTIONS
 * ====================================================
 */

/**
 * Redirect to a page
 * 
 * @param string $url - URL to redirect to
 * @param int $code - HTTP status code (301, 302, etc)
 * @return void
 * 
 * Usage: redirect_to('dashboard.php');
 */
function redirect_to($url, $code = 302) {
    header("Location: $url", true, $code);
    exit();
}

/**
 * Format date for display
 * 
 * @param string $date - Date string from database
 * @param string $format - PHP date format
 * @return string - Formatted date
 * 
 * Usage: echo format_date($created_at, 'M d, Y');
 *        // Output: Jan 15, 2024
 */
function format_date($date, $format = 'M d, Y') {
    return date($format, strtotime($date));
}

/**
 * Get current logged-in user ID
 * 
 * @return int|null - User ID or null if not logged in
 * 
 * Usage: $user_id = get_user_id();
 */
function get_user_id() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current logged-in user role
 * 
 * @return string|null - 'admin', 'teacher', 'student', or null
 * 
 * Usage: $role = get_user_role();
 */
function get_user_role() {
    if (is_admin()) return 'admin';
    if (is_teacher()) return 'teacher';
    if (is_student()) return 'student';
    return null;
}

/**
 * 9. DATABASE HELPERS
 * ====================================================
 */

/**
 * Safely escape string for SQL (legacy - use prepared statements instead!)
 * NOTE: Always prefer prepared statements over this function
 * 
 * @param object $conn - Database connection
 * @param string $string - String to escape
 * @return string - Escaped string
 * 
 * Usage: $escaped = safe_sql_string($conn, $_POST['name']);
 *        // But better to use prepared statements!
 */
function safe_sql_string($conn, $string) {
    return mysqli_real_escape_string($conn, $string);
}

/**
 * Get total count of records
 * 
 * @param object $conn - Database connection
 * @param string $table - Table name
 * @param string $where - WHERE clause (optional)
 * @return int - Total count
 * 
 * Usage: $count = get_count($conn, 'students', "course_id = 1 AND deleted = 0");
 */
function get_count($conn, $table, $where = '') {
    $query = "SELECT COUNT(*) as total FROM $table";
    if ($where) {
        $query .= " WHERE $where";
    }
    $result = $conn->query($query);
    return $result->fetch_assoc()['total'];
}

?>