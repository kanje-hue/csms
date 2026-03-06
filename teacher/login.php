<?php
/**
 * teacher/login.php - Teacher Login with Force Password Change
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/security_base.php';

$security = new SecurityManager($conn);
$error = '';

// Rate limiting
$ip = $_SERVER['REMOTE_ADDR'];
if (!$security->checkRateLimit($ip, 'teacher_login')) {
    die('<div style="color: red; text-align: center; padding: 50px;">Too many login attempts. Please try again later.</div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif (empty($password)) {
        $error = "Password is required";
    } else {
        // Get teacher from database
        $stmt = $conn->prepare("SELECT teacher_id, fullname, email, password, force_password_change, status FROM teachers WHERE email = ? AND deleted = 0");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $teacher = $result->fetch_assoc();
            
            // Check if account is locked
            if ($security->isAccountLocked('teachers', $teacher['teacher_id'])) {
                $mins = $security->getLockoutMinutes('teachers', $teacher['teacher_id']);
                $error = "🔒 Account locked. Try again in $mins minutes.";
            }
            // Verify password
            elseif (password_verify($password, $teacher['password'])) {
                // Check if password needs rehash
                if (password_needs_rehash($teacher['password'], PASSWORD_DEFAULT)) {
                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                    $update = $conn->prepare("UPDATE teachers SET password = ? WHERE teacher_id = ?");
                    $update->bind_param("si", $new_hash, $teacher['teacher_id']);
                    $update->execute();
                    $update->close();
                }
                
                // Reset failed attempts
                $security->resetFailedAttempts('teachers', $teacher['teacher_id']);
                
                // Check if teacher needs to change password on first login
                if ($teacher['force_password_change'] == 1) {
                    $_SESSION['temp_teacher_id'] = $teacher['teacher_id'];
                    $_SESSION['temp_teacher_email'] = $email;
                    $_SESSION['temp_teacher_name'] = $teacher['fullname'];
                    header("Location: first_login.php");
                    exit();
                }
                
                // Check if teacher is active
                if ($teacher['status'] !== 'active') {
                    $error = "Your account is not active. Please contact admin.";
                    $security->recordRateLimit($ip, 'teacher_login');
                } else {
                    // Successful login
                    session_regenerate_id(true);
                    $_SESSION['teacher_logged_in'] = true;
                    $_SESSION['teacher_id'] = $teacher['teacher_id'];
                    $_SESSION['teacher_name'] = $teacher['fullname'];
                    $_SESSION['last_activity'] = time();
                    
                    // Log login
                    $security->logLogin('teachers', $teacher['teacher_id'], $email, 'success', $ip);
                    
                    header("Location: dashboard.php");
                    exit();
                }
            } else {
                // Failed login
                $security->recordFailedAttempt('teachers', $teacher['teacher_id']);
                $security->recordRateLimit($ip, 'teacher_login');
                $error = "❌ Invalid email or password";
            }
        } else {
            $error = "❌ Invalid email or password";
            $security->recordRateLimit($ip, 'teacher_login');
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
    <title>Teacher Login - CSMS</title>
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

        .login-container {
            width: 100%;
            max-width: 450px;
        }

        .login-card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            font-size: 2rem;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .login-header h1 span {
            color: #2dd4bf;
        }

        .login-header p {
            color: #64748b;
            font-size: 0.95rem;
        }

        .alert {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
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

        .password-group {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 45px;
            background: none;
            border: none;
            color: #2dd4bf;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .checkbox-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 0.9rem;
        }

        .checkbox-group a {
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .checkbox-group a:hover {
            text-decoration: underline;
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

        .login-footer {
            text-align: center;
            margin-top: 25px;
        }

        .login-footer a {
            color: #64748b;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .login-footer a:hover {
            color: #2dd4bf;
        }

        @media (max-width: 768px) {
            .login-card {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>CSMS <span>Teacher</span></h1>
                <p>Sign in to access your teaching dashboard</p>
            </div>

            <?php if ($error): ?>
                <div class="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="teacher@college.edu" required>
                </div>

                <div class="form-group password-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
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

            <div class="login-footer">
                <a href="../public/">← Back to Unified Login</a>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const password = document.getElementById('password');
            const toggle = event.target;
            if (password.type === 'password') {
                password.type = 'text';
                toggle.textContent = 'Hide';
            } else {
                password.type = 'password';
                toggle.textContent = 'Show';
            }
        }
    </script>
</body>
</html>