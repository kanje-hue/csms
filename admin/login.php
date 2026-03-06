<?php
/**
 * admin/login.php - Secure Admin Login
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/security_base.php';

$security = new SecurityManager($conn);
$error = '';

// Rate limiting
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Check rate limit - FIXED: Check if function exists
if (function_exists('checkRateLimit')) {
    if (!checkRateLimit($conn, $ip, 'admin_login', 5, 15)) {
        die('<div style="color: red; text-align: center; padding: 50px; font-family: Arial;">🔒 Too many login attempts. Please try again later.</div>');
    }
} else {
    // Fallback to SecurityManager method
    if (!$security->checkRateLimit($ip, 'admin_login')) {
        die('<div style="color: red; text-align: center; padding: 50px; font-family: Arial;">🔒 Too many login attempts. Please try again later.</div>');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection - FIXED: Check if token exists
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        $error = "Security token verification failed";
    } else {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format";
        } elseif (empty($password)) {
            $error = "Password is required";
        } else {
            // Get admin from database
            $stmt = $conn->prepare("SELECT admin_id, name, email, password FROM admins WHERE email = ? AND deleted = 0");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $admin = $result->fetch_assoc();
                    
                    // Verify password
                    if (password_verify($password, $admin['password'])) {
                        // Check if password needs rehash
                        if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
                            $new_hash = password_hash($password, PASSWORD_DEFAULT);
                            $update = $conn->prepare("UPDATE admins SET password = ? WHERE admin_id = ?");
                            if ($update) {
                                $update->bind_param("si", $new_hash, $admin['admin_id']);
                                $update->execute();
                                $update->close();
                            }
                        }
                        
                        // Successful login
                        session_regenerate_id(true);
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id'] = $admin['admin_id'];
                        $_SESSION['admin_name'] = $admin['name'];
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $_SESSION['last_activity'] = time();
                        
                        // Clear rate limits
                        if (function_exists('clearRateLimit')) {
                            clearRateLimit($conn, $ip, 'admin_login');
                        }
                        
                        header("Location: dashboard.php");
                        exit();
                    } else {
                        // Failed login - record attempt
                        if (function_exists('recordRateLimit')) {
                            recordRateLimit($conn, $ip, 'admin_login');
                        } else {
                            $security->recordRateLimit($ip, 'admin_login');
                        }
                        $error = "❌ Invalid email or password";
                    }
                } else {
                    // No user found
                    if (function_exists('recordRateLimit')) {
                        recordRateLimit($conn, $ip, 'admin_login');
                    } else {
                        $security->recordRateLimit($ip, 'admin_login');
                    }
                    $error = "❌ Invalid email or password";
                }
                $stmt->close();
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
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
    <title>Admin Login - CSMS</title>
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
                <h1>CSMS <span>Admin</span></h1>
                <p>Sign in to manage your system</p>
            </div>

            <?php if ($error): ?>
                <div class="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="admin@csms.com" placeholder="admin@csms.com" required>
                </div>

                <div class="form-group password-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword()">Show</button>
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