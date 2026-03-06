<?php
/**
 * teacher/forgot_password.php - Request Password Reset
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
        // Check if teacher exists
        $stmt = $conn->prepare("SELECT teacher_id, fullname FROM teachers WHERE email = ? AND deleted = 0");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $teacher = $result->fetch_assoc();
            
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store token in database
            $insert = $conn->prepare("
                INSERT INTO password_reset_tokens (user_type, user_id, email, token, expires_at, created_at) 
                VALUES ('teachers', ?, ?, ?, ?, NOW())
            ");
            $insert->bind_param("isss", $teacher['teacher_id'], $email, $token, $expires);
            $insert->execute();
            $insert->close();
            
            // Send email
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/csms/teacher/reset_password.php?token=" . $token;
            $email_sent = send_password_reset_email($email, $teacher['fullname'], $reset_link);
            
            if ($email_sent) {
                $message = "✓ Password reset link has been sent to your email.";
                $message_type = "success";
            } else {
                $message = "⚠️ Could not send email. Please contact administrator.";
                $message_type = "warning";
            }
        } else {
            // Always show same message for security
            $message = "If your email exists in our system, you will receive a password reset link.";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CSMS Teacher</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 450px;
        }

        .card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2rem;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .header h1 span {
            color: #2dd4bf;
        }

        .header p {
            color: #64748b;
            font-size: 0.95rem;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert.warning {
            background: #fef3c7;
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #0f172a;
            font-size: 0.9rem;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #2dd4bf;
            box-shadow: 0 0 0 4px rgba(45, 212, 191, 0.1);
        }

        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(45, 212, 191, 0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .footer {
            text-align: center;
            margin-top: 20px;
        }

        .footer a {
            color: #64748b;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .footer a:hover {
            color: #2dd4bf;
        }

        .info-text {
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            margin: 20px 0;
            font-size: 0.9rem;
            color: #475569;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>CSMS <span>Teacher</span></h1>
                <p>Reset your password</p>
            </div>

            <?php if ($message): ?>
                <div class="alert <?= $message_type ?>"><?= $message ?></div>
            <?php endif; ?>

            <div class="info-text">
                Enter your email address and we'll send you a link to reset your password.
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="teacher@college.edu" required autofocus>
                </div>

                <button type="submit" name="submit" class="btn">Send Reset Link</button>
                <a href="login.php" class="btn btn-secondary">Back to Login</a>
            </form>

            <div class="footer">
                <a href="../public/">← Back to Unified Login</a>
            </div>
        </div>
    </div>
</body>
</html>