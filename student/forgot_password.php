<?php
/**
 * student/forgot_password.php - Password Reset Request
 */

session_start();
require_once '../config/db.php';
require_once '../config/email_config.php';
require_once '../config/security_base.php';

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    
    if (!$email) {
        $message = "Please enter a valid email address";
        $message_type = "error";
    } else {
        // Check if student exists
        $stmt = $conn->prepare("SELECT student_id, name FROM students WHERE email = ? AND deleted = 0");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store token in database
            $insert = $conn->prepare("
                INSERT INTO password_reset_tokens (user_type, user_id, email, token, expires_at) 
                VALUES ('students', ?, ?, ?, ?)
            ");
            $insert->bind_param("isss", $student['student_id'], $email, $token, $expires);
            $insert->execute();
            $insert->close();
            
            // Send email
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/csms/student/reset_password.php?token=" . $token;
            send_password_reset_email($email, $student['name'], $reset_link);
            
            $message = "✓ Password reset link sent to your email.";
            $message_type = "success";
        } else {
            $message = "If your email exists, you will receive a reset link.";
            $message_type = "success";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - CSMS Student</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: white;
            width: 100%;
            max-width: 450px;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        }
        h1 { color: #16a085; margin-bottom: 10px; }
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert.success { background: #d1fae5; color: #065f46; }
        .alert.error { background: #fee2e2; color: #991b1b; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 5px; }
        input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
        }
        input:focus {
            outline: none;
            border-color: #16a085;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: #16a085;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn:hover { background: #117a65; }
        .auth-links { text-align: center; margin-top: 20px; }
        a { color: #16a085; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Reset Password</h1>
        <p style="margin-bottom: 20px;">Enter your email to receive a reset link</p>

        <?php if ($message): ?>
            <div class="alert <?= $message_type ?>"><?= $message ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required>
            </div>

            <button type="submit" name="submit" class="btn">Send Reset Link</button>
        </form>

        <div class="auth-links">
            <a href="login.php">← Back to Login</a>
        </div>
    </div>
</body>
</html>