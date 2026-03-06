<?php
/**
 * public/index.php - Unified Login & Registration with Working Role Selection
 * Styled with CSMSPro logo and teal color scheme
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.cookie_lifetime', 0);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/email_config.php';

$security = new SecurityManager($conn);

// Sanitization helper
function h($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Get current view
$view = $_GET['view'] ?? $_POST['view'] ?? 'login';
$role = $_GET['role'] ?? $_POST['role'] ?? '';

// Handle magic link reset
if (isset($_GET['view']) && $_GET['view'] === 'reset-by-token' && isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Verify token
    $token_data = $security->verifyResetToken($token);
    
    if ($token_data) {
        $_SESSION['reset_token'] = $token_data['token'];
        $_SESSION['reset_user_id'] = $token_data['user_id'];
        $_SESSION['reset_user_type'] = $token_data['user_type'];
        $_SESSION['reset_verified'] = true;
        $view = 'reset-password';
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid or expired reset link'];
        $view = 'forgot';
    }
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $action = $_POST['action'] ?? '';
    
    // ============= LOGIN =============
    if ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? '');
        
        $roleMap = [
            'student' => [
                'table' => 'students',
                'pk' => 'student_id',
                'name_col' => 'name',
                'redirect' => '../student/dashboard.php',
                'sess_prefix' => 'student'
            ],
            'teacher' => [
                'table' => 'teachers',
                'pk' => 'teacher_id',
                'name_col' => 'fullname',
                'redirect' => '../teacher/dashboard.php',
                'sess_prefix' => 'teacher'
            ],
            'admin' => [
                'table' => 'admins',
                'pk' => 'admin_id',
                'name_col' => 'name',
                'redirect' => '../admin/dashboard.php',
                'sess_prefix' => 'admin'
            ]
        ];
        
        if (!isset($roleMap[$role])) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please select a valid role'];
        } elseif (empty($email) || empty($password)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Email and password required'];
        } else {
            $meta = $roleMap[$role];
            $user_type = $role . 's';
            
            $stmt = $conn->prepare("SELECT * FROM {$meta['table']} WHERE email = ? AND deleted = 0 LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$user) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid email or password'];
            } elseif ($security->isAccountLocked($user_type, $user[$meta['pk']])) {
                $mins = $security->getLockoutMinutes($user_type, $user[$meta['pk']]);
                $_SESSION['flash'] = ['type' => 'error', 'message' => "Account locked. Try again in $mins minutes"];
            } elseif (!$security->verifyPassword($password, $user['password'])) {
                $attempts = $security->recordFailedAttempt($user_type, $user[$meta['pk']]);
                $remaining = 5 - $attempts;
                $_SESSION['flash'] = ['type' => 'error', 'message' => "Invalid password. $remaining attempts remaining"];
            } else {
                if ($role === 'teacher' && !empty($user['force_password_change'])) {
                    $_SESSION['temp_teacher_id'] = $user[$meta['pk']];
                    $_SESSION['temp_teacher_email'] = $email;
                    $_SESSION['temp_teacher_name'] = $user[$meta['name_col']];
                    header('Location: ../teacher/first_login.php');
                    exit;
                }
                
                if ($role === 'student' && $user['status'] !== 'active') {
                    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Your account is pending approval'];
                    header('Location: ?view=login');
                    exit;
                }
                
                $security->resetFailedAttempts($user_type, $user[$meta['pk']]);
                
                $_SESSION["{$meta['sess_prefix']}_logged_in"] = true;
                $_SESSION["{$meta['sess_prefix']}_id"] = $user[$meta['pk']];
                $_SESSION["{$meta['sess_prefix']}_name"] = $user[$meta['name_col']];
                $_SESSION['last_activity'] = time();
                
                header('Location: ' . $meta['redirect']);
                exit;
            }
        }
        
        header('Location: ?view=login');
        exit;
    }
    
    // ============= STUDENT REGISTRATION =============
    elseif ($action === 'register') {
        $reg_number = h($_POST['reg_number'] ?? '');
        $name = h($_POST['name'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $year = (int)($_POST['year'] ?? 0);
        $semester = (int)($_POST['semester'] ?? 0);
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        $errors = [];
        if (!$reg_number) $errors[] = 'Registration number required';
        if (!$name) $errors[] = 'Name required';
        if (!$email) $errors[] = 'Valid email required';
        if (!$course_id) $errors[] = 'Course required';
        if (!$year || $year < 1 || $year > 3) $errors[] = 'Valid year required';
        if (!$semester || !in_array($semester, [1,2])) $errors[] = 'Valid semester required';
        
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match';
        } else {
            $strength = $security->validatePasswordStrength($password);
            if (!$strength['valid']) {
                $errors[] = $strength['message'];
            }
        }
        
        if (empty($errors)) {
            $check = $conn->prepare("SELECT student_id FROM students WHERE email = ? OR reg_number = ? AND deleted = 0");
            $check->bind_param('ss', $email, $reg_number);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Email or registration number already exists'];
            } else {
                $hash = $security->hashPassword($password);
                $insert = $conn->prepare(
                    "INSERT INTO students (reg_number, name, email, password, course_id, year, semester, status, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())"
                );
                $insert->bind_param('ssssiis', $reg_number, $name, $email, $hash, $course_id, $year, $semester);
                
                if ($insert->execute()) {
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Registration successful! Wait for admin approval.'];
                    $view = 'login';
                } else {
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Registration failed'];
                }
                $insert->close();
            }
            $check->close();
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => implode('<br>', $errors)];
        }
        
        header('Location: ?view=register');
        exit;
    }
    
    // ============= FORGOT PASSWORD =============
    elseif ($action === 'forgot') {
        $email = trim($_POST['email'] ?? '');
        $role = trim($_POST['role'] ?? '');
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Valid email required'];
        } elseif (!in_array($role, ['student', 'teacher', 'admin'])) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Select a valid role'];
        } else {
            $user_type = $role . 's';
            
            if ($role === 'teacher') {
                $stmt = $conn->prepare("SELECT teacher_id as user_id, fullname as name FROM teachers WHERE email = ? AND deleted = 0");
            } elseif ($role === 'student') {
                $stmt = $conn->prepare("SELECT student_id as user_id, name FROM students WHERE email = ? AND deleted = 0");
            } else {
                $stmt = $conn->prepare("SELECT admin_id as user_id, name FROM admins WHERE email = ? AND deleted = 0");
            }
            
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($user) {
                $result = $security->generateVerificationCode($user_type, $user['user_id'], $email);
                
                if ($result) {
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_role'] = $role;
                    $_SESSION['reset_user_id'] = $user['user_id'];
                    $_SESSION['reset_token_data'] = $result;
                    
                    $magic_link = "http://" . $_SERVER['HTTP_HOST'] . "/csms/public/?view=reset-by-token&token=" . $result['token'];
                    $verification_code = $result['code'];
                    
                    $email_sent = send_password_reset_email($email, $user['name'] ?? 'User', $magic_link, $verification_code);
                    
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'A reset link and verification code have been sent to your email.'];
                    header('Location: ?view=verify-code');
                    exit;
                }
            }
            
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'If your email exists, you will receive reset instructions.'];
        }
        
        header('Location: ?view=forgot');
        exit;
    }
    
    // ============= VERIFY CODE =============
    elseif ($action === 'verify_code') {
        $code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
        $email = $_SESSION['reset_email'] ?? '';
        $role = $_SESSION['reset_role'] ?? '';
        $user_type = $role . 's';
        
        if (strlen($code) !== 6) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Enter valid 6-digit code'];
        } elseif (!$email || !$role) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Session expired'];
            header('Location: ?view=forgot');
            exit;
        } else {
            $result = $security->verifyResetCode($email, $code, $user_type);
            
            if ($result['valid']) {
                $_SESSION['reset_token'] = $result['token'];
                $_SESSION['reset_user_id'] = $result['user_id'];
                $_SESSION['reset_user_type'] = $result['user_type'];
                $_SESSION['reset_verified'] = true;
                header('Location: ?view=reset-password');
                exit;
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'message' => $result['message']];
            }
        }
        
        header('Location: ?view=verify-code');
        exit;
    }
    
    // ============= RESET PASSWORD =============
    elseif ($action === 'reset_password') {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $token = $_SESSION['reset_token'] ?? '';
        $user_id = (int)($_SESSION['reset_user_id'] ?? 0);
        $user_type = $_SESSION['reset_user_type'] ?? '';
        $verified = $_SESSION['reset_verified'] ?? false;
        
        if (!$token || !$user_id || !$user_type || !$verified) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Session expired. Please start over.'];
            header('Location: ?view=forgot');
            exit;
        }
        
        if (!$security->verifyResetToken($token)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Reset token has expired. Please start over.'];
            header('Location: ?view=forgot');
            exit;
        }
        
        if ($password !== $confirm) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Passwords do not match'];
        } else {
            $strength = $security->validatePasswordStrength($password);
            if (!$strength['valid']) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => $strength['message']];
            } elseif ($security->checkPasswordHistory($user_type, $user_id, $password)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Cannot reuse recent password'];
            } else {
                if ($security->updatePassword($user_type, $user_id, $password)) {
                    $security->invalidateToken($token);
                    
                    unset($_SESSION['reset_token'], $_SESSION['reset_user_id'], 
                          $_SESSION['reset_user_type'], $_SESSION['reset_email'],
                          $_SESSION['reset_role'], $_SESSION['reset_verified'],
                          $_SESSION['reset_token_data']);
                    
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password reset successfully! You can now login.'];
                    header('Location: ?view=login');
                    exit;
                } else {
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Failed to reset password'];
                }
            }
        }
        
        header('Location: ?view=reset-password');
        exit;
    }
}

// Get courses for registration
$courses = [];
$result = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Display flash messages
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSMSPro - Login & Registration</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0d9488 0%, #115e59 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card {
            background: white;
            width: 100%;
            max-width: 500px;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #0d9488, #115e59);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }

        .card-header h1 {
            font-size: 42px;
            margin-bottom: 5px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .card-header h1 span {
            color: #ffd700;
        }

        .card-header p {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 5px;
        }

        .card-body {
            padding: 35px 30px;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-error { background: #fee2e2; color: #991b1b; border-color: #ef4444; }
        .alert-success { background: #d1fae5; color: #065f46; border-color: #10b981; }
        .alert-warning { background: #fef3c7; color: #92400e; border-color: #f59e0b; }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #0d9488, #115e59);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px #0d9488;
        }

        .link {
            color: #0d9488;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: color 0.3s;
        }

        .link:hover {
            color: #115e59;
            text-decoration: underline;
        }

        /* Role Selection - FIXED */
        .role-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
        }

        .role-btn {
            flex: 1;
            padding: 14px 8px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            background: white;
            color: #4b5563;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            transition: all 0.25s;
            user-select: none;
        }

        .role-btn:hover {
            border-color: #0d9488;
            background: #f0fdf9;
        }

        .role-btn.active {
            border-color: #0d9488;
            background: #0d9488;
            color: white;
        }

        .role-btn input[type="radio"] {
            display: none;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }

        .password-group {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 38px;
            background: none;
            border: none;
            color: #0d9488;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            padding: 0;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        .checkbox-group label {
            font-size: 13px;
            color: #4b5563;
            margin-bottom: 0;
            text-transform: none;
            letter-spacing: normal;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .text-center { text-align: center; }
        .mt-16 { margin-top: 16px; }

        .hint {
            font-size: 11px;
            color: #6b7280;
            margin-top: 6px;
        }

        .steps {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-bottom: 30px;
        }

        .step {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 2px solid #e5e7eb;
            color: #9ca3af;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
        }

        .step.active {
            border-color: #0d9488;
            background: #0d9488;
            color: white;
        }

        .step.done {
            border-color: #10b981;
            background: #10b981;
            color: white;
        }

        .step-line {
            width: 40px;
            height: 2px;
            background: #e5e7eb;
        }

        .info-box {
            background: #f0fdf9;
            border-left: 4px solid #0d9488;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #115e59;
        }

        .footer-links {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        @media (max-width: 480px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .role-buttons {
                flex-direction: column;
            }
            
            .footer-links {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <h1>CSMS<span>Pro</span></h1>
        <p>Create Your Account • Manage your account and access the system</p>
    </div>

    <div class="card-body">
        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>">
            <?= $flash['message'] ?>
        </div>
        <?php endif; ?>

        <?php if ($view === 'login'): ?>
            <h2 style="margin-bottom: 20px; color: #1f2937; font-size: 24px;">Sign In</h2>
            
            <form method="POST" id="loginForm">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label>Select Role</label>
                    <div class="role-buttons">
                        <?php foreach (['admin' => 'Admin', 'teacher' => 'Teacher', 'student' => 'Student'] as $val => $label): ?>
                        <label class="role-btn <?= $role === $val ? 'active' : '' ?>" onclick="selectRole('<?= $val ?>')">
                            <input type="radio" name="role" value="<?= $val ?>" <?= $role === $val ? 'checked' : '' ?> required>
                            <?= $label ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

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
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Remember me</label>
                </div>

                <button type="submit" class="btn-primary">Sign In</button>
            </form>

            <div class="footer-links">
                <a href="?view=forgot" class="link">Forgot password?</a>
                <a href="?view=register" class="link">New student? Register</a>
            </div>

        <?php elseif ($view === 'register'): ?>
            <h2 style="margin-bottom: 20px; color: #1f2937; font-size: 24px;">Student Registration</h2>
            <p style="font-size: 13px; color: #6b7280; margin-bottom: 25px;">Admin approval required after registration</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="register">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Reg Number</label>
                        <input type="text" name="reg_number" placeholder="e.g., STU001" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" placeholder="John Doe" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="john@example.com" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Course</label>
                        <select name="course_id" required>
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['course_id'] ?>"><?= h($c['course_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Year</label>
                        <select name="year" required>
                            <option value="">Year</option>
                            <?php for ($y=1; $y<=3; $y++): ?>
                            <option value="<?= $y ?>">Year <?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Semester</label>
                    <select name="semester" required>
                        <option value="">Select Semester</option>
                        <option value="1">Semester 1</option>
                        <option value="2">Semester 2</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" placeholder="Min 8 chars" required>
                        <div class="hint">8+ chars, upper, lower, number, special</div>
                    </div>
                    <div class="form-group">
                        <label>Confirm</label>
                        <input type="password" name="confirm_password" placeholder="Re-enter" required>
                    </div>
                </div>

                <button type="submit" class="btn-primary">Register Account</button>
            </form>

            <div class="text-center mt-16">
                <a href="?view=login" class="link">← Back to Sign In</a>
            </div>

        <?php elseif ($view === 'forgot'): ?>
            <div class="steps">
                <div class="step active">1</div>
                <div class="step-line"></div>
                <div class="step">2</div>
                <div class="step-line"></div>
                <div class="step">3</div>
            </div>
            
            <h2 style="margin-bottom: 20px; color: #1f2937; font-size: 24px;">Forgot Password</h2>
            <p style="font-size: 13px; color: #6b7280; margin-bottom: 25px;">Enter your email to receive reset instructions</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="forgot">
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="">Select Role</option>
                        <option value="admin">Admin</option>
                        <option value="teacher">Teacher</option>
                        <option value="student">Student</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="your@email.com" required>
                </div>

                <button type="submit" class="btn-primary">Send Reset Instructions</button>
            </form>

            <div class="text-center mt-16">
                <a href="?view=login" class="link">← Back to Sign In</a>
            </div>

        <?php elseif ($view === 'verify-code'): ?>
            <div class="steps">
                <div class="step done">✓</div>
                <div class="step-line"></div>
                <div class="step active">2</div>
                <div class="step-line"></div>
                <div class="step">3</div>
            </div>
            
            <h2 style="margin-bottom: 20px; color: #1f2937; font-size: 24px;">Enter Code</h2>
            <p style="font-size: 13px; color: #6b7280; margin-bottom: 20px;">
                Code sent to <strong><?= h($_SESSION['reset_email'] ?? 'your email') ?></strong>
            </p>
            
            <div class="info-box">
                <strong>📌 Two ways to reset:</strong><br>
                1. Enter the 6-digit code below, OR<br>
                2. Click the magic link sent to your email
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="verify_code">
                
                <div class="form-group">
                    <label>6-Digit Code</label>
                    <input type="text" name="code" maxlength="6" placeholder="000000" style="letter-spacing: 8px; font-size: 24px; text-align: center; font-family: monospace;" required>
                </div>

                <button type="submit" class="btn-primary">Verify Code</button>
            </form>

            <div class="footer-links">
                <a href="?view=forgot" class="link">← Try again</a>
                <a href="?view=login" class="link">Cancel</a>
            </div>

        <?php elseif ($view === 'reset-password'): ?>
            <div class="steps">
                <div class="step done">✓</div>
                <div class="step-line"></div>
                <div class="step done">✓</div>
                <div class="step-line"></div>
                <div class="step active">3</div>
            </div>
            
            <h2 style="margin-bottom: 20px; color: #1f2937; font-size: 24px;">New Password</h2>
            <p style="font-size: 13px; color: #6b7280; margin-bottom: 25px;">Choose a strong password you haven't used before</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" placeholder="Min 8 chars" required>
                    <div class="hint">Uppercase, lowercase, number, special character</div>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="Re-enter" required>
                </div>

                <button type="submit" class="btn-primary">Reset Password</button>
            </form>

            <div class="text-center mt-16">
                <a href="?view=login" class="link">Cancel</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Toggle password visibility
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

// Role selection function
function selectRole(role) {
    // Update hidden radio
    document.querySelectorAll('input[name="role"]').forEach(radio => {
        if (radio.value === role) {
            radio.checked = true;
        }
    });
    
    // Update active class on buttons
    document.querySelectorAll('.role-btn').forEach(btn => {
        const radio = btn.querySelector('input[name="role"]');
        if (radio && radio.value === role) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
}

// Initialize role buttons on page load
document.addEventListener('DOMContentLoaded', function() {
    const selectedRole = document.querySelector('input[name="role"]:checked');
    if (selectedRole) {
        selectRole(selectedRole.value);
    }
});
</script>

</body>
</html>