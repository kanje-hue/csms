<?php
session_start();
include '../config/db.php';

$message = "";

if(isset($_POST['submit_email'])){
    $email = $_POST['email'];

    // Only allow reset for admin email in this demo
    if($email === "admin@csms.com"){
        $token = bin2hex(random_bytes(32));
        $expires = time() + 1800; // 30 minutes

        $_SESSION['admin_reset_token'] = $token;
        $_SESSION['admin_reset_expires'] = $expires;

        $message = "Reset link generated:<br>
        <code>reset_password.php?token=$token</code>";
    } else {
        $message = "Email not recognized!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-auth.css">
</head>
<body>

<div class="auth-card">
    <h2>Forgot Password</h2>
    <p>We’ll help you reset it</p>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>

        <button class="btn" name="submit_email">Send Reset Link</button>
    </form>

    <div class="auth-links">
        <a href="login.php">Back to Login</a>
    </div>
</div>

</body>
</html>
