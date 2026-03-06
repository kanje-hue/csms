<?php
/**
 * teacher/reset_password.php - Reset Password with Token
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
    WHERE token = ? AND user_type = 'teachers' AND is_used = 0 AND expires_at > NOW()
");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$token_data = $result->fetch_assoc();
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
            if ($security->updatePassword('teachers', $token_data['user_id'], $password)) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CSMS Teacher</title>
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
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(45, 212, 191, 0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
            margin-top: 10px;
        }

        .footer {
            text-align: center;
            margin-top: 25px;
        }

        .footer a {
            color: #64748b;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .footer a:hover {
            color: #2dd4bf;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>CSMS <span>Teacher</span></h1>
                <p>Enter your new password</p>
            </div>

            <?php if ($message): ?>
                <div class="alert <?= $message_type ?>"><?= $message ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required placeholder="Enter new password">
                </div>

                <div class="form-group">
                    <label for="confirm">Confirm Password</label>
                    <input type="password" id="confirm" name="confirm_password" required placeholder="Confirm new password">
                </div>

                <button type="submit" name="reset" class="btn">Reset Password</button>
                <a href="login.php" class="btn btn-secondary">Back to Login</a>
            </form>
        </div>
    </div>
</body>
</html>