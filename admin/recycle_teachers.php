<?php
session_start();

// If already logged in, redirect to appropriate dashboard
if(isset($_SESSION['admin_logged_in'])){
    header("Location: admin/manage_courses.php");
    exit();
} elseif(isset($_SESSION['teacher_logged_in'])){
    header("Location: teacher/dashboard.php");
    exit();
} elseif(isset($_SESSION['student_id'])){
    header("Location: student/dashboard.php");
    exit();
}

// Determine current tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'login';

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSMS - Course Student Management System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen',
                'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif;
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

        .logo-text {
            color: white;
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

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid #ecf0f1;
            margin-bottom: 30px;
            gap: 0;
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

        /* Role Selection */
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

        .hidden-role {
            display: none;
        }

        /* Form Groups */
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

        /* Checkbox */
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

        /* Links */
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

        .forgot-password a:hover {
            text-decoration: underline;
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #16a085, #117a65);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(22, 160, 133, 0.3);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: #ecf0f1;
            color: #2c3e50;
            margin-top: 10px;
        }

        .btn-secondary:hover {
            background: #d5dbdb;
        }

        /* Footer Links */
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

        .toggle-link {
            margin-top: 15px;
        }

        .toggle-link a {
            cursor: pointer;
        }

        /* Alert Messages */
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

        /* Responsive */
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
                <span class="logo-text">CSMS</span><span class="logo-accent">Pro</span>
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

            <!-- LOGIN TAB -->
            <div id="login" class="tab-content active">
                
                <!-- Role Selection -->
                <label style="display: block; text-align: center; font-size: 14px; margin-bottom: 20px; font-weight: 600; color: #2c3e50;">Select Your Role:</label>
                
                <div class="role-selector">
                    <div class="role-option selected" onclick="selectRole(this, 'admin')">
                        <div class="role-icon">👨‍💼</div>
                        <div class="role-label">Admin</div>
                    </div>
                    <div class="role-option" onclick="selectRole(this, 'teacher')">
                        <div class="role-icon">👨‍🏫</div>
                        <div class="role-label">Teacher</div>
                    </div>
                    <div class="role-option" onclick="selectRole(this, 'student')">
                        <div class="role-icon">👨‍🎓</div>
                        <div class="role-label">Student</div>
                    </div>
                </div>

                <!-- Login Form -->
                <form id="login-form" method="POST" action="">
                    <input type="hidden" id="selected-role" name="role" value="admin">
                    
                    <div id="alert-container"></div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="Enter your email" required>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" placeholder="Enter your password" required>
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

                <div style="text-align: center; margin-top: 15px;">
                    <a href="#" onclick="switchTab(event, 'register'); return false;" style="color: #16a085; font-size: 13px; font-weight: 600;">
                        Don't have an account? Create one
                    </a>
                </div>

            </div>

            <!-- REGISTER TAB -->
            <div id="register" class="tab-content">
                
                <!-- Role Selection for Registration -->
                <label style="display: block; text-align: center; font-size: 14px; margin-bottom: 20px; font-weight: 600; color: #2c3e50;">Select Your Role:</label>
                
                <div class="role-selector">
                    <div class="role-option" onclick="selectRegisterRole(this, 'admin')">
                        <div class="role-icon">👨‍💼</div>
                        <div class="role-label">Admin</div>
                    </div>
                    <div class="role-option" onclick="selectRegisterRole(this, 'teacher')">
                        <div class="role-icon">👨‍🏫</div>
                        <div class="role-label">Teacher</div>
                    </div>
                    <div class="role-option selected" onclick="selectRegisterRole(this, 'student')">
                        <div class="role-icon">👨‍🎓</div>
                        <div class="role-label">Student</div>
                    </div>
                </div>

                <!-- Registration Form -->
                <form id="register-form" method="POST" action="">
                    <input type="hidden" id="register-role" name="register_role" value="student">
                    
                    <div id="register-alert"></div>

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
                            include 'config/db.php';
                            $courses = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name");
                            while($course = $courses->fetch_assoc()){
                                echo "<option value='{$course['course_id']}'>{$course['course_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" placeholder="Create a password" required>
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
        
        // Hide all tabs
        const tabs = document.querySelectorAll('.tab-content');
        tabs.forEach(tab => tab.classList.remove('active'));
        
        // Remove active from all buttons
        const buttons = document.querySelectorAll('.tab-btn');
        buttons.forEach(btn => btn.classList.remove('active'));
        
        // Show selected tab
        document.getElementById(tabName).classList.add('active');
        
        // Add active to clicked button
        event.target.classList.add('active');
        
        // Update title
        const title = tabName === 'login' ? 'Login to Your Account' : 'Create Your Account';
        document.getElementById('card-title').textContent = title;
    }

    function selectRole(element, role) {
        // Remove selected from all
        document.querySelectorAll('.role-selector .role-option').forEach(el => {
            el.classList.remove('selected');
        });
        
        // Add selected to clicked
        element.classList.add('selected');
        
        // Update hidden input
        document.getElementById('selected-role').value = role;
    }

    function selectRegisterRole(element, role) {
        // Remove selected from all
        document.querySelectorAll('#register .role-selector .role-option').forEach(el => {
            el.classList.remove('selected');
        });
        
        // Add selected to clicked
        element.classList.add('selected');
        
        // Update hidden input
        document.getElementById('register-role').value = role;
        
        // Show/hide student-specific fields
        const studentFields = document.getElementById('student-reg-fields');
        const courseFields = document.getElementById('student-course-fields');
        
        if(role === 'student') {
            studentFields.style.display = 'block';
            courseFields.style.display = 'block';
            document.querySelector('input[name="reg_number"]').required = true;
            document.querySelector('select[name="course_id"]').required = true;
        } else {
            studentFields.style.display = 'none';
            courseFields.style.display = 'none';
            document.querySelector('input[name="reg_number"]').required = false;
            document.querySelector('select[name="course_id"]').required = false;
        }
    }

    // Form submission - redirect to appropriate login handler
    document.getElementById('login-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const role = document.getElementById('selected-role').value;
        const formData = new FormData(this);
        
        // Redirect to appropriate login page
        const loginPages = {
            'admin': 'admin/login.php',
            'teacher': 'teacher/login.php',
            'student': 'student/login.php'
        };
        
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = loginPages[role];
        
        for(let [key, value] of formData) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        }
        
        document.body.appendChild(form);
        form.submit();
    });

    document.getElementById('register-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const role = document.getElementById('register-role').value;
        const formData = new FormData(this);
        
        // Redirect to appropriate registration page
        const registerPages = {
            'admin': 'admin/register.php',
            'teacher': 'teacher/register.php',
            'student': 'student/register.php'
        };
        
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = registerPages[role];
        
        for(let [key, value] of formData) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        }
        
        document.body.appendChild(form);
        form.submit();
    });
</script>

</body>
</html>