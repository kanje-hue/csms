<?php
/**
 * student/login.php - Student Login
 * Features: Rate limiting, account lockout, session management
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/security_base.php';

$security = new SecurityManager($conn);
$error = '';

// Rate limiting
$ip = $_SERVER['REMOTE_ADDR'];
if (!$security->checkRateLimit($ip, 'student_login')) {
    die('<div style="color: red; text-align: center; padding: 50px;">Too many login attempts. Please try again later.</div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    if (!$email) {
        $error = "Valid email required";
    } elseif (empty($password)) {
        $error = "Password required";
    } else {
        // Get student from database
        $stmt = $conn->prepare("SELECT student_id, name, email, password, course_id, year, status FROM students WHERE email = ? AND deleted = 0");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            
            // Check if account is locked
            if ($security->isAccountLocked('students', $student['student_id'])) {
                $mins = $security->getLockoutMinutes('students', $student['student_id']);
                $error = "🔒 Account locked. Try again in $mins minutes.";
            }
            // Verify password
            elseif (password_verify($password, $student['password'])) {
                // Reset failed attempts
                $security->resetFailedAttempts('students', $student['student_id']);
                
                // Check if account is active
                if ($student['status'] !== 'active') {
                    $error = "Your account is pending admin approval.";
                    $security->recordRateLimit($ip, 'student_login');
                } else {
                    // Successful login
                    session_regenerate_id(true);
                    $_SESSION['student_logged_in'] = true;
                    $_SESSION['student_id'] = $student['student_id'];
                    $_SESSION['student_name'] = $student['name'];
                    $_SESSION['course_id'] = $student['course_id'];
                    $_SESSION['year'] = $student['year'];
                    $_SESSION['last_activity'] = time();
                    
                    // Log login
                    $security->logLogin('students', $student['student_id'], $email, 'success', $ip);
                    
                    header("Location: dashboard.php");
                    exit();
                }
            } else {
                // Failed login
                $security->recordFailedAttempt('students', $student['student_id']);
                $security->recordRateLimit($ip, 'student_login');
                $error = "❌ Invalid email or password";
            }
        } else {
            $error = "❌ Invalid email or password";
            $security->recordRateLimit($ip, 'student_login');
        }
        $stmt->close();
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - CSMS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
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
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #16a085, #117a65);
            padding: 30px;
            text-align: center;
            color: white;
        }
        .card-header h1 { font-size: 28px; margin-bottom: 5px; }
        .card-header p { font-size: 14px; opacity: 0.9; }
        .card-body { padding: 30px; }
        .alert {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ef4444;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #374151;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #16a085;
            box-shadow: 0 0 0 3px rgba(22,160,133,0.1);
        }
        .password-group { position: relative; }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 40px;
            background: none;
            border: none;
            color: #16a085;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
        }
        .checkbox-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .checkbox-group a {
            color: #16a085;
            text-decoration: none;
            font-weight: 600;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #16a085, #117a65);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(22,160,133,0.3);
        }
        .auth-links {
            text-align: center;
            margin-top: 20px;
        }
        .auth-links a {
            color: #16a085;
            text-decoration: none;
            font-weight: 600;
            margin: 0 5px;
        }
        .auth-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <h1>CSMS Student</h1>
            <p>Sign in to access your dashboard</p>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="your@email.com" required>
                </div>

                <div class="form-group password-group">
                    <label>Password</label>
                    <input type="password" name="password" id="password" placeholder="••••••••" required>
                    <button type="button" class="password-toggle" onclick="togglePassword()">Show</button>
                </div>

                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="remember"> Remember me
                    </label>
                    <a href="forgot_password.php">Forgot password?</a>
                </div>

                <button type="submit" class="btn">Sign In</button>
            </form>

            <div class="auth-links">
                <p>Don't have an account? <a href="register.php">Register here</a></p>
                <p><a href="../public/">← Back to Unified Login</a></p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const password = document.getElementById('password');
            if (password.type === 'password') {
                password.type = 'text';
                event.target.textContent = 'Hide';
            } else {
                password.type = 'password';
                event.target.textContent = 'Show';
            }
        }
    </script>
</body>
</html>