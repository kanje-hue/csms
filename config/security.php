<?php
/**
 * SecurityManager - Handles password management, login attempt tracking,
 * account lockout, password reset tokens, and login audit logging.
 */
class SecurityManager {
    private $conn;

    // Security constants
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_DURATION   = 1800; // 30 minutes in seconds
    const PASSWORD_HISTORY   = 5;    // Last 5 passwords
    const RESET_TOKEN_EXPIRY = 3600; // 1 hour

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Validate password strength.
     * Requirements: 8+ chars, uppercase, lowercase, digit, special char.
     */
    public function validatePasswordStrength($password) {
        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters long.'];
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter.'];
        }
        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter.'];
        }
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one number.'];
        }
        if (!preg_match('/[\W_]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one special character.'];
        }
        return ['valid' => true, 'message' => 'Password is strong.'];
    }

    /**
     * Hash a password using bcrypt.
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Verify a password against a hash.
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    /**
     * Generate a secure random password.
     */
    public function generateSecurePassword($length = 12) {
        $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower   = 'abcdefghijklmnopqrstuvwxyz';
        $digits  = '0123456789';
        $special = '!@#$%^&*()_+-=[]{}';
        $all     = $upper . $lower . $digits . $special;

        $password  = $upper[random_int(0, strlen($upper) - 1)];
        $password .= $lower[random_int(0, strlen($lower) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Check if account is currently locked out.
     *
     * @param string $table  admins | teachers | students
     * @param int    $userId
     * @return bool
     */
    public function isAccountLocked($table, $userId) {
        $allowedTables = ['admins', 'teachers', 'students'];
        if (!in_array($table, $allowedTables)) {
            return false;
        }

        $idCol = $this->getIdColumn($table);
        $stmt  = $this->conn->prepare(
            "SELECT locked_until FROM `$table` WHERE `$idCol` = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
            return true;
        }
        return false;
    }

    /**
     * Record a failed login attempt; lock account after MAX_LOGIN_ATTEMPTS.
     *
     * @param string $table
     * @param int    $userId
     */
    public function recordFailedLogin($table, $userId) {
        $allowedTables = ['admins', 'teachers', 'students'];
        if (!in_array($table, $allowedTables)) {
            return;
        }

        $idCol = $this->getIdColumn($table);

        // Increment counter
        $stmt = $this->conn->prepare(
            "UPDATE `$table` SET failed_login_attempts = failed_login_attempts + 1 WHERE `$idCol` = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        // Check if we hit the threshold
        $stmt = $this->conn->prepare(
            "SELECT failed_login_attempts FROM `$table` WHERE `$idCol` = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && $row['failed_login_attempts'] >= self::MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_DURATION);
            $stmt = $this->conn->prepare(
                "UPDATE `$table` SET locked_until = ? WHERE `$idCol` = ?"
            );
            $stmt->bind_param("si", $lockedUntil, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Reset failed login counter and lockout after successful login.
     *
     * @param string $table
     * @param int    $userId
     */
    public function resetLoginAttempts($table, $userId) {
        $allowedTables = ['admins', 'teachers', 'students'];
        if (!in_array($table, $allowedTables)) {
            return;
        }

        $idCol = $this->getIdColumn($table);
        $stmt  = $this->conn->prepare(
            "UPDATE `$table` SET failed_login_attempts = 0, locked_until = NULL WHERE `$idCol` = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Generate a password reset token and store it in the database.
     * FIXED: Now correctly stores table name in user_type, not user_id
     *
     * @param string $table
     * @param int    $userId
     * @param string $email
     * @return string|false  The verification code (6-digit) or false on failure
     */
    public function generatePasswordResetToken($table, $userId, $email) {
        $allowedTables = ['admins', 'teachers', 'students'];
        if (!in_array($table, $allowedTables)) {
            return false;
        }

        $token      = bin2hex(random_bytes(32));
        $code       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Invalidate previous tokens for this user/table
        $del = $this->conn->prepare(
            "DELETE FROM password_reset_tokens WHERE user_type = ? AND user_id = ?"
        );
        $del->bind_param("si", $table, $userId);
        $del->execute();
        $del->close();

        // FIX: Insert with correct parameter types and order
        $ins = $this->conn->prepare(
            "INSERT INTO password_reset_tokens (user_type, user_id, email, token, verification_code, created_at, expires_at, is_used)
             VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), 0)"
        );
        
        // IMPORTANT: bind_param order must match VALUES order
        // user_type (s=string), user_id (i=integer), email (s=string), token (s=string), code (s=string)
        $ins->bind_param("sisis", $table, $userId, $email, $token, $code);
        
        if ($ins->execute()) {
            $ins->close();
            return $code;
        }
        $ins->close();
        return false;
    }

    /**
     * Verify a password reset code.
     * FIXED: Now returns correct user_type (table name) for session storage
     *
     * @param string $email
     * @param string $code
     * @return array|false  Row from password_reset_tokens or false
     */
    public function verifyResetCode($email, $code) {
        $stmt = $this->conn->prepare(
            "SELECT id, user_type, user_id, email, token, verification_code, expires_at, is_used 
             FROM password_reset_tokens
             WHERE email = ? AND verification_code = ? AND expires_at > NOW() AND is_used = 0"
        );
        $stmt->bind_param("ss", $email, $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $row ?: false;
    }

    /**
     * Mark a reset token as used.
     *
     * @param int $tokenId
     */
    public function markTokenUsed($tokenId) {
        $stmt = $this->conn->prepare(
            "UPDATE password_reset_tokens SET is_used = 1 WHERE id = ?"
        );
        $stmt->bind_param("i", $tokenId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Check whether a new password was used recently (last PASSWORD_HISTORY passwords).
     *
     * @param string $table
     * @param int    $userId
     * @param string $newPassword  Plain-text new password
     * @return bool  true if reused
     */
    public function isPasswordReused($table, $userId, $newPassword) {
        $allowedTables = ['admins', 'teachers', 'students'];
        if (!in_array($table, $allowedTables)) {
            return false;
        }

        $idCol = $this->getIdColumn($table);
        $stmt  = $this->conn->prepare(
            "SELECT password_history FROM `$table` WHERE `$idCol` = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && $row['password_history']) {
            $history = json_decode($row['password_history'], true) ?? [];
            foreach ($history as $oldHash) {
                if (password_verify($newPassword, $oldHash)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Update a user's password and maintain the history list.
     *
     * @param string $table
     * @param int    $userId
     * @param string $newPassword  Plain-text new password
     * @param bool   $forceChange  Whether to clear the force_password_change flag
     */
    public function updatePassword($table, $userId, $newPassword, $forceChange = false) {
        $allowedTables = ['admins', 'teachers', 'students'];
        if (!in_array($table, $allowedTables)) {
            return false;
        }

        $idCol   = $this->getIdColumn($table);
        $newHash = $this->hashPassword($newPassword);

        // Fetch current password + history
        $stmt = $this->conn->prepare(
            "SELECT password, password_history FROM `$table` WHERE `$idCol` = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $history = [];
        if ($row) {
            if ($row['password']) {
                $history[] = $row['password'];
            }
            $oldHistory = json_decode($row['password_history'] ?? '[]', true) ?? [];
            $history    = array_merge($history, $oldHistory);
        }

        // Keep only last (PASSWORD_HISTORY - 1) so the current becomes history[0]
        $history = array_slice($history, 0, self::PASSWORD_HISTORY - 1);

        $historyJson   = json_encode($history);
        $now           = date('Y-m-d H:i:s');

        if ($forceChange) {
            $stmt = $this->conn->prepare(
                "UPDATE `$table`
                 SET password = ?, password_history = ?, password_changed_at = ?,
                     force_password_change = 0
                 WHERE `$idCol` = ?"
            );
            $stmt->bind_param("sssi", $newHash, $historyJson, $now, $userId);
        } else {
            $stmt = $this->conn->prepare(
                "UPDATE `$table`
                 SET password = ?, password_history = ?, password_changed_at = ?
                 WHERE `$idCol` = ?"
            );
            $stmt->bind_param("sssi", $newHash, $historyJson, $now, $userId);
        }

        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Write an entry to the login_audit table.
     *
     * @param string      $userType
     * @param int|null    $userId
     * @param string      $email
     * @param string      $status    'success' | 'failed' | 'locked'
     * @param string|null $ipAddress
     */
    public function logLoginAttempt($userType, $userId, $email, $status, $ipAddress = null) {
        $ip   = $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt = $this->conn->prepare(
            "INSERT INTO login_audit (user_type, user_id, email, login_status, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sissss", $userType, $userId, $email, $status, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Return the primary-key column name for a given table.
     */
    private function getIdColumn($table) {
        $map = [
            'admins'   => 'admin_id',
            'teachers' => 'teacher_id',
            'students' => 'student_id',
        ];
        return $map[$table] ?? 'id';
    }
}
?>