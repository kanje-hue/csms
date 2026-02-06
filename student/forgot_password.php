<?php
session_start();
include '../config/db.php';

$message = "";

if (isset($_POST['submit_email'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    $query = "SELECT * FROM students WHERE email='$email'";
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) == 1) {
        $token = bin2hex(random_bytes(50));
        $expires = date("U") + 1800;

        $update = "UPDATE students SET reset_token='$token', reset_expires='$expires' WHERE email='$email'";
        mysqli_query($conn, $update);

        $message = "Password reset token generated (email sending disabled on localhost).";
    } else {
        $message = "Email not found.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>

<div class="auth-card">
    <h2>Forgot Password</h2>

    <?php if ($message != "") echo "<div class='message'>$message</div>"; ?>

    <form method="POST">
        <input type="email" name="email" placeholder="Enter your email" required>
        <button type="submit" name="submit_email">Submit</button>
    </form>

    <p><a href="login.php">Back to Login</a></p>
</div>

</body>
</html>
