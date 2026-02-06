<?php
session_start();
include '../config/db.php';

$message = "";

// Handle forgot password form submission
if (isset($_POST['submit_email'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    // Check if the email exists
    $query = "SELECT * FROM students WHERE email='$email'";
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) == 1) {
        // Generate a unique token
        $token = bin2hex(random_bytes(50));
        $expires = date("U") + 1800; // 30 minutes expiry time

        // Store the token and expiry time in the database
        $update_query = "UPDATE students SET reset_token='$token', reset_expires='$expires' WHERE email='$email'";
        if (mysqli_query($conn, $update_query)) {
            // Send the reset link via email
            $reset_link = "http://yourdomain.com/reset_password.php?token=" . $token;
            $subject = "Password Reset Request";
            $message = "Click on the following link to reset your password: " . $reset_link;
            mail($email, $subject, $message);

            echo "An email has been sent with a password reset link.";
        }
    } else {
        echo "Email not found.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password - CSMS</title>
</head>
<body>
    <h2>Forgot Password</h2>

    <!-- Show message -->
    <?php echo $message; ?>

    <form method="POST">
        <label>Email:</label><br>
        <input type="email" name="email" required><br><br>
        <button type="submit" name="submit_email">Submit</button>
    </form>
</body>
</html>
