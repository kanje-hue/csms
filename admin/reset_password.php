<?php
session_start();
$message = "";

if(!isset($_GET['token']) || $_GET['token'] !== $_SESSION['admin_reset_token'] || time() > $_SESSION['admin_reset_expires']){
    die("Invalid or expired token.");
}

if(isset($_POST['reset_password'])){
    $new_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Update the session "password" for demo (or database if stored)
    $_SESSION['admin_pass_hash'] = $new_pass;

    unset($_SESSION['admin_reset_token'], $_SESSION['admin_reset_expires']);

    $message = "Password reset successful! You can now login.";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-auth.css">
</head>
<body>

<div class="auth-card">
    <h2>Reset Password</h2>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="password" required>
        </div>

        <button class="btn" name="reset_password">Reset Password</button>
    </form>

    <div class="auth-links">
        <a href="login.php">Go to Login</a>
    </div>
</div>

</body>
</html>
