<?php
session_start();
include '../config/db.php';
require_once '../config/security.php';

$security = new SecurityManager($conn);
$message  = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if(empty($email) || empty($password)){
        $message = "❌ Please fill in all fields";
    } else {
        $stmt = $conn->prepare(
            "SELECT student_id, name, email, password, course_id, year, force_password_change, locked_until, failed_login_attempts
             FROM students WHERE email = ? AND deleted = 0 AND status = 'active'"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$student) {
            $security->logLoginAttempt('students', null, $email, 'failed');
            $message = "❌ Invalid email or password, or account not active";
        } elseif ($security->isAccountLocked('students', $student['student_id'])) {
            $security->logLoginAttempt('students', $student['student_id'], $email, 'locked');
            $message = "🔒 Account locked due to too many failed attempts. Please try again later.";
        } elseif (!$security->verifyPassword($password, $student['password'])) {
            $security->recordFailedLogin('students', $student['student_id']);
            $security->logLoginAttempt('students', $student['student_id'], $email, 'failed');
            $remaining = max(0, SecurityManager::MAX_LOGIN_ATTEMPTS - ($student['failed_login_attempts'] + 1));
            $message   = "❌ Invalid email or password, or account not active. $remaining attempt(s) remaining.";
        } else {
            $security->resetLoginAttempts('students', $student['student_id']);
            $security->logLoginAttempt('students', $student['student_id'], $email, 'success');

            $_SESSION['student_id']   = $student['student_id'];
            $_SESSION['student_name'] = $student['name'];
            $_SESSION['course_id']    = $student['course_id'];
            $_SESSION['year']         = $student['year'];
            $_SESSION['user_role']    = 'student';
            $_SESSION['csrf_token']   = bin2hex(random_bytes(32));

            if ($student['force_password_change']) {
                $_SESSION['force_change_role'] = 'student';
                $_SESSION['force_change_id']   = $student['student_id'];
                header("Location: ../public/change_password.php");
                exit();
            }

            header("Location: dashboard.php");
            exit();
        }
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Login - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .auth-card {
            background: white;
            width: 100%;
            max-width: 500px;
            padding: 40px 30px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        h2 {
            text-align: center;
            color: #1a1a2e;
            margin-bottom: 10px;
            font-size: 24px;
        }

        .subtitle {
            text-align: center;
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 13px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ecf0f1;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s;
        }

        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #16a085;
            box-shadow: 0 0 0 3px rgba(22, 160, 133, 0.1);
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

        .checkbox-group {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .checkbox-group a {
            color: #16a085;
            text-decoration: none;
            font-weight: 600;
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #16a085, #117a65);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(22, 160, 133, 0.3);
        }

        .message {
            background: #fadbd8;
            color: #c0392b;
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
            border-left: 4px solid #c0392b;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #16a085;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
        }

        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>👨‍🎓 Student Login</h2>
    <p class="subtitle">View your courses, modules, and results</p>

    <?php if($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="Enter your email" required>
        </div>

        <div class="form-group password-group">
            <label>Password</label>
            <input type="password" name="password" id="student-password" placeholder="Enter your password" required>
            <button type="button" class="password-toggle" onclick="toggleStudentPassword()">Show</button>
        </div>

        <div class="checkbox-group">
            <label style="margin-bottom: 0;">
                <input type="checkbox" name="remember"> Remember me
            </label>
            <a href="#">Forgot password?</a>
        </div>

        <button type="submit">Continue</button>
    </form>

    <div class="back-link">
        <a href="../index.php">← Back to Login Page</a>
    </div>
</div>

<script>
    function toggleStudentPassword() {
        const passwordField = document.getElementById('student-password');
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