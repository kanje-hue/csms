<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../config/db.php';

$message = "";

// Hardcoded admin credentials for student project
$admin_user = "admin";
$admin_pass = "admin123"; // in real apps, hash this

if(isset($_POST['login'])){
    $username = $_POST['username'];
    $password = $_POST['password'];

    if($username === $admin_user && $password === $admin_pass){
        $_SESSION['admin_logged_in'] = true;
        header("Location: dashboard.php");
        exit();
    } else {
        $message = "Invalid credentials!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Login - CSMS</title>
</head>
<body>
    <h2>Admin Login</h2>

    <?php if($message != ""){ echo "<p style='color:red;'>$message</p>"; } ?>

    <form method="POST">
        <label>Username:</label><br>
        <input type="text" name="username" required><br><br>

        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit" name="login">Login</button>
    </form>
</body>
</html>
