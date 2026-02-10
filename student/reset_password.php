<?php
session_start();
include '../config/db.php';
$message = "";

if (!isset($_GET['token'])) die("No token provided.");

$token = mysqli_real_escape_string($conn, $_GET['token']);

$check = mysqli_query(
    $conn,
    "SELECT * FROM students 
     WHERE reset_token='$token' 
     AND reset_expires > " . time()
);

if (mysqli_num_rows($check) !== 1) die("Invalid or expired token.");

if (isset($_POST['reset_password'])) {
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    if ($password !== $confirm) {
        $message = "Passwords do not match!";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        mysqli_query(
            $conn,
            "UPDATE students 
             SET password='$hash', reset_token=NULL, reset_expires=NULL
             WHERE reset_token='$token'"
        );

        $message = "Password updated successfully. You can now log in.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
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

        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>
        </div>

        <button class="btn" name="reset_password">Reset Password</button>
    </form>

    <div class="auth-links">
        <a href="login.php">Go to Login</a>
    </div>
</div>

</body>
</html>
