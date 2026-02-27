<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/email_config.php';

$security = new SecurityManager($conn);

$action  = $_GET['action'] ?? 'login';
$message = '';
$msgType = 'error';

function redirectByRole($role) {
    $map = [
        'admin'   => '../admin/manage_courses.php',
        'teacher' => '../teacher/dashboard.php',
        'student' => '../student/dashboard.php',
    ];
    header('Location: ' . ($map[$role] ?? '../'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? 'login';

    if ($postAction === 'login') {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? '';

        $validRoles = ['admin' => 'admins', 'teacher' => 'teachers', 'student' => 'students'];
        if (!$email || !$password || !isset($validRoles[$role])) {
            $message = '❌ Please fill in all fields and select a role.';
        } else {
            $table = $validRoles[$role];
            $idCol = ($role === 'admin') ? 'admin_id' : (($role === 'teacher') ? 'teacher_id' : 'student_id');
            $nameCol = ($role === 'admin') ? 'name' : (($role === 'teacher') ? 'fullname' : 'name');

            $deletedCond = ($role === 'student') ? "AND deleted = 0 AND status = 'active'" : "AND deleted = 0";
            $stmt = $conn->prepare(
                "SELECT $idCol, $nameCol, email, password, force_password_change, locked_until, failed_login_attempts
                 FROM `$table` WHERE email = ? $deletedCond"
            );
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                $security->logLoginAttempt($table, null, $email, 'failed');
                $message = '❌ Invalid email or password.';
            } elseif ($security->isAccountLocked($table, $user[$idCol])) {
                $security->logLoginAttempt($table, $user[$idCol], $email, 'locked');
                $lockedUntil = date('H:i', strtotime($user['locked_until']));
                $message = "🔒 Account locked due to too many failed attempts. Try again after $lockedUntil.";
            } elseif (!$security->verifyPassword($password, $user['password'])) {
                $security->recordFailedLogin($table, $user[$idCol]);
                $security->logLoginAttempt($table, $user[$idCol], $email, 'failed');
                $remaining = max(0, SecurityManager::MAX_LOGIN_ATTEMPTS - ($user['failed_login_attempts'] + 1));
                $message   = "❌ Invalid email or password. $remaining attempt(s) remaining before lockout.";
            } else {
                $security->resetLoginAttempts($table, $user[$idCol]);
                $security->logLoginAttempt($table, $user[$idCol], $email, 'success');

                $_SESSION[$role . '_logged_in'] = true;
                $_SESSION[$role . '_id']         = $user[$idCol];
                $_SESSION[$role . '_name']       = $user[$nameCol];
                $_SESSION['user_role']           = $role;
                $_SESSION['csrf_token']          = bin2hex(random_bytes(32));

                if ($user['force_password_change']) {
                    $_SESSION['force_change_role']  = $role;
                    $_SESSION['force_change_id']    = $user[$idCol];
                    header('Location: change_password.php');
                    exit();
                }

                redirectByRole($role);
            }
        }
    }

    elseif ($postAction === 'register') {
        $regNumber = trim($_POST['reg_number'] ?? '');
        $name      = trim($_POST['name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $courseId  = (int)($_POST['course_id'] ?? 0);
        $year      = (int)($_POST['year'] ?? 0);
        $semester  = (int)($_POST['semester'] ?? 0);
        $password  = $_POST['password'] ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';

        $pwCheck = $security->validatePasswordStrength($password);

        if (!$regNumber || !$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$courseId || !$year || !$semester) {
            $message = '❌ Please fill in all required fields.';
            $action  = 'register';
        } elseif ($password !== $confirm) {
            $message = '❌ Passwords do not match.';
            $action  = 'register';
        } elseif (!$pwCheck['valid']) {
            $message = '❌ ' . $pwCheck['message'];
            $action  = 'register';
        } else {
            $chk = $conn->prepare("SELECT student_id FROM students WHERE email = ? AND deleted = 0");
            $chk->bind_param("s", $email);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();

            if ($exists) {
                $message = '❌ Email already registered.';
                $action  = 'register';
            } else {
                $hash = $security->hashPassword($password);
                $ins  = $conn->prepare(
                    "INSERT INTO students (reg_number, name, email, password, course_id, year, semester, status, deleted, password_changed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW())"
                );
                $ins->bind_param("ssssiis", $regNumber, $name, $email, $hash, $courseId, $year, $semester);
                if ($ins->execute()) {
                    $message = '✅ Registration successful! Please wait for admin approval.';
                    $msgType = 'success';
                } else {
                    $message = '❌ Registration failed. Please try again.';
                }
                $ins->close();
                $action = 'register';
            }
        }
    }

    elseif ($postAction === 'forgot') {
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? '';

        $validRoles = ['admin' => 'admins', 'teacher' => 'teachers', 'student' => 'students'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($validRoles[$role])) {
            $message = '❌ Please provide a valid email and role.';
            $action  = 'forgot';
        } else {
            $table = $validRoles[$role];
            $idCol = ($role === 'admin') ? 'admin_id' : (($role === 'teacher') ? 'teacher_id' : 'student_id');

            $stmt = $conn->prepare("SELECT $idCol FROM `$table` WHERE email = ? AND deleted = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user) {
                $code = $security->generateVerificationCode($table, $user[$idCol], $email);
                if ($code) {
                    $subject = 'CSMS Password Reset Code';
                    $body    = "<p>Your password reset verification code is: <strong>$code</strong></p>"
                             . "<p>This code expires in 1 hour.</p>";
                    send_email($email, ucfirst($role), $subject, $body);
                }
            }
            $message = '✅ If that email is registered, a verification code has been sent.';
            $msgType = 'success';
            $action  = 'verify_code';
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_role']  = $role;
        }
    }

    elseif ($postAction === 'verify_code') {
        error_log("=== VERIFY CODE START ===");
        
        $email = $_SESSION['reset_email'] ?? '';
        $code  = trim($_POST['code'] ?? '');

        error_log("Email: $email, Code: $code");
        
        $row = $security->verifyResetCode($email, $code);
        
        error_log("Query result: " . print_r($row, true));
        
        if (!$row) {
            error_log("Invalid or expired code");
            $message = '❌ Invalid or expired verification code.';
            $action  = 'verify_code';
        } else {
            error_log("Code verified! Setting session variables:");
            error_log("  id: {$row['id']}");
            error_log("  user_type: {$row['user_type']}");
            error_log("  user_id: {$row['user_id']}");
            
            $_SESSION['reset_token_id']    = $row['id'];
            $_SESSION['reset_user_table']  = $row['user_type'];
            $_SESSION['reset_user_id']     = $row['user_id'];
            $_SESSION['reset_email']       = $email;
            
            error_log("Session set. Current session: " . print_r($_SESSION, true));
            
            $message = '✅ Code verified! Please create your new password.';
            $msgType = 'success';
            $action  = 'reset';
        }
        error_log("=== VERIFY CODE END ===");
    }

    elseif ($postAction === 'reset') {
        error_log("=== RESET PASSWORD START ===");
        error_log("Current session at start of reset: " . print_r($_SESSION, true));
        
        $tokenId  = $_SESSION['reset_token_id']   ?? null;
        $table    = $_SESSION['reset_user_table'] ?? null;
        $userId   = $_SESSION['reset_user_id']    ?? null;
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        error_log("tokenId: $tokenId, table: $table, userId: $userId");
        
        if (!$tokenId || !$table || !$userId) {
            error_log("ERROR: Missing session variables!");
            error_log("  tokenId is " . ($tokenId ? "SET to: $tokenId" : "NULL"));
            error_log("  table is " . ($table ? "SET to: $table" : "NULL"));
            error_log("  userId is " . ($userId ? "SET to: $userId" : "NULL"));
            
            $message = '❌ Invalid reset session. Please start over.';
            $action  = 'forgot';
            unset($_SESSION['reset_token_id'], $_SESSION['reset_user_table'],
                  $_SESSION['reset_user_id'], $_SESSION['reset_email'], $_SESSION['reset_role']);
        } elseif (!$password || !$confirm) {
            error_log("ERROR: Missing password fields");
            $message = '❌ Please fill in all password fields.';
            $action  = 'reset';
        } elseif ($password !== $confirm) {
            error_log("ERROR: Passwords don't match");
            $message = '❌ Passwords do not match.';
            $action  = 'reset';
        } else {
            $pwCheck = $security->validatePasswordStrength($password);
            if (!$pwCheck['valid']) {
                error_log("ERROR: Password strength check failed: " . $pwCheck['message']);
                $message = '❌ ' . $pwCheck['message'];
                $action  = 'reset';
            } elseif ($security->isPasswordReused($table, $userId, $password)) {
                error_log("ERROR: Password reused");
                $message = '❌ You cannot reuse one of your last ' . SecurityManager::PASSWORD_HISTORY . ' passwords.';
                $action  = 'reset';
            } else {
                error_log("Updating password for table: $table, userId: $userId");
                $security->updatePassword($table, $userId, $password, true);
                $security->markTokenUsed($tokenId);
                unset($_SESSION['reset_token_id'], $_SESSION['reset_user_table'],
                      $_SESSION['reset_user_id'], $_SESSION['reset_email'], $_SESSION['reset_role']);
                error_log("Password updated successfully!");
                $message = '✅ Password changed successfully. Please log in.';
                $msgType = 'success';
                $action  = 'login';
            }
        }
        error_log("=== RESET PASSWORD END ===");
    }
}

$courses = [];
if ($action === 'register') {
    $cRes = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name");
    if ($cRes) {
        while ($c = $cRes->fetch_assoc()) {
            $courses[] = $c;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSMS – Login</title>
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
            background: white;
            width: 100%;
            max-width: 480px;
            border-radius: 16px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.4);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #16213e, #0f3460);
            color: white;
            text-align: center;
            padding: 30px 20px 20px;
        }

        .card-header h1 { font-size: 26px; margin-bottom: 6px; }
        .card-header p  { font-size: 13px; opacity: 0.8; }

        .tab-nav {
            display: flex;
            background: #f0f4f8;
            border-bottom: 1px solid #dde3eb;
        }

        .tab-btn {
            flex: 1;
            padding: 13px 8px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: #7f8c8d;
            transition: all 0.2s;
        }

        .tab-btn.active {
            background: white;
            color: #0f3460;
            border-bottom: 3px solid #0f3460;
        }

        .card-body { padding: 30px 25px; }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13px;
            font-weight: 500;
        }

        .alert-error   { background: #fdecea; color: #c0392b; border-left: 4px solid #c0392b; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #27ae60; }

        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 7px;
        }

        input[type="text"], input[type="email"], input[type="password"], select {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid #dde3eb;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
            background: white;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #0f3460;
        }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        .role-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .role-btn {
            border: 2px solid #dde3eb;
            border-radius: 10px;
            padding: 14px 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
        }

        .role-btn:hover  { border-color: #0f3460; background: #f0f4f8; }
        .role-btn.active { border-color: #0f3460; background: #e8eef8; }

        .role-btn .icon { font-size: 24px; display: block; margin-bottom: 6px; }
        .role-btn span  { font-size: 12px; font-weight: 600; color: #2c3e50; display: block; }

        .btn {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #0f3460, #16213e);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: opacity 0.2s;
            margin-top: 4px;
        }

        .btn:hover { opacity: 0.9; }

        .link-row {
            text-align: center;
            margin-top: 16px;
            font-size: 13px;
            color: #7f8c8d;
        }

        .link-row a { color: #0f3460; text-decoration: none; font-weight: 600; }
        .link-row a:hover { text-decoration: underline; }

        .pw-hint {
            font-size: 11px;
            color: #95a5a6;
            margin-top: 5px;
        }

        .pw-wrap { position: relative; }

        .pw-toggle {
            position: absolute;
            right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: #7f8c8d; cursor: pointer;
            font-size: 12px; font-weight: 600;
        }

        @media (max-width: 480px) {
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h1>🎓 CSMS</h1>
        <p>Course & Student Management System</p>
    </div>

    <?php if (!in_array($action, ['forgot', 'verify_code', 'reset', 'register'])): ?>
    <div class="tab-nav">
        <button class="tab-btn <?= $action === 'login' ? 'active' : '' ?>"
                onclick="location.href='?action=login'">Login</button>
        <button class="tab-btn <?= $action === 'register' ? 'active' : '' ?>"
                onclick="location.href='?action=register'">Student Register</button>
    </div>
    <?php endif; ?>

    <div class="card-body">

        <?php if ($message): ?>
            <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($action === 'login'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="login">

            <p style="font-size:13px;color:#555;margin-bottom:16px;text-align:center;">
                Select your role to continue
            </p>

            <div class="role-grid" id="roleGrid">
                <div class="role-btn" id="role-admin" onclick="selectRole('admin')">
                    <span class="icon">🏢</span>
                    <span>Admin</span>
                </div>
                <div class="role-btn" id="role-teacher" onclick="selectRole('teacher')">
                    <span class="icon">👨‍🏫</span>
                    <span>Teacher</span>
                </div>
                <div class="role-btn" id="role-student" onclick="selectRole('student')">
                    <span class="icon">👨‍🎓</span>
                    <span>Student</span>
                </div>
            </div>
            <input type="hidden" name="role" id="roleInput" value="">

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Enter your email" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <div class="pw-wrap">
                    <input type="password" name="password" id="loginPw" placeholder="Enter your password" required>
                    <button type="button" class="pw-toggle" onclick="togglePw('loginPw',this)">Show</button>
                </div>
            </div>

            <button type="submit" class="btn">Sign In →</button>

            <div class="link-row">
                <a href="?action=forgot">Forgot password?</a>
            </div>
        </form>

        <?php elseif ($action === 'register'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="register">

            <div class="form-group">
                <label>Registration Number *</label>
                <input type="text" name="reg_number" placeholder="e.g. STU001"
                       value="<?= htmlspecialchars($_POST['reg_number'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="name" placeholder="Full name"
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" placeholder="student@example.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Course *</label>
                    <select name="course_id" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['course_id'] ?>"
                                <?= (isset($_POST['course_id']) && $_POST['course_id'] == $c['course_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Year *</label>
                    <select name="year" required>
                        <option value="">-- Select --</option>
                        <?php for ($y = 1; $y <= 4; $y++): ?>
                            <option value="<?= $y ?>"
                                <?= (isset($_POST['year']) && $_POST['year'] == $y) ? 'selected' : '' ?>>
                                Year <?= $y ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Semester *</label>
                <select name="semester" required>
                    <option value="">-- Select --</option>
                    <option value="1" <?= (isset($_POST['semester']) && $_POST['semester'] == 1) ? 'selected' : '' ?>>Semester 1</option>
                    <option value="2" <?= (isset($_POST['semester']) && $_POST['semester'] == 2) ? 'selected' : '' ?>>Semester 2</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Password *</label>
                    <div class="pw-wrap">
                        <input type="password" name="password" id="regPw" placeholder="Min 8 chars" required>
                        <button type="button" class="pw-toggle" onclick="togglePw('regPw',this)">Show</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" placeholder="Repeat password" required>
                </div>
            </div>
            <p class="pw-hint">Password must be 8+ chars with uppercase, lowercase, number &amp; special character.</p>

            <button type="submit" class="btn" style="margin-top:14px;">Register →</button>

            <div class="link-row">
                Already have an account? <a href="?action=login">Login here</a>
            </div>
        </form>

        <?php elseif ($action === 'forgot'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="forgot">

            <p style="font-size:13px;color:#555;margin-bottom:18px;">
                Enter your email and select your role to receive a verification code.
            </p>

            <div class="form-group">
                <label>Role</label>
                <select name="role" required>
                    <option value="">-- Select Role --</option>
                    <option value="admin">Admin</option>
                    <option value="teacher">Teacher</option>
                    <option value="student">Student</option>
                </select>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Your registered email" required>
            </div>

            <button type="submit" class="btn">Send Verification Code →</button>

            <div class="link-row">
                <a href="?action=login">← Back to Login</a>
            </div>
        </form>

        <?php elseif ($action === 'verify_code'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="verify_code">

            <p style="font-size:13px;color:#555;margin-bottom:18px;">
                Enter the 6-digit code sent to
                <strong><?= htmlspecialchars($_SESSION['reset_email'] ?? '') ?></strong>.
            </p>

            <div class="form-group">
                <label>Verification Code</label>
                <input type="text" name="code" placeholder="000000" maxlength="6"
                       pattern="[0-9]{6}" required style="letter-spacing:6px;font-size:18px;text-align:center;">
            </div>

            <button type="submit" class="btn">Verify Code →</button>

            <div class="link-row">
                <a href="?action=forgot">Resend code</a>
                &nbsp;|&nbsp;
                <a href="?action=login">← Back to Login</a>
            </div>
        </form>

        <?php elseif ($action === 'reset'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="reset">

            <p style="font-size:13px;color:#555;margin-bottom:18px;">
                Create a new strong password.
            </p>

            <div class="form-group">
                <label>New Password</label>
                <div class="pw-wrap">
                    <input type="password" name="password" id="newPw" placeholder="New password" required>
                    <button type="button" class="pw-toggle" onclick="togglePw('newPw',this)">Show</button>
                </div>
            </div>

            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" placeholder="Repeat new password" required>
            </div>

            <p class="pw-hint">8+ chars · uppercase · lowercase · number · special character</p>

            <button type="submit" class="btn" style="margin-top:14px;">Change Password →</button>
        </form>
        <?php endif; ?>

    </div>
</div>

<script>
    function selectRole(role) {
        document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('role-' + role).classList.add('active');
        document.getElementById('roleInput').value = role;
    }

    function togglePw(id, btn) {
        const f = document.getElementById(id);
        if (f.type === 'password') {
            f.type = 'text';
            btn.textContent = 'Hide';
        } else {
            f.type = 'password';
            btn.textContent = 'Show';
        }
    }
</script>
</body>
</html>