<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../config/db.php';

$message = "";

// Handle login
if(isset($_POST['login'])){
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];

    $query = "SELECT * FROM students WHERE email='$email'";
    $result = mysqli_query($conn, $query);

    if(mysqli_num_rows($result) == 1){
        $student = mysqli_fetch_assoc($result);

        if(password_verify($password, $student['password'])){
            if($student['status'] == 'active'){
                $_SESSION['student_id'] = $student['student_id'];
                $_SESSION['student_name'] = $student['name'];

                header("Location: dashboard.php");
                exit();
            } else {
                $message = "Account not activated by admin.";
            }
        } else {
            $message = "Incorrect password.";
        }
    } else {
        $message = "Account not found.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Login - CSMS</title>
</head>
<body>
    <h2>Student Login</h2>

    <!-- Show message -->
    <?php if($message != ""){ echo "<p style='color:red;'>$message</p>"; } ?>

    <form method="POST" action="">
        <label>Email:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit" name="login">Login</button>
    </form>

    <p>Don't have an account? <a href="register.php">Register here</a></p>
</body>
</html>
