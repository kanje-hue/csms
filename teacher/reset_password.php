<?php
session_start();
include "../config/db.php";

$message = "";

if (
    !isset($_GET['token']) ||
    $_GET['token'] !== $_SESSION['teacher_reset_token'] ||
    time() > $_SESSION['teacher_reset_expires']
) {
    die("Invalid or expired token.");
}

if (isset($_POST['reset_password'])) {

    $new_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $email    = $_SESSION['teacher_reset_email'];

    mysqli_query(
        $conn,
        "UPDATE teachers SET password='$new_pass' WHERE email='$email'"
    );

    unset(
        $_SESSION['teacher_reset_token'],
        $_SESSION['teacher_reset_expires'],
        $_SESSION['teacher_reset_email']
    );

    $message = "Password reset successful! You can now login.";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - Teacher</title>
    <link rel="stylesheet" href="../assets/css/admin-auth.css">
</head>
<body>

<div class="auth-card">
    <h2>Reset Password</h2>

    <?php if ($message): ?>
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
