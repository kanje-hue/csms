<?php
/**
 * index.php - Unified Login, Registration & Password Reset Hub
 * Fixed column name issue - ROOT DIRECTORY VERSION
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/security.php';
require_once 'config/db.php';
require_once 'config/email_config.php';

session_start();

$security = new SecurityManager($conn);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$view    = $_POST['view']    ?? $_GET['view']    ?? $_SESSION['reset_view'] ?? 'login';
$role    = $_POST['role']    ?? $_GET['role']    ?? $_SESSION['reset_role'] ?? '';
$message = '';
$msg_type = 'error';

if ($role) $_SESSION['reset_role'] = $role;
else        $role = $_SESSION['reset_role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    error_log("[CSMS Debug] action=$action view=$view role=$role");

    // ── LOGIN ──────────────────────────────────────────────
    if ($action === 'login') {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = trim($_POST['role']     ?? '');

        // FIXED: Correct column names for each table
        $roleMap = [
            'admin'   => [
                'table' => 'admins', 
                'pk' => 'admin_id', 
                'name_col' => 'name',     // admins uses 'name'
                'redirect' => 'admin/manage_courses.php',  
                'sess_prefix' => 'admin'
            ],
            'teacher' => [
                'table' => 'teachers', 
                'pk' => 'teacher_id', 
                'name_col' => 'fullname', // teachers uses 'fullname'
                'redirect' => 'teacher/dashboard.php',      
                'sess_prefix' => 'teacher'
            ],
            'student' => [
                'table' => 'students', 
                'pk' => 'student_id', 
                'name_col' => 'name',     // students uses 'name'
                'redirect' => 'student/dashboard.php',      
                'sess_prefix' => 'student'
            ],
        ];

        if (!isset($roleMap[$role])) {
            $_SESSION['flash_message'] = 'Please select a valid role.';
            $_SESSION['flash_type'] = 'error';
        } elseif (empty($email) || empty($password)) {
            $_SESSION['flash_message'] = 'Please fill in all fields.';
            $_SESSION['flash_type'] = 'error';
        } else {
            $meta      = $roleMap[$role];
            $user_type = $role . 's';

            // Debug: Log which table we're querying
            error_log("Login attempt - Table: {$meta['table']}, Email: $email");

            $stmt = $conn->prepare(
                "SELECT * FROM `{$meta['table']}` WHERE email = ? AND deleted = 0 LIMIT 1"
            );
            
            if (!$stmt) {
                error_log("Prepare failed: " . $conn->error);
                $_SESSION['flash_message'] = 'Database error. Please try again.';
                $_SESSION['flash_type'] = 'error';
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$user) {
                    $_SESSION['flash_message'] = '❌ Invalid email or password.';
                    $_SESSION['flash_type'] = 'error';
                    error_log("[CSMS Debug] Login failed: user not found for $role email=$email");
                } elseif ($security->isAccountLocked($user_type, (int)$user[$meta['pk']])) {
                    $mins    = $security->getLockoutMinutes($user_type, (int)$user[$meta['pk']]);
                    $_SESSION['flash_message'] = "🔒 Account locked. Try again in $mins minute(s).";
                    $_SESSION['flash_type'] = 'error';
                } elseif (!$security->verifyPassword($password, $user['password'])) {
                    $attempts  = $security->recordFailedAttempt($user_type, (int)$user[$meta['pk']]);
                    $remaining = 5 - $attempts;
                    if ($remaining <= 0) {
                        $_SESSION['flash_message'] = '🔒 Account locked for 30 minutes due to too many failed attempts.';
                    } else {
                        $_SESSION['flash_message'] = "❌ Invalid email or password. $remaining attempt(s) remaining.";
                    }
                    $_SESSION['flash_type'] = 'error';
                    $security->logLogin($user_type, (int)$user[$meta['pk']], $email, 'login_failure');
                } else {
                    $security->resetFailedAttempts($user_type, (int)$user[$meta['pk']]);
                    $security->logLogin($user_type, (int)$user[$meta['pk']], $email, 'login_success');

                    $prefix = $meta['sess_prefix'];
                    $_SESSION["{$prefix}_logged_in"] = true;
                    $_SESSION["{$prefix}_id"]        = $user[$meta['pk']];
                    $_SESSION["{$prefix}_name"]      = $user[$meta['name_col']];
                    $_SESSION['csrf_token']           = bin2hex(random_bytes(32));

                    unset($_SESSION['reset_view'], $_SESSION['reset_role'],
                          $_SESSION['reset_email'], $_SESSION['reset_token']);

                    header('Location: ' . $meta['redirect']);
                    exit;
                }
            }
        }
        $view = 'login';

    // ── STUDENT REGISTRATION ──────────────────────────────
    } elseif ($action === 'register') {
        $reg_number = htmlspecialchars(trim($_POST['reg_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $name       = htmlspecialchars(trim($_POST['name']       ?? ''), ENT_QUOTES, 'UTF-8');
        $email      = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $course_id  = filter_var($_POST['course_id'] ?? 0, FILTER_VALIDATE_INT);
        $year       = filter_var($_POST['year']      ?? 0, FILTER_VALIDATE_INT);
        $semester   = filter_var($_POST['semester']  ?? 0, FILTER_VALIDATE_INT);
        $password   = $_POST['password']         ?? '';
        $confirm    = $_POST['confirm_password'] ?? '';

        if (!$reg_number || !$name || !$email || !$course_id || !$year || !$semester) {
            $_SESSION['flash_message'] = 'Please fill in all required fields.';
            $_SESSION['flash_type'] = 'error';
        } elseif ($password !== $confirm) {
            $_SESSION['flash_message'] = 'Passwords do not match.';
            $_SESSION['flash_type'] = 'error';
        } else {
            $strength = $security->validatePasswordStrength($password);
            if (!$strength['valid']) {
                $_SESSION['flash_message'] = $strength['message'];
                $_SESSION['flash_type'] = 'error';
            } else {
                $chk = $conn->prepare("SELECT student_id FROM students WHERE (email = ? OR reg_number = ?) AND deleted = 0 LIMIT 1");
                $chk->bind_param('ss', $email, $reg_number);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $_SESSION['flash_message'] = 'Email or registration number already in use.';
                    $_SESSION['flash_type'] = 'error';
                } else {
                    $hash = $security->hashPassword($password);
                    $ins  = $conn->prepare(
                        "INSERT INTO students (reg_number, name, email, password, course_id, year, semester, status, deleted, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW())"
                    );
                    $ins->bind_param('ssssiis', $reg_number, $name, $email, $hash, $course_id, $year, $semester);
                    if ($ins->execute()) {
                        $_SESSION['flash_message'] = '✅ Registration successful! Please wait for admin approval.';
                        $_SESSION['flash_type'] = 'success';
                        $view = 'login';
                    } else {
                        $_SESSION['flash_message'] = 'Registration failed. Please try again.';
                        $_SESSION['flash_type'] = 'error';
                    }
                    $ins->close();
                }
                $chk->close();
            }
        }
        if ($_SESSION['flash_type'] !== 'success') $view = 'register';

    // ── FORGOT PASSWORD – EMAIL SUBMISSION ────────────────
    } elseif ($action === 'forgot_email') {
        $email = trim($_POST['email'] ?? '');
        $user_role = trim($_POST['role'] ?? '');
        
        // Clear old session data
        unset($_SESSION['reset_email'], $_SESSION['reset_user_type'], 
              $_SESSION['reset_token'], $_SESSION['reset_user_id']);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_message'] = 'Please enter a valid email address.';
            $_SESSION['flash_type'] = 'error';
            $view = 'forgot';
        } elseif (!in_array($user_role, ['admin', 'teacher', 'student'])) {
            $_SESSION['flash_message'] = 'Please select a valid role.';
            $_SESSION['flash_type'] = 'error';
            $view = 'forgot';
        } else {
            $user_type = $user_role . 's';
            $table_map = [
                'admins' => 'admin_id',
                'teachers' => 'teacher_id',
                'students' => 'student_id'
            ];
            
            // Check if user exists
            $stmt = $conn->prepare(
                "SELECT `{$table_map[$user_type]}` as user_id 
                 FROM `{$user_type}` 
                 WHERE email = ? AND deleted = 0 LIMIT 1"
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            // Store email in session regardless
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_user_type'] = $user_type;
            $_SESSION['reset_role'] = $user_role;
            
            if ($user) {
                // Generate verification code
                $result = $security->generateVerificationCode(
                    $user_type, 
                    (int)$user['user_id'], 
                    $email
                );
                
                if ($result) {
                    // Store in session
                    $_SESSION['reset_user_id'] = (int)$user['user_id'];
                    $_SESSION['reset_token_data'] = $result;
                    
                    // Create email content
                    $email_content = "
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: #16a085; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                            .code { 
                                background: #fff; 
                                padding: 20px; 
                                text-align: center; 
                                font-size: 48px; 
                                font-weight: bold; 
                                color: #16a085; 
                                letter-spacing: 10px;
                                border: 2px dashed #16a085;
                                border-radius: 8px;
                                margin: 20px 0;
                                font-family: monospace;
                            }
                            .footer { text-align: center; color: #888; font-size: 12px; margin-top: 20px; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1>CSMS Portal</h1>
                                <p>College Student Management System</p>
                            </div>
                            <div class='content'>
                                <h2 style='color: #333;'>Password Reset Request</h2>
                                <p>Hello,</p>
                                <p>You recently requested to reset your password. Use the verification code below to complete the process:</p>
                                
                                <div class='code'>{$result['code']}</div>
                                
                                <p><strong>This code will expire in 1 hour.</strong></p>
                                <p>If you didn't request this, please ignore this email or contact support.</p>
                                <hr style='border: 1px solid #eee;'>
                                <p style='color: #888;'>This is an automated message, please do not reply.</p>
                            </div>
                            <div class='footer'>
                                <p>&copy; " . date('Y') . " CSMS Portal. All rights reserved.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                    ";
                    
                    // Send email
                    $email_sent = send_email(
                        $email,
                        $email,
                        'Password Reset Code - CSMS',
                        $email_content
                    );
                    
                    // Show code on screen for development
                    $_SESSION['flash_message'] = '🔑 YOUR VERIFICATION CODE IS: <strong style="font-size: 24px; letter-spacing: 3px;">' . $result['code'] . '</strong><br><small>Check your email or use this code directly.</small>';
                    $_SESSION['flash_type'] = 'warning';
                    
                } else {
                    $_SESSION['flash_message'] = '⚠️ System error. Please try again later.';
                    $_SESSION['flash_type'] = 'error';
                }
            } else {
                $_SESSION['flash_message'] = '✅ If the email exists, a code has been sent.';
                $_SESSION['flash_type'] = 'success';
            }
            
            $view = 'verify_code';
        }

    // ── FORGOT PASSWORD – CODE VERIFICATION ───────────────
    } elseif ($action === 'verify_code') {
        $code = trim(preg_replace('/[^0-9]/', '', $_POST['code'] ?? ''));
        $email = $_SESSION['reset_email'] ?? '';
        $user_type = $_SESSION['reset_user_type'] ?? '';
        
        if (empty($code) || strlen($code) !== 6) {
            $_SESSION['flash_message'] = 'Please enter a valid 6-digit code.';
            $_SESSION['flash_type'] = 'error';
            $view = 'verify_code';
        } elseif (empty($email) || empty($user_type)) {
            $_SESSION['flash_message'] = 'Session expired. Please start over.';
            $_SESSION['flash_type'] = 'error';
            $view = 'forgot';
            unset($_SESSION['reset_email'], $_SESSION['reset_user_type']);
        } else {
            $result = $security->verifyCode($email, $code, $user_type);
            
            if ($result['valid']) {
                $_SESSION['reset_token'] = $result['token'];
                $_SESSION['reset_user_id'] = $result['user_id'];
                $_SESSION['reset_user_type'] = $result['user_type'];
                $_SESSION['reset_verified'] = true;
                $view = 'reset_password';
                
                unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            } else {
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = 'error';
                
                if (strpos($result['message'], 'Too many') !== false) {
                    unset($_SESSION['reset_email'], $_SESSION['reset_user_type']);
                    $view = 'forgot';
                } else {
                    $view = 'verify_code';
                }
            }
        }

    // ── FORGOT PASSWORD – NEW PASSWORD SUBMISSION ─────────
    } elseif ($action === 'reset_password') {
        $token = $_SESSION['reset_token'] ?? '';
        $user_id = (int)($_SESSION['reset_user_id'] ?? 0);
        $user_type = $_SESSION['reset_user_type'] ?? '';
        $verified = $_SESSION['reset_verified'] ?? false;
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if (empty($token) || !$user_id || empty($user_type) || !$verified) {
            $_SESSION['flash_message'] = 'Session expired. Please start over.';
            $_SESSION['flash_type'] = 'error';
            $view = 'forgot';
            unset($_SESSION['reset_token'], $_SESSION['reset_user_id'], 
                  $_SESSION['reset_user_type'], $_SESSION['reset_verified']);
        }
        elseif (!$security->verifyResetToken($token)) {
            $_SESSION['flash_message'] = 'Reset link has expired. Please start over.';
            $_SESSION['flash_type'] = 'error';
            $view = 'forgot';
        }
        elseif ($password !== $confirm) {
            $_SESSION['flash_message'] = 'Passwords do not match.';
            $_SESSION['flash_type'] = 'error';
            $view = 'reset_password';
        }
        else {
            $strength = $security->validatePasswordStrength($password);
            if (!$strength['valid']) {
                $_SESSION['flash_message'] = $strength['message'];
                $_SESSION['flash_type'] = 'error';
                $view = 'reset_password';
            }
            elseif ($security->checkPasswordHistory($user_type, $user_id, $password)) {
                $_SESSION['flash_message'] = 'You cannot reuse a recent password.';
                $_SESSION['flash_type'] = 'error';
                $view = 'reset_password';
            }
            else {
                if ($security->updatePassword($user_type, $user_id, $password)) {
                    $security->invalidateToken($token);
                    
                    unset($_SESSION['reset_token'], $_SESSION['reset_user_id'],
                          $_SESSION['reset_user_type'], $_SESSION['reset_email'],
                          $_SESSION['reset_view'], $_SESSION['reset_role'],
                          $_SESSION['reset_verified']);
                    
                    $_SESSION['flash_message'] = '✅ Password reset successfully! You can now log in.';
                    $_SESSION['flash_type'] = 'success';
                    $view = 'login';
                } else {
                    $_SESSION['flash_message'] = '❌ Failed to update password. Please try again.';
                    $_SESSION['flash_type'] = 'error';
                    $view = 'reset_password';
                }
            }
        }
    }
}

if (in_array($view, ['verify_code', 'reset_password'])) {
    $_SESSION['reset_view'] = $view;
}

$courses = [];
$cr = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name");
if ($cr) {
    while ($row = $cr->fetch_assoc()) $courses[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSMS – Login & Registration</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

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
            background: #fff;
            width: 100%;
            max-width: 520px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.4);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #16a085, #117a65);
            padding: 30px;
            text-align: center;
            color: #fff;
        }
        .card-header h1 { font-size: 22px; margin-bottom: 4px; }
        .card-header p  { font-size: 13px; opacity: .85; }

        .card-body { padding: 30px; }

        .role-buttons { display: flex; gap: 10px; margin-bottom: 24px; }
        .role-btn {
            flex: 1;
            padding: 10px 6px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
            color: #555;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            transition: all .25s;
            text-decoration: none;
            display: block;
        }
        .role-btn:hover, .role-btn.active {
            border-color: #16a085;
            background: #16a085;
            color: #fff;
        }

        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid #e8e8e8;
            border-radius: 8px;
            font-size: 14px;
            transition: border .25s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #16a085;
            box-shadow: 0 0 0 3px rgba(22,160,133,.12);
        }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }

        .btn-primary {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #16a085, #117a65);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform .2s, box-shadow .2s;
            margin-top: 6px;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(22,160,133,.35);
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        .alert-error   { background: #fdecea; color: #c0392b; border-color: #c0392b; }
        .alert-success { background: #eafaf1; color: #1a7a4a; border-color: #27ae60; }
        .alert-warning { 
            background: #fff3cd; 
            color: #856404; 
            border-color: #ffc107;
            font-size: 16px;
            text-align: center;
        }
        .alert-warning strong {
            font-size: 28px;
            letter-spacing: 5px;
            display: block;
            margin: 10px 0;
        }

        .text-center { text-align: center; }
        .mt-16 { margin-top: 16px; }
        .link {
            color: #16a085;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }
        .link:hover { text-decoration: underline; }

        .hint {
            font-size: 11px;
            color: #888;
            margin-top: 4px;
        }

        .steps {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        .step {
            width: 28px; height: 28px;
            border-radius: 50%;
            border: 2px solid #ccc;
            color: #ccc;
            font-size: 12px;
            font-weight: 700;
            display: flex; align-items: center; justify-content: center;
        }
        .step.active { border-color: #16a085; background: #16a085; color: #fff; }
        .step.done   { border-color: #27ae60; background: #27ae60; color: #fff; }
        .step-line { flex: 1; height: 2px; background: #e0e0e0; align-self: center; max-width: 40px; }

        .code-display {
            background: #f0f8ff;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin: 10px 0;
            border: 2px dashed #16a085;
        }
        .code-display .code {
            font-size: 42px;
            font-weight: bold;
            color: #16a085;
            letter-spacing: 8px;
            font-family: monospace;
        }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <h1>🎓 CSMS Portal</h1>
        <p>College Student Management System</p>
    </div>

    <div class="card-body">
        <?php 
        if (isset($_SESSION['flash_message'])): 
        ?>
        <div class="alert alert-<?= $_SESSION['flash_type'] ?? 'error' ?>">
            <?= $_SESSION['flash_message'] ?>
            <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
        </div>
        <?php endif; ?>

        <?php if ($view === 'login'): ?>

        <h2 style="font-size:18px;margin-bottom:18px;color:#1a1a2e">Sign In</h2>

        <form method="POST">
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label>Select Role</label>
                <div class="role-buttons">
                    <?php foreach (['admin' => '🏢 Admin', 'teacher' => '👨‍🏫 Teacher', 'student' => '👨‍🎓 Student'] as $r => $label): ?>
                    <label class="role-btn <?= $role === $r ? 'active' : '' ?>" style="cursor:pointer">
                        <input type="radio" name="role" value="<?= $r ?>"
                               <?= $role === $r ? 'checked' : '' ?>
                               style="display:none" onchange="this.closest('form').querySelectorAll('.role-btn').forEach(b=>b.classList.remove('active'));this.parentElement.classList.add('active')">
                        <?= $label ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Enter your email" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>

            <button type="submit" class="btn-primary">Sign In →</button>
        </form>

        <div class="text-center mt-16" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
            <a href="?view=forgot" class="link">Forgot password?</a>
            <a href="?view=register" class="link">New student? Register</a>
        </div>

        <?php elseif ($view === 'register'): ?>

        <h2 style="font-size:18px;margin-bottom:4px;color:#1a1a2e">Student Registration</h2>
        <p style="font-size:12px;color:#888;margin-bottom:20px">Admin approval required after registration</p>

        <form method="POST">
            <input type="hidden" name="action" value="register">

            <div class="form-row">
                <div class="form-group">
                    <label>Registration Number *</label>
                    <input type="text" name="reg_number" value="<?= h($_POST['reg_number'] ?? '') ?>"
                           required placeholder="e.g. STU001">
                </div>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="name" value="<?= h($_POST['name'] ?? '') ?>"
                           required placeholder="John Doe">
                </div>
            </div>

            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>"
                       required placeholder="john@example.com">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Course *</label>
                    <select name="course_id" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($courses as $c): ?>
                        <option value="<?= (int)$c['course_id'] ?>"
                            <?= (isset($_POST['course_id']) && $_POST['course_id'] == $c['course_id']) ? 'selected' : '' ?>>
                            <?= h($c['course_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Year *</label>
                    <select name="year" required>
                        <option value="">-- Year --</option>
                        <?php for ($y = 1; $y <= 3; $y++): ?>
                        <option value="<?= $y ?>" <?= (($_POST['year'] ?? '') == $y) ? 'selected' : '' ?>>Year <?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Semester *</label>
                <select name="semester" required>
                    <option value="">-- Semester --</option>
                    <option value="1" <?= (($_POST['semester'] ?? '') == 1) ? 'selected' : '' ?>>Semester 1</option>
                    <option value="2" <?= (($_POST['semester'] ?? '') == 2) ? 'selected' : '' ?>>Semester 2</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" required placeholder="Min 8 chars">
                    <div class="hint">8+ chars, upper, lower, number, special</div>
                </div>
                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" required>
                </div>
            </div>

            <button type="submit" class="btn-primary">Register Account</button>
        </form>

        <div class="text-center mt-16">
            <a href="?view=login" class="link">← Back to Login</a>
        </div>

        <?php elseif ($view === 'forgot'): ?>

        <div class="steps">
            <div class="step active">1</div>
            <div class="step-line"></div>
            <div class="step">2</div>
            <div class="step-line"></div>
            <div class="step">3</div>
        </div>

        <h2 style="font-size:18px;margin-bottom:4px;color:#1a1a2e">Forgot Password</h2>
        <p style="font-size:12px;color:#888;margin-bottom:20px">Enter your email to receive a 6-digit verification code</p>

        <form method="POST">
            <input type="hidden" name="action" value="forgot_email">

            <div class="form-group">
                <label>Account Type</label>
                <select name="role" required>
                    <option value="">-- Select Role --</option>
                    <option value="admin" <?= ($role === 'admin') ? 'selected' : '' ?>>Admin</option>
                    <option value="teacher" <?= ($role === 'teacher') ? 'selected' : '' ?>>Teacher</option>
                    <option value="student" <?= ($role === 'student') ? 'selected' : '' ?>>Student</option>
                </select>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required placeholder="your@email.com">
            </div>

            <button type="submit" class="btn-primary">Send Verification Code</button>
        </form>

        <div class="text-center mt-16">
            <a href="?view=login" class="link">← Back to Login</a>
        </div>

        <?php elseif ($view === 'verify_code'): ?>

        <div class="steps">
            <div class="step done">✓</div>
            <div class="step-line"></div>
            <div class="step active">2</div>
            <div class="step-line"></div>
            <div class="step">3</div>
        </div>

        <h2 style="font-size:18px;margin-bottom:4px;color:#1a1a2e">Enter Verification Code</h2>
        <p style="font-size:12px;color:#888;margin-bottom:20px">
            A 6-digit code was sent to <strong><?= h($_SESSION['reset_email'] ?? '') ?></strong>
        </p>

        <?php if (isset($_SESSION['reset_token_data']['code'])): ?>
        <div class="code-display">
            <div style="font-size:14px; color:#666; margin-bottom:5px;">🔑 Development Code:</div>
            <div class="code"><?= $_SESSION['reset_token_data']['code'] ?></div>
            <div style="font-size:12px; color:#999; margin-top:5px;">Copy this code to verify</div>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="verify_code">

            <div class="form-group">
                <label>6-Digit Code</label>
                <input type="text" name="code" required maxlength="6" placeholder="000000"
                       style="letter-spacing:8px;font-size:22px;text-align:center"
                       pattern="\d{6}" inputmode="numeric" autocomplete="off">
            </div>

            <button type="submit" class="btn-primary">Verify Code</button>
        </form>

        <div class="text-center mt-16" style="display:flex;justify-content:space-between">
            <a href="?view=forgot" class="link">← Try different email</a>
            <a href="?view=login"  class="link">Cancel</a>
        </div>

        <?php elseif ($view === 'reset_password'): ?>

        <div class="steps">
            <div class="step done">✓</div>
            <div class="step-line"></div>
            <div class="step done">✓</div>
            <div class="step-line"></div>
            <div class="step active">3</div>
        </div>

        <h2 style="font-size:18px;margin-bottom:4px;color:#1a1a2e">Set New Password</h2>
        <p style="font-size:12px;color:#888;margin-bottom:20px">Choose a strong password you haven't used recently</p>

        <form method="POST">
            <input type="hidden" name="action" value="reset_password">

            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="password" required placeholder="Min 8 chars">
                <div class="hint">Must include uppercase, lowercase, number, and special character</div>
            </div>

            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required placeholder="Repeat password">
            </div>

            <button type="submit" class="btn-primary">Reset Password</button>
        </form>

        <div class="text-center mt-16">
            <a href="?view=login" class="link">Cancel</a>
        </div>

        <?php endif; ?>

    </div>
</div>

</body>
</html>