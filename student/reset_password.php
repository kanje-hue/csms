<?php
/**
 * student/reset_password.php - Reset Password with Token
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';

$security = new SecurityManager($conn);
$message = "";
$message_type = "";

$token = $_GET['token'] ?? '';

if (empty($token)) {
    header("Location: forgot_password.php");
    exit();
}

// Verify token
$stmt = $conn->prepare("
    SELECT * FROM password_reset_tokens 
    WHERE token = ? AND user_type = 'students' AND is_used = 0 AND expires_at > NOW()
");
$stmt->bind_param("s", $token);
$stmt->execute();
$token_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$token_data) {
    die("Invalid or expired token");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if ($password !== $confirm) {
        $message = "Passwords do not match";
        $message_type = "error";
    } else {
        $strength = $security->validatePasswordStrength($password);
        if (!$strength['valid']) {
            $message = $strength['message'];
            $message_type = "error";
        } else {
            // Update password
            if ($security->updatePassword('students', $token_data['user_id'], $password)) {
                // Mark token as used
                $update = $conn->prepare("UPDATE password_reset_tokens SET is_used = 1 WHERE id = ?");
                $update->bind_param("i", $token_data['id']);
                $update->execute();
                $update->close();
                
                $message = "✓ Password reset successfully! You can now login.";
                $message_type = "success";
            } else {
                $message = "Error resetting password";
                $message_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - CSMS Student</title>
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
        .hint { font-size: 12px; color: #666; margin-top: 5px; }
        .auth-links { text-align: center; margin-top: 20px; }
        a { color: #16a085; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Set New Password</h1>
        <p style="margin-bottom: 20px;">Enter your new password below</p>

        <?php if ($message): ?>
            <div class="alert <?= $message_type ?>"><?= $message ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="password" required>
                <div class="hint">8+ chars, uppercase, number, special</div>
            </div>

            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required>
            </div>

            <button type="submit" name="reset" class="btn">Reset Password</button>
        </form>

        <div class="auth-links">
            <a href="login.php">← Back to Login</a>
        </div>
    </div>
</body>
</html>