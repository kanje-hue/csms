<?php
session_start();
include '../config/db.php';

$message = "";

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    $query = "SELECT * FROM students WHERE reset_token='$token' AND reset_expires > " . date("U");
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) == 1) {
        if (isset($_POST['reset_password'])) {
            $new_password = password_hash($_POST['password'], PASSWORD_DEFAULT);

            mysqli_query($conn, "UPDATE students SET password='$new_password', reset_token=NULL, reset_expires=NULL WHERE reset_token='$token'");
            $message = "Password updated successfully.";
        }
    } else {
        die("Invalid or expired token.");
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

    <?php if ($message != "") echo "<div class='message success'>$message</div>"; ?>

    <form method="POST">
        <input type="password" name="password" placeholder="New Password" required>
        <button type="submit" name="reset_password">Reset Password</button>
    </form>
</div>

</body>
</html>
