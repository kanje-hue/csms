<?php

class SecurityManager {
    private $conn;
    private $max_login_attempts = 5;
    private $lockout_duration = 1800; // 30 minutes in seconds
    private $reset_token_expiry = 3600; // 1 hour
    
    public function __construct($database) {
        $this->conn = $database;
    }
    
    // ===== PASSWORD FUNCTIONS =====
    
    /**
     * Generate secure random password
     */
    public function generateSecurePassword($length = 12) {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $symbols = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        
        $allChars = $uppercase . $lowercase . $numbers . $symbols;
        $password = '';
        
        // Ensure at least one of each type
        $password .= $uppercase[rand(0, strlen($uppercase) - 1)];
        $password .= $lowercase[rand(0, strlen($lowercase) - 1)];
        $password .= $numbers[rand(0, strlen($numbers) - 1)];
        $password .= $symbols[rand(0, strlen($symbols) - 1)];
        
        // Fill rest
        for($i = strlen($password); $i < $length; $i++) {
            $password .= $allChars[rand(0, strlen($allChars) - 1)];
        }
        
        return str_shuffle($password);
    }
    
    /**
     * Validate password strength
     */
    public function validatePasswordStrength($password) {
        $errors = [];
        
        if(strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters";
        }
        if(!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain uppercase letters";
        }
        if(!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain lowercase letters";
        }
        if(!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain numbers";
        }
        if(!preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/', $password)) {
            $errors[] = "Password must contain special characters";
        }
        
        return $errors;
    }
    
