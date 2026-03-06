<?php
/**
 * teacher/force-change-password.php - Force password change on first login
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';

$security = new SecurityManager($conn);

// Check if teacher is in temp session
if (!isset($_SESSION['temp_teacher_id']) || !isset($_SESSION['temp_teacher_email'])) {
    header('Location: ../public/?view=login');
    exit;
}

$teacher_id = $_SESSION['temp_teacher_id'];
$email = $_SESSION['temp_teacher_email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if ($password !== $confirm) {
        $error = "Passwords do not match";
    } else {
        $strength = $security->validatePasswordStrength($password);
        if (!$strength['valid']) {
            $error = $strength['message'];
        } else {
            // Update password and remove force flag
            $hash = $security->hashPassword($password);
            $update = $conn->prepare(
                "UPDATE teachers SET password = ?, force_password_change = 0, password_changed_at = NOW() 
                 WHERE teacher_id = ?"
            );
            $update->bind_param('si', $hash, $teacher_id);
            
            if ($update->execute()) {
                // Clear temp session and login
                unset($_SESSION['temp_teacher_id'], $_SESSION['temp_teacher_email']);
                
                $_SESSION['teacher_logged_in'] = true;
                $_SESSION['teacher_id'] = $teacher_id;
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error = "Password update failed";
            }
            $update->close();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Change Password</title>
    <style>
        body { font-family: Arial; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: white; padding: 40px; border-radius: 8px; width: 400px; }
        h1 { color: #16a085; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { background: #16a085; color: white; padding: 10px; border: none; border-radius: 4px; width: 100%; cursor: pointer; }
        .error { color: red; background: #fdecea; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .hint { font-size: 12px; color: #888; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Change Your Password</h1>
        <p>This is your first login. Please set a new password.</p>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>New Password:</label>
                <input type="password" name="password" required>
                <div class="hint">8+ chars, uppercase, lowercase, number, special</div>
            </div>
            
            <div class="form-group">
                <label>Confirm Password:</label>
                <input type="password" name="confirm_password" required>
            </div>
            
            <button type="submit" class="btn">Change Password</button>
        </form>
    </div>
</body>
</html>