<?php
/**
 * config/security.php - Security Manager Class
 * Handles password hashing, rate limiting, account lockout, etc.
 */

class SecurityManager {
    private $conn;
    private static $tableMap = [
        'admins'   => ['table' => 'admins',   'pk' => 'admin_id'],
        'teachers' => ['table' => 'teachers', 'pk' => 'teacher_id'],
        'students' => ['table' => 'students', 'pk' => 'student_id'],
    ];

    public function __construct($conn) {
        $this->conn = $conn;
    }

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

    public function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    public function isAccountLocked($user_type, $user_id) {
        $meta = $this->getMeta($user_type);
        $stmt = $this->conn->prepare(
            "SELECT locked_until FROM `{$meta['table']}` WHERE `{$meta['pk']}` = ? LIMIT 1"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
            return true;
        }
        return false;
    }

    public function getLockedUntil($user_type, $user_id) {
        $meta = $this->getMeta($user_type);
        $stmt = $this->conn->prepare(
            "SELECT locked_until FROM `{$meta['table']}` WHERE `{$meta['pk']}` = ? LIMIT 1"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row['locked_until'] ?? null;
    }

    public function getLockoutMinutes($user_type, $user_id) {
        $until = $this->getLockedUntil($user_type, $user_id);
        if (!$until) return 0;
        
        $remaining = strtotime($until) - time();
        return max(0, ceil($remaining / 60));
    }

    public function recordFailedAttempt($user_type, $user_id) {
        $meta = $this->getMeta($user_type);
        
        $stmt = $this->conn->prepare(
            "UPDATE `{$meta['table']}` SET failed_login_attempts = failed_login_attempts + 1 WHERE `{$meta['pk']}` = ?"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->conn->prepare(
            "SELECT failed_login_attempts FROM `{$meta['table']}` WHERE `{$meta['pk']}` = ?"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $attempts = (int)($row['failed_login_attempts'] ?? 0);

        if ($attempts >= 5) {
            $locked_until = date('Y-m-d H:i:s', time() + 1800);
            $stmt = $this->conn->prepare(
                "UPDATE `{$meta['table']}` SET locked_until = ? WHERE `{$meta['pk']}` = ?"
            );
            $stmt->bind_param('si', $locked_until, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        return $attempts;
    }

    public function resetFailedAttempts($user_type, $user_id) {
        $meta = $this->getMeta($user_type);
        $stmt = $this->conn->prepare(
            "UPDATE `{$meta['table']}` SET failed_login_attempts = 0, locked_until = NULL WHERE `{$meta['pk']}` = ?"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Record rate limit attempt
     */
    public function recordRateLimit($ip_address, $action = 'login') {
        // Check if record exists within time frame
        $check = $this->conn->prepare("
            SELECT id, attempts FROM rate_limits 
            WHERE ip_address = ? AND action = ? 
            AND first_attempt > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $check->bind_param("ss", $ip_address, $action);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $update = $this->conn->prepare("UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?");
            $update->bind_param("i", $row['id']);
            $update->execute();
            $update->close();
        } else {
            $insert = $this->conn->prepare("INSERT INTO rate_limits (ip_address, action) VALUES (?, ?)");
            $insert->bind_param("ss", $ip_address, $action);
            $insert->execute();
            $insert->close();
        }
        $check->close();
    }

    /**
     * Check rate limit
     */
    public function checkRateLimit($ip_address, $action = 'login') {
        $stmt = $this->conn->prepare(
            "SELECT attempts FROM rate_limits 
             WHERE ip_address = ? AND action = ? 
             AND first_attempt > DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1"
        );
        $stmt->bind_param('ss', $ip_address, $action);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && $row['attempts'] >= 5) {
            return false;
        }
        return true;
    }

    /**
     * Generate verification code and token
     */
    public function generateVerificationCode($user_type, $user_id, $email) {
        try {
            if (!in_array($user_type, ['admins', 'teachers', 'students'])) {
                throw new Exception("Invalid user type: $user_type");
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }
            
            $this->conn->begin_transaction();
            
            // Delete old tokens
            $cleanup = $this->conn->prepare(
                "DELETE FROM password_reset_tokens 
                 WHERE user_type = ? AND user_id = ?"
            );
            $cleanup->bind_param('si', $user_type, $user_id);
            $cleanup->execute();
            $cleanup->close();
            
            // Generate values
            $code = sprintf("%06d", random_int(0, 999999));
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Insert new token
            $sql = "INSERT INTO password_reset_tokens 
                    (user_type, user_id, email, token, verification_code, expires_at, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('sissss', $user_type, $user_id, $email, $token, $code, $expires_at);
            $stmt->execute();
            
            $this->conn->commit();
            $stmt->close();
            
            return ['code' => $code, 'token' => $token, 'expires_at' => $expires_at];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("generateVerificationCode error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify reset code - YOUR EXISTING METHOD (KEEP AS IS)
     */
    public function verifyResetCode($email, $code, $user_type) {
        $code = trim(preg_replace('/[^0-9]/', '', $code));
        
        if (strlen($code) !== 6) {
            return ['valid' => false, 'message' => 'Invalid code format'];
        }
        
        $stmt = $this->conn->prepare(
            "SELECT * FROM password_reset_tokens 
             WHERE email = ? AND user_type = ? AND verification_code = ? 
             AND is_used = 0 AND expires_at > NOW() 
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('sss', $email, $user_type, $code);
        $stmt->execute();
        $token_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$token_data) {
            return ['valid' => false, 'message' => 'Invalid or expired code'];
        }

        if ($token_data['code_attempts'] >= 5) {
            return ['valid' => false, 'message' => 'Too many attempts. Request new code'];
        }

        // Increment attempts
        $update = $this->conn->prepare(
            "UPDATE password_reset_tokens SET code_attempts = code_attempts + 1 WHERE id = ?"
        );
        $update->bind_param('i', $token_data['id']);
        $update->execute();
        $update->close();

        return [
            'valid' => true,
            'token' => $token_data['token'],
            'user_id' => (int)$token_data['user_id'],
            'user_type' => $token_data['user_type']
        ];
    }

    /**
     * Verify reset token (for magic links)
     */
    public function verifyResetToken($token) {
        // Get database time
        $result = $this->conn->query("SELECT NOW() as db_now");
        $db_time = $result->fetch_assoc()['db_now'];
        
        $stmt = $this->conn->prepare(
            "SELECT * FROM password_reset_tokens 
             WHERE token = ? AND is_used = 0 AND expires_at > ? 
             LIMIT 1"
        );
        $stmt->bind_param('ss', $token, $db_time);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        return $data;
    }

    /**
     * Invalidate token after use
     */
    public function invalidateToken($token) {
        $stmt = $this->conn->prepare(
            "UPDATE password_reset_tokens SET is_used = 1 WHERE token = ?"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update password with history
     */
    public function updatePassword($user_type, $user_id, $new_password) {
        try {
            $meta = self::$tableMap[$user_type] ?? null;
            if (!$meta) {
                throw new Exception("Invalid user type");
            }
            
            $hash = $this->hashPassword($new_password);
            $now = date('Y-m-d H:i:s');
            
            // Get current password and history
            $stmt = $this->conn->prepare(
                "SELECT password, password_history FROM `{$meta['table']}` 
                 WHERE `{$meta['pk']}` = ? AND deleted = 0"
            );
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            // Update password history
            $history = [];
            if ($user && $user['password']) {
                $history[] = $user['password'];
            }
            if ($user && $user['password_history']) {
                $old_history = json_decode($user['password_history'], true) ?? [];
                $history = array_merge($history, $old_history);
            }
            
            // Keep last 5 passwords
            $history = array_slice($history, 0, 4);
            $history_json = json_encode($history);
            
            // Update user
            $update = $this->conn->prepare(
                "UPDATE `{$meta['table']}` 
                 SET password = ?, password_history = ?, 
                     password_changed_at = ?, failed_login_attempts = 0,
                     locked_until = NULL
                 WHERE `{$meta['pk']}` = ?"
            );
            $update->bind_param('sssi', $hash, $history_json, $now, $user_id);
            $result = $update->execute();
            $update->close();
            
            return $result;
            
        } catch (Exception $e) {
            error_log("updatePassword error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check password history
     */
    public function checkPasswordHistory($user_type, $user_id, $new_password) {
        $meta = $this->getMeta($user_type);
        $stmt = $this->conn->prepare(
            "SELECT password_history FROM `{$meta['table']}` WHERE `{$meta['pk']}` = ?"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && $row['password_history']) {
            $history = json_decode($row['password_history'], true) ?? [];
            foreach ($history as $oldHash) {
                if (password_verify($new_password, $oldHash)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Log login attempt
     */
    public function logLogin($user_type, $user_id, $email, $status, $ip_address) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $this->conn->prepare(
            "INSERT INTO login_audit (user_type, user_id, email, login_status, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('sissss', $user_type, $user_id, $email, $status, $ip_address, $ua);
        $stmt->execute();
        $stmt->close();
    }

    private function getMeta($user_type) {
        return self::$tableMap[$user_type] ?? null;
    }
}
?>