    /**
     * Hash password using bcrypt
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    /**
     * Verify password
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Get password history
     */
    public function getPasswordHistory($user_id, $user_type) {
        $stmt = $this->conn->prepare("SELECT password_history FROM " . $user_type . "s WHERE " . $user_type . "_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $result ? json_decode($result['password_history'] ?? '[]', true) : [];
    }
    
    /**
     * Check if password was used before
     */
    public function isPasswordReused($user_id, $user_type, $new_password) {
        $history = $this->getPasswordHistory($user_id, $user_type);
        
        foreach($history as $old_hash) {
            if($this->verifyPassword($new_password, $old_hash)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Add password to history
     */
    public function addToPasswordHistory($user_id, $user_type, $password_hash) {
        $history = $this->getPasswordHistory($user_id, $user_type);
        
        // Keep only last 5 passwords
        if(count($history) >= 5) {
            array_shift($history);
        }
        
        $history[] = $password_hash;
        $history_json = json_encode($history);
        
        $stmt = $this->conn->prepare("UPDATE " . $user_type . "s SET password_history = ? WHERE " . $user_type . "_id = ?");
        $stmt->bind_param("si", $history_json, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // ===== LOGIN ATTEMPT TRACKING =====
    
    /**
     * Record login attempt
     */
    public function recordLoginAttempt($user_type, $email, $status, $reason = '') {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $stmt = $this->conn->prepare("INSERT INTO login_audit (user_type, email, ip_address, login_status, reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $user_type, $email, $ip_address, $status, $reason);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Increment failed login attempts
     */
    public function incrementFailedAttempts($user_id, $user_type) {
        $table = $user_type . 's';
        $id_field = $user_type . '_id';
        
        $stmt = $this->conn->prepare("UPDATE $table SET failed_login_attempts = failed_login_attempts + 1 WHERE $id_field = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        
        // Check if should lock account
        $this->checkAndLockAccount($user_id, $user_type);
    }
    
    /**
     * Check if account should be locked
     */
    public function checkAndLockAccount($user_id, $user_type) {
        $table = $user_type . 's';
        $id_field = $user_type . '_id';
        
        $stmt = $this->conn->prepare("SELECT failed_login_attempts FROM $table WHERE $id_field = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if($result && $result['failed_login_attempts'] >= $this->max_login_attempts) {
            $locked_until = date('Y-m-d H:i:s', time() + $this->lockout_duration);
            
            $update = $this->conn->prepare("UPDATE $table SET locked_until = ? WHERE $id_field = ?");
            $update->bind_param("si", $locked_until, $user_id);
            $update->execute();
            $update->close();
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if account is locked
     */
    public function isAccountLocked($user_id, $user_type) {
        $table = $user_type . 's';
        $id_field = $user_type . '_id';
        
        $stmt = $this->conn->prepare("SELECT locked_until FROM $table WHERE $id_field = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if($result && $result['locked_until']) {
            $locked_time = strtotime($result['locked_until']);
            
            if(time() < $locked_time) {
                return true;
            } else {
                // Unlock account
                $this->unlockAccount($user_id, $user_type);
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Unlock account and reset attempts
     */
    public function unlockAccount($user_id, $user_type) {
        $table = $user_type . 's';
        $id_field = $user_type . '_id';
        
        $stmt = $this->conn->prepare("UPDATE $table SET failed_login_attempts = 0, locked_until = NULL WHERE $id_field = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Reset login attempts on successful login
     */
    public function resetLoginAttempts($user_id, $user_type) {
        $table = $user_type . 's';
        $id_field = $user_type . '_id';
        
        $stmt = $this->conn->prepare("UPDATE $table SET failed_login_attempts = 0, locked_until = NULL WHERE $id_field = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // ===== PASSWORD RESET =====
    
    /**
     * Generate password reset token and code
     */
    public function generatePasswordResetToken($user_id, $user_type, $email) {
        // Delete old tokens
        $delete = $this->conn->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND user_type = ? AND expires_at < NOW()");
        $delete->bind_param("is", $user_id, $user_type);
        $delete->execute();
        $delete->close();
        
        // Generate token and code
        $token = bin2hex(random_bytes(32));
        $verification_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', time() + $this->reset_token_expiry);
        
        $stmt = $this->conn->prepare("INSERT INTO password_reset_tokens (user_type, user_id, email, token, verification_code, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sissss", $user_type, $user_id, $email, $token, $verification_code, $expires_at);
        $stmt->execute();
        $stmt->close();
        
        return [
            'token' => $token,
            'code' => $verification_code
        ];
    }
    
    /**
     * Verify reset code
     */
    public function verifyResetCode($token, $code) {
        $stmt = $this->conn->prepare("SELECT * FROM password_reset_tokens WHERE token = ? AND expires_at > NOW() AND is_used = 0");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if(!$result) {
            return ['valid' => false, 'message' => 'Token expired or invalid'];
        }
        
        if($result['code_attempts'] >= 3) {
            return ['valid' => false, 'message' => 'Too many attempts. Please request a new reset code.'];
        }
        
        if($result['verification_code'] !== $code) {
            // Increment attempts
            $update = $this->conn->prepare("UPDATE password_reset_tokens SET code_attempts = code_attempts + 1 WHERE id = ?");
            $update->bind_param("i", $result['id']);
            $update->execute();
            $update->close();
            
            return ['valid' => false, 'message' => 'Invalid verification code'];
        }
        
        return [
            'valid' => true,
            'user_id' => $result['user_id'],
            'user_type' => $result['user_type'],
            'email' => $result['email']
        ];
    }
    
    /**
     * Mark token as used
     */
    public function markTokenAsUsed($token) {
        $stmt = $this->conn->prepare("UPDATE password_reset_tokens SET is_used = 1 WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $stmt->close();
    }
    
    // ===== EMAIL NOTIFICATIONS =====
    
    /**
     * Send account created email
     */
    public function sendAccountCreatedEmail($email, $fullname, $temp_password, $user_type) {
        $subject = "CSMS Account Created - Please Set Your Password";
        $login_url = "http://localhost/csms/index.php";
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                <h2 style='color: #16a085;'>Welcome to CSMS! 👋</h2>
                
                <p>Dear <strong>$fullname</strong>,</p>
                
                <p>Your $user_type account has been created by the administrator. Please use the credentials below to login and set up your own password.</p>
                
                <div style='background: #f8fafb; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                    <p><strong>Email:</strong> $email</p>
                    <p><strong>Temporary Password:</strong> <code style='background: #e8e8e8; padding: 5px 10px; border-radius: 3px; font-family: monospace;'>$temp_password</code></p>
                    <p><strong>Login URL:</strong> <a href='$login_url' style='color: #16a085; text-decoration: none;'>$login_url</a></p>
                </div>
                
                <h3 style='color: #16a085;'>⚠️ Important Security Notes:</h3>
                <ul>
                    <li>✅ You MUST change your password on first login</li>
                    <li>🔒 Do not share this email with anyone</li>
                    <li>⏰ Temporary password is valid for this login only</li>
                    <li>📧 If you didn't request this, contact your administrator immediately</li>
                </ul>
                
                <p style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #999; font-size: 12px;'>
                    This is an automated email. Please do not reply to this email.
                </p>
                
                <p style='color: #999; font-size: 12px;'>
                    Best regards,<br><strong>CSMS Administration</strong>
                </p>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($email, $subject, $message);
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail($email, $fullname, $verification_code) {
        $subject = "CSMS Password Reset Request";
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                <h2 style='color: #16a085;'>Password Reset Request 🔐</h2>
                
                <p>Dear <strong>$fullname</strong>,</p>
                
                <p>We received a password reset request for your CSMS account. Use the verification code below to reset your password.</p>
                
                <div style='background: #f8fafb; padding: 20px; border-radius: 6px; margin: 20px 0; text-align: center;'>
                    <p style='margin: 0; font-size: 12px; color: #666;'>Your Verification Code</p>
                    <p style='margin: 10px 0 0 0; font-size: 32px; font-weight: bold; letter-spacing: 2px; color: #16a085; font-family: monospace;'>$verification_code</p>
                </div>
                
                <h3 style='color: #16a085;'>⚠️ Important:</h3>
                <ul>
                    <li>This code expires in 1 hour</li>
                    <li>Do not share this code with anyone</li>
                    <li>If you didn't request this, ignore this email</li>
                </ul>
                
                <p style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #999; font-size: 12px;'>
                    This is an automated email. Please do not reply to this email.
                </p>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($email, $subject, $message);
    }
    
    /**
     * Send account locked email
     */
    public function sendAccountLockedEmail($email, $fullname) {
        $subject = "CSMS Account Locked - Security Alert";
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ffc107; border-radius: 8px;'>
                <h2 style='color: #c0392b;'>🔒 Account Locked</h2>
                
                <p>Dear <strong>$fullname</strong>,</p>
                
                <p>Your CSMS account has been locked due to multiple failed login attempts. This is a security measure to protect your account.</p>
                
                <div style='background: #fff3cd; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                    <strong>Your account will be automatically unlocked in 30 minutes.</strong>
                </div>
                
                <h3>What to do:</h3>
                <ul>
                    <li>Wait 30 minutes before trying to login again</li>
                    <li>Use your correct password</li>
                    <li>If you forgot your password, use the password reset feature</li>
                </ul>
                
                <p style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #999; font-size: 12px;'>
                    If you believe this is a security concern, please contact your system administrator.
                </p>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($email, $subject, $message);
    }
    
    /**
     * Generic send email function
     */
    public function sendEmail($to, $subject, $message) {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: noreply@csms.local\r\n";
        
        return mail($to, $subject, $message, $headers);
    }
}

?>