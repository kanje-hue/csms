<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Clear session on page load (not on POST)
$csrf_token = $_SESSION['csrf_token'] ?? null;
if(empty($_POST)) {
    session_destroy();
    session_start();
    if($csrf_token) {
        $_SESSION['csrf_token'] = $csrf_token;
    }
}

include 'config/db.php';
include 'config/security.php';

if (!$conn) {
    die("Database connection failed!");
}

$security = new SecurityManager($conn);
$message = '';
$message_type = '';

// HANDLE LOGIN
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])){
    $action = trim($_POST['action']);
    
    if($action === 'login'){
        $role = trim($_POST['role'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if(empty($role) || empty($email) || empty($password)){
            $message = "❌ Please fill in all fields";
            $message_type = "error";
        } else {
            if($role === 'admin'){
                // Check if account exists
                $stmt = $conn->prepare("SELECT admin_id, name, password, locked_until, force_password_change FROM admins WHERE email = ? AND deleted = 0");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if($result->num_rows > 0){
                    $admin = $result->fetch_assoc();
                    
                    // Check if locked
                    if($security->isAccountLocked($admin['admin_id'], 'admin')){
                        $message = "❌ Account locked. Try again in 30 minutes.";
                        $message_type = "error";
                        $security->recordLoginAttempt('admin', $email, 'locked', 'Account locked');
                    }
                    // Verify password
                    elseif($security->verifyPassword($password, $admin['password'])){
                        // Reset login attempts
                        $security->resetLoginAttempts($admin['admin_id'], 'admin');
                        
                        session_regenerate_id(true);
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id'] = $admin['admin_id'];
                        $_SESSION['admin_name'] = $admin['name'];
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        
                        $security->recordLoginAttempt('admin', $email, 'success', '');
                        
                        // Check if needs password change
                        if($admin['force_password_change']){
                            $_SESSION['force_password_change'] = true;
                            header("Location: admin/change_password.php", true, 302);
                            exit();
                        }
                        
                        header("Location: admin/manage_courses.php", true, 302);
                        exit();
                    } else {
                        $security->incrementFailedAttempts($admin['admin_id'], 'admin');
                        $message = "❌ Invalid email or password";
                        $message_type = "error";
                        $security->recordLoginAttempt('admin', $email, 'failed', 'Invalid password');
                    }
                } else {
                    $message = "❌ Invalid email or password";
                    $message_type = "error";
                    $security->recordLoginAttempt('admin', $email, 'failed', 'User not found');
                }
                $stmt->close();
            }
            elseif($role === 'teacher'){
                $stmt = $conn->prepare("SELECT teacher_id, fullname, password, locked_until, force_password_change FROM teachers WHERE email = ? AND deleted = 0");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if($result->num_rows > 0){
                    $teacher = $result->fetch_assoc();
                    
                    if($security->isAccountLocked($teacher['teacher_id'], 'teacher')){
                        $message = "❌ Account locked. Try again in 30 minutes.";
                        $message_type = "error";
                        $security->recordLoginAttempt('teacher', $email, 'locked', 'Account locked');
                    }
                    elseif($security->verifyPassword($password, $teacher['password'])){
                        $security->resetLoginAttempts($teacher['teacher_id'], 'teacher');
                        
                        session_regenerate_id(true);
                        $_SESSION['teacher_logged_in'] = true;
                        $_SESSION['teacher_id'] = $teacher['teacher_id'];
                        $_SESSION['teacher_name'] = $teacher['fullname'];
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        
                        $security->recordLoginAttempt('teacher', $email, 'success', '');
                        
                        if($teacher['force_password_change']){
                            $_SESSION['force_password_change'] = true;
                            header("Location: teacher/change_password.php", true, 302);
                            exit();
                        }
                        
                        header("Location: teacher/dashboard.php", true, 302);
                        exit();
                    } else {
                        $security->incrementFailedAttempts($teacher['teacher_id'], 'teacher');
                        $message = "❌ Invalid email or password";
                        $message_type = "error";
                        $security->recordLoginAttempt('teacher', $email, 'failed', 'Invalid password');
                    }
                } else {
                    $message = "❌ Invalid email or password";
                    $message_type = "error";
                    $security->recordLoginAttempt('teacher', $email, 'failed', 'User not found');
                }
                $stmt->close();
            }
            elseif($role === 'student'){
                $stmt = $conn->prepare("SELECT student_id, name, course_id, year, password, locked_until FROM students WHERE email = ? AND deleted = 0 AND status = 'active'");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if($result->num_rows > 0){
                    $student = $result->fetch_assoc();
                    
                    if($security->isAccountLocked($student['student_id'], 'student')){
                        $message = "❌ Account locked. Try again in 30 minutes.";
                        $message_type = "error";
                        $security->recordLoginAttempt('student', $email, 'locked', 'Account locked');
                    }
                    elseif($security->verifyPassword($password, $student['password'])){
                        $security->resetLoginAttempts($student['student_id'], 'student');
                        
                        session_regenerate_id(true);
                        $_SESSION['student_id'] = $student['student_id'];
                        $_SESSION['student_name'] = $student['name'];
                        $_SESSION['course_id'] = $student['course_id'];
                        $_SESSION['year'] = $student['year'];
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        
                        $security->recordLoginAttempt('student', $email, 'success', '');
                        header("Location: student/dashboard.php", true, 302);
                        exit();
                    } else {
                        $security->incrementFailedAttempts($student['student_id'], 'student');
                        $message = "❌ Invalid email or password";
                        $message_type = "error";
                        $security->recordLoginAttempt('student', $email, 'failed', 'Invalid password');
                    }
                } else {
                    $message = "❌ Invalid email or password or account not active";
                    $message_type = "error";
                    $security->recordLoginAttempt('student', $email, 'failed', 'User not found or not active');
                }
                $stmt->close();
            }
        }
    }
    // STUDENT REGISTRATION ONLY
    elseif($action === 'register'){
        $role = trim($_POST['register_role'] ?? '');
        
        if($role === 'admin' || $role === 'teacher'){
            $message = "❌ Admin and Teacher accounts can only be created by the administrator";
            $message_type = "error";
        }
        elseif($role === 'student'){
            $fullname = trim($_POST['fullname'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $confirm_password = trim($_POST['confirm_password'] ?? '');
            $reg_number = trim($_POST['reg_number'] ?? '');
            $course_id = filter_var($_POST['course_id'] ?? 0, FILTER_VALIDATE_INT);
            
            if(empty($fullname) || empty($email) || empty($password) || empty($confirm_password)){
                $message = "❌ Please fill in all fields";
                $message_type = "error";
            } elseif($password !== $confirm_password){
                $message = "❌ Passwords do not match";
                $message_type = "error";
            } elseif(empty($reg_number) || !$course_id){
                $message = "❌ Please fill registration number and select course";
                $message_type = "error";
            } else {
                // Validate password strength
                $password_errors = $security->validatePasswordStrength($password);
                if(!empty($password_errors)){
                    $message = "❌ Password: " . implode(", ", $password_errors);
                    $message_type = "error";
                } else {
                    $hashed_password = $security->hashPassword($password);
                    
                    $check = $conn->prepare("SELECT student_id FROM students WHERE email = ?");
                    $check->bind_param("s", $email);
                    $check->execute();
                    
                    if($check->get_result()->num_rows > 0){
                        $message = "❌ Email already registered";
                        $message_type = "error";
                    } else {
                        $stmt = $conn->prepare("INSERT INTO students (reg_number, name, email, password, course_id, year, status, deleted, created_at) VALUES (?, ?, ?, ?, ?, 1, 'pending', 0, NOW())");
                        $stmt->bind_param("ssssi", $reg_number, $fullname, $email, $hashed_password, $course_id);
                        
                        if($stmt->execute()){
                            $message = "✅ Student account created! Awaiting admin approval.";
                            $message_type = "success";
                        } else {
                            $message = "❌ Error: " . $stmt->error;
                            $message_type = "error";
                        }
                        $stmt->close();
                    }
                    $check->close();
                }
            }
        }
    }
    // FORGOT PASSWORD
    elseif($action === 'forgot-password'){
        $email = trim($_POST['forgot_email'] ?? '');
        $user_type = trim($_POST['forgot_type'] ?? '');
        
        if(empty($email) || empty($user_type)){
            $message = "❌ Please provide email and select account type";
            $message_type = "error";
        } else {
            $table = $user_type . 's';
            $id_field = $user_type . '_id';
            
            $stmt = $conn->prepare("SELECT $id_field, fullname FROM $table WHERE email = ? AND deleted = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows > 0){
                $user = $result->fetch_assoc();
                $code = $security->generateVerificationCode($table, $user[$id_field], $email);
                
                // Send email
                $security->sendPasswordResetEmail($email, $user['fullname'], $code);
                
                // Store type in session for verification page
                $_SESSION['reset_type'] = $user_type;
                
                $message = "✅ Verification code sent to your email. Please check your inbox.";
                $message_type = "success";
            } else {
                $message = "❌ Email not found";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSMS - Secure Login</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); font-family: Arial, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { width: 100%; max-width: 600px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #1a1a2e, #16213e); color: white; padding: 40px 20px; text-align: center; }
        .logo { font-size: 36px; font-weight: bold; margin-bottom: 10px; }
        .logo-accent { color: #16a085; }
        .card-header h2 { font-size: 24px; margin-bottom: 10px; }
        .card-header p { font-size: 13px; opacity: 0.9; }
        .card-body { padding: 40px 30px; }
        .tabs { display: flex; border-bottom: 2px solid #ecf0f1; margin-bottom: 30px; }
        .tab-btn { flex: 1; padding: 15px; border: none; background: none; cursor: pointer; font-size: 14px; font-weight: 600; color: #95a5a6; transition: all 0.3s; border-bottom: 3px solid transparent; margin-bottom: -2px; }
        .tab-btn:hover { color: #16a085; }
        .tab-btn.active { color: #16a085; border-bottom-color: #16a085; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .role-selector { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 30px; }
        .role-option { padding: 20px; border: 2px solid #ecf0f1; border-radius: 8px; cursor: pointer; text-align: center; transition: all 0.3s; }
        .role-option:hover:not(.disabled) { border-color: #16a085; background: #f8fafb; }
        .role-option.selected { border-color: #16a085; background: #e8f8f5; box-shadow: 0 4px 12px rgba(22,160,133,0.2); }
        .role-option.disabled { opacity: 0.5; cursor: not-allowed; background: #f5f5f5; }
        .role-icon { font-size: 32px; margin-bottom: 8px; }
        .role-label { font-size: 12px; font-weight: 600; color: #2c3e50; }
        .role-note { font-size: 10px; color: #e74c3c; margin-top: 5px; font-weight: 600; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #2c3e50; }
        input, select { width: 100%; padding: 12px 15px; border: 2px solid #ecf0f1; border-radius: 6px; font-size: 14px; transition: all 0.3s; font-family: Arial, sans-serif; }
        input:focus, select:focus { outline: none; border-color: #16a085; box-shadow: 0 0 0 3px rgba(22,160,133,0.1); background: #f8fafb; }
        input::placeholder { color: #95a5a6; }
        .password-group { position: relative; }
        .password-toggle { position: absolute; right: 12px; top: 40px; background: none; border: none; color: #16a085; cursor: pointer; font-size: 12px; font-weight: 600; padding: 0; }
        .password-toggle:hover { color: #117a65; }
        .checkbox-group { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: #16a085; }
        .checkbox-group label { margin-bottom: 0; font-size: 13px; font-weight: 400; cursor: pointer; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; background: linear-gradient(135deg, #16a085, #117a65); color: white; transition: all 0.3s; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(22,160,133,0.3); }
        .alert { padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; display: none; }
        .alert.error { background: #fadbd8; color: #c0392b; border-left: 4px solid #c0392b; display: block; }
        .alert.success { background: #d5f4e6; color: #27ae60; border-left: 4px solid #27ae60; display: block; }
        .card-footer { text-align: center; padding: 20px; background: #f8fafb; border-top: 1px solid #ecf0f1; }
        .card-footer p { font-size: 13px; color: #7f8c8d; }
        .link { color: #16a085; text-decoration: none; font-weight: 600; cursor: pointer; font-size: 13px; }
        .link:hover { text-decoration: underline; }
        @media (max-width: 600px) { .card-header { padding: 30px 15px; } .card-body { padding: 25px 15px; } .role-selector { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="card-header">
            <div class="logo">CSMS<span class="logo-accent">Pro</span></div>
            <h2 id="card-title">Secure Login</h2>
            <p>Professional education management system</p>
        </div>

        <div class="card-body">
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab(event, 'login')">🔐 Login</button>
                <button class="tab-btn" onclick="switchTab(event, 'register')">📝 Student Register</button>
                <button class="tab-btn" onclick="switchTab(event, 'forgot')">🔑 Forgot Password</button>
            </div>

            <?php if($message): ?>
                <div class="alert <?= $message_type ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- LOGIN TAB -->
            <div id="login" class="tab-content active">
                <label style="display: block; text-align: center; font-size: 14px; margin-bottom: 20px; font-weight: 600;">Select Your Role:</label>
                
                <div class="role-selector">
                    <div class="role-option selected" onclick="selectRole(this, 'admin', 'login')">
                        <div class="role-icon">👨‍💼</div>
                        <div class="role-label">Admin</div>
                    </div>
                    <div class="role-option" onclick="selectRole(this, 'teacher', 'login')">
                        <div class="role-icon">👨‍🏫</div>
                        <div class="role-label">Teacher</div>
                    </div>
                    <div class="role-option" onclick="selectRole(this, 'student', 'login')">
                        <div class="role-icon">👨‍🎓</div>
                        <div class="role-label">Student</div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" id="login-role" name="role" value="admin">

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="Enter your email" required autocomplete="email">
                    </div>

                    <div class="form-group password-group">
                        <label>Password</label>
                        <input type="password" name="password" id="login-password" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="password-toggle" onclick="toggleLoginPassword()">Show</button>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div class="checkbox-group">
                            <input type="checkbox" id="remember" name="remember">
                            <label for="remember">Remember me</label>
                        </div>
                        <a class="link" onclick="switchTab(event, 'forgot'); return false;">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn">Continue</button>
                </form>

                <div style="text-align: center; margin-top: 15px;">
                    <p style="font-size: 12px; color: #7f8c8d; margin-bottom: 10px;">👉 New Student?</p>
                    <a class="link" onclick="switchTab(event, 'register'); return false;">Register as Student</a>
                </div>
            </div>

            <!-- REGISTER TAB -->
            <div id="register" class="tab-content">
                <label style="display: block; text-align: center; font-size: 14px; margin-bottom: 20px; font-weight: 600; color: #2c3e50;">Student Registration Only</label>
                
                <div class="role-selector">
                    <div class="role-option disabled">
                        <div class="role-icon">👨‍💼</div>
                        <div class="role-label">Admin</div>
                        <div class="role-note">Admin Only</div>
                    </div>
                    <div class="role-option disabled">
                        <div class="role-icon">👨‍🏫</div>
                        <div class="role-label">Teacher</div>
                        <div class="role-note">Admin Only</div>
                    </div>
                    <div class="role-option selected">
                        <div class="role-icon">👨‍🎓</div>
                        <div class="role-label">Student</div>
                        <div class="role-note">Self Register</div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="register_role" value="student">

                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="fullname" placeholder="Enter your full name" required>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="Enter your email" required autocomplete="email">
                    </div>

                    <div class="form-group">
                        <label>Registration Number</label>
                        <input type="text" name="reg_number" placeholder="Enter your registration number" required>
                    </div>

                    <div class="form-group">
                        <label>Select Course</label>
                        <select name="course_id" required>
                            <option value="">-- Select Your Course --</option>
                            <?php
                            $courses = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name");
                            if($courses){
                                while($course = $courses->fetch_assoc()){
                                    echo "<option value='{$course['course_id']}'>{$course['course_name']}</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group password-group">
                        <label>Password</label>
                        <input type="password" name="password" id="register-password" placeholder="Create a password (min 8 chars, mix of uppercase, lowercase, numbers, symbols)" required>
                        <button type="button" class="password-toggle" onclick="toggleRegisterPassword()">Show</button>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="Confirm your password" required>
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" id="agree" name="agree" required>
                        <label for="agree">I agree to the Terms and Conditions</label>
                    </div>

                    <button type="submit" class="btn">Create Account</button>
                </form>

                <div style="text-align: center; margin-top: 15px;">
                    <a class="link" onclick="switchTab(event, 'login'); return false;">Already have account? Login</a>
                </div>
            </div>

            <!-- FORGOT PASSWORD TAB -->
            <div id="forgot" class="tab-content">
                <label style="display: block; text-align: center; font-size: 14px; margin-bottom: 20px; font-weight: 600;">Reset Your Password</label>

                <form method="POST">
                    <input type="hidden" name="action" value="forgot-password">

                    <div class="form-group">
                        <label>Account Type</label>
                        <select name="forgot_type" required>
                            <option value="">-- Select Account Type --</option>
                            <option value="admin">Admin</option>
                            <option value="teacher">Teacher</option>
                            <option value="student">Student</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="forgot_email" placeholder="Enter your registered email" required>
                    </div>

                    <p style="font-size: 12px; color: #7f8c8d; margin-bottom: 20px;">
                        We'll send a verification code to your email. You'll use it to set a new password.
                    </p>

                    <button type="submit" class="btn">Send Verification Code</button>
                </form>

                <div style="text-align: center; margin-top: 15px;">
                    <a class="link" onclick="switchTab(event, 'login'); return false;">Back to Login</a>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <p>&copy; 2026 CSMS Pro. All rights reserved. | Secure Education Management</p>
        </div>
    </div>
</div>

<script>
    function switchTab(e, tabName) {
        if(e) e.preventDefault();
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById(tabName).classList.add('active');
        if(e && e.target) e.target.classList.add('active');
        const titles = { login: 'Secure Login', register: 'Student Registration', forgot: 'Reset Password' };
        document.getElementById('card-title').textContent = titles[tabName];
    }

    function selectRole(el, role, tab) {
        if(tab === 'login') {
            document.querySelectorAll('#login .role-option').forEach(e => e.classList.remove('selected'));
            el.classList.add('selected');
            document.getElementById('login-role').value = role;
        }
    }

    function toggleLoginPassword() {
        const p = document.getElementById('login-password');
        p.type = p.type === 'password' ? 'text' : 'password';
        event.target.textContent = p.type === 'text' ? 'Hide' : 'Show';
    }

    function toggleRegisterPassword() {
        const p = document.getElementById('register-password');
        p.type = p.type === 'password' ? 'text' : 'password';
        event.target.textContent = p.type === 'text' ? 'Hide' : 'Show';
    }
</script>

</body>
</html>