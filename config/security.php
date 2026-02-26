<?php
/**
 * SecurityManager - Centralized security and password management
 * 
 * Handles: password strength validation, bcrypt hashing, login attempt
 * tracking, account lockout, 6-digit email verification codes, password
 * reset tokens, password history (last 5), and login audit logging.
 */
class SecurityManager {
    private $conn;

    // Map user_type strings to table names and primary key columns
    private static $tableMap = [
        'admins'   => ['table' => 'admins',   'pk' => 'admin_id'],
        'teachers' => ['table' => 'teachers', 'pk' => 'teacher_id'],
        'students' => ['table' => 'students', 'pk' => 'student_id'],
    ];

    public function __construct($conn) {
        $this->conn = $conn;
    }

    // =========================================================
    // 1. PASSWORD STRENGTH VALIDATION
    // =========================================================

    /**
     * Validate password strength.
     * Requirements: 8+ chars, uppercase, lowercase, digit, special char.
     *
     * @param string $password
     * @return array ['valid' => bool, 'message' => string]
     */
    public function validatePasswordStrength($password) {
        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters'];
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter'];
        }
        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter'];
        }
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one number'];
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one special character'];
        }
        return ['valid' => true, 'message' => 'Password is strong'];
    }

    // =========================================================
    // 2. PASSWORD HASHING
    // =========================================================

    /**
     * Hash a password using bcrypt.
     *
     * @param string $password
     * @return string
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Verify a password against its bcrypt hash.
     *
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    // =========================================================
    // 3. LOGIN ATTEMPT TRACKING & ACCOUNT LOCKOUT
    // =========================================================

    /**
     * Check if an account is currently locked.
     *
     * @param string $user_type  One of: admins, teachers, students
     * @param int    $user_id
     * @return bool
     */
    public function isAccountLocked($user_type, $user_id) {
        $meta = $this->getMeta($user_type);
        $stmt = $this->conn->prepare(
            "SELECT locked_until FROM `{$meta['table']}` WHERE `{$meta['pk']}` = ? LIMIT 1"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && !empty($row['locked_until']) && strtotime($row['locked_until']) > time();
    }

    /**
     * Get remaining lockout duration in minutes.
     *
     * @param string $user_type
     * @param int    $user_id
     * @return int  Minutes remaining (0 if not locked)
     */
    public function getLockoutMinutes($user_type, $user_id) {
        $meta = $this->getMeta($user_type);
        $stmt = $this->conn->prepare(
            "SELECT locked_until FROM `{$meta['table']}` WHERE `{$meta['pk']}` = ? LIMIT 1"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && !empty($row['locked_until']) && strtotime($row['locked_until']) > time()) {
            return (int) ceil((strtotime($row['locked_until']) - time()) / 60);
        }
        return 0;
    }

    /**
     * Record a failed login attempt. Locks account after 5 failures (30 min).
     *
     * @param string $user_type
     * @param int    $user_id
     * @return int  Total failed attempts so far
     */
    public function recordFailedAttempt($user_type, $user_id) {
        $meta = $this->getMeta($user_type);

        $stmt = $this->conn->prepare(
            "SELECT failed_login_attempts FROM `{$meta['table']}` WHERE `{$meta['pk']}` = ? LIMIT 1"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row   = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $attempts = (int)($row['failed_login_attempts'] ?? 0) + 1;

        if ($attempts >= 5) {
            $lock_until = date('Y-m-d H:i:s', time() + 1800);
            $upd = $this->conn->prepare(
                "UPDATE `{$meta['table']}` SET failed_login_attempts = ?, locked_until = ? WHERE `{$meta['pk']}` = ?"
            );
            $upd->bind_param('isi', $attempts, $lock_until, $user_id);
        } else {
            $upd = $this->conn->prepare(
                "UPDATE `{$meta['table']}` SET failed_login_attempts = ? WHERE `{$meta['pk']}` = ?"
            );
            $upd->bind_param('ii', $attempts, $user_id);
        }
        $upd->execute();
        $upd->close();

        error_log("[CSMS Security] Failed login attempt #$attempts for $user_type ID $user_id");
        return $attempts;
    }

    /**
     * Reset failed login attempts and clear lockout after successful login.
     *
     * @param string $user_type
     * @param int    $user_id
     */
    public function resetFailedAttempts($user_type, $user_id) {
        $meta = $this->getMeta($user_type);
        $stmt = $this->conn->prepare(
            "UPDATE `{$meta['table']}` SET failed_login_attempts = 0, locked_until = NULL WHERE `{$meta['pk']}` = ?"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
    }

    // =========================================================
    // 4. EMAIL VERIFICATION CODE (for password reset)
    // =========================================================

    /**
     * Generate a 6-digit verification code and persist it to
     * password_reset_tokens. Expires in 1 hour.
     *
     * @param string $user_type  admins | teachers | students
     * @param int    $user_id
     * @param string $email
     * @return array ['code' => string, 'token' => string]
     */
    public function generateVerificationCode($user_type, $user_id, $email) {
        // Remove any existing unused tokens for this user
        $del = $this->conn->prepare(
            "DELETE FROM password_reset_tokens WHERE user_type = ? AND user_id = ? AND is_used = 0"
        );
        $del->bind_param('si', $user_type, $user_id);
        $del->execute();
        $del->close();

        $code       = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token      = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + 3600);

        $ins = $this->conn->prepare(
            "INSERT INTO password_reset_tokens
             (user_type, user_id, email, token, verification_code, code_attempts, created_at, expires_at, is_used)
             VALUES (?, ?, ?, ?, ?, 0, NOW(), ?, 0)"
        );
        $ins->bind_param('sissss', $user_type, $user_id, $email, $token, $code, $expires_at);
        $ins->execute();
        $ins->close();

        error_log("[CSMS Security] Verification code generated for $user_type ID $user_id email=$email");
        return ['code' => $code, 'token' => $token];
    }

    /**
     * Verify a 6-digit code submitted by the user.
     *
     * @param string $email
     * @param string $code
     * @param string $user_type
     * @return array ['valid' => bool, 'message' => string, 'token' => string|null, ...]
     */
    public function verifyCode($email, $code, $user_type) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM password_reset_tokens
             WHERE email = ? AND user_type = ? AND is_used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('ss', $email, $user_type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['valid' => false, 'message' => 'No valid reset request found. Please request a new code.'];
        }

        if ((int)$row['code_attempts'] >= 5) {
            return ['valid' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
        }

        // Increment code_attempts before comparing
        $id  = (int)$row['id'];
        $upd = $this->conn->prepare(
            "UPDATE password_reset_tokens SET code_attempts = code_attempts + 1 WHERE id = ?"
        );
        $upd->bind_param('i', $id);
        $upd->execute();
        $upd->close();

        if ($row['verification_code'] !== $code) {
            return ['valid' => false, 'message' => 'Invalid verification code.'];
        }

        return [
            'valid'     => true,
            'token'     => $row['token'],
            'user_id'   => (int)$row['user_id'],
            'user_type' => $row['user_type'],
        ];
    }

    // =========================================================
    // 5. PASSWORD RESET TOKEN
    // =========================================================

    /**
     * Verify a reset token (after the 6-digit code has been confirmed).
     *
     * @param string $token
     * @return array|null  Row from password_reset_tokens, or null if invalid/expired
     */
    public function verifyResetToken($token) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM password_reset_tokens
             WHERE token = ? AND is_used = 0 AND expires_at > NOW() LIMIT 1"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Mark a reset token as used/consumed.
     *
     * @param string $token
     */
    public function invalidateToken($token) {
        $stmt = $this->conn->prepare(
            "UPDATE password_reset_tokens SET is_used = 1 WHERE token = ?"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
    }

    // =========================================================
    // 6. PASSWORD HISTORY
    // =========================================================

    /**
     * Check whether $new_password matches any of the last 5 stored hashes.
     *
     * @param string $user_type
     * @param int    $user_id
     * @param string $new_password  Plain-text password
     * @return bool  true if the password was recently used
     */
    public function checkPasswordHistory($user_type, $user_id, $new_password) {
        $meta = $this->getMeta($user_type);
        $stmt = $this->conn->prepare(
            "SELECT password_history FROM `{$meta['table']}` WHERE `{$meta['pk']}` = ? LIMIT 1"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['password_history'])) {
            return false;
        }

        $history = json_decode($row['password_history'], true) ?? [];
        foreach ($history as $old_hash) {
            if (password_verify($new_password, $old_hash)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Update a user's password and prepend the old hash to the history
     * (keeping the last 5 entries).
     *
     * @param string $user_type
     * @param int    $user_id
     * @param string $new_hash  Already-hashed new password
     */
    public function updatePasswordHistory($user_type, $user_id, $new_hash) {
        $meta = $this->getMeta($user_type);

        $stmt = $this->conn->prepare(
            "SELECT password, password_history FROM `{$meta['table']}` WHERE `{$meta['pk']}` = ? LIMIT 1"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $history = json_decode($row['password_history'] ?? '[]', true) ?? [];

        // Push the current password hash into history
        if (!empty($row['password'])) {
            array_unshift($history, $row['password']);
        }
        $history      = array_slice($history, 0, 5);
        $history_json = json_encode($history);

        $upd = $this->conn->prepare(
            "UPDATE `{$meta['table']}`
             SET password = ?, password_history = ?, password_changed_at = NOW()
             WHERE `{$meta['pk']}` = ?"
        );
        $upd->bind_param('ssi', $new_hash, $history_json, $user_id);
        $upd->execute();
        $upd->close();

        error_log("[CSMS Security] Password updated for $user_type ID $user_id");
    }

    // =========================================================
    // 7. LOGIN AUDIT LOGGING
    // =========================================================

    /**
     * Log a login event to error.log (and optionally to activity_logs table).
     *
     * @param string $user_type
     * @param int    $user_id
     * @param string $email
     * @param string $action   e.g. 'login_success', 'login_failure', 'password_reset'
     * @param string|null $ip
     */
    public function logLogin($user_type, $user_id, $email, $action, $ip = null) {
        $ip      = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $details = json_encode(['email' => $email, 'ip' => $ip, 'user_agent' => $ua]);

        error_log("[CSMS Security] [$action] user_type=$user_type user_id=$user_id email=$email ip=$ip");

        // Attempt to persist to activity_logs if the table exists (silently skip if not)
        $stmt = $this->conn->prepare(
            "INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $user_id, $action, $details);
            $stmt->execute();
            $stmt->close();
        }
    }

    // =========================================================
    // HELPERS
    // =========================================================

    /**
     * Return table + primary-key info for a given user_type string.
     *
     * @param string $user_type
     * @return array ['table' => string, 'pk' => string]
     * @throws InvalidArgumentException
     */
    private function getMeta($user_type) {
        if (!isset(self::$tableMap[$user_type])) {
            throw new InvalidArgumentException("Invalid user_type: $user_type");
        }
        return self::$tableMap[$user_type];
    }
}
?>
