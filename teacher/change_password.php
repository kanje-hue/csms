<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$message = "";
$errors = [];

if(isset($_POST['change_password'])){
    $teacher_id = $_SESSION['teacher_id'];

    $current_pass = $_POST['current_password'];
    $new_pass     = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    // Fetch current password from DB
    $res = mysqli_query($conn, "SELECT password FROM teachers WHERE teacher_id=$teacher_id LIMIT 1");
    $row = mysqli_fetch_assoc($res);

    // Verify current password
    if(!password_verify($current_pass, $row['password'])){
        $errors[] = "Current password is incorrect.";
    }

    // Verify new passwords match
    if($new_pass !== $confirm_pass){
        $errors[] = "New password and confirm password do not match.";
    }

    // Update password if no errors
    if(empty($errors)){
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE teachers SET password='$hashed' WHERE teacher_id=$teacher_id");
        $message = "Password updated successfully!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Change Password - Teacher</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 500px;
            max-width: 95%;
            margin: 50px auto;
            padding: 25px;
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
        }
        h2 { text-align: center; color: var(--midnight-garden); margin-bottom: 15px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input { width: 100%; padding: 10px; border-radius: 10px; border: 1px solid #566947; }
        .btn { width: 100%; padding: 12px; border-radius: 12px; border: none; background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow)); color: #fff; font-size: 16px; cursor: pointer; margin-top: 10px; }
        .btn:hover { opacity: 0.9; }
        .message { text-align: center; margin-bottom: 10px; color: var(--terra-rosa); }
        .errors { color: red; margin-bottom: 10px; }
        .auth-links { margin-top: 15px; text-align: center; }
        .auth-links a { color: var(--midnight-garden); font-weight: bold; text-decoration: none; }
    </style>
</head>
<body>
<div class="auth-card">
    <h2>Change Password</h2>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <?php if(!empty($errors)): ?>
        <div class="errors">
            <?php foreach($errors as $e){ echo $e."<br>"; } ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required>
        </div>
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" required>
        </div>
        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" required>
        </div>
        <button class="btn" name="change_password">Update Password</button>
    </form>

    <div class="auth-links">
        <a href="dashboard.php">Back to Dashboard</a>
    </div>
</div>
</body>
</html>
