<?php
session_start();
include '../config/db.php';

$message = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if(empty($email) || empty($password)){
        $message = "❌ Please fill in all fields";
    } else {
        $stmt = $conn->prepare("SELECT admin_id, name, email FROM admins WHERE email = ? AND password = ? AND deleted = 0");
        $stmt->bind_param("ss", $email, $password);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if($result->num_rows > 0){
            $admin = $result->fetch_assoc();
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            header("Location: manage_courses.php");
            exit();
        } else {
            $message = "❌ Invalid email or password";
        }
        $stmt->close();
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Login - CSMS</title>
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
    <h2>🏢 Admin Login</h2>
    <p class="subtitle">Manage courses, modules, and students</p>

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
            <input type="password" name="password" id="admin-password" placeholder="Enter your password" required>
            <button type="button" class="password-toggle" onclick="toggleAdminPassword()">Show</button>
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
    function toggleAdminPassword() {
        const passwordField = document.getElementById('admin-password');
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