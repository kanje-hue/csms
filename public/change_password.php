<?php
/**
 * Forced password change page.
 * Reached when force_password_change = 1 after login.
 */
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['force_change_role'], $_SESSION['force_change_id'])) {
    header('Location: index.php');
    exit();
}

$security = new SecurityManager($conn);
$role     = $_SESSION['force_change_role'];
$userId   = (int)$_SESSION['force_change_id'];
$table    = ['admin' => 'admins', 'teacher' => 'teachers', 'student' => 'students'][$role] ?? null;

if (!$table) {
    session_destroy();
    header('Location: index.php');
    exit();
}

$message = '';
$msgType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $pwCheck = $security->validatePasswordStrength($password);

    if ($password !== $confirm) {
        $message = '❌ Passwords do not match.';
    } elseif (!$pwCheck['valid']) {
        $message = '❌ ' . $pwCheck['message'];
    } elseif ($security->isPasswordReused($table, $userId, $password)) {
        $message = '❌ You cannot reuse one of your last ' . SecurityManager::PASSWORD_HISTORY . ' passwords.';
    } else {
        $security->updatePassword($table, $userId, $password, true);

        // Set up session as logged in
        $idCol   = ($role === 'admin') ? 'admin_id' : (($role === 'teacher') ? 'teacher_id' : 'student_id');
        $nameCol = ($role === 'admin') ? 'name' : (($role === 'teacher') ? 'fullname' : 'name');

        $stmt = $conn->prepare("SELECT $nameCol FROM `$table` WHERE `$idCol` = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $_SESSION[$role . '_logged_in'] = true;
        $_SESSION[$role . '_id']         = $userId;
        $_SESSION[$role . '_name']       = $row[$nameCol] ?? '';
        $_SESSION['user_role']           = $role;
        $_SESSION['csrf_token']          = bin2hex(random_bytes(32));

        unset($_SESSION['force_change_role'], $_SESSION['force_change_id']);

        $redirect = ['admin' => '../admin/manage_courses.php',
                     'teacher' => '../teacher/dashboard.php',
                     'student' => '../student/dashboard.php'][$role] ?? 'index.php';
        header('Location: ' . $redirect);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password – CSMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #0f3460 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .card {
            background: white; width: 100%; max-width: 440px;
            border-radius: 16px; overflow: hidden;
            box-shadow: 0 25px 50px rgba(0,0,0,0.4);
        }
        .card-header {
            background: linear-gradient(135deg, #e67e22, #d35400);
            color: white; text-align: center; padding: 28px 20px 18px;
        }
        .card-header h1 { font-size: 22px; margin-bottom: 6px; }
        .card-header p  { font-size: 13px; opacity: 0.85; }
        .card-body { padding: 28px 24px; }
        .alert {
            padding: 11px 14px; border-radius: 8px; margin-bottom: 16px;
            font-size: 13px; font-weight: 500;
        }
        .alert-error   { background: #fdecea; color: #c0392b; border-left: 4px solid #c0392b; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #2c3e50; margin-bottom: 6px; }
        input[type="password"] {
            width: 100%; padding: 11px 14px; border: 2px solid #dde3eb;
            border-radius: 8px; font-size: 14px; transition: border-color 0.2s;
        }
        input:focus { outline: none; border-color: #e67e22; }
        .pw-wrap { position: relative; }
        .pw-toggle {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: #7f8c8d; cursor: pointer;
            font-size: 12px; font-weight: 600;
        }
        .pw-hint { font-size: 11px; color: #95a5a6; margin-top: 6px; }
        .btn {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, #e67e22, #d35400);
            color: white; border: none; border-radius: 8px;
            font-weight: 700; font-size: 14px; cursor: pointer;
            transition: opacity 0.2s; margin-top: 6px;
        }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h1>🔑 Change Password</h1>
        <p>You must set a new password before continuing.</p>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>New Password</label>
                <div class="pw-wrap">
                    <input type="password" name="password" id="pw1" placeholder="New password" required>
                    <button type="button" class="pw-toggle" onclick="togglePw('pw1',this)">Show</button>
                </div>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="pw-wrap">
                    <input type="password" name="confirm_password" id="pw2" placeholder="Repeat password" required>
                    <button type="button" class="pw-toggle" onclick="togglePw('pw2',this)">Show</button>
                </div>
            </div>
            <p class="pw-hint">8+ chars · uppercase · lowercase · number · special character</p>
            <button type="submit" class="btn">Save New Password →</button>
        </form>
    </div>
</div>
<script>
    function togglePw(id, btn) {
        const f = document.getElementById(id);
        if (f.type === 'password') { f.type = 'text';     btn.textContent = 'Hide'; }
        else                        { f.type = 'password'; btn.textContent = 'Show'; }
    }
</script>
</body>
</html>
