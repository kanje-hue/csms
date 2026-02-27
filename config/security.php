<?php
/**
 * config/security.php
 * Improved SecurityManager with database time fix
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
     * Generate verification code using database time
     */
    public function generateVerificationCode($user_type, $user_id, $email) {
        try {
            // Validate inputs
            if (!in_array($user_type, ['admins', 'teachers', 'students'])) {
                throw new Exception("Invalid user type: $user_type");
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }
            
            // Start transaction
            $this->conn->begin_transaction();
            
            // Delete old unused tokens for this user
            $cleanup = $this->conn->prepare(
                "DELETE FROM password_reset_tokens 
                 WHERE user_type = ? AND user_id = ?"
            );
            $cleanup->bind_param('si', $user_type, $user_id);
            $cleanup->execute();
            $cleanup->close();
            
            // Generate secure values
            $code = sprintf("%06d", random_int(0, 999999));
            $token = bin2hex(random_bytes(32));
            
            // Get database current time
            $time_result = $this->conn->query("SELECT NOW() as db_now")->fetch_assoc();
            $db_now = $time_result['db_now'];
            
            // Calculate expiry using database time
            $expires_at = date('Y-m-d H:i:s', strtotime($db_now . ' +1 hour'));
            
            // Log time info for debugging
            error_log("🕐 Time Info - PHP: " . date('Y-m-d H:i:s') . ", DB: $db_now");
            error_log("   Token expires at: $expires_at");
            
            // Insert new token
            $sql = "INSERT INTO password_reset_tokens 
                    (user_type, user_id, email, token, verification_code, created_at, expires_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param('sisssss', 
                $user_type, $user_id, $email, $token, $code, $db_now, $expires_at
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $this->conn->commit();
            $stmt->close();
            
            error_log("✅ Password reset code generated for $email: $code");
            
            return [
                'code' => $code,
                'token' => $token,
                'expires_at' => $expires_at
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("❌ generateVerificationCode error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify code using database time
     */
    public function verifyCode($email, $code, $user_type) {
        try {
            // Clean inputs
            $email = trim($email);
            $code = trim(preg_replace('/[^0-9]/', '', $code));
            $user_type = trim($user_type);
            
            error_log("🔍 Verifying code for: $email");
            
            if (strlen($code) !== 6) {
                return ['valid' => false, 'message' => 'Code must be 6 digits'];
            }
            
            // Get current database time for comparison
            $db_time = $this->conn->query("SELECT NOW() as db_now")->fetch_assoc()['db_now'];
            error_log("   Current DB time: $db_time");
            
            // Get the latest valid token using database time
            $sql = "SELECT * FROM password_reset_tokens 
                    WHERE email = ? AND user_type = ? 
                    AND is_used = 0 AND expires_at > ? 
                    ORDER BY id DESC LIMIT 1";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('sss', $email, $user_type, $db_time);
            $stmt->execute();
            $result = $stmt->get_result();
            $token_data = $result->fetch_assoc();
            $stmt->close();
            
            if (!$token_data) {
                error_log("❌ No valid token found for $email");
                return ['valid' => false, 'message' => 'No valid reset request found. Please request a new code.'];
            }
            
            error_log("✅ Token found: ID={$token_data['id']}, stored_code={$token_data['verification_code']}");
            
            // Check attempts
            if ($token_data['code_attempts'] >= 5) {
                return ['valid' => false, 'message' => 'Too many failed attempts. Please request a new code.'];
            }
            
            // Increment attempts
            $update = $this->conn->prepare(
                "UPDATE password_reset_tokens SET code_attempts = code_attempts + 1 WHERE id = ?"
            );
            $update->bind_param('i', $token_data['id']);
            $update->execute();
            $update->close();
            
            // Verify code
            if (trim($token_data['verification_code']) !== trim($code)) {
                error_log("❌ Code mismatch");
                return ['valid' => false, 'message' => 'Invalid verification code.'];
            }
            
            error_log("✅ Code verified successfully");
            
            return [
                'valid' => true,
                'token' => $token_data['token'],
                'user_id' => (int)$token_data['user_id'],
                'user_type' => $token_data['user_type']
            ];
            
        } catch (Exception $e) {
            error_log("❌ verifyCode error: " . $e->getMessage());
            return ['valid' => false, 'message' => 'An error occurred. Please try again.'];
        }
    }

    public function verifyResetToken($token) {
        // Get database time
        $db_time = $this->conn->query("SELECT NOW() as db_now")->fetch_assoc()['db_now'];
        
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

    public function invalidateToken($token) {
        $stmt = $this->conn->prepare(
            "UPDATE password_reset_tokens SET is_used = 1 WHERE token = ?"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
    }

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

    public function logLogin($user_type, $user_id, $email, $status) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $this->conn->prepare(
            "INSERT INTO login_audit (user_type, user_id, email, login_status, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('sissss', $user_type, $user_id, $email, $status, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    }

    private function getMeta($user_type) {
        return self::$tableMap[$user_type] ?? null;
    }
}
?>