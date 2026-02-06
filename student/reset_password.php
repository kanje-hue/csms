<?php
session_start();
include '../config/db.php';

$message = "";

// Check if the token exists
if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // Check if the token is valid and not expired
    $query = "SELECT * FROM students WHERE reset_token='$token' AND reset_expires > " . date("U");
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) == 1) {
        // Token is valid
        if (isset($_POST['reset_password'])) {
            $new_password = $_POST['password'];
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // Update the password
            $update_query = "UPDATE students SET password='$hashed_password', reset_token=NULL, reset_expires=NULL WHERE reset_token='$token'";
            if (mysqli_query($conn, $update_query)) {
                $message = "Your password has been updated. You can now log in with your new password.";
            }
        }

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Reset Password - CSMS</title>
        </head>
        <body>
            <h2>Reset Your Password</h2>

            <!-- Show message -->
            <?php echo $message; ?>

            <form method="POST">
                <label>New Password:</label><br>
                <input type="password" name="password" required><br><br>
                <button type="submit" name="reset_password">Reset Password</button>
            </form>
        </body>
        </html>
        <?php
    } else {
        echo "This link has expired or is invalid.";
    }
}
?>
