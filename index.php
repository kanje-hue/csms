<?php
session_start();
include 'config/db.php';

// Redirect if already logged in
if(isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true){
    header("Location: admin/manage_courses.php");
    exit();
} 

if(isset($_SESSION['teacher_logged_in']) && $_SESSION['teacher_logged_in'] === true){
    header("Location: teacher/dashboard.php");
    exit();
} 

if(isset($_SESSION['student_id']) && !empty($_SESSION['student_id'])){
    header("Location: student/dashboard.php");
    exit();
}

$message = '';
$message_type = '';

// HANDLE LOGIN
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login'){
    $role = trim($_POST['role'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if(empty($role) || empty($email) || empty($password)){
        $message = "❌ Please fill in all fields";
        $message_type = "error";
    } else {
        if($role === 'admin'){
            $stmt = $conn->prepare("SELECT admin_id, name, password FROM admins WHERE email = ? AND deleted = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows > 0){
                $admin = $result->fetch_assoc();
                if($admin['password'] === $password || password_verify($password, $admin['password'])){
                    session_regenerate_id(true);
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $admin['admin_id'];
                    $_SESSION['admin_name'] = $admin['name'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header("Location: admin/manage_courses.php", true, 302);
                    exit();
                } else {
                    $message = "❌ Invalid email or password";
                    $message_type = "error";
                }
            } else {
                $message = "❌ Invalid email or password";
                $message_type = "error";
            }
            $stmt->close();
        }
        elseif($role === 'teacher'){
            $stmt = $conn->prepare("SELECT teacher_id, fullname, password FROM teachers WHERE email = ? AND deleted = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows > 0){
                $teacher = $result->fetch_assoc();
                if($teacher['password'] === $password || password_verify($password, $teacher['password'])){
                    session_regenerate_id(true);
                    $_SESSION['teacher_logged_in'] = true;
                    $_SESSION['teacher_id'] = $teacher['teacher_id'];
                    $_SESSION['teacher_name'] = $teacher['fullname'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header("Location: teacher/dashboard.php", true, 302);
                    exit();
                } else {
                    $message = "❌ Invalid email or password";
                    $message_type = "error";
                }
            } else {
                $message = "❌ Invalid email or password";
                $message_type = "error";
            }
            $stmt->close();
        }
        elseif($role === 'student'){
            $stmt = $conn->prepare("SELECT student_id, name, course_id, year, password FROM students WHERE email = ? AND deleted = 0 AND status = 'active'");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows > 0){
                $student = $result->fetch_assoc();
                if($student['password'] === $password || password_verify($password, $student['password'])){
                    session_regenerate_id(true);
                    $_SESSION['student_id'] = $student['student_id'];
                    $_SESSION['student_name'] = $student['name'];
                    $_SESSION['course_id'] = $student['course_id'];
                    $_SESSION['year'] = $student['year'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header("Location: student/dashboard.php", true, 302);
                    exit();
                } else {
                    $message = "❌ Invalid email or password, or account not active";
                    $message_type = "error";
                }
            } else {
                $message = "❌ Invalid email or password, or account not active";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

// HANDLE REGISTRATION
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register'){
    $role = trim($_POST['register_role'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    if(empty($role) || empty($fullname) || empty($email) || empty($password) || empty($confirm_password)){
        $message = "❌ Please fill in all fields";
        $message_type = "error";
    } elseif($password !== $confirm_password){
        $message = "❌ Passwords do not match";
        $message_type = "error";
    } else {
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        if($role === 'admin'){
            $check = $conn->prepare("SELECT admin_id FROM admins WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            
            if($check->get_result()->num_rows > 0){
                $message = "❌ Email already registered";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO admins (name, email, password, status, deleted) VALUES (?, ?, ?, 'active', 0)");
                $stmt->bind_param("sss", $fullname, $email, $hashed_password);
                
                if($stmt->execute()){
                    $message = "✅ Admin account created successfully! You can now login.";
                    $message_type = "success";
                } else {
                    $message = "❌ Error creating account";
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check->close();
        }
        elseif($role === 'teacher'){
            $check = $conn->prepare("SELECT teacher_id FROM teachers WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            
            if($check->get_result()->num_rows > 0){
                $message = "❌ Email already registered";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO teachers (fullname, email, password, status, deleted) VALUES (?, ?, ?, 'active', 0)");
                $stmt->bind_param("sss", $fullname, $email, $hashed_password);
                
                if($stmt->execute()){
                    $message = "✅ Teacher account created successfully! You can now login.";
                    $message_type = "success";
                } else {
                    $message = "❌ Error creating account";
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check->close();
        }
        elseif($role === 'student'){
            $reg_number = trim($_POST['reg_number'] ?? '');
            $course_id = safe_int($_POST['course_id'] ?? 0);
            
            if(empty($reg_number) || !$course_id){
                $message = "❌ Please fill in registration number and select a course";
                $message_type = "error";
            } else {
                $check = $conn->prepare("SELECT student_id FROM students WHERE email = ?");
                $check->bind_param("s", $email);
                $check->execute();
                
                if($check->get_result()->num_rows > 0){
                    $message = "❌ Email already registered";
                    $message_type = "error";
                } else {
                    $stmt = $conn->prepare("INSERT INTO students (reg_number, name, email, password, course_id, year, status, deleted) VALUES (?, ?, ?, ?, ?, 1, 'pending', 0)");
                    $stmt->bind_param("ssssi", $reg_number, $fullname, $email, $hashed_password, $course_id);
                    
                    if($stmt->execute()){
                        $message = "✅ Student account created successfully! Awaiting admin approval.";
                        $message_type = "success";
                    } else {
                        $message = "❌ Error creating account";
                        $message_type = "error";
                    }
                    $stmt->close();
                }
                $check->close();
            }
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
    <title>CSMS - Unified Login & Registration</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 600px;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            color: white;
            padding: 40px 20px;
            text-align: center;
        }

        .logo {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 10px;
            letter-spacing: 2px;
        }

        .logo-accent {
            color: #16a085;
        }

        .card-header h2 {
            font-size: 24px;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .card-header p {
            font-size: 13px;
            opacity: 0.9;
            line-height: 1.6;
            color: #bdc3c7;
        }

        .card-body {
            padding: 40px 30px;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid #ecf0f1;
            margin-bottom: 30px;
        }

        .tab-btn {
            flex: 1;
            padding: 15px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #95a5a6;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }

        .tab-btn:hover {
            color: #16a085;
            background: #ecf0f1;
        }

        .tab-btn.active {
            color: #16a085;
            border-bottom-color: #16a085;
            background: #f8fafb;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .role-selector {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 30px;
        }

        .role-option {
            padding: 20px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
        }

        .role-option:hover {
            border-color: #16a085;
            background: #f8fafb;
        }

        .role-option.selected {
            border-color: #16a085;
            background: #e8f8f5;
            box-shadow: 0 4px 12px rgba(22, 160, 133, 0.2);
        }

        .role-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }

        .role-label {
            font-size: 12px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ecf0f1;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s;
            font-family: inherit;
        }

        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: #16a085;
            box-shadow: 0 0 0 3px rgba(22, 160, 133, 0.1);
            background: #f8fafb;
        }

        input::placeholder {
            color: #95a5a6;
        }

        .password-group {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 40px;
            background: none;
            border: none;
            color: #16a085;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            padding: 0;
        }

        .password-toggle:hover {
            color: #117a65;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #16a085;
        }

        .checkbox-group label {
            margin-bottom: 0;
            font-size: 13px;
            font-weight: 400;
            cursor: pointer;
        }

        .forgot-password {
            text-align: right;
            margin-bottom: 20px;
        }

        .forgot-password a {
            font-size: 12px;
            color: #16a085;
            text-decoration: none;
            font-weight: 600;
        }

        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #16a085, #117a65);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(22, 160, 133, 0.3);
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
            display: none;
        }

        .alert.error {
            background: #fadbd8;
            color: #c0392b;
            border-left: 4px solid #c0392b;
            display: block;
        }

        .alert.success {
            background: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
            display: block;
        }

        .card-footer {
            text-align: center;
            padding: 20px;
            background: #f8fafb;
            border-top: 1px solid #ecf0f1;
        }

        .card-footer p {
            font-size: 13px;
            color: #7f8c8d;
        }

        .card-footer a {
            color: #16a085;
            text-decoration: none;
            font-weight: 600;
        }

        .card-footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .card-header {
                padding: 30px 15px;
            }

            .card-body {
                padding: 25px 15px;
            }

            .role-selector {
                grid-template-columns: 1fr;
            }

            .logo {
                font-size: 28px;
            }

            .card-header h2 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <!-- Header -->
        <div class="card-header">
            <div class="logo">
                CSMS<span class="logo-accent">Pro</span>
            </div>
            <h2 id="card-title">Login to Your Account</h2>
            <p>Manage your accounts and access insightful reports and technical analysis among many more features.</p>
        </div>

        <!-- Body -->
        <div class="card-body">

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab(event, 'login')">
                    🔐 Login
                </button>
                <button class="tab-btn" onclick="switchTab(event, 'register')">
                    📝 Register
                </button>
            </div>

            <?php if($message): ?>
                <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- LOGIN TAB -->
            <div id="login" class="tab-content active">
                
                <label style="display: block; text-align: center; font-size: 14px; margin-bottom: 20px; font-weight: 600; color: #2c3e50;">Select Your Role:</label>
                
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
                        <input type="email" name="email" placeholder="Enter your email" required>
                    </div>

                    <div class="form-group password-group">
                        <label>Password</label>
                        <input type="password" name="password" id="login-password" placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" onclick="toggleLoginPassword()">Show</button>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div class="checkbox-group">
                            <input type="checkbox" id="remember" name="remember">
                            <label for="remember">Remember me</label>
                        </div>
                        <a href="#" class="forgot-password">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary">Continue</button>
                </form>

                <!-- LOGIN PROMPT -->
                <div style="text-align: center; margin-top: 15px;">
                    <a href="#" onclick="switchTab(event, 'register'); return false;" style="color: #16a085; font-size: 13px; font-weight: 600;">
                        Don't have an account? Create one
                    </a>
                </div>

            </div>

            <!-- REGISTER TAB -->
            <div id="register" class="tab-content">
                
                <label style="display: block; text-align: center; font-size: 14px; margin-bottom: 20px; font-weight: 600; color: #2c3e50;">Select Your Role:</label>
                
                <div class="role-selector">
                    <div class="role-option" onclick="selectRole(this, 'admin', 'register')">
                        <div class="role-icon">👨‍💼</div>
                        <div class="role-label">Admin</div>
                    </div>
                    <div class="role-option" onclick="selectRole(this, 'teacher', 'register')">
                        <div class="role-icon">👨‍🏫</div>
                        <div class="role-label">Teacher</div>
                    </div>
                    <div class="role-option selected" onclick="selectRole(this, 'student', 'register')">
                        <div class="role-icon">👨‍🎓</div>
                        <div class="role-label">Student</div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" id="register-role" name="register_role" value="student">

                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="fullname" placeholder="Enter your full name" required>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="Enter your email" required>
                    </div>

                    <div class="form-group" id="student-reg-fields">
                        <label>Registration Number</label>
                        <input type="text" name="reg_number" placeholder="Enter registration number">
                    </div>

                    <div class="form-group" id="student-course-fields">
                        <label>Select Course</label>
                        <select name="course_id">
                            <option value="">-- Select Course --</option>
                            <?php
                            $courses = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name");
                            while($course = $courses->fetch_assoc()){
                                echo "<option value='{$course['course_id']}'>{$course['course_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group password-group">
                        <label>Password</label>
                        <input type="password" name="password" id="register-password" placeholder="Create a password" required>
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

                    <button type="submit" class="btn btn-primary">Create Account</button>
                </form>

                <!-- REGISTER PROMPT -->
                <div style="text-align: center; margin-top: 15px;">
                    <a href="#" onclick="switchTab(event, 'login'); return false;" style="color: #16a085; font-size: 13px; font-weight: 600;">
                        Already have an account? Login here
                    </a>
                </div>

            </div>

        </div>

        <!-- Footer -->
        <div class="card-footer">
            <p>&copy; 2026 CSMS Pro. All rights reserved.</p>
        </div>
    </div>
</div>

<script>
    function switchTab(e, tabName) {
        if(e) e.preventDefault();
        
        const tabs = document.querySelectorAll('.tab-content');
        tabs.forEach(tab => tab.classList.remove('active'));
        
        const buttons = document.querySelectorAll('.tab-btn');
        buttons.forEach(btn => btn.classList.remove('active'));
        
        document.getElementById(tabName).classList.add('active');
        event.target.classList.add('active');
        
        const title = tabName === 'login' ? 'Login to Your Account' : 'Create Your Account';
        document.getElementById('card-title').textContent = title;
    }

    function selectRole(element, role, tab) {
        const selector = tab === 'login' ? '#login' : '#register';
        document.querySelectorAll(selector + ' .role-selector .role-option').forEach(el => {
            el.classList.remove('selected');
        });
        element.classList.add('selected');
        
        if(tab === 'login'){
            document.getElementById('login-role').value = role;
        } else {
            document.getElementById('register-role').value = role;
            
            const studentRegFields = document.getElementById('student-reg-fields');
            const studentCourseFields = document.getElementById('student-course-fields');
            
            if(role === 'student'){
                studentRegFields.style.display = 'block';
                studentCourseFields.style.display = 'block';
                document.querySelector('input[name="reg_number"]').required = true;
                document.querySelector('select[name="course_id"]').required = true;
            } else {
                studentRegFields.style.display = 'none';
                studentCourseFields.style.display = 'none';
                document.querySelector('input[name="reg_number"]').required = false;
                document.querySelector('select[name="course_id"]').required = false;
            }
        }
    }

    function toggleLoginPassword() {
        const passwordField = document.getElementById('login-password');
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            event.target.textContent = 'Hide';
        } else {
            passwordField.type = 'password';
            event.target.textContent = 'Show';
        }
    }

    function toggleRegisterPassword() {
        const passwordField = document.getElementById('register-password');
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            event.target.textContent = 'Hide';
        } else {
            passwordField.type = 'password';
            event.target.textContent = 'Show';
        }
    }
</script>

</body>
</html>