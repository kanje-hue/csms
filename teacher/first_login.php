<?php
/**
 * teacher/first_login.php - Force Password Change on First Login
 * Teachers redirected here when force_password_change = 1
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';  // Add this line

$security = new SecurityManager($conn);

// Check if teacher is in temp session
if (!isset($_SESSION['temp_teacher_id']) || !isset($_SESSION['temp_teacher_email'])) {
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['temp_teacher_id'];
$email = $_SESSION['temp_teacher_email'];
$name = $_SESSION['temp_teacher_name'] ?? 'Teacher';
$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if ($password !== $confirm) {
        $error = "Passwords do not match!";
    } else {
        // Validate password strength
        $strength = $security->validatePasswordStrength($password);
        if (!$strength['valid']) {
            $error = $strength['message'];
        } else {
            // Check password history
            if ($security->checkPasswordHistory('teachers', $teacher_id, $password)) {
                $error = "You cannot reuse one of your last 5 passwords!";
            } else {
                // Update password and remove force flag
                if ($security->updatePassword('teachers', $teacher_id, $password)) {
                    // Clear temp session and set permanent session
                    unset($_SESSION['temp_teacher_id'], $_SESSION['temp_teacher_email'], $_SESSION['temp_teacher_name']);
                    
                    $_SESSION['teacher_logged_in'] = true;
                    $_SESSION['teacher_id'] = $teacher_id;
                    $_SESSION['teacher_name'] = $name;
                    $_SESSION['last_activity'] = time();
                    
                    $success = "Password changed successfully! Redirecting...";
                    header("refresh:2;url=dashboard.php");
                } else {
                    $error = "Failed to update password!";
                }
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
    <title>First Login - Change Password</title>
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
            max-width: 500px;
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

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .info-box {
            background: #e0f2fe;
            border-left: 4px solid #2dd4bf;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 25px;
        }

        .info-box p {
            margin-bottom: 5px;
            color: #0f172a;
        }

        .info-box strong {
            color: #2dd4bf;
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

        .hint {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 6px;
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
            margin-top: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(45, 212, 191, 0.4);
        }

        .password-requirements {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
            margin: 20px 0;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 0.85rem;
            margin: 5px 0;
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
                <p>First Time Login - Change Your Password</p>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= $error ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert success"><?= $success ?></div>
            <?php endif; ?>

            <div class="info-box">
                <p><strong>Welcome, <?= htmlspecialchars($name) ?>!</strong></p>
                <p>This is your first login. Please set a new password to continue.</p>
                <p style="margin-top: 8px;">📧 <?= htmlspecialchars($email) ?></p>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required placeholder="Enter new password">
                    <div class="hint">Must be at least 8 characters</div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required placeholder="Re-enter password">
                </div>

                <div class="password-requirements">
                    <div class="requirement">✓ At least 8 characters</div>
                    <div class="requirement">✓ One uppercase letter (A-Z)</div>
                    <div class="requirement">✓ One lowercase letter (a-z)</div>
                    <div class="requirement">✓ One number (0-9)</div>
                    <div class="requirement">✓ One special character (!@#$%^&*)</div>
                </div>

                <button type="submit" class="btn">Change Password & Continue</button>
            </form>

            <div class="footer">
                <a href="logout.php">← Cancel and Logout</a>
            </div>
        </div>
    </div>
</body>
</html>